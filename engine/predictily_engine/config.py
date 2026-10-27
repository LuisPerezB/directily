"""Configuración del motor (variables de entorno con prefijo ENGINE_)."""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

FEATURE_SET_VERSION = "fs-2026.10-v1"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="ENGINE_", env_file=".env", extra="ignore")

    database_url: str = "postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily"
    api_token: str = "dev-engine-token-change-me"
    artifacts_dir: Path = Path("./storage/artifacts")
    random_seed: int = 42
    log_level: str = "INFO"
    # Límite de seguridad: por encima de este número de series no se entrena LightGBM global.
    max_series_lgbm: int = 5000
    n_jobs: int = 4
    # Un run "running" más antiguo que esto se considera abandonado y puede retomarse.
    stale_run_seconds: int = 1800
    # Mejora relativa mínima de WAPE que debe lograr un modelo complejo sobre el mejor estadístico.
    min_improvement: float = 0.03
    max_backtest_windows: int = 3


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
