"""FastAPI application — the worker's REST surface for the WordPress plugin.

All mutating/reading endpoints require a valid HMAC signature from the plugin.
The worker owns the staging store and never writes to WordPress.
"""

from __future__ import annotations

import datetime as dt
import json
import uuid
from contextlib import asynccontextmanager
from typing import Any

from fastapi import Depends, FastAPI, HTTPException
from sqlalchemy import select, update
from sqlalchemy.ext.asyncio import AsyncSession

from .auth import verify_signature
from .classify.anthropic_client import AnthropicClassifier
from .db import SessionLocal, get_session, init_models
from .fetchers.http import build_client
from .pipeline import process_listing
from .queue import worker
from .store.models import Breadcrumb, Decision, Finding, ListingJob, Run


@asynccontextmanager
async def lifespan(app: FastAPI):
    await init_models()
    worker.start()
    yield
    await worker.stop()


app = FastAPI(title="Haunt Verifier Worker", version="0.1.0", lifespan=lifespan)


def _utcnow() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


def _json(body: bytes) -> dict[str, Any]:
    if not body:
        return {}
    try:
        return json.loads(body)
    except json.JSONDecodeError as exc:
        raise HTTPException(status_code=400, detail="Invalid JSON body.") from exc


@app.get("/health")
async def health(_body: bytes = Depends(verify_signature)) -> dict[str, str]:
    return {"status": "ok", "version": "0.1.0"}


