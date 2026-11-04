"""Paquete de datos de una empresa para el Customer Core.

`CustomerData` es independiente de la base de datos: las pruebas lo construyen en memoria (generador
sintético) y el servicio lo carga con `load_customer_data`. Contiene SOLO compras de compradores
identificables y no genéricos (anónimos y genéricos quedan fuera y se contabilizan aparte).

Conocimiento as-of (ADR-V25-23): `purchases` y `returns` son la vista al corte final (`through`: la verdad disponible, con que se
etiquetan los cortes). `purchase_versions` conserva las versiones de las líneas con su ventana de conocimiento, leídas una vez, para
que el `FeatureBuilder` arme las VARIABLES de cada corte con lo que se conocía ese día (un documento cargado después o una
anulación posterior no cambian las variables de un corte anterior). Sin versiones (datos sintéticos) todo se asume conocido
desde su día de negocio.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from typing import Any

import pandas as pd

from predictily_engine.data.asof import local_today, with_knowledge
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
    purchase_versions: Any = None     # data.asof.PurchaseVersions: versiones de compras y devoluciones con su ventana de conocimiento
    live: bool = False                # ejecución vigente: la verdad conoce hasta HOY (ver `load_customer_data`)

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
                       cap_to_complete: bool = False, live: bool | None = None) -> CustomerData:
    """Carga los datos de UNA empresa hasta `through` (incluido). Nada posterior entra al paquete.

    `cap_to_complete`: limita `through` al último día completo (entrenamiento: sin datos parciales).
    `live`: la ejecución es VIGENTE (no se pidió una fecha histórica): la verdad con que se etiquetan los cortes conoce hasta HOY (la
    carga nocturna del último día cuenta), aunque la cobertura declarada limite `through` por debajo de la última venta. `False` fuerza
    una reconstrucción histórica. `None` (por defecto) lo infiere de lo PEDIDO (`through` ausente o ≥ última venta), no del `through`
    ya limitado por la cobertura.
    """
    first, last = repo.sales_range()
    coverage = repo.coverage("sales")
    if live is None:
        live = last is not None and (through is None or through >= last)
    if through is None or (last is not None and through > last):
        through = last
    if cap_to_complete:
        complete = last_complete_day(coverage, last)
        if complete is not None and through is not None and complete < through:
            through = complete
    # Una sola lectura de versiones hasta `through`: la vista al corte final (verdad disponible) y la de cada corte se derivan de ella.
    # Ejecución vigente (`live`): la verdad conoce hasta HOY (una carga nocturna del último día cuenta), aunque la cobertura limite
    # `through`; reconstrucción histórica: solo hasta el propio corte. Las variables de cada corte anterior usan siempre lo conocido
    # en ese corte.
    known_until = local_today(repo.base.timezone()) if (live and through is not None) else None
    versions = repo.purchase_versions(through, bool(cfg["exclude_generic_buyers"]), list(cfg["generic_buyer_codes"]), known_until) \
        if through is not None else None
    purchases = with_knowledge(versions.purchase_cells(through, known_until), versions) if versions is not None \
        else pd.DataFrame(columns=PURCHASE_COLUMNS)
    returns = versions.return_cells(through, known_until) if versions is not None \
        else pd.DataFrame(columns=["customer_id", "product_id", "ds", "qty"])
    pids = sorted(int(p) for p in purchases["product_id"].unique()) if len(purchases) else []
    availability = repo.availability(pids, first, through) if (pids and first is not None) else pd.DataFrame(
        columns=["branch_id", "product_id", "ds", "available"])
    excluded = repo.excluded_sales_stats(through, list(cfg["generic_buyer_codes"]), known_until) if through is not None else {}
    return CustomerData(
        purchases=purchases, returns=returns, products=repo.products(), branches=repo.branches(),
        availability=availability, coverage=coverage, promotions=repo.promotions(),
        promotion_revisions=repo.promotion_revisions(), sales_first=first, sales_last=last,
        excluded=excluded, tenant=repo.tenant(), through=through, purchase_versions=versions, live=bool(live),
    )
