"""Per-listing verification pipeline: fetch → pre-filter → (AI classify) → store.

This is the heart of the worker. It is split into two halves so the same logic
serves both classification paths:

* :func:`prepare_listing` — the crawl half. Fetch, breadcrumb skip-check,
  pre-filter, flyer selection, and building the classification request. Ends
  either with a request to send or with a terminal outcome (skipped/no-signal)
  that needs no model call at all.
* :func:`store_classification` — the write half. Turns a ClassificationResult
  into a Finding + Breadcrumb.

:func:`process_listing` composes the two for the interactive path. The batch
path calls them separately, with an Anthropic batch in between.
"""

from __future__ import annotations

import datetime as dt
import hashlib
import uuid
from dataclasses import dataclass, field
from typing import Any

import httpx
from sqlalchemy import update
from sqlalchemy.ext.asyncio import AsyncSession

from .classify.anthropic_client import AnthropicClassifier, RequestSpec, build_request_spec
from .classify.schema import ClassificationResult
from .config import get_settings
from .fetchers import google_cse, images, social, website
from .prefilter import assess
from .store.models import Breadcrumb, Finding

_settings = get_settings()

_GATED_SIGNALS = {"login_gated", "no_public_signal", "fetch_error", "http_404"}


def _now() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


def _confidence_to_tier(result: ClassificationResult) -> str:
    return result.confidence.value


def _fingerprints(sources: list[dict[str, Any]]) -> dict[str, str]:
    """Per-source content fingerprint. Folds in candidate image URLs so a NEW
    flyer on otherwise-unchanged text still changes the hash (and is not skipped).
    """
    out: dict[str, str] = {}
    for s in sources:
        basis = (s.get("text") or "") + "\n" + "\n".join(sorted(s.get("image_urls") or []))
        out[s["source"]] = hashlib.sha256(basis.encode("utf-8")).hexdigest()[:16]
    return out


def _finding_hash(result: ClassificationResult) -> str:
    parts = [result.operating_status.value, result.suggested_name or "", result.website_status.value]
    for f in result.findings:
        parts.append(f"{f.change_label.value}:{f.field.value}:{f.suggested_value}")
    return hashlib.sha256("|".join(parts).encode("utf-8")).hexdigest()[:32]


async def _fetch_sources(
    client: httpx.AsyncClient,
    session: AsyncSession,
    listing: dict[str, Any],
) -> tuple[list[dict[str, Any]], list[tuple[str, str]]]:
    """Fetch website + social, then Google for dead/gated gaps. Returns
    (sources, candidate_images as (source, url) pairs ordered by prominence)."""
    sources: list[dict[str, Any]] = []
    urls: dict[str, str] = listing.get("urls", {}) or {}

    # Website first (durable source of truth).
    website_dead = False
    if urls.get("website"):
        ws = await website.fetch_website(client, urls["website"])
        sources.append(ws)
        website_dead = ws.get("website_status") in ("dead", "parked", "redirected_other_business")

    # All five social platforms, public-only.
    gated_platforms: list[str] = []
    for source_slug, url in urls.items():
        if source_slug == "website":
            continue
        sc = await social.fetch_social(client, source_slug, url)
        sources.append(sc)
        if not sc.get("ok") and any(sig in _GATED_SIGNALS for sig in sc.get("signals", [])):
            gated_platforms.append(source_slug)

    # Google snippet fallback: when the website is dead/missing, or social is gated.
    if website_dead or not urls.get("website") or gated_platforms:
        query = _build_query(listing)
        g = await google_cse.search(client, session, query, num=5)
        if g.get("ok"):
            sources.append(g)

    # Collect candidate images, OG/first-in-DOM order preserved per source.
    candidates: list[tuple[str, str]] = []
    for s in sources:
        for img_url in s.get("image_urls", []) or []:
            candidates.append((s["source"], img_url))

    return sources, candidates


