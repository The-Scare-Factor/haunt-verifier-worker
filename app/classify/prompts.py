"""Assembles the per-listing user content from fetched sources + images.

The SYSTEM prompt (task framing + operator guidance) arrives compiled from the
WordPress manifest. This module only builds the volatile, per-listing user turn
that goes AFTER the cached system prefix.
"""

from __future__ import annotations

import datetime as dt
from typing import Any

# Keep per-source text bounded so one bloated page can't blow the context window.
MAX_SOURCE_CHARS = 6000


def _format_stored(listing: dict[str, Any]) -> str:
    addr = listing.get("address", {}) or {}
    existing = listing.get("existing", {}) or {}
    today = dt.date.today().isoformat()
    lines = [
        f"TODAY'S DATE: {today}  (use this to judge past vs. upcoming dates)",
        "",
        "STORED DIRECTORY RECORD (what we currently have):",
        f"- Name: {listing.get('name', '')}",
        f"- Address: {addr.get('street', '')}, {addr.get('city', '')}, "
        f"{addr.get('state', '')} {addr.get('zip', '')} {addr.get('country', '')}".strip(),
        f"- Premium (paying) listing: {'yes' if listing.get('is_premium') else 'no'}",
    ]
    urls = listing.get("urls", {}) or {}
    if urls:
        lines.append("- Stored URLs:")
        for source, url in urls.items():
            lines.append(f"    - {source}: {url}")

    # "What we already know" — the model must NOT re-suggest any of this.
    dates_text = (existing.get("dates_hours_text") or "").strip()
    if dates_text:
        expires = existing.get("dates_hours_expires") or "?"
        lines.append(f"- Stored dates/hours (expires {expires}) — already recorded: {dates_text}")
    off_season = existing.get("off_season_events") or []
    if off_season:
        lines.append("- Stored off-season events (already recorded — do NOT re-suggest these):")
        for e in off_season:
            lines.append(
                f"    - {e.get('holiday', '')}: {e.get('start', '')} → {e.get('end', '')}".rstrip()
            )
    note = (existing.get("internal_notes") or "").strip()
    if note:
        lines.append(
            f"- ADMIN NOTE (internal — what we already know / are tracking): {note}\n"
            "    If a finding is already explained by this note, set addressed_by_admin_note=true "
            "and admin_note_relevant=true, and prefer change_label=no_change."
        )
    return "\n".join(lines)


def _format_sources(sources: list[dict[str, Any]]) -> str:
    """Render the fetched text sources (website + social + google) as text."""
    if not sources:
        return "NO READABLE TEXT SOURCES were retrieved."
    blocks = ["FETCHED SOURCES:"]
    for s in sources:
        text = (s.get("text") or "").strip()
        if len(text) > MAX_SOURCE_CHARS:
            text = text[:MAX_SOURCE_CHARS] + " …[truncated]"
        header = f"--- source={s.get('source')} url={s.get('url', '')} " \
                 f"http={s.get('status_code', '')} final_url={s.get('final_url', '')} ---"
        blocks.append(header)
        if s.get("signals"):
            blocks.append(f"(pre-filter signals: {', '.join(s['signals'])})")
        blocks.append(text or "(no extractable text)")
    return "\n".join(blocks)


def build_user_content(
    listing: dict[str, Any],
    sources: list[dict[str, Any]],
    images: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Build the Anthropic ``content`` array for the user turn.

    Text first, then each flyer image preceded by a label so the model knows
    which source/URL it came from (for provenance).
    """
    text = "\n\n".join(
        [
            _format_stored(listing),
            _format_sources(sources),
            (
                "TASK: Decide this listing's operating status and whether its name, "
                "address, website, social, or dates/hours have changed, citing evidence. "
                "Read the images below as carefully as the text — flyers often carry the "
                "real dates/announcements. Apply the date and admin-note rules in the "
                "system instructions: dates already in the past are a sign of life, not an "
                "update; route off-season dates to off_season_events; never re-suggest "
                "anything already in the stored record above. Return the structured result."
            ),
        ]
    )

    content: list[dict[str, Any]] = [{"type": "text", "text": text}]

    for img in images:
        label = (
            f"Image from source={img.get('source')} url={img.get('url', '')} "
            f"(flyer-score={img.get('score', 0):.2f}):"
        )
        content.append({"type": "text", "text": label})
        content.append(
            {
                "type": "image",
                "source": {
                    "type": "base64",
                    "media_type": img.get("media_type", "image/jpeg"),
                    "data": img["data"],
                },
            }
        )

    return content
