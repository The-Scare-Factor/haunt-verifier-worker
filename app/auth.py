"""HMAC verification of requests from the WordPress plugin.

The plugin signs ``timestamp + "\\n" + raw_body`` with HMAC-SHA256 using the
shared secret and sends the timestamp + hex signature in headers. We recompute
and compare in constant time, and reject stale timestamps to blunt replays.
"""

from __future__ import annotations

import hashlib
import hmac
import time

from fastapi import HTTPException, Request

from .config import get_settings


async def verify_signature(request: Request) -> bytes:
    """FastAPI dependency: verify the HMAC and return the raw body.

    Returning the body lets handlers parse it themselves without re-reading the
    stream (which would already be consumed).
    """
    settings = get_settings()
    if not settings.worker_secret:
        raise HTTPException(status_code=500, detail="Worker secret not configured.")

    timestamp = request.headers.get("X-HLV-Timestamp", "")
    signature = request.headers.get("X-HLV-Signature", "")
    if not timestamp or not signature:
        raise HTTPException(status_code=401, detail="Missing signature headers.")

    try:
        skew = abs(int(time.time()) - int(timestamp))
    except ValueError as exc:
        raise HTTPException(status_code=401, detail="Bad timestamp.") from exc
    if skew > settings.signature_max_skew_seconds:
        raise HTTPException(status_code=401, detail="Stale request.")

    body = await request.body()
    expected = hmac.new(
        settings.worker_secret.encode("utf-8"),
        timestamp.encode("utf-8") + b"\n" + body,
        hashlib.sha256,
    ).hexdigest()

    if not hmac.compare_digest(expected, signature):
        raise HTTPException(status_code=401, detail="Bad signature.")

    return body
