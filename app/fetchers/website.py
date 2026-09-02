"""Website fetcher — the durable source of truth.

Detects 404 / dead / parked / redirect-to-other-business and surfaces the page
text + candidate images for the classifier and image module.
"""

from __future__ import annotations

from typing import Any

import httpx

from .http import FetchResult, fetch_url


def _classify_website_status(res: FetchResult) -> str:
    if "http_404" in res.signals or "fetch_error" in res.signals:
        return "dead"
    if "parked_markers" in res.signals:
        return "parked"
    if "redirected_host" in res.signals:
        return "redirected_other_business"
    return "live" if res.ok else "dead"


async def fetch_website(client: httpx.AsyncClient, url: str) -> dict[str, Any]:
    res = await fetch_url(client, url)
    return {
        "source": "website",
        "url": url,
        "final_url": res.final_url,
        "status_code": res.status_code,
        "ok": res.ok,
        "title": res.title,
        "text": res.text,
        "meta": res.meta,
        "image_urls": res.image_urls,
        "signals": res.signals,
        "website_status": _classify_website_status(res),
        "error": res.error,
    }
