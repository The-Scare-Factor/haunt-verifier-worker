"""Shared async HTTP layer with sane timeouts + a polite, identifiable UA.

Bounded timeouts here are what stop a dead/slow haunt domain from hanging a
worker slot. We hit each host once per crawl, so this is about worker health,
not politeness.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

import httpx
from bs4 import BeautifulSoup

from ..config import get_settings

_settings = get_settings()

_PARKED_MARKERS = (
    "this domain is for sale",
    "buy this domain",
    "domain may be for sale",
    "parked free",
    "godaddy.com/domainsearch",
    "sedoparking",
    "hugedomains",
    "domain parking",
)
_CLOSED_MARKERS = (
    "permanently closed",
    "we are closed for good",
    "no longer in operation",
    "has closed its doors",
    "out of business",
)


@dataclass
class FetchResult:
    url: str
    ok: bool = False
    status_code: int | None = None
    final_url: str = ""
    content_type: str = ""
    html: str = ""
    text: str = ""
    title: str = ""
    meta: dict[str, str] = field(default_factory=dict)
    image_urls: list[str] = field(default_factory=list)
    signals: list[str] = field(default_factory=list)
    error: str = ""


def build_client() -> httpx.AsyncClient:
    timeout = httpx.Timeout(
        _settings.fetch_total_timeout,
        connect=_settings.fetch_connect_timeout,
        read=_settings.fetch_read_timeout,
    )
    return httpx.AsyncClient(
        timeout=timeout,
        follow_redirects=True,
        headers={"User-Agent": _settings.user_agent, "Accept-Language": "en-US,en;q=0.9"},
        limits=httpx.Limits(max_connections=24, max_keepalive_connections=8),
    )


def _visible_text(soup: BeautifulSoup) -> str:
    for tag in soup(["script", "style", "noscript", "template", "svg"]):
        tag.decompose()
    text = soup.get_text(separator=" ")
    return re.sub(r"\s+", " ", text).strip()


def _meta_tags(soup: BeautifulSoup) -> dict[str, str]:
    meta: dict[str, str] = {}
    for tag in soup.find_all("meta"):
        key = tag.get("property") or tag.get("name")
        val = tag.get("content")
        if key and val:
            meta[key.lower()] = val.strip()
    return meta


def _absolute(base: str, src: str) -> str:
    try:
        return str(httpx.URL(base).join(src))
    except Exception:  # noqa: BLE001 - malformed src, skip
        return ""


def parse_html(result: FetchResult) -> None:
    """Populate text/title/meta/image_urls/signals from result.html in place."""
    soup = BeautifulSoup(result.html, "lxml")

    if soup.title and soup.title.string:
        result.title = soup.title.string.strip()
    result.meta = _meta_tags(soup)
    result.text = _visible_text(soup)

    base = result.final_url or result.url
    images: list[str] = []
    for img in soup.find_all("img"):
        src = img.get("src") or img.get("data-src") or ""
        if not src:
            continue
        abs_url = _absolute(base, src)
        if abs_url:
            images.append(abs_url)
    # Include OpenGraph image as a strong flyer candidate.
    og_image = result.meta.get("og:image")
    if og_image:
        images.insert(0, _absolute(base, og_image) or og_image)
    # De-dup, preserve order.
    seen: set[str] = set()
    result.image_urls = [u for u in images if not (u in seen or seen.add(u))]

    lowered = result.text.lower()
    if any(m in lowered for m in _PARKED_MARKERS):
        result.signals.append("parked_markers")
    if any(m in lowered for m in _CLOSED_MARKERS):
        result.signals.append("closure_markers")


async def fetch_url(client: httpx.AsyncClient, url: str) -> FetchResult:
    """GET a URL and parse it if it is HTML."""
    res = FetchResult(url=url)
    try:
        resp = await client.get(url)
    except httpx.HTTPError as exc:
        res.error = str(exc)
        res.signals.append("fetch_error")
        return res

    res.status_code = resp.status_code
    res.final_url = str(resp.url)
    res.content_type = resp.headers.get("content-type", "")
    res.ok = 200 <= resp.status_code < 300

    if res.status_code in (404, 410):
        res.signals.append("http_404")
    elif res.status_code and res.status_code >= 500:
        res.signals.append("http_5xx")

    # Redirect to a clearly different registrable host = possible takeover.
    if _host(res.final_url) and _host(url) and _host(res.final_url) != _host(url):
        res.signals.append("redirected_host")

    if "html" in res.content_type.lower() and _body_safe(resp):
        res.html = resp.text
        parse_html(res)

    return res


def _body_safe(resp: httpx.Response) -> bool:
    """Guard against multi-MB bodies on slow links."""
    cl = resp.headers.get("content-length")
    return not (cl and cl.isdigit() and int(cl) > 5_000_000)


def _host(url: str) -> str:
    try:
        return httpx.URL(url).host or ""
    except Exception:  # noqa: BLE001
        return ""
