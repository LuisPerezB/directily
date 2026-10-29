"""Normalización y comparación de ofertas de proveedor (E2-26).

Cada oferta se lleva a unidades del producto, moneda del plan y costo puesto en bodega con reglas explícitas:

- unidad: `unit_conversions` (oferta → producto). Sin conversión ⇒ la oferta NO es comparable.
- moneda: `exchange_rates` explícitas con fecha y fuente. Sin tasa ⇒ NO comparable. Sin triangulación.
- gastos de adquisición explícitos: porcentaje, costo por unidad y costo fijo por línea de orden. El IVA
  recuperable no es un costo.
- vigencia y `known_at`: una oferta desconocida al corte (o con `known_at` posterior) no existe para ese corte.

La comparación declara su cobertura: cuántas ofertas se excluyeron y por qué, y qué productos tienen una sola
fuente o ninguna.
"""

from __future__ import annotations

import math
from datetime import date

import numpy as np
import pandas as pd

from predictily_engine.inventory.recommendations import resolve_lead_time
from predictily_engine.supply.availability import known_by, to_date
from predictily_engine.supply.fx import FxRates
from predictily_engine.supply.model import EPS, OfferSpec
from predictily_engine.supply.units import UnitConversions, norm_unit

REASON_TEXT = {
    "inactive": "La oferta está desactivada.",
    "unknown_availability": "Se desconoce desde cuándo se conocía la oferta (known_at) y el corte es retroactivo.",
    "after_cutoff": "La oferta se conoció después del corte.",
    "expired": "La oferta venció antes del corte.",
    "no_unit_conversion": "No hay conversión de unidades de la oferta a la del producto.",
    "no_fx_rate": "No hay tasa de cambio explícita hacia la moneda del plan.",
    "invalid_price": "El precio de la oferta no es válido.",
    "invalid_lead_time": "El plazo de la oferta no es válido.",
}


def _f(v) -> float | None:
    try:
        return None if v is None or pd.isna(v) else float(v)
    except (TypeError, ValueError):
        return None


def resolve_offer_lead_time(supplier_id: int | None, product_id: int, branch_id: int | None, lead_times: pd.DataFrame,
                            suppliers: pd.DataFrame, default_days: int) -> tuple[int, float | None, str]:
    """Plazo de una oferta concreta. Prioridad: (proveedor+producto+sucursal) > (proveedor+producto) >
    (proveedor) > plazo por defecto del proveedor > valor de la empresa."""
    lt = lead_times if lead_times is not None else pd.DataFrame()
    if supplier_id is not None and not lt.empty:
        sup = lt[lt["supplier_id"] == supplier_id]
        prod = sup[sup["product_id"] == product_id]
        if branch_id is not None:
            pb = prod[prod["branch_id"] == branch_id]
            if not pb.empty:
                r = pb.iloc[-1]
                return int(r["lead_time_days"]), _f(r.get("lead_time_std_days")), "supplier_product_branch"
        pn = prod[prod["branch_id"].isna()]
        if not pn.empty:
            r = pn.iloc[-1]
            return int(r["lead_time_days"]), _f(r.get("lead_time_std_days")), "supplier_product"
        s_only = sup[sup["product_id"].isna() & sup["branch_id"].isna()]
        if not s_only.empty:
            r = s_only.iloc[-1]
            return int(r["lead_time_days"]), _f(r.get("lead_time_std_days")), "supplier"
    if supplier_id is not None and suppliers is not None and not suppliers.empty:
        srow = suppliers[suppliers["supplier_id"] == supplier_id]
        if not srow.empty and pd.notna(srow.iloc[0]["default_lead_time_days"]):
            return int(srow.iloc[0]["default_lead_time_days"]), None, "supplier_default"
    if branch_id is not None:
        return resolve_lead_time(product_id, branch_id, lt, suppliers, default_days)
    return int(default_days), None, "tenant_default"


