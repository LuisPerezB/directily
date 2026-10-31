"""ADR-V25-23 · Customer Core con tiempo de conocimiento (T02, defecto HIGH de cliente.json).

`CustomerRepository.purchases()/returns()` leían el estado ACTUAL del documento sin tiempo de conocimiento: un documento cargado después del
corte o una anulación posterior reescribían las variables y las etiquetas de los cortes pasados. Ahora:

- las VARIABLES de cada corte usan lo que se conocía ese día (`FeatureBuilder.events_at(c)`);
- las ETIQUETAS (compras en (corte, corte+H]) usan el conocimiento más reciente al corte final (la verdad disponible).

Los documentos se insertan directamente en las tablas del contrato; cada prueba se revierte.
"""

from __future__ import annotations

from contextlib import contextmanager
from dataclasses import replace
from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest
from asof_helpers import GENERIC_CODE, World, add_document, amend_document, local, make_world

from predictily_engine.customer.data import load_customer_data
from predictily_engine.customer.dataset import build_dataset
from predictily_engine.customer.events import to_day
from predictily_engine.customer.features import BASE_FEATURES, HORIZON_FEATURES, FeatureBuilder
from predictily_engine.customer.outputs import compute_activity
from predictily_engine.customer.service import _snapshot
from predictily_engine.db.customer_repository import CustomerRepository
from predictily_engine.platform.config import resolve_config

pytestmark = pytest.mark.integration

D0 = date(2026, 3, 2)


def iso(offset: int) -> str:
    return (D0 + timedelta(days=offset)).isoformat()


def day(offset: int) -> int:
    return int(to_day(D0 + timedelta(days=offset)))


def cfg(**over):
    c, _, _ = resolve_config("customer", {}, None, tenant_overrides=over or None)
    return c


@contextmanager
def rolled_back(db_engine):
    with db_engine.connect() as conn:
        tx = conn.begin()
        try:
            yield conn
        finally:
            tx.rollback()


def repo_of(conn, w: World) -> CustomerRepository:
    repo = CustomerRepository(conn, w.tenant_id)
    repo.base.analytic_reads()
    return repo


def keyed(frame: pd.DataFrame) -> pd.DataFrame:
    return frame.set_index(["customer_id", "product_id"]).sort_index()


