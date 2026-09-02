"""SQLAlchemy ORM models for the staging store.

This is the Sevalla-relocatable boundary: findings, breadcrumbs, the decision
log, and the job queue all live here, independent of WordPress.
"""

from __future__ import annotations

import datetime as dt

from sqlalchemy import (
    JSON,
    BigInteger,
    Boolean,
    Date,
    DateTime,
    Integer,
    String,
    Text,
    func,
)
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column


class Base(DeclarativeBase):
    pass


def _now() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


class Run(Base):
    __tablename__ = "hlv_runs"

    id: Mapped[str] = mapped_column(String(64), primary_key=True)
    mode: Mapped[str] = mapped_column(String(16), default="interactive")  # interactive | batch
    status: Mapped[str] = mapped_column(String(16), default="queued", index=True)
    # interactive: queued -> ready -> running -> done | error
    # batch:       queued -> ready -> running -> awaiting_batch -> done | error
    run_config: Mapped[dict] = mapped_column(JSON, default=dict)
    prompt: Mapped[str] = mapped_column(Text, default="")
    scope: Mapped[dict] = mapped_column(JSON, default=dict)
    summary: Mapped[dict] = mapped_column(JSON, default=dict)
    # Batch mode: the Anthropic batch ids this run is waiting on, and when they
    # went out. Persisted so a worker restart resumes polling instead of
    # orphaning an in-flight (already paid for) batch.
    batch_ids: Mapped[list] = mapped_column(JSON, default=list)
    batch_submitted_at: Mapped[dt.datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    # Touched every HEARTBEAT_SECONDS by whichever worker is actively processing
    # this run. A `running` run with a cold heartbeat was stranded by a crashed or
    # restarted worker, and the janitor re-readies it. Distinct from updated_at,
    # which does not move during a long crawl (the jobs get written, not the run).
    heartbeat_at: Mapped[dt.datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    # How many times the janitor has re-readied this run. Capped, so a listing
    # that reliably kills the worker can't put the queue in a crash loop.
    reclaim_count: Mapped[int] = mapped_column(Integer, default=0)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime(timezone=True), default=_now)
    updated_at: Mapped[dt.datetime] = mapped_column(
        DateTime(timezone=True), default=_now, onupdate=_now
    )


class ListingJob(Base):
    __tablename__ = "hlv_listing_jobs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    run_id: Mapped[str] = mapped_column(String(64), index=True)
    listing_id: Mapped[int] = mapped_column(BigInteger, index=True)
    snapshot: Mapped[dict] = mapped_column(JSON, default=dict)
    status: Mapped[str] = mapped_column(String(16), default="queued", index=True)
    # interactive: queued -> running -> done | no_signal | error
    # batch:       queued -> submitted -> done | no_signal | error
    error: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Batch mode only. `custom_id` is how a batch result finds its way back to
    # this row (results return in any order). `prepared` carries the crawl-phase
    # output the write phase still needs: source fingerprints for the breadcrumb,
    # the stats already counted, and whether flyers were attached.
    custom_id: Mapped[str | None] = mapped_column(String(64), nullable=True, index=True)
    prepared: Mapped[dict] = mapped_column(JSON, default=dict)
    created_at: Mapped[dt.datetime] = mapped_column(DateTime(timezone=True), default=_now)


class Finding(Base):
    __tablename__ = "hlv_findings"

    id: Mapped[str] = mapped_column(String(64), primary_key=True)
    run_id: Mapped[str] = mapped_column(String(64), index=True)
    listing_id: Mapped[int] = mapped_column(BigInteger, index=True)
    name: Mapped[str] = mapped_column(String(255), default="")
    is_premium: Mapped[bool] = mapped_column(Boolean, default=False)
    confidence: Mapped[str] = mapped_column(String(8), default="low", index=True)
    durable_fact_conflict: Mapped[bool] = mapped_column(Boolean, default=False, index=True)
    no_signal: Mapped[bool] = mapped_column(Boolean, default=False, index=True)
    labels: Mapped[list] = mapped_column(JSON, default=list)
    sources: Mapped[list] = mapped_column(JSON, default=list)
    payload: Mapped[dict] = mapped_column(JSON, default=dict)  # full classification result
    finding_hash: Mapped[str] = mapped_column(String(64), default="", index=True)
    status: Mapped[str] = mapped_column(String(16), default="pending", index=True)
    # pending -> accepted | rejected | edited | deferred | auto_approved
    #         -> resolved (every actionable field decided)
    #         -> superseded (a newer crawl replaced it)
    #         -> ignored (parked in the Past findings archive; restorable)
    # A DELETED result is not a status — the row is removed outright and the
    # listing's breadcrumb is reset, so the next crawl re-checks it from scratch.
    created_at: Mapped[dt.datetime] = mapped_column(DateTime(timezone=True), default=_now)
    decided_at: Mapped[dt.datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )


class Decision(Base):
    __tablename__ = "hlv_decisions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    finding_id: Mapped[str] = mapped_column(String(64), default="", index=True)
    run_id: Mapped[str] = mapped_column(String(64), default="", index=True)
    listing_id: Mapped[int] = mapped_column(BigInteger, default=0, index=True)
    field: Mapped[str] = mapped_column(String(32), default="")
    action: Mapped[str] = mapped_column(String(16), default="")
    reason_code: Mapped[str] = mapped_column(String(48), default="", index=True)
    suggested_value: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    final_value: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    note: Mapped[str] = mapped_column(Text, default="")
    user: Mapped[str] = mapped_column(String(120), default="")
    created_at: Mapped[dt.datetime] = mapped_column(DateTime(timezone=True), default=_now)


class Breadcrumb(Base):
    __tablename__ = "hlv_breadcrumbs"

    listing_id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    last_crawled_at: Mapped[dt.datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    source_fingerprints: Mapped[dict] = mapped_column(JSON, default=dict)  # per-source content hash
    last_suggestion_hash: Mapped[str] = mapped_column(String(64), default="")
    last_status: Mapped[str] = mapped_column(String(32), default="")  # operating_status of last finding
    decision_history: Mapped[list] = mapped_column(JSON, default=list)
    suppressions: Mapped[list] = mapped_column(JSON, default=list)  # e.g. ["seasonal_dark"]
    correction_memory: Mapped[str] = mapped_column(Text, default="")  # carried into future prompts


class GoogleQuota(Base):
    __tablename__ = "hlv_google_quota"

    day: Mapped[dt.date] = mapped_column(Date, primary_key=True)
    count: Mapped[int] = mapped_column(Integer, default=0)
