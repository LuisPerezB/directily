"""Valores de factor TAL COMO SE SIRVEN (W6-E · revisión adversarial H1 y M1; ADR-V25-06 y ADR-V25-16).

**El defecto que corrige.** `FactorValidator.evaluate_series` (W2-D) entrega, para una fila de entrenamiento de la fecha `d`, el valor visible
EL MISMO DÍA `d` (corte = `d`): cada fecha tiene su propio valor (una «huella de fecha») y el modelo puede memorizar choques de fecha con él,
mientras que en la predicción todas las fechas futuras comparten UN valor arrastrado desde el origen. El modelo se entrenaba con una señal que
nunca recibe al servir: el ruido puro podía «ganar» el backtest.

**Qué hace este módulo.** Una fila de entrenamiento de la fecha `d` recibe el valor que habría estado visible en SU origen y arrastrado hasta `d`
exactamente como en la predicción:

- Las fechas FUTURAS (`d > corte`) leen el valor visible en el corte (el origen real de la predicción), con su antigüedad `d − referencia`.
- Las fechas del PASADO se agrupan en bloques de `block_days` días hacia atrás desde el corte (la longitud del horizonte que se sirve). Todas
  las filas del bloque `k` comparten el MISMO origen `corte − (k+1) × block_days` y reciben el valor visible en ese origen, arrastrado: edades
  `1 … block_days`, como en un horizonte servido. Dentro de un bloque el valor es constante, igual que en la predicción; el número de valores
  distintos del entrenamiento baja de uno por día a uno por bloque y desaparece la huella de fecha.
- Un valor cuya antigüedad (`d − referencia`) supera `max_staleness_days` NO entra: es faltante (NaN), nunca un valor viejo presentado como
  vigente (M1). El estado `STALE` y la antigüedad se conservan para el reporte (fracción de días vencidos, antigüedad máxima).
- Desconocido = NaN, jamás cero. Lo que no era visible en el origen no se usa (la regla única de `availability.visibility`).

Es un modo OPCIONAL de `FactorResolution` (`served_block_days`): sin él, el comportamiento de W2-D es el de siempre. El cableado de demanda
(`backtest/factor_arm.py`) siempre lo usa.
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.availability.model import AvailabilityClass, AvailabilityStatus, FactorSpec, Reason
from predictily_engine.availability.validator import FeatureAvailabilityValidator, PreparedObservations
from predictily_engine.availability.visibility import to_date

_AC = AvailabilityClass
_ST = AvailabilityStatus
SERVED_COLUMNS = ["ds", "value", "status", "reason", "age_days", "usable", "origin"]


def block_origins(index: pd.DatetimeIndex, cutoff: pd.Timestamp, block_days: int) -> np.ndarray:
    """Origen de cada fecha: el corte para el futuro; para el pasado (incluido el propio día del corte), el inicio del bloque de `block_days` días al
    que pertenece. El bloque `k` reúne las fechas con `corte − d ∈ [k·B, (k+1)·B − 1]` y su origen es `corte − (k+1)·B`: la edad `d − origen` va de 1 a B."""
    if block_days < 1:
        raise ValueError("block_days debe ser un entero positivo.")
    delta = (cutoff - index).days.to_numpy()
    k = np.where(delta >= 0, delta // block_days, 0)
    back = np.where(delta >= 0, (k + 1) * block_days, 0)
    return (cutoff - pd.to_timedelta(back, unit="D")).to_numpy("datetime64[ns]")


def served_series(validator: FeatureAvailabilityValidator, spec: FactorSpec, prepared: PreparedObservations, dates: Any, cutoff: Any,
                  block_days: int) -> pd.DataFrame:
    """Valor de un factor para cada fecha de `dates` tal como se serviría desde `cutoff` (ver el docstring del módulo).

    Columnas: `ds`, `value` (NaN si no es utilizable o si está vencido), `status`, `reason`, `age_days`, `usable` (verdadero ⇔ `value` informado)
    y `origin` (el corte desde el que se leyó el valor)."""
    cutoff_ts = pd.Timestamp(to_date(cutoff))
    index = pd.DatetimeIndex(pd.to_datetime(dates)).normalize()
    origins = pd.DatetimeIndex(block_origins(index, cutoff_ts, block_days))
    frame = prepared.frame
    cls = spec.availability_class
    # Camino vectorizado: una evaluación por ORIGEN (no por fecha). Vale para los observados/estáticos sin vigencia propia (`valid_to`), porque su valor
    # visible no depende de la fecha de uso: solo envejece.
    vectorized = cls in (_AC.OBSERVED_HISTORICAL, _AC.STATIC) and not bool(frame["vto"].notna().any()) if len(frame) else False
    n = len(index)
    value = np.full(n, np.nan)
    status = np.empty(n, dtype=object)
    reason = np.empty(n, dtype=object)
    age = np.full(n, np.nan)
    if vectorized:
        for origin in pd.unique(origins):
            mask = (origins == origin)
            o = pd.Timestamp(origin)
            base = validator.evaluate(spec, prepared, o.date(), target_date=o.date())
            gap = (index[mask] - o).days.to_numpy()
            if base.status in (_ST.AVAILABLE, _ST.STALE) and base.value is not None:
                a = (base.age_days if base.age_days is not None else 0) + gap
                limit = spec.max_staleness_days
                stale = np.zeros(mask.sum(), dtype=bool) if cls is _AC.STATIC or limit is None else a > limit
                value[mask] = np.where(stale, np.nan, float(base.value))
                status[mask] = np.where(stale, _ST.STALE.value, _ST.AVAILABLE.value)
                reason[mask] = np.where(stale, Reason.STALE, Reason.OK)
                age[mask] = a
            else:
                status[mask] = base.status.value
                reason[mask] = base.reason
    else:
        for i in range(n):
            t, o = index[i], origins[i]
            r = validator.evaluate(spec, prepared, pd.Timestamp(o).date(), target_date=t.date())
            usable = r.status is _ST.AVAILABLE and r.value is not None and "informative_alert" not in r.flags
            if usable:
                value[i] = float(r.value)
            status[i], reason[i] = r.status.value, r.reason
            if r.age_days is not None:
                age[i] = r.age_days
    usable = ~np.isnan(value)
    return pd.DataFrame({"ds": index, "value": value, "status": status, "reason": reason, "age_days": age, "usable": usable, "origin": origins})[SERVED_COLUMNS]


def served_stats(series: pd.DataFrame, cutoff: Any) -> dict[str, Any]:
    """Cómo fue el factor tal como se sirve (para el manifiesto y el resumen): días futuros con valor, vencidos (`STALE`: antigüedad sobre el límite,
    presentados como faltantes) y antigüedad máxima del valor arrastrado, más lo mismo para las filas de entrenamiento."""
    day = pd.Timestamp(to_date(cutoff))
    out: dict[str, Any] = {}
    for name, part in (("future", series[series["ds"] > day]), ("training", series[series["ds"] <= day])):
        n = int(len(part))
        stale = int((part["status"] == _ST.STALE.value).sum())
        out[name] = {"days": n, "with_value": int(part["usable"].sum()), "stale_days": stale,
                     "stale_fraction": (stale / n) if n else None,
                     "missing_fraction": (1 - float(part["usable"].mean())) if n else None,
                     "max_age_days": None if part["age_days"].isna().all() else int(part["age_days"].max())}
    return out
