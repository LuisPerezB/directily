"""Combinación de modelos: promedio simple de AutoETS y LightGBM global (interruptor `ensemble_models`, apagado por defecto).

Elegir UN ganador entre dos modelos casi empatados es elegir con ruido (ver `selection/selector.py`); promediarlos suele igualar o
mejorar a ambos porque sus errores no coinciden: AutoETS sigue el nivel y el ritmo semanal de cada serie, LightGBM aporta calendario,
promociones y precio. La combinación es una familia más (`ets_lightgbm_mean`): compite en el backtest con la regla de los modelos
complejos (margen mínimo y ventanas ganadas frente al mejor estadístico) y solo se sirve si gana.

No tiene artefacto propio: se recalcula con sus miembros (AutoETS determinista + el LightGBM de la ejecución). Si un miembro falta en una
fila (LightGBM no entrenable en una ventana, o falló en el entrenamiento final) esa fila no existe: nunca se sirve medio promedio.
"""

from __future__ import annotations

import pandas as pd

from predictily_engine.models.registry import ENSEMBLE_FAMILY, ENSEMBLE_MEMBERS

BACKTEST_KEYS = ["unique_id", "window", "ds"]
FORECAST_KEYS = ["unique_id", "ds"]


def ensemble_rows(rows: pd.DataFrame, keys: list[str]) -> pd.DataFrame:
    """Filas de la combinación a partir de las de sus miembros en `rows` (columnas `family`, `yhat` y `keys`).

    Una fila por clave en la que TODOS los miembros tienen pronóstico; `yhat` = su media y el resto de las columnas (real, escalas, clase,
    día no evaluable…) las del primer miembro, que son propias de la serie y el día, no del modelo."""
    first, *others = ENSEMBLE_MEMBERS
    base = rows[rows["family"] == first]
    if base.empty:
        return rows.iloc[0:0].copy()
    out = base.copy()
    total = out["yhat"].astype(float).to_numpy().copy()
    key = pd.MultiIndex.from_frame(out[keys])
    for member in others:
        part = rows[rows["family"] == member]
        if part.empty:
            return rows.iloc[0:0].copy()
        member_yhat = part.drop_duplicates(keys).set_index(keys)["yhat"].astype(float).reindex(key).to_numpy()
        total = total + member_yhat          # NaN donde el miembro no tiene esa fila
    out["yhat"] = total / len(ENSEMBLE_MEMBERS)
    out["family"] = ENSEMBLE_FAMILY
    return out[out["yhat"].notna()].reset_index(drop=True)


def with_ensemble_rows(bt: pd.DataFrame) -> pd.DataFrame:
    """Backtest con las filas de la combinación recalculadas desde las filas VIGENTES de sus miembros (se llama después del ajuste de
    hiperparámetros y de la competencia de factores, que reemplazan las filas de LightGBM). Sin algún miembro, el backtest queda igual."""
    if bt.empty or "family" not in bt:
        return bt
    rest = bt[bt["family"] != ENSEMBLE_FAMILY]
    extra = ensemble_rows(rest, BACKTEST_KEYS)
    return rest if extra.empty else pd.concat([rest, extra], ignore_index=True)
