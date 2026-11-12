"""Pasa por el motor de Predictily un subconjunto real de Corporación Favorita (4 tiendas, ~160 productos estratificados por volumen,
2016-06-01 → 2017-08-15) y guarda el backtest. Sin precios (no los trae la base): precio 1. Sin inventario ni promociones.
Uso: python fav_run.py <subset.parquet> <dir_datos> <salida.pkl>"""

import sys
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import DEFAULT_SETTINGS, TenantData, _normalize
from predictily_engine.selection.selector import selected_rows

sub, src, out = sys.argv[1:4]
CORES = {
    "promotion": {"legacy_selection_metric": "total_day"},
    "demand": {"weekday_profile_models": True, "holiday_adjustment_models": True, "ensemble_models": True},
}
d = pd.read_parquet(sub)
d["ds"] = pd.to_datetime(d["date"])
days = d["ds"].nunique()
# productos presentes al inicio y al final del período, estratificados por volumen total
span = d.groupby("item_nbr")["ds"].agg(["min", "max"])
alive = span[(span["min"] <= "2016-07-01") & (span["max"] >= "2017-07-15")].index
vol = d[d["item_nbr"].isin(alive)].groupby("item_nbr")["unit_sales"].sum().sort_values()
rng = np.random.default_rng(42)
top = list(vol.index[-20:])
rest = vol.iloc[:-20]
bins = pd.qcut(rest.rank(method="first"), 10, labels=False)
pick = top + [i for b in range(10) for i in rng.choice(rest.index[bins == b], 14, replace=False)]
d = d[d["item_nbr"].isin(pick)]
items = pd.read_csv(f"{src}/items.csv")
items = items[items["item_nbr"].isin(pick)].reset_index(drop=True)
stores = pd.read_csv(f"{src}/stores.csv")
stores = stores[stores["store_nbr"].isin(d["store_nbr"].unique())].reset_index(drop=True)

branches = pd.DataFrame(
    {
        "branch_id": np.arange(1, len(stores) + 1),
        "code": "T" + stores["store_nbr"].astype(str),
        "name": "Tienda " + stores["store_nbr"].astype(str),
        "province": stores["state"],
        "canton": stores["city"],
    }
)
b_map = dict(zip(stores["store_nbr"], branches["branch_id"]))
fams = sorted(items["family"].unique())
categories = pd.DataFrame({"category_id": np.arange(1, len(fams) + 1), "name": fams})
c_map = dict(zip(categories["name"], categories["category_id"]))
products = pd.DataFrame(
    {
        "product_id": np.arange(1, len(items) + 1),
        "sku": items["item_nbr"].astype(str),
        "name": "Producto " + items["item_nbr"].astype(str),
        "category_id": items["family"].map(c_map),
        "unit": "unidad",
        "current_price": 1.0,
        "current_cost": np.nan,
        "is_active": True,
    }
)
p_map = dict(zip(items["item_nbr"], products["product_id"]))
d["branch_id"] = d["store_nbr"].map(b_map)
d["product_id"] = d["item_nbr"].map(p_map)
pos = d[d["unit_sales"] > 0]
sales = pos.groupby(["branch_id", "product_id", "ds"], as_index=False).agg(units=("unit_sales", "sum"), lines=("unit_sales", "size"))
sales["net_amount"] = sales["units"]
sales["gross_amount"] = sales["units"]
sales["discount_amount"] = 0.0
sales["cost_amount"] = np.nan
sales["cost_units"] = 0.0
neg = d[d["unit_sales"] < 0]
returns = neg.groupby(["branch_id", "product_id", "ds"], as_index=False).agg(units=("unit_sales", lambda s: float(-s.sum())))
cutoff = d["ds"].max().date()
data = _normalize(
    TenantData(
        tenant_id=1,
        settings={**DEFAULT_SETTINGS, "cores": CORES},
        cutoff=cutoff,
        branches=branches,
        products=products,
        categories=categories,
        sales=sales,
        returns=returns,
        inventory=pd.DataFrame(columns=["branch_id", "product_id", "ds", "on_hand", "reserved", "in_transit", "unit_cost"]),
        latest_inventory=pd.DataFrame(columns=["branch_id", "product_id", "ds", "on_hand", "reserved", "in_transit", "unit_cost"]),
        promotions=pd.DataFrame(
            columns=["promotion_id", "discount_pct", "starts_on", "ends_on", "branch_id", "product_id", "category_id", "known_at"]
        ),
        lead_times=pd.DataFrame(columns=["supplier_id", "product_id", "branch_id", "lead_time_days", "lead_time_std_days", "valid_from"]),
        suppliers=pd.DataFrame(columns=["supplier_id", "code", "default_lead_time_days"]),
        open_purchase_orders=pd.DataFrame(columns=["branch_id", "product_id", "supplier_id", "qty_open", "expected_at"]),
        sales_stats={
            "lines": len(pos),
            "lines_with_cost": 0,
            "date_from": sales["ds"].min().date(),
            "date_to": sales["ds"].max().date(),
        },
    )
)
print("series candidatas", sales.groupby(["branch_id", "product_id"]).ngroups, "productos", len(products), "días", days, flush=True)
res = run_pipeline(data, horizon=90, seed=42, n_jobs=4)
bt = res.backtest
hold = bt[bt["is_holdout"] & ~bt["stockout"].astype(bool)]
sel = selected_rows(hold, res.panel.profile, res.evaluation_selection or res.selection)
promo = d[d["onpromotion"] == "True"].groupby(["branch_id", "product_id", "ds"]).size().rename("promo").reset_index()
payload = {"bt": bt, "panel": res.panel.df, "profile": res.panel.profile, "served": sel, "summary": res.summary, "promo": promo}
pd.to_pickle(payload, out)
m = res.summary["metrics"]
print("bt", bt.shape, "familias", sorted(bt["family"].unique()), "ventanas", bt["window"].nunique())
print(
    "resumen: serie-día",
    round(m["selected_wape"], 4),
    "total-día",
    round(m["total_selected_wape"], 4),
    "cobertura 80",
    round(m["coverage_80"], 3),
)
print("modelos", res.summary["models"])
print("limitaciones", [l["code"] for l in res.limitations])
print("avisos", len(res.warnings))
print(res.panel.profile["demand_class"].value_counts().to_dict())
