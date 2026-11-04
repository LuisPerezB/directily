"""Evaluación de los gates por módulo: PASS, WARN, DEGRADE o BLOCK con motivos de código estable.

`evaluate(module, metrics, cfg)` recorre las reglas del módulo (`quality.rules`), compara cada métrica con su umbral efectivo y devuelve
un `GateResult`. El resultado es la PEOR severidad entre los motivos; los datos que una regla DEGRADE declara insuficientes determinan
las features deshabilitadas (`quality.features`). Una métrica no disponible no pasa en silencio: la regla queda en `not_evaluated` con
su razón (`no_data`, `not_collected`, `signal_failed`, `metrics_error`); si la causa es un FALLO y la regla era BLOCK o DEGRADE, el gate
emite además un motivo WARN de código estable (`dq_signal_unavailable` / `dq_metrics_unavailable`), de modo que el resultado nunca es un
PASS sin aviso.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from predictily_engine.quality.features import features_disabled_by
from predictily_engine.quality.metrics import get_path
from predictily_engine.quality.rules import (
    BLOCK,
    DEGRADE,
    METRICS_UNAVAILABLE_CODE,
    OUTCOME_RANK,
    PASS,
    SIGNAL_UNAVAILABLE_CODE,
    WARN,
    Rule,
    rules_for,
)


def _percent_key(key: str) -> bool:
    return key.endswith(("_pct", "_share", "_coverage"))


def fmt_number(threshold_key: str, value: float) -> str:
    """Texto de un valor para los mensajes: fracciones como porcentaje (coma decimal) y el resto como número compacto."""
    if _percent_key(threshold_key):
        return f"{value * 100:.1f} %".replace(".", ",")
    return f"{value:g}".replace(".", ",")


@dataclass
class GateResult:
    module: str
    outcome: str = PASS
    reasons: list[dict[str, Any]] = field(default_factory=list)
    disabled_features: list[str] = field(default_factory=list)
    not_evaluated: list[dict[str, Any]] = field(default_factory=list)

    def as_dict(self) -> dict[str, Any]:
        return {"outcome": self.outcome, "reasons": self.reasons, "disabled_features": self.disabled_features,
                "not_evaluated": self.not_evaluated}

    def reasons_of(self, severity: str) -> list[dict[str, Any]]:
        return [r for r in self.reasons if r["severity"] == severity]

    @property
    def codes(self) -> list[str]:
        return [r["code"] for r in self.reasons]


def _passes(rule: Rule, value: float, threshold: float) -> bool:
    return value >= threshold if rule.op == ">=" else value <= threshold


def _classify(section: dict[str, Any]) -> tuple[str, str | None]:
    reason = str(section.get("reason") or "") or None
    if section.get("failed"):
        return "signal_failed", reason
    return ("not_collected" if reason == "signals_not_collected" else "no_data"), reason


def _unavailable_reason(metrics: dict[str, Any], path: str) -> tuple[str, str | None]:
    """Por qué una métrica no tiene valor: `metrics_error`, `signal_failed`, `not_collected` o `no_data`, con el detalle de la sección."""
    if metrics.get("error"):
        return "metrics_error", str(metrics["error"])
    cur: Any = metrics
    for part in path.split("."):
        if not isinstance(cur, dict):
            break
        if cur.get("available") is False:
            return _classify(cur)
        cur = cur.get(part)
    if isinstance(cur, dict) and cur.get("available") is False:
        return _classify(cur)
    return "no_data", None


def evaluate(module: str, metrics: dict[str, Any], cfg: dict[str, Any]) -> GateResult:
    reasons: list[dict[str, Any]] = []
    not_evaluated: list[dict[str, Any]] = []
    insufficient: set[str] = set()
    for rule in rules_for(module):
        value = get_path(metrics, rule.metric)
        if value is None or isinstance(value, (bool, dict, list, str)):
            why, detail = _unavailable_reason(metrics, rule.metric)
            not_evaluated.append({"code": rule.code, "metric": rule.metric, "severity": rule.severity, "reason": why,
                                  **({"detail": detail} if detail else {})})
            continue
        threshold = cfg[rule.threshold]
        if _passes(rule, float(value), float(threshold)):
            continue
        reasons.append({
            "code": rule.code, "severity": rule.severity, "metric": rule.metric, "value": value, "op": rule.op,
            "threshold_key": rule.threshold, "threshold": threshold,
            "message": rule.message.format(value=fmt_number(rule.threshold, float(value)), threshold=fmt_number(rule.threshold, float(threshold))),
            **({"data_kind": rule.data_kind} if rule.data_kind else {})})
        if rule.severity == DEGRADE and rule.data_kind:
            insufficient.add(rule.data_kind)
    # Lo que no se pudo evaluar por un FALLO (no por falta de datos) y habría podido bloquear o degradar no pasa en silencio: WARN estable.
    failed = [n for n in not_evaluated if n["reason"] in ("signal_failed", "metrics_error") and n["severity"] in (BLOCK, DEGRADE)]
    if metrics.get("error"):
        reasons.append({
            "code": METRICS_UNAVAILABLE_CODE, "severity": WARN, "metric": None, "value": None,
            "message": (f"No se pudieron calcular las métricas de calidad de datos ({metrics['error']}): los gates de {module} no se evaluaron. "
                        "La ejecución continúa sin esa verificación."),
            "rules": sorted(n["code"] for n in not_evaluated)})
    elif failed:
        signals = sorted({str(n.get("detail") or n["metric"]) for n in failed})
        reasons.append({
            "code": SIGNAL_UNAVAILABLE_CODE, "severity": WARN, "metric": None, "value": None,
            "message": (f"No se pudo leer una señal de calidad de datos ({', '.join(signals)}): {len(failed)} regla(s) BLOCK o DEGRADE de {module} "
                        "no se evaluaron. La ejecución continúa sin esa verificación."),
            "rules": sorted(n["code"] for n in failed)})
    reasons.sort(key=lambda r: (-OUTCOME_RANK[r["severity"]], r["code"]))
    outcome = max((r["severity"] for r in reasons), key=lambda s: OUTCOME_RANK[s], default=PASS)
    disabled = features_disabled_by(module, insufficient) if insufficient else []
    return GateResult(module, outcome, reasons, disabled, not_evaluated)


__all__ = ["BLOCK", "DEGRADE", "GateResult", "evaluate", "fmt_number"]
