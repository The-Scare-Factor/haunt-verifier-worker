"""Message Batches API client — the 50%-discount classification path.

Scheduled crawls and any run started in "batch" mode go through here. The crawl
itself (fetching website + socials + flyer images) still happens locally in the
worker; only the *classification* calls are submitted asynchronously.

Shape of a batch run:

1. The pipeline prepares every in-scope listing (fetch → skip check → pre-filter
   → flyer selection) and hands back a request body per listing that still needs
   Claude.
2. :meth:`submit` posts those as one or more batches and returns the batch ids.
3. The worker loop polls :meth:`status` until a batch has ``ended``, then streams
   :meth:`results` and writes the findings.

Results come back in ANY order, so every request carries a ``custom_id`` that
maps to its ``hlv_listing_jobs`` row — never rely on position.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any, AsyncIterator

from anthropic import AsyncAnthropic
from anthropic.types.message_create_params import MessageCreateParamsNonStreaming
from anthropic.types.messages.batch_create_params import Request

from ..config import get_settings
from .anthropic_client import (
    ClassifyOutcome,
    RequestSpec,
    fallback_result,
    output_config,
    parse_result_text,
    text_from_content,
    usage_dict,
)

log = logging.getLogger("hlv.batch")

# API ceilings are 100,000 requests / 256 MB per batch. We chunk well under both
# so a single oversized listing (many flyer images) can never tip a batch over.
MAX_REQUESTS_PER_BATCH = 10_000
MAX_BYTES_PER_BATCH = 96 * 1024 * 1024


@dataclass
class PreparedRequest:
    """One listing's classification request, ready to submit."""

    custom_id: str
    spec: RequestSpec


@dataclass
class BatchResult:
    """One result read back out of a completed batch."""

    custom_id: str
    outcome: ClassifyOutcome | None  # None when the request failed outright
    error: str = ""


def approx_size(params: dict[str, Any]) -> int:
    """Rough serialized size of a request, for chunking. Image blocks dominate,
    so measuring their base64 length is enough to keep batches under the cap."""
    total = 0
    for block in params.get("system", []) or []:
        total += len(block.get("text", "") or "")
    for message in params.get("messages", []) or []:
        content = message.get("content")
        if isinstance(content, str):
            total += len(content)
            continue
        for block in content or []:
            if not isinstance(block, dict):
                continue
            total += len(block.get("text", "") or "")
            source = block.get("source") or {}
            total += len(source.get("data", "") or "")
    return total


class AnthropicBatchClient:
    def __init__(self) -> None:
        settings = get_settings()
        self._client = AsyncAnthropic(api_key=settings.anthropic_api_key)

    async def submit(self, prepared: list[PreparedRequest]) -> list[str]:
        """Submit prepared requests, chunked. Returns the created batch ids."""
        batch_ids: list[str] = []
        for chunk in self._chunk(prepared):
            requests = [
                Request(
                    custom_id=item.custom_id,
                    params=MessageCreateParamsNonStreaming(
                        **item.spec.params,
                        output_config=output_config(),
                    ),
                )
                for item in chunk
            ]
            batch = await self._client.messages.batches.create(requests=requests)
            log.info("Submitted batch %s with %d requests", batch.id, len(requests))
            batch_ids.append(batch.id)
        return batch_ids

    @staticmethod
    def _chunk(prepared: list[PreparedRequest]) -> list[list[PreparedRequest]]:
        chunks: list[list[PreparedRequest]] = []
        current: list[PreparedRequest] = []
        current_bytes = 0
        for item in prepared:
            size = approx_size(item.spec.params)
            too_many = len(current) >= MAX_REQUESTS_PER_BATCH
            too_big = current and (current_bytes + size) > MAX_BYTES_PER_BATCH
            if too_many or too_big:
                chunks.append(current)
                current = []
                current_bytes = 0
            current.append(item)
            current_bytes += size
        if current:
            chunks.append(current)
        return chunks

    async def status(self, batch_id: str) -> str:
        """``in_progress`` | ``canceling`` | ``ended``."""
        batch = await self._client.messages.batches.retrieve(batch_id)
        return batch.processing_status

    async def results(self, batch_id: str) -> AsyncIterator[BatchResult]:
        """Stream one BatchResult per request in a completed batch."""
        async for entry in await self._client.messages.batches.results(batch_id):
            yield self._to_result(entry)

    async def cancel(self, batch_id: str) -> None:
        try:
            await self._client.messages.batches.cancel(batch_id)
        except Exception:  # noqa: BLE001 - cancelling is best-effort cleanup
            log.exception("Could not cancel batch %s", batch_id)

    @staticmethod
    def _to_result(entry: Any) -> BatchResult:
        custom_id = getattr(entry, "custom_id", "")
        result = getattr(entry, "result", None)
        kind = getattr(result, "type", "")

        if kind != "succeeded":
            # errored | canceled | expired — all mean "no classification".
            # On `errored`, `result.error` is an ErrorResponse wrapper; the useful
            # type ("invalid_request_error", "rate_limit_error", …) is one level
            # further in, on its own `.error`.
            error = kind or "unknown"
            wrapper = getattr(result, "error", None)
            if wrapper is not None:
                inner = getattr(wrapper, "error", None)
                detail = getattr(inner, "type", None) or getattr(wrapper, "type", "")
                message = getattr(inner, "message", "")
                error = ": ".join(part for part in (kind, detail, message) if part)
            return BatchResult(custom_id=custom_id, outcome=None, error=error[:500])

        message = getattr(result, "message", None)
        text = text_from_content(getattr(message, "content", None))
        parsed = parse_result_text(text)
        if parsed is None:
            parsed = fallback_result("Batch response was not parseable.")

        return BatchResult(
            custom_id=custom_id,
            outcome=ClassifyOutcome(
                result=parsed,
                model=getattr(message, "model", "") or "",
                # Recovered from the job row at write time; the response itself
                # doesn't say whether flyers were attached.
                path="text",
                usage=usage_dict(message),
            ),
        )
