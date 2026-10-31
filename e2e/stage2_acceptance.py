#!/usr/bin/env python3
"""Prueba de aceptación E2E de la etapa 2 de Predictily (P7; instrucctions.md §6.J, matriz E2-xx).

Recorre la API pública real (Laravel) con el motor Python y el worker de colas corriendo, como lo haría un cliente,
con empresas NUEVAS creadas por el propio script (datos ficticios de data/demo/):

  1. Demanda v2      horizontes 14 y 60, envoltura, manifiesto, MASE/RMSSE, reversión y vuelta     (E2-06, E2-21, E2-24)
  2. Compradores     train + predict, 7/30/90, calibración, coherencia P7≤P30≤P90, genéricos,
                     conciliación sin doble conteo, segunda predicción sin duplicados             (E2-08..11, E2-18, E2-22)
  3. Compromisos     pedidos confirmados importados sin known_at: comprometidos, no re-sumados    (E2-23)
  4. Abastecimiento  ofertas, capacidad, presupuesto, plan MILP verificado de forma independiente,
                     oferta tardía, infactibilidad, costos, escenarios, causalidad               (E2-12..16, E2-26)
  5. Datos           cobertura, configuración efectiva, fuente SQL PostgreSQL de solo lectura     (E2-25)
  6. Asistente       desactivado sin clave; los cores siguen funcionando                          (E2-24 autonomía)
  7. Aislamiento     una segunda empresa no ve nada de la primera                                 (E2-17)

Uso:
  engine/.venv/bin/python e2e/stage2_acceptance.py --api http://127.0.0.1:8000/api/v1 \\
      --log docs/architecture/v2/evidence/stage2-acceptance-2026-10-31-final.log

Requisitos: backend + `queue:work` (colas imports, forecasting, default) + motor activos; datos demo en data/demo/;
PostgreSQL con un rol que pueda crear bases (por defecto predictily/secret en 127.0.0.1; se puede cambiar con
E2E_PG_HOST, E2E_PG_PORT, E2E_PG_USER, E2E_PG_PASSWORD). Crea la base «ERP de origen» ficticia `predictily_erp_source_e2e`.
Cada ejecución registra 2 usuarios nuevos (A y B) y crea 3 empresas; el registro está limitado a 10 por hora y por IP (429 «Too Many Attempts»).

Convenciones de salida: `[OK ]` verificación cumplida · `[FALLA]` criterio no cumplido (el script continúa con el
siguiente) · `[AVISO]` observación que no es un fallo del criterio (límite conocido o dependencia del entorno).
Código de salida: 0 si no hay FALLA; 1 en caso contrario. Dependencias: httpx y psycopg (incluidas en engine/.venv).
"""
from __future__ import annotations

import argparse
import csv
import datetime as dt
import hashlib
import json
import math
import os
import sys
import time
import traceback
import uuid
from pathlib import Path
from zoneinfo import ZoneInfo

import httpx

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(Path(__file__).resolve().parent))
import mvp_acceptance as mvp  # noqa: E402  (se reutilizan Client, setup_company, run_import y las esperas)

DEMO = ROOT / "data" / "demo"
TZ = ZoneInfo("America/Guayaquil")
RESULTS: list[tuple[str, str, str]] = []   # (paso, OK|FALLA|AVISO, detalle)
TIMES: dict[str, float] = {}
S: dict = {}                                # estado compartido entre bloques (ids de la empresa A, etc.)
OPTS: argparse.Namespace | None = None


# ───────────────────────────── salida y verificación ─────────────────────────────
class Tee:
    def __init__(self, *streams) -> None:
        self.streams = streams

    def write(self, data: str) -> int:
        for s in self.streams:
            s.write(data)
        return len(data)

    def flush(self) -> None:
        for s in self.streams:
            s.flush()


def check(step: str, ok: bool, detail: str = "") -> bool:
    ok = bool(ok)
    RESULTS.append((step, "OK" if ok else "FALLA", detail))
    print(f"[{'OK ' if ok else 'FALLA'}] {step}" + (f" — {detail}" if detail else ""), flush=True)
    return ok


def warn(step: str, detail: str = "") -> None:
    RESULTS.append((step, "AVISO", detail))
    print(f"[AVISO] {step}" + (f" — {detail}" if detail else ""), flush=True)


def info(msg: str) -> None:
    print(f"        {msg}", flush=True)


mvp.check = check   # las verificaciones de setup_company del MVP se registran en este resumen y no abortan


class block:
    """Bloque con cronómetro; un error inesperado se registra como FALLA y el script continúa con el siguiente bloque."""

    def __init__(self, name: str) -> None:
        self.name = name

    def __enter__(self):
        self.t0 = time.time()
        print(f"\n══ {self.name} ══", flush=True)
        return self

    def __exit__(self, et, ev, tb) -> bool:
        TIMES[self.name] = time.time() - self.t0
        if et is None:
            print(f"   ({TIMES[self.name]:.0f} s)", flush=True)
            return False
        if et is KeyboardInterrupt:
            return False
        check(f"[{self.name}] el bloque terminó con un error inesperado", False, f"{et.__name__}: {ev}")
        if et is not SystemExit:
            traceback.print_exception(et, ev, tb, limit=6, file=sys.stdout)
        print(f"   ({TIMES[self.name]:.0f} s)", flush=True)
        return True


# ───────────────────────────── utilidades de API ─────────────────────────────
def wait_run(c: mvp.Client, job_id: int | None, label: str, timeout: int = 1500) -> dict:
    """Espera un trabajo (training_job) e imprime una sola línea con el resultado."""
    t0 = time.time()
    if job_id is None:
        raise SystemExit(f"{label}: la respuesta no trae training_job")
    while time.time() - t0 < timeout:
        job = c.json("GET", f"/jobs/{job_id}")["data"]
        if job["status"] in {"succeeded", "failed"}:
            info(f"{label}: trabajo {job_id} {job['status']} en {time.time() - t0:.0f} s"
                 + (f" — {job.get('error_message')}" if job.get("error_message") else ""))
            return job
        time.sleep(1.5)
    raise SystemExit(f"{label}: tiempo agotado esperando el trabajo {job_id}")


def get_all(c: mvp.Client, path: str, params: dict | None = None, per_page: int = 100, max_pages: int = 100000):
    """Recorre todas las páginas de una lista paginada; devuelve (filas, última respuesta)."""
    rows: list = []
    page = 1
    while page <= max_pages:
        r = c.json("GET", path, params={**(params or {}), "per_page": per_page, "page": page})
        rows += r["data"]
        if page >= (r.get("meta") or {}).get("last_page", 1):
            return rows, r
        page += 1
    return rows, r


def num(x) -> float | None:
    try:
        return None if x is None else float(x)
    except (TypeError, ValueError):
        return None


def close(a, b, rel: float = 1e-3, abs_: float = 1e-3) -> bool:
    a, b = num(a), num(b)
    return a is not None and b is not None and abs(a - b) <= max(abs_, rel * max(abs(a), abs(b)))


def today_local() -> dt.date:
    return dt.datetime.now(TZ).date()


def pg_connect(dbname: str, autocommit: bool = False):
    import psycopg

    return psycopg.connect(
        host=os.environ.get("E2E_PG_HOST", "127.0.0.1"), port=int(os.environ.get("E2E_PG_PORT", "5432")),
        user=os.environ.get("E2E_PG_USER", "predictily"), password=os.environ.get("E2E_PG_PASSWORD", "secret"),
        dbname=dbname, autocommit=autocommit,
    )


VALID_QUALITY = {"ok", "degraded", "insufficient_data"}
VALID_HS = {"ok", "degraded", "insufficient_data", "not_computed", "unknown"}


# ───────────────────────────── preparación de la empresa A ─────────────────────────────
def setup_a(api: str) -> mvp.Client:
    state_file = Path(OPTS.state) if OPTS.state else None
    a = mvp.Client(api)
    if state_file and state_file.exists():
        st = json.loads(state_file.read_text())
        a.tenant = st["tenant"]
        a.http.headers["Authorization"] = st["auth"]
        check("[A] reutiliza la empresa del archivo de estado (modo desarrollo)", True, a.tenant)
        return a
    mvp.setup_company(a, "ferreteria_andina", "Ferretería E2E etapa 2 (prueba ficticia)", "ferreteria", "A")
    t0 = time.time()
    for entity, fname in (("products", "products"), ("sales", "sales"), ("inventory", "inventory"), ("lead_times", "lead_times")):
        job = mvp.run_import(a, DEMO / f"ferreteria_andina_{fname}.csv", entity)
        st = job["stats"]
        check(f"[A] importa {entity}", job["status"] == "completed",
              f"{st.get('rows_valid')} filas válidas" + (f", {st.get('documents_new')} documentos" if entity == "sales" else "")
              + f" ({time.time() - t0:.0f} s acumulados)")
    if state_file:
        state_file.write_text(json.dumps({"tenant": a.tenant, "auth": a.http.headers["Authorization"]}))
    return a


# ───────────────────────────── 1. Demanda v2 ─────────────────────────────
def run_demand(a: mvp.Client, horizon: int) -> dict:
    t0 = time.time()
    run = a.json("POST", "/forecast-runs", 202, json={"horizon_days": horizon})["data"]
    job = wait_run(a, (run.get("training_job") or {}).get("id"), f"pronóstico H={horizon}")
    full = a.json("GET", f"/forecast-runs/{run['id']}")["data"]
    check(f"[Demanda] ejecución con horizonte {horizon} completada", job["status"] == "succeeded" and full["status"] == "completed",
          f"run {full['id']} v{full['version']} en {time.time() - t0:.0f} s {full.get('error_message') or ''}")
    return full


def check_envelope_demand(run: dict, horizon: int, label: str) -> None:
    env = run.get("envelope") or {}
    check(f"[Demanda] {label}: envoltura presente con config_version y data_snapshot_id",
          bool(env) and bool(env.get("config_version")) and env.get("data_snapshot_id") is not None
          and env.get("config_version") == run.get("config_version") and env.get("data_snapshot_id") == run.get("data_snapshot_id")
          and env.get("core") == "demand" and env.get("result_type") == "forecast" and env.get("run_id") == run["id"],
          f"config {str(env.get('config_version'))[:12]}…, snapshot {env.get('data_snapshot_id')}, core {env.get('core')}, tipo {env.get('result_type')}")
    hs = env.get("horizon_support") or {}
    computed = {h: v for h, v in hs.items() if int(h) <= horizon}
    beyond = {h: v for h, v in hs.items() if int(h) > horizon}
    status = {h: v.get("status") for h, v in hs.items()}
    check(f"[Demanda] {label}: horizon_support coherente (calculados ≤ {horizon} d con respaldo; los mayores 'not_computed')",
          set(hs) == {"7", "14", "30", "60", "90"} and all(v["status"] in {"ok", "degraded", "insufficient_data"} for v in computed.values())
          and all(v["status"] == "not_computed" for v in beyond.values()) and str(horizon) in computed,
          json.dumps(status))
    # con 730 días de historia (≥ 2×H) y un backtest de 90 días, todo horizonte ≤ H debe estar respaldado (ok)
    hist = {h: (v.get("history_days"), v.get("required_history_days"), v.get("backtest_horizon_days")) for h, v in computed.items()}
    check(f"[Demanda] {label}: respaldo = ok cuando historia ≥ 2×H y backtest ≥ H (reglas de abstención)",
          all(v["status"] == "ok" and v["required_history_days"] == 2 * int(h) and v["history_days"] >= v["required_history_days"]
              and v["backtest_horizon_days"] >= int(h) and v["independent_holdout"] is True and v["evaluated_obs"] > 0 for h, v in computed.items()),
          f"(historia, requerida, backtest) {hist}")
    worst_ok = all(v["status"] == "ok" for v in computed.values())
    check(f"[Demanda] {label}: quality_status coherente con el respaldo por horizonte",
          env.get("quality_status") in VALID_QUALITY and (env["quality_status"] == "ok") == worst_ok
          and (run.get("summary") or {}).get("quality_status") == env.get("quality_status"),
          f"envoltura {env.get('quality_status')}, resumen {(run.get('summary') or {}).get('quality_status')}")
    check(f"[Demanda] {label}: incertidumbre declarada y supuestos/limitaciones listados",
          bool(env.get("uncertainty_source")) and bool(env.get("assumptions")) and isinstance(env.get("limitation_codes"), list),
          f"fuente {env.get('uncertainty_source')}, {len(env.get('assumptions') or [])} supuestos, limitaciones {env.get('limitation_codes')}")


def check_accuracy_manifest(a: mvp.Client, run: dict, label: str) -> dict:
    acc = a.json("GET", "/accuracy/summary", params={"run_id": run["id"]})["data"]
    mf = acc.get("evaluation_manifest") or {}
    check(f"[Demanda] {label}: /accuracy/summary expone el manifiesto del run y coincide con el de /forecast-runs/{{id}}",
          mf.get("manifest_version") and mf == (run.get("evaluation_manifest") or {}) and (acc.get("run") or {}).get("id") == run["id"],
          f"{mf.get('manifest_version')}, config {str(mf.get('config_version'))[:12]}…")
    ho, chk, dec = mf.get("holdout") or {}, mf.get("checks") or {}, mf.get("decisions") or {}
    sel_to = (mf.get("selection") or {}).get("target_to")
    cal = mf.get("calibration") or {}
    fits = (mf.get("training") or {}).get("backtest_fits") or []
    sel_fits = [f for f in fits if f.get("role") == "selection"]
    ho_fits = [f for f in fits if f.get("role") == "holdout"]
    check(f"[Demanda] {label}: protocolo independiente (E2-06) — el período final no participa en decisiones y es posterior a la selección",
          ho.get("present") and ho.get("independent") and chk and all(v is True for v in chk.values())
          and not any(ho.get(k) for k in ho if k.startswith("used_for_"))
          and dec.get("evaluation_windows_that_also_took_decisions") == []
          and sel_to and ho.get("target_from") and sel_to < ho["target_from"] and cal.get("observable_at") and cal["observable_at"] <= ho.get("origin", "9")
          and sel_fits and ho_fits and max(f["target_to"] for f in sel_fits) < min(f["target_from"] for f in ho_fits),
          f"selección hasta {sel_to}, calibración observable {cal.get('observable_at')}, final {ho.get('target_from')}→{ho.get('target_to')}, comprobaciones {chk}")
    lm = mf.get("label_maturity") or {}
    check(f"[Demanda] {label}: maduración de etiquetas y fechas de entrenamiento registradas",
          lm.get("holdout_evidence_end") and lm.get("selection_evidence_end") and (mf.get("history") or {}).get("to")
          and (mf.get("training") or {}).get("final_fit", {}).get("train_through"),
          f"evidencia selección {lm.get('selection_evidence_end')}, final {lm.get('holdout_evidence_end')}")
    bt = acc.get("backtest") or {}
    sl, bl = bt.get("selected") or {}, bt.get("baseline") or {}
    pin = sl.get("pinball") or {}
    check(f"[Demanda] {label}: MASE/RMSSE presentes y positivos (seleccionado y base) con tamaño de muestra",
          all((num(x) or 0) > 0 for x in (sl.get("mase"), sl.get("rmsse"), bl.get("mase"), bl.get("rmsse")))
          and (sl.get("sample") or {}).get("n_obs", 0) > 0,
          f"MASE {sl.get('mase')} (naive {bl.get('mase')}), RMSSE {sl.get('rmsse')} (naive {bl.get('rmsse')}), n={(sl.get('sample') or {}).get('n_obs')}")
    check(f"[Demanda] {label}: pinball P10/P90, amplitud de intervalos y dinero separado de unidades",
          num(pin.get("p10")) is not None and num(pin.get("p90")) is not None and "p50" in pin
          and (sl.get("interval_width") or {}).get("80") is not None and (sl.get("money") or {}).get("currency")
          and (sl.get("money") or {}).get("n_obs", 0) > 0,
          f"P10 {pin.get('p10')}, P90 {pin.get('p90')}, P50 {pin.get('p50')}, dinero {(sl.get('money') or {}).get('currency')}")
    byh = {x["level_key"] for x in bt.get("by_horizon", [])}
    tot = {x["horizon_days"] for x in bt.get("by_horizon_total", [])}
    check(f"[Demanda] {label}: evaluación por tramo (h1-7…h61-90) y error del total de H días",
          byh >= {"h1-7", "h8-14", "h15-30", "h31-60", "h61-90"} and tot >= {7, 14, 30, 60, 90}, f"tramos {sorted(byh)}, totales {sorted(tot)}")
    return acc


