"""Intervalos de predicción conformales a partir de los residuos del backtest.

Para cada grupo (clase de demanda o nivel jerárquico) y tramo de horizonte se calculan
cuantiles empíricos del error normalizado e = (real − pred) / escala. El intervalo es
pred + q·escala (80 %: q10–q90; 95 %: q2.5–q97.5), truncado en 0 (modo "quantile",
asimétrico, adecuado para conteos sesgados a la derecha a nivel serie). En modo "absolute"
el intervalo es simétrico: pred ± cuantil(|e|) (más robusto en niveles agregados cuando
las pocas ventanas comparten un sesgo estacional). Más allá del horizonte
evaluado, el último tramo se ensancha con √(paso / paso_máximo). La cobertura se mide con
validación "dejando una ventana fuera" (los cuantiles nunca usan la ventana evaluada).
"""

from __future__ import annotations

from collections.abc import Callable

import numpy as np
import pandas as pd

DAY_BUCKETS = [(1, 7), (8, 14), (15, 30), (31, 60), (61, 90)]
QUANTILES = (0.025, 0.10, 0.90, 0.975)
MIN_POINTS = 20


def day_bucket(step: np.ndarray) -> np.ndarray:
    step = np.asarray(step)
    out = np.zeros(len(step), dtype=int)
    for i, (lo, _hi) in enumerate(DAY_BUCKETS):
        out[step >= lo] = i
    return out


def identity_bucket(step: np.ndarray) -> np.ndarray:
    return np.asarray(step, dtype=int)


class ConformalCalibrator:
    def __init__(self, rows: pd.DataFrame, group_col: str = "group",
                 bucketer: Callable[[np.ndarray], np.ndarray] = day_bucket, mode: str = "quantile") -> None:
        self.bucketer = bucketer
        self.mode = mode
        self.group_col = group_col
        rows = rows[np.isfinite(rows["actual"]) & np.isfinite(rows["yhat"]) & (rows["scale"] > 0)]
        e = (rows["actual"].to_numpy() - rows["yhat"].to_numpy()) / rows["scale"].to_numpy()
        frame = pd.DataFrame({"group": rows[group_col].to_numpy(), "bucket": bucketer(rows["step"].to_numpy()), "e": e})
        self.max_step = int(rows["step"].max()) if len(rows) else 0
        self.max_bucket = int(frame["bucket"].max()) if len(frame) else 0
        self.table: dict[tuple, np.ndarray] = {}
        for (g, b), sub in frame.groupby(["group", "bucket"]):
            if len(sub) >= MIN_POINTS:
                self.table[(g, b)] = self._quantiles(sub["e"].to_numpy())
        for b, sub in frame.groupby("bucket"):
            if len(sub) >= MIN_POINTS:
                self.table[("__all__", b)] = self._quantiles(sub["e"].to_numpy())

    def _quantiles(self, e: np.ndarray) -> np.ndarray:
        if self.mode == "absolute":
            q80, q95 = np.quantile(np.abs(e), [0.80, 0.95])
            return np.array([-q95, -q80, q80, q95])
        return np.quantile(e, QUANTILES)

    @property
    def empty(self) -> bool:
        return not self.table

    def _q(self, group, bucket: int) -> np.ndarray | None:
        for b in range(min(bucket, self.max_bucket), -1, -1):
            for g in (group, "__all__"):
                q = self.table.get((g, b))
                if q is not None:
                    return q
        return None

    def apply(self, df: pd.DataFrame) -> pd.DataFrame:
        """Agrega lo_80, hi_80, lo_95, hi_95 a df (columnas group_col, step, yhat, scale)."""
        out = df.copy()
        if self.empty or out.empty:
            for c in ("lo_80", "hi_80", "lo_95", "hi_95"):
                out[c] = np.nan
            return out
        steps = out["step"].to_numpy()
        buckets = self.bucketer(steps)
        groups = out[self.group_col].to_numpy()
        qs = np.full((len(out), 4), np.nan)
        cache: dict[tuple, np.ndarray | None] = {}
        for i, (g, b) in enumerate(zip(groups, buckets)):
            key = (g, int(b))
            if key not in cache:
                cache[key] = self._q(g, int(b))
            if cache[key] is not None:
                qs[i] = cache[key]
        widen = np.where(steps > self.max_step, np.sqrt(steps / max(self.max_step, 1)), 1.0)
        yhat = out["yhat"].to_numpy(dtype=float)
        scale = out["scale"].to_numpy(dtype=float) * widen
        lo95, lo80, hi80, hi95 = (yhat + qs[:, j] * scale for j in range(4))
        lo80 = np.minimum(np.clip(lo80, 0, None), yhat)
        lo95 = np.minimum(np.clip(lo95, 0, None), lo80)
        hi80 = np.maximum(hi80, yhat)
        hi95 = np.maximum(hi95, hi80)
        out["lo_80"], out["hi_80"], out["lo_95"], out["hi_95"] = lo80, hi80, lo95, hi95
        return out


def loo_coverage(rows: pd.DataFrame, group_col: str = "group",
                 bucketer: Callable[[np.ndarray], np.ndarray] = day_bucket,
                 mode: str = "quantile") -> tuple[float | None, float | None]:
    """Cobertura 80/95 dejando cada ventana fuera de la calibración."""
    hits80, hits95, total = 0, 0, 0
    for w in sorted(rows["window"].unique()):
        cal = ConformalCalibrator(rows[rows["window"] != w], group_col, bucketer, mode)
        if cal.empty:
            continue
        test = cal.apply(rows[rows["window"] == w])
        test = test[test["lo_80"].notna()]
        a = test["actual"].to_numpy()
        hits80 += int(((a >= test["lo_80"] - 1e-9) & (a <= test["hi_80"] + 1e-9)).sum())
        hits95 += int(((a >= test["lo_95"] - 1e-9) & (a <= test["hi_95"] + 1e-9)).sum())
        total += len(test)
    if total == 0:
        return None, None
    return hits80 / total, hits95 / total
