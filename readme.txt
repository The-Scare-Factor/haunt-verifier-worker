=== Haunt Listing Verifier ===
Contributors: thescarefactor
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later

Automated crawl + AI verification of haunt listings (website + social), with a
human-reviewed dashboard. The heavy crawl/AI work runs in a separate Python
worker on Sevalla; this plugin owns the admin UI, crawl triggers, and the
publishing of approved changes. No silent writes to production.

== Architecture ==

* WordPress plugin (this) — admin dashboard, settings, crawl triggers, Ajax
  publish/unpublish/delete, decision log. System of record for LIVE listings.
* Python/FastAPI worker (../haunt-verifier-worker) — fetching, image reading,
  Anthropic classification. Writes findings to a staging Postgres DB.
* Provider keys (Anthropic, Google) live ONLY in the worker's Sevalla
  environment. WordPress holds just the worker URL + a shared HMAC secret.

== Install ==

1. Copy this folder to wp-content/plugins/ and activate it. Activation grants
   the `manage_haunt_verifier` capability to Administrators and creates the
   local decision-log table.
2. (Recommended) Add the shared secret to wp-config.php instead of the DB:
       define( 'HLV_WORKER_SECRET', 'the-same-long-random-string-as-the-worker' );
3. Deploy the worker (see ../haunt-verifier-worker/README.md) and set its env
   secrets on Sevalla.
4. WP Admin -> Haunt Verifier -> Settings: enter the Worker URL, confirm the
   secret, choose models, then click "Test connection".

== Smallest end-to-end test (the Indiana pilot) ==

1. Haunt Verifier -> Review Queue -> "Start a crawl" -> Indiana pilot ->
   choose "Interactive" for this first run (you want to watch it) -> Start.
2. As the worker processes, refresh; findings appear in the High/Medium/Low
   queues. Expand a row to see suggested values, citations, and evidence
   (including text read out of flyer images).
3. Edit a suggested value if needed and click "Apply this change" — confirm the
   live listing updates and the decision is logged. Confirm a stylized flyer's
   dates were read correctly before running a wider crawl.

== Processing modes ==

Each run picks one, and either can be scoped to any state/region on demand:

* Batch (default) — the AI checks go through Anthropic's Batch API at HALF the
  standard rate. The worker crawls straight away, then waits on the batch;
  findings usually land within an hour (24 hours at the outside). The run card
  shows "waiting on Anthropic" in the meantime.
* Interactive — full price, findings appear as each listing finishes. Best for
  a handful of listings you want to sit and watch.

Both modes crawl the same way and produce identical findings. Scheduled cron
crawls always use batch.

== Acting on a result ==

Row-level actions that never touch the live listing:

* Ignore (save for later) — parks the result in "Past findings". The listing
  keeps its crawl breadcrumb, so this suggestion stays out of the queue until
  the haunt's own website/social actually changes. Restorable any time.
* Delete this result — removes the record AND resets that listing's crawl
  state, so the next crawl re-reads it from scratch and produces a brand-new
  record. Use it when a result came out of instructions you have since changed.

"Clear results" does the same thing in bulk (by review status, confidence tier,
or run). The intended flow after editing the AI's rules: clear the stale
results, change the instructions, re-run — and the new queue holds only fresh
findings, with nothing from the old rules mixed in.

== Notes ==

* Closure = revert to draft (reversible) + a stored closure reason; never trash.
* Premium (paying) listings are badged for extra caution.
* Auto-approve ships OFF; durable website/social conflicts always need a human.
* "Delete this result" and "Delete listing" are different buttons: the first
  discards a finding, the second trashes the actual WordPress listing.