def block_demand(a: mvp.Client) -> None:
    ids = S["ids"]
    run14 = run_demand(a, 14)
    ids["demand_run14"], ids["demand_job"] = run14["id"], (run14.get("training_job") or {}).get("id")
    check_envelope_demand(run14, 14, "H=14")
    jb = a.json("GET", f"/jobs/{ids['demand_job']}")["data"]
    check("[Demanda] un trabajo ejecutado una sola vez informa attempts = 1 (lo cuenta solo el backend, una vez por intento real)",
          jb["attempts"] == 1, f"attempts = {jb['attempts']}")
    # series / productos / dashboard: respaldo del horizonte pedido
    s14 = a.json("GET", "/forecasts/series", params={"horizon": 14})["data"]
    s30 = a.json("GET", "/forecasts/series", params={"horizon": 30})["data"]
    check("[Demanda] H=14: /forecasts/series informa respaldo ok y quality_status por punto",
          s14["meta"]["horizon_support"]["status"] == "ok" and s14["meta"]["run_id"] == run14["id"]
          and s14["envelope"]["quality_status"] == "ok" and s14["forecast"]
          and all(p.get("quality_status") in VALID_QUALITY and "case_label" in p and "p50" in p for p in s14["forecast"]),
          f"{len(s14['forecast'])} puntos")
    check("[Demanda] H=14: pedir 30 días a una ejecución de 14 → 'not_computed' (no se inventa)",
          s30["meta"]["horizon_support"]["status"] == "not_computed" and s30["envelope"]["horizon_support"]["status"] == "not_computed",
          json.dumps(s30["meta"]["horizon_support"].get("reasons")))
    prod = a.json("GET", "/forecasts/products", params={"horizon": 14, "per_page": 3})
    dash = a.json("GET", "/dashboard/summary", params={"horizon": 14})["data"]
    check("[Demanda] H=14: productos y dashboard traen horizon_support", prod["horizon_support"]["status"] == "ok" and dash["horizon_support"]["status"] == "ok")
    acc14 = check_accuracy_manifest(a, run14, "H=14")

    run60 = run_demand(a, 60)
    ids["demand_run60"] = run60["id"]
    check_envelope_demand(run60, 60, "H=60")
    check("[Demanda] H=60: nueva versión vigente y la anterior deja de serlo",
          run60["version"] == run14["version"] + 1 and run60["is_current"]
          and not a.json("GET", f"/forecast-runs/{run14['id']}")["data"]["is_current"],
          f"v{run14['version']} → v{run60['version']}")
    s60 = a.json("GET", "/forecasts/series", params={"horizon": 60})["data"]
    check("[Demanda] H=60: serie a 60 días respaldada (historia 730 d ≥ 120 d) con 60 puntos diarios",
          s60["meta"]["horizon_support"]["status"] == "ok" and s60["meta"]["horizon_support"]["required_history_days"] == 120
          and len(s60["forecast"]) == 60, f"{len(s60['forecast'])} puntos")
    acc60 = check_accuracy_manifest(a, run60, "H=60")
    check("[Demanda] /accuracy/summary por defecto apunta a la ejecución vigente",
          a.json("GET", "/accuracy/summary")["data"]["run"]["id"] == run60["id"])

    # ── reversión y vuelta (E2-24) ──
    models_before = a.json("GET", "/models", params={"core": "demand", "status": "production"})["data"]
    check("[Demanda] registro de modelos: solo los de la ejecución vigente están en producción",
          models_before and all(m["forecast_run_id"] == run60["id"] and m["core"] == "demand" and m["role"] in {"primary", "fallback"} for m in models_before),
          f"{len(models_before)} modelos, familias {sorted({m['model_family'] for m in models_before})}")
    r = a.req("POST", "/models/demand/rollback", (409,), json={"run_id": run60["id"]})
    check("[Demanda] reversión a la ejecución vigente → 409 rollback_already_current", r.json().get("code") == "rollback_already_current")
    r = a.req("POST", "/models/demand/rollback", (404,), json={"run_id": 2_000_000_000})
    check("[Demanda] reversión a una ejecución inexistente → 404", r.status_code == 404)
    r = a.req("POST", "/models/demand/rollback", (422,), json={})
    check("[Demanda] reversión sin run_id → 422", r.status_code == 422)
    rb = a.req("POST", "/models/demand/rollback", (200,), json={"run_id": run14["id"]}).json()["data"]
    check("[Demanda] reversión de la ejecución vigente a la anterior",
          rb["from_run_id"] == run60["id"] and rb["to_run_id"] == run14["id"] and rb["restored_model_ids"] and rb["retired_model_ids"]
          and rb["to_config_version"] == run14["config_version"] and rb["to_data_snapshot_id"] == run14["data_snapshot_id"],
          f"v{rb['from_version']} → v{rb['to_version']}, restaurados {rb['restored_model_ids']}, retirados {rb['retired_model_ids']}")
    cur = [r_ for r_ in a.json("GET", "/forecast-runs")["data"] if r_["is_current"]]
    s_after = a.json("GET", "/forecasts/series", params={"horizon": 14})["data"]
    acc_after = a.json("GET", "/accuracy/summary")["data"]
    models_after = a.json("GET", "/models", params={"core": "demand", "status": "production"})["data"]
    check("[Demanda] tras revertir: vigente única = ejecución anterior; series, precisión y modelos apuntan a ella",
          len(cur) == 1 and cur[0]["id"] == run14["id"] and s_after["meta"]["run_id"] == run14["id"]
          and acc_after["run"]["id"] == run14["id"] and models_after and all(m["forecast_run_id"] == run14["id"] for m in models_after),
          f"vigente {cur[0]['id'] if cur else None}; modelos en producción de la ejecución {sorted({m['forecast_run_id'] for m in models_after})}")
    check("[Demanda] tras revertir: los pronósticos de la ejecución restaurada son los de su corte (no se recalcula)",
          s_after["forecast"] == s14["forecast"] and acc_after["backtest"]["selected"]["mase"] == acc14["backtest"]["selected"]["mase"],
          f"corte {s_after['meta']['cutoff_date']}")
    av, av_info = rb.get("artifacts_verified"), rb.get("artifacts_verification") or {}
    if av is True:
        check("[Demanda] reversión con SHA-256 de los artefactos verificado por el backend (artifact_path relativo a ENGINE_ARTIFACTS_DIR)", True,
              f"artifacts_verified = true; {av_info.get('checked')}/{av_info.get('registered')} artefactos verificados")
    else:
        # Sin acceso al volumen de artefactos el backend debe INFORMARLO explícitamente (estado y motivo), no devolver null a secas.
        check("[Demanda] la reversión que no pudo verificar el SHA-256 lo informa explícitamente (estado y motivo)",
              av is None and av_info.get("status") in {"not_verifiable", "partially_verified", "no_artifacts"} and bool(av_info.get("message")),
              f"artifacts_verification = {av_info}")
        warn("[Demanda] la reversión no pudo verificar el SHA-256 desde el backend",
             "el backend no ve el volumen de artefactos: configure ENGINE_ARTIFACTS_DIR (el mismo del motor) en backend/.env; ver verificación independiente")
    # verificación independiente del SHA-256 de los artefactos restaurados (si el motor corre en esta máquina)
    tenant_id = (run14.get("envelope") or {}).get("tenant_id")
    verified, missing = 0, 0
    for mdl in models_after:
        if not mdl.get("artifact_sha256"):
            continue
        path = Path(OPTS.engine_dir) / "storage" / "artifacts" / str(tenant_id) / f"run-{mdl['forecast_run_id']}" / f"{mdl['model_family']}.joblib"
        if not path.is_file():
            missing += 1
            continue
        ok = hashlib.sha256(path.read_bytes()).hexdigest() == mdl["artifact_sha256"]
        verified += 1
        check(f"[Demanda] SHA-256 del artefacto restaurado {mdl['model_family']} coincide con el registro (verificación independiente)", ok, path.name)
    if not verified:
        warn("[Demanda] no se pudo verificar el SHA-256 de ningún artefacto de forma independiente", f"{missing} artefactos no visibles en {OPTS.engine_dir}")
    r = a.req("POST", "/models/demand/rollback", (409,), json={"run_id": run60["id"]})
    check("[Demanda] 'avanzar' con rollback a una ejecución más nueva → 409 rollback_target_not_previous", r.json().get("code") == "rollback_target_not_previous")

    # vuelta: generar un nuevo pronóstico (H=90, el mayor del plan) deja una ejecución nueva vigente
    run90 = run_demand(a, 90)
    ids["demand_run90"] = run90["id"]
    check_envelope_demand(run90, 90, "H=90 (vuelta)")
    cur = [r_ for r_ in a.json("GET", "/forecast-runs")["data"] if r_["is_current"]]
    models_fwd = a.json("GET", "/models", params={"core": "demand", "status": "production"})["data"]
    check("[Demanda] 'vuelta' tras la reversión: nueva ejecución vigente única y modelos en producción solo de ella",
          run90["version"] == run60["version"] + 1 and len(cur) == 1 and cur[0]["id"] == run90["id"]
          and models_fwd and all(m["forecast_run_id"] == run90["id"] for m in models_fwd),
          f"v{run90['version']} vigente; modelos de la ejecución {sorted({m['forecast_run_id'] for m in models_fwd})}")
    retired = a.json("GET", "/models", params={"core": "demand", "status": "retired", "run_id": run14["id"]})["data"]
    check("[Demanda] los modelos de la ejecución revertida quedan retirados (no hay dos producciones a la vez)", len(retired) > 0, f"{len(retired)} retirados de la ejecución {run14['id']}")
    check_accuracy_manifest(a, run90, "H=90")
    short_history(a)


def short_history(a: mvp.Client) -> None:
    """E2-21 · datos insuficientes producen abstención explícita: empresa C con solo 45 días de ventas."""
    # Segunda empresa del mismo usuario (el registro está limitado a 10 por hora y por IP): no se registra otro usuario.
    api = str(a.http.base_url).rstrip("/")
    c = mvp.Client(api)
    c.http.headers["Authorization"] = a.http.headers["Authorization"]
    c.tenant = c.json("POST", "/tenants", 201, json={"name": "Ferretería historia corta E2E (ficticia)", "sector": "ferreteria", "timezone": "America/Guayaquil", "currency": "USD"})["data"]["uuid"]
    with (DEMO / "ferreteria_andina_branches.csv").open(encoding="utf-8") as fh:
        for row in csv.DictReader(fh):
            c.json("POST", "/branches", 201, json={k: row[k] for k in ("code", "name", "province", "canton", "commercial_zone") if row.get(k)})
    check("[C] empresa de historia corta creada (segunda empresa del mismo usuario)", bool(c.tenant), c.tenant)
    src = DEMO / "ferreteria_andina_sales.csv"
    with src.open(encoding="utf-8") as fh:
        rows = list(csv.DictReader(fh))
    last = max(r["issued_at"][:10] for r in rows)
    first = (dt.date.fromisoformat(last) - dt.timedelta(days=44)).isoformat()
    out = Path(OPTS.workdir) / "e2e_stage2_sales_short.csv"
    keep = [r for r in rows if r["issued_at"][:10] >= first]
    with out.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=list(rows[0].keys()))
        w.writeheader()
        w.writerows(keep)
    job = mvp.run_import(c, out, "sales")
    check("[C] importa solo 45 días de ventas (historia corta)", job["status"] == "completed", f"{job['stats'].get('documents_new')} documentos desde {first}")
    t0 = time.time()
    run = c.json("POST", "/forecast-runs", 202, json={"horizon_days": 30})["data"]
    wj = wait_run(c, (run.get("training_job") or {}).get("id"), "pronóstico H=30 con 45 días de historia")
    full = c.json("GET", f"/forecast-runs/{run['id']}")["data"]
    if full["status"] != "completed":
        msg = str(full.get("error_message") or "")
        explicit = wj["status"] == "failed" and ("insuficien" in msg.lower() or "historia" in msg.lower()) and not msg.startswith("Error interno")
        check("[C] E2-21: con historia insuficiente el pronóstico se abstiene de forma explícita (insufficient_data o rechazo con motivo de datos), sin error interno del motor",
              explicit, f"estado {full['status']}, mensaje: {msg[:200]}")
        return
    env = full["envelope"]
    hs = env["horizon_support"]
    comp = {h: v for h, v in hs.items() if int(h) <= 30}
    short = {h: v for h, v in comp.items() if v["history_days"] is not None and v["history_days"] < 2 * int(h)}
    check("[C] E2-21: horizonte sin historia suficiente (< 2×H) → insufficient_data con motivo history_lt_2h; no se presenta como completo",
          short and all(v["status"] == "insufficient_data" and any(r_["code"] == "history_lt_2h" for r_ in v["reasons"]) for v in short.values()),
          {h: (v["status"], v["history_days"], v["required_history_days"], [r_["code"] for r_ in v["reasons"]]) for h, v in comp.items()})
    check("[C] E2-21: la calidad global no es 'ok' si algún horizonte calculado carece de respaldo; los demás horizontes figuran 'not_computed'",
          env["quality_status"] in {"degraded", "insufficient_data"} and hs["60"]["status"] == "not_computed" and hs["90"]["status"] == "not_computed",
          f"quality_status {env['quality_status']}")
    s30 = c.json("GET", "/forecasts/series", params={"horizon": 30})["data"]
    check("[C] E2-21: la serie a 30 días marca cada punto y el horizonte como insufficient_data (el número existe pero no se presenta como completo)",
          s30["meta"]["horizon_support"]["status"] == "insufficient_data" and s30["envelope"]["quality_status"] == "insufficient_data" and s30["forecast"]
          and all(p_["quality_status"] == "insufficient_data" for p_ in s30["forecast"]), f"{len(s30['forecast'])} puntos; motivos {[r_['code'] for r_ in s30['meta']['horizon_support']['reasons']]}")
    prod = c.json("GET", "/forecasts/products", params={"horizon": 30, "per_page": 5})
    check("[C] E2-21: productos y precisión informan la abstención del horizonte", prod["horizon_support"]["status"] == "insufficient_data"
          and c.json("GET", "/accuracy/summary")["data"]["horizon_support"]["30"]["status"] == "insufficient_data", f"{time.time() - t0:.0f} s")


# ───────────────────────────── 2. Compradores ─────────────────────────────
GENERIC_CODES = {"9999999999999", "9999999999", "CONSUMIDOR_FINAL"}


def run_customer(a: mvp.Client, operation: str, **body) -> dict:
    t0 = time.time()
    r = a.req("POST", "/customer/runs", (202,), json={"operation": operation, **body})
    run = r.json()["data"]
    job = wait_run(a, (run.get("training_job") or {}).get("id"), f"comprador {operation}", timeout=1800)
    full = a.json("GET", f"/customer/runs/{run['id']}")["data"]
    check(f"[Compradores] {operation} completado", job["status"] == "succeeded" and full["status"] == "completed",
          f"run {full['id']} v{full['version']} calidad {full.get('quality_status')} en {time.time() - t0:.0f} s {full.get('error_message') or ''}")
    return full


def fetch_predictions(a: mvp.Client, horizons=(7, 30, 90), params: dict | None = None) -> dict[int, list]:
    out = {}
    for h in horizons:
        out[h], _ = get_all(a, "/customer/predictions", {"horizon": h, **(params or {})})
    return out