def test_purchases_and_returns_contain_exactly_the_valid_documents(db_engine):
    """Prueba recomendada por la auditoría: un documento de cada tipo y estado, dos sucursales el mismo día y un comprador genérico."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=2, n_products=2, n_customers=2, generic=True)
        add_document(conn, w, number="F-1", sale_date=iso(10), lines=[(0, 2, 10.0)], customer=0, branch=0)
        add_document(conn, w, number="N-1", sale_date=iso(10), lines=[(0, 3, 10.0)], customer=0, branch=1, doc_type="sales_note")
        add_document(conn, w, number="O-1", sale_date=iso(11), lines=[(0, 4, 10.0)], customer=0, branch=0, doc_type="other")
        add_document(conn, w, number="C-1", sale_date=iso(12), lines=[(0, 1, 10.0)], customer=0, branch=0, doc_type="credit_note")
        add_document(conn, w, number="V-1", sale_date=iso(13), lines=[(0, 100, 10.0)], customer=0, status="voided")
        add_document(conn, w, number="P-1", sale_date=iso(14), lines=[(0, 100, 10.0)], customer=0, status="pending")
        add_document(conn, w, number="G-1", sale_date=iso(15), lines=[(0, 50, 10.0)], customer=-1)
        add_document(conn, w, number="A-1", sale_date=iso(16), lines=[(0, 60, 10.0)], customer=None)
        add_document(conn, w, number="F-2", sale_date=iso(11), lines=[(1, 5, 10.0)], customer=1, branch=0)
        repo = repo_of(conn, w)
        through = date.fromisoformat(iso(30))
        purchases = repo.purchases(through, True, [GENERIC_CODE])
        c0, c1 = w.customer_ids
        got = [(int(r.customer_id), int(r.product_id), int(r.branch_id), r.ds.date().isoformat(), float(r.qty), float(r.net_amount),
                float(r.gross_amount)) for r in purchases.itertuples()]
        b0, b1 = w.branch_ids
        p0, p1 = w.product_ids
        assert got == [(c0, p0, b0, iso(10), 2.0, 20.0, 20.0), (c0, p0, b1, iso(10), 3.0, 30.0, 30.0), (c0, p0, b0, iso(11), 4.0, 40.0, 40.0),
                       (c1, p1, b0, iso(11), 5.0, 50.0, 50.0)], "ni anuladas, ni pendientes, ni notas de crédito, ni genéricos, ni anónimos"
        assert list(purchases.columns) == ["customer_id", "product_id", "branch_id", "ds", "currency", "qty", "net_amount", "gross_amount",
                                           "discount_amount"]
        returns = repo.returns(through)
        assert [(int(r.customer_id), int(r.product_id), r.ds.date().isoformat(), float(r.qty)) for r in returns.itertuples()] == [
            (c0, p0, iso(12), 1.0)], "las devoluciones se informan aparte"
        # con la exclusión de genéricos apagada, el genérico es un comprador más
        everyone = repo.purchases(through, False, [GENERIC_CODE])
        assert w.generic_customer_id in set(everyone["customer_id"]) and float(everyone["qty"].sum()) == 2 + 3 + 4 + 5 + 50
        stats = repo.excluded_sales_stats(through, [GENERIC_CODE])
        assert stats["anonymous"] == {"lines": 1, "units": 60.0} and stats["generic"] == {"lines": 1, "units": 50.0}
        assert stats["modeled"]["units"] == 14.0 and stats["modeled_share_units_180d"] == pytest.approx(14 / (14 + 60 + 50))


def test_purchases_and_returns_are_asof_to_the_cutoff(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_customers=1)
        add_document(conn, w, number="F-1", sale_date=iso(10), lines=[(0, 2, 10.0)], customer=0)
        add_document(conn, w, number="F-2", sale_date=iso(11), lines=[(0, 100, 10.0)], customer=0)             # se anula el día 20
        add_document(conn, w, number="F-3", sale_date=iso(12), lines=[(0, 7, 10.0)], customer=0, mode="incremental",
                     known_from=local(iso(25), 9))                                                              # llega el día 25
        add_document(conn, w, number="C-1", sale_date=iso(13), lines=[(0, 1, 10.0)], customer=0, doc_type="credit_note")
        add_document(conn, w, number="C-2", sale_date=iso(13), lines=[(0, 3, 10.0)], customer=0, doc_type="credit_note",
                     mode="incremental", known_from=local(iso(26), 9))
        amend_document(conn, w, "F-2", known_from=local(iso(20), 9), status="voided")
        repo = repo_of(conn, w)

        def qty(offset: int) -> float:
            return float(repo.purchases(date.fromisoformat(iso(offset)), True, [GENERIC_CODE])["qty"].sum())

        def returned(offset: int) -> float:
            return float(repo.returns(date.fromisoformat(iso(offset)))["qty"].sum())

        assert [qty(d) for d in (12, 19, 20, 24, 25, 40)] == [102.0, 102.0, 2.0, 2.0, 9.0, 9.0]
        assert [returned(d) for d in (13, 25, 26, 40)] == [1.0, 1.0, 4.0, 4.0]
        stats = repo.excluded_sales_stats(date.fromisoformat(iso(19)), [GENERIC_CODE])
        assert stats["modeled"]["units"] == 102.0, "las estadísticas de exclusión también son as-of"
        v = repo.purchase_versions(date.fromisoformat(iso(40)), True, [GENERIC_CODE])
        assert v.has_dynamic and v.summary()["superseded_lines"] == 1 and v.summary()["late_lines"] == 2


def make_scenario(conn, perturb: bool) -> tuple[World, dict]:
    """Relación regular comprador 0 × producto 0 y un ancla de datos hasta el día 135. Con `perturb`: la factura del día 101 se anula el
    día 107, una compra del día 103 llega el día 108 y otra del día 110 llega el día 120 (todas con fecha de evento posible antes del corte)."""
    w = make_world(conn, n_branches=1, n_products=2, n_customers=2)
    n = iter(range(1, 1000))
    for d in (0, 20, 40, 60, 80, 100):
        add_document(conn, w, number=f"R-{next(n)}", sale_date=iso(d), lines=[(0, 1, 10.0)], customer=0)
    for d in (10, 50, 90):
        add_document(conn, w, number=f"R-{next(n)}", sale_date=iso(d), lines=[(0, 2, 10.0)], customer=1)
    add_document(conn, w, number="X-101", sale_date=iso(101), lines=[(0, 2, 10.0)], customer=0)
    add_document(conn, w, number="ANCLA", sale_date=iso(135), lines=[(1, 1, 10.0)], customer=1)
    if perturb:
        amend_document(conn, w, "X-101", known_from=local(iso(107), 12), status="voided")
        add_document(conn, w, number="L-103", sale_date=iso(103), lines=[(0, 4, 10.0)], customer=0, mode="incremental", known_from=local(iso(108), 9))
        add_document(conn, w, number="E-110", sale_date=iso(110), lines=[(0, 5, 10.0)], customer=0, mode="incremental", known_from=local(iso(120), 9))
    return w, {}


def load(conn, w: World, through: int = 135):
    return load_customer_data(repo_of(conn, w), cfg(), through=date.fromisoformat(iso(through)))


def test_features_are_asof_to_each_cutoff_and_labels_use_the_latest_knowledge(db_engine):
    with rolled_back(db_engine) as conn:
        w_ref, _ = make_scenario(conn, perturb=False)
        w_pert, _ = make_scenario(conn, perturb=True)
        ref, pert = load(conn, w_ref), load(conn, w_pert)
        assert pert.purchase_versions.has_dynamic and not ref.purchase_versions.has_dynamic
        assert ref.through == pert.through == date.fromisoformat(iso(135))
        fb_ref, fb_pert = FeatureBuilder(ref, cfg()), FeatureBuilder(pert, cfg())
        c = day(105)
        r, p = fb_ref.compute(c, (7, 30, 90)), fb_pert.compute(c, (7, 30, 90))
        # 1) Al corte del día 105 el estado perturbado se veía EXACTAMENTE como el de referencia: la factura del 101 aún vigente, y las
        #    compras de los días 103 y 110 aún sin conocer. Variables idénticas.
        a, b = keyed(r.base), keyed(p.base)
        assert len(a) == len(b) == 2
        # (los ids de cliente/producto difieren entre las dos empresas ficticias: se comparan por posición ordenada)
        pd.testing.assert_frame_equal(a[BASE_FEATURES].reset_index(drop=True), b[BASE_FEATURES].reset_index(drop=True))
        for h in (7, 30, 90):
            ha, hb = r.horizon[h][HORIZON_FEATURES + ["ref_p", "ref_q"]], p.horizon[h][HORIZON_FEATURES + ["ref_p", "ref_q"]]
            pd.testing.assert_frame_equal(ha.reset_index(drop=True), hb.reset_index(drop=True))
        rel = a.index[0]
        assert a.iloc[0]["rel_recency_days"] == 4 == b.iloc[0]["rel_recency_days"], "última compra conocida: la factura del día 101"
        # 2) Con el estado ACTUAL (lo que se hacía antes) las variables del día 105 cambiaban: fuga hacia el pasado
        old = FeatureBuilder(replace(pert, purchase_versions=None), cfg()).compute(c, (7,))
        o = keyed(old.base)
        assert o.iloc[0]["rel_recency_days"] == 2, "veía la compra del 103 (cargada después) y no la factura del 101 (anulada después)"
        assert not np.allclose(o[BASE_FEATURES].fillna(-1).to_numpy(float), b[BASE_FEATURES].fillna(-1).to_numpy(float))
        # 3) Las ETIQUETAS (c, c+7] usan el conocimiento más reciente: la compra del día 110 (conocida el 120) cuenta como positivo
        lab_ref = fb_ref.labels(r.base, c, 7, last_complete=day(135))
        lab_pert = fb_pert.labels(p.base, c, 7, last_complete=day(135))
        i_ref = r.base.index[(r.base["customer_id"] == w_ref.customer_ids[0]) & (r.base["product_id"] == w_ref.product_ids[0])][0]
        i_pert = p.base.index[(p.base["customer_id"] == w_pert.customer_ids[0]) & (p.base["product_id"] == w_pert.product_ids[0])][0]
        assert (lab_ref.loc[i_ref, "y"], lab_ref.loc[i_ref, "qty"]) == (0.0, 0.0)
        assert (lab_pert.loc[i_pert, "y"], lab_pert.loc[i_pert, "qty"]) == (1.0, 5.0)
        # 4) Un corte posterior a la ingestión sí las conoce: el día 125 la compra del 103 y la del 110 ya existían y la factura estaba anulada
        late = fb_pert.compute(day(125), (7,)).base
        row = keyed(late).loc[(w_pert.customer_ids[0], w_pert.product_ids[0])]
        assert row["rel_recency_days"] == 15 and row["rel_n_purchases"] == 6 + 1 + 1, "regulares + la del 103 + la del 110; sin la anulada"
        row_ref = keyed(fb_ref.compute(day(125), (7,)).base).loc[(w_ref.customer_ids[0], w_ref.product_ids[0])]
        assert row_ref["rel_recency_days"] == 24 and row_ref["rel_n_purchases"] == 6 + 1
        # 5) La actividad del comprador (frecuencia/inactividad) también se mide con lo conocido al corte
        act_ref, act_pert = compute_activity(fb_ref, c, cfg()), compute_activity(fb_pert, c, cfg())
        cols = ["days_since_last_purchase", "n_purchase_days", "purchase_frequency_per_30d", "median_interval_days", "inactivity_status"]
        pd.testing.assert_frame_equal(act_ref[cols].sort_values(cols).reset_index(drop=True), act_pert[cols].sort_values(cols).reset_index(drop=True))
        assert rel is not None


def test_the_dataset_has_asof_features_and_latest_knowledge_labels_per_cutoff(db_engine):
    """Fila del dataset (comprador × producto × corte): variables del corte con su conocimiento, etiqueta y cantidad de la verdad."""
    with rolled_back(db_engine) as conn:
        w_ref, _ = make_scenario(conn, perturb=False)
        w_pert, _ = make_scenario(conn, perturb=True)
        out = {}
        for key, w in (("ref", w_ref), ("pert", w_pert)):
            data = load(conn, w)
            fb = FeatureBuilder(data, cfg())
            bundle = build_dataset(fb, cfg(), (7,), last_complete=day(135), first_sale_day=day(0))
            frame = bundle.per_horizon[7].frame
            base = bundle.base.loc[frame["base_row"]].reset_index(drop=True)
            f = frame.reset_index(drop=True)
            f["customer_id"], f["product_id"] = base["customer_id"], base["product_id"]
            f["rel_recency_days"] = base["rel_recency_days"]
            out[key] = (f, w)
        for key, (f, w) in out.items():
            out[key] = (f[(f["customer_id"] == w.customer_ids[0]) & (f["product_id"] == w.product_ids[0])].set_index("cutoff"), w)
        ref, pert = out["ref"][0], out["pert"][0]
        assert day(100) in ref.index and day(100) in pert.index
        # corte del día 100: nada de lo perturbado existía todavía → mismas variables
        assert ref.loc[day(100), "rel_recency_days"] == pert.loc[day(100), "rel_recency_days"] == 0
        for col in HORIZON_FEATURES + ["ref_p", "ref_q"]:
            assert ref.loc[day(100), col] == pert.loc[day(100), col], col
        # …pero la etiqueta de (100, 107] ve la verdad: en la referencia la factura del 101 (2 u.), en el estado perturbado la anulada
        # no cuenta y sí la compra del 103 (4 u.) cargada después
        assert (ref.loc[day(100), "y"], ref.loc[day(100), "qty"]) == (1.0, 2.0)
        assert (pert.loc[day(100), "y"], pert.loc[day(100), "qty"]) == (1.0, 4.0)


def test_with_only_backfill_documents_the_builder_matches_the_one_without_versions(db_engine):
    """Regresión: sin documentos tardíos ni anulaciones (carga histórica) las variables y etiquetas son las de siempre."""
    with rolled_back(db_engine) as conn:
        w, _ = make_scenario(conn, perturb=False)
        data = load(conn, w)
        assert data.purchase_versions is not None and not data.purchase_versions.has_dynamic
        a, b = FeatureBuilder(data, cfg()), FeatureBuilder(replace(data, purchase_versions=None), cfg())
        for c in (day(60), day(105), day(125)):
            ca, cb = a.compute(c, (7, 30)), b.compute(c, (7, 30))
            pd.testing.assert_frame_equal(ca.base, cb.base)
            for h in (7, 30):
                pd.testing.assert_frame_equal(ca.horizon[h], cb.horizon[h])
            for h in (7, 30):
                pd.testing.assert_frame_equal(a.labels(ca.base, c, h, day(135)), b.labels(cb.base, c, h, day(135)))
        assert a.events_at(day(60)) is a._truth, "sin diferencias todos los cortes comparten un único índice de eventos"


def test_the_customer_fingerprint_covers_the_knowledge_windows(db_engine):
    """El componente de compras de la huella del Customer Core incluye las ventanas de conocimiento de los datos usados."""
    cfg_v = cfg()
    snaps = []
    for perturb_mode in ("backfill", "incremental"):
        with rolled_back(db_engine) as conn:
            w = make_world(conn, n_customers=1)
            for d in range(0, 30):
                add_document(conn, w, number=f"R-{d}", sale_date=iso(d), lines=[(0, 1 + d % 3, 10.0)], customer=0)
            kwargs = {} if perturb_mode == "backfill" else {"mode": "incremental", "known_from": local(iso(20), 9)}
            add_document(conn, w, number="Z", sale_date=iso(10), lines=[(0, 9, 10.0)], customer=0, **kwargs)
            data = load(conn, w, through=29)
            snaps.append((data, _snapshot(data, cfg_v, "v1", date.fromisoformat(iso(29)))))
    (d1, s1), (d2, s2) = snaps
    assert float(d1.purchases["qty"].sum()) == float(d2.purchases["qty"].sum())
    assert s1.components["purchases"] != s2.components["purchases"], "mismas cifras, distinto conocimiento: distinta huella"
    assert s1.components["returns"] == s2.components["returns"] and s1.hash != s2.hash


def test_the_effective_final_cutoff_defines_what_labels_can_know(db_engine):
    """La verdad de las etiquetas es el conocimiento al corte final EFECTIVO del run: lo que llega después no entra ni cambia la huella."""
    with rolled_back(db_engine) as conn:
        w, _ = make_scenario(conn, perturb=True)
        at_130 = load(conn, w, through=130)
        # E (día 110) se conoce el 120 y la anulación el 107: con corte final 115 la compra E aún no existe en la verdad
        at_115 = load(conn, w, through=115)
        assert float(at_115.purchases["qty"].sum()) < float(at_130.purchases["qty"].sum())
        assert iso(110) not in set(at_115.purchases["ds"].dt.date.astype(str)) and iso(110) in set(at_130.purchases["ds"].dt.date.astype(str))
        again = load(conn, w, through=115)
        assert again.purchases.attrs["knowledge_digest"] == at_115.purchases.attrs["knowledge_digest"]
        amend_document(conn, w, "L-103", known_from=local(iso(140), 9), status="voided")           # llega MUY después
        after = load(conn, w, through=115)
        pd.testing.assert_frame_equal(after.purchases.reset_index(drop=True), at_115.purchases.reset_index(drop=True))
        assert after.purchases.attrs["knowledge_digest"] == at_115.purchases.attrs["knowledge_digest"]


def test_a_live_customer_run_knows_the_nightly_load_of_its_last_day_and_a_historical_one_does_not(db_engine):
    """Corte = última venta: la verdad (compras, etiquetas y estadísticas de exclusión) conoce hasta hoy, así que la carga nocturna del último
    día cuenta; la reconstrucción histórica del mismo corte (hay ventas posteriores) no. Las variables de un corte anterior no dependen de ello."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_customers=2)
        for d in (0, 10, 20):
            add_document(conn, w, number=f"R-{d}", sale_date=iso(d), lines=[(0, 1, 10.0)], customer=0)
        add_document(conn, w, number="N-20", sale_date=iso(20), lines=[(0, 5, 10.0)], customer=0, mode="incremental", known_from=local(iso(21), 3))
        add_document(conn, w, number="N-ANON", sale_date=iso(20), lines=[(0, 7, 10.0)], customer=None, mode="incremental", known_from=local(iso(21), 3))
        c = day(13)

        def label(data):
            fb = FeatureBuilder(data, cfg())
            base = fb.compute(c, (7,)).base
            lab = fb.labels(base, c, 7, last_complete=day(20))
            i = base.index[(base["customer_id"] == w.customer_ids[0]) & (base["product_id"] == w.product_ids[0])][0]
            return base, (float(lab.loc[i, "y"]), float(lab.loc[i, "qty"]))

        live = load(conn, w, through=20)
        v = live.purchase_versions
        assert v.knowledge > v.final == pd.Timestamp(iso(20)), "ejecución vigente: conoce hasta hoy"
        assert float(live.purchases["qty"].sum()) == 1 + 1 + 1 + 5 and live.excluded["anonymous"]["lines"] == 1
        base_live, label_live = label(live)
        assert label_live == (1.0, 6.0), "etiqueta (13, 20]: la compra del día 20 más la carga nocturna"

        add_document(conn, w, number="R-30", sale_date=iso(30), lines=[(0, 1, 10.0)], customer=0)      # el día 20 deja de ser el último dato
        hist = load(conn, w, through=20)
        assert hist.purchase_versions.knowledge == hist.purchase_versions.final == pd.Timestamp(iso(20))
        assert float(hist.purchases["qty"].sum()) == 3.0 and hist.excluded["anonymous"]["lines"] == 0
        base_hist, label_hist = label(hist)
        assert label_hist == (1.0, 1.0), "reconstrucción histórica: la carga nocturna del día 21 no existía"
        pd.testing.assert_frame_equal(base_live[BASE_FEATURES].reset_index(drop=True), base_hist[BASE_FEATURES].reset_index(drop=True))
        assert live.purchases.attrs["knowledge_digest"] != hist.purchases.attrs["knowledge_digest"]
        # el mismo corte pedido con el repositorio sin conocimiento extra sigue siendo la lectura estricta
        repo = repo_of(conn, w)
        strict = repo.purchases(date.fromisoformat(iso(20)), True, [GENERIC_CODE])
        assert float(strict["qty"].sum()) == 3.0
        assert float(repo.purchases(date.fromisoformat(iso(20)), True, [GENERIC_CODE], date.fromisoformat(iso(25)))["qty"].sum()) == 8.0
