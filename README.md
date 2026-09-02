# Haunt Verifier — Worker (Python / FastAPI)

The heavy crawl + AI side of the Haunt Listing Verifier. WordPress owns the admin
UI and publishing; this worker does the fetching, image reading, and Anthropic
classification, writing findings to a staging Postgres DB. WordPress reads those
findings back over this worker's signed REST API.

This is the **Sevalla-relocatable boundary**: the worker + its Postgres can move
hosts without touching the WordPress plugin.

## What it does

1. Accepts an HMAC-signed crawl manifest from the plugin (scope + run config +
   compiled prompt + listing snapshots).
2. For each listing: fetch website + all five socials (public-only) + Google
   snippet fallback → pre-filter → score likely flyer images → classify with a
   tiered model (cheap text model, Opus 4.8 vision for flyers) → write a strict,
   schema-validated finding + breadcrumb.
3. Serves the High/Med/Low review queues and records admin decisions.

## Interactive vs. batch

Every run carries a `mode`. Both modes crawl identically and write identical
findings — only how the *classification* calls are billed and awaited differs.

| | `interactive` | `batch` |
|---|---|---|
| Classification call | one live request per listing | Message Batches API |
| Price | standard | **50%** |
| Findings appear | as each listing finishes | when the batch ends (usually <1h, 24h max) |
| Run status while working | `running` | `running` → `awaiting_batch` → `done` |

