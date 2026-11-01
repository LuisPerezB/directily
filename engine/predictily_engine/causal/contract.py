"""Verificación de requisitos para publicar un efecto causal del precio sobre la demanda.

Predictily NO habilita ningún estimador causal en esta etapa. Esta verificación existe para que, cuando haya
evidencia, la decisión de habilitar un estimador (p. ej. LinearDML) sea explícita y reproducible:

1. Definición del efecto: tratamiento, resultado, unidad de análisis, estimando, horizonte y población.
2. Variación de precios: niveles distintos, rango relativo y número de cambios independientes.
3. Soporte: observaciones suficientes en cada nivel y sin extrapolar fuera del rango observado.
4. Confusores: observables vs no observables (promociones, estacionalidad, quiebres de stock, costos, competencia).
5. Disponibilidad: el precio y sus cambios deben conocerse al momento de la decisión (`known_at`).
6. Supuestos de identificación: ignorabilidad condicional, positividad, ausencia de interferencia y exogeneidad del
   precio; NO son verificables con los datos y deben declararse.
7. Incertidumbre: intervalos válidos y límites de extrapolación (solo los entrega un estimador habilitado).

El buen error predictivo de un modelo (p. ej. la importancia de una variable en LightGBM) NO es evidencia causal.
Respuesta: `insufficient_evidence`; la simulación condicionada (/scenarios/evaluate) sigue disponible.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import numpy as np

CONTRACT_VERSION = "causal-contract-2026.10"
IDENTIFICATION_ASSUMPTIONS = (
    "conditional_ignorability",  # dado el conjunto de confusores, el precio es independiente de la demanda potencial
    "positivity",                # todos los niveles de precio relevantes ocurren con probabilidad > 0 en cada estrato
    "no_interference",           # el precio de un producto/sucursal no altera la demanda de otra unidad analizada
    "price_exogeneity",          # los cambios de precio no se fijaron reaccionando a la demanda observada
)
OBSERVABLE_CONFOUNDERS = ("promotions", "calendar_seasonality", "stock_availability", "own_cost_changes")
UNOBSERVABLE_CONFOUNDERS = ("competitor_prices", "macro_conditions", "marketing_other_than_promotions")
DEFAULTS = {"min_weeks": 52, "min_levels": 3, "min_relative_range": 0.10, "min_changes": 4, "min_weeks_per_level": 8,
            "level_bin_pct": 0.02, "max_promo_overlap_share": 0.5, "max_stockout_share": 0.2}
REQUIRED_DEFINITION = ("treatment", "outcome", "unit_of_analysis", "estimand", "horizon_days", "population")


@dataclass
class CausalEvidence:
    """Resumen observable de un alcance (producto o conjunto)."""

    weekly: Any  # DataFrame: week, units, price, promo (0/1), stockout_share
    price_known_at_available: bool
    promotions_known_at_available: bool
    n_products: int = 1
    notes: list[str] = field(default_factory=list)


def _req(rid: str, label: str, status: str, evidence: dict | None = None, fix: str | None = None) -> dict[str, Any]:
    return {"id": rid, "label": label, "status": status, "evidence": evidence or {}, "how_to_fix": fix}


def check_requirements(request: dict[str, Any], evidence: CausalEvidence | None, cfg: dict[str, Any] | None = None) -> dict[str, Any]:
    c = {**DEFAULTS, **(cfg or {})}
    reqs: list[dict[str, Any]] = []

    # 1. Definición del efecto
    missing = [k for k in REQUIRED_DEFINITION if not request.get(k)]
    reqs.append(_req("effect_definition", "Definición del efecto", "met" if not missing else "not_met",
                     {"missing_fields": missing, "defined": {k: request.get(k) for k in REQUIRED_DEFINITION}},
                     None if not missing else "Defina tratamiento, resultado, unidad de análisis, estimando, horizonte y población."))

    # 2-3. Variación y soporte
    if evidence is None or evidence.weekly is None or len(evidence.weekly) == 0:
        reqs.append(_req("price_variation", "Variación de precios", "not_met", {"weeks": 0},
                         "No hay ventas con precio en el alcance indicado."))
        reqs.append(_req("support", "Soporte de precios", "not_met", {"weeks": 0}, "Se requieren observaciones por nivel de precio."))
    else:
        w = evidence.weekly
        price = w["price"].to_numpy(dtype=float)
        price = price[np.isfinite(price) & (price > 0)]
        weeks = len(price)
        med = float(np.median(price)) if weeks else 0.0
        bins = np.round(price / max(med, 1e-9) / c["level_bin_pct"]).astype(int) if weeks else np.array([])
        levels, counts = np.unique(bins, return_counts=True) if weeks else (np.array([]), np.array([]))
        rel_range = float((price.max() - price.min()) / med) if weeks and med > 0 else 0.0
        changes = int(np.sum(np.abs(np.diff(price)) / np.maximum(price[:-1], 1e-9) > c["level_bin_pct"])) if weeks > 1 else 0
        var_ok = weeks >= c["min_weeks"] and len(levels) >= c["min_levels"] and rel_range >= c["min_relative_range"] \
            and changes >= c["min_changes"]
        reqs.append(_req(
            "price_variation", "Variación de precios", "met" if var_ok else "not_met",
            {"weeks": weeks, "distinct_levels": int(len(levels)), "relative_range": round(rel_range, 4), "price_changes": changes,
             "thresholds": {k: c[k] for k in ("min_weeks", "min_levels", "min_relative_range", "min_changes")}},
            None if var_ok else "Se necesita más historia con cambios de precio distintos y repetidos (no un único cambio)."))
        thick = int(np.sum(counts >= c["min_weeks_per_level"])) if len(counts) else 0
        target = request.get("price_change_pct")
        extrap = None
        if target is not None and weeks and med > 0:
            lo, hi = float(price.min()), float(price.max())
            cur = float(price[-1])
            extrap = not (lo <= cur * (1 + float(target)) <= hi)
        sup_ok = thick >= c["min_levels"] and extrap is not True
        reqs.append(_req(
            "support", "Soporte de precios (sin extrapolar)", "met" if sup_ok else "not_met",
            {"levels_with_enough_weeks": thick, "min_weeks_per_level": c["min_weeks_per_level"],
             "target_outside_observed_range": extrap},
            None if sup_ok else "Faltan observaciones en cada nivel o el cambio pedido queda fuera del rango observado (extrapolación)."))

    # 4. Confusores
    declared = set(request.get("confounders_controlled") or [])
    unobs_declared = set(request.get("unobserved_confounders_acknowledged") or [])
    obs_missing = [x for x in OBSERVABLE_CONFOUNDERS if x not in declared]
    conf_info: dict[str, Any] = {"observable_required": list(OBSERVABLE_CONFOUNDERS), "observable_not_controlled": obs_missing,
                                 "unobservable_without_data": [x for x in UNOBSERVABLE_CONFOUNDERS],
                                 "unobservable_acknowledged": sorted(unobs_declared)}
    if evidence is not None and evidence.weekly is not None and len(evidence.weekly):
        w = evidence.weekly
        promo_share = float(w["promo"].mean()) if "promo" in w else None
        stock_share = float(w["stockout_share"].mean()) if "stockout_share" in w else None
        # Cambios de precio que coinciden con una promoción: el precio y la promoción no se pueden separar.
        pr = w["price"].to_numpy(dtype=float)
        ch = np.where(np.abs(np.diff(pr)) / np.maximum(pr[:-1], 1e-9) > c["level_bin_pct"])[0] + 1 if len(pr) > 1 else np.array([])
        overlap = float(np.mean(w["promo"].to_numpy()[ch])) if len(ch) and "promo" in w else None
        conf_info.update({"promotion_week_share": promo_share, "stockout_week_share": stock_share,
                          "price_changes_overlapping_promotions": overlap})
        entangled = overlap is not None and overlap > c["max_promo_overlap_share"]
        censored = stock_share is not None and stock_share > c["max_stockout_share"]
    else:
        entangled = censored = False
    conf_ok = not obs_missing and not entangled and not censored and set(UNOBSERVABLE_CONFOUNDERS) <= unobs_declared
    reasons = []
    if obs_missing:
        reasons.append("confusores observables sin controlar: " + ", ".join(obs_missing))
    if entangled:
        reasons.append("los cambios de precio coinciden con promociones (no se pueden separar)")
    if censored:
        reasons.append("demasiadas semanas con quiebre de stock: la demanda observada está censurada")
    if not set(UNOBSERVABLE_CONFOUNDERS) <= unobs_declared:
        reasons.append("confusores no observables sin reconocer: " + ", ".join(sorted(set(UNOBSERVABLE_CONFOUNDERS) - unobs_declared)))
    conf_info["problems"] = reasons
    reqs.append(_req("confounders", "Confusores", "met" if conf_ok else "not_met", conf_info,
                     None if conf_ok else "Controle los confusores observables, separe precio de promociones y declare los no observables."))

    # 5. Disponibilidad temporal
    avail_ok = evidence is not None and evidence.price_known_at_available and evidence.promotions_known_at_available
    reqs.append(_req("availability", "Disponibilidad de la información al momento de decidir",
                     "met" if avail_ok else "not_met",
                     {"price_known_at": getattr(evidence, "price_known_at_available", False),
                      "promotions_known_at": getattr(evidence, "promotions_known_at_available", False)},
                     None if avail_ok else "Registre cuándo se conoció cada precio/promoción (known_at); sin eso no se reconstruye lo que se sabía."))

    # 6. Supuestos de identificación (no verificables)
    ack = set(request.get("identification_assumptions_acknowledged") or [])
    missing_ack = [a for a in IDENTIFICATION_ASSUMPTIONS if a not in ack]
    reqs.append(_req("identification", "Supuestos de identificación", "declared_not_verifiable" if not missing_ack else "not_met",
                     {"required": list(IDENTIFICATION_ASSUMPTIONS), "acknowledged": sorted(ack), "missing": missing_ack,
                      "note": "Los supuestos no se pueden verificar con los datos: declararlos no los hace ciertos."},
                     None if not missing_ack else "Declare explícitamente los supuestos de identificación que sostiene."))

    # 7. Estimador
    reqs.append(_req("estimator", "Estimador con intervalos y límites de extrapolación", "not_met",
                     {"estimator_enabled": False, "candidates": ["LinearDML (a evaluar)"],
                      "note": "Ningún estimador causal está habilitado en esta etapa."},
                     "Se habilita solo tras cumplir los requisitos anteriores y validar el estimador con datos reales."))

    statuses = {r["id"]: r["status"] for r in reqs}
    data_requirements_met = all(statuses[k] == "met" for k in ("effect_definition", "price_variation", "support", "confounders", "availability")) \
        and statuses["identification"] == "declared_not_verifiable"
    reason_codes = [f"{r['id']}:{r['status']}" for r in reqs if r["status"] not in ("met", "declared_not_verifiable")]
    return {
        "contract_version": CONTRACT_VERSION,
        "verdict": "insufficient_evidence",
        "effect": None,
        "estimator_enabled": False,
        "eligible_for_estimator_evaluation": bool(data_requirements_met),
        "reason_codes": reason_codes,
        "requirements": reqs,
        "message": ("No se publica ningún efecto causal: "
                    + ("los datos y las declaraciones cumplen los requisitos para evaluar un estimador, pero ninguno está habilitado."
                       if data_requirements_met else "no se cumplen los requisitos de identificación (ver requisitos).")),
        "conditional_simulation": {"available": True, "endpoint": "/api/v1/supply/scenarios/evaluate",
                                   "note": "La simulación condicionada usa supuestos declarados; su cambio de demanda no se deriva del precio."},
        "predictive_vs_causal": "El error predictivo o la importancia de una variable en un modelo (p. ej. LightGBM) no estiman "
                                "la reacción causal de la demanda al precio.",
    }
