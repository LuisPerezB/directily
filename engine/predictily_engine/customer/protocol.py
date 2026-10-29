"""Protocolo temporal de evaluación (instrucctions.md §6.D/E): bloques cronológicos con separación de H días.

    entrenamiento | H | calibración | H | selección | H | holdout

La separación de H días entre bloques evita que la ventana de resultado de una fila de un bloque
(c, c+H] se solape con la de las filas del siguiente: no se comparten desenlaces futuros. El holdout no
se usa para elegir modelo, hiperparámetros, variables, umbrales ni calibrador. Los cortes que caen en las
separaciones se descartan (se registran en el manifiesto). Las ventanas de cortes consecutivos se
solapan cuando el paso es menor que H: sus observaciones NO son independientes (se declara en el manifiesto).
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

from predictily_engine.customer.events import from_day

FULL_BLOCKS = ("train", "calibration", "selection", "holdout")
REFERENCE_BLOCKS = ("calibration", "holdout")


@dataclass
class BlockPlan:
    names: tuple[str, ...]
    horizon: int
    cutoffs: dict[str, np.ndarray]
    bounds: dict[str, tuple[int, int]]
    discarded_in_gaps: int

    def manifest(self, label_through: int) -> dict:
        out: dict = {}
        for name in self.names:
            cs = self.cutoffs[name]
            lo, hi = int(cs.min()), int(cs.max())
            out[name] = {
                "cutoff_from": from_day(lo).isoformat(), "cutoff_to": from_day(hi).isoformat(),
                "label_window_from": from_day(lo + 1).isoformat(), "label_window_to": from_day(hi + self.horizon).isoformat(),
                "n_cutoffs": int(len(cs)),
            }
        out["gap_days_between_blocks"] = self.horizon
        out["cutoffs_discarded_in_gaps"] = self.discarded_in_gaps
        out["labels_mature_through"] = from_day(label_through).isoformat()
        return out


def plan_blocks(cutoffs: np.ndarray, horizon: int, fractions: dict[str, float], names: tuple[str, ...] = FULL_BLOCKS,
                min_cutoffs: int = 2) -> BlockPlan | None:
    """Reparte los cortes maduros en bloques cronológicos con separación de `horizon` días. None si no alcanza."""
    cutoffs = np.unique(np.asarray(cutoffs, dtype=np.int64))
    if len(cutoffs) < min_cutoffs * len(names):
        return None
    first, last = int(cutoffs.min()), int(cutoffs.max())
    avail = (last - first) - (len(names) - 1) * horizon
    if avail <= 0:
        return None
    w = np.array([fractions[n] for n in names], dtype=float)
    w = w / w.sum()
    lengths = w * avail
    bounds: dict[str, tuple[int, int]] = {}
    cur = float(first)
    for name, ln in zip(names, lengths):
        lo, hi = cur, cur + ln
        bounds[name] = (int(round(lo)), int(round(hi)))
        cur = hi + horizon
    bounds[names[-1]] = (bounds[names[-1]][0], last)
    assigned: dict[str, np.ndarray] = {}
    for name in names:
        lo, hi = bounds[name]
        assigned[name] = cutoffs[(cutoffs >= lo) & (cutoffs <= hi)]
    if any(len(assigned[n]) < min_cutoffs for n in names):
        return None
    # separación efectiva: el primer corte de cada bloque debe distar ≥ H del último del anterior
    for a, b in zip(names[:-1], names[1:]):
        if assigned[b].min() - assigned[a].max() < horizon:
            keep = assigned[a][assigned[a] <= assigned[b].min() - horizon]
            if len(keep) < min_cutoffs:
                return None
            assigned[a] = keep
    used = sum(len(v) for v in assigned.values())
    return BlockPlan(names, horizon, assigned, bounds, int(len(cutoffs) - used))