def block_customer(a: mvp.Client) -> None:
    ids = S["ids"]
    train = run_customer(a, "train", horizons=[7, 30, 90])
    ids["customer_train"] = train["id"]
    env = train.get("envelope") or {}
    check("[Compradores] train: ejecución de evaluación con envoltura, config_version y data_snapshot_id",
          train["operation"] == "train" and train["result_type"] == "evaluation" and train["quality_status"] in {"ok", "degraded"}
          and train.get("config_version") and train.get("data_snapshot_id") is not None and env.get("core") == "customer",
          f"calidad {train['quality_status']}, horizontes {train['horizons']}")
    ev = a.json("GET", "/customer/evaluation")["data"]
    mf = ev["manifest"]
    check("[Compradores] manifiesto del entrenamiento: horizontes 7/30/90 con nivel (tier) y estado explícitos",
          set(mf["horizons"]) == {"7", "30", "90"} and all(v["status"] in {"trained", "abstained"} and v["tier"] in {"full", "lean", "reference_only", "abstained"}
                                                          for v in mf["horizons"].values()),
          {h: (v["status"], v["tier"]) for h, v in mf["horizons"].items()})
    for h in ("7", "30", "90"):
        hz = mf["horizons"][h]
        if hz["status"] != "trained":
            warn(f"[Compradores] H={h}: el entrenamiento se abstuvo", hz.get("reason") or "")
            continue
        blocks = hz["blocks"]
        order = [b for b in ("train", "calibration", "selection", "holdout") if b in blocks]
        chrono = all(blocks[p]["label_window_to"] < blocks[n]["cutoff_from"] for p, n in zip(order, order[1:])) \
            and blocks["holdout"]["label_window_to"] <= blocks["labels_mature_through"] and all(blocks[b]["n_cutoffs"] > 0 for b in order)
        check(f"[Compradores] H={h}: bloques cronológicos y separados por ≥ H días; el holdout no usa etiquetas inmaduras (E2-10, E2-08)",
              chrono and "holdout" in order and "calibration" in order, f"bloques {order}; madurez hasta {blocks['labels_mature_through']}; separación {blocks.get('gap_days_between_blocks')} d")
        check(f"[Compradores] H={h}: el holdout no decide modelo, calibrador ni umbrales",
              {"modelo", "calibrador", "umbrales"} <= set(hz.get("holdout_not_used_for", [])), json.dumps(hz.get("holdout_not_used_for"), ensure_ascii=False))
        rows = ev_metric_rows(a, h)
        sel = [x for x in rows if x["evaluation_type"] == "holdout" and x["is_selected"]]
        m_ = (sel[0]["metrics"] if sel else {})
        curve = m_.get("calibration_curve") or []
        check(f"[Compradores] H={h}: evaluación con Brier, log loss y curva de calibración (con n por tramo) sobre el holdout",
              sel and num(m_.get("brier")) is not None and num(m_.get("log_loss")) is not None and 0 < m_["brier"] < 0.25 and m_["log_loss"] > 0
              and curve and all(b["n"] > 0 for b in curve) and sum(b["n"] for b in curve) == m_["n"] == sel[0]["n_obs"],
              f"Brier {num(m_.get('brier')):.4f} (climatología {num(m_.get('brier_climatology')):.4f}), log loss {num(m_.get('log_loss')):.4f}, ECE {num(m_.get('ece')):.4f}, "
              f"n={m_.get('n')}, {len(curve)} tramos" if sel else "sin fila de holdout seleccionada")
        qrows = [x for x in ev["metrics"].get(f"h{h}:quantity_if_purchase", []) if x["evaluation_type"] == "holdout"]
        check(f"[Compradores] H={h}: cantidad si compra, esperada y monto evaluados frente a la referencia",
              qrows and ev["metrics"].get(f"h{h}:expected_amount") and ev["metrics"].get(f"h{h}:ranking"), f"{len(qrows)} filas de cantidad")

    # ── predict ──
    p1 = run_customer(a, "predict")
    ids["customer_predict1"] = p1["id"]
    check("[Compradores] predict: no entrena (registro de modelos sin cambios) y usa la configuración del entrenamiento",
          p1["operation"] == "predict" and p1["result_type"] == "forecast" and p1["config_version"] == train["config_version"]
          and p1["quality_status"] in VALID_QUALITY and p1["horizons"] == [7, 30, 90],
          f"as_of {p1['as_of']}, calidad {p1['quality_status']}")
    t0 = time.time()
    pred = fetch_predictions(a)
    S["pred1"] = pred
    info(f"{sum(len(v) for v in pred.values())} predicciones leídas en {time.time() - t0:.0f} s")
    # envoltura de resultados
    first = a.json("GET", "/customer/predictions", params={"horizon": 30, "per_page": 1})
    env = first["envelope"]
    check("[Compradores] predicciones con envoltura: quality_status, uncertainty_source, tipo, config y snapshot",
          env["quality_status"] in VALID_QUALITY and env["uncertainty_source"] == "empirical_holdout" and env["result_type"] == "forecast"
          and env["core"] == "customer" and env["config_version"] and env["data_snapshot_id"] is not None and env["run_id"] == p1["id"]
          and first.get("definitions") and first.get("ranking_source") in {"model", "popularity", "recency"},
          f"calidad {env['quality_status']}, incertidumbre {env['uncertainty_source']}, ranking {first.get('ranking_source')}")
    # 7/30/90 presentes y con el mismo universo
    keys = {h: {(r["customer"]["id"], r["product"]["id"]) for r in rows} for h, rows in pred.items()}
    check("[Compradores] predicciones a 7, 30 y 90 días con el mismo universo comprador×producto y sin filas duplicadas",
          all(len(rows) > 0 and len(rows) == len(keys[h]) for h, rows in pred.items()) and keys[7] == keys[30] == keys[90],
          {h: len(v) for h, v in pred.items()})
    # probabilidades en (0,1)
    bad_p = [r for rows in pred.values() for r in rows if not (0 < r["purchase_probability"] < 1)]
    check("[Compradores] todas las probabilidades están en (0, 1) (ni 0 ni 1 exactos)", not bad_p, f"{len(bad_p)} fuera de rango")
    # coherencia P7 <= P30 <= P90 por comprador×producto
    by = {h: {(r["customer"]["id"], r["product"]["id"]): r["purchase_probability"] for r in rows} for h, rows in pred.items()}
    viol = [k for k in by[7] if not (by[7][k] <= by[30].get(k, -1) + 1e-9 and by[30].get(k, -1) <= by[90].get(k, -1) + 1e-9)]
    adj = {h: sum(1 for r in rows if r.get("probability_coherence_adjusted")) for h, rows in pred.items()}
    check("[Compradores] coherencia P7 ≤ P30 ≤ P90 en todos los comprador×producto", not viol,
          f"{len(by[7])} pares comparados, {len(viol)} violaciones; filas ajustadas por coherencia {adj}")
    # expected = P × cantidad, intervalos, monto supuesto
    bad_id = [r for rows in pred.values() for r in rows if not close(r["expected_quantity"], r["purchase_probability"] * r["quantity_if_purchase"], rel=2e-3, abs_=2e-4)]
    check("[Compradores] identidad esperada = probabilidad × cantidad_si_compra (incluye el cero de «no compra»)", not bad_id, f"{len(bad_id)} filas fuera de tolerancia")
    qi = [r for rows in pred.values() for r in rows if not (r["quantity_interval"]["lo"] <= r["quantity_if_purchase"] <= r["quantity_interval"]["hi"] and r["quantity_interval"]["level"] == 0.8)]
    check("[Compradores] cantidad si compra dentro de su intervalo del 80 %", not qi, f"{len(qi)} fuera del intervalo")
    amt = [r for rows in pred.values() for r in rows if not (r["expected_amount"] and "supuesto" in r["expected_amount"]["basis"] and r["expected_amount"]["assumed_unit_price"] is not None
                                                           and close(r["expected_amount"]["value"], r["expected_quantity"] * r["expected_amount"]["assumed_unit_price"], rel=5e-3, abs_=0.011))]
    check("[Compradores] expected_amount marcado como supuesto (precio supuesto explícito; no es venta confirmada)", not amt and "no es una venta confirmada" in pred[30][0]["expected_amount"]["basis"],
          f"{len(amt)} filas incoherentes; ejemplo: {pred[30][0]['expected_amount']}")
    check("[Compradores] calibration_status y rol del modelo informados en cada fila",
          all(r["calibration_status"] in {"calibrated", "degraded", "uncalibrated"} and r["model"]["role"] in {"primary", "fallback"} for rows in pred.values() for r in rows[:2000]))
    # genéricos ausentes
    codes = {r["customer"]["code"] for rows in pred.values() for r in rows}
    check("[Compradores] ningún comprador genérico (consumidor final) tiene predicción individual (E2-09)", not (codes & GENERIC_CODES), f"{len(codes)} compradores con predicción")
    generic_id = None
    try:
        with pg_connect(OPTS.app_db) as conn:
            cur = conn.execute("select c.id from customers c join tenants t on t.id=c.tenant_id where t.uuid=%s and c.is_generic order by c.id limit 1", (a.tenant,))
            row = cur.fetchone()
            generic_id = row[0] if row else None
    except Exception as exc:  # noqa: BLE001
        warn("[Compradores] no se pudo consultar el id del comprador genérico en la BD de la aplicación", str(exc)[:120])
    if generic_id:
        prof = a.json("GET", f"/customer/customers/{generic_id}/profile", params={"horizon": 30})["data"]
        gp = a.json("GET", "/customer/predictions", params={"horizon": 30, "customer_id": generic_id})
        check("[Compradores] el genérico no recibe predicción individual: perfil 'no_individual_prediction' con respaldo identificado y sin filas",
              prof["customer"]["is_generic"] and prof["status"] == "no_individual_prediction" and prof["fallback"] and not gp["data"],
              f"estado {prof['status']}; respaldo: {str(prof['fallback'])[:100]}")
    elif generic_id is None and OPTS.app_db:
        warn("[Compradores] sin id de genérico para probar el perfil; se verificó la ausencia en las predicciones")
    summ = p1["summary"].get("excluded_from_individual_prediction", {})
    check("[Compradores] el resumen cuenta lo excluido de la predicción individual (genéricos, anónimos) sin convertirlo en un comprador",
          summ.get("generic", {}).get("lines", 0) > 0 and "modeled_share_units_180d" in summ, json.dumps({k: v for k, v in summ.items() if k != "window_days"}))
    # perfil de un comprador con predicción
    top = pred[90][0]["customer"]
    prof = a.json("GET", f"/customer/customers/{top['id']}/profile")["data"]
    check("[Compradores] perfil del comprador: frecuencia, inactividad con definición, ranking 7/30/90 con fuente",
          prof["status"] == "predicted" and prof["activity"]["inactivity"]["definition"] and prof["activity"]["purchase_frequency_per_30d"] is not None
          and set(prof["ranking_sources"]) == {"7", "30", "90"}, f"{top['code']}: inactividad {prof['activity']['inactivity']['status']}, frecuencia/30 d {prof['activity']['purchase_frequency_per_30d']}")
    rk = prof["rankings"]
    rk_ok = all(items and [x["rank"] for x in items] == list(range(1, len(items) + 1)) and all(x["ranking_source"] in {"model", "popularity", "recency"} for x in items)
                and all(items[i]["ranking_score"] >= items[i + 1]["ranking_score"] - 1e-9 for i in range(len(items) - 1)) for items in rk.values())
    top_e = a.json("GET", "/customer/predictions", params={"horizon": 90, "order_by": "expected_quantity", "direction": "desc", "per_page": 50})["data"]
    top_p = a.json("GET", "/customer/predictions", params={"horizon": 90, "order_by": "probability", "direction": "desc", "per_page": 50})["data"]
    check("[Compradores] E2-22: ranking de productos probables (rank 1..k, fuente y puntaje decreciente) y ordenamientos por esperada/probabilidad correctos",
          rk_ok and all(top_e[i]["expected_quantity"] >= top_e[i + 1]["expected_quantity"] for i in range(len(top_e) - 1))
          and all(top_p[i]["purchase_probability"] >= top_p[i + 1]["purchase_probability"] for i in range(len(top_p) - 1))
          and top_p[0]["purchase_probability"] == max(r["purchase_probability"] for r in pred[90]),
          f"ranking H30 de {top['code']}: {[(x['rank'], x['product']['sku']) for x in rk['30'][:3]]}, fuente {sorted({x['ranking_source'] for x in rk['30']})}")
    inact = prof["activity"]["inactivity"]["status"]
    chg = prof["activity"]["behavior_change"]["status"]
    check("[Compradores] E2-22: frecuencia, inactividad («no es abandono») y cambio de comportamiento con estados definidos",
          inact in {"active", "at_risk", "inactive_by_pattern", "insufficient_history"} and chg in {"increase", "decrease", "stable", "insufficient_history"}
          and "No es abandono" in prof["activity"]["inactivity"]["definition"], f"inactividad {inact} (riesgo {prof['activity']['inactivity']['risk']}), cambio {chg}")
    S["customers_with_pred"] = pred

    # ── conciliación sin doble conteo (E2-11) ──
    reconcile_checks(a, pred, "sin compromisos")

    # ── segunda predicción sin ventas nuevas: no duplica (E2-18) y una activa por operación ──
    run_b = a.req("POST", "/customer/runs", (202,), json={"operation": "predict"}).json()["data"]
    dup = a.req("POST", "/customer/runs", (409,), json={"operation": "predict"})
    check("[Compradores] una sola predicción activa a la vez → 409 customer_run_in_progress", dup.json().get("code") == "customer_run_in_progress", dup.text[:100])
    job = wait_run(a, run_b["training_job"]["id"], "comprador predict (2.ª, sin ventas nuevas)")
    p2 = a.json("GET", f"/customer/runs/{run_b['id']}")["data"]
    ids["customer_predict2"] = p2["id"]
    totals = {h: a.json("GET", "/customer/predictions", params={"horizon": h, "per_page": 1})["meta"]["total"] for h in (7, 30, 90)}
    old_totals = {h: a.json("GET", "/customer/predictions", params={"horizon": h, "per_page": 1, "run_id": p1["id"]})["meta"]["total"] for h in (7, 30, 90)}
    sample, _ = get_all(a, "/customer/predictions", {"horizon": 90, "order_by": "probability", "direction": "desc"}, max_pages=20)
    second_by = {(r["customer"]["id"], r["product"]["id"]): r for r in sample}
    first_by = {(r["customer"]["id"], r["product"]["id"]): r for r in pred[90]}
    same = all(k in first_by and close(first_by[k]["purchase_probability"], r["purchase_probability"], rel=1e-9, abs_=1e-9)
               and first_by[k]["days_since_last_purchase"] == r["days_since_last_purchase"] for k, r in second_by.items())
    check("[Compradores] 2.ª predicción sin ventas nuevas: misma cantidad de filas por horizonte (no duplica), vigente = la nueva, la anterior se conserva intacta",
          job["status"] == "succeeded" and p2["is_current"] and p2["version"] == p1["version"] + 1 and totals == {h: len(v) for h, v in pred.items()} == old_totals
          and len(sample) == len(second_by),
          f"filas {totals} (anterior {old_totals}); muestra de {len(sample)} filas sin duplicados")
    check("[Compradores] 2.ª predicción el mismo día sin ventas nuevas reproduce las mismas probabilidades y recencias", same and len(sample) > 0, f"{len(sample)} filas comparadas")
    # predicción por id de corrida y aislamiento de parámetros
    r = a.req("GET", "/customer/predictions", (200, 404, 422), params={"horizon": 30, "run_id": 987654321})
    check("[Compradores] run_id inexistente no devuelve resultados", r.status_code in (404, 422) or not r.json().get("data"), f"HTTP {r.status_code}")
    r = a.req("GET", "/customer/predictions", (422,), params={"horizon": 14})
    check("[Compradores] horizonte fuera de {7,30,90} → 422", r.status_code == 422)
    S["pred2_totals"] = totals

    # ── E2-18: recencia sin ventas nuevas y corridas retroactivas (historical) ──
    ids["customer_predict2"] = p2["id"]
    retro_asof = (dt.date.fromisoformat(p2["as_of"]) - dt.timedelta(days=4)).isoformat()
    retro = run_customer(a, "predict", as_of=retro_asof)
    ra, _ = get_all(a, "/customer/predictions", {"horizon": 90, "run_id": retro["id"]}, max_pages=15)
    rb, _ = get_all(a, "/customer/predictions", {"horizon": 90, "run_id": p2["id"]}, max_pages=15)
    rbk = {(r["customer"]["id"], r["product"]["id"]): r for r in rb}
    delta = (dt.date.fromisoformat(p2["as_of"]) - dt.date.fromisoformat(retro["as_of"])).days
    rec_diff = {rbk[k]["days_since_last_purchase"] - r["days_since_last_purchase"] for k, r in ((( x["customer"]["id"], x["product"]["id"]), x) for x in ra) if k in rbk}
    check("[Compradores] E2-18: la recencia se actualiza con la fecha sin exigir ventas nuevas (días desde la última compra a hoy − días al corte retroactivo = días transcurridos)",
          retro["as_of"] == retro_asof and delta == 4 and rec_diff == {delta} and len(ra) > 0, f"corte retroactivo {retro['as_of']} vs {p2['as_of']}: diferencia de recencia {sorted(rec_diff)} sobre {len(ra)} filas")
    runs_pred = a.json("GET", "/customer/runs", params={"operation": "predict"})["data"]
    served = a.json("GET", "/customer/predictions", params={"horizon": 30, "per_page": 1})["envelope"]["run_id"]
    check("[Compradores] §10: un predict retroactivo (as_of pasado) queda 'historical', no se promueve ni desplaza a la predicción vigente de producción",
          retro["run_type"] == "historical" and not retro["is_current"] and [r["id"] for r in runs_pred if r["is_current"]] == [p2["id"]] and served == p2["id"],
          f"vigentes tras el retroactivo {[r['id'] for r in runs_pred if r['is_current']]} (esperada [{p2['id']}]); /customer/predictions sirve la corrida {served} "
          f"({'histórica' if served == retro['id'] else 'producción'}, as_of {retro['as_of'] if served == retro['id'] else p2['as_of']})")
    models_before = {m["id"] for m in a.json("GET", "/models", params={"core": "customer", "status": "production"})["data"]}
    retro_train = run_customer(a, "train", as_of=(dt.date.fromisoformat(train["as_of"]) - dt.timedelta(days=60)).isoformat(), horizons=[7])
    truns = a.json("GET", "/customer/runs", params={"operation": "train"})["data"]
    ev_served = a.json("GET", "/customer/evaluation", params={"horizon": 7})["data"]["run_id"]
    models_after = {m["id"] for m in a.json("GET", "/models", params={"core": "customer", "status": "production"})["data"]}
    check("[Compradores] §10: un train retroactivo queda 'historical' y no promueve modelos (el registro en producción no cambia)",
          retro_train["run_type"] == "historical" and not retro_train["is_current"] and models_before == models_after and models_before, f"{len(models_after)} modelos en producción, sin cambios")
    check("[Compradores] §10: tras un train retroactivo, la evaluación vigente sigue siendo la del entrenamiento de producción",
          [r["id"] for r in truns if r["is_current"]] == [train["id"]] and ev_served == train["id"],
          f"entrenamientos vigentes {[r['id'] for r in truns if r['is_current']]} (esperado [{train['id']}]); /customer/evaluation sirve la corrida {ev_served} "
          f"({'histórica' if ev_served == retro_train['id'] else 'producción'})")


def ev_metric_rows(a: mvp.Client, h: str) -> list:
    return a.json("GET", "/customer/evaluation", params={"horizon": int(h)})["data"]["metrics"].get(f"h{h}:probability", [])


def reconcile_checks(a: mvp.Client, pred: dict[int, list], label: str, only_products: set[int] | None = None) -> None:
    """Conciliación (E2-11): agregado = compradores + comprometido fuera del modelo + residual, recomputada de forma independiente."""
    for h in (7, 30, 90):
        rec, resp = get_all(a, "/customer/reconciliation", {"horizon": h})
        tot_view: dict[int, float] = {}
        n_buyers: dict[int, int] = {}
        for r in pred[h]:
            tot_view[r["product"]["id"]] = tot_view.get(r["product"]["id"], 0.0) + float(r["total_view_quantity"])
            n_buyers[r["product"]["id"]] = n_buyers.get(r["product"]["id"], 0) + 1
        statuses: dict[str, int] = {}
        problems: list[str] = []
        for x in rec:
            pid = x["product"]["id"]
            if only_products and pid not in only_products:
                continue
            statuses[x["status"]] = statuses.get(x["status"], 0) + 1
            if x["status"] not in {"ok", "inconsistent", "aggregate_unavailable", "aggregate_incomplete"}:
                problems.append(f"{pid}: estado {x['status']}")
                continue
            # compradores: coincide con la suma independiente de las filas de predicción (sin doble conteo)
            if not close(x["buyers_total_quantity"], tot_view.get(pid, 0.0), rel=2e-3, abs_=0.02) or x["n_buyers"] != n_buyers.get(pid, 0):
                problems.append(f"{pid}: compradores API {x['buyers_total_quantity']} ≠ Σ filas {tot_view.get(pid, 0.0):.4f}")
            if not close(x["buyers_total_quantity"], x["buyers_committed_quantity"] + x["buyers_incremental_quantity"], rel=2e-3, abs_=0.02):
                problems.append(f"{pid}: compradores ≠ comprometido + incremental")
            if x["status"] == "ok":
                parts = x["buyers_total_quantity"] + x["committed_outside_model"] + x["residual_quantity"]
                if not close(x["aggregate_quantity"], parts, rel=2e-3, abs_=0.02) or x["residual_quantity"] < -1e-6:
                    problems.append(f"{pid}: agregado {x['aggregate_quantity']} ≠ {parts:.4f}")
            elif x["status"] == "inconsistent":
                if not (x["residual_quantity"] in (0, 0.0) and (x["excess_quantity"] or 0) > 0
                        and x["buyers_total_quantity"] + x["committed_outside_model"] > x["aggregate_quantity"]):
                    problems.append(f"{pid}: inconsistente sin exceso informado")
            elif x["residual_quantity"] is not None:
                problems.append(f"{pid}: residual inventado ({x['residual_quantity']}) con agregado no disponible/incompleto")
        check(f"[Compradores] conciliación H={h} ({label}): agregado = compradores + comprometido fuera del modelo + residual (o inconsistencia/indisponibilidad reportada)",
              not problems and statuses, f"estados {statuses}" + (f"; problemas: {problems[:3]}" if problems else ""))
        if statuses.get("ok", 0) == 0 and not only_products:
            warn(f"[Compradores] conciliación H={h}: ningún producto con agregado completo",
                 "la predicción usa as_of de hoy y el pronóstico agregado se cortó antes: cobertura del agregado < H días (límite de diseño, no se inventa residual)")


