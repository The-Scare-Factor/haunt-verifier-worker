"""Tiered Anthropic classification.

- Cheap text model (Haiku 4.5 / Sonnet 4.6) when only page text is in play.
- Opus 4.8 vision when flyer images are present or we escalate a low-confidence
  text result.
- The fixed system prompt is prompt-cached so repeated listings in a run reuse
  the cached prefix (verify via cache_read_input_tokens).
- Output is constrained to the ClassificationResult schema via structured
  outputs, so the parseable shape is guaranteed regardless of prompt edits.

Both the interactive path (this module) and the Batch API path
(``batch_client``) build their request through :func:`build_request_spec`, so a
listing is classified identically whichever way it is submitted. The ONLY
difference is how the structured output is requested: the interactive path uses
the ``messages.parse()`` helper with the Pydantic model, while a batch request
must carry the equivalent raw ``output_config`` (``parse()`` is a client-side
helper and has no batch equivalent).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from anthropic import AsyncAnthropic

from ..config import get_settings
from .prompts import build_user_content
from .schema import ClassificationResult

# Models that accept adaptive thinking; we leave it off for Haiku to avoid 400s.
_THINKING_MODELS = {"claude-sonnet-4-6", "claude-opus-4-8", "claude-fable-5"}

_MAX_TOKENS = 4096


@dataclass
class ClassifyOutcome:
    result: ClassificationResult
    model: str
    path: str  # "text" | "image"
    usage: dict[str, int] = field(default_factory=dict)


@dataclass
class RequestSpec:
    """Everything needed to issue one classification request, either way."""

    model: str
    path: str  # "text" | "image"
    params: dict[str, Any]  # model/max_tokens/system/messages/thinking


def select_model(run_config: dict[str, Any], use_images: bool) -> str:
    settings = get_settings()
    if use_images:
        return run_config.get("image_model", settings.default_image_model)
    return run_config.get("text_model", settings.default_text_model)


def build_request_spec(
    system_prompt: str,
    listing: dict[str, Any],
    sources: list[dict[str, Any]],
    images: list[dict[str, Any]],
    run_config: dict[str, Any],
) -> RequestSpec:
    """Build the shared request body for one listing (no output config)."""
    use_images = bool(images)
    model = select_model(run_config, use_images)
    content = build_user_content(listing, sources, images)

    params: dict[str, Any] = {
        "model": model,
        "max_tokens": _MAX_TOKENS,
        # Cache the stable system prefix; per-listing data is in `messages`,
        # after the breakpoint, so it never invalidates the cache. This holds
        # for batch requests too — every request in a run shares this prefix.
        "system": [
            {
                "type": "text",
                "text": system_prompt,
                "cache_control": {"type": "ephemeral"},
            }
        ],
        "messages": [{"role": "user", "content": content}],
    }
    if model in _THINKING_MODELS:
        params["thinking"] = {"type": "adaptive"}

    return RequestSpec(model=model, path="image" if use_images else "text", params=params)


def _strictify(node: Any) -> Any:
    """Make a Pydantic-generated JSON Schema acceptable as a strict output format.

    Every object node must forbid extra properties and require all of its
    declared properties. Pydantic omits fields that carry defaults from
    ``required``; the structured-output contract wants them all present, and the
    model has an explicit value to give for each (empty string / empty list).
    """
    if isinstance(node, dict):
        out = {k: _strictify(v) for k, v in node.items()}
        if out.get("type") == "object" and isinstance(out.get("properties"), dict):
            out["additionalProperties"] = False
            out["required"] = list(out["properties"].keys())
        return out
    if isinstance(node, list):
        return [_strictify(v) for v in node]
    return node


def output_config() -> dict[str, Any]:
    """Raw structured-output config equivalent to ``output_format=ClassificationResult``.

    Used by the batch path, where the ``parse()`` helper is unavailable.
    """
    return {
        "format": {
            "type": "json_schema",
            "schema": _strictify(ClassificationResult.model_json_schema()),
        }
    }


def fallback_result(note: str) -> ClassificationResult:
    """A no-signal result, so an unparseable response routes to the manual queue
    instead of silently vanishing from the run."""
    return ClassificationResult(
        operating_status="unknown",
        name_match=True,
        address_match=True,
        website_status="not_checked",
        confidence="low",
        no_signal=True,
        notes=note,
    )


def parse_result_text(text: str) -> ClassificationResult | None:
    """Parse a raw structured-output response body into the contract model."""
    if not text:
        return None
    try:
        return ClassificationResult.model_validate_json(text)
    except Exception:  # noqa: BLE001 - malformed output is a data case, not a crash
        return None


def usage_dict(response: Any) -> dict[str, int]:
    u = getattr(response, "usage", None)
    if u is None:
        return {}
    return {
        "input_tokens": getattr(u, "input_tokens", 0) or 0,
        "output_tokens": getattr(u, "output_tokens", 0) or 0,
        "cache_creation_input_tokens": getattr(u, "cache_creation_input_tokens", 0) or 0,
        "cache_read_input_tokens": getattr(u, "cache_read_input_tokens", 0) or 0,
    }


def text_from_content(content: Any) -> str:
    """First text block of a response's content list."""
    for block in content or []:
        if getattr(block, "type", None) == "text":
            return getattr(block, "text", "") or ""
        if isinstance(block, dict) and block.get("type") == "text":
            return block.get("text", "") or ""
    return ""


class AnthropicClassifier:
    def __init__(self) -> None:
        self._settings = get_settings()
        self._client = AsyncAnthropic(api_key=self._settings.anthropic_api_key)

    async def classify(
        self,
        system_prompt: str,
        listing: dict[str, Any],
        sources: list[dict[str, Any]],
        images: list[dict[str, Any]],
        run_config: dict[str, Any],
    ) -> ClassifyOutcome:
        spec = build_request_spec(system_prompt, listing, sources, images, run_config)
        return await self.run_spec(spec)

    async def run_spec(self, spec: RequestSpec) -> ClassifyOutcome:
        """Issue an already-built request. The pipeline builds the spec once, so
        the interactive and batch paths cannot drift apart."""
        response = await self._client.messages.parse(
            **spec.params,
            output_format=ClassificationResult,
        )

        result = response.parsed_output
        if result is None:
            # Structured parse failed (refusal/malformed). Surface a no-signal
            # result so the pipeline routes it to the error/manual queue.
            result = fallback_result("Model did not return a parseable result.")

        return ClassifyOutcome(
            result=result,
            model=spec.model,
            path=spec.path,
            usage=usage_dict(response),
        )


__all__ = [
    "AnthropicClassifier",
    "ClassifyOutcome",
    "RequestSpec",
    "build_request_spec",
    "fallback_result",
    "output_config",
    "parse_result_text",
    "select_model",
    "text_from_content",
    "usage_dict",
]
