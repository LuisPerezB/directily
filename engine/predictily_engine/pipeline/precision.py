"""Modo de precisión automático (`demand.precision_mode = "auto"`; por defecto `manual`: nada cambia).

En `manual` cada interruptor de precisión vale lo que la empresa configure. En `auto` el motor los ajusta en cada ejecución con el
backtest de esa empresa, sin configuración por cliente:

- Enciende los interruptores que SOLO agregan candidatos: familias del mismo día de la semana (`weekday_profile_models`) y combinación
  AutoETS + LightGBM (`ensemble_models`). Un candidato se sirve únicamente si gana en el backtest, así que encenderlos no impone nada.
- Elige con la regla del total diario (`promotion.legacy_selection_metric = total_day`), que trae sus propias guardas de sesgo y de error
  por serie.
- Decide el ajuste de feriados de los modelos estadísticos (`holiday_adjustment_models`) comparando el backtest CON y SIN el ajuste en las
  ventanas de SELECCIÓN (el período final reservado nunca decide): se adopta solo si el error del total diario de la mejor familia
  estadística baja al menos `precision_auto_margin` y su error por serie y día no empeora. Sin feriados en esas ventanas no hay evidencia
  y el ajuste no se adopta. Si la empresa ya lo encendió a mano, se respeta y no se compara.

Comparar el ajuste de feriados así no es elegir «con suerte»: son dos procedimientos fijos medidos fuera de muestra en las mismas ventanas;
el margen cubre el ruido. La decisión y su evidencia quedan en el resumen de la ejecución (`summary["precision"]`).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import pandas as pd

from predictily_engine.models.holidays import HOLIDAY_UNADJUSTED
from predictily_engine.models.registry import STATISTICAL
from predictily_engine.selection.selector import total_day_wape

MODE_MANUAL, MODE_AUTO = "manual", "auto"
AUTO_DEMAND = {"weekday_profile_models": True, "ensemble_models": True}
AUTO_PROMOTION = {"legacy_selection_metric": "total_day"}
DEFAULT_MARGIN = 0.01
SERIES_SLACK = 0.005       # el error por serie y día de la variante ajustada puede superar al de la variante sin ajuste a lo sumo en 0,5 %

REASON_ADOPTED = "adopted"
REASON_NO_GAIN = "no_gain"
REASON_NO_EVIDENCE = "no_evidence"
REASON_ALREADY_ON = "enabled_by_company"


def is_auto(core_config: dict[str, Any] | None) -> bool:
    return str((core_config or {}).get("precision_mode", MODE_MANUAL)) == MODE_AUTO


def auto_demand_config(core_config: dict[str, Any]) -> dict[str, Any]:
    """Configuración de demanda con los interruptores de candidatos encendidos (copia; la original no se toca)."""
    return {**core_config, **AUTO_DEMAND}


def auto_promotion_config(promotion_config: dict[str, Any]) -> dict[str, Any]:
    """Configuración de promoción con la regla de selección por el total diario (copia)."""
    return {**promotion_config, **AUTO_PROMOTION}


def trial_families(families: list[str]) -> list[str]:
    """Familias que el ajuste de feriados modifica: las estadísticas que no son referencia."""
    return [f for f in families if f in STATISTICAL and f not in HOLIDAY_UNADJUSTED]


@dataclass
class HolidayDecision:
    adopted: bool
    reason: str
    evidence: dict[str, Any] = field(default_factory=dict)

    def summary(self) -> dict[str, Any]:
        return {"adopted": self.adopted, "reason": self.reason, **self.evidence}


def _selection_rows(bt: pd.DataFrame, families: list[str]) -> pd.DataFrame:
    if bt.empty:
        return bt
    rows = bt[bt["family"].isin(families) & ~bt["is_holdout"].astype(bool) & ~bt["stockout"].astype(bool)]
    return rows[["unique_id", "window", "ds", "family", "actual", "yhat"]]


def _errors(rows: pd.DataFrame) -> pd.DataFrame:
    """Por familia: WAPE del total diario y WAPE por serie y día."""
    total = total_day_wape(rows, ["family"]).set_index("family")["wape_total"]
    g = rows.assign(_ae=(rows["yhat"] - rows["actual"]).abs()).groupby("family")[["_ae", "actual"]].sum()
    series = g["_ae"] / g["actual"].where(g["actual"] > 0)
    return pd.DataFrame({"total_day": total, "series_day": series}).dropna()


def decide_holiday_adjustment(plain: pd.DataFrame, adjusted: pd.DataFrame, margin: float = DEFAULT_MARGIN) -> HolidayDecision:
    """¿Se adopta el ajuste de feriados? `plain` y `adjusted` son filas de backtest (con y sin ajuste) de las mismas ventanas.

    Se comparan, en las ventanas de selección y días evaluables, la mejor familia estadística ajustable de cada variante por el WAPE del
    total diario. Adopta si el de la variante ajustada es menor en al menos `margin` (relativo) y su WAPE por serie y día no supera al de
    la variante sin ajuste en más de `SERIES_SLACK`."""
    families = sorted(set(trial_families(list(adjusted["family"].unique()))) & set(plain["family"].unique())) if not adjusted.empty and not plain.empty else []
    rows_p, rows_a = (_selection_rows(plain, families), _selection_rows(adjusted, families)) if families else (pd.DataFrame(), pd.DataFrame())
    if rows_p.empty or rows_a.empty:
        return HolidayDecision(False, REASON_NO_EVIDENCE, {"metric": "total_day", "margin": margin})
    keys = ["unique_id", "window", "ds", "family"]
    both = rows_p.merge(rows_a[keys + ["yhat"]], on=keys, suffixes=("", "_adj"))
    if both.empty or bool((both["yhat"] - both["yhat_adj"]).abs().max() < 1e-9):     # sin feriados en las ventanas: las dos variantes son iguales
        return HolidayDecision(False, REASON_NO_EVIDENCE, {"metric": "total_day", "margin": margin})
    err_p = _errors(both[keys + ["actual", "yhat"]])
    err_a = _errors(both[keys + ["actual"]].assign(yhat=both["yhat_adj"]))
    if err_p.empty or err_a.empty:
        return HolidayDecision(False, REASON_NO_EVIDENCE, {"metric": "total_day", "margin": margin})
    best_p, best_a = err_p["total_day"].idxmin(), err_a["total_day"].idxmin()
    tot_p, tot_a = float(err_p.loc[best_p, "total_day"]), float(err_a.loc[best_a, "total_day"])
    ser_p, ser_a = float(err_p.loc[best_p, "series_day"]), float(err_a.loc[best_a, "series_day"])
    adopted = tot_a <= tot_p * (1 - margin) and ser_a <= ser_p * (1 + SERIES_SLACK)
    evidence = {
        "metric": "total_day", "margin": margin, "windows": int(both["window"].nunique()),
        "plain": {"family": best_p, "total_day_wape": round(tot_p, 6), "series_day_wape": round(ser_p, 6)},
        "adjusted": {"family": best_a, "total_day_wape": round(tot_a, 6), "series_day_wape": round(ser_a, 6)},
        "relative_change": round(tot_a / tot_p - 1, 6) if tot_p > 0 else None,
    }
    return HolidayDecision(adopted, REASON_ADOPTED if adopted else REASON_NO_GAIN, evidence)


def replace_family_rows(bt: pd.DataFrame, adjusted: pd.DataFrame) -> pd.DataFrame:
    """Backtest con las filas de las familias de `adjusted` en lugar de las suyas en `bt` (todas las ventanas, incluido el período final)."""
    if adjusted.empty:
        return bt
    families = set(adjusted["family"].unique())
    return pd.concat([bt[~bt["family"].isin(families)], adjusted], ignore_index=True)


def summary_block(decision: HolidayDecision | None, holiday_adjustment: bool) -> dict[str, Any]:
    """Bloque `summary["precision"]` de una ejecución en modo automático."""
    holiday = decision.summary() if decision is not None else {"adopted": holiday_adjustment, "reason": REASON_ALREADY_ON if holiday_adjustment else REASON_NO_EVIDENCE}
    return {"mode": MODE_AUTO, "candidates_enabled": sorted(AUTO_DEMAND), "selection_metric": AUTO_PROMOTION["legacy_selection_metric"],
            "holiday_adjustment": holiday}
