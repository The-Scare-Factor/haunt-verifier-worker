"""Tier 2 — Google Programmable Search Engine (Custom Search JSON API).

ToS-compliant search. Two uses: (a) find a current official source for a
dead/missing stored URL, and (b) surface cached snippet / last-post / 404
signal for gated social (FB/IG/X), where snippets often beat a direct fetch.

A per-day query cap (default 9,000, under the 10k/day/engine limit) is enforced
against the GoogleQuota table so we stay compliant. When the cap is hit we
return a 'quota_exhausted' signal instead of querying.
"""

from __future__ import annotations

import datetime as dt
from typing import Any

import httpx
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from ..config import get_settings
from ..store.models import GoogleQuota

_settings = get_settings()
_ENDPOINT = "https://www.googleapis.com/customsearch/v1"


async def _reserve_quota(session: AsyncSession) -> bool:
    """Atomically reserve one query for today. Returns False if the cap is hit."""
    today = dt.date.today()
    row = await session.get(GoogleQuota, today)
    if row is None:
        row = GoogleQuota(day=today, count=0)
        session.add(row)
        await session.flush()
    if row.count >= _settings.google_daily_query_cap:
        return False
    row.count += 1
    await session.flush()
    return True


async def search(
    client: httpx.AsyncClient,
    session: AsyncSession,
    query: str,
    num: int = 5,
) -> dict[str, Any]:
    """Run one CSE query, returning a normalized 'google_cache' source dict."""
    if not _settings.google_cse_key or not _settings.google_cse_cx:
        return _source(query, [], ["cse_not_configured"])

    if not await _reserve_quota(session):
        return _source(query, [], ["quota_exhausted"])

    try:
        resp = await client.get(
            _ENDPOINT,
            params={
                "key": _settings.google_cse_key,
                "cx": _settings.google_cse_cx,
                "q": query,
                "num": max(1, min(10, num)),
            },
        )
    except httpx.HTTPError as exc:
        return _source(query, [], [f"cse_error:{type(exc).__name__}"])

    if resp.status_code != 200:
        return _source(query, [], [f"cse_http_{resp.status_code}"])

    try:
        data = resp.json()
    except ValueError:
        return _source(query, [], ["cse_bad_json"])

    items = [
        {
            "title": it.get("title", ""),
            "link": it.get("link", ""),
            "snippet": it.get("snippet", ""),
        }
        for it in (data.get("items") or [])
    ]
    return _source(query, items, ["cse_ok"] if items else ["cse_no_results"])


def _source(query: str, items: list[dict[str, str]], signals: list[str]) -> dict[str, Any]:
    text_lines = [f"Google results for: {query}"]
    for it in items:
        text_lines.append(f"- {it['title']} — {it['snippet']} ({it['link']})")
    return {
        "source": "google_cache",
        "url": "",
        "final_url": "",
        "status_code": 200 if "cse_ok" in signals else None,
        "ok": bool(items),
        "text": "\n".join(text_lines),
        "image_urls": [],
        "signals": signals,
        "items": items,
    }