# ───────────────────────────── 3. Compromisos ─────────────────────────────
def block_commitments(a: mvp.Client) -> None:
    ids = S["ids"]
    pred = S["pred1"]
    p1 = a.json("GET", f"/customer/runs/{ids['customer_predict2']}")["data"]
    as_of = dt.date.fromisoformat(p1["as_of"])
    # pares comprador×producto (distintos productos) con la mayor esperanza a 90 días
    ranked = sorted(pred[90], key=lambda r: -r["expected_quantity"])
    pairs, used_products = [], set()
    for r in ranked:
        if r["product"]["id"] in used_products:
            continue
        pairs.append(r)
        used_products.add(r["product"]["id"])
        if len(pairs) == 6:
            break
    pA, pB, pC, pD, pE, pF = pairs
    old_by = {h: {(r["customer"]["id"], r["product"]["id"]): r for r in pred[h]} for h in (7, 30, 90)}
    kA, kB = (pA["customer"]["id"], pA["product"]["id"]), (pB["customer"]["id"], pB["product"]["id"])
    qA = math.ceil(pA["expected_quantity"] * 3) + 10          # compromiso MAYOR que la esperanza
    qB = max(0.5, round(pB["expected_quantity"] * 0.4, 2))    # compromiso MENOR que la esperanza a 90 d
    issued = f"{as_of - dt.timedelta(days=2)} 10:00:00"
    rows = [
        # pA: pedido confirmado, entrega en 10 días (dentro de 30 y 90, fuera de 7)
        ("order", "E2E2-PED-A", "confirmed", issued, as_of + dt.timedelta(days=10), "", pA, qA, 0),
        # pB: pedido confirmado con entrega en 5 días (dentro de 7, 30 y 90) y cantidad pequeña
        ("order", "E2E2-PED-B", "confirmed", issued, as_of + dt.timedelta(days=5), "", pB, qB, 0),
        # pC: pedido cancelado antes de hoy: no cuenta
        ("order", "E2E2-PED-C", "cancelled", issued, as_of + dt.timedelta(days=6), str(as_of - dt.timedelta(days=1)), pC, 25, 0),
        # pD: cotización (no es pedido): no cuenta
        ("quote", "E2E2-COT-D", "open", issued, as_of + dt.timedelta(days=6), "", pD, 25, 0),
        # pE: pedido confirmado con entrega fuera del horizonte de 90 días: no cuenta
        ("order", "E2E2-PED-E", "confirmed", issued, as_of + dt.timedelta(days=200), "", pE, 25, 0),
        # pF: pedido ya cumplido por completo: sin pendiente
        ("order", "E2E2-PED-F", "partially_fulfilled", issued, as_of + dt.timedelta(days=4), "", pF, 25, 25),
    ]
    path = Path(OPTS.workdir) / "e2e_stage2_commitments.csv"
    with path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["commitment_type", "external_id", "status", "issued_at", "expected_delivery_date", "cancelled_at", "customer_code", "branch_code", "product_sku",
                    "quantity", "quantity_fulfilled", "unit_price", "line_number"])
        for ct, ext, st, iss, deliv, canc, pr, q, ful in rows:
            w.writerow([ct, ext, st, iss, deliv, canc, pr["customer"]["code"], "001", pr["product"]["sku"], q, ful, pr["expected_amount"]["assumed_unit_price"], 1])
    prods_before = {pid: a.json("GET", "/forecasts/products", params={"horizon": 30, "search": sku})["data"] for pid, sku in ((p["product"]["id"], p["product"]["sku"]) for p in pairs)}
    sales_before = a.json("GET", "/sales/summary", params={"granularity": "month", "date_from": "2024-01-01", "date_to": "2026-12-31"})["data"]["totals"]
    job = mvp.run_import(a, path, "commitments")
    st = job["stats"]
    check("[Compromisos] importa el CSV de pedidos sin known_at (cotización, pedido, cancelado, cumplido)",
          job["status"] == "completed" and st.get("commitments_new") == 6 and st.get("rows_error") == 0 and "known_at" not in path.read_text().splitlines()[0],
          f"{st.get('commitments_new')} compromisos nuevos, {st.get('cancelled_commitments')} cancelados, {st.get('lines_written')} líneas")
    lst = a.json("GET", "/commitments", params={"per_page": 50})["data"]
    mine = {x["external_id"]: x for x in lst if x["external_id"].startswith("E2E2-")}
    ids["commitment_id"] = mine["E2E2-PED-A"]["id"]
    check("[Compromisos] known_at desconocido: no se rellena con la fecha de carga (queda NULL y se declara)",
          len(mine) == 6 and all(x["known_at"] is None and x["known_at_unknown"] for x in mine.values()), f"{len(mine)} compromisos")
    cutoff_before = (dt.datetime.now(dt.timezone.utc) - dt.timedelta(days=3)).date().isoformat()
    mine_of = lambda rows: {x["external_id"]: x for x in rows if x["external_id"].startswith("E2E2-")}  # noqa: E731
    past = mine_of(a.json("GET", "/commitments", params={"as_of": cutoff_before, "per_page": 50})["data"])
    vis_now = mine_of(a.json("GET", "/commitments", params={"as_of": as_of.isoformat(), "per_page": 50})["data"])
    check("[Compromisos] E2-23: un corte anterior a la carga no ve los compromisos importados hoy sin known_at (la ingestión es cota superior del conocimiento)",
          not past, f"corte {cutoff_before}: {len(past)} visibles")
    check("[Compromisos] E2-23: en el corte de hoy (as_of = fecha de la carga) los 6 compromisos son visibles con known_basis=ingestion (P3 §2.3: «sí cuenta en el corte de hoy»)",
          len(vis_now) == 6 and all(x.get("known_basis") == "ingestion" for x in vis_now.values()),
          f"GET /commitments?as_of={as_of} devolvió {len(vis_now)} de 6 (ingestados {mine['E2E2-PED-A'].get('ingested_at') or 'hoy'})")
    if not vis_now:
        # diagnóstico: ¿desde qué fecha de corte son visibles?
        for k in (1, 2):
            probe = mine_of(a.json("GET", "/commitments", params={"as_of": (as_of + dt.timedelta(days=k)).isoformat(), "per_page": 50})["data"])
            info(f"diagnóstico: as_of = {as_of + dt.timedelta(days=k)} → {len(probe)} visibles (la API interpreta una fecha sin hora como 00:00 UTC; el motor usa el fin del día local)")
            if probe:
                vis_now = probe
                break
    check("[Compromisos] estado al corte: cancelado con pendiente 0, cumplido con pendiente 0, vigente con su pendiente",
          vis_now and vis_now["E2E2-PED-C"]["pending_total"] == 0 and vis_now["E2E2-PED-F"]["pending_total"] == 0 and vis_now["E2E2-PED-A"]["pending_total"] == qA,
          (f"pendiente A {vis_now['E2E2-PED-A']['pending_total']}, C {vis_now['E2E2-PED-C']['pending_total']}, F {vis_now['E2E2-PED-F']['pending_total']}" if vis_now else "sin compromisos visibles en ningún corte probado"))
    sales_after_import = a.json("GET", "/sales/summary", params={"granularity": "month", "date_from": "2024-01-01", "date_to": "2026-12-31"})["data"]["totals"]
    check("[Compromisos] los compromisos no son ventas: el resumen de ventas no cambia", sales_before == sales_after_import, f"{sales_before['documents']} documentos")

    p3 = run_customer(a, "predict")
    ids["customer_predict3"] = p3["id"]
    summ = (p3["summary"].get("committed") or {})
    check("[Compromisos] la predicción vigente trata los pedidos confirmados como comprometidos (resumen por horizonte)",
          p3["is_current"] and summ["7"]["lines_committed"] >= 1 and summ["30"]["lines_committed"] == 2 and summ["90"]["lines_committed"] == 2
          and summ["90"]["commitments_known_by_ingestion"] == 6,
          {h: {"líneas": summ[h]["lines_committed"], "cantidad": summ[h]["committed_quantity"], "excluidas": {k: v for k, v in summ[h]["excluded"].items() if v}} for h in ("7", "30", "90")})
    # filas puntuales
    new = {h: {(r["customer"]["id"], r["product"]["id"]): r for r in fetch_for_pairs(a, h, pairs)} for h in (7, 30, 90)}
    problems = []
    for name, pr, q, deliv, counted in (("A", pA, qA, 10, True), ("B", pB, qB, 5, True), ("C", pC, 25, 6, False), ("D", pD, 25, 6, False), ("E", pE, 25, 200, False), ("F", pF, 25, 4, False)):
        k = (pr["customer"]["id"], pr["product"]["id"])
        for h in (7, 30, 90):
            row, old = new[h][k], old_by[h][k]
            committed = q if (counted and deliv <= h) else 0
            exp = row["expected_quantity"]
            if not close(row["committed_quantity"], committed, abs_=1e-6):
                problems.append(f"{name}/H{h}: comprometido {row['committed_quantity']} ≠ {committed}")
            if not close(row["total_view_quantity"], max(exp, committed), rel=1e-3, abs_=2e-4):
                problems.append(f"{name}/H{h}: vista total {row['total_view_quantity']} ≠ max({exp}, {committed})")
            if not close(row["incremental_quantity"], max(exp - committed, 0), rel=1e-3, abs_=2e-4):
                problems.append(f"{name}/H{h}: incremento {row['incremental_quantity']}")
            if committed and abs(row["total_view_quantity"] - (exp + committed)) < 1e-3 * max(1, exp + committed) and exp > 1e-3:
                problems.append(f"{name}/H{h}: comprometido re-sumado a la esperanza")
            if not close(row["purchase_probability"], old["purchase_probability"], rel=1e-9, abs_=1e-9):
                problems.append(f"{name}/H{h}: la probabilidad cambió por el compromiso")
    check("[Compromisos] E2-23: total = máx(esperanza, comprometido), incremento = máx(esperanza − comprometido, 0); cancelado, cotización, fuera de horizonte y cumplido no cuentan; la probabilidad no cambia",
          not problems, "; ".join(problems[:4]) if problems else
          f"A: comprometido {new[30][kA]['committed_quantity']} vs esperado {new[30][kA]['expected_quantity']} → vista {new[30][kA]['total_view_quantity']}; "
          f"B (H90): comprometido {new[90][kB]['committed_quantity']}, esperado {new[90][kB]['expected_quantity']} → vista {new[90][kB]['total_view_quantity']}, incremento {new[90][kB]['incremental_quantity']}")
    # conciliación de los productos tocados, con la vista por comprador recomputada
    pred3 = {}
    for h in (7, 30, 90):
        pred3[h] = []
        for pid in {p["product"]["id"] for p in pairs}:
            rows_, _ = get_all(a, "/customer/predictions", {"horizon": h, "product_id": pid})
            pred3[h] += rows_
    reconcile_checks(a, pred3, "con compromisos", only_products={p["product"]["id"] for p in pairs})
    rec30 = {x["product"]["id"]: x for x in get_all(a, "/customer/reconciliation", {"horizon": 30})[0]}
    check("[Compromisos] la conciliación contabiliza el comprometido dentro de la vista de compradores (no fuera del modelo, no sumado dos veces)",
          rec30[pA["product"]["id"]]["buyers_committed_quantity"] >= qA - 1e-6 and rec30[pA["product"]["id"]]["committed_outside_model"] == 0
          and rec30[pC["product"]["id"]]["buyers_committed_quantity"] == 0,
          f"producto A: comprometido {rec30[pA['product']['id']]['buyers_committed_quantity']}, fuera del modelo {rec30[pA['product']['id']]['committed_outside_model']}")
    # el pronóstico agregado de demanda no se altera por los compromisos
    prods_after = {pid: a.json("GET", "/forecasts/products", params={"horizon": 30, "search": sku})["data"] for pid, sku in ((p["product"]["id"], p["product"]["sku"]) for p in pairs)}
    check("[Compromisos] el pronóstico agregado de demanda no cambia (el comprometido no se suma a la demanda)", prods_before == prods_after, f"{len(prods_after)} productos comparados")


def fetch_for_pairs(a: mvp.Client, h: int, pairs: list) -> list:
    out = []
    for p in pairs:
        out += a.json("GET", "/customer/predictions", params={"horizon": h, "customer_id": p["customer"]["id"], "product_id": p["product"]["id"]})["data"]
    return out


# ───────────────────────────── 4. Abastecimiento ─────────────────────────────
def verify_plan(data: dict, offers: dict[int, dict], budgets: list[dict], caps: list[dict], as_of: dt.date) -> dict[str, list[str]]:
    """Verifica por su cuenta (sin las matrices del solver) que ninguna línea viole mínimos, múltiplos, plazos, presupuesto ni capacidades."""
    v: dict[str, list[str]] = {k: [] for k in ("lines", "min_multiple", "lead", "budget", "supplier_capacity", "storage", "balance", "shortfalls")}
    lines = [l for l in data["lines"] if l["line_type"] == "purchase"]
    by_offer_date: dict[tuple, float] = {}
    for l in lines:
        o = offers.get(l["offer_id"])
        if o is None:
            v["lines"].append(f"línea {l['id']}: oferta {l['offer_id']} desconocida")
            continue
        q = l["offer_quantity"]
        if l["quantity"] <= 0 or not close(l["line_cost"], l["quantity"] * l["unit_cost"] + (num(o.get("fixed_order_cost")) or 0), rel=1e-3, abs_=0.011):
            v["lines"].append(f"línea {l['id']}: costo {l['line_cost']} ≠ {l['quantity']}×{l['unit_cost']}")
        if not close(l["unit_cost"], o["unit_cost"] * (1 + (num(o.get("acquisition_cost_pct")) or 0)) + (num(o.get("acquisition_cost_per_unit")) or 0), rel=1e-3, abs_=1e-3):
            v["lines"].append(f"línea {l['id']}: costo unitario {l['unit_cost']} ≠ oferta {o['unit_cost']}")
        if l["offer_unit"] != o["unit"] or l["currency"] != o["currency"]:
            v["lines"].append(f"línea {l['id']}: unidad/moneda distinta de la oferta")
        if o.get("min_order_qty") and q < o["min_order_qty"] - 1e-6:
            v["min_multiple"].append(f"línea {l['id']}: {q} < mínimo {o['min_order_qty']}")
        if o.get("order_multiple") and abs(q / o["order_multiple"] - round(q / o["order_multiple"])) > 1e-6:
            v["min_multiple"].append(f"línea {l['id']}: {q} no es múltiplo de {o['order_multiple']}")
        od, rd = dt.date.fromisoformat(l["order_date"]), dt.date.fromisoformat(l["expected_receipt_date"])
        if o.get("lead_time_days") is not None and (rd - od).days != o["lead_time_days"]:
            v["lead"].append(f"línea {l['id']}: plazo {(rd - od).days} d ≠ oferta {o['lead_time_days']} d")
        if od < as_of:
            v["lead"].append(f"línea {l['id']}: emisión {od} anterior al corte {as_of}")
        by_offer_date[(l["offer_id"], l["order_date"])] = by_offer_date.get((l["offer_id"], l["order_date"]), 0) + q
    for b in budgets:
        lo, hi = dt.date.fromisoformat(b["period_start"]), dt.date.fromisoformat(b["period_end"])
        spend = sum(l["line_cost"] for l in lines if lo <= dt.date.fromisoformat(l["order_date"]) <= hi and (b.get("branch_id") in (None, l["branch_id"])))
        if spend > b["amount"] + 0.011:
            v["budget"].append(f"presupuesto {b['id']}: gasto {spend:.2f} > {b['amount']}")
    for (oid, od), q in by_offer_date.items():
        cap = offers[oid].get("capacity_per_period")
        if cap is not None and q > cap * 7 / offers[oid].get("capacity_period_days", 7) + 1e-6:
            v["supplier_capacity"].append(f"oferta {oid} {od}: {q} > capacidad {cap}")
    # balance por nodo (identidad) y almacenamiento: pico tras recepciones por sucursal y período.
    # La capacidad efectiva de un período es max(declarada, espacio ya comprometido SIN compras nuevas): si las existencias y las
    # órdenes abiertas ya exceden el límite, ninguna compra lo corrige y el plan lo informa (preexisting_excess). Se reconstruye aquí
    # la trayectoria sin compras nuevas restando del balance las recepciones del plan (ventas perdidas, mismo pronóstico).
    plan_rec: dict[tuple, float] = {}
    for l in lines:
        k = (l["branch_id"], l["product_id"], l["assumptions"]["arrival_period"])
        plan_rec[k] = plan_rec.get(k, 0) + l["quantity"]
    peak: dict[tuple, float] = {}
    peak_no: dict[tuple, float] = {}
    for node in data["balance"]:
        prev = prev_no = None
        for p in node["periods"]:
            if abs(p["demand"] - p["sales"] - p["unmet"]) > 0.01:
                v["balance"].append(f"nodo {node['branch_id']}/{node['product_id']} período {p['period']}: demanda ≠ ventas + no atendida")
            if prev is None:
                prev = prev_no = p["closing"] + p["sales"] - p["receipts"]
            if abs(prev + p["receipts"] - p["sales"] - p["closing"]) > 0.01:
                v["balance"].append(f"nodo {node['branch_id']}/{node['product_id']} período {p['period']}: apertura + recepciones − ventas ≠ cierre")
            if p["closing"] < -1e-6 or p["unmet"] < -1e-6:
                v["balance"].append(f"nodo {node['branch_id']}/{node['product_id']} período {p['period']}: inventario negativo")
            open_rec = p["receipts"] - plan_rec.get((node["branch_id"], node["product_id"], p["period"]), 0)
            key = (node["branch_id"], p["period"])
            peak[key] = peak.get(key, 0) + prev + p["receipts"]
            peak_no[key] = peak_no.get(key, 0) + prev_no + open_rec
            prev, prev_no = p["closing"], max(0.0, prev_no + open_rec - p["demand"])
    data["_peak"], data["_peak_no_purchases"] = peak, peak_no
    for cp in caps:
        for (bid, per), used in peak.items():
            eff = max(cp["capacity"], peak_no.get((bid, per), 0))
            if bid == cp["branch_id"] and used > eff + 0.5:
                v["storage"].append(f"sucursal {bid} período {per}: pico {used:.1f} > capacidad efectiva {eff:.1f} (declarada {cp['capacity']})")
    sf = sum(s["unmet"] for s in data["shortfalls"])
    if not close(sf, data["plan"]["unmet_units_total"], rel=2e-3, abs_=0.02):
        v["shortfalls"].append(f"Σ faltantes {sf:.3f} ≠ unmet_units_total {data['plan']['unmet_units_total']}")
    return v


def fetch_full_plan(a: mvp.Client, run_id: int) -> tuple[dict, dict]:
    """Lee el plan COMPLETO. El detalle solo trae una vista previa de 20 filas en `lines` / `shortfalls` y no incluye el balance salvo
    `?include=balance`, así que se arma el mismo `data` que consumen las verificaciones con: el detalle con balance + TODAS las líneas y
    TODOS los faltantes recorriendo `/lines` y `/shortfalls` (per_page=100, hasta agotar `meta.last_page`).
    Queda en `data['_preview']` la vista previa original y en `data['_meta']` los totales que informó la paginación."""
    d = a.json("GET", f"/supply/plans/{run_id}", params={"include": "balance"})
    data = dict(d["data"])
    lines, last_l = get_all(a, f"/supply/plans/{run_id}/lines")
    shortfalls, last_s = get_all(a, f"/supply/plans/{run_id}/shortfalls")
    data["_preview"] = {"lines": data["lines"], "shortfalls": data["shortfalls"]}
    data["_meta"] = {"lines_total": (last_l.get("meta") or {}).get("total"), "shortfalls_total": (last_s.get("meta") or {}).get("total")}
    data["lines"], data["shortfalls"] = lines, shortfalls
    return data, d["envelope"]


