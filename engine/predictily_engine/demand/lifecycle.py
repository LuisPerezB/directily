"""Casos especiales de la demanda (instrucctions.md §6.D): productos nuevos, descontinuados, aperturas y
cierres de sucursal y eventos extraordinarios, siempre con etiqueta (`case_label`) y respaldo o abstención
identificados. Nada se resuelve en silencio y ningún desconocido se convierte en cero:

- **Producto nuevo** (menos de 28 días de historia): sin evidencia para un modelo propio. Con poca venta
  propia (< 14 días de historia o < 4 días con venta) se usa la **mediana de la categoría** (promedio diario
  de las series maduras de la misma categoría y sucursal si son ≥ 3, o de toda la empresa); si no hay
  categoría comparable, o la serie ya tiene suficiente venta propia, se usa **su promedio**. Etiquetas:
  `new_product_category_median`, `new_product_own_average`, `new_product_own_average_low_evidence`.
- **Historia escasa** (≥ 28 días pero < 4 días con venta): `sparse_history`, promedio de su historia.
- **Descontinuado**: `products.is_active = false` (declarado por la empresa) → **cero explícito**
  (`discontinued_zero`) si vendió en el último año; si no, abstención (`discontinued_abstained`).
  **Sin ventas recientes** (activo en el catálogo, > 180 días sin venta) → **abstención**
  (`no_recent_sales`): no se sabe si la demanda volvió, así que no se inventa un cero.
- **Cierre de sucursal**: solo si está **declarado** (`branches.is_active = false`) → cero explícito
  (`branch_closed_declared`). Una sucursal sin ventas recientes no se asume cerrada.
- **Apertura de sucursal**: sucursal cuya primera venta ocurrió ≥ 60 días después de la primera de la
  empresa y hace ≤ 90 días: sus series con < 90 días de historia se etiquetan `branch_opening` (la serie
  empieza en su primera venta; la estacionalidad de la sucursal aún no se conoce).
- **Evento extraordinario** (`calendar_events.category = 'exceptional'`): **bandera opcional**; solo cuentan
  los eventos cuyo `known_at` es ≤ corte (los de `known_at` desconocido no se usan y se informan). No cambia
  el valor pronosticado: marca las filas dentro del evento (`exceptional_event`, calidad `degraded`).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.profiling.series import INACTIVE_AFTER_DAYS, MIN_HISTORY_DAYS, MIN_NONZERO_DAYS

NEW_OWN_MIN_DAYS = 14
CATEGORY_MIN_SERIES = 3
DISCONTINUED_LOOKBACK_DAYS = 365
BRANCH_OPENING_GAP_DAYS = 60
BRANCH_OPENING_RECENT_DAYS = 90
BRANCH_OPENING_HISTORY_DAYS = 90

LABEL_CATEGORY = "new_product_category_median"
LABEL_OWN = "new_product_own_average"
LABEL_OWN_LOW = "new_product_own_average_low_evidence"
LABEL_SPARSE = "sparse_history"
LABEL_DISCONTINUED = "discontinued_zero"
LABEL_DISCONTINUED_ABSTAIN = "discontinued_abstained"
LABEL_NO_RECENT = "no_recent_sales"
LABEL_BRANCH_CLOSED = "branch_closed_declared"
LABEL_BRANCH_OPENING = "branch_opening"
LABEL_EXCEPTIONAL = "exceptional_event"
ZERO_FAMILY = "declared_zero"
CATEGORY_FAMILY = "category_benchmark"


@dataclass
class LifecyclePlan:
    """Resultado de clasificar el ciclo de vida de las series al corte."""

    labels: pd.Series  # unique_id → case_label (solo series con caso especial)
    zero_ids: list[str]  # series con cero explícito declarado
    abstain: dict[str, str]  # unique_id → motivo (sin filas de pronóstico)
    short_levels: pd.Series  # unique_id → nivel diario plano de series nuevas/escasas
    short_family: pd.Series  # unique_id → familia/método que sirve ese nivel
    summary: dict[str, Any] = field(default_factory=dict)

    @property
    def excluded_from_model(self) -> set[str]:
        return set(self.zero_ids) | set(self.abstain)


def _benchmarks(prof: pd.DataFrame, panel_df: pd.DataFrame, cutoff: pd.Timestamp) -> tuple[pd.Series, pd.Series]:
    """Mediana del promedio diario (últimos 28 días) de las series maduras, por (sucursal, categoría) y por categoría."""
    mature = prof[(prof["history_days"] >= MIN_HISTORY_DAYS) & prof["is_active"]
                  & (prof["demand_class"] != "insufficient") & prof["category_id"].notna()]
    empty = pd.Series(dtype=float)
    if mature.empty:
        return empty, empty
    recent = panel_df[(panel_df["ds"] > cutoff - pd.Timedelta(days=28)) & panel_df["unique_id"].isin(mature["unique_id"])]
    level = recent.groupby("unique_id")["y"].mean()
    m = mature.assign(level=mature["unique_id"].map(level)).dropna(subset=["level"])
    by_bc = m.groupby(["branch_id", "category_id"])["level"].agg(["median", "size"])
    by_c = m.groupby("category_id")["level"].agg(["median", "size"])
    return (by_bc.loc[by_bc["size"] >= CATEGORY_MIN_SERIES, "median"],
            by_c.loc[by_c["size"] >= CATEGORY_MIN_SERIES, "median"])


def plan_lifecycle(profile: pd.DataFrame, panel_df: pd.DataFrame, cutoff: pd.Timestamp, products: pd.DataFrame,
                   branches: pd.DataFrame) -> LifecyclePlan:
    """Clasifica cada serie del perfil al corte. `profile` es el perfil del corte final (`panel.profile`)."""
    cutoff = pd.Timestamp(cutoff)
    prof = profile.copy()
    if prof.empty:
        return LifecyclePlan(pd.Series(dtype=object), [], {}, pd.Series(dtype=float), pd.Series(dtype=object), {})
    cat_active = (products.set_index("product_id")["is_active"].astype(bool)
                  if not products.empty else pd.Series(dtype=bool))
    prof["catalog_active"] = prof["product_id"].map(cat_active).fillna(True).astype(bool)
    if "is_active" in branches.columns and not branches.empty:
        br_active = branches.set_index("branch_id")["is_active"].astype(bool)
    else:
        br_active = pd.Series(dtype=bool)
    prof["branch_active"] = prof["branch_id"].map(br_active).fillna(True).astype(bool)
    last = pd.to_datetime(prof["last_sale_date"])
    days_since = (cutoff - last).dt.days
    prof["days_since_last"] = days_since

    # ── Aperturas de sucursal ──
    first = pd.to_datetime(prof["first_sale_date"])
    tenant_first = first.min()
    branch_first = first.groupby(prof["branch_id"]).transform("min")
    opened = ((branch_first - tenant_first).dt.days >= BRANCH_OPENING_GAP_DAYS) \
        & ((cutoff - branch_first).dt.days <= BRANCH_OPENING_RECENT_DAYS)
    prof["branch_opening"] = opened & (prof["history_days"] < BRANCH_OPENING_HISTORY_DAYS)

    closed = ~prof["branch_active"]
    disc = ~closed & ~prof["catalog_active"]
    dormant = ~closed & ~disc & ~prof["is_active"]
    live = prof["is_active"] & prof["branch_active"]
    recent_enough = days_since.le(DISCONTINUED_LOOKBACK_DAYS).fillna(False)
    zero = (closed | disc) & recent_enough
    abst_disc = disc & ~recent_enough
    abst_closed = closed & ~recent_enough
    new = live & (prof["history_days"] < MIN_HISTORY_DAYS)
    sparse = live & ~new & (prof["demand_class"] == "insufficient")

    labels = pd.Series(index=prof["unique_id"], dtype=object)
    for mask, label in ((sparse, LABEL_SPARSE), (new, LABEL_OWN), (live & prof["branch_opening"], LABEL_BRANCH_OPENING),
                        (closed & zero, LABEL_BRANCH_CLOSED), (disc & zero, LABEL_DISCONTINUED)):
        ids = prof.loc[mask, "unique_id"].tolist()
        if ids:
            labels.loc[ids] = label

    abstain: dict[str, str] = {}
    for uid in prof.loc[dormant, "unique_id"]:
        abstain[uid] = LABEL_NO_RECENT
    for uid in prof.loc[abst_disc, "unique_id"]:
        abstain[uid] = LABEL_DISCONTINUED_ABSTAIN
    for uid in prof.loc[abst_closed, "unique_id"]:
        abstain[uid] = LABEL_BRANCH_CLOSED + "_abstained"

    # ── Nivel de las series nuevas / de historia escasa ──
    by_bc, by_c = _benchmarks(prof, panel_df, cutoff)
    own_mean = (panel_df[panel_df["ds"] <= cutoff].groupby("unique_id")["y"].mean() if not panel_df.empty
                else pd.Series(dtype=float))
    levels: dict[str, float] = {}
    family: dict[str, str] = {}
    methods = {"category_median_branch": 0, "category_median_company": 0, "own_average": 0, "own_average_low_evidence": 0}
    for r in prof.loc[new | sparse].itertuples(index=False):
        uid = r.unique_id
        own = float(own_mean.get(uid, 0.0))
        thin = (r.history_days < NEW_OWN_MIN_DAYS) or (r.nonzero_days < MIN_NONZERO_DAYS)
        bench, how = None, None
        if r.history_days < MIN_HISTORY_DAYS and thin and not pd.isna(r.category_id):
            key = (r.branch_id, r.category_id)
            if key in by_bc.index:
                bench, how = float(by_bc.loc[key]), "category_median_branch"
            elif r.category_id in by_c.index:
                bench, how = float(by_c.loc[r.category_id]), "category_median_company"
        if bench is not None:
            levels[uid], family[uid] = bench, CATEGORY_FAMILY
            methods[how] += 1
            if labels.get(uid) != LABEL_BRANCH_OPENING:
                labels[uid] = LABEL_CATEGORY
        else:
            levels[uid], family[uid] = own, "window_average"
            if r.history_days < MIN_HISTORY_DAYS and thin:
                methods["own_average_low_evidence"] += 1
                if labels.get(uid) != LABEL_BRANCH_OPENING:
                    labels[uid] = LABEL_OWN_LOW
            elif r.history_days < MIN_HISTORY_DAYS:
                methods["own_average"] += 1
    labels = labels.dropna()

    counts = labels.value_counts().to_dict()
    opened_branches = sorted(int(b) for b in prof.loc[prof["branch_opening"], "branch_id"].unique())
    closed_branches = sorted(int(b) for b in prof.loc[closed, "branch_id"].unique())
    summary = {
        "label_counts": {k: int(v) for k, v in counts.items()},
        "new_product": {"count": int(new.sum()), "methods": methods,
                        "rule": f"< {MIN_HISTORY_DAYS} días de historia; categoría comparable: ≥ {CATEGORY_MIN_SERIES} "
                                "series maduras (sucursal+categoría, o categoría de la empresa)"},
        "sparse_history": {"count": int(sparse.sum())},
        "discontinued": {"explicit_zero": int((disc & zero).sum()), "abstained": int(abst_disc.sum()),
                         "lookback_days": DISCONTINUED_LOOKBACK_DAYS},
        "no_recent_sales": {"abstained": int(dormant.sum()), "after_days": INACTIVE_AFTER_DAYS},
        "branch_closed_declared": {"branches": closed_branches, "explicit_zero": int((closed & zero).sum()),
                                   "abstained": int(abst_closed.sum())},
        "branch_opening": {"branches": opened_branches, "series": int((live & prof["branch_opening"]).sum())},
    }
    return LifecyclePlan(labels=labels, zero_ids=prof.loc[zero, "unique_id"].tolist(), abstain=abstain,
                         short_levels=pd.Series(levels, dtype=float), short_family=pd.Series(family, dtype=object),
                         summary=summary)


def short_forecast(plan: LifecyclePlan, ids: list[str], cutoff: pd.Timestamp, h: int) -> pd.DataFrame:
    """Pronóstico plano (unique_id, ds, family, yhat) de las series nuevas o de historia escasa."""
    ids = [i for i in ids if i in plan.short_levels.index]
    if not ids:
        return pd.DataFrame(columns=["unique_id", "ds", "family", "yhat"])
    days = pd.date_range(pd.Timestamp(cutoff) + pd.Timedelta(days=1), periods=h, freq="D")
    lv = plan.short_levels.loc[ids].clip(lower=0)
    return pd.DataFrame({
        "unique_id": np.repeat(lv.index.to_numpy(), h), "ds": np.tile(days.values, len(lv)),
        "family": "__short__", "yhat": np.repeat(lv.to_numpy(), h),
    })


def zero_rows(zero_ids: list[str], profile: pd.DataFrame, cutoff: pd.Timestamp, h: int,
              category_of: pd.Series) -> pd.DataFrame:
    """Filas de pronóstico cero explícito (declarado) para series descontinuadas o de sucursal cerrada."""
    if not zero_ids:
        return pd.DataFrame()
    p = profile.set_index("unique_id").loc[zero_ids]
    days = pd.date_range(pd.Timestamp(cutoff) + pd.Timedelta(days=1), periods=h, freq="D")
    out = pd.DataFrame({
        "unique_id": np.repeat(p.index.to_numpy(), h), "ds": np.tile(days.values, len(p)),
        "branch_id": np.repeat(p["branch_id"].to_numpy(), h), "product_id": np.repeat(p["product_id"].to_numpy(), h),
    })
    out["category_id"] = out["product_id"].map(category_of)
    out["step"] = (out["ds"] - pd.Timestamp(cutoff)).dt.days.astype(int)
    out["yhat"] = 0.0
    out["demand_class"] = None
    out["model_family"] = ZERO_FAMILY
    for c in ("lo_80", "hi_80", "lo_95", "hi_95", "p50"):
        out[c] = 0.0
    out["revenue_yhat"] = 0.0
    out["unit_price_assumed"] = np.nan
    return out


# ─────────────────────────── eventos extraordinarios ───────────────────────────

EVENT_COLUMNS = ["id", "tenant_id", "scope", "scope_ref", "code", "name", "category", "starts_on", "ends_on", "status",
                 "known_at", "source", "revision"]


def known_events(events: pd.DataFrame | None, cutoff: pd.Timestamp) -> tuple[pd.DataFrame, dict[str, int]]:
    """Eventos extraordinarios conocidos al final del día `cutoff` (versión vigente ese día, sin cancelados).

    `known_at` nulo = desconocido → el evento NO se usa (no se sabe si existía al corte) y se cuenta.
    """
    stats = {"total": 0, "known_at_unknown_ignored": 0, "known_after_cutoff_ignored": 0, "cancelled": 0}
    if events is None or events.empty:
        return pd.DataFrame(columns=EVENT_COLUMNS), stats
    ev = events[events["category"] == "exceptional"].copy()
    stats["total"] = int(len(ev))
    ev["known_at"] = pd.to_datetime(ev["known_at"], utc=True).dt.tz_localize(None)  # UTC; el corte es el fin del día UTC
    end_of_cutoff = pd.Timestamp(cutoff).normalize() + pd.Timedelta(days=1)
    unknown = ev["known_at"].isna()
    late = ~unknown & (ev["known_at"] >= end_of_cutoff)
    stats["known_at_unknown_ignored"] = int(unknown.sum())
    stats["known_after_cutoff_ignored"] = int(late.sum())
    ev = ev[~unknown & ~late]
    if ev.empty:
        return ev, stats
    ev = ev.sort_values(["tenant_id", "scope", "scope_ref", "code", "starts_on", "revision"], na_position="first")
    ev = ev.groupby(["scope", "scope_ref", "code", "starts_on"], dropna=False).tail(1)
    stats["cancelled"] = int((ev["status"] == "cancelled").sum())
    return ev[ev["status"] != "cancelled"].reset_index(drop=True), stats


def event_series(event: Any, series: pd.DataFrame, branches: pd.DataFrame) -> np.ndarray:
    """Máscara de series (columnas unique_id, branch_id, product_id, category_id) alcanzadas por el alcance del evento."""
    scope, ref = event.scope, (event.scope_ref or "")
    n = len(series)
    if scope in ("country", "tenant", "sector"):
        return np.ones(n, dtype=bool)
    if scope == "region":
        region = branches.set_index("branch_id")["region"] if "region" in branches else pd.Series(dtype=object)
        return (series["branch_id"].map(region).astype(str).str.lower() == ref.lower()).to_numpy()
    for kind, col in (("branch", "branch_id"), ("product", "product_id"), ("category", "category_id")):
        if scope == kind:
            try:
                target = int(ref.split(":", 1)[1] if ":" in ref else ref)
            except ValueError:
                return np.zeros(n, dtype=bool)
            return (series[col].astype(float) == target).to_numpy()
    return np.zeros(n, dtype=bool)


def flag_events(series_fc: pd.DataFrame, events: pd.DataFrame, branches: pd.DataFrame
                ) -> tuple[np.ndarray, list[dict[str, Any]]]:
    """Marca las filas (serie, fecha) dentro de un evento extraordinario conocido. Devuelve (máscara, resumen)."""
    mask = np.zeros(len(series_fc), dtype=bool)
    used: list[dict[str, Any]] = []
    if events.empty or series_fc.empty:
        return mask, used
    uids = series_fc.drop_duplicates("unique_id")[["unique_id", "branch_id", "product_id", "category_id"]]
    ds = pd.to_datetime(series_fc["ds"]).to_numpy()
    for e in events.itertuples(index=False):
        in_window = (ds >= np.datetime64(pd.Timestamp(e.starts_on))) & (ds <= np.datetime64(pd.Timestamp(e.ends_on)))
        if not in_window.any():
            continue
        hit_ids = set(uids.loc[event_series(e, uids, branches), "unique_id"])
        m = in_window & series_fc["unique_id"].isin(hit_ids).to_numpy()
        if m.any():
            mask |= m
            used.append({"code": e.code, "name": e.name, "scope": e.scope, "scope_ref": e.scope_ref,
                         "starts_on": pd.Timestamp(e.starts_on).date().isoformat(),
                         "ends_on": pd.Timestamp(e.ends_on).date().isoformat(),
                         "known_at": pd.Timestamp(e.known_at).isoformat(), "source": e.source,
                         "affected_series": int(series_fc.loc[m, "unique_id"].nunique())})
    return mask, used
