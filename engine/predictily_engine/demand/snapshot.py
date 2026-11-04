"""Huella reproducible de una ejecución de demanda (ADR-V2-04): `data_snapshots` + `forecast_runs.data_snapshot_id`.

Cubre, además de las cantidades vendidas, TODO lo que puede cambiar el resultado de un corte: devoluciones,
inventario, precios observados, promociones tal como se conocían al corte (as-of), costos, catálogo,
calendario y feature set (incluidos los eventos extraordinarios conocidos), insumos de abastecimiento,
configuración efectiva, reglas de elegibilidad y cobertura de las fuentes. Reproducibilidad = mismo snapshot
+ misma configuración + misma semilla ⇒ mismos valores (prueba `test_snapshot_config.py`).

Semántica de días (ADR-V25-03): dos paneles con las mismas ventas pueden diferir en qué días son `missing_source` o `closed` (huecos de la
fuente, cierres del calendario, mapa fuente → sucursales que expande los huecos por fuente, reglas `DayRules`), y eso cambia el entrenamiento y
las métricas. Por eso la huella lleva dos componentes: `day_inputs` (las ENTRADAS declaradas, ordenadas: huecos, cierres y mapa fuente → sucursales) y
`day_status` (su RESULTADO: los días-serie excluidos con su estado y base, que recoge también la regla inferida `DayRules`, cuyos parámetros
viajan además en la configuración efectiva). `persist_snapshot` reutiliza la fila por (empresa, core, hash): sin ellos, declarar un cierre o
un hueco no cambiaba la huella.
"""

from __future__ import annotations

from importlib import metadata
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.backtest.rolling import MIN_SCALE_PAIRS, SEASON, TIERS
from predictily_engine.config import FEATURE_SET_VERSION
from predictily_engine.features.builder import CALENDAR_FEATURES, observed_promotions, promotions_as_of
from predictily_engine.models.ml import MIN_SERIES, MIN_TRAIN_ROWS
from predictily_engine.platform.snapshot import DataSnapshot
from predictily_engine.profiling.days import excluded_days
from predictily_engine.profiling.series import (
    ADI_THRESHOLD,
    CV2_THRESHOLD,
    INACTIVE_AFTER_DAYS,
    MIN_HISTORY_DAYS,
    MIN_NONZERO_DAYS,
    Panel,
)

SNAPSHOT_CORE = "demand"


def eligibility_rules() -> dict[str, Any]:
    """Reglas que deciden qué series se entrenan, evalúan y con qué protocolo (cambian el resultado)."""
    return {
        "adi_threshold": ADI_THRESHOLD, "cv2_threshold": CV2_THRESHOLD, "min_history_days": MIN_HISTORY_DAYS,
        "min_nonzero_days": MIN_NONZERO_DAYS, "inactive_after_days": INACTIVE_AFTER_DAYS,
        "lgbm_min_series": MIN_SERIES, "lgbm_min_train_rows": MIN_TRAIN_ROWS, "backtest_tiers": [list(t) for t in TIERS],
        "scale": {"season": SEASON, "min_pairs": MIN_SCALE_PAIRS},
    }


def _lib(name: str) -> str:
    try:
        return metadata.version(name)
    except metadata.PackageNotFoundError:  # pragma: no cover
        return "n/d"


def _sorted_rows(df: pd.DataFrame | None, columns: tuple[str, ...], dates: tuple[str, ...]) -> list[list[str]]:
    """Filas ordenadas y como texto estable (fechas ISO, enteros sin decimales, nulos vacíos) de un marco de entradas declaradas."""
    if df is None or len(df) == 0:
        return []
    out = pd.DataFrame(index=df.index)
    for col in columns:
        values = df[col] if col in df else pd.Series(np.nan, index=df.index)
        if col in dates:
            out[col] = pd.to_datetime(values).dt.strftime("%Y-%m-%d").fillna("")
        else:
            out[col] = values.map(lambda v: "" if pd.isna(v) else (str(int(v)) if isinstance(v, (float, np.floating)) and float(v).is_integer()
                                                                   else str(v)))
    return sorted(out.values.tolist())


def day_classification_inputs(data: Any) -> dict[str, Any]:
    """Entradas declaradas de la clasificación de días (ADR-V25-03): huecos y cierres ordenados y el mapa fuente → sucursales que expande los
    huecos por fuente. Las reglas de la regla inferida (`day_status_*`) viajan en la configuración efectiva (componente `config`) y su efecto en
    `day_status`."""
    return {
        "coverage_gaps": _sorted_rows(getattr(data, "coverage_gaps", None),
                                      ("data_source_id", "branch_id", "gap_from", "gap_to", "reason", "basis"), ("gap_from", "gap_to")),
        "closures": _sorted_rows(getattr(data, "closures", None), ("branch_id", "starts_on", "ends_on", "code"), ("starts_on", "ends_on")),
        "source_branches": {str(k): sorted(int(b) for b in v) for k, v in sorted((getattr(data, "source_branches", None) or {}).items())},
    }


