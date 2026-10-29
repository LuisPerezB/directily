"""Regla as-of de promociones (hallazgo F5 de la auditoría 2026-10-28).

Para un corte C: las fechas ≤ C usan la promoción tal como ocurrió (última versión); las
fechas > C solo usan promociones conocidas al corte (`known_at` ≤ C) con los valores de la
versión vigente ese día (`recorded_at` ≤ C). Nada registrado después de C puede cambiar el
pronóstico hecho en C.
"""

from datetime import date

import numpy as np
import pandas as pd
import pandas.testing as pdt

from predictily_engine.backtest.rolling import BacktestPlan, run_backtest
from predictily_engine.features.builder import FeatureBuilder, promotions_as_of
from predictily_engine.pipeline.data import TenantData
from predictily_engine.profiling.series import build_panel
from predictily_engine.synthetic import generate_company

CUTOFF = pd.Timestamp("2026-04-01")
DAY = pd.Timedelta(days=1)
IDS = ["b1-p1"]


def _panel():
    ds = pd.date_range("2026-01-01", "2026-07-31")
    sales = pd.DataFrame({"branch_id": 1, "product_id": 1, "ds": ds, "units": 10.0, "net_amount": 20.0})
    data = TenantData(tenant_id=1, settings={}, cutoff=date(2026, 7, 31),
                      branches=pd.DataFrame({"branch_id": [1], "region": ["costa"], "canton": ["guayaquil"]}),
                      products=pd.DataFrame({"product_id": [1], "category_id": [1], "is_active": [True]}),
                      categories=pd.DataFrame(), sales=sales)
    return build_panel(data), data.branches


PANEL, BRANCHES = _panel()


def _promo(pid=1, discount=0.5, starts=CUTOFF + DAY, ends=CUTOFF + 7 * DAY, **extra) -> dict:
    return {"promotion_id": pid, "discount_pct": discount, "starts_on": starts, "ends_on": ends,
            "branch_id": 1, "product_id": 1, "category_id": 1, **extra}


def _rev(pid, revision, recorded_at, discount=0.5, starts=CUTOFF + DAY, ends=CUTOFF + 7 * DAY, deleted=False) -> dict:
    return {**_promo(pid, discount, starts, ends), "revision": revision, "recorded_at": recorded_at,
            "deleted": deleted}


def _builder(promos: list[dict], revisions: list[dict] | None = None, known_on=None) -> FeatureBuilder:
    return FeatureBuilder(PANEL.df, BRANCHES, pd.DataFrame(promos), date(2026, 9, 30),
                          promotion_revisions=pd.DataFrame(revisions) if revisions else None,
                          promotions_known_on=known_on)


def _future(fb: FeatureBuilder, cutoff=CUTOFF, h=14) -> pd.Series:
    _, ref = fb.training_frame(cutoff, IDS)
    return fb.future_frame(cutoff, IDS, h, ref).set_index("ds")["promo_discount"]


def _past(fb: FeatureBuilder, cutoff: pd.Timestamp) -> pd.Series:
    tr, _ = fb.training_frame(cutoff, IDS)
    return tr.set_index("ds")["promo_discount"]


def _days(start: pd.Timestamp, end: pd.Timestamp) -> pd.DatetimeIndex:
    return pd.date_range(start, end, freq="D")


# ───────────────────────── caso de la auditoría ─────────────────────────

def test_audit_case_promotion_registered_after_cutoff_is_not_used():
    """Reproducción de `promotion_known_after_cutoff`: registrada el 4-abr, corte 1-abr."""
    late = _promo(created_at=CUTOFF + 3 * DAY)  # mismo dato que la sonda (solo created_at)
    assert (_future(_builder([late])) == 0.0).all()
    assert (_future(_builder([_promo(known_at=CUTOFF + 3 * DAY)])) == 0.0).all()
    # known_at manda sobre created_at (histórico importado con su fecha real de planificación).
    planned = _promo(known_at=CUTOFF - 5 * DAY, created_at=CUTOFF + 90 * DAY)
    fut = _future(_builder([planned]))
    assert (fut.loc[_days(CUTOFF + DAY, CUTOFF + 7 * DAY)] == 0.5).all()
    assert (fut.loc[CUTOFF + 8 * DAY:] == 0.0).all()


def test_promotion_counts_from_the_day_it_was_registered():
    fb = _builder([_promo(known_at=CUTOFF + 3 * DAY)])
    later = CUTOFF + 3 * DAY  # corte = día de registro (conocida al cierre de ese día)
    fut = _future(fb, later)
    assert (fut.loc[_days(later + DAY, CUTOFF + 7 * DAY)] == 0.5).all()


