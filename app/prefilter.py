"""Cheap pre-filter — decide whether there is real signal to interpret before
spending AI tokens. We don't pay Claude to certify silence across thousands of
listings; no-signal listings route per the admin's no-signal toggle.
"""

from __future__ import annotations

from typing import Any

# Signals that are themselves worth a classification even with thin text.
_NOTABLE = {"http_404", "parked_markers", "closure_markers", "redirected_host", "http_5xx"}


def assess(sources: list[dict[str, Any]], candidate_images: list[tuple[str, str]]) -> dict[str, Any]:
    """Return { has_signal, reasons } for a listing's fetched sources."""
    reasons: list[str] = []
    has_text = False

    for s in sources:
        if (s.get("text") or "").strip():
            has_text = True
            reasons.append(f"text:{s.get('source')}")
        for sig in s.get("signals", []):
            if sig in _NOTABLE:
                reasons.append(f"{sig}:{s.get('source')}")

    if candidate_images:
        reasons.append(f"images:{len(candidate_images)}")

    has_signal = has_text or bool(candidate_images) or any(
        r.split(":")[0] in _NOTABLE for r in reasons
    )
    return {"has_signal": has_signal, "reasons": reasons}