def normalize_offers(offers: pd.DataFrame | None, products: pd.DataFrame, conversions: UnitConversions, fx: FxRates,
                     lead_times: pd.DataFrame, suppliers: pd.DataFrame, as_of: date, retroactive: bool,
                     plan_currency: str, period_days: int, default_lead_days: int, branch_ids: list[int],
                     lead_evidence: pd.DataFrame | None = None) -> tuple[list[OfferSpec], list[dict]]:
    """Devuelve (ofertas comparables, entradas del informe: una por oferta recibida)."""
    out: list[OfferSpec] = []
    report: list[dict] = []
    if offers is None or offers.empty:
        return out, report
    pinfo = products.set_index("product_id") if not products.empty else pd.DataFrame()
    for r in offers.itertuples(index=False):
        reasons: list[str] = []
        entry = {"offer_id": _i(r.offer_id), "supplier_id": _i(r.supplier_id), "product_id": _i(r.product_id),
                 "branch_id": _i(getattr(r, "branch_id", None)), "status": "comparable", "reasons": [],
                 "messages": []}
        if not bool(getattr(r, "is_active", True)):
            reasons.append("inactive")
        ok, why = known_by(getattr(r, "known_at", None), as_of, retroactive)
        if not ok:
            reasons.append("unknown_availability" if why == "unknown_availability" else "after_cutoff")
        vto = to_date(getattr(r, "valid_to", None))
        if vto is not None and vto < as_of:
            reasons.append("expired")
        price = _f(r.unit_cost)
        if price is None or price < 0:
            reasons.append("invalid_price")
        pid = int(r.product_id)
        product_unit = pinfo.loc[pid, "unit"] if (not pinfo.empty and pid in pinfo.index) else None
        factor, factor_src = conversions.factor(pid, getattr(r, "unit", None), product_unit, as_of)
        if factor is None or factor <= 0:
            reasons.append("no_unit_conversion")
            entry["unit_detail"] = {"offer_unit": getattr(r, "unit", None), "product_unit": product_unit}
        fx_rate, fx_meta = fx.rate(str(r.currency), plan_currency)
        if fx_rate is None:
            reasons.append("no_fx_rate")
            entry["fx_detail"] = {"from": str(r.currency), "to": plan_currency}
        lead_override = _f(getattr(r, "lead_time_days", None))
        if lead_override is not None and lead_override < 1:
            reasons.append("invalid_lead_time")
        if reasons:
            entry["status"] = "excluded"
            entry["reasons"] = reasons
            entry["messages"] = [REASON_TEXT[x] for x in reasons]
            report.append(entry)
            continue

        pct = _f(getattr(r, "acquisition_cost_pct", 0.0)) or 0.0
        per_unit = _f(getattr(r, "acquisition_cost_per_unit", 0.0)) or 0.0
        fixed = _f(getattr(r, "fixed_order_cost", 0.0)) or 0.0
        landed_offer_unit = price * (1.0 + pct) + per_unit  # moneda de la oferta, por unidad de la oferta
        base_unit_cost = price / factor * fx_rate
        landed = landed_offer_unit / factor * fx_rate
        mult = _f(getattr(r, "order_multiple", None))
        lot = (mult if mult and mult > 0 else 1.0) * factor
        moq_offer = _f(getattr(r, "min_order_qty", None))
        moq = (moq_offer or 0.0) * factor
        cap = _f(getattr(r, "capacity_per_period", None))
        cap_days = _f(getattr(r, "capacity_period_days", None)) or period_days
        capacity = None if cap is None else cap * factor * (period_days / cap_days)
        std = _f(getattr(r, "lead_time_std_days", None))
        lead_by_branch: dict[int, int] = {}
        if lead_override is not None:
            lead_src = "offer"
            default_lead = int(lead_override)
            lead_by_branch = {b: default_lead for b in branch_ids}
        else:
            lead_src = "tenant_default"
            for b in branch_ids:
                d, s_, lead_src = resolve_offer_lead_time(_i(r.supplier_id), pid, b, lead_times, suppliers,
                                                          default_lead_days)
                lead_by_branch[b] = d
                std = std if std is not None else s_
            if lead_by_branch:
                default_lead = max(lead_by_branch.values())
            else:
                default_lead, s_, lead_src = resolve_offer_lead_time(_i(r.supplier_id), pid, None, lead_times,
                                                                     suppliers, default_lead_days)
                std = std if std is not None else s_
        if std is None and lead_evidence is not None and not lead_evidence.empty and _i(r.supplier_id) is not None:
            ev = lead_evidence[lead_evidence["supplier_id"] == _i(r.supplier_id)]
            if not ev.empty and pd.notna(ev.iloc[0]["std_lead"]):
                std = float(ev.iloc[0]["std_lead"])
                lead_src += "+observed_std"
        spec = OfferSpec(
            key=f"o{int(r.offer_id)}", offer_id=int(r.offer_id), supplier_id=_i(r.supplier_id), product_id=pid,
            branch_id=_i(getattr(r, "branch_id", None)), unit_cost=landed, base_unit_cost=base_unit_cost, lot=lot,
            moq=moq, fixed_cost=fixed * fx_rate, capacity=capacity, default_lead=default_lead,
            lead_by_branch=lead_by_branch, lead_std=std, valid_from=to_date(getattr(r, "valid_from", None)),
            valid_to=vto, implicit=False, offer_unit=norm_unit(getattr(r, "unit", None)), offer_factor=factor,
            meta={"currency": str(r.currency), "fx": fx_meta, "unit_source": factor_src, "lead_source": lead_src,
                  "min_order_qty_offer_units": moq_offer, "order_multiple_offer_units": mult,
                  "acquisition_cost_pct": pct, "acquisition_cost_per_unit": per_unit,
                  "capacity_prorated": cap is not None and cap_days != period_days})
        out.append(spec)
        entry.update({"landed_unit_cost": round(landed, 6), "lead_days": default_lead,
                      "unit_source": factor_src, "fx": fx_meta})
        report.append(entry)
    return out, report


