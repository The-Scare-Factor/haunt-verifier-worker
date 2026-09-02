"""The strict classification contract.

This Pydantic model IS the protected JSON shape. We hand it to Claude via
structured outputs, so no amount of operator prompt editing can change the
parseable structure — the worker guarantees it.
"""

from __future__ import annotations

from enum import Enum

from pydantic import BaseModel, Field


class OperatingStatus(str, Enum):
    open = "open"
    closed_permanently = "closed_permanently"
    closed_for_season = "closed_for_season"
    relocated = "relocated"
    unknown = "unknown"


class WebsiteStatus(str, Enum):
    ok = "ok"
    live = "live"
    dead = "dead"
    parked = "parked"
    redirected_other_business = "redirected_other_business"
    not_checked = "not_checked"


class Confidence(str, Enum):
    high = "high"
    medium = "medium"
    low = "low"


class FindingType(str, Enum):
    durable = "durable"
    time_sensitive = "time_sensitive"


class ChangeLabel(str, Enum):
    name_change = "name_change"
    bad_website = "bad_website"
    new_address = "new_address"
    likely_closed = "likely_closed"
    closed_for_season = "closed_for_season"
    relocated = "relocated"
    renamed = "renamed"
    website_social_conflict = "website_social_conflict"
    time_sensitive_notice = "time_sensitive_notice"
    no_change = "no_change"
    unreadable = "unreadable"


class FieldName(str, Enum):
    name = "name"
    address = "address"  # compound; applied via the top-level suggested_address
    status = "status"    # routed to row-level unpublish/defer, not apply_field
    website = "website"
    facebook = "facebook"
    instagram = "instagram"
    tiktok = "tiktok"
    youtube_channel = "youtube_channel"
    x_twitter = "x_twitter"
    dates_hours = "dates_hours"
    other = "other"


class EventScope(str, Enum):
    """Which calendar bucket a dated finding belongs to (Round 2)."""

    haunt_season = "haunt_season"          # ~Aug 15–Nov 15 → dates/hours fields
    off_season_holiday = "off_season_holiday"  # outside that window → off_season_events repeater
    past_event = "past_event"              # already elapsed → not actionable (sign of life only)
    none = "none"                          # finding is not date-bound


class Source(str, Enum):
    website = "website"
    social_facebook = "social_facebook"
    social_instagram = "social_instagram"
    social_tiktok = "social_tiktok"
    social_youtube = "social_youtube"
    social_x = "social_x"
    google_cache = "google_cache"


class Modality(str, Enum):
    page_text = "page_text"
    image = "image"


class SuggestedAddress(BaseModel):
    street: str = ""
    city: str = ""
    state: str = ""
    zip: str = ""
    country: str = ""


class FindingDetail(BaseModel):
    finding_type: FindingType
    change_label: ChangeLabel
    field: FieldName
    suggested_value: str = ""
    conflicting_value: str = ""  # social value when a durable fact conflicts
    source: Source
    modality: Modality
    evidence_snippet: str = ""   # includes text transcribed out of images
    evidence_url: str = ""
    image_url: str = ""          # direct URL of the cited flyer/calendar image (for sideload)
    confidence: Confidence

    # Date-aware routing (Round 2). For dates_hours findings the writer must set
    # the conditionally-required expiration field, so the model reports the last
    # event date. Off-season events carry their own holiday + start/end so the
    # dashboard can write a repeater row on Apply.
    event_scope: EventScope = EventScope.none
    dates_hours_expiration: str = ""  # ISO date (YYYY-MM-DD) of the LAST event covered by the text
    event_holiday: str = ""           # holiday_tag name for an off-season event
    event_start: str = ""             # ISO datetime for an off-season event
    event_end: str = ""               # ISO datetime for an off-season event
    addressed_by_admin_note: bool = False  # the listing's internal note already covers this


class ClassificationResult(BaseModel):
    """Top-level per-listing result Claude must return."""

    operating_status: OperatingStatus
    name_match: bool
    suggested_name: str = ""
    address_match: bool
    suggested_address: SuggestedAddress = Field(default_factory=SuggestedAddress)
    website_status: WebsiteStatus
    confidence: Confidence
    durable_fact_conflict: bool = False
    no_signal: bool = False
    admin_note_relevant: bool = False  # the listing's internal note bears on this verification
    findings: list[FindingDetail] = Field(default_factory=list)
    notes: str = ""

    def labels(self) -> list[str]:
        """Distinct change labels across the findings, for dashboard chips."""
        seen: list[str] = []
        for f in self.findings:
            if f.change_label.value not in seen:
                seen.append(f.change_label.value)
        return seen

    def sources(self) -> list[str]:
        seen: list[str] = []
        for f in self.findings:
            if f.source.value not in seen:
                seen.append(f.source.value)
        return seen
