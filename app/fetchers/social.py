"""Social fetchers — all five platforms, public-only.

Boundary (hard): public oEmbed endpoints, OpenGraph/meta on public URLs, and
public server-rendered HTML only. NO logged-in/credentialed scraping, NO
unofficial private APIs, NO block evasion. "Couldn't legitimately read it" is a
valid outcome — we record it as no signal and let Google snippets fill the gap.

Realistic yield: TikTok/YouTube oEmbed are reliable; Facebook/Instagram/X are
mostly login-gated, so we lean on OpenGraph meta where present and otherwise
defer to the Google-cache fetcher.
"""

from __future__ import annotations

from typing import Any

import httpx

from .http import fetch_url

OEMBED_ENDPOINTS = {
    "social_youtube": "https://www.youtube.com/oembed",
    "social_tiktok": "https://www.tiktok.com/oembed",
}

_LOGIN_GATE_HINTS = ("login", "/accounts/login", "signin", "/i/flow/login")


def _empty(source: str, url: str, signals: list[str]) -> dict[str, Any]:
    return {
        "source": source,
        "url": url,
        "final_url": "",
        "status_code": None,
        "ok": False,
        "text": "",
        "image_urls": [],
        "signals": signals,
    }


async def _oembed(client: httpx.AsyncClient, source: str, url: str) -> dict[str, Any] | None:
    endpoint = OEMBED_ENDPOINTS.get(source)
    if not endpoint:
        return None
    try:
        resp = await client.get(endpoint, params={"url": url, "format": "json"})
    except httpx.HTTPError:
        return None
    if resp.status_code != 200:
        return None
    try:
        data = resp.json()
    except ValueError:
        return None

    parts = [data.get("title", ""), f"by {data.get('author_name', '')}".strip()]
    text = " — ".join(p for p in parts if p).strip()
    images = [data["thumbnail_url"]] if data.get("thumbnail_url") else []
    return {
        "source": source,
        "url": url,
        "final_url": url,
        "status_code": 200,
        "ok": bool(text or images),
        "text": text,
        "image_urls": images,
        "signals": ["oembed_ok"],
    }


async def _og_meta(client: httpx.AsyncClient, source: str, url: str) -> dict[str, Any]:
    res = await fetch_url(client, url)
    signals = list(res.signals)

    final = (res.final_url or "").lower()
    if any(h in final for h in _LOGIN_GATE_HINTS):
        signals.append("login_gated")
        return _empty(source, url, signals)

    title = res.meta.get("og:title") or res.title
    desc = res.meta.get("og:description") or res.meta.get("description", "")
    text = " — ".join(p for p in (title, desc) if p).strip()
    images = []
    if res.meta.get("og:image"):
        images.append(res.meta["og:image"])

    if not text and not images:
        signals.append("no_public_signal")

    return {
        "source": source,
        "url": url,
        "final_url": res.final_url,
        "status_code": res.status_code,
        "ok": bool(text or images),
        "text": text,
        "image_urls": images,
        "signals": signals,
    }


async def fetch_social(client: httpx.AsyncClient, source: str, url: str) -> dict[str, Any]:
    """Fetch one social source by its slug (social_youtube, social_tiktok, …)."""
    # Prefer the public oEmbed endpoint where one exists and the URL is a
    # video/post (oEmbed rejects bare profile URLs, which we then OG-read).
    oembed = await _oembed(client, source, url)
    if oembed and oembed["ok"]:
        return oembed
    return await _og_meta(client, source, url)
