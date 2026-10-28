#!/usr/bin/env python3
"""Prueba de aceptación del MVP de Predictily (docs/01, criterios 1–13).

Recorre la API pública real (Laravel) con el motor Python y el worker de colas
corriendo, exactamente como lo haría un cliente:

  registro → empresa → sucursales → importación (con errores y corregida) →
  inventario y plazos → pronóstico real → dashboard / productos / precisión /
  abastecimiento → reimportación sin duplicados → nueva versión →
  segunda empresa aislada de la primera.

Uso:
  python e2e/mvp_acceptance.py --api http://127.0.0.1:8000/api/v1

Requisitos: backend + `queue:work` + motor activos; datos demo en data/demo/.
Crea usuarios y empresas NUEVOS con sufijo aleatorio (no toca las empresas demo).
Dependencia: httpx (incluida en engine/.venv).
"""
from __future__ import annotations

import argparse
import csv
import sys
import time
import uuid
from pathlib import Path

import httpx

ROOT = Path(__file__).resolve().parents[1]
DEMO = ROOT / "data" / "demo"
RESULTS: list[tuple[str, bool, str]] = []


def check(step: str, ok: bool, detail: str = "") -> None:
    RESULTS.append((step, ok, detail))
    print(f"[{'OK ' if ok else 'FALLA'}] {step}" + (f" — {detail}" if detail else ""), flush=True)
    if not ok:
        raise SystemExit(f"Criterio no cumplido: {step} {detail}")


class Client:
    def __init__(self, api: str) -> None:
        self.http = httpx.Client(base_url=api, timeout=120, headers={"Accept": "application/json"})
        self.tenant: str | None = None

    def _headers(self) -> dict[str, str]:
        return {"X-Tenant-ID": self.tenant} if self.tenant else {}

    def req(self, method: str, path: str, expect: int | tuple[int, ...] = 200, **kw) -> httpx.Response:
        r = self.http.request(method, path, headers={**self._headers(), **kw.pop("headers", {})}, **kw)
        allowed = (expect,) if isinstance(expect, int) else expect
        if r.status_code not in allowed:
            raise SystemExit(f"{method} {path} → {r.status_code} (esperado {allowed}): {r.text[:500]}")
        return r

    def json(self, method: str, path: str, expect: int | tuple[int, ...] = 200, **kw) -> dict:
        return self.req(method, path, expect, **kw).json()


def register(c: Client, label: str) -> str:
    email = f"e2e.{label}.{uuid.uuid4().hex[:8]}@predictily.test"
    data = c.json("POST", "/auth/register", 201, json={
        "name": f"Usuario E2E {label}", "email": email,
        "password": "Prueba2026segura", "password_confirmation": "Prueba2026segura", "accept_terms": True,
    })["data"]
    token = data.get("token") or c.json("POST", "/auth/login", json={"email": email, "password": "Prueba2026segura"})["data"]["token"]
    c.http.headers["Authorization"] = f"Bearer {token}"
    return email


def wait_import(c: Client, import_id: int, targets: set[str], timeout: int = 600) -> dict:
    t0 = time.time()
    while time.time() - t0 < timeout:
        job = c.json("GET", f"/imports/{import_id}")["data"]
        if job["status"] in targets:
            return job
        if job["status"] in {"failed", "rejected"} and job["status"] not in targets:
            raise SystemExit(f"Importación {import_id} en estado {job['status']}: {job.get('error_message')}")
        time.sleep(1.5)
    raise SystemExit(f"Timeout esperando importación {import_id}")


def run_import(c: Client, path: Path, entity: str, options: dict | None = None, confirm: bool = True) -> dict:
    with path.open("rb") as fh:
        job = c.json("POST", "/imports", 201, data={"entity_type": entity}, files={"file": (path.name, fh, "text/csv")})["data"]
    mapping = job.get("suggested_mapping") or {}
    # Columnas auxiliares que no forman parte del esquema (p. ej. nota_calidad) no se mapean.
    c.json("PUT", f"/imports/{job['id']}/mapping", 202, json={"mapping": mapping, "options": options or {}})
    job = wait_import(c, job["id"], {"validated", "failed"})
    if confirm and job["status"] == "validated":
        c.json("POST", f"/imports/{job['id']}/confirm", 202)
        job = wait_import(c, job["id"], {"completed"})
    return job


