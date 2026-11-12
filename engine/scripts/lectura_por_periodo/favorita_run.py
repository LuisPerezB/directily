"""Pasa por el motor de Predictily un subconjunto real de Corporación Favorita (4 tiendas, ~160 productos estratificados por volumen,
2016-06-01 → 2017-08-15) y guarda el backtest. Sin precios (no los trae la base): precio 1. Sin inventario.

Uso: python favorita_run.py <subset.parquet> <dir_datos> <salida.pkl> [variante] [promociones] [semilla de quiebres simulados]
  variante:     todo (los cuatro interruptores de precisión, por defecto) | base (configuración por defecto del motor)
                con el sufijo +quiebres enciende además la inferencia de quiebres de stock (`stockout_inference`)
  promociones:  none (por defecto) | known (el negocio conoce cada promoción con un año de anticipación: techo del beneficio)
                | <n> (la conoce n días antes de que empiece, p. ej. 14)
  semilla:      si se da, se SIMULAN quiebres de stock conocidos borrando ventas reales antes del período final (ver `inject_stockouts`);
                sirve para medir, con verdad conocida, cuánto daña un quiebre al pronóstico y cuánto recupera la inferencia
La base solo marca si el producto estuvo en promoción ese día (sin porcentaje): cada racha de días seguidos en promoción de un producto
en una tienda se entrega como una promoción con descuento nominal de 10 %."""

import sys
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")
from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import DEFAULT_SETTINGS, TenantData, _normalize
from predictily_engine.selection.selector import selected_rows

sub, src, out = sys.argv[1:4]
variant = sys.argv[4] if len(sys.argv) > 4 else "todo"
promo_mode = sys.argv[5] if len(sys.argv) > 5 else "none"
inject_seed = int(sys.argv[6]) if len(sys.argv) > 6 else None
TODO = {
    "promotion": {"legacy_selection_metric": "total_day"},
    "demand": {"weekday_profile_models": True, "holiday_adjustment_models": True, "ensemble_models": True},
}
CORES = TODO if variant.startswith("todo") else {}
if variant.endswith("+quiebres"):
    CORES = {**CORES, "demand": {**CORES.get("demand", {}), "stockout_inference": True}}
PROMO_COLUMNS = ["promotion_id", "discount_pct", "starts_on", "ends_on", "branch_id", "product_id", "category_id", "known_at"]


def promotion_runs(flags: pd.DataFrame, mode: str) -> pd.DataFrame:
    """Rachas de días seguidos en promoción por tienda y producto → filas de promoción con la fecha desde la que se conocen."""
    if mode == "none" or flags.empty:
        return pd.DataFrame(columns=PROMO_COLUMNS)
    f = flags.sort_values(["branch_id", "product_id", "ds"]).copy()
    gap = f.groupby(["branch_id", "product_id"])["ds"].diff().dt.days.ne(1)
    f["run"] = gap.cumsum()
    runs = f.groupby(["branch_id", "product_id", "run"], as_index=False).agg(starts_on=("ds", "min"), ends_on=("ds", "max"))
    lead = 365 if mode == "known" else int(mode)
    runs["known_at"] = runs["starts_on"] - pd.Timedelta(days=lead)
    runs["discount_pct"] = 10.0
    runs["category_id"] = np.nan
    runs.insert(0, "promotion_id", np.arange(1, len(runs) + 1))
    return runs[PROMO_COLUMNS]


def inject_stockouts(sales: pd.DataFrame, origin: pd.Timestamp, seed: int) -> pd.DataFrame:
    """Celdas (sucursal, producto, día) de quiebres simulados: en 40 % de las series de venta regular (≥ 150 días con venta en los 240
    previos al origen del período final), dos rachas de 4 a 21 días en esos 240 días; una de cada cuatro tiene además una racha que
    termina justo en el origen (el producto sigue sin stock cuando se pronostica)."""
    rng = np.random.default_rng(seed)
    recent = sales[(sales["ds"] > origin - pd.Timedelta(days=240)) & (sales["ds"] <= origin)]
    n = recent.groupby(["branch_id", "product_id"]).size()
    regular = n[n >= 150].index.to_list()
    chosen = [regular[i] for i in rng.choice(len(regular), int(0.4 * len(regular)), replace=False)]
    cells = []
    for b, p in chosen:
        spans = [(int(rng.integers(30, 240)), int(rng.integers(4, 22))) for _ in range(2)]
        if rng.random() < 0.25:
            spans.append((0, int(rng.integers(4, 22))))
        for back, length in spans:
            end = origin - pd.Timedelta(days=back)
            cells += [(b, p, end - pd.Timedelta(days=k)) for k in range(length)]
    return pd.DataFrame(cells, columns=["branch_id", "product_id", "ds"]).drop_duplicates()


d = pd.read_parquet(sub)
d["ds"] = pd.to_datetime(d["date"])
days = d["ds"].nunique()
# productos presentes al inicio y al final del período, estratificados por volumen total
# la muestra se elige siempre con el período 2016-06-01 → fin, aunque el archivo traiga más historia: así es la misma en toda corrida
recent = d[d["ds"] >= "2016-06-01"]
span = recent.groupby("item_nbr")["ds"].agg(["min", "max"])
alive = span[(span["min"] <= "2016-07-01") & (span["max"] >= "2017-07-15")].index
vol = recent[recent["item_nbr"].isin(alive)].groupby("item_nbr")["unit_sales"].sum().sort_values()
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
injected = pd.DataFrame(columns=["branch_id", "product_id", "ds"])
if inject_seed is not None:
    injected = inject_stockouts(sales, d["ds"].max() - pd.Timedelta(days=90), inject_seed)
    gone = sales.merge(injected.assign(_x=1), on=["branch_id", "product_id", "ds"], how="left")["_x"].notna().to_numpy()
    print("quiebres simulados:", len(injected), "días-serie en", injected.groupby(["branch_id", "product_id"]).ngroups, "series;",
          f"unidades borradas {sales.loc[gone, 'units'].sum():,.0f} de {sales['units'].sum():,.0f}", flush=True)  # fmt: skip
    sales = sales[~gone].reset_index(drop=True)
sales["net_amount"] = sales["units"]
sales["gross_amount"] = sales["units"]
sales["discount_amount"] = 0.0
sales["cost_amount"] = np.nan
sales["cost_units"] = 0.0
neg = d[d["unit_sales"] < 0]
returns = neg.groupby(["branch_id", "product_id", "ds"], as_index=False).agg(units=("unit_sales", lambda s: float(-s.sum())))
cutoff = d["ds"].max().date()
promo_flags = d.loc[d["onpromotion"] == "True", ["branch_id", "product_id", "ds"]].drop_duplicates()
promotions = promotion_runs(promo_flags, promo_mode)
print("variante", variant, "| promociones", promo_mode, "| rachas", len(promotions), "| días en promoción", len(promo_flags), flush=True)
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
        promotions=promotions,
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
served_all = selected_rows(bt[bt["is_holdout"]], res.panel.profile, res.evaluation_selection or res.selection)  # con los días no evaluables
payload = {"bt": bt, "panel": res.panel.df, "profile": res.panel.profile, "served": sel, "served_all": served_all, "summary": res.summary, "promo": promo, "injected": injected}
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
