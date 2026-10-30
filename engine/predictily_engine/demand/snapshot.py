"""Huella reproducible de una ejecución de demanda (ADR-V2-04): `data_snapshots` + `forecast_runs.data_snapshot_id`.

Cubre, además de las cantidades vendidas, TODO lo que puede cambiar el resultado de un corte: devoluciones,
inventario, precios observados, promociones tal como se conocían al corte (as-of), costos, catálogo,
calendario y feature set (incluidos los eventos extraordinarios conocidos), insumos de abastecimiento,
configuración efectiva, reglas de elegibilidad y cobertura de las fuentes. Reproducibilidad = mismo snapshot
+ misma configuración + misma semilla ⇒ mismos valores (prueba `test_snapshot_config.py`).
"""

from __future__ import annotations

from importlib import metadata
from typing import Any

import pandas as pd

from predictily_engine.backtest.rolling import MIN_SCALE_PAIRS, SEASON, TIERS
from predictily_engine.config import FEATURE_SET_VERSION
from predictily_engine.features.builder import CALENDAR_FEATURES, observed_promotions, promotions_as_of
from predictily_engine.models.ml import MIN_SERIES, MIN_TRAIN_ROWS
from predictily_engine.platform.snapshot import DataSnapshot
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


def build_snapshot(data: Any, panel: Panel, effective_config: dict[str, Any], knowledge_date: pd.Timestamp,
                   exceptional_events: pd.DataFrame | None, coverage: dict[str, Any] | None) -> DataSnapshot:
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
    snap.add_value("external_indicators", {"enabled": False})
    snap.add_value("supply_inputs", {
        "lead_times": None if data.lead_times is None or data.lead_times.empty else
        data.lead_times.astype(str).values.tolist(),
        "suppliers": None if data.suppliers is None or data.suppliers.empty else data.suppliers.astype(str).values.tolist(),
        "open_purchase_orders": None if data.open_purchase_orders is None or data.open_purchase_orders.empty else
        data.open_purchase_orders.astype(str).values.tolist(),
    })
    snap.add_value("config", effective_config)
    snap.add_value("eligibility", eligibility_rules())
    snap.add_value("coverage", coverage or {})
    return snap