def wait_job(c: Client, job_id: int, timeout: int = 900) -> dict:
    t0 = time.time()
    last = None
    while time.time() - t0 < timeout:
        job = c.json("GET", f"/jobs/{job_id}")["data"]
        if (job["stage"], job["progress"]) != last:
            last = (job["stage"], job["progress"])
            print(f"      job {job_id}: {job['status']} · {job['stage']} · {job['progress']}%", flush=True)
        if job["status"] in {"succeeded", "failed"}:
            return job
        time.sleep(2)
    raise SystemExit(f"Timeout esperando job {job_id}")


def request_forecast(c: Client) -> dict:
    run = c.json("POST", "/forecast-runs", 202, json={"horizon_days": 90})["data"]
    job_id = (run.get("training_job") or {}).get("id")
    job = wait_job(c, job_id)
    check("Trabajo asíncrono de pronóstico finaliza", job["status"] == "succeeded",
          f"job {job_id} {job['status']} {job.get('error_message') or ''} en {job.get('duration_ms')} ms")
    return c.json("GET", f"/forecast-runs/{run['id']}")["data"]


def setup_company(c: Client, slug: str, name: str, sector: str, label: str) -> dict:
    register(c, label)
    check(f"[{label}] 1. Registro de empresario", True)
    tenant = c.json("POST", "/tenants", 201, json={"name": name, "sector": sector, "timezone": "America/Guayaquil", "currency": "USD"})["data"]
    c.tenant = tenant["uuid"]
    check(f"[{label}] 1. Empresa creada", bool(c.tenant), tenant["uuid"])

    with (DEMO / f"{slug}_branches.csv").open(encoding="utf-8") as fh:
        for row in csv.DictReader(fh):
            c.json("POST", "/branches", 201, json={k: row[k] for k in ("code", "name", "province", "canton", "commercial_zone") if row.get(k)})
    c.json("PATCH", "/tenant", 200, json={"settings": {"service_level": 0.95, "review_period_days": 7, "default_lead_time_days": 10}})
    branches = c.json("GET", "/branches")["data"]
    check(f"[{label}] 2. Configura negocio y sucursales", len(branches) >= 2, f"{len(branches)} sucursales")
    return tenant


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--api", default="http://127.0.0.1:8000/api/v1")
    args = ap.parse_args()

    # ───────────────────────────── Empresa A ─────────────────────────────
    a = Client(args.api)
    setup_company(a, "ferreteria_andina", "Ferretería E2E (prueba ficticia)", "ferreteria", "A")

    # 3–4. Importación con errores: el reporte de calidad debe detectarlos y no permitir confirmar.
    bad = run_import(a, DEMO / "ferreteria_andina_sales_with_errors.csv", "sales", confirm=False)
    errors = a.json("GET", f"/imports/{bad['id']}/errors", params={"per_page": 100})
    n_err = bad["stats"].get("rows_error", 0)
    codes = sorted({e["error_code"] for e in errors["data"]})
    check("[A] 4. Validación detecta errores reales del archivo", n_err > 0, f"{n_err} filas con error; códigos {codes[:8]}")
    blocked = a.req("POST", f"/imports/{bad['id']}/confirm", expect=(409, 422))
    check("[A] 4. No se puede confirmar con errores sin 'skip_invalid_rows'", blocked.status_code in (409, 422))
    # Corrección: el usuario elige importar solo filas válidas y revalida.
    a.json("PUT", f"/imports/{bad['id']}/mapping", 202, json={"mapping": bad["column_mapping"] or bad["suggested_mapping"], "options": {"skip_invalid_rows": True}})
    bad = wait_import(a, bad["id"], {"validated"})
    a.json("POST", f"/imports/{bad['id']}/reject", 200)
    check("[A] 4. Corrige opciones, revalida y rechaza el archivo defectuoso", True)

    # 3. Importación del histórico limpio (CSV real de ~99k líneas).
    t0 = time.time()
    sales = run_import(a, DEMO / "ferreteria_andina_sales.csv", "sales")
    st = sales["stats"]
    check("[A] 3. Importa ventas históricas", sales["status"] == "completed",
          f"{st.get('documents_new')} documentos nuevos, {st.get('return_lines')} líneas de devolución, "
          f"{st.get('voided_documents')} anulados, {time.time() - t0:.0f} s")
    for entity, fname in (("inventory", "inventory"), ("lead_times", "lead_times")):
        job = run_import(a, DEMO / f"ferreteria_andina_{fname}.csv", entity)
        check(f"[A] 3. Importa {entity}", job["status"] == "completed", f"{job['stats'].get('rows_valid')} filas")

    # 5–6. Procesamiento y pronóstico con modelos reales.
    run1 = request_forecast(a)
    sel = run1.get("selection") or {}
    check("[A] 6. Pronóstico generado por modelo estadístico/ML real", run1["status"] == "completed" and bool(sel),
          f"versión {run1['version']}, modelos { {k: v.get('model_family') for k, v in sel.items() if isinstance(v, dict)} }")

    # 7. Ventas previstas.
    series = a.json("GET", "/forecasts/series", params={"horizon": 30, "granularity": "week"})["data"]
    check("[A] 7. Visualiza ventas previstas", len(series["forecast"]) > 0 and len(series["history"]) > 0,
          f"{len(series['history'])} periodos históricos, {len(series['forecast'])} pronosticados, nodo {series['node']['key']}")
    dash = a.json("GET", "/dashboard/summary", params={"horizon": 30})["data"]
    k = dash["kpis"]
    check("[A] 7. Dashboard responde con KPIs reales", k.get("forecast_units") is not None,
          f"30 días: {k['forecast_units']:.0f} u. (80%: {k.get('forecast_units_lo_80')}–{k.get('forecast_units_hi_80')}), "
          f"ingresos ${k.get('forecast_revenue') or 0:,.0f}, riesgo quiebre {k.get('stockout_risk_products')}, compra ${k.get('purchase_investment') or 0:,.0f}")

    # 8. Mayor y menor demanda.
    up = a.json("GET", "/forecasts/products", params={"horizon": 30, "sort": "-change_pct", "per_page": 3})["data"]
    down = a.json("GET", "/forecasts/products", params={"horizon": 30, "sort": "change_pct", "per_page": 3})["data"]
    top = a.json("GET", "/forecasts/products", params={"horizon": 30, "sort": "-forecast_units", "per_page": 3})["data"]
    check("[A] 8. Productos de mayor/menor demanda", bool(up and down and top),
          f"más vendido: {top[0]['product']['name']} ({top[0]['forecast_units']:.0f} u.); "
          f"sube: {up[0]['product']['name']} ({up[0]['change_pct']:+.0%}); baja: {down[0]['product']['name']} ({down[0]['change_pct']:+.0%})")

    # 9. Evaluación histórica.
    acc = a.json("GET", "/accuracy/summary")["data"]
    bt = acc["backtest"]
    check("[A] 9. Evaluación histórica (backtesting) disponible", bt["selected"].get("wape") is not None and bt["baseline"].get("wape") is not None,
          f"WAPE seleccionado {bt['selected']['wape']:.3f} vs baseline {bt['baseline']['wape']:.3f}, "
          f"cobertura 80% {bt['selected'].get('coverage_80')}")

    # 10. Abastecimiento.
    recs = a.json("GET", "/inventory/recommendations", params={"sort": "-stockout_probability", "per_page": 5})
    r0 = recs["data"][0] if recs["data"] else None
    check("[A] 10. Recomendación básica de abastecimiento", r0 is not None,
          f"{recs['meta']['total']} recomendaciones; primera: {r0['product']['name']} @ {r0['branch']['name']}, "
          f"cobertura {r0['days_of_cover']} días, sugerido {r0['suggested_qty']} u., riesgo {r0['risk_level']}" if r0 else "")

    # 11. Reimportación sin duplicados.
    before = a.json("GET", "/sales/summary", params={"granularity": "month", "date_from": "2024-01-01", "date_to": "2026-12-31"})["data"]["totals"]
    again = run_import(a, DEMO / "ferreteria_andina_sales.csv", "sales")
    after = a.json("GET", "/sales/summary", params={"granularity": "month", "date_from": "2024-01-01", "date_to": "2026-12-31"})["data"]["totals"]
    st2 = again["stats"]
    check("[A] 11. Reimportar no genera duplicados",
          st2.get("documents_new") == 0 and before["documents"] == after["documents"] and before["units"] == after["units"],
          f"nuevos {st2.get('documents_new')}, sin cambios {st2.get('documents_unchanged')}, documentos {before['documents']}→{after['documents']}")

    # 12. Nuevo pronóstico versionado.
    run2 = request_forecast(a)
    runs = a.json("GET", "/forecast-runs")["data"]
    current = [r for r in runs if r.get("is_current")]
    check("[A] 12. Regenera pronóstico y consulta su versión", run2["version"] == run1["version"] + 1 and len(current) == 1 and current[0]["id"] == run2["id"],
          f"versiones {[r['version'] for r in runs]}, vigente v{current[0]['version'] if current else '?'}")

    # ───────────────────────────── Empresa B ─────────────────────────────
    b = Client(args.api)
    setup_company(b, "distribuidora_costa", "Distribuidora E2E (prueba ficticia)", "distribuidora", "B")
    sales_b = run_import(b, DEMO / "distribuidora_costa_sales.csv", "sales")
    run_import(b, DEMO / "distribuidora_costa_inventory.csv", "inventory")
    check("[B] 3. Importa ventas", sales_b["status"] == "completed", f"{sales_b['stats'].get('documents_new')} documentos")
    run_b = request_forecast(b)
    check("[B] 6. Pronóstico propio", run_b["status"] == "completed" and run_b["version"] == 1, f"versión {run_b['version']}")

    # 13. Aislamiento: B no puede leer nada de A.
    prod_a = top[0]["product"]["id"]
    probes = {
        f"/forecast-runs/{run2['id']}": "run de A",
        f"/products/{prod_a}": "producto de A",
        f"/imports/{sales['id']}": "importación de A",
        f"/jobs/{(run2.get('training_job') or {}).get('id')}": "trabajo de A",
    }
    for path, what in probes.items():
        r = b.req("GET", path, expect=(404,))
        check(f"[B] 13. No accede a {what}", r.status_code == 404)
    r = b.req("GET", "/forecasts/series", expect=(404, 422), params={"run_id": run2["id"]})
    check("[B] 13. No consulta pronósticos de A por run_id", r.status_code in (404, 422))
    b.tenant = a.tenant
    r = b.req("GET", "/dashboard/summary", expect=(404,))
    check("[B] 13. No puede usar el X-Tenant-ID de A", r.status_code == 404)
    b.tenant = None
    tenants_b = b.json("GET", "/tenants")["data"]
    check("[B] 13. Solo ve su propia empresa", len(tenants_b) == 1 and tenants_b[0]["uuid"] != a.tenant)

    print("\nResumen:", sum(ok for _, ok, _ in RESULTS), "de", len(RESULTS), "verificaciones OK")


if __name__ == "__main__":
    try:
        main()
    except SystemExit as exc:
        if exc.code not in (0, None):
            print(f"\n✗ {exc}", file=sys.stderr)
            sys.exit(1)
