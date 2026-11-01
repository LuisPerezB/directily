"""Tipos del planificador de reposición y utilidades de tiempo.

Convención temporal (documentada en docs/architecture/v2/P4-decisiones.md §2):

- `as_of` es el último día con información. El día 1 es el día siguiente.
- El período `τ` (0-indexado) cubre los días `τ·R + 1 … (τ+1)·R` posteriores a `as_of` (R = días del período,
  por defecto el período de revisión).
- Una orden se emite al cierre del día `as_of + t·R` (`t` = período de emisión; `t = 0` es la decisión de hoy).
  Con plazo `L ≥ 1` días llega el día `t·R + L`, utilizable para la demanda de ese día. En un modelo por períodos
  se cuenta como disponible desde el período `t + ⌈(L − 1)/R⌉`: si llega a mitad de un período se supone
  disponible a partir del siguiente (aproximación conservadora: nunca adelanta una recepción).
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from datetime import date, timedelta
from typing import Any

import numpy as np

EPS = 1e-9
SOLVER_STATUSES = ("optimal", "feasible_with_gap", "time_limit", "infeasible", "heuristic_fallback", "not_solved")


def arrival_period(t: int, lead_days: int, period_days: int) -> int:
    """Período desde el que está disponible una orden emitida en el período `t` con plazo `lead_days`."""
    return t + math.ceil((max(1, int(lead_days)) - 1) / period_days)


def day_to_period(day: int, period_days: int) -> int:
    """Período desde el que está disponible una recepción que llega el día `day` (1 = día siguiente al corte)."""
    return 0 if day <= 1 else math.ceil((day - 1) / period_days)


def issue_date(as_of: date, t: int, period_days: int) -> date:
    return as_of + timedelta(days=t * period_days)


@dataclass
class OfferSpec:
    """Oferta normalizada: precios por unidad del producto, en la moneda del plan."""

    key: str
    offer_id: int | None
    supplier_id: int | None
    product_id: int
    branch_id: int | None  # None = entrega a cualquier sucursal
    unit_cost: float  # puesto en bodega (incluye gastos de adquisición variables) por unidad del producto
    base_unit_cost: float  # precio sin gastos de adquisición, por unidad del producto
    lot: float = 1.0  # unidades del producto por lote (múltiplo de compra)
    moq: float = 0.0  # mínimo de compra por orden, en unidades del producto
    fixed_cost: float = 0.0  # costo fijo por línea de orden, moneda del plan
    capacity: float | None = None  # unidades del producto por período de planificación; None = sin límite declarado
    default_lead: int = 7
    lead_by_branch: dict[int, int] = field(default_factory=dict)
    lead_std: float | None = None
    valid_from: date | None = None
    valid_to: date | None = None
    implicit: bool = False
    offer_unit: str | None = None
    offer_factor: float = 1.0  # unidades del producto por unidad de la oferta
    meta: dict[str, Any] = field(default_factory=dict)

    def lead(self, branch_id: int) -> int:
        return int(self.lead_by_branch.get(branch_id, self.default_lead))

    def applies_to(self, branch_id: int) -> bool:
        return self.branch_id is None or self.branch_id == branch_id

    def valid_on(self, d: date) -> bool:
        return (self.valid_from is None or d >= self.valid_from) and (self.valid_to is None or d <= self.valid_to)


@dataclass
class NodeSpec:
    """Producto × ubicación con demanda conocida durante `len(demand)` períodos."""

    branch_id: int
    product_id: int
    unit: str | None
    opening: float  # existencias físicas utilizables al corte (existencias − reservas)
    demand: np.ndarray  # unidades por período (pronóstico de la media)
    known_receipts: np.ndarray  # recepciones de órdenes abiertas por período (ya con la política de fechas)
    penalty: float  # penalización por unidad no atendida (moneda del plan), ya ponderada por prioridad
    holding: float  # costo de mantener una unidad durante un período
    safety_stock: float = 0.0
    space: float | None = None  # unidades de capacidad por unidad del producto
    price: float | None = None
    derived: bool = False  # sin pronóstico propio: solo demanda dependiente (insumo)
    u_weights: np.ndarray | None = None  # ponderación de urgencia del faltante por período (ver planner.prepare_problem)
    meta: dict[str, Any] = field(default_factory=dict)

    @property
    def key(self) -> tuple[int, int]:
        return (self.branch_id, self.product_id)

    @property
    def horizon(self) -> int:
        return int(len(self.demand))


@dataclass
class RecipeSpec:
    recipe_id: int
    code: str
    output_product_id: int
    branch_id: int | None
    output_per_batch: float  # producción útil por lote (nominal × rendimiento)
    inputs: dict[int, float]  # insumo -> cantidad por lote
    production_days: int = 1
    resource_id: int | None = None
    resource_usage: float = 0.0
    cost_per_batch: float | None = None
    max_batches: float | None = None
    integer_batches: bool = True


@dataclass
class ResourceSpec:
    resource_id: int
    code: str
    branch_id: int | None
    capacity: float  # por período de planificación (ya prorrateada)
    unit: str | None = None


@dataclass
class BudgetSpec:
    budget_id: int | None
    branch_id: int | None
    start: date
    end: date
    amount: float  # moneda del plan


@dataclass
class CapacitySpec:
    branch_id: int
    capacity: float
    unit: str


@dataclass
class PlanProblem:
    as_of: date
    period_days: int
    n_periods: int
    currency: str
    nodes: list[NodeSpec]
    offers: list[OfferSpec]
    recipes: list[RecipeSpec] = field(default_factory=list)
    resources: list[ResourceSpec] = field(default_factory=list)
    budgets: list[BudgetSpec] = field(default_factory=list)
    capacities: list[CapacitySpec] = field(default_factory=list)
    objective: str = "min_cost"  # min_cost | max_margin
    coverage_mode: str = "soft"  # soft | hard
    time_limit: float = 30.0
    mip_gap: float = 1e-4
    ss_weight: float = 0.9
    meta: dict[str, Any] = field(default_factory=dict)

    def node_index(self) -> dict[tuple[int, int], NodeSpec]:
        return {n.key: n for n in self.nodes}

    def offers_for(self, branch_id: int, product_id: int) -> list[OfferSpec]:
        return [o for o in self.offers if o.product_id == product_id and o.applies_to(branch_id)]

    def recipes_for(self, branch_id: int) -> list[RecipeSpec]:
        return [r for r in self.recipes if r.branch_id is None or r.branch_id == branch_id]

    def issue_date(self, t: int) -> date:
        return issue_date(self.as_of, t, self.period_days)

    def period_start(self, tau: int) -> date:
        return self.as_of + timedelta(days=tau * self.period_days + 1)

    def period_end(self, tau: int) -> date:
        return self.as_of + timedelta(days=(tau + 1) * self.period_days)


@dataclass
class PurchaseLine:
    offer_key: str
    offer_id: int | None
    supplier_id: int | None
    branch_id: int
    product_id: int
    t: int  # período de emisión
    lots: int
    quantity: float  # unidades del producto
    arrival_period: int
    lead_days: int
    unit_cost: float
    fixed_cost: float

    @property
    def cost(self) -> float:
        return self.quantity * self.unit_cost + (self.fixed_cost if self.quantity > EPS else 0.0)


@dataclass
class ProductionLine:
    recipe_id: int
    branch_id: int
    output_product_id: int
    t: int
    batches: float
    arrival_period: int
    output_quantity: float
    cost: float = 0.0


@dataclass
class NodeResult:
    inventory: np.ndarray  # existencias al cierre de cada período
    sales: np.ndarray
    unmet: np.ndarray
    ss_shortfall: np.ndarray
    receipts: np.ndarray  # recepciones totales por período (órdenes abiertas + plan + producción)
    peak: np.ndarray  # existencias tras recepciones, antes de ventas (pico de almacenamiento)


@dataclass
class Evaluation:
    """Resultado de re-simular un plan de forma independiente del solver."""

    nodes: dict[tuple[int, int], NodeResult]
    purchase_cost: float
    fixed_cost: float
    production_cost: float
    holding_cost: float
    shortage_penalty: float
    ss_penalty: float
    unmet_units: float
    demand_units: float
    violations: list[dict[str, Any]]
    activity: list[dict[str, Any]]  # actividad de cada restricción agregada

    @property
    def feasible(self) -> bool:
        return not self.violations

    @property
    def total_cost(self) -> float:
        return (self.purchase_cost + self.fixed_cost + self.production_cost + self.holding_cost
                + self.shortage_penalty + self.ss_penalty)


@dataclass
class PlanSolution:
    status: str  # ver SOLVER_STATUSES
    detail: str = ""
    purchases: list[PurchaseLine] = field(default_factory=list)
    productions: list[ProductionLine] = field(default_factory=list)
    objective_value: float | None = None
    mip_gap: float | None = None
    dual_bound: float | None = None
    seconds: float = 0.0
    n_vars: int = 0
    n_constraints: int = 0
    n_integer: int = 0
    evaluation: Evaluation | None = None
    notes: list[str] = field(default_factory=list)
    n_components: int = 1