def implicit_offers(products_without_offers: list[int], products: pd.DataFrame, cost_by_product: dict[int, dict],
                    lead_times: pd.DataFrame, suppliers: pd.DataFrame, default_lead_days: int, branch_ids: list[int],
                    plan_currency: str, start_id: int = 0) -> tuple[list[OfferSpec], list[dict]]:
    """Ofertas implícitas para productos sin ofertas explícitas: costo conocido del catálogo/compras y plazo
    resuelto como en la etapa 1. Sin mínimo, múltiplo ni capacidad (desconocidos). Se marcan `implicit`."""
    out: list[OfferSpec] = []
    report: list[dict] = []
    pinfo = products.set_index("product_id") if not products.empty else pd.DataFrame()
    for i, pid in enumerate(products_without_offers):
        info = cost_by_product.get(pid)
        if not info or info.get("unit_cost") is None or not math.isfinite(info["unit_cost"]):
            report.append({"offer_id": None, "supplier_id": None, "product_id": pid, "status": "excluded",
                           "implicit": True, "reasons": ["no_cost_known"],
                           "messages": ["El producto no tiene ofertas ni costo conocido: no se puede planificar su compra."]})
            continue
        supplier_id = info.get("supplier_id")
        if supplier_id is None and lead_times is not None and not lead_times.empty:
            cand = lead_times[(lead_times["product_id"] == pid) & lead_times["supplier_id"].notna()]
            if not cand.empty:
                supplier_id = int(cand.iloc[-1]["supplier_id"])
        lead_by_branch = {}
        std = None
        src = "tenant_default"
        for b in branch_ids:
            d, s, src = resolve_lead_time(pid, b, lead_times, suppliers, default_lead_days)
            lead_by_branch[b] = d
            std = std if std is not None else s
        spec = OfferSpec(
            key=f"i{start_id + i}", offer_id=None, supplier_id=supplier_id, product_id=pid, branch_id=None,
            unit_cost=float(info["unit_cost"]), base_unit_cost=float(info["unit_cost"]), lot=1.0, moq=0.0,
            fixed_cost=0.0, capacity=None, default_lead=max(lead_by_branch.values()) if lead_by_branch else default_lead_days,
            lead_by_branch=lead_by_branch, lead_std=std, implicit=True,
            offer_unit=norm_unit(pinfo.loc[pid, "unit"]) if (not pinfo.empty and pid in pinfo.index) else None,
            offer_factor=1.0, meta={"currency": plan_currency, "cost_source": info.get("source"), "lead_source": src})
        out.append(spec)
        report.append({"offer_id": None, "supplier_id": supplier_id, "product_id": pid, "status": "comparable",
                       "implicit": True, "reasons": [], "messages": [], "landed_unit_cost": round(spec.unit_cost, 6),
                       "lead_days": spec.default_lead})
    return out, report


