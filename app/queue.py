"""Background worker loop.

Two kinds of run share this loop:

* **interactive** — claim a ``ready`` run, classify every listing inline, finish.
* **batch** — claim a ``ready`` run, crawl every listing, submit the
  classification calls to the Message Batches API (50% cheaper), and park the run
  as ``awaiting_batch``. A later tick polls the batch, writes the findings, and
  finishes the run.

The batch ids live on the Run row, so a worker restart resumes polling an
in-flight batch rather than orphaning work that has already been paid for.

Single-process safe; for multi-worker scale-out, add SELECT ... FOR UPDATE SKIP
LOCKED when claiming runs (noted for the scale phase).
"""

from __future__ import annotations

import asyncio
import datetime as dt
import logging

from sqlalchemy import or_, select, update

from .classify.anthropic_client import AnthropicClassifier, ClassifyOutcome
from .classify.batch_client import AnthropicBatchClient, PreparedRequest, approx_size
from .config import get_settings
from .db import SessionLocal
from .fetchers.http import build_client
from .pipeline import prepare_listing, process_listing, store_classification
from .store.models import ListingJob, Run
from . import summary

log = logging.getLogger("hlv.worker")

_settings = get_settings()

# How much prepared work we hold in memory before flushing it to a batch. Bounds
# peak memory on a full-directory crawl — base64 flyer images are the bulk, so
# both a request count and a byte budget are enforced.
FLUSH_REQUESTS = 500
FLUSH_BYTES = 64 * 1024 * 1024

# How often to re-check an in-flight batch. Most batches finish well inside an
# hour; polling harder just burns API calls.
BATCH_POLL_SECONDS = 60

# How often the worker touches the run row it is actively processing. The janitor
# reads this to tell "still crawling" from "the process died mid-crawl".
HEARTBEAT_SECONDS = 60


def _now() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


