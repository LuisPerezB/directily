"""Selección automática de modelos por clase de demanda.

Reglas (docs/04-estrategia-modelado.md):
1. Se evalúan en backtesting todos los candidatos de la clase, incluido siempre el baseline
   estadístico (Seasonal Naive), que se reporta como referencia.
2. Entre los estadísticos gana el de menor WAPE.
3. Un modelo más complejo (LightGBM global) solo reemplaza al mejor estadístico si mejora su
   WAPE al menos `min_improvement` (relativo) Y es mejor en ≥ 2/3 de las ventanas (estable).
4. Clases con poca evidencia (< 3 series o Σ ventas < 30) usan la decisión agregada.
5. Se registra un modelo de respaldo simple (el mejor entre Seasonal Naive y Promedio móvil).
6. Series con historia insuficiente usan el promedio de su historia disponible (sin evaluar).

Cada fila del backtest se agrupa por la clase que su serie tenía en el corte de SU ventana
(columna `demand_class` de `run_backtest`), no por la clase al corte final: así la selección
de una ventana no depende de ventas posteriores (F4). Si la columna no existe (backtests
externos o pruebas) se usa la clase del `profile` entregado.

`select_models` no sabe qué ventanas recibe: el pipeline lo llama con TODAS las ventanas
para el modelo de producción y SOLO con las ventanas de selección para la evaluación en el
período final reservado (F6).
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field

import pandas as pd

from predictily_engine.backtest.metrics import summarize
from predictily_engine.models.registry import (
    BASELINE_FAMILY,
    CANDIDATES_BY_CLASS,
    FAMILIES,
    SIMPLE_FALLBACKS,
    STATISTICAL,
)

POOLED = "pooled"


@dataclass
class Selection:
    winners: dict[str, str]  # demand_class -> family
    fallback: str
    reasons: dict[str, str]
    class_table: pd.DataFrame  # demand_class, family, wape, ... , windows_better
    pooled_table: pd.DataFrame
    used_pooled: list[str] = field(default_factory=list)

    def family_for(self, demand_class: str) -> str:
        if demand_class == "insufficient":
            return "window_average"
        return self.winners.get(demand_class, self.winners.get(POOLED, self.fallback))

    def served(self, failed: set[str]) -> "Selection":
        """Selección tal como se SIRVE cuando fallan familias en el entrenamiento final (E2-19).

        Cada clase cuyo ganador falló pasa a su respaldo: las métricas, la calibración de los intervalos y las
        etiquetas del resultado deben corresponder al modelo realmente usado, no al ganador del backtest.
        Sin fallos devuelve la misma selección."""
        if not failed:
            return self
        winners = {c: (self.fallback if f in failed else f) for c, f in self.winners.items()}
        reasons = dict(self.reasons)
        for c, f in self.winners.items():
            if f in failed:
                reasons[c] = (f"Falló el entrenamiento final de {FAMILIES[f].label}: se sirve el respaldo "
                              f"({FAMILIES[self.fallback].label}). " + self.reasons.get(c, ""))
        return Selection(winners, self.fallback, reasons, self.class_table, self.pooled_table, self.used_pooled)

    def as_json(self, registry_ids: dict[str, int] | None = None, planned: "Selection | None" = None) -> dict:
        """Selección servida por clase. Con `planned` (la elegida por el backtest), las clases donde se sirve el
        respaldo en lugar del ganador lo indican (`planned_family`, `fallback_applied`)."""
        ids = registry_ids or {}
        out = {}
        for cls, fam in self.winners.items():
            row = {"model_family": fam, "model_registry_id": ids.get(fam), "reason": self.reasons.get(cls)}
            if planned is not None and planned.winners.get(cls) not in (None, fam):
                row.update({"planned_family": planned.winners[cls], "fallback_applied": True})
            out[cls] = row
        out["insufficient"] = {"model_family": "window_average", "model_registry_id": ids.get("window_average"),
                               "reason": "Historia menor a 28 días: promedio de la historia disponible (sin evaluación)."}
        out["fallback"] = {"model_family": self.fallback, "model_registry_id": ids.get(self.fallback)}
        return out


def _decide(sub: pd.DataFrame, candidates: list[str], min_improvement: float, label: str) -> tuple[str, str, pd.DataFrame]:
    sub = sub[sub["family"].isin(candidates)]
    table = summarize(sub, ["family"])
    per_window = summarize(sub, ["family", "window"])
    stats = table[table["family"].isin(STATISTICAL) & table["wape"].notna()].sort_values("wape")
    if stats.empty:
        return BASELINE_FAMILY, "Sin evidencia suficiente: se usa el baseline.", table
    best_stat = str(stats.iloc[0]["family"])
    best_wape = float(stats.iloc[0]["wape"])
    winner, reason = best_stat, f"{FAMILIES[best_stat].label}: menor WAPE entre los estadísticos ({best_wape:.3f})."
    lg = table[table["family"] == "lightgbm_global"]
    table["windows_better"] = 0
    if not lg.empty and pd.notna(lg.iloc[0]["wape"]):
        lg_wape = float(lg.iloc[0]["wape"])
        pw = per_window.pivot(index="window", columns="family", values="wape")
        n_windows = len(pw)
        better = int((pw["lightgbm_global"] < pw[best_stat]).sum()) if "lightgbm_global" in pw else 0
        table.loc[table["family"] == "lightgbm_global", "windows_better"] = better
        needed = math.ceil(n_windows * 2 / 3)
        if lg_wape <= best_wape * (1 - min_improvement) and better >= needed:
            winner = "lightgbm_global"
            reason = (f"LightGBM global mejora el WAPE de {best_wape:.3f} ({FAMILIES[best_stat].label}) a "
                      f"{lg_wape:.3f} y gana en {better}/{n_windows} ventanas.")
        else:
            reason += (f" LightGBM ({lg_wape:.3f}) no superó el margen mínimo de {min_improvement:.0%} "
                       f"de forma estable ({better}/{n_windows} ventanas).")
    return winner, reason, table


def row_classes(backtest: pd.DataFrame, profile: pd.DataFrame | None = None) -> pd.Series:
    """Clase de demanda de cada fila: la de su ventana si existe; si no, la del perfil."""
    if "demand_class" in backtest and backtest["demand_class"].notna().any():
        return backtest["demand_class"]
    if profile is None or profile.empty:
        return pd.Series(None, index=backtest.index, dtype=object)
    return backtest["unique_id"].map(profile.set_index("unique_id")["demand_class"])


def select_models(backtest: pd.DataFrame, profile: pd.DataFrame, min_improvement: float = 0.03) -> Selection:
    bt = backtest.assign(demand_class=row_classes(backtest, profile))
    bt = bt[~bt["stockout"].astype(bool)].copy()
    all_families = sorted(bt["family"].unique())

    pooled_winner, pooled_reason, pooled_table = _decide(bt, all_families, min_improvement, POOLED)
    winners = {POOLED: pooled_winner}
    reasons = {POOLED: pooled_reason}
    used_pooled = []
    tables = []
    for cls in ("smooth", "erratic", "intermittent", "lumpy"):
        sub = bt[bt["demand_class"] == cls]
        if sub.empty:
            continue
        if sub["unique_id"].nunique() < 3 or sub["actual"].sum() < 30:
            winners[cls] = pooled_winner
            reasons[cls] = "Pocas series en la clase: se usa la decisión agregada. " + pooled_reason
            used_pooled.append(cls)
            continue
        cands = [f for f in CANDIDATES_BY_CLASS[cls] if f in all_families]
        winner, reason, table = _decide(sub, cands, min_improvement, cls)
        winners[cls] = winner
        reasons[cls] = reason
        tables.append(table.assign(demand_class=cls))

    fb = pooled_table[pooled_table["family"].isin(SIMPLE_FALLBACKS)].sort_values("wape")
    fallback = str(fb.iloc[0]["family"]) if not fb.empty else BASELINE_FAMILY
    class_table = pd.concat(tables, ignore_index=True) if tables else pd.DataFrame()
    return Selection(winners, fallback, reasons, class_table, pooled_table, used_pooled)


def selected_rows(backtest: pd.DataFrame, profile: pd.DataFrame, selection: Selection) -> pd.DataFrame:
    """Filas del backtest correspondientes al modelo elegido para cada serie (según la clase de
    la serie en el corte de cada ventana)."""
    if backtest.empty:
        return backtest.copy()
    classes = row_classes(backtest, profile)
    chosen = classes.map(selection.family_for)  # clase desconocida → decisión agregada
    return backtest[backtest["family"] == chosen].assign(demand_class=classes).copy()
