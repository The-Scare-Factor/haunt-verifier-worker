"""Run-summary aggregation: scannable counts + token spend split (text vs image)
so an admin can grasp a run at a glance and tune image aggressiveness against cost.
"""

from __future__ import annotations

from typing import Any

_KEYS = (
    "fetched_ok",
    "dead_urls",
    "images_analyzed",
    "suggested",
    "no_signal",
    "skipped",
    "errors",
    "text_tokens",
    "image_tokens",
)


def new_accumulator() -> dict[str, int]:
    return {k: 0 for k in _KEYS}


def merge(acc: dict[str, int], stats: dict[str, Any]) -> None:
    for k in _KEYS:
        acc[k] += int(stats.get(k, 0) or 0)


def finalize(acc: dict[str, int], listing_count: int) -> dict[str, Any]:
    out: dict[str, Any] = dict(acc)
    out["listings"] = listing_count
    out["total_tokens"] = acc["text_tokens"] + acc["image_tokens"]
    return out