def _build_query(listing: dict[str, Any]) -> str:
    addr = listing.get("address", {}) or {}
    bits = [f'"{listing.get("name", "")}"', addr.get("city", ""), addr.get("state", ""), "haunted attraction"]
    return " ".join(b for b in bits if b).strip()


def _new_stats() -> dict[str, Any]:
    return {
        "fetched_ok": 0,
        "dead_urls": 0,
        "images_analyzed": 0,
        "suggested": 0,
        "no_signal": 0,
        "skipped": 0,
        "errors": 0,
        "text_tokens": 0,
        "image_tokens": 0,
    }


@dataclass
class PreparedListing:
    """Output of the crawl half.

    ``spec`` is None when the listing needs no model call at all — it was skipped
    as unchanged, or it produced a no-signal finding already written to the DB.
    """

    stats: dict[str, Any]
    fingerprints: dict[str, str] = field(default_factory=dict)
    spec: RequestSpec | None = None


async def prepare_listing(
    session: AsyncSession,
    client: httpx.AsyncClient,
    run_id: str,
    listing: dict[str, Any],
    run_config: dict[str, Any],
    system_prompt: str,
) -> PreparedListing:
    """Crawl half: fetch → skip-check → pre-filter → flyers → build the request."""
    stats = _new_stats()

    listing_id = int(listing["listing_id"])
    sources, candidates = await _fetch_sources(client, session, listing)
    stats["fetched_ok"] = sum(1 for s in sources if s.get("ok"))
    stats["dead_urls"] = sum(
        1 for s in sources if s.get("source") == "website" and not s.get("ok")
    )

    # Breadcrumb skip: if every source fingerprint matches the last successful
    # crawl, the content hasn't changed — don't re-pay Claude. The existing
    # finding (pending or already actioned) stands. Fetching is cheap; the AI
    # call is what we save. Single interactive checks pass skip_unchanged=False,
    # and DELETING a result clears the breadcrumb so this can't skip it.
    fingerprints = _fingerprints(sources)
    if run_config.get("skip_unchanged"):
        crumb = await session.get(Breadcrumb, listing_id)
        if (
            crumb is not None
            and crumb.last_suggestion_hash
            and crumb.source_fingerprints == fingerprints
        ):
            crumb.last_crawled_at = _now()
            await session.flush()
            stats["skipped"] = 1
            return PreparedListing(stats=stats, fingerprints=fingerprints)

    pre = assess(sources, candidates)
    aggressiveness = run_config.get("image_aggressiveness", "plausible")
    max_flyers = int(run_config.get("max_flyers", 4))
    no_signal_mode = run_config.get("no_signal_mode", "manual_queue")

    # No-signal handling (admin toggle).
    if not pre["has_signal"]:
        stats["no_signal"] = 1
        if no_signal_mode == "manual_queue":
            await _persist_no_signal(session, run_id, listing, pre["reasons"])
            return PreparedListing(stats=stats, fingerprints=fingerprints)
        # else fall through to a single cheap pass

    # Select flyers for vision (skip when text signal is strong & mode is weak-only).
    flyers: list[dict[str, Any]] = []
    if aggressiveness != "off":
        flyers = await images.gather_flyers(client, candidates, aggressiveness, max_flyers)
    stats["images_analyzed"] = len(flyers)

    spec = build_request_spec(system_prompt, listing, sources, flyers, run_config)
    return PreparedListing(stats=stats, fingerprints=fingerprints, spec=spec)