@app.post("/crawls")
async def create_crawl(
    body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    data = _json(body)
    run = Run(
        id=uuid.uuid4().hex,
        mode=data.get("mode", "interactive"),
        status="queued",
        run_config=data.get("run_config", {}),
        prompt=data.get("prompt", ""),
        scope=data.get("scope", {}),
    )
    session.add(run)
    await session.commit()
    return {"run_id": run.id, "queued": True}


@app.post("/runs/{run_id}/listings")
async def add_listings(
    run_id: str,
    body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    data = _json(body)
    listings = data.get("listings", [])
    run = await session.get(Run, run_id)
    if run is None:
        raise HTTPException(status_code=404, detail="Unknown run.")
    for snap in listings:
        session.add(
            ListingJob(run_id=run_id, listing_id=int(snap["listing_id"]), snapshot=snap, status="queued")
        )
    await session.commit()
    return {"added": len(listings)}


@app.post("/runs/{run_id}/start")
async def start_run(
    run_id: str,
    _body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    res = await session.execute(
        update(Run).where(Run.id == run_id, Run.status == "queued").values(status="ready")
    )
    await session.commit()
    if res.rowcount == 0:
        raise HTTPException(status_code=409, detail="Run not in a startable state.")
    return {"run_id": run_id, "status": "ready"}


@app.get("/runs/{run_id}")
async def get_run(
    run_id: str,
    _body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    run = await session.get(Run, run_id)
    if run is None:
        raise HTTPException(status_code=404, detail="Unknown run.")
    return {
        "run_id": run.id,
        "status": run.status,
        "mode": run.mode,
        "scope": run.scope,
        "summary": run.summary or {},
        "batch_ids": run.batch_ids or [],
        "batch_submitted_at": run.batch_submitted_at.isoformat() if run.batch_submitted_at else None,
    }


@app.get("/runs/{run_id}/breadcrumbs")
async def run_breadcrumbs(
    run_id: str,
    _body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    """Per-listing verified state for a run, so WordPress can mirror it into post
    meta (drives the list-table 'last verified' column; keeps cron skip local)."""
    listing_ids = select(ListingJob.listing_id).where(ListingJob.run_id == run_id)
    rows = (
        await session.execute(select(Breadcrumb).where(Breadcrumb.listing_id.in_(listing_ids)))
    ).scalars().all()
    return {
        "items": [
            {
                "listing_id": b.listing_id,
                "last_crawled_at": b.last_crawled_at.isoformat() if b.last_crawled_at else None,
                "last_status": b.last_status,
                "finding_hash": b.last_suggestion_hash,
            }
            for b in rows
        ]
    }


@app.post("/listings/check")
async def check_listing(
    body: bytes = Depends(verify_signature),
) -> dict[str, Any]:
    """Interactive single-listing check — process inline and return the finding."""
    data = _json(body)
    listing = data.get("listing", {})
    run_config = dict(data.get("run_config", {}))
    prompt = data.get("prompt", "")
    if not listing.get("listing_id"):
        raise HTTPException(status_code=400, detail="Missing listing.")

    # An explicit interactive check always re-classifies, even if unchanged.
    run_config["skip_unchanged"] = False

    run_id = "adhoc-" + uuid.uuid4().hex
    classifier = AnthropicClassifier()

    async with SessionLocal() as session:
        session.add(Run(id=run_id, mode="interactive", status="running", run_config=run_config, prompt=prompt))
        await session.flush()
        async with build_client() as client:
            stats = await process_listing(
                session=session,
                classifier=classifier,
                client=client,
                run_id=run_id,
                listing=listing,
                run_config=run_config,
                system_prompt=prompt,
            )
        await session.execute(update(Run).where(Run.id == run_id).values(status="done"))
        await session.commit()

        finding = (
            await session.execute(
                select(Finding).where(Finding.run_id == run_id).order_by(Finding.created_at.desc()).limit(1)
            )
        ).scalar_one_or_none()

    return {"run_id": run_id, "stats": stats, "finding": _finding_dto(finding) if finding else None}


@app.get("/findings")
async def list_findings(
    confidence: str | None = None,
    status: str = "pending",
    page: int = 1,
    per_page: int = 50,
    _body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    stmt = select(Finding).where(Finding.status == status)
    if confidence in ("high", "medium", "low"):
        stmt = stmt.where(Finding.confidence == confidence)
    if status == "ignored":
        # The archive reads as a history: most recently parked first. Every
        # ignored row has a decided_at, so no NULL-ordering fuss is needed.
        stmt = stmt.order_by(Finding.decided_at.desc(), Finding.created_at.desc())
    else:
        stmt = stmt.order_by(Finding.durable_fact_conflict.desc(), Finding.created_at.desc())
    stmt = stmt.limit(max(1, min(200, per_page))).offset(max(0, (page - 1) * per_page))

    rows = (await session.execute(stmt)).scalars().all()
    return {"items": [_finding_dto(f) for f in rows], "count": len(rows)}


@app.post("/findings/{finding_id}/ignore")
async def ignore_finding(
    finding_id: str,
    body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    """Park a result in the Past findings archive.

    The row is kept and the listing's breadcrumb is left INTACT, so the next
    crawl still treats this listing as verified and won't re-surface the same
    suggestion until its sources actually change. Restorable.
    """
    d = _json(body)
    finding = await session.get(Finding, finding_id)
    if finding is None:
        raise HTTPException(status_code=404, detail="Unknown finding.")

    finding.status = "ignored"
    finding.decided_at = _utcnow()
    session.add(
        Decision(
            finding_id=finding_id,
            run_id=finding.run_id,
            listing_id=finding.listing_id,
            field=d.get("field", ""),
            action="ignored",
            reason_code=d.get("reason_code", "ignored_for_later"),
            note=d.get("note", ""),
            user=d.get("user", ""),
        )
    )
    await session.commit()
    return {"ignored": True, "finding_id": finding_id}


@app.post("/findings/{finding_id}/restore")
async def restore_finding(
    finding_id: str,
    _body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    """Move an archived result back into the active review queue."""
    finding = await session.get(Finding, finding_id)
    if finding is None:
        raise HTTPException(status_code=404, detail="Unknown finding.")
    if finding.status != "ignored":
        raise HTTPException(status_code=409, detail="Only ignored findings can be restored.")

    finding.status = "pending"
    finding.decided_at = None
    await session.commit()
    return {"restored": True, "finding_id": finding_id}


@app.post("/findings/{finding_id}/delete")
async def delete_finding(
    finding_id: str,
    _body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    """Delete a result outright and force a fresh look at that listing.

    Unlike ignore, this RESETS the listing's breadcrumb — clearing the source
    fingerprints and last-suggestion hash the unchanged-skip relies on. The next
    crawl therefore re-reads and re-classifies the listing from scratch and
    writes a brand-new record, instead of skipping it as "nothing changed".
    """
    finding = await session.get(Finding, finding_id)
    if finding is None:
        raise HTTPException(status_code=404, detail="Unknown finding.")

    listing_id = finding.listing_id
    await session.delete(finding)
    await _reset_breadcrumbs(session, [listing_id])
    await session.commit()
    return {"deleted": 1, "listing_ids": [listing_id]}


@app.post("/findings/purge")
async def purge_findings(
    body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    """Bulk-delete results and force those listings to be re-checked.

    This is the "I changed the instructions, give me a clean slate" control:
    clear the muddied results, edit the prompt, re-run, and every returned
    finding is fresh. Filters are ANDed; an empty body would match everything, so
    at least one filter is required.

    Body: { status?, confidence?, run_id?, listing_ids?[] }
    """
    d = _json(body)
    status = d.get("status")
    confidence = d.get("confidence")
    run_id = d.get("run_id")
    listing_ids = [int(i) for i in (d.get("listing_ids") or [])]

    if not any([status, confidence, run_id, listing_ids]):
        raise HTTPException(
            status_code=400,
            detail="Refusing to purge every finding — pass at least one filter.",
        )

    stmt = select(Finding)
    if status:
        stmt = stmt.where(Finding.status == status)
    if confidence in ("high", "medium", "low"):
        stmt = stmt.where(Finding.confidence == confidence)
    if run_id:
        stmt = stmt.where(Finding.run_id == run_id)
    if listing_ids:
        stmt = stmt.where(Finding.listing_id.in_(listing_ids))

    rows = (await session.execute(stmt)).scalars().all()
    affected = sorted({f.listing_id for f in rows})
    for finding in rows:
        await session.delete(finding)
    await _reset_breadcrumbs(session, affected)
    await session.commit()
    return {"deleted": len(rows), "listing_ids": affected}


async def _reset_breadcrumbs(session: AsyncSession, listing_ids: list[int]) -> None:
    """Clear the skip-check state so the next crawl re-classifies these listings.

    Deliberately keeps last_crawled_at, decision_history and correction_memory —
    deleting a result discards the AI's *suggestion*, not our own review history.
    """
    if not listing_ids:
        return
    await session.execute(
        update(Breadcrumb)
        .where(Breadcrumb.listing_id.in_(listing_ids))
        .values(source_fingerprints={}, last_suggestion_hash="")
    )


@app.post("/decisions")
async def record_decision(
    body: bytes = Depends(verify_signature),
    session: AsyncSession = Depends(get_session),
) -> dict[str, Any]:
    d = _json(body)
    decision = Decision(
        finding_id=d.get("finding_id", ""),
        run_id=d.get("run_id", ""),
        listing_id=int(d.get("listing_id", 0) or 0),
        field=d.get("field", ""),
        action=d.get("action", ""),
        reason_code=d.get("reason_code", ""),
        suggested_value=d.get("suggested_value"),
        final_value=d.get("final_value"),
        note=d.get("note", ""),
        user=d.get("user", ""),
    )
    session.add(decision)

    # Reflect the decision on the finding. A PER-FIELD decision only retires the
    # finding once EVERY actionable field has been decided — so acting on one
    # suggested change never drops a listing's other unreviewed suggestions from
    # the queue. A listing-level decision (no specific field, or a defer) retires
    # the whole finding at once.
    finding_id = d.get("finding_id", "")
    action = d.get("action", "")
    field = (d.get("field") or "").strip()
    if finding_id and action in {"accepted", "rejected", "deferred", "dismissed"}:
        finding = await session.get(Finding, finding_id)
        if finding is not None and finding.status == "pending":
            row_level = action == "deferred" or field in ("", "status", "other")
            if row_level:
                finding.status = action
                finding.decided_at = _utcnow()
            else:
                payload = dict(finding.payload or {})
                resolved = list(payload.get("_resolved_fields", []))
                if field not in resolved:
                    resolved.append(field)
                payload["_resolved_fields"] = resolved
                finding.payload = payload  # reassign so the ORM persists the JSON change
                if _actionable_fields(payload).issubset(set(resolved)):
                    finding.status = "resolved"
                    finding.decided_at = _utcnow()
    await session.commit()
    return {"recorded": True}


def _actionable_fields(payload: dict[str, Any]) -> set[str]:
    """Fields in a finding a human must act on — applyable field suggestions,
    excluding no_change confirmations and status/other informational findings."""
    fields: set[str] = set()
    for det in payload.get("findings", []) or []:
        name = (det.get("field") or "").strip()
        if name and name not in ("status", "other") and det.get("change_label") != "no_change":
            fields.add(name)
    return fields


def _finding_dto(f: Finding) -> dict[str, Any]:
    payload = f.payload or {}
    return {
        "finding_id": f.id,
        "run_id": f.run_id,
        "listing_id": f.listing_id,
        "name": f.name,
        "status": f.status,
        "created_at": f.created_at.isoformat() if f.created_at else None,
        "decided_at": f.decided_at.isoformat() if f.decided_at else None,
        "is_premium": f.is_premium,
        "confidence": f.confidence,
        "durable_fact_conflict": f.durable_fact_conflict,
        "no_signal": f.no_signal,
        "labels": f.labels or [],
        "sources": f.sources or [],
        "findings": payload.get("findings", []),
        "operating_status": payload.get("operating_status", ""),
        "suggested_name": payload.get("suggested_name", ""),
        "suggested_address": payload.get("suggested_address", {}),
        "admin_note_relevant": bool(payload.get("admin_note_relevant", False)),
        "resolved_fields": payload.get("_resolved_fields", []),
    }