class Worker:
    def __init__(self) -> None:
        self._task: asyncio.Task | None = None
        self._stop = asyncio.Event()
        self._classifier = AnthropicClassifier()
        self._batches = AnthropicBatchClient()
        self._last_poll = 0.0
        self._last_sweep = 0.0
        # The run this process is currently working on. The janitor never touches
        # it, however cold the row looks.
        self._active_run_id: str | None = None

    def start(self) -> None:
        if self._task is None:
            self._task = asyncio.create_task(self._loop())

    async def stop(self) -> None:
        self._stop.set()
        if self._task:
            await self._task

    async def _loop(self) -> None:
        while not self._stop.is_set():
            try:
                await self._poll_batches()
            except Exception:  # noqa: BLE001 - polling must never kill the loop
                log.exception("Batch polling failed")

            try:
                await self._reclaim_stranded_runs()
            except Exception:  # noqa: BLE001 - the janitor must never kill the loop
                log.exception("Stranded-run sweep failed")

            run = await self._claim_ready_run()
            if run is None:
                await asyncio.sleep(2.0)
                continue

            self._active_run_id = run.id
            beat = asyncio.create_task(self._heartbeat(run.id))
            try:
                if run.mode == "batch":
                    await self._process_run_batched(run)
                else:
                    await self._process_run(run)
            except Exception:  # noqa: BLE001 - keep the worker alive across run failures
                log.exception("Run %s failed", run.id)
                await self._mark_run(run.id, "error")
            finally:
                beat.cancel()
                try:
                    await beat
                except asyncio.CancelledError:
                    pass
                self._active_run_id = None

    async def _heartbeat(self, run_id: str) -> None:
        """Keep the active run's heartbeat warm so the janitor leaves it alone."""
        try:
            while True:
                await asyncio.sleep(HEARTBEAT_SECONDS)
                async with SessionLocal() as session:
                    await session.execute(
                        update(Run).where(Run.id == run_id).values(heartbeat_at=_now())
                    )
                    await session.commit()
        except asyncio.CancelledError:
            raise
        except Exception:  # noqa: BLE001 - a failed heartbeat must not kill the run
            log.exception("Heartbeat failed for run %s", run_id)

    async def _reclaim_stranded_runs(self) -> None:
        """Re-ready runs abandoned mid-crawl by a crashed or restarted worker.

        Only `running` runs can strand: `awaiting_batch` is already the poller's
        job, and `ready` is by definition unclaimed. A run qualifies when its
        heartbeat has gone cold — meaning no process is working on it — and it is
        not the run THIS process is currently crawling.

        Re-readying is safe because the work is job-level: `_queued_jobs()` only
        picks up jobs still marked `queued`, so listings already finished (or
        already submitted to a batch) are not redone or re-billed.
        """
        loop_now = asyncio.get_running_loop().time()
        if loop_now - self._last_sweep < BATCH_POLL_SECONDS:
            return
        self._last_sweep = loop_now

        cutoff = _now() - dt.timedelta(minutes=max(2, _settings.stale_run_minutes))

        async with SessionLocal() as session:
            stmt = select(Run).where(
                Run.status == "running",
                or_(
                    Run.heartbeat_at < cutoff,
                    # Never heartbeated: either a pre-heartbeat build claimed it,
                    # or it died within the first minute. Fall back to updated_at
                    # so we still require it to be genuinely old.
                    (Run.heartbeat_at.is_(None)) & (Run.updated_at < cutoff),
                ),
            )
            stranded = (await session.execute(stmt)).scalars().all()

            for run in stranded:
                if run.id == self._active_run_id:
                    continue

                if (run.reclaim_count or 0) >= _settings.max_run_reclaims:
                    log.error(
                        "Run %s stranded %d times — parking it as an error instead of "
                        "re-readying. Its queued listings need a look.",
                        run.id,
                        run.reclaim_count,
                    )
                    run.status = "error"
                    continue

                queued = (
                    await session.execute(
                        select(ListingJob.id).where(
                            ListingJob.run_id == run.id, ListingJob.status == "queued"
                        )
                    )
                ).scalars().all()

                if not queued:
                    # Nothing left to crawl. If batches went out, hand it to the
                    # poller; otherwise it is simply finished and needs closing.
                    if run.batch_ids:
                        log.info("Run %s had no queued work left; parking for batch results", run.id)
                        run.status = "awaiting_batch"
                    else:
                        log.info("Run %s stranded with no work left; finalizing", run.id)
                        run.status = "ready"  # a no-op pass will close it out cleanly
                    run.reclaim_count = (run.reclaim_count or 0) + 1
                    continue

                log.warning(
                    "Reclaiming stranded run %s (%s mode, %d listings still queued, "
                    "attempt %d) — heartbeat last seen %s",
                    run.id,
                    run.mode,
                    len(queued),
                    (run.reclaim_count or 0) + 1,
                    run.heartbeat_at.isoformat() if run.heartbeat_at else "never",
                )
                run.status = "ready"
                run.reclaim_count = (run.reclaim_count or 0) + 1

            await session.commit()

    async def _claim_ready_run(self) -> Run | None:
        async with SessionLocal() as session:
            result = await session.execute(
                select(Run).where(Run.status == "ready").order_by(Run.created_at).limit(1)
            )
            run = result.scalar_one_or_none()
            if run is None:
                return None
            run.status = "running"
            # Stamp the heartbeat at claim time so a run that dies before the
            # first beat still ages out on the heartbeat rule rather than
            # falling through to the updated_at fallback.
            run.heartbeat_at = _now()
            await session.commit()
            await session.refresh(run)
            return run

    # ------------------------------------------------------------------
    # Interactive path
    # ------------------------------------------------------------------

    async def _process_run(self, run: Run) -> None:
        jobs = await self._queued_jobs(run.id)

        concurrency = int(run.run_config.get("concurrency", 6)) if run.run_config else 6
        sem = asyncio.Semaphore(max(1, concurrency))
        acc = summary.new_accumulator()
        lock = asyncio.Lock()

        async with build_client() as client:
            async def handle(job: ListingJob) -> None:
                async with sem:
                    stats = await self._process_job(client, run, job)
                async with lock:
                    summary.merge(acc, stats)

            await asyncio.gather(*(handle(j) for j in jobs))

        final = summary.finalize(acc, len(jobs))
        final["status"] = "done"
        await self._finish_run(run.id, final)

    async def _process_job(self, client, run: Run, job: ListingJob) -> dict:
        async with SessionLocal() as session:
            try:
                stats = await process_listing(
                    session=session,
                    classifier=self._classifier,
                    client=client,
                    run_id=run.id,
                    listing=job.snapshot,
                    run_config=run.run_config or {},
                    system_prompt=run.prompt or "",
                )
                await session.execute(
                    update(ListingJob).where(ListingJob.id == job.id).values(status="done")
                )
                await session.commit()
                return stats
            except Exception as exc:  # noqa: BLE001 - one bad listing must not kill the run
                log.exception("Listing %s failed", job.listing_id)
                await session.rollback()
                await self._mark_job_error(job.id, str(exc))
                return {"errors": 1}

    # ------------------------------------------------------------------
    # Batch path
    # ------------------------------------------------------------------

    async def _process_run_batched(self, run: Run) -> None:
        """Crawl every listing, then submit the classification calls as batches.

        The run does NOT finish here — it parks as ``awaiting_batch`` and the
        poll tick completes it once Anthropic returns results.
        """
        jobs = await self._queued_jobs(run.id)
        concurrency = int(run.run_config.get("concurrency", 6)) if run.run_config else 6
        sem = asyncio.Semaphore(max(1, concurrency))
        lock = asyncio.Lock()

        buffer: list[PreparedRequest] = []
        buffered_bytes = 0
        # Seeded, not empty: a run the janitor re-readied may already have batches
        # in flight from its first attempt. Starting fresh here would overwrite
        # those ids on the next flush and lose results we have already paid for.
        batch_ids: list[str] = list(run.batch_ids or [])
        submitted = 0

        async def flush() -> None:
            nonlocal buffer, buffered_bytes
            if not buffer:
                return
            pending, buffer = buffer, []
            buffered_bytes = 0
            ids = await self._batches.submit(pending)
            batch_ids.extend(ids)
            await self._mark_jobs_submitted([p.custom_id for p in pending])
            # Record ids as they are created, not just at the end. A batch that
            # exists has already been paid for, so it must be recoverable even if
            # a later flush blows up. The run stays `running` until every chunk is
            # out, so the poller can't finalize it half-crawled.
            await self._record_batch_ids(run.id, batch_ids)

        try:
            async with build_client() as client:
                async def handle(job: ListingJob) -> None:
                    nonlocal buffered_bytes, submitted
                    prepared = await self._prepare_job(client, run, job)
                    if prepared is None or prepared.spec is None:
                        return
                    item = PreparedRequest(custom_id=f"job-{job.id}", spec=prepared.spec)
                    async with lock:
                        buffer.append(item)
                        buffered_bytes += approx_size(prepared.spec.params)
                        submitted += 1
                        if len(buffer) >= FLUSH_REQUESTS or buffered_bytes >= FLUSH_BYTES:
                            await flush()

                async def guarded(job: ListingJob) -> None:
                    async with sem:
                        await handle(job)

                await asyncio.gather(*(guarded(j) for j in jobs))

            async with lock:
                await flush()
        except Exception:
            if batch_ids:
                # Some batches are already in flight. Park the run so polling
                # still collects them, rather than erroring out and throwing away
                # work we have been billed for. Listings that never made it into
                # a batch keep their `queued` job rows for a follow-up run.
                log.exception(
                    "Run %s failed partway through submission; %d batch(es) already sent "
                    "and will still be collected",
                    run.id,
                    len(batch_ids),
                )
                await self._park_awaiting_batch(run.id, batch_ids)
                return
            raise

        if not batch_ids:
            # Everything was skipped or resolved without a model call.
            log.info("Run %s needed no classification calls", run.id)
            await self._finalize_batch_run(run.id)
            return

        await self._park_awaiting_batch(run.id, batch_ids)
        log.info("Run %s submitted %d requests across %d batch(es)", run.id, submitted, len(batch_ids))

    async def _prepare_job(self, client, run: Run, job: ListingJob):
        """Crawl half for one job; persists the crawl-phase stats on the row."""
        async with SessionLocal() as session:
            try:
                prepared = await prepare_listing(
                    session=session,
                    client=client,
                    run_id=run.id,
                    listing=job.snapshot,
                    run_config=run.run_config or {},
                    system_prompt=run.prompt or "",
                )
                await session.execute(
                    update(ListingJob)
                    .where(ListingJob.id == job.id)
                    .values(
                        custom_id=f"job-{job.id}",
                        prepared={
                            "stats": prepared.stats,
                            "fingerprints": prepared.fingerprints,
                            "path": prepared.spec.path if prepared.spec else "text",
                        },
                        # No model call needed → the job is already finished.
                        status="queued" if prepared.spec else "done",
                    )
                )
                await session.commit()
                return prepared
            except Exception as exc:  # noqa: BLE001 - one bad listing must not kill the run
                log.exception("Preparing listing %s failed", job.listing_id)
                await session.rollback()
                await self._mark_job_error(job.id, str(exc))
                return None

    async def _poll_batches(self) -> None:
        """Advance every run waiting on a batch. Cheap no-op when there are none."""
        loop_now = asyncio.get_running_loop().time()
        if loop_now - self._last_poll < BATCH_POLL_SECONDS:
            return
        self._last_poll = loop_now

        async with SessionLocal() as session:
            runs = (
                await session.execute(select(Run).where(Run.status == "awaiting_batch"))
            ).scalars().all()

        for run in runs:
            batch_ids = list(run.batch_ids or [])
            if not batch_ids:
                await self._finalize_batch_run(run.id)
                continue

            statuses = [await self._batches.status(bid) for bid in batch_ids]
            if any(s != "ended" for s in statuses):
                log.debug("Run %s still waiting: %s", run.id, statuses)
                continue

            for batch_id in batch_ids:
                await self._ingest_batch(run, batch_id)
            await self._finalize_batch_run(run.id)

    async def _ingest_batch(self, run: Run, batch_id: str) -> None:
        """Write the findings for one completed batch."""
        async for item in self._batches.results(batch_id):
            job = await self._job_by_custom_id(item.custom_id)
            if job is None:
                log.warning("Batch %s returned unknown custom_id %s", batch_id, item.custom_id)
                continue
            if job.status == "done":
                continue  # already ingested (a re-poll after a partial pass)

            if item.outcome is None:
                await self._mark_job_error(job.id, item.error or "batch request failed")
                continue

            await self._store_batch_result(run, job, item.outcome)

    async def _store_batch_result(self, run: Run, job: ListingJob, outcome: ClassifyOutcome) -> None:
        prepared = job.prepared or {}
        async with SessionLocal() as session:
            try:
                delta = await store_classification(
                    session=session,
                    run_id=run.id,
                    listing=job.snapshot,
                    result=outcome.result,
                    fingerprints=prepared.get("fingerprints", {}) or {},
                    # The response can't tell us whether flyers went with it; the
                    # crawl phase recorded that, so token spend lands in the right
                    # column of the summary.
                    path=prepared.get("path", "text"),
                    usage=outcome.usage,
                )
                merged = dict(prepared.get("stats") or {})
                for key in ("suggested", "text_tokens", "image_tokens"):
                    merged[key] = delta[key]
                await session.execute(
                    update(ListingJob)
                    .where(ListingJob.id == job.id)
                    .values(status="done", prepared={**prepared, "stats": merged})
                )
                await session.commit()
            except Exception as exc:  # noqa: BLE001
                log.exception("Storing batch result for listing %s failed", job.listing_id)
                await session.rollback()
                await self._mark_job_error(job.id, str(exc))

    async def _finalize_batch_run(self, run_id: str) -> None:
        """Aggregate the per-job stats a batch run accumulated and close it out."""
        async with SessionLocal() as session:
            jobs = (
                await session.execute(select(ListingJob).where(ListingJob.run_id == run_id))
            ).scalars().all()

        acc = summary.new_accumulator()
        for job in jobs:
            stats = (job.prepared or {}).get("stats") or {}
            if job.status == "error":
                summary.merge(acc, {"errors": 1})
                continue
            summary.merge(acc, stats)

        final = summary.finalize(acc, len(jobs))
        final["status"] = "done"
        final["mode"] = "batch"
        await self._finish_run(run_id, final)

    # ------------------------------------------------------------------
    # Shared helpers
    # ------------------------------------------------------------------

    async def _queued_jobs(self, run_id: str) -> list[ListingJob]:
        async with SessionLocal() as session:
            return list(
                (
                    await session.execute(
                        select(ListingJob).where(
                            ListingJob.run_id == run_id, ListingJob.status == "queued"
                        )
                    )
                ).scalars().all()
            )

    async def _job_by_custom_id(self, custom_id: str) -> ListingJob | None:
        async with SessionLocal() as session:
            return (
                await session.execute(
                    select(ListingJob).where(ListingJob.custom_id == custom_id).limit(1)
                )
            ).scalar_one_or_none()

    async def _record_batch_ids(self, run_id: str, batch_ids: list[str]) -> None:
        """Record the batches created so far WITHOUT parking the run.

        Called after every flush so the ids survive a crash between chunks. The
        status deliberately stays ``running``: the poll tick only looks at
        ``awaiting_batch`` runs, and parking early would let it ingest the first
        chunk and finalize the run while later listings are still being crawled.
        """
        async with SessionLocal() as session:
            await session.execute(
                update(Run).where(Run.id == run_id).values(batch_ids=list(batch_ids))
            )
            await session.commit()

    async def _park_awaiting_batch(self, run_id: str, batch_ids: list[str]) -> None:
        """Everything that will be submitted has been. Hand the run to the poller."""
        async with SessionLocal() as session:
            await session.execute(
                update(Run)
                .where(Run.id == run_id)
                .values(
                    status="awaiting_batch",
                    batch_ids=list(batch_ids),
                    batch_submitted_at=_now(),
                )
            )
            await session.commit()

    async def _mark_jobs_submitted(self, custom_ids: list[str]) -> None:
        async with SessionLocal() as session:
            await session.execute(
                update(ListingJob)
                .where(ListingJob.custom_id.in_(custom_ids))
                .values(status="submitted")
            )
            await session.commit()

    async def _mark_job_error(self, job_id: int, error: str) -> None:
        async with SessionLocal() as session:
            await session.execute(
                update(ListingJob).where(ListingJob.id == job_id).values(status="error", error=error)
            )
            await session.commit()

    async def _mark_run(self, run_id: str, status: str) -> None:
        async with SessionLocal() as session:
            await session.execute(update(Run).where(Run.id == run_id).values(status=status))
            await session.commit()

    async def _finish_run(self, run_id: str, summary_dict: dict) -> None:
        async with SessionLocal() as session:
            await session.execute(
                update(Run).where(Run.id == run_id).values(status="done", summary=summary_dict)
            )
            await session.commit()


worker = Worker()
