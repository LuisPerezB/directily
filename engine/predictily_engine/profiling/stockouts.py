"""Quiebres de stock inferidos de las ventas (interruptor `demand.stockout_inference`; apagado por defecto: nada cambia).

Sin historia de inventario el motor no puede saber que un producto se quedó sin stock: ve una racha de días en cero, la aprende como
demanda y pronostica de menos justo cuando el producto vuelve. Esta regla marca como quiebre (`stockout_censored`, base `inferred`) los
días de una racha de ceros que NO se explica por la demanda habitual de ese producto. Un día marcado recibe el trato de cualquier quiebre:
se imputa para entrenar y queda fuera de las métricas y de la proporción de ceros del perfilado.

Regla (por serie = sucursal × producto):

- Racha: días consecutivos sin venta. Los días sin fuente, de cierre o con quiebre según inventario no la cortan ni cuentan. La cortan
  un día con venta o un día en cero con existencias confirmadas por el inventario.
- Probabilidad de la racha si hubiera stock: producto, sobre sus días, de la frecuencia de ceros de ESE día de la semana en las
  `lookback_days` anteriores al inicio de la racha (suavizada: (ceros + 1) / (días + 2)). Un producto que vende todos los días tiene una
  frecuencia de ceros baja y dos o tres días en cero ya son improbables; uno que vende de forma esporádica necesita semanas, o nunca
  llega.
- Solo productos de venta regular: en las `lookback_days` anteriores al inicio de la racha debe haber al menos `MIN_HISTORY_DAYS` días
  observados y a lo sumo `max_zero_share` de ellos en cero. En un producto de venta esporádica las rachas largas de ceros son parte de
  su demanda (los ceros no son independientes entre días) y la regla marcaría quiebres que no existen.
- Un día se marca cuando, CON LO OBSERVADO HASTA ESE DÍA, la racha ya es improbable (probabilidad ≤ `alpha`) y lleva entre `min_days` y
  `max_days` días en cero. Los primeros días de la racha (antes de ser improbable) quedan como ceros observados: la marca de un día nunca
  depende de lo que pasó después, así que una ventana de backtest no aprende nada que no se supiera en su corte.
- Pasados `max_days` días en cero se deja de suponer quiebre: los días siguientes vuelven a ser ceros observados (producto descontinuado
  o demanda que cambió), y el pronóstico baja.
- Salvaguarda de sucursal: si desde el inicio de la racha los DEMÁS productos de la sucursal vendieron menos de `min_branch_activity`
  veces lo habitual para esos días de la semana, la caída es de toda la sucursal (no de un producto) y no se marca.
- Los días ya marcados (por inventario o por esta regla) no cuentan como ceros en la frecuencia de ceros de las rachas siguientes
  (segunda pasada): un producto con quiebres repetidos no «aprende» que el cero es normal.

Límites: solo detecta quiebres totales (días en cero), no ventas reducidas por poco stock; no distingue un quiebre de un producto que se
dejó de vender hasta que pasan `max_days`; en productos de venta esporádica no marca nada. Es una inferencia: debe aprobarse por
empresa con sus datos (queda contada en el resumen de la ejecución y en sus limitaciones).
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from typing import Any

import numpy as np
import pandas as pd

_EPOCH = np.datetime64("1970-01-01", "D")
MIN_HISTORY_DAYS = 28   # días observados que debe haber antes de la racha para juzgar si el producto vende con regularidad


@dataclass(frozen=True)
class StockoutRules:
    """Parámetros de la inferencia (claves `stockout_inference_*` de la configuración del core de Demanda)."""

    alpha: float = 0.001
    min_days: int = 3
    max_days: int = 42
    lookback_days: int = 84
    min_branch_activity: float = 0.5
    max_zero_share: float = 0.5

    @classmethod
    def from_config(cls, cfg: dict[str, Any] | None) -> StockoutRules | None:
        """Las reglas si el interruptor está encendido; `None` si está apagado."""
        cfg = cfg or {}
        if not bool(cfg.get("stockout_inference", False)):
            return None
        return cls(
            alpha=float(cfg.get("stockout_inference_alpha", cls.alpha)),
            min_days=int(cfg.get("stockout_inference_min_days", cls.min_days)),
            max_days=int(cfg.get("stockout_inference_max_days", cls.max_days)),
            lookback_days=int(cfg.get("stockout_inference_lookback_days", cls.lookback_days)),
            min_branch_activity=float(cfg.get("stockout_inference_min_branch_activity", cls.min_branch_activity)),
            max_zero_share=float(cfg.get("stockout_inference_max_zero_share", cls.max_zero_share)),
        )

    def as_dict(self) -> dict[str, Any]:
        return {"alpha": self.alpha, "min_days": self.min_days, "max_days": self.max_days, "lookback_days": self.lookback_days,
                "min_branch_activity": self.min_branch_activity, "max_zero_share": self.max_zero_share}


@dataclass
class StockoutInference:
    inferred: np.ndarray  # una marca por fila del panel
    summary: dict[str, Any]


def _take(matrix: np.ndarray, columns: np.ndarray) -> np.ndarray:
    """matrix[i, columns[i, d]] con columnas fuera de rango (< 0) en cero."""
    out = np.take_along_axis(matrix, np.clip(columns, 0, matrix.shape[1] - 1), axis=1)
    return np.where(columns >= 0, out, 0)


def _same_weekday_sum(values: np.ndarray, weeks: int) -> np.ndarray:
    """Para cada día, la suma de `values` en ese mismo día de la semana de las `weeks` semanas anteriores."""
    out = np.zeros(values.shape, dtype=np.float64)
    for w in range(1, weeks + 1):
        if 7 * w >= values.shape[1]:
            break
        out[:, 7 * w:] += values[:, :-7 * w]
    return out


def _frozen(matrix: np.ndarray, start: np.ndarray, col: np.ndarray) -> np.ndarray:
    """Valor de `matrix` congelado al inicio de la racha: el del mismo día de la semana dentro de sus primeros 7 días (que solo mira
    días anteriores a la racha)."""
    return np.take_along_axis(matrix, np.minimum(start + (col - start) % 7, matrix.shape[1] - 1), axis=1)


def _run_sum(values: np.ndarray, start: np.ndarray) -> np.ndarray:
    """Suma de `values` desde el inicio de la racha en curso hasta cada día."""
    cum = np.cumsum(values, axis=1, dtype=np.float64)
    return cum - _take(cum, start - 1)


def _branch_drop(own: np.ndarray, open_day: np.ndarray, start: np.ndarray, col: np.ndarray, rules: StockoutRules) -> np.ndarray:
    """Celdas (serie, día) en que, desde el inicio de la racha, LOS DEMÁS productos de la sucursal vendieron menos de
    `min_branch_activity` veces lo esperable (su media de esos mismos días de la semana en las semanas previas a la racha; solo días con
    alguna observación en la sucursal). `own`: unidades observadas por serie y día. Sin otros productos que vendan no hay evidencia."""
    weeks = max(rules.lookback_days // 7, 1)
    others = (own.sum(axis=0)[None, :] - own) * open_day[None, :]
    seen = _same_weekday_sum(np.broadcast_to(open_day.astype(np.float64), own.shape), weeks)
    usual = _same_weekday_sum(others, weeks) / np.maximum(seen, 1.0)
    expected = _run_sum(_frozen(usual, start, col) * open_day[None, :], start)
    return (expected > 0) & (_run_sum(others, start) < rules.min_branch_activity * expected)


def _pass(zero: np.ndarray, observed: np.ndarray, history: np.ndarray, start: np.ndarray, col: np.ndarray,
          rules: StockoutRules) -> tuple[np.ndarray, np.ndarray]:
    """Una pasada sobre la matriz series × días de una sucursal: (racha improbable hasta ese día, días en cero que lleva la racha).

    `zero`: días en cero que cuentan para la racha. `observed`: días observados (con ellos se juzga si el producto vende con
    regularidad). `history`: días observados que cuentan para la frecuencia de ceros de cada día de la semana (sin los de quiebres ya
    marcados). `start`: primer día posible de la racha en curso de cada celda."""
    weeks = max(rules.lookback_days // 7, 1)
    past_zero = zero & history
    # frecuencia de ceros de cada día de la semana en las semanas previas, suavizada, y congelada al inicio de la racha
    logp = np.log((_same_weekday_sum(past_zero, weeks) + 1.0) / (_same_weekday_sum(history, weeks) + 2.0))
    run_logp = _run_sum(np.where(zero, _frozen(logp, start, col), 0.0), start)
    run_zero = _run_sum(zero, start)
    # venta regular antes de la racha: días observados y ceros en las `lookback_days` anteriores a su inicio. Se juzga con TODO lo
    # observado (también los días de rachas ya marcadas): si no, cada racha marcada volvería «regular» a un producto de venta esporádica.
    cum_seen, cum_past_zero = np.cumsum(observed, axis=1, dtype=np.int32), np.cumsum(zero, axis=1, dtype=np.int32)
    seen = _take(cum_seen, start - 1) - _take(cum_seen, start - 1 - rules.lookback_days)
    zeros = _take(cum_past_zero, start - 1) - _take(cum_past_zero, start - 1 - rules.lookback_days)
    regular = (seen >= MIN_HISTORY_DAYS) & (zeros <= rules.max_zero_share * seen)
    return zero & regular & (run_logp <= math.log(rules.alpha)) & (run_zero >= rules.min_days), run_zero


def infer_stockouts(df: pd.DataFrame, excluded: np.ndarray, in_stock: np.ndarray, rules: StockoutRules) -> StockoutInference:
    """Marca los días de quiebre inferido del panel `df` (una fila por serie y día; con unique_id, branch_id, ds, units y stockout).

    `excluded`: filas sin fuente o de cierre. `in_stock`: filas con existencias confirmadas por el inventario (nunca se marcan)."""
    inferred = np.zeros(len(df), dtype=bool)
    counts = {"long_run_series_days": 0, "branch_drop_series_days": 0}
    if len(df):
        day = (df["ds"].to_numpy("datetime64[D]") - _EPOCH).astype(np.int64)
        day0, days = int(day.min()), int(day.max() - day.min()) + 1
        units = df["units"].to_numpy(np.float32)
        known = df["stockout"].to_numpy(bool)
        branch = df["branch_id"].to_numpy(np.int64)
        codes, _ = pd.factorize(df["unique_id"], sort=False)
        for b in np.unique(branch):
            rows = np.flatnonzero(branch == b)
            ser = pd.factorize(codes[rows], sort=False)[0]
            c = day[rows] - day0
            n = int(ser.max()) + 1
            present, sold, observed, stocked = (np.zeros((n, days), dtype=bool) for _ in range(4))
            present[ser, c] = True
            sold[ser, c] = units[rows] > 0
            observed[ser, c] = ~excluded[rows] & ~known[rows]      # con fuente, abierto y sin quiebre según inventario
            stocked[ser, c] = in_stock[rows]
            own = np.zeros((n, days), dtype=np.float32)
            own[ser, c] = np.where(~excluded[rows] & ~known[rows], units[rows], 0.0)
            zero = observed & ~sold
            breaker = ~present | (observed & (sold | stocked))
            col = np.broadcast_to(np.arange(days), (n, days))
            start = np.maximum.accumulate(np.where(breaker, col + 1, 0), axis=1)
            improbable, run_zero = _pass(zero, observed, observed, start, col, rules)
            # Segunda pasada: los días ya marcados dejan de contar como ceros de la historia: un producto con quiebres repetidos no
            # «aprende» que el cero es normal. Lo que pasó de `max_days` sí cuenta (el producto dejó de venderse).
            first = improbable & (run_zero <= rules.max_days)
            again, run_zero = _pass(zero, observed, observed & ~first, start, col, rules)
            improbable |= again
            too_long = improbable & (run_zero > rules.max_days)
            branch_drop = improbable & ~too_long & _branch_drop(own, observed.any(axis=0), start, col, rules)
            inferred[rows] = (improbable & ~too_long & ~branch_drop)[ser, c]
            counts["long_run_series_days"] += int(too_long.sum())
            counts["branch_drop_series_days"] += int(branch_drop.sum())
    ids = df["unique_id"].to_numpy()[inferred]
    at_cutoff = inferred & (df["ds"] == df["ds"].max()).to_numpy() if len(df) else inferred
    summary = {"rules": rules.as_dict(), "series_days": int(inferred.sum()), "series": len(np.unique(ids)),
               "series_total": int(df["unique_id"].nunique()) if len(df) else 0,
               "open_at_cutoff_series": len(np.unique(df["unique_id"].to_numpy()[at_cutoff])),
               "units_share_of_affected_series": _units_share(df, ids), **counts}
    return StockoutInference(inferred, summary)


def _units_share(df: pd.DataFrame, ids: np.ndarray) -> float:
    """Fracción de las unidades vendidas que corresponde a las series con algún quiebre inferido."""
    total = float(df["units"].sum()) if len(df) else 0.0
    if total <= 0 or len(ids) == 0:
        return 0.0
    return round(float(df.loc[df["unique_id"].isin(set(ids)), "units"].sum()) / total, 6)
