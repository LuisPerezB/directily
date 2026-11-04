"""Comparación de dos versiones de un modelo por horizonte y segmento (ADR-V25-19 `compare`; métricas de W3-A).

Cada lado es una versión del registro de la empresa (`--a V1 --b V2`) o un paquete local (`bundle:<carpeta>`). De cada lado se obtiene un resumen de métricas del
período final reservado (holdout), con el esquema `predictily-metrics-summary/1` (`mlops.training`):

- versión PUBLICADA por la CLI: el resumen guardado en `artifact_compat.evaluation_summary` (lo servido por la selección, por horizonte y segmento);
- versión del pipeline propio: las filas de `model_evaluations` enlazadas a su `model_registry_id` (la familia con sus propias cifras);
- paquete local: el resumen firmado del paquete (o el borrador `train.json`, avisando que no está firmado).

Solo se comparan las cifras presentes en AMBOS lados; lo que falta en uno se lista, nunca se rellena. El veredicto por métrica (menor error es mejor; sesgo en valor absoluto)
es descriptivo: NO es una recomendación de promover (la promoción es una decisión separada del backend, ADR-V25-07). Se avisa cuando los lados no son comparables
(otra base de la cifra, otra huella de datos o ningún horizonte en común).
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from sqlalchemy import Connection, text

from predictily_engine.mlops import policy
from predictily_engine.mlops.bundle import PACKAGE_FILE, TRAIN_FILE, read_json, read_package
from predictily_engine.mlops.errors import EXIT_CHECK_FAILED, EXIT_NOT_AUTHORIZED, EXIT_NOT_FOUND, CliError

LOWER_BETTER = {"wape", "mae", "rmse", "brier", "log_loss", "ece", "mase", "rmsse"}
ABS_LOWER_BETTER = {"bias", "mean_error"}
HIGHER_BETTER = {"pr_auc"}
IGNORED = {"n_obs", "n", "n_holdout", "n_holdout_positive", "reference_brier", "reference_log_loss", "reference_mae", "coverage_80", "coverage_95"}


def flatten(value: Any, prefix: str = "") -> dict[str, float]:
    """Hojas numéricas de un resumen anidado con su ruta (`selected.segment:demand_class:smooth:7.wape`)."""
    out: dict[str, float] = {}
    if isinstance(value, dict):
        for k, v in value.items():
            if k in ("schema", "core", "scope"):
                continue
            out.update(flatten(v, f"{prefix}.{k}" if prefix else str(k)))
    elif isinstance(value, (int, float)) and not isinstance(value, bool) and value == value and abs(value) != float("inf"):
        out[prefix] = float(value)
    return out


def _metric(path: str) -> str:
    return path.rsplit(".", 1)[-1]


def _better(metric: str, a: float, b: float) -> str | None:
    if metric in IGNORED:
        return None
    if metric in LOWER_BETTER:
        x, y = a, b
    elif metric in ABS_LOWER_BETTER:
        x, y = abs(a), abs(b)
    elif metric in HIGHER_BETTER:
        x, y = -a, -b
    else:
        return None
    if abs(x - y) <= 1e-12:
        return "tie"
    return "a" if x < y else "b"


def compare_summaries(a: dict[str, Any], b: dict[str, Any]) -> dict[str, Any]:
    fa, fb = flatten(a.get("summary") or {}), flatten(b.get("summary") or {})
    common = sorted(set(fa) & set(fb))
    rows, wins = [], {"a": 0, "b": 0, "tie": 0}
    for path in common:
        verdict = _better(_metric(path), fa[path], fb[path])
        rows.append({"metric": path, "a": fa[path], "b": fb[path], "delta_b_minus_a": round(fb[path] - fa[path], 10), "better": verdict})
        if verdict:
            wins[verdict] += 1
    warnings: list[str] = []
    if not common:
        warnings.append("Los dos lados no tienen ninguna cifra en común (otro core, otros horizontes u otro nivel): no son comparables.")
    if a.get("basis") != b.get("basis"):
        warnings.append(f"Las cifras tienen bases distintas ({a.get('basis')} frente a {b.get('basis')}): la comparación es orientativa.")
    if a.get("data_fingerprint") and b.get("data_fingerprint") and a["data_fingerprint"] != b["data_fingerprint"]:
        warnings.append("Se entrenaron con datos distintos (huella de datos diferente): una diferencia puede venir de los datos y no del modelo.")
    only_a, only_b = sorted(set(fa) - set(fb)), sorted(set(fb) - set(fa))
    return {"comparable": bool(common), "common_metrics": len(common), "wins": wins, "metrics": rows, "only_in_a": only_a[:50], "only_in_b": only_b[:50],
            "warnings": warnings, "note": "Veredicto descriptivo por métrica: no es una recomendación de promover; la promoción la decide la aprobación separada del backend."}


# ───────────────────────────── lados de la comparación ─────────────────────────────

def _num(value: Any) -> float | None:
    return float(value) if isinstance(value, (int, float)) and not isinstance(value, bool) and value == value else None


def _evaluation_rows_summary(conn: Connection, tenant_id: int, model_id: int, core: str) -> dict[str, Any]:
    """Resumen de un modelo del pipeline propio a partir de sus filas de `model_evaluations` (holdout), en la MISMA forma que el resumen de un paquete."""
    if core == "demand":
        rows = conn.execute(text(
            "SELECT level, level_key, horizon_days, wape, bias, mae, rmse, coverage_80, coverage_95, n_obs FROM model_evaluations "
            "WHERE tenant_id = :t AND model_registry_id = :m AND evaluation_type = 'backtest' AND extra->>'evaluation_scope' = 'holdout' ORDER BY id"),
            {"t": tenant_id, "m": model_id}).mappings().all()
        selected: dict[str, Any] = {}
        for r in rows:
            slot = ":".join(str(x) for x in (r["level"], r["level_key"], r["horizon_days"]) if x is not None) or "overall"
            selected[slot] = {k: _num(r[k]) for k in ("wape", "bias", "mae", "rmse", "coverage_80", "coverage_95")} | {"n_obs": int(r["n_obs"]) if r["n_obs"] is not None else None}
        return {"schema": "predictily-metrics-summary/1", "core": core, "scope": "holdout", "selected": selected}
    rows = conn.execute(text(
        "SELECT level_key, horizon_days, mae, bias, metrics FROM model_evaluations WHERE tenant_id = :t AND model_registry_id = :m AND evaluation_type = 'holdout' ORDER BY id"),
        {"t": tenant_id, "m": model_id}).mappings().all()
    horizons: dict[str, Any] = {}
    for r in rows:
        m = r["metrics"] if isinstance(r["metrics"], dict) else json.loads(r["metrics"] or "{}")
        h = str(r["horizon_days"])
        if str(r["level_key"]).endswith(":probability"):
            horizons.setdefault(h, {})["probability"] = {k: _num(m.get(k)) for k in ("brier", "log_loss", "pr_auc", "ece")}
        elif str(r["level_key"]).endswith(":quantity_if_purchase"):
            horizons.setdefault(h, {})["quantity"] = {"mae": _num(r["mae"] if r["mae"] is not None else m.get("mae")), "bias": _num(r["bias"] if r["bias"] is not None else m.get("bias"))}
    return {"schema": "predictily-metrics-summary/1", "core": core, "scope": "holdout", "horizons": horizons}


def registry_side(conn: Connection, tenant_id: int, core: str, version: str) -> dict[str, Any]:
    row = conn.execute(text("SELECT * FROM model_registry WHERE tenant_id = :t AND core = :c AND version = :v"), {"t": tenant_id, "c": core, "v": version}).mappings().first()
    if row is None:
        raise CliError(EXIT_NOT_FOUND, f"La versión «{version}» no existe en el registro de la empresa {tenant_id} (core {core}).")
    row = dict(row)
    basis = policy.basis_of_rows(conn, tenant_id, [row])[0]
    compat = row.get("artifact_compat") or {}
    published = compat.get("evaluation_summary") if isinstance(compat, dict) else None
    if published:
        summary, how = published, "published_bundle_selection"
    else:
        summary, how = _evaluation_rows_summary(conn, tenant_id, int(row["id"]), core), "registry_family_rows"
    return {"label": version, "kind": "registry", "model_registry_id": int(row["id"]), "status": row["status"], "serving_basis": basis, "basis": how,
            "family": row["model_family"], "data_fingerprint": (row.get("training_data_hash") or "").strip() or None, "summary": summary}


def bundle_side(directory: Path, tenant_id: int | None = None) -> dict[str, Any]:
    """Un paquete local como lado de la comparación. Si se indica la empresa (`--tenant-id`), el paquete DEBE ser de esa empresa (exit 4): comparar con el paquete de
    otra empresa mezclaría cifras entre empresas."""
    manifest, reason = read_package(directory)
    if manifest is not None:
        owner = int(manifest["tenant_id"])
        draft_note = None
        summary, fingerprint, verified = manifest.get("metrics"), manifest.get("data_fingerprint"), True
    elif not (Path(directory) / PACKAGE_FILE).exists():
        draft = read_json(Path(directory) / TRAIN_FILE)
        owner = int(draft["tenant_id"])
        summary, fingerprint, verified = draft.get("metrics"), draft.get("data_fingerprint"), False
        draft_note = "Borrador sin empaquetar ni firmar (train.json): cifras no verificadas."
    else:
        raise CliError(EXIT_CHECK_FAILED, f"El paquete de {directory} no verifica ({reason}).")
    if tenant_id is not None and owner != int(tenant_id):
        raise CliError(EXIT_NOT_AUTHORIZED, f"El paquete es de la empresa {owner}, no de la {tenant_id}: no se comparan cifras de empresas distintas.")
    side = {"label": f"bundle:{Path(directory).name}", "kind": "bundle", "tenant_id": owner, "verified": verified, "basis": "published_bundle_selection",
            "data_fingerprint": fingerprint, "summary": summary}
    if draft_note:
        side["warning"] = draft_note
    return side


def side_from_arg(conn: Connection | None, tenant_id: int, core: str, arg: str) -> dict[str, Any]:
    if arg.startswith("bundle:"):
        return bundle_side(Path(arg[len("bundle:"):]), tenant_id)
    if conn is None:
        raise CliError(EXIT_NOT_FOUND, "Comparar versiones del registro necesita la base de datos.")
    return registry_side(conn, tenant_id, core, arg)
