"""Integración: regla as-of de promociones con el esquema real (known_at, promotion_revisions)."""

from __future__ import annotations

from datetime import timedelta

import pandas as pd
import pytest
from sqlalchemy import text

from loader import load_company
from predictily_engine.db.repository import TenantRepository
from predictily_engine.features.builder import FeatureBuilder
from predictily_engine.pipeline.data import load_tenant_data
from predictily_engine.profiling.series import build_panel
from predictily_engine.synthetic import generate_company

pytestmark = pytest.mark.integration

DAY = pd.Timedelta(days=1)


def _local(d, hour: int = 10) -> str:
    return f"{d.isoformat()} {hour:02d}:00:00-05:00"  # hora de Guayaquil


@pytest.fixture(scope="module")
def promo_tenant(db_engine, tenants):
    """Empresa P con tres promociones manuales alrededor de un corte histórico C.

    - late: registrada el día C+3 (después del corte).
    - modified: conocida en C-10 con 10 %; en C+5 se cambió a 35 %.
    - cancelled: conocida en C-10 con 20 %; dada de baja en C+1.
    """
    company = generate_company("ferreteria_andina", seed=42, n_products=6, start="2026-01-01")
    with db_engine.begin() as conn:
        tid = load_company(conn, company, "Empresa P (promociones, prueba ficticia)", with_inventory=False)
        last = conn.execute(text("SELECT MAX(sale_date) FROM sales WHERE tenant_id = :t"), {"t": tid}).scalar_one()
        cutoff = last - timedelta(days=40)
        products = conn.execute(text("SELECT id FROM products WHERE tenant_id = :t ORDER BY id"), {"t": tid}).scalars().all()
        specs = {"late": (products[0], 0.4, cutoff + timedelta(days=3), None),
                 "modified": (products[1], 0.35, cutoff - timedelta(days=10), None),
                 "cancelled": (products[2], 0.2, cutoff - timedelta(days=10), cutoff + timedelta(days=1))}
        ids = {}
        for name, (product, pct, known, deleted) in specs.items():
            ids[name] = conn.execute(text(
                "INSERT INTO promotions (tenant_id, name, promo_type, discount_pct, starts_on, ends_on, product_id, "
                "known_at, deleted_at, created_at, updated_at) VALUES (:t, :n, 'percentage', :d, :s, :e, :p, "
                "CAST(:k AS timestamptz), CAST(:del AS timestamptz), now(), now()) RETURNING id"
            ), {"t": tid, "n": f"Prueba {name}", "d": pct, "s": cutoff + timedelta(days=2),
                "e": cutoff + timedelta(days=8), "p": product, "k": _local(known),
                "del": _local(deleted) if deleted else None}).scalar_one()
        revisions = [("late", 1, 0.4, cutoff + timedelta(days=3), False),
                     ("modified", 1, 0.1, cutoff - timedelta(days=10), False),
                     ("modified", 2, 0.35, cutoff + timedelta(days=5), False),
                     ("cancelled", 1, 0.2, cutoff - timedelta(days=10), False),
                     ("cancelled", 2, 0.2, cutoff + timedelta(days=1), True)]
        for name, rev, pct, recorded, deleted in revisions:
            conn.execute(text(
                "INSERT INTO promotion_revisions (tenant_id, promotion_id, revision, name, promo_type, discount_pct, "
                "starts_on, ends_on, branch_id, product_id, category_id, known_at, deleted, source, recorded_at) "
                "SELECT tenant_id, id, :r, name, promo_type, :d, starts_on, ends_on, branch_id, product_id, category_id, "
                "known_at, :del, 'api', CAST(:rec AS timestamptz) FROM promotions WHERE tenant_id = :t AND id = :id"
            ), {"t": tid, "id": ids[name], "r": rev, "d": pct, "del": deleted, "rec": _local(recorded)})
    return {"tenant_id": tid, "cutoff": pd.Timestamp(cutoff), "products": products[:3], "ids": ids}


def _load(db_engine, tenant_id: int, cutoff=None):
    with db_engine.connect() as conn:
        return load_tenant_data(TenantRepository(conn, tenant_id), cutoff.date() if cutoff is not None else None)


