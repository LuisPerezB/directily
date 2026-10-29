"""Configuración del motor (variables de entorno con prefijo ENGINE_)."""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

# v2 (validación 2026-10-28, F5): la variable promo_discount de fechas futuras solo usa promociones
# conocidas al corte (known_at / promotion_revisions); v1 usaba toda promoción vigente en esas fechas.
FEATURE_SET_VERSION = "fs-2026.10-v2"


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
    # Sobrescrituras del operador (None = usar la configuración del core/perfil/empresa, ver platform/config.py):
    # mejora relativa mínima de WAPE que debe lograr un modelo complejo sobre el mejor estadístico y ventanas.
    min_improvement: float | None = None
    max_backtest_windows: int | None = None


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
