"""Paquete de datos de una empresa para el Customer Core.

`CustomerData` es independiente de la base de datos: las pruebas lo construyen en memoria (generador
sintético) y el servicio lo carga con `load_customer_data`. Contiene SOLO compras de compradores
identificables y no genéricos (anónimos y genéricos quedan fuera y se contabilizan aparte).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from typing import Any

import pandas as pd

from predictily_engine.db.customer_repository import CustomerRepository

PURCHASE_COLUMNS = ["customer_id", "product_id", "branch_id", "ds", "currency", "qty", "net_amount",
                    "gross_amount", "discount_amount"]


@dataclass
class CustomerData:
    purchases: pd.DataFrame
    returns: pd.DataFrame
    products: pd.DataFrame            # product_id, category_id, unit, is_active
    branches: pd.DataFrame            # branch_id, province, canton
    availability: pd.DataFrame        # branch_id, product_id, ds, available (bool): días con inventario conocido
    coverage: pd.DataFrame            # entity, branch_id, complete_from, complete_through (entity='sales')
    promotions: pd.DataFrame
    promotion_revisions: pd.DataFrame
    sales_first: date | None          # primera/última venta válida de la empresa (cualquier comprador)
    sales_last: date | None
    excluded: dict[str, Any] = field(default_factory=dict)
    tenant: dict[str, Any] = field(default_factory=dict)  # currency, timezone, sector
    through: date | None = None       # los datos se cargaron hasta esta fecha (incluida)

    def __post_init__(self) -> None:
        for col in ("ds",):
            if len(self.purchases):
                self.purchases[col] = pd.to_datetime(self.purchases[col])
            if len(self.returns):
                self.returns[col] = pd.to_datetime(self.returns[col])

    # ── cobertura y madurez ──
    def last_complete_day(self) -> date | None:
        complete = last_complete_day(self.coverage, self.sales_last)
        if complete is not None and self.through is not None and self.through < complete:
            return self.through
        return complete

    def coverage_source(self) -> str:
        cov = self.coverage
        if cov is not None and len(cov) and cov["complete_through"].notna().any():
            return "data_coverage"
        return "last_sale_date"

    def complete_from(self) -> date | None:
        cov = self.coverage
        if cov is not None and len(cov) and cov["complete_from"].notna().any():
            return pd.Timestamp(cov["complete_from"].max()).date()
        return None


def last_complete_day(coverage: pd.DataFrame | None, sales_last: date | None) -> date | None:
    """Último día completo de ventas: `data_coverage` (el más conservador) o, sin ella, la última venta."""
    if coverage is not None and len(coverage) and coverage["complete_through"].notna().any():
        tenant_level = coverage[coverage["branch_id"].isna() & coverage["complete_through"].notna()]
        rows = tenant_level if len(tenant_level) else coverage[coverage["complete_through"].notna()]
        return pd.Timestamp(rows["complete_through"].min()).date()
    return sales_last


def load_customer_data(repo: CustomerRepository, cfg: dict[str, Any], through: date | None = None,
                       cap_to_complete: bool = False) -> CustomerData:
    """Carga los datos de UNA empresa hasta `through` (incluido). Nada posterior entra al paquete.

    `cap_to_complete`: limita `through` al último día completo (entrenamiento: sin datos parciales).
    """
    first, last = repo.sales_range()
    coverage = repo.coverage("sales")
    if through is None or (last is not None and through > last):
        through = last
    if cap_to_complete:
        complete = last_complete_day(coverage, last)
        if complete is not None and through is not None and complete < through:
            through = complete
    purchases = repo.purchases(through, bool(cfg["exclude_generic_buyers"]), list(cfg["generic_buyer_codes"])) \
        if through is not None else pd.DataFrame(columns=PURCHASE_COLUMNS)
    returns = repo.returns(through) if through is not None else pd.DataFrame(columns=["customer_id", "product_id", "ds", "qty"])
    pids = sorted(int(p) for p in purchases["product_id"].unique()) if len(purchases) else []
    availability = repo.availability(pids, first, through) if (pids and first is not None) else pd.DataFrame(
        columns=["branch_id", "product_id", "ds", "available"])
    excluded = repo.excluded_sales_stats(through, list(cfg["generic_buyer_codes"])) if through is not None else {}
    return CustomerData(
        purchases=purchases, returns=returns, products=repo.products(), branches=repo.branches(),
        availability=availability, coverage=coverage, promotions=repo.promotions(),
        promotion_revisions=repo.promotion_revisions(), sales_first=first, sales_last=last,
        excluded=excluded, tenant=repo.tenant(), through=through,
    )
