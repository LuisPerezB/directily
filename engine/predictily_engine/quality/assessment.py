"""Evaluación de calidad por módulo (demanda, Customer, abastecimiento) y su resultado persistible.

`assess_demand`, `assess_customer` y `assess_supply` calculan las métricas del dataset al corte, evalúan el gate del módulo con la
política efectiva de la empresa (`quality_gates`: core → perfil → empresa) y devuelven un `QualityAssessment`. Los puntos de entrada
de los módulos solo llaman a estas funciones al inicio y actúan sobre el resultado:

- BLOCK       → termina la ejecución con `quality_status = insufficient_data` y los códigos de motivo (`block_message`, `block_details`).
- DEGRADE     → deshabilita las features de `disabled_features` (ver `quality.features`) y lo registra en limitaciones y manifiesto.
- WARN        → continúa; los avisos van a `warnings`/manifiesto.
- PASS        → continúa sin cambios.
"""

from __future__ import annotations

import hashlib
import json
import logging
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import date
from typing import Any

from predictily_engine.platform.config import effective_config_record
from predictily_engine.quality import metrics as m
from predictily_engine.quality.features import DEGRADABLE_FEATURES
from predictily_engine.quality.gates import GateResult, evaluate
from predictily_engine.quality.rules import (
    BLOCK,
    DEGRADE,
    GENERATED_CODES,
    METRICS_SCHEMA,
    PASS,
    RULES,
    RULES_CODE_VERSION,
    WARN,
)

log = logging.getLogger(__name__)
CUSTOMER_INDIVIDUAL_FEATURES = ("customer.individual_predictions", "customer.individual_rankings", "customer.activity_risk")


@dataclass(frozen=True)
class QualityPolicy:
    """Umbrales efectivos de los gates de una empresa (`platform.config`, core `quality_gates`) con su procedencia."""

    config: dict[str, Any]
    config_version: str
    provenance: dict[str, str]
    profile: dict[str, Any] | None = None

    @classmethod
    def resolve(cls, tenant_settings: dict[str, Any] | None, sector: str | None = None,
                tenant_overrides: dict[str, Any] | None = None) -> "QualityPolicy":
        """Lanza `ConfigValidationError` si la empresa trae claves desconocidas o valores fuera de rango (nunca se aplican en silencio)."""
        record = effective_config_record("quality_gates", tenant_settings, sector, tenant_overrides)
        return cls(record["effective_config"], record["config_version"], record["provenance"], record["profile"])

    @property
    def overridden(self) -> list[str]:
        """Claves cuyo valor no es el del core (las fija un perfil sectorial o la empresa)."""
        return sorted(k for k, src in self.provenance.items() if src != "core")

    @property
    def rules_version(self) -> str:
        """SHA-256 de la versión del código de reglas, el esquema de métricas, las reglas, el mapa de features y los umbrales efectivos."""
        payload = {
            "code": RULES_CODE_VERSION, "metrics_schema": METRICS_SCHEMA,
            "rules": [[r.code, r.module, r.severity, r.metric, r.op, r.threshold, r.data_kind] for r in RULES],
            "features": [[f.name, f.module, list(f.requires), f.effect] for f in DEGRADABLE_FEATURES],
            "generated": list(GENERATED_CODES),
            "thresholds": self.config}
        return hashlib.sha256(json.dumps(payload, sort_keys=True, default=str).encode()).hexdigest()