def test_past_dates_use_the_promotion_as_it_occurred():
    """Para fechas ≤ corte la promoción es un hecho observado aunque se registrara después."""
    fb = _builder([_promo(known_at=CUTOFF + 30 * DAY)])
    past = _past(fb, CUTOFF + 20 * DAY)
    assert (past.loc[_days(CUTOFF + DAY, CUTOFF + 7 * DAY)] == 0.5).all()
    assert past.loc[CUTOFF] == 0.0


def test_unknown_availability_is_not_used_in_the_future():
    assert (_future(_builder([_promo()])) == 0.0).all()


# ───────────────────────── versiones históricas ─────────────────────────

def test_modification_after_cutoff_does_not_change_that_cutoff():
    current = _promo(discount=0.4, ends=CUTOFF + 14 * DAY, known_at=CUTOFF - 12 * DAY)
    revisions = [
        _rev(1, 1, CUTOFF - 12 * DAY, discount=0.2, ends=CUTOFF + 7 * DAY),
        _rev(1, 2, CUTOFF + 4 * DAY, discount=0.4, ends=CUTOFF + 14 * DAY),
    ]
    fb = _builder([current], revisions)
    fut = _future(fb)
    assert (fut.loc[_days(CUTOFF + DAY, CUTOFF + 7 * DAY)] == 0.2).all()
    assert (fut.loc[CUTOFF + 8 * DAY:] == 0.0).all()
    # Igual que si la modificación no existiera.
    pdt.assert_series_equal(fut, _future(_builder([current], revisions[:1])))
    # Desde el día de la modificación rige la versión nueva.
    fut_late = _future(fb, CUTOFF + 4 * DAY)
    assert (fut_late.loc[_days(CUTOFF + 5 * DAY, CUTOFF + 14 * DAY)] == 0.4).all()
    # El pasado usa la última versión (lo que realmente ocurrió).
    assert (_past(fb, CUTOFF + 20 * DAY).loc[_days(CUTOFF + DAY, CUTOFF + 14 * DAY)] == 0.4).all()


def test_cancellation_after_cutoff_keeps_the_plan_known_at_the_cutoff():
    cancelled = _promo(discount=0.3, known_at=CUTOFF - 12 * DAY, deleted_at=CUTOFF + 2 * DAY)
    revisions = [_rev(1, 1, CUTOFF - 12 * DAY, discount=0.3), _rev(1, 2, CUTOFF + 2 * DAY, discount=0.3, deleted=True)]
    for fb in (_builder([cancelled], revisions), _builder([cancelled])):  # con y sin revisiones
        assert (_future(fb).loc[_days(CUTOFF + DAY, CUTOFF + 7 * DAY)] == 0.3).all()
        assert (_future(fb, CUTOFF + 2 * DAY) == 0.0).all()
        assert (_past(fb, CUTOFF + 20 * DAY) == 0.0).all()  # no ocurrió


def test_first_revision_is_valid_since_known_at_and_missing_revisions_fall_back():
    # Promoción 1: importada en octubre con su fecha real de planificación (marzo).
    # Promoción 2: sin revisiones → known_at y valores actuales.
    promos = [_promo(1, 0.25, known_at=CUTOFF - 12 * DAY),
              _promo(2, 0.1, starts=CUTOFF + 8 * DAY, ends=CUTOFF + 10 * DAY, known_at=CUTOFF - DAY)]
    revisions = [_rev(1, 1, pd.Timestamp("2026-10-20"), discount=0.25)]
    known = promotions_as_of(pd.DataFrame(promos), pd.DataFrame(revisions), CUTOFF)
    assert sorted(known["promotion_id"]) == [1, 2]
    fut = _future(_builder(promos, revisions))
    assert (fut.loc[_days(CUTOFF + DAY, CUTOFF + 7 * DAY)] == 0.25).all()
    assert (fut.loc[_days(CUTOFF + 8 * DAY, CUTOFF + 10 * DAY)] == 0.1).all()


def test_current_run_uses_promotions_known_on_run_day_only_for_final_cutoff():
    end = PANEL.df["ds"].max()  # corte final (último dato)
    promo = _promo(known_at=end + DAY, starts=end + 10 * DAY, ends=end + 16 * DAY)
    fb = _builder([promo], known_on=end + 2 * DAY)
    assert fb.knowledge_date(end) == end + 2 * DAY and fb.knowledge_date(CUTOFF) == CUTOFF
    fut = _future(fb, end, h=30)
    assert (fut.loc[_days(end + 10 * DAY, end + 16 * DAY)] == 0.5).all()
    # Ejecución histórica (sin día de ejecución): solo lo conocido al corte.
    assert (_future(_builder([promo]), end, h=30) == 0.0).all()