def comparison_coverage(report: list[dict], products_with_demand: list[int]) -> dict:
    """Cobertura de la comparación: qué productos tienen 0, 1 o ≥2 ofertas comparables y qué se excluyó."""
    by_product: dict[int, dict] = {}
    for e in report:
        d = by_product.setdefault(e["product_id"], {"total": 0, "comparable": 0, "excluded": []})
        d["total"] += 1
        if e["status"] == "comparable":
            d["comparable"] += 1
        else:
            d["excluded"].append({"offer_id": e.get("offer_id"), "supplier_id": e.get("supplier_id"),
                                  "reasons": e["reasons"]})
    none = [p for p in products_with_demand if by_product.get(p, {}).get("comparable", 0) == 0]
    single = [p for p, d in by_product.items() if d["comparable"] == 1]
    multi = [p for p, d in by_product.items() if d["comparable"] >= 2]
    total = sum(d["total"] for d in by_product.values())
    comp = sum(d["comparable"] for d in by_product.values())
    return {
        "offers_received": total, "offers_comparable": comp, "offers_excluded": total - comp,
        "comparable_share": round(comp / total, 4) if total else None,
        "products_with_demand": len(products_with_demand),
        "products_without_comparable_offer": sorted(none),
        "products_single_source": sorted(single),
        "products_with_alternatives": sorted(multi),
        "exclusions": {p: d["excluded"] for p, d in by_product.items() if d["excluded"]},
        "note": "Solo se comparan ofertas con unidad convertible y tasa de cambio explícita; las demás quedan fuera.",
    }


def _i(v) -> int | None:
    try:
        return None if v is None or pd.isna(v) else int(v)
    except (TypeError, ValueError):
        return None


def standalone_offer_diagnostics(node, offers: list[OfferSpec], period_days: int) -> list[dict]:
    """Diagnóstico por oferta de un producto: costo, llegada más temprana y demanda NO cubierta antes de esa
    llegada (existencias + recepciones ya abiertas). Muestra por qué una oferta barata y lenta no es gratis."""
    from predictily_engine.supply.model import arrival_period

    rows = []
    cum_d = np.cumsum(node.demand)
    for o in offers:
        L = o.lead(node.branch_id)
        first = arrival_period(0, L, period_days)
        cover_to = min(first, node.horizon)
        supply_before = node.opening + float(node.known_receipts[:cover_to].sum())
        demand_before = float(cum_d[cover_to - 1]) if cover_to > 0 else 0.0
        unmet_before = max(0.0, demand_before - supply_before)
        rows.append({
            "offer_id": o.offer_id, "supplier_id": o.supplier_id, "landed_unit_cost": round(o.unit_cost, 6),
            "lead_days": L, "first_available_period": int(first), "unmet_before_arrival_units": round(unmet_before, 4),
            "penalty_before_arrival": round(unmet_before * node.penalty, 4),
        })
    rows.sort(key=lambda r: r["landed_unit_cost"])
    for rank, r in enumerate(rows, start=1):
        r["rank_by_price"] = rank
    return rows