@dataclass
class QualityAssessment:
    module: str
    dataset: str
    as_of: date | None
    metrics: dict[str, Any]
    gate: GateResult
    policy: QualityPolicy
    signals_collected: bool = False
    applied_changes: list[str] = field(default_factory=list)   # efectos aplicados (p. ej. políticas de abastecimiento forzadas)
    metrics_error: str | None = None   # las métricas no se pudieron calcular: ningún gate se evaluó (no se da por aprobado) y se registra

    # ── resultado ──
    @property
    def outcome(self) -> str:
        return self.gate.outcome

    @property
    def blocked(self) -> bool:
        return self.gate.outcome == BLOCK

    @property
    def degraded(self) -> bool:
        return self.gate.outcome == DEGRADE

    @property
    def disabled_features(self) -> list[str]:
        return self.gate.disabled_features

    @property
    def rules_version(self) -> str:
        return self.policy.rules_version

    @property
    def quality_status_floor(self) -> str | None:
        """Estado mínimo que impone el gate al resultado: BLOCK → insufficient_data, DEGRADE → degraded, el resto no impone nada."""
        return {BLOCK: "insufficient_data", DEGRADE: "degraded"}.get(self.gate.outcome)

    @property
    def stops_individual_customer(self) -> bool:
        """Customer: BLOCK o DEGRADE a agregado ⇒ no se entrena ni se predice a nivel comprador."""
        return self.module == "customer" and (self.blocked or any(f in self.disabled_features for f in CUSTOMER_INDIVIDUAL_FEATURES))

    @property
    def individual_stop_code(self) -> str:
        """Código de motivo con que Customer se abstiene de las recomendaciones individuales."""
        return "dq_gate_block" if self.blocked else "dq_individual_recommendations_disabled"

    def individual_stop_message(self) -> str:
        """Motivo corto (con los códigos) de la abstención por horizonte; los mensajes completos van una sola vez en las limitaciones."""
        if self.blocked:
            return f"Calidad de datos insuficiente (gate customer: BLOCK): {', '.join(self.reason_codes(BLOCK))}."
        return ("Recomendaciones individuales deshabilitadas por la calidad de los datos (gate customer: DEGRADE a agregado; se conserva el "
                f"pronóstico agregado de demanda): {', '.join(self.reason_codes(DEGRADE))}.")

    def limitation_strings(self) -> list[str]:
        """Limitaciones de texto (Customer y abastecimiento): motivos BLOCK/DEGRADE y avisos de verificación no realizada como limitaciones,
        y los demás WARN como avisos."""
        out = [f"Calidad de datos [{r['code']}]: {r['message']}" for r in self.gate.reasons
               if r["severity"] in (DEGRADE, BLOCK) or r["code"] in GENERATED_CODES]
        out += [f"Aviso de calidad de datos [{r['code']}]: {r['message']}" for r in self.gate.reasons_of(WARN) if r["code"] not in GENERATED_CODES]
        return out

    def reason_codes(self, *severities: str) -> list[str]:
        return [r["code"] for r in self.gate.reasons if not severities or r["severity"] in severities]

    # ── textos para limitaciones, avisos y errores ──
    def block_message(self) -> str:
        reasons = "; ".join(f"{r['code']}: {r['message']}" for r in self.gate.reasons_of(BLOCK))
        return f"Calidad de datos insuficiente (gate {self.module}: BLOCK). {reasons}"

    def block_details(self) -> dict[str, Any]:
        return {"code": "dq_gate_block", "module": self.module, "reason_codes": self.reason_codes(BLOCK), "gate": self.gate.as_dict(),
                "rules_version": self.rules_version}

    def limitation_items(self) -> list[dict[str, str]]:
        """Limitaciones `{code, message}` de los motivos DEGRADE y BLOCK (código estable `dq_*`) y de los avisos de que una verificación
        no pudo hacerse (`dq_signal_unavailable`, `dq_metrics_unavailable`): esos NO son avisos menores, son límites de lo que se verificó."""
        out = []
        for r in self.gate.reasons:
            if r["severity"] not in (DEGRADE, BLOCK) and r["code"] not in GENERATED_CODES:
                continue
            message = r["message"]
            if r["severity"] == DEGRADE and self.gate.disabled_features:
                message += " Deshabilitado: " + ", ".join(self.gate.disabled_features) + "."
            out.append({"code": r["code"], "message": message})
        return out

    def warning_messages(self) -> list[str]:
        """Avisos WARN (sin los que ya viajan como limitación: `GENERATED_CODES`)."""
        return [f"[{r['code']}] {r['message']}" for r in self.gate.reasons_of(WARN) if r["code"] not in GENERATED_CODES]

    # ── bloques persistibles ──
    def gate_results(self) -> dict[str, Any]:
        """`data_quality_reports.gate_results`: `{módulo: {outcome, reasons, disabled_features, ...}}`."""
        return {self.module: {**self.gate.as_dict(), "config_version": self.policy.config_version,
                              "thresholds_overridden": self.policy.overridden, "applied_changes": self.applied_changes}}

    def manifest_block(self, report_id: int | None = None) -> dict[str, Any]:
        """Bloque del manifiesto del protocolo y del resumen de la ejecución: resultado del gate y de dónde sale."""
        block = {"module": self.module, "dataset": self.dataset, "as_of": self.as_of.isoformat() if self.as_of else None,
                 **self.gate.as_dict(), "rules_version": self.rules_version, "config_version": self.policy.config_version,
                 "thresholds_overridden": self.policy.overridden, "applied_changes": self.applied_changes,
                 "signals_collected": self.signals_collected, "metrics_error": self.metrics_error}
        if self.module == "customer" and self.degraded and self.stops_individual_customer:
            block["degraded_to"] = "aggregate"   # V2.5 §25.2: sin recomendaciones individuales; se conserva el Demand Core general
        if report_id is not None:
            block["report_id"] = report_id
        return block

    def report_summary(self) -> dict[str, Any]:
        return {"module": self.module, "dataset": self.dataset, "outcome": self.outcome, "quality_status": self.quality_status_floor,
                "reason_codes": self.reason_codes(), "disabled_features": self.disabled_features, "rules_version": self.rules_version,
                "config_version": self.policy.config_version, "metrics_error": self.metrics_error}

    def issues(self) -> list[dict[str, Any]]:
        """Motivos en la forma heredada de `data_quality_reports.issues`: `{code, severity, message, count}`."""
        sev = {BLOCK: "error", DEGRADE: "warning", WARN: "warning"}
        return [{"code": r["code"], "severity": sev[r["severity"]], "message": r["message"], "count": None, "gate_severity": r["severity"]}
                for r in self.gate.reasons]


