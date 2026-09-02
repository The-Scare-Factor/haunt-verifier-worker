"""Image intelligence — candidate collection + likely-flyer scoring.

A large share of haunt ground truth (dates, closure/rename/relocation notices)
lives inside stylized flyer images, so this is a core capability, not an add-on.
We score candidates and send the most flyer-like ones to the vision model.

Heuristic (per the approved plan):
  + prominence  : large rendered size; OpenGraph image; appears early in the DOM
  + text-bearing: portrait/square aspect; filename hints (flyer/2026/schedule/…)
  - decoration  : logo/header/footer/icon/sprite/badge; very small images
Optional Tesseract OCR is used ONLY to BOOST ("text found → definitely send"),
never to exclude — stylized art routinely defeats OCR, so "no OCR text" ≠ "no
text".
"""

from __future__ import annotations

import base64
import io
from dataclasses import dataclass
from typing import Any

import httpx

from ..config import get_settings

_settings = get_settings()

_FLYER_HINTS = (
    "flyer",
    "schedule",
    "hours",
    "dates",
    "season",
    "2025",
    "2026",
    "tickets",
    "event",
    "announc",
    "poster",
)
_NEGATIVE_HINTS = (
    "logo",
    "header",
    "footer",
    "icon",
    "sprite",
    "badge",
    "avatar",
    "favicon",
    "banner-ad",
)
_SUPPORTED = {"JPEG": "image/jpeg", "PNG": "image/png", "GIF": "image/gif", "WEBP": "image/webp"}
_MAX_CANDIDATES = 20
_MAX_IMAGE_BYTES = 8_000_000


@dataclass
class ScoredImage:
    source: str
    url: str
    data: str          # base64
    media_type: str
    width: int
    height: int
    score: float
    has_text: bool = False


def _pre_score(url: str, width: int, height: int) -> float:
    score = 0.0
    long_edge = max(width, height)
    short_edge = min(width, height) or 1

    # Prominence by size.
    if long_edge >= _settings.min_flyer_edge_px:
        score += 1.0
    if long_edge >= 800:
        score += 0.5
    if long_edge < 200:
        score -= 1.0  # too small to carry readable announcement text

    # Aspect ratio: flyers are portrait/square, not thin banners.
    ratio = long_edge / short_edge
    if ratio <= 1.6:
        score += 0.6
    elif ratio >= 3.0:
        score -= 0.6  # likely a banner/strip

    lowered = url.lower()
    if any(h in lowered for h in _FLYER_HINTS):
        score += 0.8
    if any(h in lowered for h in _NEGATIVE_HINTS):
        score -= 1.2

    return score


async def _fetch_image(client: httpx.AsyncClient, url: str) -> tuple[bytes, str, int, int] | None:
    try:
        resp = await client.get(url)
    except httpx.HTTPError:
        return None
    if resp.status_code != 200:
        return None
    content = resp.content
    if not content or len(content) > _MAX_IMAGE_BYTES:
        return None

    try:
        from PIL import Image  # local import keeps Pillow optional at import time
    except ImportError:
        return None

    try:
        with Image.open(io.BytesIO(content)) as im:
            fmt = (im.format or "").upper()
            width, height = im.size
    except Exception:  # noqa: BLE001 - not a decodable image
        return None

    media_type = _SUPPORTED.get(fmt)
    if not media_type:
        return None
    return content, media_type, width, height


def _ocr_has_text(content: bytes) -> bool:
    """Cheap OCR presence check (boost only). Safe no-op if Tesseract is absent."""
    if not _settings.enable_tesseract_boost:
        return False
    try:
        import pytesseract
        from PIL import Image
    except ImportError:
        return False
    try:
        with Image.open(io.BytesIO(content)) as im:
            text = pytesseract.image_to_string(im)
        return len(text.strip()) >= 12
    except Exception:  # noqa: BLE001 - OCR/runtime issue; treat as no boost
        return False


async def gather_flyers(
    client: httpx.AsyncClient,
    candidates: list[tuple[str, str]],  # (source, url), ordered by prominence
    aggressiveness: str,
    max_flyers: int,
) -> list[dict[str, Any]]:
    """Fetch, score, and select the most flyer-like images for vision."""
    if aggressiveness == "off" or max_flyers <= 0:
        return []

    scored: list[ScoredImage] = []
    for order, (source, url) in enumerate(candidates[:_MAX_CANDIDATES]):
        fetched = await _fetch_image(client, url)
        if not fetched:
            continue
        content, media_type, width, height = fetched
        score = _pre_score(url, width, height)
        # Earlier-in-DOM (and OG image, which we inserted first) gets a nudge.
        score += max(0.0, 0.4 - order * 0.04)
        scored.append(
            ScoredImage(
                source=source,
                url=url,
                data=base64.standard_b64encode(content).decode("ascii"),
                media_type=media_type,
                width=width,
                height=height,
                score=score,
            )
        )

    if not scored:
        return []

    # OCR-boost a shortlist of the best pre-scored images.
    scored.sort(key=lambda s: s.score, reverse=True)
    shortlist = scored[: max_flyers + 3]
    for img in shortlist:
        if _ocr_has_text(base64.standard_b64decode(img.data)):
            img.has_text = True
            img.score += 0.7

    scored.sort(key=lambda s: s.score, reverse=True)

    threshold = {"weak_signal_only": 1.2, "plausible": 0.6, "all": -999.0}.get(aggressiveness, 0.6)
    selected = [s for s in scored if s.score >= threshold][:max_flyers]

    return [
        {
            "source": s.source,
            "url": s.url,
            "data": s.data,
            "media_type": s.media_type,
            "score": s.score,
            "has_text": s.has_text,
        }
        for s in selected
    ]