def excluded_day_frame(panel: Panel) -> pd.DataFrame | None:
    """Series-día excluidos del panel (`missing_source` / `closed`) con su estado y base: el RESULTADO de la clasificación de días."""
    df = panel.df
    if df is None or df.empty or "day_status" not in df:
        return None
    rows = df.loc[excluded_days(df), ["unique_id", "ds", "day_status", "day_basis"]]
    return rows.assign(day_status=rows["day_status"].astype(str), day_basis=rows["day_basis"].astype(str))


def build_snapshot(data: Any, panel: Panel, effective_config: dict[str, Any], knowledge_date: pd.Timestamp,
                   exceptional_events: pd.DataFrame | None, coverage: dict[str, Any] | None, factors: dict[str, Any] | None = None) -> DataSnapshot:
    """`factors` (W6-E · H2): con el pipeline de factores ENCENDIDO, el componente `external_indicators` lleva por cada factor resuelto su clave, la versión de
    su definición, la huella de sus valores visibles y su estado; sin él (apagado, por defecto) el componente es `{"enabled": false}` como siempre y la huella
    de datos no cambia."""
    snap = DataSnapshot(core=SNAPSHOT_CORE, as_of=panel.cutoff.date(), coverage=coverage or {})
    snap.add_frame("sales", data.sales, ["branch_id", "product_id", "ds", "units", "net_amount"],
                   ["branch_id", "product_id", "ds"])
    snap.add_frame("returns", data.returns, None if data.returns is None or data.returns.empty else
                   [c for c in ("branch_id", "product_id", "ds", "units") if c in data.returns],
                   ["branch_id", "product_id", "ds"])
    snap.add_frame("inventory", data.inventory, None if data.inventory is None or data.inventory.empty else
                   [c for c in ("branch_id", "product_id", "ds", "on_hand", "reserved", "in_transit") if c in data.inventory],
                   ["branch_id", "product_id", "ds"])
    snap.add_frame("prices", panel.df, ["unique_id", "ds", "price"], ["unique_id", "ds"])
    cost_cols = [c for c in ("branch_id", "product_id", "ds", "cost_amount", "cost_units") if c in data.sales]
    snap.add_frame("costs", data.sales, cost_cols, ["branch_id", "product_id", "ds"])
    snap.add_value("catalog", {
        "products": None if data.products is None or data.products.empty else
        data.products.sort_values("product_id")[["product_id", "category_id", "is_active", "current_price",
                                                 "current_cost"]].astype(str).values.tolist(),
        "branches": None if data.branches is None or data.branches.empty else
        data.branches.sort_values("branch_id")[[c for c in ("branch_id", "province", "canton", "is_active")
                                                if c in data.branches]].astype(str).values.tolist(),
    })
    obs = observed_promotions(data.promotions)
    asof = promotions_as_of(data.promotions, data.promotion_revisions, knowledge_date)
    promo_cols = ["promotion_id", "discount_pct", "starts_on", "ends_on", "branch_id", "product_id", "category_id"]

    def _h(df: pd.DataFrame, with_known_at: pd.DataFrame | None = None) -> list:
        if df is None or df.empty:
            return []
        out = df.sort_values("promotion_id")[promo_cols]
        if with_known_at is not None and "known_at" in with_known_at:  # procedencia: cuándo se conocía cada una
            out = out.assign(known_at=out["promotion_id"].map(with_known_at.set_index("promotion_id")["known_at"]))
        return out.astype(str).values.tolist()

    snap.add_value("promotions", {"knowledge_date": str(pd.Timestamp(knowledge_date).date()),
                                  "observed": _h(obs, data.promotions), "as_of": _h(asof, data.promotions)})
    events = exceptional_events if exceptional_events is not None else pd.DataFrame()
    snap.add_value("calendar", {
        "feature_set_version": FEATURE_SET_VERSION, "calendar_features": list(CALENDAR_FEATURES),
        "holidays": _lib("holidays"),
        "exceptional_events": [] if events.empty else
        events.sort_values(["code", "starts_on"])[["code", "scope", "scope_ref", "starts_on", "ends_on", "known_at",
                                                    "revision"]].astype(str).values.tolist(),
    })
    snap.add_value("external_indicators", {"enabled": False} if factors is None else factors)
    snap.add_value("supply_inputs", {
        "lead_times": None if data.lead_times is None or data.lead_times.empty else
        data.lead_times.astype(str).values.tolist(),
        "suppliers": None if data.suppliers is None or data.suppliers.empty else data.suppliers.astype(str).values.tolist(),
        "open_purchase_orders": None if data.open_purchase_orders is None or data.open_purchase_orders.empty else
        data.open_purchase_orders.astype(str).values.tolist(),
    })
    snap.add_value("day_inputs", day_classification_inputs(data))
    snap.add_frame("day_status", excluded_day_frame(panel), None, ["unique_id", "ds"])
    snap.add_value("config", effective_config)
    snap.add_value("eligibility", eligibility_rules())
    snap.add_value("coverage", coverage or {})
    return snap
