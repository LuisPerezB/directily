"""Dataset de cortes históricos comprador × producto con etiquetas por horizonte.

Para cada corte `c` de una rejilla cronológica se construyen los candidatos observables al corte y sus
variables (solo pasado); para cada H ∈ {7, 30, 90} se calculan las etiquetas de la ventana (c, c+H]:

- `purchase` = ≥ 1 compra válida (sin anuladas ni notas de crédito); `qty` = suma de TODAS las compras
  válidas de la ventana (devoluciones aparte, columna `returned_qty`);
- la etiqueta es *madura* solo si c+H ≤ último día completo de ventas (`data_coverage` o, sin ella, la última
  venta); las filas inmaduras no se entrenan ni se evalúan;
- censura: si el producto estuvo sin stock en la sucursal habitual más de (1 − min_availability_share) de la
  ventana, la fila entera se excluye (no es un negativo ni un positivo). Disponibilidad desconocida
  (sin snapshots suficientes) no excluye, pero se contabiliza.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.customer import SUPPORTED_HORIZONS
from predictily_engine.customer.events import from_day
from predictily_engine.customer.features import (
    BASE_FEATURES,
    HORIZON_FEATURES,
    REFERENCE_COLUMNS,
    FeatureBuilder,
)

MODEL_FEATURES = BASE_FEATURES + HORIZON_FEATURES


@dataclass
class HorizonData:
    horizon: int
    frame: pd.DataFrame                       # filas VÁLIDAS: cutoff, rel, ids, h-features, ref_p/ref_q, labels
    counts: dict[str, int] = field(default_factory=dict)  # valid / immature / censored / availability_unknown


@dataclass
class DatasetBundle:
    builder: FeatureBuilder
    base: pd.DataFrame                        # variables independientes del horizonte de TODAS las filas (union de cortes)
    cutoffs: list[int]
    last_complete: int
    first_allowed: int
    effective_step: int
    per_horizon: dict[int, HorizonData]
    meta: dict[str, Any] = field(default_factory=dict)

    def matrix(self, horizon: int, rows: np.ndarray | None = None) -> np.ndarray:
        """Matriz float32 de variables del modelo para las filas válidas del horizonte (o un subconjunto)."""
        hd = self.per_horizon[horizon].frame
        if rows is not None:
            hd = hd.iloc[rows]
        base = self.base.loc[hd["base_row"].to_numpy(), BASE_FEATURES].to_numpy(dtype=np.float32)
        hf = hd[HORIZON_FEATURES].to_numpy(dtype=np.float32)
        return np.concatenate([base, hf], axis=1)


def cutoff_grid(last_complete: int, first_allowed: int, step: int, max_cutoffs: int, min_horizon: int = 7) -> list[int]:
    """Cortes cronológicos anclados en el último día completo: L − min_horizon, L − min_horizon − step, ..."""
    grid = []
    c = last_complete - min_horizon
    while c >= first_allowed and len(grid) < max_cutoffs:
        grid.append(c)
        c -= step
    return sorted(grid)


def build_dataset(builder: FeatureBuilder, cfg: dict[str, Any], horizons: tuple[int, ...] = SUPPORTED_HORIZONS,
                  last_complete: int | None = None, first_sale_day: int | None = None,
                  coverage_from_day: int | None = None, progress=None) -> DatasetBundle:
    horizons = tuple(sorted(horizons))
    L = int(last_complete if last_complete is not None else builder.last_complete)
    first = first_sale_day if first_sale_day is not None else builder.day_min
    if coverage_from_day is not None:
        first = max(first, coverage_from_day)
    first_allowed = int(first + int(cfg["min_history_days"]))
    step = int(cfg["cutoff_step_days"])
    grid = cutoff_grid(L, first_allowed, step, int(cfg["max_cutoffs"]), min(horizons))
    # tope de filas: si la rejilla excede el presupuesto se ensancha el paso (se registra el paso efectivo)
    budget = int(cfg["max_dataset_rows"])
    if grid:
        est = len(builder.candidate_rels(grid[len(grid) // 2])[0]) * len(grid)
        while est > budget and step < 120:
            step = int(np.ceil(step * 1.5))
            grid = cutoff_grid(L, first_allowed, step, int(cfg["max_cutoffs"]), min(horizons))
            est = len(builder.candidate_rels(grid[len(grid) // 2])[0]) * len(grid) if grid else 0
    bases: list[pd.DataFrame] = []
    per_h: dict[int, list[pd.DataFrame]] = {h: [] for h in horizons}
    counts = {h: {"valid": 0, "immature": 0, "censored": 0, "availability_unknown": 0} for h in horizons}
    offset = 0
    for k, c in enumerate(grid):
        cf = builder.compute(c, horizons)
        if cf.base.empty:
            continue
        base = cf.base
        base = base.assign(cutoff=np.int32(c))
        bases.append(base)
        n = len(base)
        for h in horizons:
            lab = builder.labels(base, c, h, L)
            st = lab["status"].to_numpy()
            counts[h]["immature"] += int((st == 0).sum())
            counts[h]["censored"] += int((st == 2).sum())
            valid = st == 1
            if not valid.any():
                continue
            counts[h]["valid"] += int(valid.sum())
            counts[h]["availability_unknown"] += int((valid & lab["avail_share"].isna().to_numpy()).sum())
            hf = cf.horizon[h].loc[valid, HORIZON_FEATURES + REFERENCE_COLUMNS].reset_index(drop=True)
            part = pd.concat([hf, lab.loc[valid].reset_index(drop=True)], axis=1)
            part.insert(0, "cutoff", np.int32(c))
            part.insert(1, "base_row", (offset + np.nonzero(valid)[0]).astype(np.int64))
            per_h[h].append(part)
        offset += n
        if progress:
            progress("building_dataset", 5 + int(20 * (k + 1) / max(len(grid), 1)))
    base_all = pd.concat(bases, ignore_index=True) if bases else pd.DataFrame(columns=["cutoff", *BASE_FEATURES])
    per_horizon = {}
    for h in horizons:
        frame = pd.concat(per_h[h], ignore_index=True) if per_h[h] else pd.DataFrame(
            columns=["cutoff", "base_row", *HORIZON_FEATURES, *REFERENCE_COLUMNS, "status", "y", "qty", "net"])
        per_horizon[h] = HorizonData(h, frame, counts[h])
    return DatasetBundle(
        builder=builder, base=base_all, cutoffs=grid, last_complete=L, first_allowed=first_allowed, effective_step=step,
        per_horizon=per_horizon,
        meta={"cutoff_first": from_day(grid[0]).isoformat() if grid else None,
              "cutoff_last": from_day(grid[-1]).isoformat() if grid else None, "n_cutoffs": len(grid),
              "rows_total": int(len(base_all)), "min_history_days": int(cfg["min_history_days"])})