def create_plan(a: mvp.Client, label: str, probe_conflict: bool = False, **body) -> tuple[dict, dict]:
    t0 = time.time()
    run = a.req("POST", "/supply/plans", (202,), json=body).json()["data"]
    if probe_conflict:
        dup = a.req("POST", "/supply/plans", (409, 202), json=body)
        check("[Abastecimiento] un solo plan activo a la vez → 409 supply_run_in_progress", dup.status_code == 409 and dup.json().get("code") == "supply_run_in_progress", dup.text[:100])
    job = wait_run(a, (run.get("training_job") or {}).get("id"), f"plan {label}")
    d = a.json("GET", f"/supply/plans/{run['id']}")   # detalle por defecto: vista previa de 20 filas y sin balance
    check(f"[Abastecimiento] plan «{label}» completado", job["status"] == "succeeded" and d["data"]["run"]["status"] == "completed",
          f"run {run['id']} en {time.time() - t0:.0f} s {job.get('error_message') or ''}")
    data, env = fetch_full_plan(a, run["id"])
    data["_default"] = {"balance": d["data"].get("balance", "AUSENTE"), "lines": len(d["data"]["lines"]), "shortfalls": len(d["data"]["shortfalls"])}
    ls, ss, pv, meta = data["lines_summary"], data["shortfalls_summary"], data["_preview"], data["_meta"]
    ids_l, ids_s = [x["id"] for x in data["lines"]], [x["id"] for x in data["shortfalls"]]
    unmet_sum = sum(x["unmet"] for x in data["shortfalls"])
    check(f"[Abastecimiento] plan «{label}»: lectura COMPLETA paginada — {len(ids_l)} líneas = lines_summary.total y {len(ids_s)} faltantes = shortfalls_summary.total "
          "(meta.total coincide, sin filas repetidas, Σ no atendido = resumen y la vista previa del detalle son las primeras filas)",
          len(ids_l) == ls["total"] == meta["lines_total"] and len(ids_s) == ss["total"] == meta["shortfalls_total"]
          and len(set(ids_l)) == len(ids_l) and len(set(ids_s)) == len(ids_s)
          and ls["matched"] == ls["total"] and ls["shown"] == len(pv["lines"]) and ss["shown"] == len(pv["shortfalls"])
          and [x["id"] for x in pv["lines"]] == ids_l[:len(pv["lines"])] and [x["id"] for x in pv["shortfalls"]] == ids_s[:len(pv["shortfalls"])]
          and close(unmet_sum, ss["unmet_units"], rel=1e-6, abs_=0.01),
          f"líneas {len(ids_l)}/{ls['total']} (meta {meta['lines_total']}, vista previa {len(pv['lines'])}), faltantes {len(ids_s)}/{ss['total']} "
          f"(meta {meta['shortfalls_total']}, vista previa {len(pv['shortfalls'])}), Σ no atendido {unmet_sum:.2f} vs resumen {ss['unmet_units']:.2f}")
    return data, env


def block_supply(a: mvp.Client) -> None:
    ids = S["ids"]
    run90 = a.json("GET", f"/forecast-runs/{ids['demand_run90']}")["data"]
    as_of = dt.date.fromisoformat(run90["cutoff_date"])
    prods = {p["id"]: p for p in get_all(a, "/products")[0]}
    top = a.json("GET", "/forecasts/products", params={"horizon": 90, "sort": "-forecast_units", "per_page": 30})["data"]
    hi = [x["product"]["id"] for x in top if prods[x["product"]["id"]]["unit"] == "unidad"][:2]
    recs = get_all(a, "/inventory/recommendations", {"sort": "days_of_cover"})[0]
    low = [r for r in recs if r["on_hand"] == 0 and prods[r["product_id"]]["unit"] == "unidad" and r["product_id"] not in hi]
    X = low[0]["product_id"]
    Y = next(r["product_id"] for r in low if r["product_id"] != X)
    sup = {s["code"]: s["id"] for s in a.json("GET", "/suppliers")["data"]}
    fast, late = sup["PROV-HER"], sup["PROV-GEN"]
    cost = {pid: float(prods[pid]["current_cost"]) for pid in prods if prods[pid]["current_cost"] is not None}
    ids["supply_products"] = {"hi": hi, "X": X, "Y": Y}

    def offer(supplier: int, pid: int, unit_cost: float, lead: int, mn: float, mult: float, **extra) -> dict:
        r = a.req("POST", "/supply/offers", (201, 422), json={"supplier_id": supplier, "product_id": pid, "unit_cost": round(unit_cost, 4), "currency": "USD",
                                                               "unit": prods[pid]["unit"], "min_order_qty": mn, "order_multiple": mult, "lead_time_days": lead,
                                                               "lead_time_std_days": 1, **extra})
        if r.status_code != 201:
            raise SystemExit(f"oferta rechazada: {r.text[:300]}")
        return r.json()["data"]

    offs = []
    for pid in hi:           # dos proveedores: el barato llega tarde (40 d), el caro llega en 3 d
        c0 = cost.get(pid, 5.0)
        offs.append(offer(fast, pid, c0 * 1.20, 3, 100, 50))
        offs.append(offer(late, pid, c0 * 0.90, 40, 200, 100, capacity_per_period=300, capacity_period_days=7))
    c0 = cost.get(X, 5.0)
    offs.append(offer(fast, X, c0 * 1.20, 3, 20, 10))
    offs.append(offer(late, X, c0 * 0.80, 45, 10, 5))
    c0 = cost.get(Y, 5.0)
    oY = offer(late, Y, c0 * 0.80, 45, 10, 5)      # única fuente, llega tarde
    offs.append(oY)
    ids["offer_id"] = offs[0]["id"]
    check("[Abastecimiento] ofertas de dos proveedores (uno más barato que llega tarde) registradas con comparabilidad declarada",
          all(o["comparability"]["comparable"] for o in offs), f"{len(offs)} ofertas, productos {hi + [X, Y]}")
    # unidades y monedas: sin conversión / sin tasa de cambio ⇒ no comparable
    unit_prod = next(pid for pid, p in prods.items() if p["unit"] == "saco")
    o_unit = offer(sup["PROV-CEM"], unit_prod, 300, 5, 1, 1)
    r_bad_unit = a.req("PATCH", f"/supply/offers/{o_unit['id']}", (200,), json={"unit": "pallet"}).json()["data"]
    o_fx =a.req("POST", "/supply/offers", (201,), json={"supplier_id": sup["PROV-ACE"], "product_id": hi[0], "unit_cost": 2.0, "currency": "EUR", "unit": prods[hi[0]]["unit"],
                                                         "lead_time_days": 4, "min_order_qty": 10, "order_multiple": 1}).json()["data"]
    check("[Abastecimiento] E2-26: oferta en otra unidad sin conversión y oferta en EUR sin tasa se declaran NO comparables (nunca factor 1)",
          not r_bad_unit["comparability"]["comparable"] and "no_unit_conversion" in r_bad_unit["comparability"]["issues"]
          and not o_fx["comparability"]["comparable"] and "no_fx_rate" in o_fx["comparability"]["issues"],
          f"unidad: {r_bad_unit['comparability']['issues']}, moneda: {o_fx['comparability']['issues']}")
    dup = a.req("POST", "/supply/offers", (201, 422), json={"supplier_id": offs[0]["supplier_id"], "product_id": offs[0]["product_id"], "unit_cost": 9.99, "currency": "USD",
                                                           "unit": offs[0]["unit"], "lead_time_days": offs[0]["lead_time_days"]})
    check("[Abastecimiento] oferta duplicada (misma clave natural) → 422", dup.status_code == 422, dup.text[:110])
    # ── plan 1: sin presupuesto ni capacidad ──
    cfg = {"use_implicit_offers": False, "solver_time_limit_seconds": 30}
    p1, env1 = create_plan(a, "base (sin presupuesto ni capacidad)", objective="min_cost", coverage_mode="soft", config=cfg, probe_conflict=True)
    ids["plan1"] = p1["run"]["id"]
    all_offers = {o["id"]: o for o in get_all(a, "/supply/offers")[0]}
    pl = p1["plan"]
    check("[Abastecimiento] plan: estado del solver honesto (is_optimal ⇔ optimal con brecha ≤ 0,01 %), moneda y nota de que son sugerencias",
          pl["solver_status"] in {"optimal", "feasible_with_gap", "time_limit", "infeasible", "heuristic_fallback", "not_solved"}
          and (pl["is_optimal"] == (pl["solver_status"] == "optimal")) and (pl["solver_status"] != "optimal" or (pl["mip_gap"] or 0) <= 1e-4 + 1e-12)
          and pl["currency"] == "USD" and "no envía pedidos" in pl["note"],
          f"estado {pl['solver_status']}, brecha {pl['mip_gap']}, {p1['solver'].get('n_vars')} variables, {p1['solver'].get('components')} componentes")
    check("[Abastecimiento] envoltura del plan: result_type='optimized_plan', config_version, data_snapshot_id, supuestos de penalización/mantenimiento y quality_status",
          env1["result_type"] == "optimized_plan" and env1["core"] == "supply" and env1["config_version"] and env1["data_snapshot_id"] is not None
          and env1["quality_status"] in VALID_QUALITY and any("SUPUESTO" in s for s in env1["assumptions"]),
          f"calidad {env1['quality_status']}, {len(env1['assumptions'])} supuestos, {len(env1['limitations'])} limitaciones")
    v1 = verify_plan(p1, all_offers, [], [], as_of)
    check("[Abastecimiento] plan sin restricciones externas: mínimos, múltiplos, plazos, costos y balance verificados línea por línea (independiente)",
          not any(v1[k] for k in ("lines", "min_multiple", "lead", "balance", "shortfalls")) and p1["lines"],
          f"{len(p1['lines'])} líneas; " + "; ".join(x for k in v1 for x in v1[k][:2]))
    used_offers = {l["offer_id"] for l in p1["lines"]}
    excl = {o["offer_id"]: o for o in p1["offers"] if o["status"] != "comparable"}
    check("[Abastecimiento] E2-26: las ofertas no comparables (unidad sin conversión, EUR sin tasa) quedan excluidas con su motivo y ninguna línea las usa",
          {o_unit["id"], o_fx["id"]} <= set(excl) and not ({o_unit["id"], o_fx["id"]} & used_offers)
          and p1["comparison_coverage"]["offers_excluded"] >= 2 and p1["comparison_coverage"]["offers_comparable"] == len(offs),
          f"excluidas {[(i, excl[i]['reasons']) for i in excl]}; cobertura {p1['comparison_coverage']['offers_received']} recibidas / {p1['comparison_coverage']['offers_comparable']} comparables")
    # oferta tardía: no oculta faltantes previos
    sfY = [s_ for s_ in p1["shortfalls"] if s_["product_id"] == Y]
    y_early = [s_ for s_ in sfY if dt.date.fromisoformat(s_["period_start"]) < as_of + dt.timedelta(days=45) and s_["unmet"] > 0]
    diag_x = [d for d in p1["offer_diagnostics"] if d["product_id"] == X]
    late_x = [o for d in diag_x for o in d["offers"] if o["lead_days"] == 45]
    fast_x = [o for d in diag_x for o in d["offers"] if o["lead_days"] == 3]
    check("[Abastecimiento] E2-26: la oferta barata que llega tarde no oculta faltantes previos — el plan reporta la demanda no atendida antes de la llegada con causa lead_time y no declara cobertura completa",
          y_early and any(s_["reason"] == "lead_time" for s_ in y_early) and pl["unmet_units_total"] > 0,
          f"producto {Y} (única fuente, 45 d): {len(y_early)} períodos con faltante antes de la llegada (≈{sum(s_['unmet'] for s_ in y_early):.1f} u.); "
          f"no atendido total {pl['unmet_units_total']:.1f} de {pl['demand_units_total']:.1f}")
    check("[Abastecimiento] E2-26: con dos ofertas el diagnóstico por producto ordena por precio, indica el primer período disponible de la barata y la demanda sin cubrir antes de su llegada",
          late_x and fast_x and any(o["rank_by_price"] == 1 and o["first_available_period"] > min(f["first_available_period"] for f in fast_x) and o["unmet_before_arrival_units"] > 0 for o in late_x),
          f"producto {X}: " + "; ".join(f"oferta {o['offer_id']} {o['lead_days']} d precio #{o['rank_by_price']} disponible período {o['first_available_period']} sin cubrir {o['unmet_before_arrival_units']}" for d in diag_x[:1] for o in d["offers"]))
    fastX = [l for l in p1["lines"] if l["product_id"] == X and l["supplier_id"] == fast]
    check("[Abastecimiento] cada línea explica su elección frente a la alternativa (justificación contrafactual) y trae proveedor/cantidad/emisión/recepción/costo",
          p1["lines"] and all(l["justification"] and l["supplier"]["code"] and l["order_date"] and l["expected_receipt_date"] and l["line_cost"] is not None for l in p1["lines"])
          and (not fastX or ("llega antes" in fastX[0]["justification"] or "única" in fastX[0]["justification"].lower())),
          f"ejemplo: {p1['lines'][0]['supplier']['code']} {p1['lines'][0]['offer_quantity']} u. emitir {p1['lines'][0]['order_date']} → recibir {p1['lines'][0]['expected_receipt_date']}, ${p1['lines'][0]['line_cost']}")
    check("[Abastecimiento] producción genérica sin recetas: production='not_configured' y no bloquea la reposición",
          p1["production"]["status"] == "not_configured" and bool(p1["lines"]))

    # los filtros viven en el listado paginado `/lines` (el detalle solo trae una vista previa); se contrastan con el plan completo leído arriba
    plan1_lines = f"/supply/plans/{ids['plan1']}/lines"
    now_lines = get_all(a, plan1_lines, {"actionable_now": 1})[0]
    px_lines = get_all(a, plan1_lines, {"product_id": X})[0]
    sp_lines = get_all(a, plan1_lines, {"supplier_id": fast})[0]
    ids_of = lambda rows: sorted(l["id"] for l in rows)   # noqa: E731
    check("[Abastecimiento] filtros del plan (/lines paginado): actionable_now (solo la emisión de hoy), por producto y por proveedor — coinciden con el plan completo",
          now_lines and all(l["actionable_now"] and l["order_date"] == as_of.isoformat() for l in now_lines) and px_lines and all(l["product_id"] == X for l in px_lines)
          and sp_lines and all(l["supplier_id"] == fast for l in sp_lines) and len(now_lines) <= len(p1["lines"])
          and ids_of(now_lines) == ids_of([l for l in p1["lines"] if l["actionable_now"]])
          and ids_of(px_lines) == ids_of([l for l in p1["lines"] if l["product_id"] == X])
          and ids_of(sp_lines) == ids_of([l for l in p1["lines"] if l["supplier_id"] == fast])
          and len(now_lines) == p1["lines_summary"]["actionable_now"],
          f"{len(now_lines)} accionables de {len(p1['lines'])}, {len(px_lines)} del producto {X}, {len(sp_lines)} del proveedor")
    inc_bad = a.req("GET", f"/supply/plans/{ids['plan1']}", (200, 422), params={"include": "foo"})
    check("[Abastecimiento] detalle por defecto: balance es null (opt-in con ?include=balance), la vista previa tiene ≤ 20 líneas y faltantes, y un include desconocido → 422",
          p1["_default"]["balance"] is None and p1["_default"]["lines"] <= 20 and p1["_default"]["shortfalls"] <= 20 and p1["_default"]["lines"] == min(20, len(p1["lines"]))
          and isinstance(p1["balance"], list) and p1["balance"] and inc_bad.status_code == 422,
          f"por defecto balance={p1['_default']['balance']!r}, {p1['_default']['lines']} líneas y {p1['_default']['shortfalls']} faltantes de vista previa; "
          f"con include=balance {len(p1['balance'])} nodos; include=foo → {inc_bad.status_code}")
    # ── presupuesto y capacidades ──
    spend1 = sum(l["line_cost"] for l in p1["lines"])
    budget_amount = round(spend1 * 0.55, 2)
    bud = a.req("POST", "/supply/budgets", (201,), json={"period_start": as_of.isoformat(), "period_end": (as_of + dt.timedelta(days=90)).isoformat(),
                                                          "amount": budget_amount, "currency": "USD"}).json()["data"]
    ids["budget_id"] = bud["id"]
    br = {b["id"]: b["code"] for b in a.json("GET", "/branches")["data"]}
    # capacidad de almacén: por sucursal, algo por debajo del pico del plan sin restricciones (sin bajar del stock ya existente)
    peak1: dict[int, float] = {}
    opening: dict[int, float] = {}
    for node in p1["balance"]:
        prev = None
        for p in node["periods"]:
            if prev is None:
                prev = p["closing"] + p["sales"] - p["receipts"]
                opening[node["branch_id"]] = opening.get(node["branch_id"], 0) + prev
            peak1[node["branch_id"]] = max(peak1.get(node["branch_id"], 0), 0)
            prev = p["closing"]
    per_peak: dict[tuple, float] = {}
    for node in p1["balance"]:
        prev = None
        for p in node["periods"]:
            if prev is None:
                prev = p["closing"] + p["sales"] - p["receipts"]
            per_peak[(node["branch_id"], p["period"])] = per_peak.get((node["branch_id"], p["period"]), 0) + prev + p["receipts"]
            prev = p["closing"]
    caps = []
    for bid in sorted({k[0] for k in per_peak}):
        mx = max(v for (b_, _), v in per_peak.items() if b_ == bid)
        # la capacidad no puede ser menor que lo ya comprometido (stock actual + recepciones abiertas sin compras nuevas)
        floor = max(per_peak.get((bid, 0), 0), opening.get(bid, 0))
        cap_value = round(max(mx * 0.75, floor * 1.05), 0)
        r = a.req("POST", "/supply/capacities", (201,), json={"branch_id": bid, "capacity": cap_value, "capacity_unit": "unidad"}).json()["data"]
        caps.append(r)
        info(f"capacidad sucursal {br.get(bid)}: {cap_value:.0f} u. (pico sin límite {mx:.0f}, base ya comprometida {floor:.0f})")
    ids["capacity_id"] = caps[0]["id"]
    p2, env2 = create_plan(a, "con presupuesto y capacidad", objective="min_cost", coverage_mode="soft", config=cfg)
    ids["plan2"] = p2["run"]["id"]
    budgets = [b for b in get_all(a, "/supply/budgets")[0]]
    capsl = get_all(a, "/supply/capacities")[0]
    all_offers = {o["id"]: o for o in get_all(a, "/supply/offers")[0]}
    v2 = verify_plan(p2, all_offers, budgets, capsl, as_of)
    spend2 = sum(l["line_cost"] for l in p2["lines"])
    pl2 = p2["plan"]
    check("[Abastecimiento] E2-13: el plan respeta el presupuesto (gasto ≤ tope, por fecha de emisión) — verificado con las líneas devueltas",
          not v2["budget"] and spend2 <= budget_amount + 0.011 and spend2 > 0, f"gasto {spend2:.2f} ≤ presupuesto {budget_amount:.2f} (sin tope: {spend1:.2f})")
    check("[Abastecimiento] E2-13: el plan respeta la capacidad de almacenamiento (pico tras recepciones por sucursal) y de proveedor",
          not v2["storage"] and not v2["supplier_capacity"], "; ".join(v2["storage"][:2] + v2["supplier_capacity"][:2]) or f"{len(capsl)} capacidades de almacén, oferta con tope por período")
    check("[Abastecimiento] E2-13: mínimos, múltiplos, plazos, balance y faltantes del plan restringido verificados línea por línea",
          not any(v2[k] for k in ("lines", "min_multiple", "lead", "balance", "shortfalls")) and p2["lines"], f"{len(p2['lines'])} líneas; " + "; ".join(x for k in v2 for x in v2[k][:2]))
    stor = [c_ for c_ in p2["constraints"] if c_["family"] == "storage_capacity"]
    pn = p2.get("_peak_no_purchases", {})
    check("[Abastecimiento] E2-12/13: el exceso de almacenamiento preexistente (órdenes abiertas + existencias) se informa y coincide con mi reconstrucción sin compras nuevas",
          all(close(c_["limit"], max(c_["declared_limit"], pn.get((c_["ref"], c_["period"]), 0)), rel=5e-3, abs_=1.0) for c_ in stor if c_["binding"] or c_["preexisting_excess"])
          and all((c_["preexisting_excess"] > 0) == (pn.get((c_["ref"], c_["period"]), 0) > c_["declared_limit"] + 0.5) for c_ in stor),
          f"{len(stor)} restricciones de almacén; con exceso preexistente: {[(c_['ref'], c_['period'], round(c_['preexisting_excess'], 1)) for c_ in stor if c_['preexisting_excess']][:3]}")
    check("[Abastecimiento] las restricciones activas informadas son coherentes (uso ≤ límite) y el estado del solver es honesto",
          all(c_["used"] <= c_["limit"] + 1e-6 for c_ in p2["constraints"]) and pl2["solver_status"] in {"optimal", "feasible_with_gap", "time_limit", "heuristic_fallback"}
          and (pl2["is_optimal"] == (pl2["solver_status"] == "optimal")) and env2["result_type"] == "optimized_plan",
          f"estado {pl2['solver_status']}, brecha {pl2['mip_gap']}, {len(p2['constraints'])} restricciones activas "
          f"{sorted({c_['family'] for c_ in p2['constraints']})}, no atendido {pl2['unmet_units_total']:.1f} (sin tope: {pl['unmet_units_total']:.1f})")
    check("[Abastecimiento] un presupuesto menor no mejora la cobertura: el no atendido no baja respecto al plan sin tope y se declara por causa",
          pl2["unmet_units_total"] >= pl["unmet_units_total"] - 1e-6 and (spend2 >= spend1 - 1e-6 or pl2["unmet_units_total"] > pl["unmet_units_total"] - 1e-6)
          and all(s["reason"] in {"lead_time", "budget", "supplier_capacity", "storage_capacity", "resource_capacity", "economic", "no_offer", "mixed"} for s in p2["shortfalls"]),
          f"causas {sorted({s['reason'] for s in p2['shortfalls']})}")
    # infactibilidad explicada
    p3, env3 = create_plan(a, "cobertura obligatoria (infactible por plazos)", objective="min_cost", coverage_mode="hard", config=cfg)
    pl3 = p3["plan"]
    check("[Abastecimiento] E2-13: con cobertura obligatoria y plazos imposibles el estado es 'infeasible' (no 'óptimo'), sin plan de compras y con causa y alternativa explicadas",
          pl3["solver_status"] == "infeasible" and not pl3["is_optimal"] and not p3["lines"] and bool(p3["infeasibility"]) and bool(p3["alternative_plan"]),
          f"estado {pl3['solver_status']}; infactibilidad: {json.dumps(p3['infeasibility'], ensure_ascii=False)[:230]}")
    ids["plan3"] = p3["run"]["id"]
    # ── unidades y monedas declaradas explícitamente: las ofertas pasan a ser comparables ──
    a.req("POST", "/unit-conversions", (200, 201), json={"product_sku": prods[unit_prod]["sku"], "from_unit": "pallet", "to_unit": prods[unit_prod]["unit"], "factor": 40, "source": "prueba E2E"})
    a.req("POST", "/supply/exchange-rates", (201,), json={"from_currency": "EUR", "to_currency": "USD", "rate": 1.08, "valid_on": as_of.isoformat(), "source": "prueba E2E"})
    o_unit2 = a.json("GET", f"/supply/offers/{o_unit['id']}")["data"]
    o_fx2 = a.json("GET", f"/supply/offers/{o_fx['id']}")["data"]
    check("[Abastecimiento] E2-26: al declarar la conversión pallet→saco (×40) y la tasa EUR→USD (1,08) las ofertas pasan a ser comparables, con la conversión explícita",
          o_unit2["comparability"]["comparable"] and o_unit2["comparability"]["unit_conversion"]["factor"] == 40
          and o_fx2["comparability"]["comparable"] and close(o_fx2["comparability"]["exchange_rate"]["rate"], 1.08),
          f"unidad: {o_unit2['comparability']['unit_conversion']}, moneda: {o_fx2['comparability']['exchange_rate']}")
    lst = a.json("GET", "/supply/plans")["data"]
    check("[Abastecimiento] listado de planes con tipo de resultado y plan vigente único", len(lst) >= 3 and all(x["result_type"] == "optimized_plan" for x in lst), f"{len(lst)} planes")

    # ── pronóstico de costos (E2-15) ──
    t0 = time.time()
    cr = a.req("POST", "/supply/cost-forecasts", (202,), json={}).json()["data"]
    wait_run(a, (cr.get("training_job") or {}).get("id"), "pronóstico de costos")
    ids["cost_run"] = cr["id"]
    cf = get_all(a, "/supply/cost-forecasts", {"run_id": cr["id"]})
    rows, resp = cf
    envc = resp["envelope"]
    check("[Abastecimiento] pronóstico de costos con envoltura (core cost, forecast, config y snapshot) y calidad por serie",
          envc["core"] == "cost" and envc["result_type"] == "forecast" and envc["config_version"] and envc["data_snapshot_id"] is not None and rows
          and all(r["quality_status"] in VALID_QUALITY for r in rows), f"{len(rows)} series, calidad global {envc['quality_status']} ({time.time() - t0:.0f} s)")
    bad_sel, n_other = [], 0
    for r in rows:
        if r["quality_status"] == "insufficient_data" or not r["points"]:
            continue
        m_ = r["metrics"]
        if r["method"] != r["reference_method"]:
            n_other += 1
            if not (m_[r["method"]]["selection"]["mae"] <= 0.97 * m_[r["reference_method"]]["selection"]["mae"] + 1e-12):
                bad_sel.append(r["product_id"])
        if not all(p_["reference"] is not None and p_["yhat"] is not None for p_ in r["points"]):
            bad_sel.append(r["product_id"])
    check("[Abastecimiento] costos: solo se sirve un método distinto de la referencia si mejora el MAE de selección ≥ 3 % (comprobado con las métricas devueltas); holdout reportado",
          not bad_sel and rows and all("holdout" in r["metrics"][r["reference_method"]] for r in rows if r["metrics"]),
          f"{n_other} series con método propio, métodos {sorted({r['method'] for r in rows})}, referencia {sorted({r['reference_method'] for r in rows})}")
    abst = [r for r in rows if r["quality_status"] == "insufficient_data"]
    check("[Abastecimiento] costos: las series con historia insuficiente se abstienen explícitamente (sin puntos inventados)", all(not r["points"] for r in abst), f"{len(abst)} abstenciones")

    # ── escenarios (E2-15) ── (los *_pct son fracciones: 0,05 = 5 %; rangos de la validación: precio/costo −0,9…5, descuento 0…0,95, demanda −0,95…5)
    body = {"horizon_days": 28, "strategies": [
        {"key": "base", "name": "Base"},
        {"key": "precio", "name": "Subir precio 5 %", "price_change_pct": 0.05, "demand_change_pct": -0.03},
        {"key": "promo", "name": "Descuento 10 % con costo pronosticado", "discount_pct": 0.10, "demand_change_pct": 0.08, "use_cost_forecast": True},
        {"key": "plan", "name": "Ejecutar el plan restringido", "plan_run_id": p2["run"]["id"], "supply_fill_rate": 1.0},
    ]}
    sr = a.req("POST", "/supply/scenarios/evaluate", (201,), json=body).json()
    res, ens = sr["data"]["results"], sr["envelope"]
    ids["scenario_run"] = sr["data"]["run_id"]
    strat = {s_["key"]: s_ for s_ in res["strategies"]}
    classes = {i["classification"] for s_ in res["strategies"] for i in s_["inputs"]}
    assumed_names = {k: {i["input"] for i in strat[k]["inputs"] if i["classification"] == "assumed"} for k in strat}
    check("[Abastecimiento] escenarios: entradas clasificadas observed/forecasted/assumed; los cambios de precio, descuento y demanda son supuestos; resultado condicionado",
          ens["result_type"] == "conditional_simulation" and classes <= {"observed", "forecasted", "assumed"} and {"observed", "forecasted", "assumed"} <= classes
          and {"price_change", "demand_change"} <= assumed_names["precio"] and {"discount", "demand_change"} <= assumed_names["promo"]
          and any(i["input"] == "demand_base" and i["classification"] == "forecasted" for i in strat["base"]["inputs"])
          and any(i["input"] == "opening_inventory" and i["classification"] == "observed" for i in strat["base"]["inputs"])
          and any(i["input"] == "unit_cost_base" and i["classification"] == "forecasted" for i in strat["promo"]["inputs"])
          and "no es una estimación causal" in res["disclaimer"] and "NO se deriva" in " ".join(ens["assumptions"]),
          f"resumen {res['classification_summary']}; supuestos por estrategia { {k: sorted(v) for k, v in assumed_names.items()} }")
    cb = res["common_basis"]
    keys_by = {k: {(x["branch_id"], x["product_id"]) for x in s_["by_series"]} for k, s_ in strat.items()}
    bad_series = [(k, x["branch_id"], x["product_id"]) for k, s_ in strat.items() for x in s_["by_series"]
                  if not (close(x["attainable_sales"] + x["unmet"], x["demand"], rel=1e-3, abs_=0.02) and x["attainable_sales"] <= x["demand"] + 1e-6
                          and x["revenue"] >= -1e-9 and x["ending_inventory"] >= -1e-9 and x["demand"] >= 0)]
    check("[Abastecimiento] escenarios comparables: misma base (corte, pronóstico, horizonte, series) y, por serie, ventas atendibles + no atendido = demanda, sin cantidades ni ingresos negativos",
          cb["horizon_days"] == 28 and cb["forecast_run_id"] == ids["demand_run90"] and len({frozenset(v) for v in keys_by.values()}) == 1 and len(keys_by["base"]) == cb["series"] and not bad_series,
          f"base común: {cb['series']} series, corte {cb['as_of']}, demanda de la ejecución {cb['forecast_run_id']}" + (f"; series incoherentes: {bad_series[:3]}" if bad_series else ""))
    unit_note = strat["base"]["metrics"].get("units_note")
    check("[Abastecimiento] productos con unidades distintas: no se suman unidades (solo importes) y se declara",
          (unit_note is not None and strat["base"]["metrics"]["demand_units"] is None) or (unit_note is None and strat["base"]["metrics"]["demand_units"] is not None),
          unit_note or "una sola unidad en la base")

    def utot(k: str, field: str) -> float:
        return sum(x[field] for x in strat[k]["by_series"] if x["unit"] == "unidad")
    b_, pr_, plan_ = strat["base"]["metrics"], strat["precio"]["metrics"], strat["plan"]["metrics"]
    ratio = pr_["revenue"] / b_["revenue"] if b_["revenue"] else None
    check("[Abastecimiento] escenario de precio +5 % con demanda −3 % (supuestos): la demanda cae 3 % e ingresos suben entre +1,5 % y +5 % (ventas atendibles limitadas por existencias)",
          close(utot("precio", "demand"), utot("base", "demand") * 0.97, rel=2e-3, abs_=0.1) and ratio is not None and 1.015 <= ratio <= 1.0505,
          f"demanda (unidades) {utot('base', 'demand'):.0f} → {utot('precio', 'demand'):.0f}; ingresos base {b_['revenue']:.0f} → {pr_['revenue']:.0f} (×{ratio:.4f})" if ratio else f"ingresos base {b_['revenue']}")
    check("[Abastecimiento] escenario de descuento 10 % + demanda +8 % con costo pronosticado: ingresos y margen coherentes (precio efectivo −10 %)",
          strat["promo"]["metrics"]["revenue"] > 0 and strat["promo"]["metrics"]["revenue"] <= b_["revenue"] * 1.08 * 1.0001 and strat["promo"]["metrics"]["gross_margin"] is not None,
          f"ingresos promo {strat['promo']['metrics']['revenue']:.0f} (base {b_['revenue']:.0f}), margen {strat['promo']['metrics']['gross_margin']}")
    check("[Abastecimiento] escenario «ejecutar el plan»: las recepciones del plan aumentan o igualan las ventas atendibles; figura el desembolso de compras del plan",
          utot("plan", "attainable_sales") >= utot("base", "attainable_sales") - 1e-6 and plan_["plan_purchase_outlay"] is not None and plan_["plan_purchase_outlay"] > 0
          and "plan_receipts" in assumed_names["plan"],
          f"ventas atendibles (unidades) base {utot('base', 'attainable_sales'):.0f} → plan {utot('plan', 'attainable_sales'):.0f}; compras del plan ${plan_['plan_purchase_outlay']}")
    unknown_margin = [k for k, s_ in strat.items() if s_["metrics"]["gross_margin"] is None and not s_["metrics"]["revenue_without_known_cost"]]
    check("[Abastecimiento] el margen desconocido no se presenta como cero (margen calculado o ingresos sin costo conocido declarados)", not unknown_margin and b_["gross_margin"] is not None,
          f"margen bruto base {b_['gross_margin']} ({b_['gross_margin_pct']}), ranking por margen {res['ranking_by_gross_margin']}")
    # entradas fuera de rango: la forma escalar debe validarse igual que la lista (rango de demanda −0,95…5)
    bad_s = a.req("POST", "/supply/scenarios/evaluate", (201, 422), json={"horizon_days": 14, "strategies": [{"key": "mala", "name": "Demanda −300 %", "demand_change_pct": -3}]})
    bad_l = a.req("POST", "/supply/scenarios/evaluate", (201, 422), json={"horizon_days": 14, "strategies": [{"key": "mala", "name": "Demanda −300 %", "demand_change_pct": [-3]}]})
    extra = ""
    if bad_s.status_code == 201:
        m_ = bad_s.json()["data"]["results"]["strategies"][0]["metrics"]
        extra = f"; con el escalar −3 se aceptó y devolvió ingresos {m_['revenue']} (negativos) y llenado {m_['fill_rate']}"
    check("[Abastecimiento] escenarios: un cambio de demanda fuera de rango (−300 %) se rechaza con 422 tanto en forma de lista como escalar",
          bad_l.status_code == 422 and bad_s.status_code == 422, f"lista [-3] → HTTP {bad_l.status_code}; escalar -3 → HTTP {bad_s.status_code}{extra}")
    evals = a.json("GET", "/supply/scenarios/evaluations")["data"]
    check("[Abastecimiento] las evaluaciones de escenarios se consultan por listado y por id", evals and a.json("GET", f"/supply/scenarios/evaluations/{evals[0]['id']}")["data"], f"{len(evals)} evaluaciones")
    # ── causalidad (E2-16) ──
    for label, cb_ in (("sin definir el efecto", {}), ("con el efecto definido completo", {"treatment": "price", "outcome": "units", "unit_of_analysis": "product_week", "estimand": "ATE",
                                                                                          "horizon_days": 28, "population": "productos activos"})):
        cz = a.req("POST", "/supply/causal/check", (200,), json=cb_).json()
        d_ = cz["data"]
        check(f"[Abastecimiento] E2-16: verificación causal {label} → insufficient_evidence, sin efecto publicado y estimador deshabilitado; la simulación condicionada sigue disponible",
              d_["verdict"] == "insufficient_evidence" and d_["effect"] is None and d_["estimator_enabled"] is False and d_["conditional_simulation"]["available"] is True
              and (cz.get("envelope") or {}).get("quality_status", "insufficient_evidence") == "insufficient_evidence",
              f"veredicto {d_['verdict']}, requisitos {[(q['id'], q['status']) for q in d_['requirements']]}")


