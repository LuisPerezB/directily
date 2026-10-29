"""Mismos casos que docs/validation/2026-10-28/probe_findings.py, ejecutados sobre el código corregido.

El script histórico AFIRMA los defectos (y por eso ahora se detiene en la primera aserción). Este
script reutiliza exactamente sus entradas y afirma el comportamiento CORRECTO, guardando el resultado
en `probe-results-after-fixes.json`. No conecta ninguna base de datos. F7 (programador y plan Básico)
es de Laravel: ver backend/tests/Feature/Analytics/PlanHorizonSchedulingTest.php.

Uso (desde la raíz):  engine/.venv/bin/python -B docs/validation/2026-10-29/probe_after_fixes.py
"""
import json
import sys
from dataclasses import replace
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "2026-10-28"))
from _validation_context import validation_context  # noqa: E402

SOURCE, OUTPUT = validation_context()
import numpy as np  # noqa: E402
import pandas as pd  # noqa: E402

from predictily_engine.backtest.rolling import eligible_ids, plan_backtest  # noqa: E402
from predictily_engine.features.builder import FeatureBuilder  # noqa: E402
from predictily_engine.inventory.recommendations import LeadTimeErrorModel, build_recommendations  # noqa: E402
from predictily_engine.pipeline.data import TenantData, tenant_data_from_synthetic  # noqa: E402
from predictily_engine.profiling.series import build_panel  # noqa: E402
from predictily_engine.synthetic import generate_company  # noqa: E402

cutoff = pd.Timestamp("2026-10-01")
profile = pd.DataFrame({"unique_id": ["b1-p1"], "demand_class": ["smooth"]})
empty_errors = LeadTimeErrorModel(pd.DataFrame(columns=[
    "unique_id", "window", "step", "actual", "yhat", "stockout", "scale"]), pd.Series(dtype=object))
KEYS = ["available", "in_transit", "lead_time_days", "review_period_days", "demand_lead_time", "demand_lead_time_std",
        "safety_stock", "suggested_qty", "stockout_probability", "stockout_date_estimate", "risk_level"]
ASSUMPTIONS = ["uncertainty", "safety_stock_policy", "risk_rule", "coverage_days_needed", "coverage_days_available",
               "coverage_insufficient", "suggested_qty_lower_bound", "inventory_position", "stockout_before_receipt",
               "limitations"]


def recommendation(days=7, on_hand=0, transit=0, lt=30, review=7, intervals=True, orders=None):
    fc = pd.DataFrame({"unique_id": "b1-p1", "branch_id": 1, "product_id": 1,
                       "step": range(1, days + 1), "ds": pd.date_range(cutoff + pd.Timedelta(days=1), periods=days),
                       "yhat": 10.0, "lo_80": 6.0 if intervals else np.nan, "hi_80": 14.0 if intervals else np.nan})
    inv = pd.DataFrame({"branch_id": [1], "product_id": [1], "ds": [cutoff], "on_hand": [on_hand],
                        "reserved": [0.0], "in_transit": [transit], "unit_cost": [2.0]})
    r = build_recommendations(fc, inv, pd.DataFrame(), pd.DataFrame(), orders, pd.Series(dtype=float), empty_errors,
                              profile, pd.Series({"b1-p1": 10.0}),
                              {"default_lead_time_days": lt, "review_period_days": review, "service_level": 0.95},
                              cutoff).iloc[0]
    out = {k: (None if r[k] is None or (isinstance(r[k], float) and np.isnan(r[k])) else r[k]) for k in KEYS}
    out["assumptions"] = {k: r["assumptions"].get(k) for k in ASSUMPTIONS}
    return out


def day_of(rec):
    d = rec["stockout_date_estimate"]
    return None if d is None else (pd.Timestamp(d) - cutoff).days


results = {}

# F2 · cobertura plazo + revisión: un pronóstico de 7 días ya no produce una compra "válida" de 84.
short, full = recommendation(days=7), recommendation(days=90)
assert short["suggested_qty"] is None and short["demand_lead_time"] is None
assert short["assumptions"]["coverage_insufficient"] is True and short["assumptions"]["coverage_days_needed"] == 37
assert full["demand_lead_time"] == 370
results["F2_truncated_supplier_period"] = {"forecast_7_days": short, "forecast_90_days": full}

# F3 · incertidumbre desconocida ≠ certeza.
unknown_low = recommendation(days=30, lt=7, review=7, on_hand=200, intervals=False)
unknown_high = recommendation(days=30, lt=7, review=7, on_hand=100, intervals=False)
for r in (unknown_low, unknown_high):
    assert r["stockout_probability"] is None and r["demand_lead_time_std"] is None
    assert r["assumptions"]["uncertainty"] == "unknown" and r["safety_stock"] > 0
