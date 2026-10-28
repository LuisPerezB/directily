"""Reproducciones aisladas; usa el código original y no conecta ninguna base de datos."""
import sys
import json
from pathlib import Path
from dataclasses import replace

from _validation_context import validation_context

SOURCE, OUTPUT = validation_context()
import numpy as np
import pandas as pd
from predictily_engine.inventory.recommendations import LeadTimeErrorModel, build_recommendations
from predictily_engine.pipeline.data import TenantData
from predictily_engine.profiling.series import build_panel
from predictily_engine.backtest.rolling import eligible_ids, plan_backtest
from predictily_engine.features.builder import FeatureBuilder

cutoff = pd.Timestamp('2026-10-01')
profile = pd.DataFrame({'unique_id':['b1-p1'], 'demand_class':['smooth']})
empty_errors = LeadTimeErrorModel(pd.DataFrame(columns=[
    'unique_id','window','step','actual','yhat','stockout','scale']), pd.Series(dtype=object))

def recommendation(days=7, on_hand=0, transit=0, lt=30, review=7, intervals=True, orders=None):
    fc = pd.DataFrame({'unique_id':'b1-p1', 'branch_id':1, 'product_id':1,
        'step':range(1,days+1), 'ds':pd.date_range(cutoff+pd.Timedelta(days=1),periods=days),
        'yhat':10.0, 'lo_80':6.0 if intervals else np.nan, 'hi_80':14.0 if intervals else np.nan})
    inv = pd.DataFrame({'branch_id':[1], 'product_id':[1], 'ds':[cutoff],
        'on_hand':[on_hand], 'reserved':[0.0], 'in_transit':[transit], 'unit_cost':[2.0]})
    r = build_recommendations(fc,inv,pd.DataFrame(),pd.DataFrame(),orders,pd.Series(dtype=float),
        empty_errors,profile,pd.Series({'b1-p1':10.0}),
        {'default_lead_time_days':lt,'review_period_days':review,'service_level':0.95},cutoff).iloc[0]
    return {k:r[k] for k in ['available','lead_time_days','review_period_days','demand_lead_time',
        'safety_stock','suggested_qty','stockout_probability','stockout_date_estimate','risk_level','assumptions']}

results = {}
short = recommendation(days=7)
full = recommendation(days=90)
assert short['demand_lead_time'] == 70 and full['demand_lead_time'] == 370
results['truncated_supplier_period'] = {'forecast_7_days':short,'forecast_90_days':full,
    'expected_mean_demand_for_37_days':370}

unknown_low = recommendation(days=30,lt=7,review=7,on_hand=200,intervals=False)
unknown_high = recommendation(days=30,lt=7,review=7,on_hand=100,intervals=False)
assert unknown_low['stockout_probability']==0 and unknown_high['stockout_probability']==1
assert unknown_low['safety_stock']==0
results['missing_uncertainty'] = {'ample_stock':unknown_low,'low_stock':unknown_high}

orders = pd.DataFrame({'branch_id':[1], 'product_id':[1], 'qty_open':[1000.0],
    'expected_at':[cutoff+pd.Timedelta(days=30)]})
late = recommendation(days=30,lt=7,review=7,on_hand=10,orders=orders)
without = recommendation(days=30,lt=7,review=7,on_hand=10)
assert late['available']==1010 and late['stockout_date_estimate'] is None
results['late_purchase_order'] = {'arrival_day':30,'stock_units_now':10,'daily_demand':10,
    'with_late_order':late,'without_order':without}

dates = pd.to_datetime(['2026-01-01','2026-02-01','2026-05-01','2026-06-01','2026-07-01'])
sales = pd.DataFrame({'branch_id':1,'product_id':1,'ds':dates,'units':10.0,'net_amount':20.0})
data = TenantData(tenant_id=1,settings={},cutoff=pd.Timestamp('2026-07-31').date(),
    branches=pd.DataFrame({'branch_id':[1],'region':['costa'],'canton':['guayaquil']}),
    products=pd.DataFrame({'product_id':[1],'category_id':[1],'is_active':[True]}),
    categories=pd.DataFrame(),sales=sales)
historical = pd.Timestamp('2026-04-01')
panel_full = build_panel(data)
panel_past = build_panel(replace(data,cutoff=historical.date()))
full_ids = eligible_ids(panel_full,historical)
past_ids = eligible_ids(panel_past,historical)
assert full_ids==['b1-p1'] and past_ids==[]
results['future_profile_leaks_into_backtest'] = {
    'historical_cutoff':str(historical.date()),'past_nonzero_days':2,
    'eligible_using_full_profile':full_ids,'eligible_using_only_past':past_ids,
    'full_class':panel_full.profile.iloc[0]['demand_class'],
    'past_class':panel_past.profile.iloc[0]['demand_class']}

promos = pd.DataFrame({'promotion_id':[1],'discount_pct':[0.5],
    'starts_on':[historical+pd.Timedelta(days=1)],'ends_on':[historical+pd.Timedelta(days=7)],
    'branch_id':[1],'product_id':[1],'category_id':[1],
    'created_at':[historical+pd.Timedelta(days=3)]})
fb = FeatureBuilder(panel_full.df,data.branches,promos,data.cutoff)
_,ref = fb.training_frame(historical,['b1-p1'])
future = fb.future_frame(historical,['b1-p1'],7,ref)
assert (future['promo_discount']==0.5).all()
results['promotion_known_after_cutoff'] = {'forecast_cutoff':str(historical.date()),
    'promotion_created_at':str(promos['created_at'].iat[0].date()),
    'future_discounts_used':future['promo_discount'].tolist()}

plan = plan_backtest(panel_full)
if plan:
    targets = [set(pd.date_range(c+pd.Timedelta(days=1),periods=plan.horizon)) for c in plan.cutoffs]
    results['overlap_between_backtest_windows'] = {'horizon':plan.horizon,'step':plan.step,
        'same_target_dates_first_two_windows':len(targets[0]&targets[1])}

out = OUTPUT / 'probe-results.json'
out.write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf-8')
print(json.dumps(results,ensure_ascii=False,indent=2,default=str))