# ───────────────────────────── 5. Datos ─────────────────────────────
ERP_DB = "predictily_erp_source_e2e"
ERP_SCHEMA = """
CREATE TABLE erp_clientes (id_cliente serial PRIMARY KEY, ruc varchar(13), nombre varchar(100));
CREATE TABLE erp_productos (cod_item varchar(30) PRIMARY KEY, descripcion varchar(120), linea varchar(50), unidad varchar(20), activo boolean DEFAULT true, precio numeric(14,4), costo numeric(14,4));
CREATE TABLE erp_facturas (
    id_factura serial PRIMARY KEY, num_factura varchar(30) UNIQUE NOT NULL, tipo varchar(20) NOT NULL, estado varchar(20) NOT NULL,
    fecha_emision timestamp NOT NULL, cod_sucursal varchar(10) NOT NULL, id_cliente int REFERENCES erp_clientes, canal varchar(20),
    modificado_en timestamptz NOT NULL DEFAULT now());
CREATE TABLE erp_factura_lineas (
    id_factura int NOT NULL REFERENCES erp_facturas, linea int NOT NULL, cod_item varchar(30) NOT NULL, cantidad numeric(14,4) NOT NULL,
    precio_unit numeric(14,4) NOT NULL, descuento numeric(14,4) NOT NULL DEFAULT 0, iva numeric(14,4) NOT NULL DEFAULT 0, subtotal numeric(14,4), costo_unit numeric(14,4),
    PRIMARY KEY (id_factura, linea));
"""
SALES_QUERY = """SELECT f.num_factura, f.tipo, f.estado, f.fecha_emision, f.cod_sucursal, l.linea AS num_linea, l.cod_item, p.descripcion, p.linea AS categoria,
       l.cantidad, l.precio_unit, l.descuento, l.iva, l.subtotal, l.costo_unit, c.ruc AS cliente, f.canal, f.modificado_en
FROM erp_facturas f
JOIN erp_factura_lineas l ON l.id_factura = f.id_factura
JOIN erp_productos p ON p.cod_item = l.cod_item
LEFT JOIN erp_clientes c ON c.id_cliente = f.id_cliente
WHERE f.modificado_en >= :since
ORDER BY f.id_factura, l.linea"""
SALES_MAP = {"document_number": "num_factura", "document_type": "tipo", "status": "estado", "issued_at": "fecha_emision", "branch_code": "cod_sucursal",
             "line_number": "num_linea", "product_sku": "cod_item", "product_name": "descripcion", "category": "categoria", "quantity": "cantidad",
             "unit_price": "precio_unit", "discount": "descuento", "tax": "iva", "net_amount": "subtotal", "unit_cost": "costo_unit",
             "customer_code": "cliente", "channel": "canal", "source_recorded_at": "modificado_en"}