results["F3_missing_uncertainty"] = {"ample_stock": unknown_low, "low_stock": unknown_high}

# F1 · una orden que llega el día 30 no oculta el quiebre del día 2.
orders = pd.DataFrame({"purchase_order_id": [1], "branch_id": [1], "product_id": [1], "supplier_id": [None],
                       "qty_open": [1000.0], "expected_at": [cutoff + pd.Timedelta(days=30)]})
late = recommendation(days=30, lt=7, review=7, on_hand=10, orders=orders)
without = recommendation(days=30, lt=7, review=7, on_hand=10)
assert late["available"] == 10 and day_of(late) is not None and day_of(late) <= 2
assert late["risk_level"] in ("high", "critical") and late["assumptions"]["stockout_before_receipt"] is True
results["F1_late_purchase_order"] = {"with_late_order": late, "without_order": without}

# F4 · la elegibilidad en un corte histórico no depende de ventas posteriores.
dates = pd.to_datetime(["2026-01-01", "2026-02-01", "2026-05-01", "2026-06-01", "2026-07-01"])
sales = pd.DataFrame({"branch_id": 1, "product_id": 1, "ds": dates, "units": 10.0, "net_amount": 20.0})
data = TenantData(tenant_id=1, settings={}, cutoff=pd.Timestamp("2026-07-31").date(),
                  branches=pd.DataFrame({"branch_id": [1], "region": ["costa"], "canton": ["guayaquil"]}),
                  products=pd.DataFrame({"product_id": [1], "category_id": [1], "is_active": [True]}),
                  categories=pd.DataFrame(), sales=sales)
historical = pd.Timestamp("2026-04-01")
panel_full = build_panel(data)
panel_past = build_panel(replace(data, cutoff=historical.date()))
full_ids, past_ids = eligible_ids(panel_full, historical), eligible_ids(panel_past, historical)
assert full_ids == past_ids == []
results["F4_profile_at_historical_cutoff"] = {"historical_cutoff": str(historical.date()),
                                               "eligible_using_full_panel": full_ids,
                                               "eligible_using_only_past": past_ids}

# F5 · una promoción registrada después del corte no se usa en el futuro de ese corte.
promos = pd.DataFrame({"promotion_id": [1], "discount_pct": [0.5],
                       "starts_on": [historical + pd.Timedelta(days=1)], "ends_on": [historical + pd.Timedelta(days=7)],
                       "branch_id": [1], "product_id": [1], "category_id": [1],
                       "created_at": [historical + pd.Timedelta(days=3)]})
fb = FeatureBuilder(panel_full.df, data.branches, promos, data.cutoff)
_, ref = fb.training_frame(historical, ["b1-p1"])
future = fb.future_frame(historical, ["b1-p1"], 7, ref)
assert (future["promo_discount"] == 0.0).all()
known_before = promos.assign(created_at=[historical - pd.Timedelta(days=2)])
fb2 = FeatureBuilder(panel_full.df, data.branches, known_before, data.cutoff)
_, ref2 = fb2.training_frame(historical, ["b1-p1"])
assert (fb2.future_frame(historical, ["b1-p1"], 7, ref2)["promo_discount"] == 0.5).all()
results["F5_promotion_known_after_cutoff"] = {
    "forecast_cutoff": str(historical.date()), "promotion_created_at": str(promos["created_at"].iat[0].date()),
    "future_discounts_used": future["promo_discount"].tolist(),
    "if_known_before_cutoff": [0.5] * 7}

# F6 · demo Ferretería Andina: el período final reservado no comparte fechas con la selección.
demo = tenant_data_from_synthetic(generate_company("ferreteria_andina", seed=42))
plan = plan_backtest(build_panel(demo))
assert plan is not None and plan.has_holdout
holdout = set(pd.date_range(plan.holdout_cutoff + pd.Timedelta(days=1), periods=plan.horizon))
selection = set().union(*[set(pd.date_range(c + pd.Timedelta(days=1), periods=plan.horizon)) for c in plan.cutoffs])
assert not (holdout & selection)
results["F6_holdout_independent_of_selection"] = {
    "horizon": plan.horizon, "step": plan.step, "selection_cutoffs": [str(c.date()) for c in plan.cutoffs],
    "holdout_cutoff": str(plan.holdout_cutoff.date()), "shared_target_dates_with_holdout": len(holdout & selection)}

out = OUTPUT / "probe-results-after-fixes.json"
out.write_text(json.dumps(results, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
print(json.dumps(results, ensure_ascii=False, indent=2, default=str))
print(f"\nCasos verificados con el comportamiento correcto: {len(results)} (F1–F6). Resultado: {out}")
