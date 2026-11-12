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
mitad de la ventana ni de sucursales sin historial suficiente (mínimo la mitad de la ventana con datos).

Mismo día de la semana (`infer_same_weekday`, activo por defecto): además, ese MISMO día de la semana debe ser habitual en la
sucursal (mediana de líneas de los mismos días de las `infer_lookback_days // 7` semanas previas ≥ `infer_min_median_lines`,
con al menos la mitad de esas semanas con datos). Sin esta condición, un día de la semana que habitualmente no se vende
(p. ej. los domingos de una distribuidora por rutas) se marcaba sin fuente cada semana y se rellenaba con un día normal: los
modelos aprendían domingos de venta normal y esos días quedaban fuera de la evaluación. Con ella, ese día es un cero observado.
Sin historia suficiente del mismo día de la semana se aplica solo la condición general.

Días de la semana sin operación (`operating_weekdays`, sin definir por defecto): una empresa que NUNCA abre ciertos días de la
semana los declara y esos días son `closed` (base `declared`) en todas sus sucursales, igual que un cierre del calendario: no son
ceros observados (no inflan la intermitencia de una serie que vende todos los días hábiles ni entran a las métricas) y en el
horizonte se pronostican en cero (`closed_future_rows`). Salvaguarda: si en los últimos `OPERATING_GUARD_DAYS` días más del
`OPERATING_GUARD_MAX_SHARE` de las unidades se vendió en días declarados sin operación, la declaración contradice los datos y NO se
aplica (queda en `summary['notes']['operating_weekdays']` y la ejecución lo informa): se evita pronosticar cero un día que sí vende.

Quiebres inferidos de las ventas (`stockout_inference`, apagado por defecto): sin historia de inventario, una racha de días en cero que
la demanda habitual del producto no explica se marca `stockout_censored` con base `inferred` (ver `profiling/stockouts.py`). `day_basis`
queda nulo en los quiebres que vienen del inventario.
"""

from __future__ import annotations

import warnings
from dataclasses import dataclass
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.profiling.stockouts import StockoutRules

SALE, ZERO_CONFIRMED, MISSING_SOURCE, STOCKOUT_CENSORED, CLOSED = (
    "sale", "zero_confirmed", "missing_source", "stockout_censored", "closed")
DAY_STATUSES = (SALE, ZERO_CONFIRMED, MISSING_SOURCE, STOCKOUT_CENSORED, CLOSED)
EXCLUDED_STATUSES = (MISSING_SOURCE, CLOSED)
BASIS_DECLARED, BASIS_INFERRED = "declared", "inferred"
DAY_BASES = (BASIS_DECLARED, BASIS_INFERRED)

# códigos internos (el orden de DAY_STATUSES define las categorías del panel)
_C_SALE, _C_ZERO, _C_MISSING, _C_STOCKOUT, _C_CLOSED = range(5)
_EPOCH = np.datetime64("1970-01-01", "D")
_EPOCH_WEEKDAY = 3   # el 1970-01-01 fue jueves (0 = lunes)

OPERATING_GUARD_DAYS = 56
OPERATING_GUARD_MAX_SHARE = 0.01


@dataclass(frozen=True)
class DayRules:
    """Parámetros de la clasificación de días (claves `day_status_*` de la configuración del core de Demanda)."""

    infer_gaps: bool = True
    infer_min_median_lines: float = 5.0
    infer_lookback_days: int = 28
    infer_same_weekday: bool = True
    operating_weekdays: tuple[int, ...] | None = None   # días de la semana en que se opera (0 = lunes); None = los 7
    stockouts: StockoutRules | None = None   # quiebres inferidos de las ventas (`stockout_inference`); None = apagado

    @classmethod
    def from_config(cls, cfg: dict[str, Any] | None) -> "DayRules":
        cfg = cfg or {}
        return cls(infer_gaps=bool(cfg.get("day_status_infer_gaps", True)),
                   infer_min_median_lines=float(cfg.get("day_status_infer_min_median_lines", 5)),
                   infer_lookback_days=int(cfg.get("day_status_infer_lookback_days", 28)),
                   infer_same_weekday=bool(cfg.get("day_status_infer_same_weekday", True)),
                   operating_weekdays=normalize_weekdays(cfg.get("operating_weekdays")),
                   stockouts=StockoutRules.from_config(cfg))

    def as_dict(self) -> dict[str, Any]:
        out: dict[str, Any] = {"infer_gaps": self.infer_gaps, "infer_min_median_lines": self.infer_min_median_lines,
                               "infer_lookback_days": self.infer_lookback_days, "infer_same_weekday": self.infer_same_weekday}
        if self.operating_weekdays is not None:      # sin declarar la regla no existe: el resumen queda como siempre
            out["operating_weekdays"] = list(self.operating_weekdays)
        return out


def normalize_weekdays(value: Any) -> tuple[int, ...] | None:
    """Días de operación como tupla ordenada; nulo, vacío o los 7 días = sin restricción (None)."""
    if not value:
        return None
    days = tuple(sorted({int(v) for v in value if 0 <= int(v) <= 6}))
    return None if len(days) in (0, 7) else days


def weekday_of(day_index: np.ndarray) -> np.ndarray:
    """Día de la semana (0 = lunes) de un índice de días desde 1970-01-01."""
    return (np.asarray(day_index, dtype=np.int64) + _EPOCH_WEEKDAY) % 7


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
    la mitad de esos días con datos. Una devolución ese día cuenta como actividad de la fuente. Con `infer_same_weekday`, el
    mismo día de la semana también debe ser habitual (ver el docstring del módulo)."""
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
    if rules.infer_same_weekday:
        regular &= _same_weekday_regular(base, lookback, float(rules.infer_min_median_lines))
    return regular & ~activity & ~skip & ~before_first


