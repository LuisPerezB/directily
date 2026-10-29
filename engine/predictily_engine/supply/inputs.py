"""Construcción del problema de planificación a partir de datos (DataFrames) de UNA empresa.

Pura y sin acceso a la base de datos: recibe `PlanInputs` (leídos por `db/supply_repository.py` o fabricados por
las pruebas/simulación) y devuelve un `PlanProblem` más un informe con exclusiones, supuestos y limitaciones.
Lo desconocido se excluye con su motivo; nunca se rellena con ceros.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from datetime import date
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.inventory.recommendations import resolve_lead_time
from predictily_engine.supply.availability import to_date
from predictily_engine.supply.balance import build_receipts, lead_time_evidence, receipts_by_period
from predictily_engine.supply.fx import FxRates
from predictily_engine.supply.model import (
    EPS,
    BudgetSpec,
    CapacitySpec,
    NodeSpec,
    PlanProblem,
    RecipeSpec,
    ResourceSpec,
)
from predictily_engine.supply.offers import (
    comparison_coverage,
    implicit_offers,
    normalize_offers,
    standalone_offer_diagnostics,
)
from predictily_engine.supply.planner import prepare_problem
from predictily_engine.supply.units import UnitConversions, norm_unit


class PlanInputError(ValueError):
    """Datos que impiden construir un plan sin violar una restricción (p. ej. presupuesto sin tasa de cambio)."""


def _empty(cols: list[str]) -> pd.DataFrame:
    return pd.DataFrame(columns=cols)


@dataclass
class PlanInputs:
    as_of: date
    currency: str = "USD"
    retroactive: bool = False
    forecast: pd.DataFrame = field(default_factory=lambda: _empty(["branch_id", "product_id", "ds", "yhat"]))
    inventory: pd.DataFrame = field(default_factory=lambda: _empty(["branch_id", "product_id", "on_hand", "reserved", "in_transit"]))
    open_pos: pd.DataFrame = field(default_factory=lambda: _empty(["branch_id", "product_id", "qty_open", "expected_at"]))
    po_history: pd.DataFrame = field(default_factory=lambda: _empty(["supplier_id", "ordered_at", "expected_at", "received_at", "status"]))
    products: pd.DataFrame = field(default_factory=lambda: _empty(["product_id", "unit", "current_price", "current_cost"]))
    lead_times: pd.DataFrame = field(default_factory=lambda: _empty(["supplier_id", "product_id", "branch_id", "lead_time_days", "lead_time_std_days"]))
    suppliers: pd.DataFrame = field(default_factory=lambda: _empty(["supplier_id", "default_lead_time_days"]))
    offers: pd.DataFrame = field(default_factory=pd.DataFrame)
    fx_rates: pd.DataFrame = field(default_factory=pd.DataFrame)
    conversions: pd.DataFrame = field(default_factory=pd.DataFrame)
    capacities: pd.DataFrame = field(default_factory=pd.DataFrame)
    warehouses: pd.DataFrame = field(default_factory=pd.DataFrame)
    budgets: pd.DataFrame = field(default_factory=pd.DataFrame)
    resources: pd.DataFrame = field(default_factory=pd.DataFrame)
    recipes: pd.DataFrame = field(default_factory=pd.DataFrame)
    recipe_lines: pd.DataFrame = field(default_factory=pd.DataFrame)
    safety_stock: pd.DataFrame | None = None  # branch_id, product_id, safety_stock, uncertainty
    cost_by_product: dict[int, dict] = field(default_factory=dict)
    config: dict[str, Any] = field(default_factory=dict)
    options: dict[str, Any] = field(default_factory=dict)


def _cfg(inp: PlanInputs, key: str, default: Any = None) -> Any:
    if key in inp.options and inp.options[key] is not None:
        return inp.options[key]
    return inp.config.get(key, default)


def build_problem(inp: PlanInputs) -> tuple[PlanProblem, dict[str, Any]]:
    assumptions: list[str] = []
    limitations: list[str] = []
    excluded: list[dict[str, Any]] = []
    as_of = inp.as_of
    R = int(_cfg(inp, "period_days", None) or _cfg(inp, "review_period_days", 7))
    horizon_days = int(_cfg(inp, "horizon_days", None) or _cfg(inp, "planning_horizon_days", 84))
    T = max(2, math.ceil(horizon_days / R))
    objective = str(_cfg(inp, "objective", "min_cost"))
    coverage_mode = str(_cfg(inp, "coverage_mode", "soft"))
    if objective not in ("min_cost", "max_margin") or coverage_mode not in ("soft", "hard"):
        raise PlanInputError("Objetivo o modo de cobertura no válido.")
    mult = float(_cfg(inp, "shortage_penalty_multiplier", 2.0))
    if mult <= 1.0:
        raise PlanInputError("shortage_penalty_multiplier debe ser mayor que 1: con valores ≤ 1 ninguna compra se justifica.")
    holding_rate = float(_cfg(inp, "holding_cost_rate_annual", 0.20))
    ss_weight = float(_cfg(inp, "safety_stock_weight", 0.9))
    if not 0 <= ss_weight < 1:
        raise PlanInputError("safety_stock_weight debe estar en [0, 1).")
    undated_policy = str(_cfg(inp, "undated_receipt_policy", "lead_time_end"))
    overdue_policy = str(_cfg(inp, "overdue_receipt_policy", undated_policy))
    default_lead = int(_cfg(inp, "default_lead_time_days", 7))
    priorities = {int(k): float(v) for k, v in (inp.options.get("priorities") or {}).items()}
    penalties_in = {int(k): float(v) for k, v in (inp.options.get("shortage_penalties") or {}).items()}

    conversions = UnitConversions(inp.conversions)
    fx = FxRates(inp.fx_rates, as_of, inp.retroactive, int(_cfg(inp, "fx_max_age_days", 30)))
    products = inp.products if not inp.products.empty else _empty(["product_id", "unit", "current_price", "current_cost"])
    pinfo = products.set_index("product_id") if not products.empty else pd.DataFrame()

    assumptions.append(f"Período de planificación de {R} días; horizonte de {T * R} días ({T} períodos).")
    assumptions.append("Una recepción dentro de un período se considera disponible desde el período siguiente "
                       "(aproximación conservadora por períodos).")
    assumptions.append(f"Objetivo `{objective}`: " + (
        "minimizar compra + gastos de adquisición + mantenimiento + penalización por demanda no atendida."
        if objective == "min_cost" else
        "maximizar ingresos − compra − gastos − mantenimiento (el precio de venta actual penaliza la demanda no atendida)."))
    assumptions.append(f"Costo anual de mantener inventario: {holding_rate:.0%} del costo (SUPUESTO configurable).")
    if objective == "min_cost":
        assumptions.append(f"Penalización por unidad no atendida = {mult:g} × costo puesto en bodega, ponderada por "
                           "prioridad (SUPUESTO explícito; no es un costo observado).")
    assumptions.append(f"Ventas perdidas: la demanda no atendida no se recupera. Peso del déficit de stock de seguridad: {ss_weight:g}.")
    assumptions.append("Demanda = media pronosticada; la incertidumbre entra solo mediante el stock de seguridad aportado.")

    # ── Demanda por nodo ──
    fc = inp.forecast.copy()
    if not fc.empty:
        fc["ds"] = pd.to_datetime(fc["ds"]).dt.date
        fc["yhat"] = pd.to_numeric(fc["yhat"], errors="coerce")
    inv = inp.inventory.copy()
    inv_idx = inv.set_index(["branch_id", "product_id"]) if not inv.empty else None
    ss_idx = None
    if inp.safety_stock is not None and not inp.safety_stock.empty:
        ss_idx = inp.safety_stock.set_index(["branch_id", "product_id"])

    keys = []
    if not fc.empty:
        keys = [(int(b), int(p)) for b, p in fc[["branch_id", "product_id"]].drop_duplicates().itertuples(index=False)]
    max_nodes = int(_cfg(inp, "max_nodes", 3000))
    if len(keys) > max_nodes:
        limitations.append(f"Se planificaron {max_nodes} de {len(keys)} series (límite configurado).")
        keys = keys[:max_nodes]
    branch_ids = sorted({b for b, _ in keys})

    # ── Ofertas ──
    lead_ev = lead_time_evidence(inp.po_history, as_of)
    offer_specs, offer_report = normalize_offers(
        inp.offers, products, conversions, fx, inp.lead_times, inp.suppliers, as_of, inp.retroactive, inp.currency,
        R, default_lead, branch_ids, lead_ev)
    product_ids_demand = sorted({p for _, p in keys})
    have_offer = {o.product_id for o in offer_specs}
    explicit_products = set(inp.offers["product_id"].unique()) if inp.offers is not None and not inp.offers.empty else set()
    if bool(_cfg(inp, "use_implicit_offers", True)):
        # Productos SIN ninguna oferta explícita (ni siquiera excluida): oferta implícita con el último costo conocido.
        missing = [p for p in product_ids_demand if p not in explicit_products]
        imp, imp_report = implicit_offers(missing, products, inp.cost_by_product, inp.lead_times, inp.suppliers,
                                          default_lead, branch_ids, inp.currency)
        offer_specs += imp
        offer_report += imp_report
        if imp:
            assumptions.append(f"{len(imp)} producto(s) sin tabla de ofertas usan una oferta implícita: último costo "
                               "conocido, plazo de la etapa 1, sin mínimo, múltiplo ni capacidad (desconocidos).")
    offers_by_product: dict[int, list] = {}
    for o in offer_specs:
        offers_by_product.setdefault(o.product_id, []).append(o)

    # ── Capacidad de almacenamiento por sucursal ──
    cap_specs, cap_notes = _resolve_capacities(inp, as_of)
    cap_by_branch = {c.branch_id: c for c in cap_specs}

    nodes: list[NodeSpec] = []
    receipts_summary = {"undated": 0, "overdue": 0, "assumed_qty": 0.0, "excluded_qty": 0.0, "excluded_orders": 0,
                        "snapshot_ignored_series": 0, "snapshot_only_series": 0}
    observed = {int(r.supplier_id): float(r.mean_lead) for r in lead_ev.itertuples(index=False)} if not lead_ev.empty else {}
    orders_by_series: dict[tuple[int, int], pd.DataFrame] = {}
    if inp.open_pos is not None and not inp.open_pos.empty:
        orders_by_series = {(int(b), int(p)): g for (b, p), g in inp.open_pos.groupby(["branch_id", "product_id"])}

    for key in keys:
        b, p = key
        g = fc[(fc["branch_id"] == b) & (fc["product_id"] == p)].sort_values("ds")
        offs = [o for o in offers_by_product.get(p, []) if o.applies_to(b)]
        if inv_idx is None or key not in inv_idx.index:
            excluded.append({"branch_id": b, "product_id": p, "reason": "no_inventory",
                             "detail": "Sin snapshot de inventario al corte: las existencias son desconocidas (no se asumen cero)."})
            continue
        snap = inv_idx.loc[key]
        if isinstance(snap, pd.DataFrame):
            snap = snap.iloc[-1]
        on_hand, reserved = float(snap["on_hand"]), float(snap.get("reserved") or 0.0)
        physical = max(on_hand - reserved, 0.0)
        # días de pronóstico contiguos desde as_of + 1
        days = (pd.to_datetime(g["ds"]) - pd.Timestamp(as_of)).dt.days.to_numpy()
        yhat = np.clip(np.nan_to_num(g["yhat"].to_numpy(dtype=float), nan=0.0), 0, None)
        covered = int(np.cumprod(days == np.arange(1, len(days) + 1)).sum())
        n_periods = min(T, covered // R)
        if not offs:
            excluded.append({"branch_id": b, "product_id": p, "reason": "no_offer",
                             "detail": "Sin oferta comparable ni costo conocido para planificar la compra."})
            continue
        min_lead = min(o.lead(b) for o in offs)
        needed_days = min_lead + R
        if covered < needed_days or n_periods < 1:
            excluded.append({"branch_id": b, "product_id": p, "reason": "coverage_insufficient",
                             "detail": f"El pronóstico cubre {covered} días y la oferta más rápida exige {needed_days} "
                                       f"(plazo {min_lead} + período {R}); no se extrapola la demanda."})
            continue
        n_periods = max(n_periods, 1)
        demand = yhat[: n_periods * R].reshape(n_periods, R).sum(axis=1)
        lt_days, _lt_std, _src = resolve_lead_time(p, b, inp.lead_times, inp.suppliers, default_lead)
        receipts, rinfo = build_receipts(orders_by_series.get(key), float(snap.get("in_transit") or 0.0), lt_days,
                                         as_of, undated_policy, overdue_policy, observed)
        for r in receipts:
            if r.status == "undated":
                receipts_summary["undated"] += 1
            elif r.status == "overdue":
                receipts_summary["overdue"] += 1
            if r.assumed:
                receipts_summary["assumed_qty"] += r.qty
        receipts_summary["excluded_qty"] += rinfo["excluded_qty"]
        receipts_summary["excluded_orders"] += rinfo["excluded_orders"]
        if rinfo["snapshot_in_transit_ignored"] > EPS:
            receipts_summary["snapshot_ignored_series"] += 1
        if rinfo["source"] == "snapshot":
            receipts_summary["snapshot_only_series"] += 1
        known = receipts_by_period(receipts, n_periods, R)
        unit = norm_unit(pinfo.loc[p, "unit"]) if (not pinfo.empty and p in pinfo.index) else None
        price = _num(pinfo.loc[p, "current_price"]) if (not pinfo.empty and p in pinfo.index) else None
        ref_cost = min(o.unit_cost for o in offs)
        priority = priorities.get(p, 1.0)
        if p in penalties_in:
            penalty, pen_src = penalties_in[p] * priority, "explicit"
        elif objective == "max_margin" and price is not None and price > 0:
            penalty, pen_src = price * priority, "price"
        else:
            penalty, pen_src = mult * ref_cost * priority, "multiplier"
            if objective == "max_margin":
                assumptions.append(f"Producto {p}: sin precio de venta; se usa la penalización por múltiplo de costo.")
        holding = holding_rate / 365.0 * R * ref_cost
        ss = 0.0
        ss_source = None
        if ss_idx is not None and key in ss_idx.index:
            row = ss_idx.loc[key]
            if isinstance(row, pd.DataFrame):
                row = row.iloc[-1]
            val = _num(row.get("safety_stock"))
            if val is not None and val > 0:
                ss, ss_source = float(val), str(row.get("uncertainty") or "unknown")
        space = None
        if b in cap_by_branch:
            f, _ = conversions.factor(p, unit, cap_by_branch[b].unit, as_of)
            space = f
            if f is None:
                excluded.append({"branch_id": b, "product_id": p, "reason": "no_capacity_conversion",
                                 "detail": f"Sin conversión de la unidad del producto ({unit}) a la de la capacidad "
                                           f"({cap_by_branch[b].unit}): no se puede verificar el almacenamiento."})
                continue
        nodes.append(NodeSpec(
            branch_id=b, product_id=p, unit=unit, opening=physical, demand=demand.astype(float),
            known_receipts=known.astype(float), penalty=float(penalty), holding=float(holding), safety_stock=float(ss),
            space=space, price=price,
            meta={"penalty_source": pen_src, "ss_source": ss_source, "covered_days": covered,
                  "physical_available": physical, "on_hand": on_hand, "reserved": reserved,
                  "receipts": [(r.day, r.qty, r.status) for r in receipts],
                  "receipts_info": rinfo, "lead_time_days": lt_days}))

    # ── Producción genérica (recetas de un nivel) ──
    recipes, resources, prod_notes, prod_status = _build_production(inp, nodes, R, as_of, assumptions, limitations,
                                                                    pinfo, offers_by_product, cap_by_branch, conversions,
                                                                    holding_rate, mult, priorities, T, undated_policy,
                                                                    overdue_policy, default_lead, inv_idx, ss_idx)
    node_keys = {n.key for n in nodes}

    # ── Presupuestos ──
    budgets = _resolve_budgets(inp, fx, as_of, R, T)

    problem = PlanProblem(
        as_of=as_of, period_days=R, n_periods=max([n.horizon for n in nodes], default=0) or T,
        currency=inp.currency, nodes=nodes, offers=[o for o in offer_specs if any(o.applies_to(n.branch_id) and o.product_id == n.product_id for n in nodes)],
        recipes=recipes, resources=resources, budgets=budgets, capacities=[c for c in cap_specs if any(n.branch_id == c.branch_id for n in nodes)],
        objective=objective, coverage_mode=coverage_mode, time_limit=float(_cfg(inp, "solver_time_limit_seconds", 30)),
        mip_gap=float(_cfg(inp, "solver_mip_gap", 1e-4)), ss_weight=ss_weight)
    prepare_problem(problem)

    if receipts_summary["undated"] + receipts_summary["overdue"] > 0:
        limitations.append(
            f"{receipts_summary['undated']} recepción(es) sin fecha y {receipts_summary['overdue']} vencida(s): política "
            f"`{undated_policy}`/`{overdue_policy}` (la fecha es un supuesto, no un dato).")
    if receipts_summary["excluded_orders"]:
        limitations.append(f"{receipts_summary['excluded_orders']} orden(es) sin fecha excluidas "
                           f"({receipts_summary['excluded_qty']:.0f} u.): el componente afectado no se calculó.")
    if receipts_summary["snapshot_ignored_series"]:
        limitations.append(f"En {receipts_summary['snapshot_ignored_series']} serie(s) el tránsito del snapshot se "
                           "ignora porque hay órdenes abiertas con fecha (no se cuenta dos veces).")
    if any(n.safety_stock <= 0 for n in nodes):
        limitations.append("Algunas series no tienen stock de seguridad: el plan es determinista (media) para ellas.")
    limitations += cap_notes + prod_notes
    if inp.retroactive:
        limitations.append("Ejecución retroactiva: solo ofertas, tasas y datos con disponibilidad conocida al corte.")

    comp = comparison_coverage(offer_report, product_ids_demand)
    diagnostics = []
    for n in nodes:
        offs = [o for o in offers_by_product.get(n.product_id, []) if o.applies_to(n.branch_id)]
        if len(offs) >= 2:
            diagnostics.append({"branch_id": n.branch_id, "product_id": n.product_id,
                                "offers": standalone_offer_diagnostics(n, offs, R)})
    uncertainty = "none"
    sources = {n.meta.get("ss_source") for n in nodes if n.safety_stock > 0}
    if sources:
        if sources <= {"empirical"}:
            uncertainty = "empirical_holdout"
        elif sources <= {"empirical", "intervals"}:
            uncertainty = "conformal"
        else:
            uncertainty = "unknown"
    report = {
        "assumptions": assumptions, "limitations": limitations, "excluded_nodes": excluded,
        "offers": offer_report, "comparison_coverage": comp, "offer_diagnostics": diagnostics[:200],
        "receipts_summary": receipts_summary, "production": prod_status, "uncertainty_source": uncertainty,
        "capacity_notes": cap_notes, "nodes_planned": len(nodes), "nodes_candidate": len(keys),
        "fx_excluded_unknown_availability": getattr(fx, "excluded_unknown_availability", 0),
        "lead_time_evidence": lead_ev.to_dict("records") if not lead_ev.empty else [],
        "period_days": R, "horizon_periods": problem.n_periods,
    }
    return problem, report


def _num(v) -> float | None:
    try:
        return None if v is None or pd.isna(v) else float(v)
    except (TypeError, ValueError):
        return None


def _resolve_capacities(inp: PlanInputs, as_of: date) -> tuple[list[CapacitySpec], list[str]]:
    notes: list[str] = []
    out: dict[int, CapacitySpec] = {}
    caps = inp.capacities
    if caps is not None and not caps.empty:
        df = caps.copy()
        df["valid_from"] = pd.to_datetime(df.get("valid_from"), errors="coerce")
        df["valid_to"] = pd.to_datetime(df.get("valid_to"), errors="coerce")
        cut = pd.Timestamp(as_of)
        df = df[(df["valid_from"].isna() | (df["valid_from"] <= cut)) & (df["valid_to"].isna() | (df["valid_to"] >= cut))]
        for b, g in df.groupby("branch_id"):
            units = {norm_unit(u) for u in g["capacity_unit"]}
            if len(units) != 1:
                notes.append(f"Sucursal {b}: capacidades en unidades distintas ({sorted(units)}); no se suman, se omite la restricción.")
                continue
            total = float(pd.to_numeric(g["capacity"]).sum())
            out[int(b)] = CapacitySpec(int(b), total, next(iter(units)))
    wh = inp.warehouses
    if wh is not None and not wh.empty:
        w = wh[wh["storage_capacity"].notna() & wh["capacity_unit"].notna()]
        for b, g in w.groupby("branch_id"):
            if int(b) in out:
                continue
            units = {norm_unit(u) for u in g["capacity_unit"]}
            if len(units) != 1:
                continue
            out[int(b)] = CapacitySpec(int(b), float(pd.to_numeric(g["storage_capacity"]).sum()), next(iter(units)))
            notes.append(f"Sucursal {b}: capacidad tomada de la suma de sus almacenes (el inventario se lleva por sucursal).")
    return list(out.values()), notes


def _resolve_budgets(inp: PlanInputs, fx: FxRates, as_of: date, R: int, T: int) -> list[BudgetSpec]:
    out: list[BudgetSpec] = []
    if inp.budgets is None or inp.budgets.empty:
        return out
    end_h = pd.Timestamp(as_of) + pd.Timedelta(days=R * T)
    for r in inp.budgets.itertuples(index=False):
        start, end = pd.Timestamp(r.period_start), pd.Timestamp(r.period_end)
        if end < pd.Timestamp(as_of) or start > end_h:
            continue
        rate, meta = fx.rate(str(r.currency), inp.currency)
        if rate is None:
            raise PlanInputError(
                f"El presupuesto en {r.currency} no se puede convertir a {inp.currency}: falta una tasa de cambio "
                "explícita. No se genera un plan que pueda exceder el presupuesto.")
        bid = getattr(r, "budget_id", None)
        out.append(BudgetSpec(None if bid is None or pd.isna(bid) else int(bid),
                              None if pd.isna(getattr(r, "branch_id", None)) else int(r.branch_id),
                              start.date(), end.date(), float(r.amount) * rate))
    return out


def _build_production(inp, nodes, R, as_of, assumptions, limitations, pinfo, offers_by_product, cap_by_branch,
                      conversions, holding_rate, mult, priorities, T, undated_policy, overdue_policy, default_lead,
                      inv_idx, ss_idx):
    status = {"status": "not_configured", "reason": "No hay recetas de producción activas: la reposición se planifica sin producción.",
              "recipes": 0, "resources": 0}
    notes: list[str] = []
    if inp.recipes is None or inp.recipes.empty:
        return [], [], notes, status
    recipes_df = inp.recipes[inp.recipes.get("is_active", True) == True] if "is_active" in inp.recipes else inp.recipes  # noqa: E712
    if recipes_df.empty:
        return [], [], notes, status
    lines = inp.recipe_lines if inp.recipe_lines is not None else pd.DataFrame()
    outputs = set(recipes_df["output_product_id"].astype(int))
    specs: list[RecipeSpec] = []
    resource_ids: set[int] = set()
    node_idx = {n.key: n for n in nodes}
    for r in recipes_df.itertuples(index=False):
        ins = lines[lines["recipe_id"] == r.recipe_id] if not lines.empty else pd.DataFrame()
        if ins.empty:
            notes.append(f"Receta {r.code}: sin insumos definidos; se omite.")
            continue
        input_map = {int(x.input_product_id): float(x.quantity_per_batch) for x in ins.itertuples(index=False)}
        if any(pid in outputs for pid in input_map):
            notes.append(f"Receta {r.code}: un insumo es salida de otra receta; las recetas de varios niveles no están soportadas y se omite.")
            continue
        out_per_batch = float(r.output_quantity) * float(r.yield_rate)
        cost = _num(getattr(r, "cost_per_batch", None))
        if cost is None:
            notes.append(f"Receta {r.code}: costo de transformación desconocido; se trata como 0 en el objetivo (el plan de producción es más barato de lo real).")
        rid = None if pd.isna(getattr(r, "resource_id", None)) else int(r.resource_id)
        if rid is not None:
            resource_ids.add(rid)
        specs.append(RecipeSpec(
            recipe_id=int(r.recipe_id), code=str(r.code), output_product_id=int(r.output_product_id),
            branch_id=None if pd.isna(getattr(r, "branch_id", None)) else int(r.branch_id),
            output_per_batch=out_per_batch, inputs=input_map, production_days=int(r.production_days),
            resource_id=rid, resource_usage=float(getattr(r, "resource_usage_per_batch", 0.0) or 0.0),
            cost_per_batch=cost, max_batches=_num(getattr(r, "max_batches_per_period", None)),
            integer_batches=bool(inp.options.get("batch_integer", inp.config.get("batch_integer", True)))))
    if not specs:
        status["reason"] = "Las recetas existentes no son utilizables (ver limitaciones)."
        return [], [], notes, status
    # Nodos derivados: insumos sin pronóstico propio.
    existing = {n.key for n in nodes}
    branches = sorted({n.branch_id for n in nodes})
    for s in specs:
        for b in branches:
            if not (s.branch_id is None or s.branch_id == b):
                continue
            if (b, s.output_product_id) not in existing:
                continue
            horizon = node_idx[(b, s.output_product_id)].horizon
            for pid in s.inputs:
                if (b, pid) in existing:
                    continue
                offs = [o for o in offers_by_product.get(pid, []) if o.applies_to(b)]
                if not offs:
                    notes.append(f"Insumo {pid} en sucursal {b}: sin oferta de compra; la receta {s.code} depende de existencias.")
                opening = 0.0
                if inv_idx is not None and (b, pid) in inv_idx.index:
                    row = inv_idx.loc[(b, pid)]
                    row = row.iloc[-1] if isinstance(row, pd.DataFrame) else row
                    opening = max(float(row["on_hand"]) - float(row.get("reserved") or 0.0), 0.0)
                else:
                    assumptions.append(f"Insumo {pid} en sucursal {b}: sin snapshot de inventario; se asume sin existencias (SUPUESTO).")
                ref = min((o.unit_cost for o in offs), default=0.0)
                unit = norm_unit(pinfo.loc[pid, "unit"]) if (not pinfo.empty and pid in pinfo.index) else None
                space = None
                if b in cap_by_branch:
                    space, _ = conversions.factor(pid, unit, cap_by_branch[b].unit, as_of)
                nodes.append(NodeSpec(
                    branch_id=b, product_id=pid, unit=unit, opening=opening, demand=np.zeros(horizon),
                    known_receipts=np.zeros(horizon), penalty=mult * ref * priorities.get(pid, 1.0),
                    holding=holding_rate / 365.0 * R * ref, safety_stock=0.0, space=space, derived=True,
                    meta={"penalty_source": "multiplier", "ss_source": None, "derived_input": True}))
                existing.add((b, pid))
                node_idx[(b, pid)] = nodes[-1]
    res_specs: list[ResourceSpec] = []
    if inp.resources is not None and not inp.resources.empty:
        for r in inp.resources.itertuples(index=False):
            if "is_active" in inp.resources.columns and not bool(r.is_active):
                continue
            res_specs.append(ResourceSpec(
                int(r.resource_id), str(r.code), None if pd.isna(getattr(r, "branch_id", None)) else int(r.branch_id),
                float(r.capacity_per_period) * (R / float(r.period_days or R)), norm_unit(getattr(r, "capacity_unit", None))))
    used_res = {s.resource_id for s in specs if s.resource_id is not None}
    missing = used_res - {r.resource_id for r in res_specs}
    if missing:
        notes.append(f"Recursos {sorted(missing)} sin definición activa: se omiten las recetas que los usan.")
        specs = [s for s in specs if s.resource_id not in missing]
    status = {"status": "configured", "recipes": len(specs), "resources": len(res_specs),
              "note": "Recetas de un nivel; producción por lotes enteros con rendimiento aplicado a la producción nominal."}
    return specs, res_specs, notes, status