A batch run crawls everything first, streams the prepared requests out in
capped chunks (`FLUSH_REQUESTS` / `FLUSH_BYTES` in `queue.py`, so a
full-directory crawl can't balloon memory), and parks as `awaiting_batch`. The
batch ids are stored on the run row, so a worker restart resumes polling rather
than orphaning work that has already been paid for.

Both paths build their request through `build_request_spec()` in
`classify/anthropic_client.py`, so the prompt, model tiering and output schema
cannot drift apart between them. The one difference is mechanical: the
interactive path uses the `messages.parse()` helper with the Pydantic model,
while batch requests carry the equivalent raw `output_config` JSON schema
(`parse()` is a client-side helper with no batch equivalent).

Scheduled cron crawls always use `batch`. On-demand runs pick their mode in the
dashboard, so a one-off "check Ohio right now" sweep can still run interactively.

## Endpoints (all require a valid `X-HLV-Signature`)

| Method | Path | Purpose |
|---|---|---|
| GET  | `/health` | Connectivity probe |
| POST | `/crawls` | Create a run (metadata only) → `{run_id}` |
| POST | `/runs/{id}/listings` | Push a chunk of listing snapshots |
| POST | `/runs/{id}/start` | Mark the run ready to process |
| GET  | `/runs/{id}` | Run status + summary (counts + token split) |
| POST | `/listings/check` | Interactive single-listing check (inline finding) |
| GET  | `/findings` | Review queue (`?confidence=high&status=pending`; `status=ignored` is the archive) |
| POST | `/decisions` | Record an admin decision |
| POST | `/findings/{id}/ignore` | Park a result in the archive (breadcrumb kept) |
| POST | `/findings/{id}/restore` | Move an archived result back to the queue |
| POST | `/findings/{id}/delete` | Delete a result **and reset its breadcrumb** |
| POST | `/findings/purge` | Bulk delete + breadcrumb reset (filtered; never unfiltered) |

### Ignore vs. delete

The two exist because they answer different questions, and the difference is
entirely in what happens to the listing's **breadcrumb** — the source
fingerprints the unchanged-skip consults before paying for a classification.

* **Ignore** = "not now." The row is kept with `status=ignored` and the
  breadcrumb is left intact, so the listing still counts as verified and the same
  suggestion will not reappear until the haunt's own website/social changes.
  Restorable.
* **Delete** = "this result is garbage, look again." The row is removed and the
  breadcrumb is **reset**, so the next crawl re-reads and re-classifies the
  listing from scratch and writes a brand-new record instead of skipping it.

`/findings/purge` is the bulk form of delete, and the intended flow after
changing the prompt: clear the results the old instructions produced, edit the
rules, re-run, and the next queue contains only fresh findings. It refuses an
unfiltered purge — pass at least one of `status`, `confidence`, `run_id`, or
`listing_ids`.

Neither action ever touches a live WordPress listing.

## Local dev

```bash
python -m venv .venv && . .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env        # fill in keys + DATABASE_URL + WORKER_SECRET
uvicorn app.main:app --reload --port 8000
```

Tables auto-create on startup (v1). The optional Tesseract OCR boost needs the
`tesseract` binary installed; without it, flyer scoring still works (OCR is a
boost-only signal, never a gate).

---

## Sevalla setup guide (run once)

> Provider keys live **only** here. WordPress holds just the worker URL + the
> shared HMAC secret.

1. **Create the Postgres staging DB** in Sevalla (a managed Postgres add-on or a
   dedicated database app). Copy its internal connection string from the Sevalla
   dashboard and convert the scheme to the async driver — `postgres://` becomes
   `postgresql+asyncpg://`, everything after it unchanged:

   ```
   postgresql+asyncpg://USER:PASSWORD@HOST:5432/DBNAME
   ```

   Put it in `HLV_DATABASE_URL` (step 3). **Never paste the real string into this
   file or any other tracked file** — it carries the database password.
2. **Deploy this worker as a Sevalla Application** from the repo (Nixpacks,
   build path `.`). The build files are committed, so there is nothing to type in:
   - `runtime.txt` pins `python-3.11.9`. This is load-bearing — `anthropic` 1.x
     requires Python >= 3.10, so letting Nixpacks pick an older default breaks
     `pip install`.
   - `Procfile` declares `web: uvicorn app.main:app --host 0.0.0.0 --port $PORT`.
     Note `app.main:app`, not `main:app` — this project keeps its package in
     `app/` (the Haunt Advisor API next door has `main.py` at the root, hence its
     shorter path).
   - Build is the Nixpacks default, `pip install -r requirements.txt`.
   - The background worker loop runs **inside** the web process (started on
     app lifespan), so no separate process type is required for v1. If you later
     split it out, run a process that imports `app.queue.worker` and calls
     `worker.start()` against the same DB.
   - **The repo must have at least one commit before you link it.** Sevalla reads
     the default branch when connecting; against an empty repo it stores a blank
     branch and every deploy dies at `fatal: Remote branch  not found in upstream
     origin` (note the empty name). If you ever delete and recreate the repo,
     re-check both the branch selection and that the Sevalla GitHub App still
     lists the new repo — deleting a repo revokes its grant.
3. **Set environment secrets** (Sevalla → app → Environment):
   - `HLV_ANTHROPIC_API_KEY` — your pay-as-you-go Anthropic API key
     (separate from any Claude Pro subscription).
   - `HLV_GOOGLE_CSE_KEY` and `HLV_GOOGLE_CSE_CX` — Programmable Search Engine
     key + engine ID. The worker self-limits to
     `HLV_GOOGLE_DAILY_QUERY_CAP` (default 9,000/day) to stay under the
     10k/day/engine limit.
   - `HLV_WORKER_SECRET` — a long random string.
   - `HLV_DATABASE_URL` — from step 1.
4. **Wire WordPress → worker** (WP Admin → Haunt Verifier → Settings):
   - Worker URL = the deployed Sevalla app URL.
   - HMAC secret = the same `HLV_WORKER_SECRET`. Prefer defining it as
     `HLV_WORKER_SECRET` in `wp-config.php` over storing it in the DB.
   - Click **Test connection** — it should report "Worker reachable."
5. **Run the Indiana pilot** from the dashboard and review the first findings.

## Stranded-run recovery (the janitor)

Only `ready` runs get claimed, so a run killed while `running` — a restart or
crash during the crawl phase — would otherwise sit at `running` forever. The
janitor sweeps for those once a minute, alongside batch polling.

**How it tells "still working" from "abandoned":** the worker touches
`hlv_runs.heartbeat_at` every 60s while it processes a run. `updated_at` can't
serve here — during a long crawl the *jobs* get written, not the run row, so it
goes stale on a perfectly healthy run. A run qualifies as stranded when its
heartbeat is colder than `HLV_STALE_RUN_MINUTES` (default 10) and it is not the
run this process is actively crawling. A run that never heartbeated at all falls
back to `updated_at` against the same cutoff, so a row left by an older build is
still recovered without robbing a live one.

**What it does, by case:**

| State found | Action |
|---|---|
| Cold, listings still `queued` | back to `ready` — the loop re-claims it |
| Cold, nothing queued, batches out | → `awaiting_batch`, the poller collects them |
| Cold, nothing left at all | → `ready`; the next pass closes it out cleanly |
| Reclaimed `HLV_MAX_RUN_RECLAIMS` times (default 3) | → `error`, with a log line |

Re-readying is safe because the unit of work is the *job*, not the run:
`_queued_jobs()` only picks up rows still marked `queued`, so listings already
crawled — or already submitted to a batch — are never redone or re-billed. A
resumed batch run seeds its `batch_ids` from the row rather than starting empty,
so the second attempt appends to the earlier batches instead of overwriting them.

The reclaim cap is the circuit breaker: a listing that reliably kills the worker
parks its run as `error` rather than putting the queue in a crash loop.

## Notes / future phases

- **Multi-worker scale-out:** run-claiming is single-process safe; add
  `SELECT ... FOR UPDATE SKIP LOCKED` when claiming runs to run multiple workers.
- **Migrations:** `create_all` builds missing tables, and `_ADDED_COLUMNS` in
  `db.py` applies the idempotent `ADD COLUMN IF NOT EXISTS` statements that
  `create_all` cannot (it never alters an existing table). Swap in Alembic before
  the next schema change rather than growing that list.