def _same_weekday_regular(base: np.ndarray, lookback: int, min_lines: float) -> np.ndarray:
    """¿Es habitual vender ESE día de la semana? Mediana de líneas de los mismos días de las `lookback // 7` semanas previas
    ≥ `min_lines`. Con menos de la mitad de esas semanas con datos no hay evidencia y no se restringe (True)."""
    weeks = max(lookback // 7, 1)
    frame = pd.DataFrame(base)
    same = np.stack([frame.shift(7 * k).to_numpy() for k in range(1, weeks + 1)])
    observed = (~np.isnan(same)).sum(axis=0)
    with warnings.catch_warnings(), np.errstate(invalid="ignore"):
        warnings.simplefilter("ignore", RuntimeWarning)   # «All-NaN slice»: celdas sin ninguna semana observada (quedan NaN y no restringen)
        median = np.nanmedian(same, axis=0)
        return np.where(observed >= max(weeks // 2, 1), median >= min_lines, True)


def classify_days(df: pd.DataFrame, data: Any, cutoff: pd.Timestamp, rules: DayRules | None = None,
                  context_from: Any = None) -> DayClassification:
    """Clasifica cada fila del panel (`df` con branch_id, ds, units y stockout). Ver el docstring del módulo.

    `data`: cualquier objeto con `coverage`, `coverage_gaps`, `closures`, `source_branches` y, para la regla inferida, `sales` (branch_id,
    ds, lines) y `returns`. `context_from`: primer día de historia que la regla inferida puede mirar cuando `df` solo trae una ventana
    reciente de días (la evaluación posterior de pronósticos clasifica los días evaluados, no todo el panel); por defecto, el primer día de `df`."""
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
    if context_from is not None:
        day0 = min(day0, int(_day_index(np.array([pd.Timestamp(context_from).to_datetime64()]))[0]))
    n_days = int(_day_index(np.array([pd.Timestamp(cutoff).to_datetime64()]))[0]) - day0 + 1
    n_days = max(n_days, int(day.max()) - day0 + 1)
    branches = np.unique(df["branch_id"].to_numpy(np.int64))
    b_idx = np.searchsorted(branches, df["branch_id"].to_numpy(np.int64))
    d_idx = day - day0

    missing, closed, notes = _declared_matrices(data, branches, day0, n_days)
    if rules.operating_weekdays is not None:
        # Días de la semana sin operación declarados por la empresa: cierres de todas las sucursales, salvo que los datos recientes lo
        # contradigan (ventas en esos días): entonces no se aplica y se informa, nunca se pronostica cero un día que sí vende.
        off_day = ~np.isin(weekday_of(np.arange(n_days) + day0), rules.operating_weekdays)
        row_off = off_day[d_idx]
        recent = d_idx > int(d_idx.max()) - OPERATING_GUARD_DAYS
        recent_units = float(units[recent].sum())
        share = float(units[recent & row_off].sum()) / recent_units if recent_units > 0 else 0.0
        applied = share <= OPERATING_GUARD_MAX_SHARE
        if applied:
            closed[off_day, :] = True
        notes["operating_weekdays"] = {
            "weekdays": list(rules.operating_weekdays), "applied": bool(applied), "recent_units_share_on_closed_weekdays": round(share, 6),
            "guard_days": OPERATING_GUARD_DAYS, "guard_max_share": OPERATING_GUARD_MAX_SHARE,
            "series_days": int(row_off.sum()) if applied else 0}
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


def operating_weekdays_applied(day_summary: dict[str, Any] | None) -> tuple[int, ...] | None:
    """Días de operación que la clasificación del panel REALMENTE aplicó (None si no se declararon o la salvaguarda los rechazó)."""
    note = ((day_summary or {}).get("notes") or {}).get("operating_weekdays") or {}
    return tuple(note["weekdays"]) if note.get("applied") else None


def closed_future_rows(branch_id: np.ndarray, ds: np.ndarray, closures: pd.DataFrame | None,
                       operating_weekdays: tuple[int, ...] | None) -> tuple[np.ndarray, np.ndarray]:
    """Filas (sucursal, día) del HORIZONTE declaradas cerradas: (por cierre del calendario, por día de la semana sin operación).

    `closures`: branch_id (nulo = toda la empresa), starts_on, ends_on (`TenantData.closures`). Un día cerrado se pronostica en
    cero: ese día no hay venta posible. Las dos máscaras pueden coincidir en una misma fila."""
    branch_id = np.asarray(branch_id, dtype=np.int64)
    days = np.asarray(ds, dtype="datetime64[D]")
    by_calendar = np.zeros(len(days), dtype=bool)
    if closures is not None and len(closures):
        for r in closures.itertuples(index=False):
            inside = (days >= np.datetime64(pd.Timestamp(r.starts_on), "D")) & (days <= np.datetime64(pd.Timestamp(r.ends_on), "D"))
            if inside.any():
                by_calendar |= inside if pd.isna(r.branch_id) else inside & (branch_id == int(r.branch_id))
    by_weekday = np.zeros(len(days), dtype=bool)
    if operating_weekdays is not None:
        by_weekday = ~np.isin(weekday_of(_day_index(days)), operating_weekdays)
    return by_calendar, by_weekday


def summarize_days(df: pd.DataFrame, classification_summary: dict[str, Any] | None = None, imputed: int | None = None,
                   not_imputable: int | None = None, carried_forward: int | None = None) -> dict[str, Any]:
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
    if carried_forward is not None:
        out["excluded_days_carried_forward"] = int(carried_forward)
    return out
