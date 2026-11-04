"""Perecibilidad en el plan de reposición (V2.5 · W5-D, §10; docs/supply/SUPPLY_CORE.md §2).

Tres efectos sobre un producto perecible, todos DECLARADOS en el informe del plan y ninguno inventado:

1. **Lotes existentes (FEFO).** Con lotes con vencimiento conciliados con la existencia (`inventory.lots`), las unidades vencidas al corte
   dejan de ser utilizables y la MERMA PROYECTADA de los lotes que no alcanzan a venderse antes de su vencimiento (demanda persistida del
   pronóstico: `yhat`, o `lo_80` en el escenario de demanda baja) se descuenta de la existencia de apertura. Como FEFO vende primero lo que
   vence antes, las compras nuevas (más frescas) nunca alteran esa merma.
2. **Compras nuevas.** Con vida útil declarada `S` y período de revisión `R`:
   - `S ≥ R`: el stock al cierre de cada período se limita a lo vendible dentro de la vida útil (`shelf_cap_by_period`);
   - `S < R`: además, lo DISPONIBLE al inicio de cada período (arrastre + recepciones) se limita a la demanda de sus primeros `S` días
     (`shelf_avail_cap_by_period`): una unidad que llega con 3 días de vida y no se vende en esos 3 días vence dentro del propio período. La demanda de
     los días restantes del período queda sin cubrir POR VIDA ÚTIL (se declara y degrada la calidad: conviene acortar el período de revisión a ≤ la vida útil).
   En ambos casos el planificador no compra más de lo que se vende antes de que venza.
3. **Auditoría independiente del plan.** Tras resolver, se re-simula el plan día a día con FEFO (lotes existentes + recepciones abiertas +
   compras del plan) y se informa la merma esperada y la de demanda baja: la verificación del tope, no su supuesto.

Un producto SIN vida útil declarada y SIN lotes con vencimiento se planifica EXACTAMENTE como antes (no se inventa un vencimiento) y el plan lo
dice. Con `perishability_enabled = false` el efecto es nulo.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.inventory.lots import (
    NEVER,
    LotPosition,
    fefo_run,
    lots_asof,
    project_expiry,
    reconcile_lots,
    shelf_avail_cap_by_period,
    shelf_cap_by_period,
)
from predictily_engine.supply.model import EPS, PlanProblem, PlanSolution

# Clases de perecibilidad de una serie (visibles en el informe).
DURABLE = "durable"                          # sin perecibilidad declarada ni lotes con vencimiento: conducta anterior
UNQUANTIFIED = "perishable_unquantified"     # marcado perecible pero sin vida útil ni lotes: conducta anterior, declarado
SHELF_LIFE = "shelf_life"                    # vida útil declarada, sin lotes utilizables
LOTS = "lots"                                # lotes con vencimiento (la vida útil no está declarada)
LOTS_AND_SHELF_LIFE = "lots_and_shelf_life"

ASSUMPTIONS = (
    "Perecibilidad (FEFO): el vencimiento es el último día vendible; sale primero lo que vence antes y las unidades sin vencimiento declarado "
    "salen después de los lotes con fecha.",
    "La vida útil de una compra nueva se cuenta desde el inicio del período en que queda disponible y con la fracción de vida útil configurada "
    "(SUPUESTO: el proveedor entrega con la vida útil completa); la recepción física puede ocurrir antes dentro del período.",
    "La merma proyectada usa la demanda ya persistida del pronóstico (nunca se recalcula); el escenario de demanda baja suma los cuantiles "
    "inferiores diarios (cota conservadora, no el P10 de la suma).",
)


@dataclass
class PerishabilityConfig:
    enabled: bool = True
    enforce_shelf_cap: bool = True
    receipt_life_fraction: float = 1.0
    lots_max_age_days: int = 7
    loss_basis: str = "yhat"
    excess_drop: str = "latest_first"
    expired_mode: str = "in_on_hand"

    @classmethod
    def from_cfg(cls, get) -> "PerishabilityConfig":
        return cls(enabled=bool(get("perishability_enabled", True)), enforce_shelf_cap=bool(get("perishability_enforce_shelf_cap", True)),
                   receipt_life_fraction=float(get("perishability_receipt_life_fraction", 1.0)),
                   lots_max_age_days=int(get("perishability_lots_max_age_days", 7)), loss_basis=str(get("perishability_loss_demand_basis", "yhat")),
                   excess_drop=str(get("perishability_excess_drop", "latest_first")), expired_mode=str(get("perishability_expired_stock_mode", "in_on_hand")))


@dataclass
class NodePerishability:
    """Resultado de evaluar una serie. `usable_adjustment` = unidades que se restan de la apertura (vencidas al corte + merma esperada)."""

    status: str
    is_perishable: bool
    shelf_life_days: int | None
    lots: LotPosition | None = None
    expected_loss: float = 0.0
    low_demand_loss: float | None = None
    high_demand_loss: float | None = None
    unresolved_qty: float = 0.0
    first_loss_day: int | None = None
    shelf_cap: np.ndarray | None = None
    avail_cap: np.ndarray | None = None
    shelf_cap_floor_binding: int = 0
    shorter_than_period: bool = False
    daily_yhat: np.ndarray = field(default_factory=lambda: np.zeros(0))
    daily_low: np.ndarray | None = None
    notes: list[str] = field(default_factory=list)

    @property
    def applies(self) -> bool:
        return self.status != DURABLE and self.status != UNQUANTIFIED

    def summary(self) -> dict[str, Any]:
        out: dict[str, Any] = {"status": self.status, "is_perishable": self.is_perishable, "shelf_life_days": self.shelf_life_days,
                               "expected_expiry_loss_units": round(self.expected_loss, 4),
                               "low_demand_expiry_loss_units": None if self.low_demand_loss is None else round(self.low_demand_loss, 4),
                               "high_demand_expiry_loss_units": None if self.high_demand_loss is None else round(self.high_demand_loss, 4),
                               "first_loss_day": self.first_loss_day, "unresolved_qty": round(self.unresolved_qty, 4),
                               "shelf_cap_periods": 0 if self.shelf_cap is None else int(np.isfinite(self.shelf_cap).sum()),
                               "shelf_life_shorter_than_period": self.shorter_than_period,
                               "avail_cap_periods": 0 if self.avail_cap is None else int(np.isfinite(self.avail_cap).sum()),
                               "notes": list(self.notes)}
        if self.lots is not None:
            out["lots"] = self.lots.to_dict()
        return out


def classify(is_perishable: bool, shelf_life_days: int | None, lots: LotPosition | None) -> str:
    has_lots = lots is not None and lots.status == "configured"
    has_shelf = is_perishable and shelf_life_days is not None
    if has_lots and has_shelf:
        return LOTS_AND_SHELF_LIFE
    if has_lots:
        return LOTS
    if has_shelf:
        return SHELF_LIFE
    return UNQUANTIFIED if is_perishable else DURABLE


def assess_node(*, cfg: PerishabilityConfig, is_perishable: bool, shelf_life_days: int | None, series_lots: pd.DataFrame | None, on_hand: float,
                held_back: float, as_of: date, inventory_date: date | None, daily_yhat: np.ndarray, daily_low: np.ndarray | None,
                daily_high: np.ndarray | None) -> NodePerishability:
    """Evalúa la perecibilidad de UNA serie. `on_hand` es la existencia física válida (ya con ajustes); `held_back` el reservado que sale de la
    existencia. No depende del plan: solo del stock, los lotes, la vida útil y la demanda persistida."""
    pos: LotPosition | None = None
    if series_lots is not None and not series_lots.empty:
        pos = reconcile_lots(series_lots, on_hand, held_back, as_of, inventory_date, cfg.lots_max_age_days, cfg.excess_drop, cfg.expired_mode)
    status = classify(is_perishable, shelf_life_days, pos)
    node = NodePerishability(status, is_perishable, shelf_life_days, pos, daily_yhat=np.asarray(daily_yhat, dtype=float),
                             daily_low=None if daily_low is None else np.asarray(daily_low, dtype=float))
    if pos is not None:
        node.notes += pos.notes
        if pos.status in ("stale_ignored", "not_configured") and pos.reason:
            node.notes.append(pos.reason)
    if status == UNQUANTIFIED:
        node.notes.append("Perecible sin vida útil declarada ni lotes con vencimiento: se planifica como un durable (no se inventa un vencimiento).")
    if pos is not None and pos.status == "configured":
        dem = node.daily_yhat if cfg.loss_basis == "yhat" or node.daily_low is None else node.daily_low
        if cfg.loss_basis == "lo_80" and node.daily_low is None:
            node.notes.append("La base de merma `lo_80` no está disponible en el pronóstico persistido: se usó la demanda esperada `yhat`.")
        proj = project_expiry(pos, dem)
        node.expected_loss = float(proj["expired_qty"] or 0.0)
        node.unresolved_qty = float(proj["unresolved_qty"] or 0.0)
        node.first_loss_day = proj["first_loss_day"]
        if node.daily_low is not None and len(node.daily_low) == len(node.daily_yhat):
            node.low_demand_loss = float(project_expiry(pos, node.daily_low)["expired_qty"] or 0.0)
        elif node.daily_low is None:
            node.notes.append("Sin cuantiles `lo_80` persistidos: no se calcula el escenario de demanda baja.")
        if daily_high is not None and len(daily_high) == len(node.daily_yhat):
            node.high_demand_loss = float(project_expiry(pos, np.asarray(daily_high, dtype=float))["expired_qty"] or 0.0)
        if node.unresolved_qty > EPS:
            node.notes.append(f"{node.unresolved_qty:g} u. vencen después de los días cubiertos por el pronóstico: no se proyecta su merma (no se extrapola).")
    return node


def apply_to_opening(physical_before: float, node: NodePerishability) -> tuple[float, float]:
    """(apertura utilizable, unidades restadas): vencidas al corte y merma esperada. Nunca negativa."""
    removed = 0.0
    if node.lots is not None and node.lots.status == "configured":
        removed = node.lots.expired_deducted + node.expected_loss
    return max(physical_before - removed, 0.0), removed


def compute_shelf_cap(node: NodePerishability, cfg: PerishabilityConfig, period_days: int, n_periods: int, floor: np.ndarray,
                      floor_avail: np.ndarray | None = None) -> np.ndarray | None:
    """Tope por período para la serie (inf = sin tope), nunca por debajo del inventario mínimo ya comprometido (`floor`: stock de cierre sin compras
    nuevas; `floor_avail`: disponible al inicio sin compras nuevas). Devuelve el tope de arrastre; el tope de lo disponible al inicio (vida útil <
    período) queda en `node.avail_cap`."""
    node.avail_cap = None
    node.shorter_than_period = False
    if not cfg.enforce_shelf_cap or node.shelf_life_days is None or not node.is_perishable:
        return None
    S_eff = int(np.floor(node.shelf_life_days * cfg.receipt_life_fraction + 1e-9))
    node.shorter_than_period = S_eff < int(period_days)
    avail_raw = shelf_avail_cap_by_period(node.daily_yhat, period_days, n_periods, node.shelf_life_days, cfg.receipt_life_fraction)
    if floor_avail is not None and np.isfinite(avail_raw).any():
        node.avail_cap = np.where(np.isnan(avail_raw), np.inf, np.maximum(avail_raw, floor_avail[:n_periods]))
        node.notes.append(f"Vida útil efectiva ({S_eff} d) menor que el período de revisión ({int(period_days)} d): lo disponible al inicio de cada período se limita a "
                          "la demanda de sus primeros días de vida; el resto del período queda sin cobertura posible por vida útil (se declara).")
    elif node.shorter_than_period:
        node.notes.append(f"Vida útil efectiva ({S_eff} d) menor que el período de revisión ({int(period_days)} d) y el pronóstico no cubre la ventana: sin tope de "
                          "disponibilidad (no se extrapola la demanda).")
    raw = shelf_cap_by_period(node.daily_yhat, period_days, n_periods, node.shelf_life_days, cfg.receipt_life_fraction)
    if np.isnan(raw).all():
        return None
    cap = np.where(np.isnan(raw), np.inf, np.maximum(raw, floor[:n_periods]))
    node.shelf_cap_floor_binding = int(np.sum(np.isfinite(raw) & (raw < floor[:n_periods] - EPS)))
    if node.shelf_cap_floor_binding:
        node.notes.append(f"En {node.shelf_cap_floor_binding} período(s) las existencias y recepciones ya abiertas superan lo vendible dentro de la vida útil: "
                          "ninguna compra nueva puede corregirlo; el tope respeta ese piso (se informa como exceso preexistente).")
    node.shelf_cap = cap
    return cap


def audit_plan(problem: PlanProblem, sol: PlanSolution, nodes: dict[tuple[int, int], NodePerishability],
               unit_cost: dict[tuple[int, int], float | None], life_fraction: float = 1.0, top: int = 100,
               cost_source: dict[tuple[int, int], str] | None = None) -> dict[str, Any]:
    """Re-simula el plan DÍA A DÍA con FEFO y devuelve la merma por serie y total (esperada y de demanda baja).

    Supuesto de llegada (se declara en el informe): una recepción o compra queda disponible desde el INICIO del período en que el modelo la hace
    disponible, con la vida útil completa (fracción configurada). La recepción física puede ocurrir antes dentro del período (la vida útil corre desde
    allí): una compra que llega a mitad de un período vence en realidad antes de lo simulado, de modo que la merma real puede ser algo MAYOR cuando el
    período es largo frente a la vida útil. `unit_cost` va por (sucursal, producto): None = costo implícito o no confiable (la serie NO se valora; se informa
    cuántas series se valoraron y cuántas no en `valuation`)."""
    R = problem.period_days
    by_series: dict[tuple[int, int], list] = {}
    for p in sol.purchases:
        by_series.setdefault((p.branch_id, p.product_id), []).append(("purchase", p.arrival_period, p.quantity))
    for pl in sol.productions:
        by_series.setdefault((pl.branch_id, pl.output_product_id), []).append(("production", pl.arrival_period, pl.output_quantity))
    series_out = []
    tot = {"expected": 0.0, "low": 0.0, "value_expected": 0.0, "value_low": 0.0, "low_available": False}
    pnodes = problem.node_index()
    for key, info in nodes.items():
        if not info.applies or key not in pnodes:
            continue
        node = pnodes[key]
        n_days = min(len(info.daily_yhat), node.horizon * R)
        if n_days <= 0:
            continue
        S = None if info.shelf_life_days is None or not info.is_perishable else int(np.floor(info.shelf_life_days * life_fraction + 1e-9))

        def expiry_of(arrival_day: int) -> int | None:
            return None if S is None else arrival_day + S - 1

        arrivals: list[tuple[int, float, int | None, str]] = []
        for tau, q in enumerate(node.known_receipts[: node.horizon]):
            if q > EPS:
                day = tau * R + 1
                arrivals.append((day, float(q), expiry_of(day), "recepción abierta"))
        for kind, tau_a, q in by_series.get(key, []):
            if q > EPS and tau_a < node.horizon:
                day = tau_a * R + 1
                arrivals.append((day, float(q), expiry_of(day), "compra del plan" if kind == "purchase" else "producción del plan"))
        lots = info.lots.lots if info.lots is not None and info.lots.status == "configured" else []
        undated = info.lots.undated_qty if info.lots is not None and info.lots.status == "configured" else max(node.opening, 0.0)
        # sin lotes configurados, el stock de apertura es de edad desconocida: no se proyecta merma sobre él
        base = fefo_run(lots, undated, info.daily_yhat[:n_days], arrivals)
        lost_new = sum(q for d, q, c in base.lost_lots if c in ("compra del plan", "producción del plan", "recepción abierta"))
        low = None
        if info.daily_low is not None and len(info.daily_low) >= n_days:
            low = fefo_run(lots, undated, info.daily_low[:n_days], arrivals)
        cost = unit_cost.get(key)
        row = {"branch_id": key[0], "product_id": key[1], "status": info.status, "days_simulated": int(n_days),
               "expected_expiry_loss_units": round(base.expired_qty, 4), "expected_loss_from_new_receipts_units": round(float(lost_new), 4),
               "low_demand_expiry_loss_units": None if low is None else round(low.expired_qty, 4),
               "first_loss_day": base.lost_lots[0][0] if base.lost_lots else None,
               "lost_lots": [{"day": d, "qty": round(q, 4), "source": c} for d, q, c in base.lost_lots[:10]],
               "expected_loss_value": None if cost is None else round(base.expired_qty * cost, 4),
               "unit_cost": cost, "cost_source": (cost_source or {}).get(key), "valued": cost is not None}
        series_out.append(row)
        tot["expected"] += base.expired_qty
        if cost is not None:
            tot["value_expected"] += base.expired_qty * cost
        if low is not None:
            tot["low"] += low.expired_qty
            tot["low_available"] = True
            if cost is not None:
                tot["value_low"] += low.expired_qty * cost
    series_out.sort(key=lambda r: (-(r["expected_expiry_loss_units"] or 0.0), r["branch_id"], r["product_id"]))
    valued = sum(1 for r in series_out if r["valued"])
    return {"method": "FEFO diario sobre el pronóstico persistido (lotes existentes + recepciones abiertas + compras del plan)",
            "arrival_assumption": "recepciones y compras disponibles desde el inicio del período con la vida útil completa; la recepción física puede ser anterior "
                                  "(la merma real puede ser algo mayor cuando el período es largo frente a la vida útil)",
            "series_audited": len(series_out), "expected_expiry_loss_units": round(tot["expected"], 4),
            "valuation": {"rule": "se valora solo con el costo de una oferta explícita de la serie (sucursal × producto); una oferta implícita (último costo conocido) "
                                  "no es un costo confiable y deja la serie sin valorar",
                          "series_valued": valued, "series_not_valued": len(series_out) - valued},
            "expected_expiry_loss_value": round(tot["value_expected"], 4) if valued else None,
            "low_demand_expiry_loss_units": round(tot["low"], 4) if tot["low_available"] else None,
            "low_demand_expiry_loss_value": round(tot["value_low"], 4) if tot["low_available"] and valued else None,
            "series": series_out[:top]}


def series_lots_index(lots: pd.DataFrame | None, as_of: date) -> dict[tuple[int, int], pd.DataFrame]:
    """Última foto de lotes ≤ corte por serie."""
    sel = lots_asof(lots, as_of)
    if sel.empty:
        return {}
    return {(int(b), int(p)): g for (b, p), g in sel.groupby(["branch_id", "product_id"])}


__all__ = ["ASSUMPTIONS", "DURABLE", "LOTS", "LOTS_AND_SHELF_LIFE", "NEVER", "SHELF_LIFE", "UNQUANTIFIED", "NodePerishability",
           "PerishabilityConfig", "apply_to_opening", "assess_node", "audit_plan", "classify", "compute_shelf_cap", "series_lots_index"]