# ───────────────────────── backtest con datos sintéticos ─────────────────────────

def test_backtest_ignores_promotions_registered_or_modified_after_window_cutoff(small_data):
    panel = build_panel(small_data)
    cutoff = panel.cutoff - pd.Timedelta(days=30)
    base = small_data.promotions.copy()
    nid = int(base["promotion_id"].max()) + 1
    # Promoción a toda la empresa dentro de la ventana, registrada el día siguiente al corte.
    extra = {"promotion_id": nid, "discount_pct": 0.5, "starts_on": cutoff + 2 * DAY,
             "ends_on": cutoff + 10 * DAY, "branch_id": np.nan, "product_id": np.nan, "category_id": np.nan}
    late = pd.concat([base, pd.DataFrame([{**extra, "known_at": cutoff + DAY}])], ignore_index=True)
    early = pd.concat([base, pd.DataFrame([{**extra, "known_at": cutoff - DAY}])], ignore_index=True)
    # Promoción conocida antes del corte (10 %) cuyo descuento se cambió a 50 % después del corte.
    modified = early.copy()
    modified.loc[modified["promotion_id"] == nid, "discount_pct"] = 0.5
    revisions = pd.DataFrame([
        {**extra, "discount_pct": 0.1, "revision": 1, "recorded_at": cutoff - DAY, "deleted": False},
        {**extra, "discount_pct": 0.5, "revision": 2, "recorded_at": cutoff + 3 * DAY, "deleted": False},
    ])
    horizon_end = panel.cutoff.date()
    ids = panel.profile["unique_id"].tolist()
    fb_base = FeatureBuilder(panel.df, small_data.branches, base, horizon_end)
    fb_late = FeatureBuilder(panel.df, small_data.branches, late, horizon_end)
    fb_early = FeatureBuilder(panel.df, small_data.branches, early, horizon_end)
    fb_mod = FeatureBuilder(panel.df, small_data.branches, modified, horizon_end, promotion_revisions=revisions)
    _, ref = fb_base.training_frame(cutoff, ids)
    fut = {k: fb.future_frame(cutoff, ids, 14, ref) for k, fb in
           {"base": fb_base, "late": fb_late, "early": fb_early, "mod": fb_mod}.items()}
    pdt.assert_frame_equal(fut["base"], fut["late"])
    assert (fut["early"]["promo_discount"] == 0.5).sum() == 9 * len(ids)  # control: conocida → sí cuenta
    mod_days = fut["mod"][fut["mod"]["ds"].between(cutoff + 2 * DAY, cutoff + 10 * DAY)]
    assert (mod_days["promo_discount"] >= 0.1).all() and (mod_days["promo_discount"] < 0.5).all()

    plan = BacktestPlan(horizon=14, step=7, cutoffs=[cutoff])
    fams = ["seasonal_naive", "lightgbm_global"]
    bt_base = run_backtest(panel, fb_base, plan, fams, seed=42, n_jobs=2, use_lgbm=True)
    bt_late = run_backtest(panel, fb_late, plan, fams, seed=42, n_jobs=2, use_lgbm=True)
    assert "lightgbm_global" in set(bt_base["family"])
    key = ["unique_id", "ds", "family"]
    m = bt_base.merge(bt_late, on=key, suffixes=("_base", "_late"))
    assert len(m) == len(bt_base) > 0
    np.testing.assert_allclose(m["yhat_base"], m["yhat_late"], rtol=1e-9, atol=1e-9)


# ───────────────────────── datos de demostración ─────────────────────────

def test_synthetic_promotions_carry_plausible_known_at(small_company, small_data):
    promos = small_company.promotions
    known = pd.to_datetime(promos["known_at"])
    starts = pd.to_datetime(promos["starts_on"])
    end = pd.Timestamp(small_company.spec.end)
    lead = (starts - known).dt.days
    past = starts <= end - pd.Timedelta(days=21)
    assert lead[past].between(7, 21).all()
    assert (known <= end).all()
    # Las promociones futuras se registraron «hoy» (último día de datos) y entran en el pronóstico vigente.
    assert (known[starts > end] == end).all() and (starts > end).any()
    assert small_data.promotions["known_at"].notna().all()
    # Reproducible: misma semilla → mismas fechas.
    again = generate_company("ferreteria_andina", seed=42, n_products=12, start="2025-04-01")
    assert again.promotions["known_at"].tolist() == promos["known_at"].tolist()
