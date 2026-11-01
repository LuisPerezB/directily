"""Semántica de los días sin transacciones (ADR-V25-03): un día sin ventas NO es siempre un cero.

Cada celda diaria (sucursal × producto × día) del panel de demanda se clasifica en `day_status`:

| estado              | significado                                                                                          | entrena / mide |
|---------------------|------------------------------------------------------------------------------------------------------|----------------|
| `sale`              | hubo venta                                                                                           | sí             |
| `zero_confirmed`    | fuente completa, sucursal abierta, stock disponible y sin venta: un cero observado                    | sí (como 0)    |
| `stockout_censored` | quiebre de stock (cierre del día con existencias ≤ 0 o el día anterior): la venta es cota inferior   | no (censurado) |
| `missing_source`    | la fuente no cubre ese día: fuera de `data_coverage`, dentro de un hueco `data_coverage_gaps` o       | no (excluido)  |
|                     | inferido por la regla de actividad de la sucursal                                                     |                |
| `closed`            | cierre del calendario de la empresa o sucursal (`calendar_events.category = 'closure'`)               | no (excluido)  |

Precedencia cuando varias condiciones coinciden: `missing_source` > `closed` > `stockout_censored` > `sale` / `zero_confirmed`.
`day_basis` indica el origen de un `missing_source`/`closed`: `declared` (cobertura, hueco o cierre registrados) o `inferred`
(regla del motor o hueco inferido por un conector).

`missing_source` y `closed` se excluyen de las métricas y del perfilado de ceros y NUNCA cuentan como cero observado. Los
modelos que exigen series contiguas los reciben imputados con la misma regla que los quiebres (media móvil de 28 días de los
días limpios; ver `profiling.series.build_panel`).

Regla inferida (configurable, activa por defecto; debe aprobarse en el piloto): un día en que una sucursal no tiene NINGUNA
línea de venta ni devolución, pese a que la mediana de sus líneas diarias en los `infer_lookback_days` días previos es
≥ `infer_min_median_lines`, se marca `missing_source` con base `inferred`. Justificación: una sucursal que vende de forma
regular y de pronto no registra nada es, con mucha más probabilidad, una caída de la fuente que una demanda cero; tratarlo como
cero sesgaría los modelos a la baja. Limitaciones: confunde un cierre sin declarar (feriado, inventario, corte de energía) con
una caída de la fuente (por eso los cierres se declaran en el calendario y tienen prioridad), no detecta caídas de más de la
mitad de la ventana ni de sucursales sin historial suficiente (mínimo la mitad de la ventana con datos) y un cierre semanal
recurrente (p. ej. domingos) se marcaría cada semana si no se declara.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

import numpy as np
import pandas as pd

SALE, ZERO_CONFIRMED, MISSING_SOURCE, STOCKOUT_CENSORED, CLOSED = (
    "sale", "zero_confirmed", "missing_source", "stockout_censored", "closed")
DAY_STATUSES = (SALE, ZERO_CONFIRMED, MISSING_SOURCE, STOCKOUT_CENSORED, CLOSED)
EXCLUDED_STATUSES = (MISSING_SOURCE, CLOSED)
BASIS_DECLARED, BASIS_INFERRED = "declared", "inferred"
DAY_BASES = (BASIS_DECLARED, BASIS_INFERRED)

# códigos internos (el orden de DAY_STATUSES define las categorías del panel)
_C_SALE, _C_ZERO, _C_MISSING, _C_STOCKOUT, _C_CLOSED = range(5)
_EPOCH = np.datetime64("1970-01-01", "D")


@dataclass(frozen=True)
class DayRules:
    """Parámetros de la clasificación de días (claves `day_status_*` de la configuración del core de Demanda)."""

    infer_gaps: bool = True
    infer_min_median_lines: float = 5.0
    infer_lookback_days: int = 28

    @classmethod
    def from_config(cls, cfg: dict[str, Any] | None) -> "DayRules":
        cfg = cfg or {}
        return cls(infer_gaps=bool(cfg.get("day_status_infer_gaps", True)),
                   infer_min_median_lines=float(cfg.get("day_status_infer_min_median_lines", 5)),
                   infer_lookback_days=int(cfg.get("day_status_infer_lookback_days", 28)))

    def as_dict(self) -> dict[str, Any]:
        return {"infer_gaps": self.infer_gaps, "infer_min_median_lines": self.infer_min_median_lines,
                "infer_lookback_days": self.infer_lookback_days}


NO_INFERENCE = DayRules(infer_gaps=False)  # solo lo declarado (cobertura, huecos y cierres): comportamiento por defecto de `build_panel`


def excluded_days(df: pd.DataFrame) -> pd.Series:
    """Días `missing_source` o `closed` del marco (False si el marco no trae `day_status`)."""
    if "day_status" not in df:
        return pd.Series(False, index=df.index)
    return df["day_status"].isin(EXCLUDED_STATUSES)


def censored_days(df: pd.DataFrame) -> pd.Series:
    """Días cuya venta NO refleja la demanda y por tanto no entran como observación: quiebre de stock, fuente ausente o cierre."""
    return df["stockout"].astype(bool) | excluded_days(df)


@dataclass
class DayClassification:
    status: pd.Categorical
    basis: pd.Categorical
    summary: dict[str, Any]


def _day_index(dates: np.ndarray) -> np.ndarray:
    return (np.asarray(dates, dtype="datetime64[D]") - _EPOCH).astype(np.int64)


def _clip_rows(start: Any, end: Any, day0: int, n_days: int) -> tuple[int, int] | None:
    a = int(_day_index(np.array([pd.Timestamp(start).to_datetime64()]))[0]) - day0
    b = int(_day_index(np.array([pd.Timestamp(end).to_datetime64()]))[0]) - day0
    a, b = max(a, 0), min(b, n_days - 1)
    return None if a > b else (a, b)


def _declared_matrices(data: Any, branches: np.ndarray, day0: int, n_days: int,
                       ) -> tuple[np.ndarray, np.ndarray, dict[str, Any]]:
    """Matrices día × sucursal de lo DECLARADO: `missing` (base: 0 ninguna, 1 declarada, 2 inferida por el conector),
    `closed` (bool) y notas de auditoría."""
    n_b = len(branches)
    col = {int(b): j for j, b in enumerate(branches)}
    missing = np.zeros((n_days, n_b), dtype=np.int8)
    closed = np.zeros((n_days, n_b), dtype=bool)
    notes: dict[str, Any] = {"coverage_outside_branch_days": 0, "gap_rows": 0, "closure_rows": 0,
                             "gaps_expanded_by_source": 0, "gaps_applied_to_all_branches": 0}

    # 1) Fuera de la cobertura de la fuente de ventas (data_coverage, entidad `sales`): antes de complete_from o después de complete_through.
    cov = (getattr(data, "coverage", None) or {}).get("sales") or {}
    if cov.get("declared"):
        basis = 2 if cov.get("basis") == BASIS_INFERRED else 1
        c_through = cov.get("company_complete_through", cov.get("complete_through"))
        c_from = cov.get("complete_from")
        b_through = cov.get("branch_complete_through") or {}
        b_from = cov.get("branch_complete_from") or {}
        day_axis = np.arange(n_days)
        for b, j in col.items():
            thr = b_through.get(str(b), c_through)
            frm = b_from.get(str(b), c_from)
            out = np.zeros(n_days, dtype=bool)
            if thr is not None:
                out |= day_axis + day0 > int(_day_index(np.array([np.datetime64(str(thr)[:10])]))[0])
            if frm is not None:
                out |= day_axis + day0 < int(_day_index(np.array([np.datetime64(str(frm)[:10])]))[0])
            if out.any():
                missing[out, j] = np.where(missing[out, j] == 1, 1, basis)
                notes["coverage_outside_branch_days"] += int(out.sum())

    # 2) Huecos internos de la fuente (data_coverage_gaps): primero los inferidos por un conector, luego los declarados (mandan).
    gaps = getattr(data, "coverage_gaps", None)
    if gaps is not None and len(gaps):
        source_branches = getattr(data, "source_branches", None) or {}
        for pass_basis in (2, 1):
            for r in gaps.itertuples(index=False):
                row_basis = 2 if str(getattr(r, "basis", "declared")) == BASIS_INFERRED else 1
                if row_basis != pass_basis:
                    continue
                span = _clip_rows(r.gap_from, r.gap_to, day0, n_days)
                if span is None:
                    continue
                notes["gap_rows"] += 1
                branch = None if pd.isna(r.branch_id) else int(r.branch_id)
                if branch is not None:
                    cols = [col[branch]] if branch in col else []
                else:
                    source = None if pd.isna(getattr(r, "data_source_id", np.nan)) else int(r.data_source_id)
                    fed = source_branches.get(source) if source is not None else None
                    if fed:
                        cols = [col[b] for b in fed if b in col]
                        notes["gaps_expanded_by_source"] += 1
                    else:
                        cols = list(range(n_b))
                        notes["gaps_applied_to_all_branches"] += 1
                if cols:
                    block = missing[span[0]:span[1] + 1][:, cols]
                    missing[span[0]:span[1] + 1, cols] = np.where(block == 1, 1, pass_basis)

    # 3) Cierres del calendario de la empresa (scope tenant) o de una sucursal (scope branch).
    closures = getattr(data, "closures", None)
    if closures is not None and len(closures):
        for r in closures.itertuples(index=False):
            span = _clip_rows(r.starts_on, r.ends_on, day0, n_days)
            if span is None:
                continue
            notes["closure_rows"] += 1
            if pd.isna(r.branch_id):
                closed[span[0]:span[1] + 1, :] = True
            elif int(r.branch_id) in col:
                closed[span[0]:span[1] + 1, col[int(r.branch_id)]] = True
    return missing, closed, notes


def infer_missing_days(data: Any, branches: np.ndarray, day0: int, n_days: int, skip: np.ndarray,
                       rules: DayRules) -> np.ndarray:
    """Matriz día × sucursal (bool) de los días SIN ninguna línea de una sucursal que vende de forma regular.

    La mediana de líneas diarias de los `infer_lookback_days` días previos (sin contar los días ya declarados como ausentes
    o cerrados ni los anteriores a la primera venta de la sucursal) debe ser ≥ `infer_min_median_lines` y se exigen al menos
    la mitad de esos días con datos. Una devolución ese día cuenta como actividad de la fuente."""
    out = np.zeros((n_days, len(branches)), dtype=bool)
    sales = getattr(data, "sales", None)
    if sales is None or sales.empty or len(branches) == 0:
        return out
    cols = {int(b): j for j, b in enumerate(branches)}
    s = sales[["branch_id", "ds"]].copy()
    s["lines"] = sales["lines"].fillna(1.0).clip(lower=1.0) if "lines" in sales else 1.0
    s = s[s["branch_id"].isin(cols)]
    if s.empty:
        return out
    s["d"] = _day_index(s["ds"].to_numpy("datetime64[D]")) - day0
    s = s[(s["d"] >= 0) & (s["d"] < n_days)]
    lines = np.zeros((n_days, len(branches)))
    grouped = s.groupby(["d", "branch_id"])["lines"].sum()
    di = grouped.index.get_level_values(0).to_numpy()
    bi = np.array([cols[int(b)] for b in grouped.index.get_level_values(1)])
    lines[di, bi] = grouped.to_numpy()
    activity = lines > 0
    returns = getattr(data, "returns", None)
    if returns is not None and not returns.empty:
        r = returns[["branch_id", "ds"]].drop_duplicates()
        r = r[r["branch_id"].isin(cols)]
        rd = _day_index(r["ds"].to_numpy("datetime64[D]")) - day0
        ok = (rd >= 0) & (rd < n_days)
        activity[rd[ok], np.array([cols[int(b)] for b in r["branch_id"].to_numpy()])[ok]] = True
    first = np.where((lines > 0).any(axis=0), np.argmax(lines > 0, axis=0), n_days)
    before_first = np.arange(n_days)[:, None] < first[None, :]
    base = np.where(skip | before_first, np.nan, lines)
    lookback = int(rules.infer_lookback_days)
    med = pd.DataFrame(base).shift(1).rolling(lookback, min_periods=max(lookback // 2, 1)).median().to_numpy()
    with np.errstate(invalid="ignore"):
        regular = med >= float(rules.infer_min_median_lines)
    return regular & ~activity & ~skip & ~before_first


def classify_days(df: pd.DataFrame, data: Any, cutoff: pd.Timestamp, rules: DayRules | None = None) -> DayClassification:
    """Clasifica cada fila del panel (`df` con branch_id, ds, units y stockout). Ver el docstring del módulo."""
    rules = rules or NO_INFERENCE
    n = len(df)
    units = df["units"].to_numpy(float)
    code = np.where(units > 0, _C_SALE, _C_ZERO).astype(np.int8)
    code[df["stockout"].to_numpy(bool)] = _C_STOCKOUT
    basis = np.zeros(n, dtype=np.int8)  # 0 ninguna, 1 declared, 2 inferred
    summary: dict[str, Any] = {"rules": rules.as_dict(), "branch_days": {}, "notes": {}}
    if n == 0:
        return DayClassification(pd.Categorical.from_codes(code, DAY_STATUSES),
                                 pd.Categorical.from_codes(np.zeros(0, dtype=np.int8) - 1, DAY_BASES), summary)

    ds = df["ds"].to_numpy("datetime64[D]")
    day = _day_index(ds)
    day0 = int(day.min())
    n_days = int(_day_index(np.array([pd.Timestamp(cutoff).to_datetime64()]))[0]) - day0 + 1
    n_days = max(n_days, int(day.max()) - day0 + 1)
    branches = np.unique(df["branch_id"].to_numpy(np.int64))
    b_idx = np.searchsorted(branches, df["branch_id"].to_numpy(np.int64))
    d_idx = day - day0

    missing, closed, notes = _declared_matrices(data, branches, day0, n_days)
    summary["notes"] = notes
    declared_skip = (missing > 0) | closed
    inferred = infer_missing_days(data, branches, day0, n_days, declared_skip, rules) if rules.infer_gaps \
        else np.zeros((n_days, len(branches)), dtype=bool)

    row_closed = closed[d_idx, b_idx]
    row_missing = missing[d_idx, b_idx]
    row_inferred = inferred[d_idx, b_idx]
    code[row_closed] = _C_CLOSED
    basis[row_closed] = 1
    miss = (row_missing > 0) | row_inferred
    code[miss] = _C_MISSING
    basis[miss] = np.where(row_missing[miss] > 0, row_missing[miss], 2)
    # un hueco declarado sobre un día cerrado: manda `missing_source` (precedencia), pero el cierre queda registrado en el resumen

    def branch_days(rows: np.ndarray) -> int:  # sucursal-días distintos con al menos una serie en el panel
        return int(len(np.unique(b_idx[rows].astype(np.int64) * n_days + d_idx[rows]))) if rows.any() else 0

    summary["branch_days"] = {
        "missing_source_declared": branch_days(miss & (basis == 1)),
        "missing_source_connector_inferred": branch_days(miss & (row_missing == 2)),
        "missing_source_engine_inferred": branch_days(miss & (row_missing == 0) & row_inferred),
        "closed_declared": branch_days(row_closed & ~miss),
    }
    status = pd.Categorical.from_codes(code, DAY_STATUSES)
    basis_cat = pd.Categorical.from_codes(np.where(basis == 0, -1, basis - 1), DAY_BASES)
    return DayClassification(status, basis_cat, summary)


def summarize_days(df: pd.DataFrame, classification_summary: dict[str, Any] | None = None, imputed: int | None = None,
                   not_imputable: int | None = None) -> dict[str, Any]:
    """Resumen de la clasificación del panel (series-día por estado y base) para el manifiesto y las limitaciones."""
    out: dict[str, Any] = dict(classification_summary or {})
    if df.empty or "day_status" not in df:
        out["series_days"] = {s: 0 for s in DAY_STATUSES}
        out["series_days_total"] = 0
        return out
    counts = df["day_status"].value_counts()
    out["series_days"] = {s: int(counts.get(s, 0)) for s in DAY_STATUSES}
    out["series_days_total"] = int(len(df))
    by_basis = df.loc[excluded_days(df)].groupby(["day_status", "day_basis"], observed=True).size()
    out["series_days_by_basis"] = {f"{k[0]}:{k[1]}": int(v) for k, v in by_basis.items()}
    if imputed is not None:
        out["excluded_days_imputed"] = int(imputed)
    if not_imputable is not None:
        out["excluded_days_not_imputable"] = int(not_imputable)
    return out
