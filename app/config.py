"""Runtime configuration, loaded from the environment (Sevalla secrets).

Nothing here is read from WordPress. The Anthropic and Google keys live ONLY in
the worker's environment; WordPress holds just the worker URL + the shared HMAC
secret used to sign requests.
"""

from __future__ import annotations

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="HLV_", env_file=".env", extra="ignore")

    # --- Secrets / provider keys (Sevalla env) ---
    anthropic_api_key: str = ""
    google_cse_key: str = ""
    google_cse_cx: str = ""          # Programmable Search Engine ID
    worker_secret: str = ""          # shared HMAC secret with the WP plugin

    # --- Storage ---
    database_url: str = "postgresql+asyncpg://localhost/haunt_verifier"

    # --- Request signing ---
    signature_max_skew_seconds: int = 300  # reject replays older than this

    # --- Crawl / fetch tuning (overridable per-run by the manifest) ---
    fetch_connect_timeout: float = 5.0
    fetch_read_timeout: float = 15.0
    fetch_total_timeout: float = 20.0
    default_concurrency: int = 6
    anthropic_concurrency: int = 4
    user_agent: str = (
        "HauntListingVerifier/0.1 (+https://thescarefactor.com; verification bot)"
    )

    # --- Google CSE compliance ---
    google_daily_query_cap: int = 9000   # stay safely under the 10k/day/engine cap

    # --- Stranded-run recovery ---
    # How cold a `running` run's heartbeat must go before the janitor re-readies
    # it. Must be comfortably more than the heartbeat interval (60s) so a busy or
    # briefly-stalled worker is never robbed of the run it is actively crawling.
    stale_run_minutes: int = 10
    # Safety valve: how many times one run may be re-readied before it is parked
    # as an error instead. Stops a listing that reliably kills the worker from
    # putting the queue in a crash loop.
    max_run_reclaims: int = 3

    # --- Default models (the WP manifest overrides these per run) ---
    default_text_model: str = "claude-haiku-4-5"
    default_image_model: str = "claude-opus-4-8"

    # --- Image intelligence ---
    enable_tesseract_boost: bool = True
    min_flyer_edge_px: int = 400


@lru_cache
def get_settings() -> Settings:
    return Settings()