async def store_classification(
    session: AsyncSession,
    run_id: str,
    listing: dict[str, Any],
    result: ClassificationResult,
    fingerprints: dict[str, str],
    path: str,
    usage: dict[str, int],
) -> dict[str, Any]:
    """Write half: persist the Finding + Breadcrumb. Returns the stats delta.

    Identical for both paths, so a batch-classified listing is indistinguishable
    from an interactively-classified one once it lands in the review queue.
    """
    delta = _new_stats()
    delta[f"{path}_tokens"] = usage.get("input_tokens", 0) + usage.get("output_tokens", 0)
    delta["suggested"] = len(result.findings)

    finding_hash = _finding_hash(result)
    await _persist_finding(session, run_id, listing, result, finding_hash)
    await _update_breadcrumb(session, int(listing["listing_id"]), fingerprints, finding_hash, result)
    return delta


async def process_listing(
    session: AsyncSession,
    classifier: AnthropicClassifier,
    client: httpx.AsyncClient,
    run_id: str,
    listing: dict[str, Any],
    run_config: dict[str, Any],
    system_prompt: str,
) -> dict[str, Any]:
    """Interactive path: crawl, classify immediately, store. Returns run stats."""
    prepared = await prepare_listing(
        session=session,
        client=client,
        run_id=run_id,
        listing=listing,
        run_config=run_config,
        system_prompt=system_prompt,
    )
    if prepared.spec is None:
        return prepared.stats

    outcome = await classifier.run_spec(prepared.spec)
    delta = await store_classification(
        session=session,
        run_id=run_id,
        listing=listing,
        result=outcome.result,
        fingerprints=prepared.fingerprints,
        path=outcome.path,
        usage=outcome.usage,
    )

    stats = dict(prepared.stats)
    for key in ("suggested", "text_tokens", "image_tokens"):
        stats[key] = delta[key]
    return stats


async def _supersede_pending(session: AsyncSession, listing_id: int) -> None:
    """Retire any still-pending finding for this listing before writing a fresh
    one, so re-running a crawl never stacks duplicate rows in the review queue.
    """
    await session.execute(
        update(Finding)
        .where(Finding.listing_id == listing_id, Finding.status == "pending")
        .values(status="superseded")
    )


async def _persist_finding(
    session: AsyncSession,
    run_id: str,
    listing: dict[str, Any],
    result: ClassificationResult,
    finding_hash: str,
) -> None:
    await _supersede_pending(session, int(listing["listing_id"]))
    finding = Finding(
        id=uuid.uuid4().hex,
        run_id=run_id,
        listing_id=int(listing["listing_id"]),
        name=listing.get("name", ""),
        is_premium=bool(listing.get("is_premium")),
        confidence=_confidence_to_tier(result),
        durable_fact_conflict=result.durable_fact_conflict,
        no_signal=result.no_signal,
        labels=result.labels() or (["no_change"] if not result.findings else []),
        sources=result.sources(),
        payload=result.model_dump(mode="json"),
        finding_hash=finding_hash,
        status="pending",
    )
    session.add(finding)
    await session.flush()


async def _persist_no_signal(
    session: AsyncSession,
    run_id: str,
    listing: dict[str, Any],
    reasons: list[str],
) -> None:
    await _supersede_pending(session, int(listing["listing_id"]))
    finding = Finding(
        id=uuid.uuid4().hex,
        run_id=run_id,
        listing_id=int(listing["listing_id"]),
        name=listing.get("name", ""),
        is_premium=bool(listing.get("is_premium")),
        confidence="low",
        no_signal=True,
        labels=["unreadable"],
        sources=[],
        payload={"no_signal": True, "reasons": reasons},
        status="pending",
    )
    session.add(finding)
    await session.flush()


async def _update_breadcrumb(
    session: AsyncSession,
    listing_id: int,
    fingerprints: dict[str, str],
    finding_hash: str,
    result: ClassificationResult,
) -> None:
    crumb = await session.get(Breadcrumb, listing_id)
    if crumb is None:
        crumb = Breadcrumb(listing_id=listing_id)
        session.add(crumb)

    crumb.last_crawled_at = _now()
    crumb.last_suggestion_hash = finding_hash
    crumb.last_status = result.operating_status.value
    crumb.source_fingerprints = fingerprints
    await session.flush()
