"""Esquemas de entrada/salida de la API interna (contrato docs/06 §4)."""

from __future__ import annotations

from datetime import date
from typing import Any

from pydantic import BaseModel, Field, field_validator


class ExecuteOptions(BaseModel):
    force_models: list[str] | None = None
    max_backtest_windows: int | None = Field(default=None, ge=1, le=6)
    seed: int | None = None


class ExecuteRequest(BaseModel):
    tenant_id: int = Field(gt=0)
    training_job_id: int | None = None
    horizons: list[int] = Field(default_factory=lambda: [7, 30, 90])
    cutoff_date: date | None = None
    options: ExecuteOptions = Field(default_factory=ExecuteOptions)

    @field_validator("horizons")
    @classmethod
    def _horizons(cls, v: list[int]) -> list[int]:
        v = sorted({int(h) for h in v})
        if not v or any(h < 1 or h > 90 for h in v):
            raise ValueError("Los horizontes deben estar entre 1 y 90 días")
        return v


class ExecuteResponse(BaseModel):
    run_id: int
    status: str
    series_count: int
    cutoff_date: date | None
    selection: dict[str, Any]
    metrics: dict[str, Any]
    warnings: list[str]
    duration_ms: int


class ReadinessRequest(BaseModel):
    training_job_id: int | None = None


class EvaluateRequest(BaseModel):
    training_job_id: int | None = None
    lookback_runs: int = Field(default=10, ge=1, le=50)