def build_erp(skus: list[tuple[str, str, str]]) -> None:
    """Crea (recrea) la base «ERP de origen» ficticia con esquema mínimo tipo ERP y unas pocas facturas ficticias."""
    with pg_connect("postgres", autocommit=True) as conn:
        conn.execute(f'DROP DATABASE IF EXISTS "{ERP_DB}" WITH (FORCE)')
        conn.execute(f'CREATE DATABASE "{ERP_DB}"')
    with pg_connect(ERP_DB, autocommit=True) as conn:
        conn.execute(ERP_SCHEMA)
        conn.execute("INSERT INTO erp_clientes (ruc, nombre) VALUES ('1710034065', 'Cliente Ficticio Uno'), ('9999999999999', 'CONSUMIDOR FINAL')")
        for sku, name, cat in skus:
            conn.execute("INSERT INTO erp_productos (cod_item, descripcion, linea, unidad, precio, costo) VALUES (%s, %s, %s, 'unidad', 8.5, 6.9)", (sku, name, cat))
        today = today_local()
        d = lambda ago: (today - dt.timedelta(days=ago)).isoformat()  # noqa: E731
        s1, s2, s3 = skus[0][0], skus[1][0], skus[2][0]
        docs = [
            ("901-001-000000001", "FACTURA", "EMITIDA", f"{d(4)} 09:15:00", 1, "LOCAL", [(s1, 10, 8.5, 0, 10.2, 85, 6.9), (s2, 5, 7.2, 1, 5.25, 35, 5.1)]),
            ("901-001-000000002", "FACTURA", "EMITIDA", f"{d(4)} 11:00:00", 2, "MAYOREO", [(s1, 4, 8.5, 0, 4.08, 34, 6.9)]),
            ("901-001-000000003", "FACTURA", "ANULADA", f"{d(3)} 16:30:00", None, None, [(s3, 2, 15, 0, 3.6, 30, 11)]),
            ("901-001-000000004", "FACTURA", "EMITIDA", f"{d(3)} 10:05:00", 1, "LOCAL", [(s3, 3, 15, 0, 5.4, 45, 11), (s1, 6, 8.5, 0, 6.12, 51, 6.9)]),
            ("901-001-000000005", "FACTURA", "EMITIDA", f"{d(2)} 15:45:00", 2, "LOCAL", [(s2, 8, 7.2, 0, 6.91, 57.6, 5.1)]),
            ("901-001-000000006", "NOTA DE CREDITO", "EMITIDA", f"{d(1)} 12:00:00", 1, "LOCAL", [(s1, 1, 8.5, 0, 1.02, 8.5, 6.9)]),
        ]
        for number, tipo, estado, issued, cust, canal, lines in docs:
            fid = conn.execute("INSERT INTO erp_facturas (num_factura, tipo, estado, fecha_emision, cod_sucursal, id_cliente, canal, modificado_en) VALUES (%s,%s,%s,%s,'001',%s,%s,%s::timestamp AT TIME ZONE 'America/Guayaquil') RETURNING id_factura",
                               (number, tipo, estado, issued, cust, canal, issued)).fetchone()[0]
            for i, (sku, qty, price, disc, tax, net, cost) in enumerate(lines, 1):
                conn.execute("INSERT INTO erp_factura_lineas (id_factura, linea, cod_item, cantidad, precio_unit, descuento, iva, subtotal, costo_unit) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                             (fid, i, sku, qty, price, disc, tax, net, cost))


def erp_scalar(sql: str):
    with pg_connect(ERP_DB, autocommit=True) as conn:
        return conn.execute(sql).fetchone()[0]


def wait_sync(a: mvp.Client, sync_id: int, timeout: int = 300) -> dict:
    t0 = time.time()
    while time.time() - t0 < timeout:
        s = a.json("GET", f"/syncs/{sync_id}")["data"]
        if s["status"] in {"completed", "needs_review", "failed"}:
            return s
        time.sleep(1.5)
    raise SystemExit(f"sincronización {sync_id}: tiempo agotado")


def block_data(a: mvp.Client) -> None:
    ids = S["ids"]
    # catálogo de conectores honesto
    conns = {c_["key"]: c_ for c_ in a.json("GET", "/connectors")["data"]}
    check("[Datos] catálogo de conectores: PostgreSQL probado con base de pruebas; MySQL/SQL Server 'implemented_untested' (nunca 'available')",
          conns["sql_postgresql"]["verification"] == "tested_with_test_database" and conns["sql_postgresql"]["status"] == "requires_credentials"
          and conns["sql_mysql"]["status"] == "implemented_untested" and conns["sql_sqlserver"]["status"] == "implemented_untested"
          and "available" not in {conns["sql_mysql"]["status"], conns["sql_sqlserver"]["status"]},
          {k: (v["status"], v["verification"]) for k, v in conns.items() if k.startswith("sql_")})
    # ── cobertura declarada ──
    cov0 = a.json("GET", "/data-coverage")
    check("[Datos] sin declarar, la cobertura efectiva de ventas es 'none' (todo día sin registros es ausente, no cero)", cov0["effective"]["sales"]["rule"] == "none")
    last = (S.get("last_sale_date") or "2026-10-26")
    fut = a.req("PUT", "/data-coverage", (422,), json={"entity": "sales", "complete_through": (today_local() + dt.timedelta(days=5)).isoformat()})
    check("[Datos] cobertura con fecha futura → 422", fut.status_code == 422, fut.text[:90])
    dec = a.req("PUT", "/data-coverage", (200, 201), json={"entity": "sales", "complete_from": "2024-10-27", "complete_through": last, "notes": "Declarada por la prueba E2E"}).json()
    cov1 = a.json("GET", "/data-coverage")
    eff = cov1["effective"]["sales"]
    check("[Datos] cobertura declarada de ventas: basis=declared y cobertura efectiva rule=declared_by_user",
          dec["data"]["basis"] == "declared" and eff["rule"] == "declared_by_user" and eff["complete_through"] == last and eff["complete_from"] == "2024-10-27",
          f"efectiva {eff['complete_from']}→{eff['complete_through']} ({eff['rule']})")
    qual = a.json("GET", "/data-quality/extended")["data"]
    check("[Datos] reporte de calidad extendido con cobertura temporal, días sin ventas e identidad de compradores",
          {"temporal_coverage", "sales_days", "customer_identity"} <= set(qual["sections"]), f"secciones {sorted(qual['sections'])}, estado {qual['overall_status']}")
    # ── configuración efectiva con procedencia ──
    cfgs = {core: a.json("GET", "/tenant/effective-config", params={"core": core})["data"] for core in ("demand", "customer", "supply")}
    prov_ok = all(k["source"] in {"core"} or k["source"].startswith("profile:") or k["source"] == "tenant" for d_ in cfgs.values() for k in d_["keys"])
    check("[Datos] configuración efectiva por core con procedencia por clave (core / perfil / empresa) y precedencia declarada",
          prov_ok and all(d_["precedence"] == ["core", "profile", "tenant"] and d_["config_version"] for d_ in cfgs.values())
          and any(k["source"] == "tenant" for k in cfgs["demand"]["keys"]) and any(k["source"] == "core" for k in cfgs["demand"]["keys"]),
          {c_: {k["key"]: k["source"] for k in d_["keys"]} for c_, d_ in cfgs.items() if c_ == "demand"})
    run_cfg_before = a.json("GET", f"/forecast-runs/{ids['demand_run90']}")["data"]["config_version"]
    put = a.req("PUT", "/tenant/core-config/demand", (200,), json={"overrides": {"min_improvement": 0.05}}).json()
    eff2 = {k["key"]: k for k in a.json("GET", "/tenant/effective-config", params={"core": "demand"})["data"]["keys"]}
    check("[Datos] el cambio de configuración de empresa se refleja con procedencia 'tenant'",
          eff2["min_improvement"]["value"] == 0.05 and eff2["min_improvement"]["source"] == "tenant", json.dumps(eff2["min_improvement"]))
    bad_key = a.req("PUT", "/tenant/core-config/demand", (422,), json={"overrides": {"clave_inexistente": 1}})
    bad_rng = a.req("PUT", "/tenant/core-config/demand", (422,), json={"overrides": {"min_improvement": "mucho"}})
    check("[Datos] clave desconocida o valor inválido → 422 (nunca se aplica en silencio)", bad_key.status_code == 422 and bad_rng.status_code == 422, f"{bad_key.json().get('code')} / {bad_rng.json().get('code')}")
    check("[Datos] cambiar la configuración después no reescribe ejecuciones ya registradas (config_version intacta)",
          a.json("GET", f"/forecast-runs/{ids['demand_run90']}")["data"]["config_version"] == run_cfg_before, run_cfg_before[:16] + "…")
    a.json("PUT", "/tenant/core-config/demand", 200, json={"overrides": {}})
    eff3 = {k["key"]: k for k in a.json("GET", "/tenant/effective-config", params={"core": "demand"})["data"]["keys"]}
    check("[Datos] vaciar los valores de empresa restablece el valor del core", eff3["min_improvement"]["source"] == "core" and eff3["min_improvement"]["value"] == 0.03)

    # ── fuente SQL PostgreSQL de solo lectura (E2-25) ──
    prods = a.json("GET", "/products", params={"per_page": 3, "sort": "-units_90d"})["data"]
    skus = [(p["sku"], p["name"], (p["category"] or {}).get("name") or "General") for p in prods]
    build_erp(skus)
    check("[Datos] base «ERP de origen» ficticia creada con esquema tipo ERP y 6 documentos", erp_scalar("select count(*) from erp_facturas") == 6 and erp_scalar("select count(*) from erp_factura_lineas") == 8, ERP_DB)
    cfg_src = {"host": os.environ.get("E2E_PG_HOST", "127.0.0.1"), "port": int(os.environ.get("E2E_PG_PORT", "5432")), "database": ERP_DB, "statement_timeout_seconds": 30}
    cred = {"username": os.environ.get("E2E_PG_USER", "predictily"), "password": os.environ.get("E2E_PG_PASSWORD", "secret")}
    src = a.req("POST", "/data-sources", (201,), json={"type": "connector", "connector_key": "sql_postgresql", "name": "ERP ficticio E2E (PostgreSQL)", "config": cfg_src, "credentials": cred}).json()["data"]
    ids["source_id"] = src["id"]
    listing = a.req("GET", "/data-sources", (200,)).text
    test = a.req("POST", f"/data-sources/{src['id']}/test", (200,))
    leak = [name for name, body in (("creación", json.dumps(src)), ("listado", listing), ("prueba", test.text)) if cred["password"] in body]
    td = test.json()["data"]
    check("[Datos] fuente SQL creada y prueba de conexión OK en transacción de solo lectura; las credenciales jamás se devuelven",
          td["ok"] is True and td["details"]["server_version"] and "read_only_enforcement" in td["details"] and not leak and src.get("has_credentials") is True,
          f"servidor {td['details']['server_version']}; solo lectura: {str(td['details']['read_only_enforcement'])[:60]}; advertencias {td['details'].get('warnings')}; fugas {leak}")
    # intentos de escritura rechazados
    before = {t: erp_scalar(f"select count(*) from {t}") for t in ("erp_facturas", "erp_factura_lineas", "erp_clientes", "erp_productos")}
    attempts = ["UPDATE erp_facturas SET estado = 'ANULADA'", "DELETE FROM erp_facturas WHERE num_factura = '901-001-000000001'", "SELECT 1; DROP TABLE erp_facturas",
                "INSERT INTO erp_clientes (ruc, nombre) VALUES ('x', 'y')", "WITH d AS (DELETE FROM erp_factura_lineas RETURNING *) SELECT * FROM d",
                "SELECT * INTO nueva_tabla FROM erp_facturas", "SELECT pg_sleep(60)", "SELECT * FROM erp_facturas FOR UPDATE", "TRUNCATE erp_facturas", "CREATE TABLE x AS SELECT 1"]
    rej = []
    for sql in attempts:
        r = a.req("POST", f"/data-sources/{src['id']}/mappings", (422, 201), json={"entity": "sales", "query": sql, "field_map": SALES_MAP, "options": {}})
        rej.append(r.status_code == 422 and r.json().get("code") == "sql_query_rejected")
    after = {t: erp_scalar(f"select count(*) from {t}") for t in before}
    n_maps = len(a.json("GET", f"/data-sources/{src['id']}/mappings")["data"])
    check("[Datos] E2-25: consultas con escritura/DDL/varias sentencias/efectos laterales son rechazadas (422 sql_query_rejected), no se crea mapeo y la base de origen no cambia",
          all(rej) and n_maps == 0 and before == after, f"{sum(rej)}/{len(attempts)} rechazadas; mapeos {n_maps}; filas de origen {after}")
    # mapeo → aprobación → sincronización
    mp = a.req("POST", f"/data-sources/{src['id']}/mappings", (201,), json={"entity": "sales", "query": SALES_QUERY, "field_map": SALES_MAP, "options": {"coverage": {"enabled": True, "lag_days": 1}}}).json()["data"]
    ids["mapping_id"] = mp["id"]
    blocked = a.req("POST", f"/data-sources/{src['id']}/sync", (409,), json={"entity": "sales", "mode": "full"})
    prev = a.req("POST", f"/data-sources/{src['id']}/mappings/{mp['id']}/preview", (200,)).json()["data"]
    check("[Datos] el mapeo nace sin aprobar: no sincroniza (409 mapping_not_approved) pero su vista previa muestra campos canónicos sin importar nada",
          mp["is_active"] is False and mp["version"] == 1 and blocked.json().get("code") == "mapping_not_approved" and "document_number" in prev["headers"],
          f"versión {mp['version']}, vista previa {len(prev.get('rows', []))} filas")
    a.req("POST", f"/data-sources/{src['id']}/mappings/{mp['id']}/approve", (200,))
    docs_before = a.json("GET", "/sales/summary", params={"granularity": "month", "date_from": "2024-01-01", "date_to": "2026-12-31"})["data"]["totals"]["documents"]
    sy = a.req("POST", f"/data-sources/{src['id']}/sync", (202,), json={"entity": "sales", "mode": "full"}).json()["data"]
    ids["sync_id"] = sy["id"]
    s1 = wait_sync(a, sy["id"])
    imp = (s1.get("stats") or {}).get("import", {})
    docs_after = a.json("GET", "/sales/summary", params={"granularity": "month", "date_from": "2024-01-01", "date_to": "2026-12-31"})["data"]["totals"]["documents"]
    sales_mine = [x for x in get_all(a, "/sales", {"date_from": (today_local() - dt.timedelta(days=10)).isoformat(), "date_to": today_local().isoformat()})[0] if x["document_number"].startswith("901-001-")]
    check("[Datos] sincronización completa: 8 líneas extraídas, 6 documentos nuevos por el mismo importador (con anulada y nota de crédito), archivo de preparación eliminado",
          s1["status"] == "completed" and s1["rows_extracted"] == 8 and imp.get("documents_new") == 6 and imp.get("rows_error") == 0 and s1["import_job_id"]
          and (s1["stats"].get("staged_file_deleted") is True) and len(sales_mine) == 6 and {x["status"] for x in sales_mine} >= {"completed", "voided"}
          and any(x["document_type"] == "credit_note" for x in sales_mine),
          f"{s1['rows_extracted']} filas, {imp.get('documents_new')} documentos nuevos; ventas totales {docs_before} → {docs_after}")
    # reimportación sin duplicados
    s2 = wait_sync(a, a.req("POST", f"/data-sources/{src['id']}/sync", (202,), json={"entity": "sales", "mode": "full"}).json()["data"]["id"])
    imp2 = (s2.get("stats") or {}).get("import", {})
    docs_re = a.json("GET", "/sales/summary", params={"granularity": "month", "date_from": "2024-01-01", "date_to": "2026-12-31"})["data"]["totals"]["documents"]
    check("[Datos] E2-25: re-sincronizar no duplica (0 nuevos, 6 sin cambios, 0 actualizados; total de documentos igual)",
          s2["status"] == "completed" and imp2.get("documents_new") == 0 and imp2.get("documents_unchanged") == 6 and imp2.get("documents_updated") == 0 and docs_re == docs_after,
          f"nuevos {imp2.get('documents_new')}, sin cambios {imp2.get('documents_unchanged')}, documentos {docs_after} → {docs_re}")
    # corrección en origen: incremental actualiza y conserva procedencia
    with pg_connect(ERP_DB, autocommit=True) as conn:
        conn.execute("UPDATE erp_factura_lineas SET cantidad = 12, subtotal = 12 * precio_unit - descuento WHERE cod_item = %s AND id_factura = (SELECT id_factura FROM erp_facturas WHERE num_factura = '901-001-000000001')", (skus[0][0],))
        conn.execute("UPDATE erp_facturas SET modificado_en = now() WHERE num_factura = '901-001-000000001'")
    s3 = wait_sync(a, a.req("POST", f"/data-sources/{src['id']}/sync", (202,), json={"entity": "sales", "mode": "incremental"}).json()["data"]["id"])
    imp3 = (s3.get("stats") or {}).get("import", {})
    inv = next(x for x in get_all(a, "/sales", {"date_from": (today_local() - dt.timedelta(days=10)).isoformat(), "date_to": today_local().isoformat()})[0] if x["document_number"] == "901-001-000000001")
    det = a.json("GET", f"/sales/{inv['id']}")["data"]
    qty = [it["quantity"] for it in det.get("items", []) if it.get("line_number") == 1]
    docs_inc = a.json("GET", "/sales/summary", params={"granularity": "month", "date_from": "2024-01-01", "date_to": "2026-12-31"})["data"]["totals"]["documents"]
    check("[Datos] E2-25: la corrección en origen entra en la sincronización incremental (1 documento actualizado, sin duplicados, líneas reemplazadas)",
          s3["status"] == "completed" and s3["mode"] == "incremental" and s3["since"] and imp3.get("documents_updated") == 1 and imp3.get("documents_new") == 0 and docs_inc == docs_after
          and (not qty or close(qty[0], 12)), f"actualizados {imp3.get('documents_updated')}, cantidad de la línea 1: {qty}")
    try:
        with pg_connect(OPTS.app_db) as conn:
            row = conn.execute("select data_source_id, import_job_id from sales where id=%s", (inv["id"],)).fetchone()
        check("[Datos] E2-25: la corrección conserva la procedencia (data_source_id) y registra la importación que la aplicó (verificación en la BD de la aplicación)",
              row and row[0] == src["id"] and row[1] == s3["import_job_id"], f"fuente {row[0]}, importación {row[1]} (sync {s3['import_job_id']})")
    except Exception as exc:  # noqa: BLE001
        warn("[Datos] procedencia de la corrección no verificable (sin acceso a la BD de la aplicación)", str(exc)[:100])
    cov2 = a.json("GET", "/data-coverage")
    src_cov = [x for x in cov2["data"] if x["data_source_id"] == src["id"] and x["entity"] == "sales"]
    check("[Datos] la sincronización infiere cobertura de la fuente (basis=inferred) sin pisar la declaración del usuario (efectiva = declared_by_user)",
          src_cov and src_cov[0]["basis"] == "inferred" and cov2["effective"]["sales"]["rule"] == "declared_by_user" and cov2["effective"]["sales"]["complete_through"] == last,
          f"inferida hasta {src_cov[0]['complete_through'] if src_cov else None}; efectiva {cov2['effective']['sales']['complete_through']} ({cov2['effective']['sales']['rule']})")
    sync_list = a.json("GET", f"/data-sources/{src['id']}/syncs")["data"]
    check("[Datos] historial de sincronizaciones con versión de mapeo y marca de agua", len(sync_list) == 3 and all(s_["mapping_version"] == 1 for s_ in sync_list) and sync_list[0]["watermark"], f"{len(sync_list)} sincronizaciones")


# ───────────────────────────── 6. Asistente ─────────────────────────────
def block_assistant(a: mvp.Client) -> None:
    st = a.req("GET", "/assistant/status", (200,))
    d = st.json()["data"]
    check("[Asistente] estado desactivado sin clave (enabled=false, reason=missing_api_key) y la clave/endpoint no se exponen",
          d["enabled"] is False and d["reason"] == "missing_api_key" and "api_key" not in st.text.lower().replace("missing_api_key", "") and "bearer" not in st.text.lower(),
          f"proveedor {d['provider']}, modelo {d['model']}, herramientas {len(d['tools'])}")
    check("[Asistente] el aviso indica que los resultados de los cores siguen disponibles", "siguen disponibles" in d["notice"])
    msg = a.req("POST", "/assistant/messages", (503,), json={"message": "¿Cuánto venderé el próximo mes?"})
    mj = msg.json()
    check("[Asistente] POST /assistant/messages → 503 assistant_disabled (con motivo, sin llamadas externas)", mj.get("code") == "assistant_disabled" and mj.get("reason") == "missing_api_key", mj.get("message", "")[:90])
    # los cores siguen funcionando con el asistente apagado
    dash = a.json("GET", "/dashboard/summary", params={"horizon": 30})["data"]
    cp = a.json("GET", "/customer/predictions", params={"horizon": 30, "per_page": 1})
    pl = a.json("GET", "/supply/plans")["data"]
    check("[Asistente] E2-24 autonomía: demanda, comportamiento y abastecimiento responden con el asistente apagado",
          dash["kpis"]["forecast_units"] is not None and cp["meta"]["total"] > 0 and pl and a.json("GET", "/data-coverage")["effective"], f"{cp['meta']['total']} predicciones, {len(pl)} planes")
    t0 = time.time()
    cr = a.req("POST", "/supply/cost-forecasts", (202,), json={}).json()["data"]
    j = wait_run(a, (cr.get("training_job") or {}).get("id"), "pronóstico de costos (asistente apagado)")
    sc = a.req("POST", "/supply/scenarios/evaluate", (201,), json={"horizon_days": 14, "strategies": [{"key": "b", "name": "Base"}]})
    check("[Asistente] E2-24 autonomía: se pueden ejecutar nuevos cálculos (costos, escenarios) tras el rechazo del asistente", j["status"] == "succeeded" and sc.status_code == 201, f"{time.time() - t0:.0f} s")


# ───────────────────────────── 7. Aislamiento ─────────────────────────────
def block_isolation(api: str, a: mvp.Client) -> None:
    ids = S["ids"]
    b = mvp.Client(api)
    mvp.setup_company(b, "distribuidora_costa", "Distribuidora E2E etapa 2 (prueba ficticia)", "distribuidora", "B")
    sj = mvp.run_import(b, DEMO / "distribuidora_costa_sales.csv", "sales")
    mvp.run_import(b, DEMO / "distribuidora_costa_inventory.csv", "inventory")
    check("[B] importa ventas de su propia empresa", sj["status"] == "completed", f"{sj['stats'].get('documents_new')} documentos")
    run_b = run_demand(b, 7)
    check("[B] pronóstico propio de la empresa B (versión 1, vigente), independiente de A",
          run_b["version"] == 1 and run_b["is_current"] and run_b["envelope"]["tenant_id"] != (a.json("GET", f"/forecast-runs/{ids['demand_run90']}")["data"]["envelope"]["tenant_id"]),
          f"ejecución {run_b['id']}")
    pb = run_customer(b, "predict")
    nb = b.json("GET", "/customer/predictions", params={"horizon": 30})
    check("[B] E2-17: predecir compradores sin modelos propios se abstiene (insufficient_data, 0 filas): no usa los modelos ni los datos de A",
          pb["quality_status"] == "insufficient_data" and nb["meta"]["total"] == 0 and nb["envelope"]["quality_status"] == "insufficient_data" and not b.json("GET", "/models", params={"core": "customer"})["data"],
          f"calidad {pb['quality_status']}, filas {nb['meta']['total']}")
    prods_b = b.json("GET", "/products", params={"per_page": 5})["data"]
    # recursos propios de B
    sup_b = b.req("POST", "/suppliers", (201,), json={"code": "B-PROV-1", "name": "Proveedor de B"}).json()["data"]
    off_b = b.req("POST", "/supply/offers", (201,), json={"supplier_id": sup_b["id"], "product_id": prods_b[0]["id"], "unit_cost": 1.5, "currency": "USD", "unit": prods_b[0]["unit"], "lead_time_days": 5}).json()["data"]
    br_b = b.json("GET", "/branches")["data"][0]
    bud_b = b.req("POST", "/supply/budgets", (201,), json={"period_start": "2026-10-01", "period_end": "2026-12-31", "amount": 1000, "currency": "USD"}).json()["data"]
    cap_b = b.req("POST", "/supply/capacities", (201,), json={"branch_id": br_b["id"], "capacity": 5000, "capacity_unit": "unidad"}).json()["data"]
    com_b = b.req("POST", "/commitments", (201,), json={"commitment_type": "order", "external_id": "B-PED-1", "status": "confirmed", "issued_at": f"{today_local() - dt.timedelta(days=1)}T10:00:00-05:00",
                                                        "expected_delivery_date": (today_local() + dt.timedelta(days=5)).isoformat(), "items": [{"product_sku": prods_b[0]["sku"], "quantity": 7}]}).json()["data"]
    src_b = b.req("POST", "/data-sources", (201,), json={"type": "connector", "connector_key": "sql_postgresql", "name": "Fuente de B", "config": {"host": os.environ.get("E2E_PG_HOST", "127.0.0.1"), "database": ERP_DB},
                                                         "credentials": {"username": os.environ.get("E2E_PG_USER", "predictily"), "password": os.environ.get("E2E_PG_PASSWORD", "secret")}}).json()["data"]
    map_b = b.req("POST", f"/data-sources/{src_b['id']}/mappings", (201,), json={"entity": "sales", "query": SALES_QUERY, "field_map": SALES_MAP, "options": {}}).json()["data"]
    check("[B] crea sus propios proveedores, ofertas, presupuesto, capacidad, compromiso, fuente y mapeo", all([sup_b, off_b, bud_b, cap_b, com_b, src_b, map_b]))

    # ── B no accede por id a recursos de A (404) ──
    a_ids = {k: v for k, v in ids.items() if isinstance(v, int)}
    probes = [
        ("GET", f"/forecast-runs/{ids['demand_run90']}", None, "ejecución de demanda"),
        ("GET", f"/accuracy/summary?run_id={ids['demand_run90']}", None, "precisión/manifiesto de una ejecución"),
        ("GET", f"/forecasts/series?run_id={ids['demand_run90']}", None, "serie de una ejecución"),
        ("GET", f"/jobs/{ids['demand_job']}", None, "trabajo de A"),
        ("POST", "/models/demand/rollback", {"run_id": ids["demand_run14"]}, "reversión hacia una ejecución de A"),
        ("GET", f"/customer/runs/{ids['customer_train']}", None, "entrenamiento de compradores"),
        ("GET", f"/customer/runs/{ids.get('customer_predict3', ids['customer_predict2'])}", None, "predicción de compradores"),
        ("GET", f"/customer/predictions?horizon=30&run_id={ids.get('customer_predict3', ids['customer_predict2'])}", None, "predicciones por run_id"),
        ("GET", f"/customer/evaluation?horizon=30&run_id={ids['customer_train']}", None, "evaluación por run_id"),
        ("GET", f"/customer/reconciliation?horizon=30&run_id={ids.get('customer_predict3', ids['customer_predict2'])}", None, "conciliación por run_id"),
        ("GET", f"/customer/customers/{S['customers_with_pred'][90][0]['customer']['id']}/profile", None, "perfil de un comprador de A"),
        ("GET", f"/customer/predictions?horizon=30&customer_id={S['customers_with_pred'][90][0]['customer']['id']}", None, "predicciones de un comprador de A"),
        ("GET", f"/supply/plans/{ids['plan2']}", None, "plan de abastecimiento"),
        ("GET", f"/supply/offers/{ids['offer_id']}", None, "oferta de A"),
        ("PATCH", f"/supply/offers/{ids['offer_id']}", {"unit_cost": 0.01}, "edición de una oferta de A"),
        ("DELETE", f"/supply/offers/{ids['offer_id']}", None, "baja de una oferta de A"),
        ("GET", f"/supply/budgets/{ids['budget_id']}", None, "presupuesto de A"),
        ("GET", f"/supply/capacities/{ids['capacity_id']}", None, "capacidad de A"),
        ("GET", f"/supply/cost-forecasts?run_id={ids['cost_run']}", None, "pronóstico de costos"),
        ("GET", f"/supply/scenarios/evaluations/{ids['scenario_run']}", None, "evaluación de escenarios"),
        ("GET", f"/data-sources/{ids['source_id']}/mappings", None, "mapeos de la fuente de A"),
        ("GET", f"/data-sources/{ids['source_id']}/mappings/{ids['mapping_id']}", None, "mapeo de A"),
        ("POST", f"/data-sources/{ids['source_id']}/mappings/{ids['mapping_id']}/deactivate", None, "desactivar el mapeo de A"),
        ("POST", f"/data-sources/{ids['source_id']}/sync", {"entity": "sales", "mode": "full"}, "sincronizar la fuente de A"),
        ("POST", f"/data-sources/{ids['source_id']}/test", None, "probar la fuente de A"),
        ("GET", f"/data-sources/{ids['source_id']}/syncs", None, "sincronizaciones de A"),
        ("GET", f"/syncs/{ids['sync_id']}", None, "sincronización de A"),
        ("GET", f"/commitments/{ids['commitment_id']}", None, "compromiso de A"),
        ("POST", f"/commitments/{ids['commitment_id']}/cancel", {}, "cancelar un compromiso de A"),
        ("GET", f"/products/{S['ids']['supply_products']['X']}", None, "producto de A"),
    ]
    leaks = []
    for method, path, body, what in probes:
        r = b.req(method, path, (200, 201, 202, 204, 400, 401, 402, 403, 404, 409, 422), **({"json": body} if body is not None else {}))
        ok = r.status_code in (403, 404, 422) or (r.status_code == 200 and not (r.json().get("data") or []))
        if not ok:
            leaks.append(f"{method} {path} → {r.status_code}")
        check(f"[B] E2-17: no accede a {what}", ok, f"{method} {path.split('?')[0]} → HTTP {r.status_code}")
    # model_registry del Customer Core de A
    a_models = a.json("GET", "/models", params={"core": "customer"})["data"]
    if a_models:
        r = b.req("POST", "/models/customer/rollback", (404, 422, 402, 409), json={"model_registry_id": a_models[0]["id"]})
        check("[B] E2-17: no revierte modelos de compradores de A", r.status_code == 404, f"HTTP {r.status_code}")
    # sus listados no incluyen nada de A
    lists = [("/forecast-runs", "ejecuciones de demanda"), ("/customer/runs", "ejecuciones de compradores"), ("/supply/plans", "planes"),
             ("/supply/offers", "ofertas"), ("/supply/budgets", "presupuestos"), ("/supply/capacities", "capacidades"), ("/data-sources", "fuentes"),
             ("/commitments", "compromisos"), ("/imports", "importaciones"), ("/models", "modelos"), ("/supply/cost-forecasts/runs", "pronósticos de costos"),
             ("/supply/scenarios/evaluations", "escenarios"), ("/suppliers", "proveedores")]
    for path, what in lists:
        mine_ids = {x["id"] for x in get_all(a, path)[0]}
        theirs = get_all(b, path)[0]
        their_ids = {x["id"] for x in theirs}
        check(f"[B] E2-17: su listado de {what} no incluye nada de A", not (mine_ids & their_ids), f"A {len(mine_ids)} · B {len(their_ids)}")
    cp = b.json("GET", "/customer/predictions", params={"horizon": 30})
    check("[B] E2-17: sus predicciones de compradores están vacías con abstención explícita (no hereda modelos ni resultados de A)",
          cp["data"] == [] and cp["envelope"]["quality_status"] == "insufficient_data", cp["envelope"]["quality_status"])
    cmt_b = [x["external_id"] for x in get_all(b, "/commitments")[0]]
    check("[B] E2-17: sus compromisos son solo los suyos", cmt_b == ["B-PED-1"], str(cmt_b))
    srcs_b = [x["name"] for x in b.json("GET", "/data-sources")["data"]]
    check("[B] E2-17: sus fuentes son solo las suyas", srcs_b == ["Fuente de B"], str(srcs_b))
    # tampoco usando el X-Tenant-ID de A
    b.tenant = a.tenant
    r = b.req("GET", "/supply/plans", (404, 403))
    r2 = b.req("GET", "/commitments", (404, 403))
    b.tenant = None
    check("[B] E2-17: no puede usar el X-Tenant-ID de A", r.status_code in (403, 404) and r2.status_code in (403, 404), f"HTTP {r.status_code}/{r2.status_code}")
    # A no fue contaminada ni alterada por los intentos de B
    offer_a = a.json("GET", f"/supply/offers/{ids['offer_id']}")["data"]
    maps_a = a.json("GET", f"/data-sources/{ids['source_id']}/mappings/{ids['mapping_id']}")["data"]
    cm_a = a.json("GET", f"/commitments/{ids['commitment_id']}")["data"]
    check("[A] E2-17: tras los intentos de B, la oferta, el mapeo aprobado y el compromiso de A siguen intactos",
          offer_a["unit_cost"] != 0.01 and maps_a["is_active"] is True and cm_a["status"] != "cancelled", f"oferta ${offer_a['unit_cost']}, mapeo activo={maps_a['is_active']}, compromiso {cm_a['status']}")
    a_off = {x["external_id"] for x in get_all(a, "/commitments")[0]}
    check("[A] E2-17: los recursos creados por B no aparecen en A", "B-PED-1" not in a_off and "Fuente de B" not in [x["name"] for x in a.json("GET", "/data-sources")["data"]])


# ───────────────────────────── principal ─────────────────────────────
def main() -> int:
    global OPTS
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--api", default="http://127.0.0.1:8000/api/v1")
    ap.add_argument("--log", help="archivo donde guardar la salida (además de la consola)")
    ap.add_argument("--only", help="bloques a ejecutar, separados por comas: demand,customer,commitments,supply,data,assistant,isolation")
    ap.add_argument("--state", help="(desarrollo) archivo JSON para reutilizar la empresa A entre ejecuciones; no se usa en la evidencia")
    ap.add_argument("--engine-dir", default=str(ROOT / "engine"), help="directorio del motor (para verificar artefactos de forma independiente)")
    ap.add_argument("--app-db", default="predictily", help="BD de la aplicación para comprobaciones de apoyo de solo lectura (id del genérico, procedencia)")
    ap.add_argument("--workdir", default=str(Path(os.environ.get("TMPDIR", "/tmp"))), help="directorio para archivos temporales")
    OPTS = ap.parse_args()
    log_fh = None
    if OPTS.log:
        Path(OPTS.log).parent.mkdir(parents=True, exist_ok=True)
        log_fh = open(OPTS.log, "w", encoding="utf-8")
        sys.stdout = Tee(sys.__stdout__, log_fh)
    t_start = time.time()
    print(f"Predictily · aceptación E2E de la etapa 2 · {dt.datetime.now(TZ):%Y-%m-%d %H:%M} (America/Guayaquil) · API {OPTS.api}")
    wanted = set((OPTS.only or "demand,customer,commitments,supply,data,assistant,isolation").split(","))
    S["ids"] = {}

    with block("Preparación (empresa A: registro, sucursales, catálogo, ventas, inventario, plazos)"):
        r = httpx.get(OPTS.api.replace("/api/v1", "") + "/up", timeout=10)
        check("backend responde (/up)", r.status_code == 200)
        a = setup_a(OPTS.api)
        S["a"] = a
        st = a.json("GET", "/assistant/status")["data"]
        check("[A] el asistente conversacional está desactivado desde el inicio (todo lo siguiente corre sin LLM)", st["enabled"] is False, st["reason"])
        sales = a.json("GET", "/sales", params={"per_page": 1, "sort": "-sale_date"})["data"]
        S["last_sale_date"] = sales[0]["sale_date"] if sales else None
    a = S.get("a")
    if a is None:
        print("No se pudo preparar la empresa A: se aborta.")
    else:
        for key, name, fn in (("demand", "1 · Demanda v2 (horizontes, envoltura, manifiesto, reversión)", block_demand),
                              ("customer", "2 · Compradores (train/predict, calibración, coherencia, conciliación)", block_customer),
                              ("commitments", "3 · Compromisos comerciales (sin doble conteo)", block_commitments),
                              ("supply", "4 · Abastecimiento, costos, escenarios y causalidad", block_supply),
                              ("data", "5 · Datos (cobertura, configuración efectiva, fuente SQL)", block_data),
                              ("assistant", "6 · Asistente desactivado y autonomía de los cores", block_assistant)):
            if key in wanted:
                with block(name):
                    fn(a)
        if "isolation" in wanted:
            with block("7 · Aislamiento entre empresas (E2-17)"):
                block_isolation(OPTS.api, a)

    ok = sum(1 for _, s, _ in RESULTS if s == "OK")
    fail = [(n, d) for n, s, d in RESULTS if s == "FALLA"]
    avisos = [(n, d) for n, s, d in RESULTS if s == "AVISO"]
    print("\n══ Tiempos por bloque ══")
    for k, v in TIMES.items():
        print(f"  {v:6.0f} s  {k}")
    print(f"  {time.time() - t_start:6.0f} s  TOTAL")
    print(f"\nResumen: {ok} de {ok + len(fail)} verificaciones OK · {len(fail)} FALLA · {len(avisos)} AVISO")
    for n, d in fail:
        print(f"  [FALLA] {n} — {d}")
    for n, d in avisos:
        print(f"  [AVISO] {n} — {d}")
    if log_fh:
        sys.stdout = sys.__stdout__
        log_fh.close()
    return 1 if fail else 0


if __name__ == "__main__":
    sys.exit(main())
