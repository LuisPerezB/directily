"""Horizontes de demanda 7/14/15/30/60/90 y abstención explícita (instrucctions.md §6.D, E2-21; ADR-V25-02).

El dominio soporta todo el catálogo (`platform.config.HORIZONS["demand"]`, fuente única que el backend replica en
`predictily.horizons.catalog`) aunque un plan comercial autorice solo algunos (la autorización la decide Laravel; el
motor calcula lo que se le pide). Un horizonte H solo se presenta como respaldado si:

1. la historia cubre al menos `HISTORY_FACTOR` × H días (2 × H), y
2. el backtesting tiene ventanas de al menos H días, para poder medir el error de ese largo.

Si no, el horizonte queda `insufficient_data` con sus motivos: el número se calcula (la jerarquía y las
recomendaciones lo necesitan) pero NUNCA se presenta como completo. Sin período final independiente el
horizonte queda `degraded` (métricas optimistas).

Nombres de la abstención (unificados, ADR-V25-02): `insufficient_data` es siempre el ESTADO; el motivo es un CÓDIGO:
`coverage_insufficient` (la historia no cubre 2 × H o no alcanza para un backtest) o `insufficient_horizon` (el backtest no
tiene ventanas completas de H días). Cada motivo conserva su `code` histórico (`history_lt_2h`, `no_backtest`,
`backtest_horizon_lt_h`, `no_evaluated_observations`) para no romper a los consumidores existentes y agrega `reason_code`.
"""

from __future__ import annotations

from typing import Any

import numpy as np

from predictily_engine.platform.config import HORIZONS

SUPPORTED_HORIZONS: tuple[int, ...] = tuple(sorted(HORIZONS["demand"]))
HISTORY_FACTOR = 2

STATUS_OK = "ok"
STATUS_DEGRADED = "degraded"
STATUS_INSUFFICIENT = "insufficient_data"
_RANK = {STATUS_OK: 0, STATUS_DEGRADED: 1, STATUS_INSUFFICIENT: 2}

# Códigos de motivo de la abstención (el estado es siempre STATUS_INSUFFICIENT).
REASON_COVERAGE = "coverage_insufficient"
REASON_HORIZON = "insufficient_horizon"
# code histórico → reason_code unificado. `no_independent_holdout` NO es una abstención (el horizonte queda `degraded`).
LEGACY_REASON_CODES: dict[str, str] = {
    "history_lt_2h": REASON_COVERAGE,
    "no_backtest": REASON_COVERAGE,
    "backtest_horizon_lt_h": REASON_HORIZON,
    "no_evaluated_observations": REASON_HORIZON,
    "no_independent_holdout": "no_independent_holdout",
}


def _reason(code: str, message: str) -> dict[str, str]:
    return {"code": code, "reason_code": LEGACY_REASON_CODES[code], "message": message}


def abstention_reason_codes(info: dict[str, Any]) -> list[str]:
    """Códigos de motivo unificados (sin repetir, en orden) de un respaldo de horizonte."""
    out: list[str] = []
    for r in info.get("reasons", []):
        rc = r.get("reason_code") or LEGACY_REASON_CODES.get(r.get("code", ""), r.get("code"))
        if rc and rc not in out:
            out.append(rc)
    return out


def worst(*statuses: str) -> str:
    return max(statuses, key=lambda s: _RANK[s])


def required_history_days(horizon: int, factor: int = HISTORY_FACTOR) -> int:
    return factor * horizon


def horizon_for_step(step: np.ndarray | int) -> np.ndarray:
    """Menor horizonte del dominio que contiene a cada paso (1..90): el paso 15 pertenece al de 15 días y el 20 al de 30."""
    arr = np.asarray(step)
    idx = np.searchsorted(np.asarray(SUPPORTED_HORIZONS), arr, side="left")
    return np.asarray(SUPPORTED_HORIZONS)[np.clip(idx, 0, len(SUPPORTED_HORIZONS) - 1)]


