"""Async database engine + session management."""

from __future__ import annotations

from collections.abc import AsyncIterator

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine

from .config import get_settings
from .store.models import Base

_settings = get_settings()

engine = create_async_engine(_settings.database_url, pool_pre_ping=True, future=True)
SessionLocal = async_sessionmaker(engine, expire_on_commit=False, class_=AsyncSession)

# Columns added after a database may already have been created. `create_all`
# only creates missing TABLES — it never alters an existing one — so these run
# separately. Each is idempotent; drop this list once Alembic lands.
_ADDED_COLUMNS = (
    "ALTER TABLE hlv_runs ADD COLUMN IF NOT EXISTS batch_ids JSON DEFAULT '[]'",
    "ALTER TABLE hlv_runs ADD COLUMN IF NOT EXISTS batch_submitted_at TIMESTAMPTZ",
    "ALTER TABLE hlv_runs ADD COLUMN IF NOT EXISTS heartbeat_at TIMESTAMPTZ",
    "ALTER TABLE hlv_runs ADD COLUMN IF NOT EXISTS reclaim_count INTEGER DEFAULT 0",
    "ALTER TABLE hlv_listing_jobs ADD COLUMN IF NOT EXISTS custom_id VARCHAR(64)",
    "ALTER TABLE hlv_listing_jobs ADD COLUMN IF NOT EXISTS prepared JSON DEFAULT '{}'",
    "ALTER TABLE hlv_findings ADD COLUMN IF NOT EXISTS decided_at TIMESTAMPTZ",
    "CREATE INDEX IF NOT EXISTS ix_hlv_listing_jobs_custom_id ON hlv_listing_jobs (custom_id)",
)


async def init_models() -> None:
    """Create tables if they do not exist, then add any newer columns.

    Fine for v1; a real Alembic migration set replaces this in the scale phase.
    """
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
        for statement in _ADDED_COLUMNS:
            await conn.execute(text(statement))


async def get_session() -> AsyncIterator[AsyncSession]:
    """FastAPI dependency yielding a session."""
    async with SessionLocal() as session:
        yield session
