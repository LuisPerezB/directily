"""Conjunto de datos en memoria de una empresa (entrada pura del pipeline).

El pipeline no accede a la base de datos: recibe un `TenantData`. Así se puede probar
con datos sintéticos y garantizar que una ejecución solo ve los datos de una empresa.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.calendar.regions import region_for_province
from predictily_engine.db.repository import TenantRepository

DEFAULT_SETTINGS = {
    "default_horizon_days": 30,
    "service_level": 0.95,
    "review_period_days": 7,
    "default_lead_time_days": 7,
    "stockout_risk_threshold": 0.3,
}


@dataclass
class TenantData:
    tenant_id: int
    settings: dict[str, Any]
    cutoff: date
    branches: pd.DataFrame  # branch_id, code, name, province, canton, region
    products: pd.DataFrame  # product_id, sku, name, category_id, unit, current_price, current_cost, is_active
    categories: pd.DataFrame  # category_id, name
    sales: pd.DataFrame  # branch_id, product_id, ds, units, net_amount, gross_amount, discount_amount, cost_amount, cost_units
    returns: pd.DataFrame = field(default_factory=pd.DataFrame)
    inventory: pd.DataFrame = field(default_factory=pd.DataFrame)  # branch_id, product_id, ds, on_hand, reserved, in_transit, unit_cost
    latest_inventory: pd.DataFrame = field(default_factory=pd.DataFrame)
    promotions: pd.DataFrame = field(default_factory=pd.DataFrame)
    lead_times: pd.DataFrame = field(default_factory=pd.DataFrame)
    suppliers: pd.DataFrame = field(default_factory=pd.DataFrame)
    open_purchase_orders: pd.DataFrame = field(default_factory=pd.DataFrame)
    sales_stats: dict[str, Any] = field(default_factory=dict)

    @property
    def effective_settings(self) -> dict[str, Any]:
        return {**DEFAULT_SETTINGS, **(self.settings or {})}


def _normalize(data: TenantData) -> TenantData:
    data.products = data.products.copy()
    data.branches = data.branches.copy()
    for name in ("sales", "returns", "inventory", "latest_inventory"):
        df = getattr(data, name)
        if not df.empty and "ds" in df:
            df["ds"] = pd.to_datetime(df["ds"])
    if not data.branches.empty:
        data.branches["region"] = data.branches["province"].map(region_for_province)
    for col in ("current_price", "current_cost"):
        if col in data.products:
            data.products[col] = pd.to_numeric(data.products[col], errors="coerce")
    for df_name in ("promotions",):
        df = getattr(data, df_name)
        if not df.empty:
            df["starts_on"] = pd.to_datetime(df["starts_on"])
            df["ends_on"] = pd.to_datetime(df["ends_on"])
    return data


def load_tenant_data(repo: TenantRepository, cutoff: date | None = None) -> TenantData:
    """Carga todo lo necesario para pronosticar con datos hasta `cutoff` (inclusive)."""
    tenant = repo.tenant()
    last = repo.last_sale_date()
    if cutoff is None:
        cutoff = last
    if cutoff is None:
        raise ValueError("La empresa no tiene ventas válidas")
    sales = repo.daily_sales(cutoff)
    first = pd.to_datetime(sales["ds"]).min().date() if not sales.empty else cutoff
    data = TenantData(
        tenant_id=repo.tenant_id,
        settings=tenant["settings"],
        cutoff=cutoff,
        branches=repo.branches(),
        products=repo.products(),
        categories=repo.categories(),
        sales=sales,
        returns=repo.daily_returns(cutoff),
        inventory=repo.inventory(first - timedelta(days=1), cutoff),
        latest_inventory=repo.latest_inventory(cutoff),
        promotions=repo.promotions(),
        lead_times=repo.lead_times(),
        suppliers=repo.suppliers(),
        open_purchase_orders=repo.open_purchase_orders(),
        sales_stats=repo.sales_stats(),
    )
    return _normalize(data)


def tenant_data_from_synthetic(company, tenant_id: int = 1, cutoff: date | None = None) -> TenantData:
    """Construye un TenantData desde un SyntheticCompany (pruebas sin base de datos).

    Aplica las mismas reglas que el repositorio: excluye anuladas y notas de crédito.
    """
    branches = company.branches.reset_index(drop=True).copy()
    branches.insert(0, "branch_id", np.arange(1, len(branches) + 1))
    b_map = dict(zip(branches["code"], branches["branch_id"]))
    cats = sorted(company.products["category"].unique())
    categories = pd.DataFrame({"category_id": np.arange(1, len(cats) + 1), "name": cats})
    c_map = dict(zip(categories["name"], categories["category_id"]))
    products = company.products.reset_index(drop=True).copy()
    products.insert(0, "product_id", np.arange(1, len(products) + 1))
    products["category_id"] = products["category"].map(c_map)
    products["is_active"] = products["is_active"].astype(str).str.lower() == "true"
    p_map = dict(zip(products["sku"], products["product_id"]))

    s = company.sales.copy()
    s["ds"] = pd.to_datetime(s["issued_at"].str[:10])
    s["quantity"] = pd.to_numeric(s["quantity"])
    for col in ("unit_price", "discount", "net_amount", "unit_cost"):
        s[col] = pd.to_numeric(s[col])
    s["branch_id"] = s["branch_code"].map(b_map)
    s["product_id"] = s["product_sku"].map(p_map)
    cutoff = cutoff or s["ds"].max().date()
    s = s[s["ds"] <= pd.Timestamp(cutoff)]
    valid = s[(s["status"].fillna("") != "anulada") & (s["document_type"] == "factura")]
    valid = valid.assign(gross=valid["quantity"] * valid["unit_price"], cost=valid["quantity"] * valid["unit_cost"])
    sales = (valid.groupby(["branch_id", "product_id", "ds"], as_index=False)
             .agg(units=("quantity", "sum"), net_amount=("net_amount", "sum"), gross_amount=("gross", "sum"),
                  discount_amount=("discount", "sum"), cost_amount=("cost", "sum"), cost_units=("quantity", "sum"),
                  lines=("quantity", "size")))
    rets = s[s["document_type"] != "factura"]
    returns = rets.groupby(["branch_id", "product_id", "ds"], as_index=False).agg(units=("quantity", "sum"))

    inv = company.inventory.copy()
    inv["ds"] = pd.to_datetime(inv["snapshot_date"])
    inv["branch_id"] = inv["branch_code"].map(b_map)
    inv["product_id"] = inv["product_sku"].map(p_map)
    inv = inv[inv["ds"] <= pd.Timestamp(cutoff)][["branch_id", "product_id", "ds", "on_hand", "reserved",
                                                    "in_transit", "unit_cost"]]
    latest = inv.sort_values("ds").groupby(["branch_id", "product_id"], as_index=False).tail(1)

    promos = company.promotions.copy()
    promos.insert(0, "promotion_id", np.arange(1, len(promos) + 1))
    promos["branch_id"] = promos["branch_code"].map(b_map)
    promos["product_id"] = promos["product_sku"].map(p_map)
    promos["category_id"] = promos["category"].map(c_map)
    promos = promos[["promotion_id", "discount_pct", "starts_on", "ends_on", "branch_id", "product_id", "category_id"]]

    sup = company.suppliers.copy()
    sup.insert(0, "supplier_id", np.arange(1, len(sup) + 1))
    s_map = dict(zip(sup["code"], sup["supplier_id"]))
    lt = company.lead_times.copy()
    lt["supplier_id"] = lt["supplier_code"].map(s_map)
    lt["product_id"] = lt["product_sku"].map(p_map)
    lt["branch_id"] = lt["branch_code"].replace("", np.nan).map(b_map)
    lt["valid_from"] = None
    lt = lt[["supplier_id", "product_id", "branch_id", "lead_time_days", "lead_time_std_days", "valid_from"]]

    data = TenantData(
        tenant_id=tenant_id,
        settings=dict(DEFAULT_SETTINGS),
        cutoff=cutoff,
        branches=branches[["branch_id", "code", "name", "province", "canton"]],
        products=products[["product_id", "sku", "name", "category_id", "unit", "current_price", "current_cost",
                           "is_active"]],
        categories=categories,
        sales=sales,
        returns=returns,
        inventory=inv.reset_index(drop=True),
        latest_inventory=latest.reset_index(drop=True),
        promotions=promos,
        lead_times=lt,
        suppliers=sup[["supplier_id", "code", "default_lead_time_days"]],
        open_purchase_orders=pd.DataFrame(columns=["branch_id", "product_id", "supplier_id", "qty_open", "expected_at"]),
        sales_stats={"lines": int(len(valid)), "lines_with_cost": int(valid["unit_cost"].notna().sum()),
                     "date_from": valid["ds"].min().date(), "date_to": valid["ds"].max().date()},
    )
    return _normalize(data)