def _assess(module: str, dataset: str, as_of: date | None, compute: Callable[[], dict[str, Any]], policy: QualityPolicy,
            collected: bool) -> QualityAssessment:
    """Calcula las métricas y evalúa el gate. Un fallo inesperado al calcular las métricas NO tumba la ejecución del módulo ni se da por
    «aprobado»: se registra (`metrics_error`, aviso `dq_metrics_unavailable`, reglas en `not_evaluated`) y se deja traza en el log."""
    error = None
    try:
        metrics = compute()
    except Exception as exc:  # noqa: BLE001
        log.exception("No se pudieron calcular las métricas de calidad (%s)", module)
        error = f"{type(exc).__name__}: {exc}"[:300]
        metrics = {"schema": METRICS_SCHEMA, "dataset": dataset, "error": error}
    return QualityAssessment(module, dataset, as_of, metrics, evaluate(module, metrics, policy.config), policy, collected, metrics_error=error)


def assess_demand(data: Any, panel: Any, policy: QualityPolicy | None = None, signals: dict[str, Any] | None = None) -> QualityAssessment:
    """Gate de demanda sobre `TenantData` + `Panel` (as-of al corte del panel). `signals`: `quality.signals.collect_signals` (opcional;
    sin ellas las reglas que dependen de la base quedan en `not_evaluated`)."""
    policy = policy or QualityPolicy.resolve(data.settings, data.sector)
    cutoff = panel.cutoff.date() if hasattr(panel.cutoff, "date") else panel.cutoff
    return _assess("demand", "sales", cutoff, lambda: m.sales_metrics(data, panel, signals), policy, signals is not None)


def assess_customer(data: Any, policy: QualityPolicy, signals: dict[str, Any] | None = None) -> QualityAssessment:
    """Gate del Customer Core sobre `CustomerData` (as-of a `data.through`)."""
    return _assess("customer", "customers", data.through,
                   lambda: m.customer_metrics(data, signals, id_lookback_days=int(policy.config["id_lookback_days"])), policy, signals is not None)


def assess_supply(inputs: Any, policy: QualityPolicy) -> QualityAssessment:
    """Gate de abastecimiento sobre `PlanInputs` (as-of a `inputs.as_of`). No necesita la base: todo viene en los insumos."""
    return _assess("supply", "supply", inputs.as_of, lambda: m.supply_metrics(inputs), policy, True)


__all__ = ["PASS", "QualityAssessment", "QualityPolicy", "assess_customer", "assess_demand", "assess_supply"]
