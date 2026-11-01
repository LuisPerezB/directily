"""ADR-V25-23 · costo del Customer Core con documentos que llegan tarde (hallazgo LOW de la revisión: medir y documentar).

Con compras cargadas de forma incremental (conocidas después de su día) el `FeatureBuilder` arma una vista de eventos POR CORTE
(`events_at(c)`); sin ellas todos los cortes comparten un único índice. La prueba mide ambos escenarios sobre el mundo sintético del Customer
Core, reporta las cifras (propiedades de la prueba y `-s`) y fija umbrales holgados para no fallar por ruido de la máquina. El costo medido y
sus supuestos están en `docs/data/TEMPORAL_AVAILABILITY.md` (§12.13).
"""

from __future__ import annotations

import time
from dataclasses import replace

import numpy as np
import pandas as pd

from predictily_engine.customer.dataset import build_dataset
from predictily_engine.customer.events import to_day
from predictily_engine.customer.features import FeatureBuilder
from predictily_engine.customer.synthetic import generate_world
from predictily_engine.data.asof import PurchaseVersions
from predictily_engine.platform.config import resolve_config


def _versions(purchases: pd.DataFrame, late_share: float, seed: int = 1):
    """Convierte las compras sintéticas en versiones: `late_share` llega al día siguiente a las 08:00 (carga incremental tardía)."""
    rng = np.random.default_rng(seed)
    late = rng.random(len(purchases)) < late_share
    ds = purchases["ds"]
    known_from = np.where(late, (ds + pd.Timedelta(days=1, hours=8)).to_numpy(), (ds + pd.Timedelta(hours=5)).to_numpy())
    lines = pd.DataFrame({
        "sale_id": np.arange(len(purchases)), "line_number": 1, "is_ret": False, "customer_id": purchases["customer_id"].to_numpy(),
        "product_id": purchases["product_id"].to_numpy(), "branch_id": purchases["branch_id"].to_numpy(), "ds": ds.to_numpy(),
        "currency": purchases["currency"].to_numpy(), "qty": purchases["qty"].to_numpy(), "net_amount": purchases["net_amount"].to_numpy(),
        "gross_amount": purchases["gross_amount"].to_numpy(), "discount_amount": purchases["discount_amount"].to_numpy(),
        "known_from": known_from, "known_until": pd.NaT, "ingestion_mode": np.where(late, "incremental", "backfill")})
    final = ds.max()
    return PurchaseVersions.from_lines(lines, "America/Guayaquil", final), final


def test_the_cost_of_late_documents_in_the_customer_dataset_is_reported(record_property):
    cfg, _, _ = resolve_config("customer", {}, None)
    data = generate_world(seed=7, n_customers=400, n_products=12, n_days=540, n_branches=2).customer_data()
    first_day = int(to_day(data.purchases["ds"].min().date()))
    timings: dict[str, float] = {}
    rows: dict[str, int] = {}
    for label, share in (("backfill", 0.0), ("late_10pct", 0.10)):
        versions, final = _versions(data.purchases, share)
        d = replace(data, purchases=versions.purchase_cells(final), returns=versions.return_cells(final), purchase_versions=versions,
                    through=final.date())
        t0 = time.perf_counter()
        builder = FeatureBuilder(d, cfg)
        bundle = build_dataset(builder, cfg, (7, 30), last_complete=int(to_day(final.date())), first_sale_day=first_day)
        timings[label] = time.perf_counter() - t0
        rows[label] = sum(len(b.frame) for b in bundle.per_horizon.values())
        assert versions.has_dynamic is (share > 0) and len(bundle.cutoffs) > 10
    for key, value in (("purchase_cells", len(data.purchases)), ("backfill_s", round(timings["backfill"], 3)),
                       ("late_10pct_s", round(timings["late_10pct"], 3)), ("ratio", round(timings["late_10pct"] / timings["backfill"], 2))):
        record_property(key, value)
    print(f"\n[costo Customer con tardíos] {len(data.purchases)} compras-celda, {len(bundle.cutoffs)} cortes: sin tardías "
          f"{timings['backfill']:.2f}s | 10 % tardías {timings['late_10pct']:.2f}s (×{timings['late_10pct'] / timings['backfill']:.1f})")
    assert abs(rows["backfill"] - rows["late_10pct"]) <= 0.05 * rows["backfill"], "las filas del dataset no cambian materialmente (solo el conocimiento)"
    assert timings["late_10pct"] < max(12 * timings["backfill"], 20.0), "umbral holgado: el costo crece de forma acotada con las vistas por corte"