def horizon_support(max_horizon: int, history_days: int, plan: Any | None,
                    evaluated_obs: dict[int, int] | None = None, factor: int = HISTORY_FACTOR) -> dict[int, dict]:
    """Respaldo de cada horizonte del dominio ≤ `max_horizon` a nivel empresa.

    `plan`: BacktestPlan o None. `evaluated_obs`: observaciones (serie×ventana) con las que se midió el error
    del total de H días (0 = sin evaluación). Devuelve {H: {status, reasons[], ...}}.
    """
    out: dict[int, dict] = {}
    # Todos los horizontes del dominio hasta el que contiene a `max_horizon` (así cada paso 1..max tiene el suyo).
    limit = next((x for x in SUPPORTED_HORIZONS if x >= max_horizon), SUPPORTED_HORIZONS[-1])
    for h in (x for x in SUPPORTED_HORIZONS if x <= limit):
        reasons: list[dict[str, str]] = []
        need = required_history_days(h, factor)
        if history_days < need:
            reasons.append(_reason("history_lt_2h",
                                   f"La historia ({history_days} días) es menor que {factor} × {h} = {need} días."))
        if plan is None:
            reasons.append(_reason("no_backtest",
                                   "No hay backtesting (historia < 70 días): el horizonte no pudo evaluarse."))
        elif plan.horizon < h:
            reasons.append(_reason("backtest_horizon_lt_h",
                                   f"El backtesting solo evalúa ventanas de {plan.horizon} días: no hay una ventana "
                                   f"de {h} días para medir el error de ese horizonte."))
        if evaluated_obs is not None and plan is not None and plan.horizon >= h and evaluated_obs.get(h, 0) == 0:
            reasons.append(_reason("no_evaluated_observations",
                                   f"Ninguna serie tuvo una ventana completa de {h} días para evaluar."))
        status = STATUS_INSUFFICIENT if reasons else STATUS_OK
        if status == STATUS_OK and plan is not None and not plan.has_holdout:
            status = STATUS_DEGRADED
            reasons.append(_reason("no_independent_holdout",
                                   "Sin período final independiente: el error proviene de las mismas ventanas que "
                                   "eligieron el modelo (optimista)."))
        out[h] = {
            "status": status, "reasons": reasons, "history_days": int(history_days), "required_history_days": need,
            "backtest_horizon_days": None if plan is None else int(plan.horizon),
            "independent_holdout": bool(plan is not None and plan.has_holdout),
            "evaluated_obs": None if evaluated_obs is None else int(evaluated_obs.get(h, 0)),
        }
    return out


def run_quality(support: dict[int, dict]) -> str:
    """Estado de calidad del run: ok si todos los horizontes están respaldados; insufficient_data si ninguno;
    degraded en otro caso."""
    if not support:
        return STATUS_INSUFFICIENT
    states = {v["status"] for v in support.values()}
    if states == {STATUS_OK}:
        return STATUS_OK
    if states == {STATUS_INSUFFICIENT}:
        return STATUS_INSUFFICIENT
    return STATUS_DEGRADED


def row_status(steps: np.ndarray, support: dict[int, dict], series_history_days: np.ndarray | None = None,
               factor: int = HISTORY_FACTOR) -> np.ndarray:
    """Estado por fila (paso del horizonte): el del horizonte que contiene al paso a nivel empresa, y para filas
    de serie, además, insuficiente si la historia de ESA serie es menor que 2 × H."""
    steps = np.asarray(steps)
    hz = horizon_for_step(steps)
    status = np.array([support.get(int(h), {"status": STATUS_INSUFFICIENT})["status"] for h in hz], dtype=object)
    if series_history_days is not None:
        short = np.asarray(series_history_days) < factor * hz
        status = np.where(short, STATUS_INSUFFICIENT, status)
    return status