def _features(data) -> FeatureBuilder:
    panel = build_panel(data)
    return FeatureBuilder(panel.df, data.branches, data.promotions, (panel.cutoff + 60 * DAY).date(),
                          promotion_revisions=data.promotion_revisions,
                          promotions_known_on=data.promotions_known_on)


def _by_product(frame: pd.DataFrame, product_id: int, start, end) -> pd.Series:
    rows = frame[frame["unique_id"].str.endswith(f"-p{product_id}") & frame["ds"].between(start, end)]
    assert not rows.empty
    return rows["promo_discount"]


def test_repository_reads_known_at_and_revisions_scoped_by_tenant(promo_tenant, db_engine, tenants):
    tid, c = promo_tenant["tenant_id"], promo_tenant["cutoff"]
    data = _load(db_engine, tid, c)
    assert data.promotions_known_on is None  # ejecución histórica: solo lo conocido al corte
    promos = data.promotions.set_index("promotion_id")
    assert {"known_at", "deleted_at"} <= set(promos.columns)
    late = promos.loc[promo_tenant["ids"]["late"]]
    assert late["known_at"] == pd.Timestamp(c.date() + timedelta(days=3)) + pd.Timedelta(hours=10)  # hora local
    assert pd.notna(promos.loc[promo_tenant["ids"]["cancelled"], "deleted_at"])
    rev = data.promotion_revisions
    with db_engine.connect() as conn:
        own = set(conn.execute(text("SELECT id FROM promotions WHERE tenant_id = :t"), {"t": tid}).scalars())
        total_other = conn.execute(text("SELECT COUNT(*) FROM promotion_revisions WHERE tenant_id <> :t"),
                                   {"t": tid}).scalar_one()
    assert total_other > 0 and set(rev["promotion_id"]) <= own  # nunca mezcla revisiones de otras empresas
    assert len(rev[rev["promotion_id"] == promo_tenant["ids"]["modified"]]) == 2
    # Ejecución vigente (corte = último dato): vale lo conocido hoy.
    assert _load(db_engine, tid).promotions_known_on is not None


def test_future_uses_only_versions_known_at_the_cutoff(promo_tenant, db_engine):
    tid, c = promo_tenant["tenant_id"], promo_tenant["cutoff"]
    p_late, p_mod, p_cancel = promo_tenant["products"]
    data = _load(db_engine, tid, c)
    fb = _features(data)
    ids = build_panel(data).profile["unique_id"].tolist()
    _, ref = fb.training_frame(c, ids)
    fut = fb.future_frame(c, ids, 14, ref)
    window = (c + 2 * DAY, c + 8 * DAY)
    assert (_by_product(fut, p_late, *window) == 0.0).all()  # registrada después del corte
    assert (_by_product(fut, p_mod, *window) == 0.1).all()  # valores conocidos al corte
    assert (_by_product(fut, p_cancel, *window) == 0.2).all()  # planificada al corte; se canceló después

    later = c + 6 * DAY
    data_later = _load(db_engine, tid, later)
    fb_later = _features(data_later)
    _, ref_l = fb_later.training_frame(later, ids)
    fut_l = fb_later.future_frame(later, ids, 14, ref_l)
    rest = (later + DAY, c + 8 * DAY)
    assert (_by_product(fut_l, p_late, *rest) == 0.4).all()
    assert (_by_product(fut_l, p_mod, *rest) == 0.35).all()
    assert (_by_product(fut_l, p_cancel, *rest) == 0.0).all()

    # Pasado (≤ corte): tal como ocurrió (última versión; la cancelada no ocurrió).
    final = _load(db_engine, tid)
    fb_final = _features(final)
    tr, _ = fb_final.training_frame(pd.Timestamp(final.cutoff), ids)
    assert (_by_product(tr, p_late, *window) == 0.4).all()
    assert (_by_product(tr, p_mod, *window) == 0.35).all()
    assert (_by_product(tr, p_cancel, *window) == 0.0).all()
