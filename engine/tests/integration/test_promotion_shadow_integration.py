"""W3-B · integración PostgreSQL de la promoción y el shadow (ADR-V25-21): decisiones por política y alcance en `model_promotion_decisions`
(tenant correcto, FKs compuestas, `is_effective`), predicciones del challenger en `shadow_forecast_values` sin servirse, champion tomado de la
ejecución vigente anterior, gobierno por empresa y evaluación prospectiva (`evaluation_type = 'shadow_prospective'`) con la MISMA censura de días
que la evaluación servida.

Las empresas son ficticias; las pruebas con datos fabricados se revierten.
"""

from __future__ import annotations

from contextlib import contextmanager
from datetime import date, timedelta

import pandas as pd
import pytest
from asof_helpers import World, add_document, make_world
from sqlalchemy import text
from sqlalchemy.exc import DBAPIError

from conftest import AUTH
from loader import create_run, load_company
from predictily_engine.db.repository import TenantRepository
from predictily_engine.demand.manifest import verify
from predictily_engine.evaluation import actuals as actuals_module
from predictily_engine.evaluation.actuals import _evaluate
from predictily_engine.pipeline import forecast_run as forecast_run_module
from predictily_engine.synthetic import generate_company

pytestmark = pytest.mark.integration

HORIZONS = [7, 14, 15, 30, 60, 90]
POLICIES = {"legacy", "v25"}


def _execute(client, run_id, tenant_id, job_id):
    return client.post(f"/v1/forecast-runs/{run_id}/execute", headers=AUTH,
                       json={"tenant_id": tenant_id, "training_job_id": job_id, "horizons": HORIZONS})


def _run(client, engine, tenant_id, **kw):
    with engine.begin() as conn:
        run_id, job_id = create_run(conn, tenant_id, **kw)
    resp = _execute(client, run_id, tenant_id, job_id)
    assert resp.status_code == 200, resp.text
    return run_id


def _rows(engine, sql, **params):
    with engine.connect() as conn:
        return [dict(r) for r in conn.execute(text(sql), params).mappings().all()]


@pytest.fixture(scope="module")
def world(client, db_engine):
    """Empresa C (configuración por defecto: gobierna la heredada) con dos ejecuciones de producción y una histórica; empresa D (la V2.5 gobierna)."""
    c_co = generate_company("ferreteria_andina", seed=42, n_products=8, start="2025-06-01")
    d_co = generate_company("ferreteria_andina", seed=42, n_products=8, start="2025-06-01")
    with db_engine.begin() as conn:
        c = load_company(conn, c_co, "Empresa C (prueba ficticia, promoción)")
        d = load_company(conn, d_co, "Empresa D (prueba ficticia, gobierna V2.5)",
                         settings={"cores": {"promotion": {"governing_policy": "v25"}}})
        bad = load_company(conn, generate_company("ferreteria_andina", seed=42, n_products=6, start="2025-06-01"),
                           "Empresa E (prueba ficticia, configuración inválida)",
                           settings={"cores": {"promotion": {"governing_policy": "ninguna"}}})
        last = conn.execute(text("SELECT MAX(sale_date) FROM sales WHERE tenant_id = :t"), {"t": c}).scalar_one()
    out = {"c": c, "d": d, "bad": bad, "last": last}
    out["r1"] = _run(client, db_engine, c)
    out["r2"] = _run(client, db_engine, c)
    out["rd"] = _run(client, db_engine, d)
    out["hist_cutoff"] = (pd.Timestamp(last) - pd.Timedelta(days=30)).date().isoformat()
    out["rh"] = _run(client, db_engine, c, run_type="historical", cutoff=out["hist_cutoff"])
    return out


# ───────────────────────────── decisiones ─────────────────────────────

def decisions(engine, tenant, run):
    return _rows(engine, "SELECT * FROM model_promotion_decisions WHERE tenant_id = :t AND forecast_run_id = :r ORDER BY id", t=tenant, r=run)


def test_decisions_are_stored_per_policy_and_scope_with_the_right_tenant(world, db_engine):
    rows = decisions(db_engine, world["c"], world["r1"])
    assert rows and {r["policy"] for r in rows} == POLICIES and {r["core"] for r in rows} == {"demand"}
    assert all(r["tenant_id"] == world["c"] and r["core_run_id"] is None and r["approved_by"] is None and r["approved_at"] is None for r in rows)
    scopes = {r["scope_key"] for r in rows}
    assert "global" in scopes and any(s.startswith("class:") for s in scopes) and {f"horizon:{h}" for h in HORIZONS} <= scopes
    assert len({(r["policy"], r["scope_key"]) for r in rows}) == len(rows), "una fila por política y alcance"
    for r in rows:
        assert len(r["policy_version"]) == 64 and r["decision"] in ("promote", "reject", "keep_champion", "publish_baseline", "shadow")
        assert isinstance(r["gates"], dict) and r["gates"] and isinstance(r["metrics"], dict) and r["reason"]
        assert r["metrics"]["served_family"] and r["candidate_family"]
    # con la configuración por defecto gobierna la heredada; la V2.5 es la sombra de decisión; los horizontes son informativos
    for scope in scopes:
        eff = [r for r in rows if r["scope_key"] == scope and r["is_effective"]]
        if scope.startswith("horizon:"):
            assert eff == []
        else:
            assert [r["policy"] for r in eff] == ["legacy"]
    # un solo juego de versiones de política por ejecución
    assert len({r["policy_version"] for r in rows if r["policy"] == "legacy"}) == 1 == len({r["policy_version"] for r in rows if r["policy"] == "v25"})


def test_every_gate_of_the_v25_policy_carries_its_evidence(world, db_engine):
    v25 = [r for r in decisions(db_engine, world["c"], world["r1"]) if r["policy"] == "v25" and r["scope_key"] == "global"][0]
    gates = v25["gates"]
    assert {"no_leakage", "valid_data", "verifiable_artifacts", "temporal_stability", "segment_regression", "metrics_documented",
            "improvement", "human_approval"} <= set(gates) or "beats_baseline" in gates
    for name, gate in gates.items():
        assert gate["status"] in ("pass", "fail", "not_evaluable", "not_applicable", "pending") and gate["message"] and "evidence" in gate
    if "no_leakage" in gates:
        ev = gates["no_leakage"]["evidence"]
        assert gates["no_leakage"]["status"] == "pass" and ev["holdout_rows_used"] == 0 and ev["independent_holdout"] is True
        assert gates["valid_data"]["evidence"]["outcome"] in ("PASS", "WARN", "DEGRADE")
        assert gates["human_approval"]["status"] in ("pending", "not_applicable")
        assert gates["improvement"]["evidence"]["required_relative_improvement"] == 0.03
    assert v25["metrics"]["holdout_used"] is False and v25["metrics"]["windows_used"]


def test_candidate_and_champion_reference_the_registry_of_the_same_tenant(world, db_engine):
    rows = _rows(db_engine,
                 "SELECT d.id, d.candidate_family, d.champion_family, d.candidate_model_registry_id AS cid, c.model_family AS cfam, c.forecast_run_id AS crun, "
                 "d.champion_model_registry_id AS kid, k.model_family AS kfam, k.forecast_run_id AS krun "
                 "FROM model_promotion_decisions d LEFT JOIN model_registry c ON c.tenant_id = d.tenant_id AND c.id = d.candidate_model_registry_id "
                 "LEFT JOIN model_registry k ON k.tenant_id = d.tenant_id AND k.id = d.champion_model_registry_id "
                 "WHERE d.tenant_id = :t AND d.forecast_run_id = :r", t=world["c"], r=world["r2"])
    assert rows
    for r in rows:
        assert r["cid"] is not None and r["cfam"] == r["candidate_family"] and r["crun"] == world["r2"], "candidato = modelo de ESTA ejecución"
        if r["kid"] is not None:
            assert r["kfam"] == r["champion_family"] and r["krun"] == world["r1"], "champion = modelo de la ejecución vigente anterior"
    assert any(r["kid"] is not None for r in rows), "la segunda ejecución ya tiene champion registrado"


def test_the_champion_is_the_model_served_by_the_previous_current_run(world, db_engine):
    first_selection = _rows(db_engine, "SELECT selection FROM forecast_runs WHERE id = :r", r=world["r1"])[0]["selection"]
    rows = [r for r in decisions(db_engine, world["c"], world["r2"]) if r["scope_key"].startswith("class:") and r["policy"] == "legacy"]
    assert rows
    for r in rows:
        cls = r["scope_key"].split(":")[1]
        assert r["champion_family"] == first_selection[cls]["model_family"]
        assert r["decision"] in ("keep_champion", "promote", "publish_baseline")
        if r["candidate_family"] == r["champion_family"]:
            assert r["decision"] == "keep_champion"
    first = [r for r in decisions(db_engine, world["c"], world["r1"]) if r["policy"] == "legacy" and r["scope_key"].startswith("class:")]
    assert all(r["champion_model_registry_id"] is None for r in first), "la primera ejecución no tiene champion: la referencia es el baseline"
    assert {r["champion_family"] for r in first} == {"seasonal_naive"}


def test_summary_and_manifest_cite_both_policies(world, db_engine):
    run = _rows(db_engine, "SELECT summary, evaluation_manifest, selection FROM forecast_runs WHERE id = :r", r=world["r1"])[0]
    versions = {r["policy"]: r["policy_version"] for r in decisions(db_engine, world["c"], world["r1"])}
    promo, manifest = run["summary"]["promotion"], run["evaluation_manifest"]["promotion"]
    assert promo["governing_policy"] == manifest["governing_policy"] == "legacy"
    assert {p: promo["policies"][p]["policy_version"] for p in POLICIES} == versions == manifest["policy_versions"]
    assert promo["policies"]["legacy"]["effective"] is True and promo["policies"]["v25"]["effective"] is False
    assert promo["holdout_used_for_decisions"] is False and promo["approval"]["status"] == "pending"
    assert promo["shadow"]["served"] is False and promo["shadow"]["rows"] > 0
    assert verify(run["evaluation_manifest"]) == []
    # lo servido es lo que decidió la política heredada (la V2.5 solo se registra)
    legacy = {r["scope_key"]: r for r in decisions(db_engine, world["c"], world["r1"]) if r["policy"] == "legacy"}
    for cls, info in run["selection"].items():
        if cls in ("insufficient", "fallback"):
            continue
        key = "global" if cls == "pooled" else f"class:{cls}"
        assert info["model_family"] == legacy[key]["metrics"]["served_family"] or info.get("fallback_applied")


def test_the_v25_policy_governs_when_the_company_asks_for_it(world, db_engine):
    rows = decisions(db_engine, world["d"], world["rd"])
    effective = {r["scope_key"]: r for r in rows if r["is_effective"]}
    assert effective and {r["policy"] for r in effective.values()} == {"v25"}
    run = _rows(db_engine, "SELECT summary, selection, evaluation_manifest FROM forecast_runs WHERE id = :r", r=world["rd"])[0]
    assert run["summary"]["promotion"]["governing_policy"] == "v25" and run["evaluation_manifest"]["promotion"]["governing_policy"] == "v25"
    for cls, info in run["selection"].items():
        if cls in ("insufficient", "fallback"):
            continue
        key = "global" if cls == "pooled" else f"class:{cls}"
        assert info["model_family"] == effective[key]["metrics"]["served_family"], "lo servido es lo que decidió la V2.5"
    production = run["evaluation_manifest"]["decisions"]["model_selection_production"]
    assert production["policy"] == "v25" and set(production["windows"]).isdisjoint(run["evaluation_manifest"]["promotion"]["windows"]["holdout_windows"])
    assert verify(run["evaluation_manifest"]) == [], "con la V2.5 el período final sigue intacto"


def test_an_invalid_promotion_config_is_rejected_not_applied(client, db_engine, world):
    with db_engine.begin() as conn:
        run_id, job_id = create_run(conn, world["bad"])
    resp = _execute(client, run_id, world["bad"], job_id)
    assert resp.status_code == 422 and resp.json()["code"] == "invalid_configuration" and "promoción" in resp.json()["message"]
    assert decisions(db_engine, world["bad"], run_id) == []


def test_decisions_do_not_leak_between_companies_and_the_foreign_keys_are_composite(world, db_engine):
    mixed = _rows(db_engine, "SELECT COUNT(*) AS n FROM model_promotion_decisions d JOIN forecast_runs f ON f.id = d.forecast_run_id "
                             "WHERE d.tenant_id <> f.tenant_id")
    assert mixed[0]["n"] == 0
    assert {r["tenant_id"] for r in _rows(db_engine, "SELECT tenant_id FROM model_promotion_decisions WHERE forecast_run_id = :r", r=world["rd"])} == {world["d"]}
    with db_engine.connect() as conn:
        tx = conn.begin()
        try:
            for sql in (
                # decisión de la empresa C que apunta a una ejecución de la D
                "INSERT INTO model_promotion_decisions (tenant_id, core, forecast_run_id, scope_key, policy, policy_version, candidate_family, decision, reason) "
                "VALUES (:c, 'demand', :rd, 'global', 'legacy', repeat('a', 64), 'naive', 'promote', 'x')",
                # shadow de C sobre una ejecución de la D
                "INSERT INTO shadow_forecast_values (tenant_id, forecast_run_id, model_family, node_key, level, target_date, horizon_step, yhat) "
                "VALUES (:c, :rd, 'naive', 'series:1:1', 'series', '2030-01-01', 1, 1)",
                # política fuera del contrato
                "INSERT INTO model_promotion_decisions (tenant_id, core, forecast_run_id, scope_key, policy, policy_version, candidate_family, decision, reason) "
                "VALUES (:c, 'demand', :rc, 'global', 'otra', repeat('a', 64), 'naive', 'promote', 'x')",
            ):
                with pytest.raises(DBAPIError):
                    with conn.begin_nested():
                        conn.execute(text(sql), {"c": world["c"], "rd": world["rd"], "rc": world["r1"]})
        finally:
            tx.rollback()


def test_persisting_the_decisions_and_the_shadow_again_replaces_them(world, db_engine, pipeline_result):
    """Reintentar la persistencia de una ejecución no duplica filas (la transacción se revierte: no toca los datos del resto de las pruebas)."""
    from predictily_engine.pipeline.forecast_run import persist_promotion_decisions, persist_shadow_forecasts

    expected, shadow_rows = len(pipeline_result.promotion.rows()), len(pipeline_result.shadow)
    assert expected > 0 and shadow_rows > 0
    t, r = world["c"], world["r1"]
    with rolled_back(db_engine) as conn:
        assert persist_promotion_decisions(conn, t, r, pipeline_result, {}) == persist_promotion_decisions(conn, t, r, pipeline_result, {}) == expected
        assert persist_shadow_forecasts(conn, t, r, pipeline_result, {}) == persist_shadow_forecasts(conn, t, r, pipeline_result, {}) == shadow_rows
        params = {"t": t, "r": r}
        assert conn.execute(text("SELECT COUNT(*) FROM model_promotion_decisions WHERE tenant_id = :t AND forecast_run_id = :r"), params).scalar_one() == expected
        assert conn.execute(text("SELECT COUNT(*) FROM shadow_forecast_values WHERE tenant_id = :t AND forecast_run_id = :r"), params).scalar_one() == shadow_rows


def test_the_run_fingerprint_records_the_promotion_policy(world, db_engine):
    run = _rows(db_engine, "SELECT config_version, effective_config, evaluation_manifest FROM forecast_runs WHERE id = :r", r=world["r1"])[0]
    promo = run["effective_config"]["promotion"]
    versions = {r["policy"]: r["policy_version"] for r in decisions(db_engine, world["c"], world["r1"])}
    assert promo["governing_policy"] == "legacy" and promo["policy_versions"] == versions and len(promo["core_config_version"]) == 64
    d = _rows(db_engine, "SELECT effective_config FROM forecast_runs WHERE id = :r", r=world["rd"])[0]["effective_config"]["promotion"]
    assert d["governing_policy"] == "v25"
    assert "⌈n × 0.6667⌉" in run["evaluation_manifest"]["selection"]["criterion"]


def test_a_historical_run_does_not_take_the_current_run_as_champion(world, db_engine):
    """El corte de una corrida histórica es anterior a la vigente: usarla de champion sería mirar el futuro. Referencia = baseline, y se dice."""
    rows = [r for r in decisions(db_engine, world["c"], world["rh"]) if r["scope_key"] == "global" or r["scope_key"].startswith("class:")]
    assert rows and all(r["champion_family"] == "seasonal_naive" and r["champion_model_registry_id"] is None for r in rows)
    assert all(r["metrics"]["champion_source"] == "baseline" for r in rows)
    summary = lambda run: _rows(db_engine, "SELECT summary FROM forecast_runs WHERE id = :r", r=run)[0]["summary"]["promotion"]      # noqa: E731
    assert summary(world["rh"])["champions_source"] == "none_historical_run" and summary(world["rh"])["champions"] == {}
    assert summary(world["r2"])["champions_source"] == "previous_production_run" and summary(world["r2"])["champions"]
    assert summary(world["r1"])["champions_source"] == "none", "la primera ejecución no tiene champion"


# ───────────────────────────── shadow ─────────────────────────────

def test_shadow_forecasts_are_persisted_for_the_challenger_and_are_not_served(world, db_engine):
    rows = _rows(db_engine,
                 "SELECT s.*, m.model_family AS reg_family, m.forecast_run_id AS reg_run FROM shadow_forecast_values s "
                 "LEFT JOIN model_registry m ON m.tenant_id = s.tenant_id AND m.id = s.model_registry_id "
                 "WHERE s.tenant_id = :t AND s.forecast_run_id = :r", t=world["c"], r=world["r1"])
    assert rows and {r["level"] for r in rows} == {"series"} and all(r["model_registry_id"] is not None for r in rows)
    assert all(r["reg_family"] == r["model_family"] and r["reg_run"] == world["r1"] for r in rows)
    cutoff = _rows(db_engine, "SELECT cutoff_date FROM forecast_runs WHERE id = :r", r=world["r1"])[0]["cutoff_date"]
    assert all(r["target_date"] > cutoff and 1 <= r["horizon_step"] <= 90 for r in rows)
    served = {(r["node_key"], r["target_date"]): r["model_family"] for r in _rows(
        db_engine, "SELECT node_key, target_date, model_family FROM forecast_values WHERE tenant_id = :t AND forecast_run_id = :r AND level = 'series' "
                   "AND granularity = 'day'", t=world["c"], r=world["r1"])}
    assert served and all(served.get((r["node_key"], r["target_date"])) not in (None, r["model_family"]) for r in rows[:2000]), "el shadow es OTRO modelo"
    challengers = _rows(db_engine, "SELECT summary FROM forecast_runs WHERE id = :r", r=world["r1"])[0]["summary"]["promotion"]["shadow"]["challengers"]
    assert {r["model_family"] for r in rows} == {c["challenger"] for c in challengers}


def test_prospective_shadow_evaluation_of_a_historical_run(client, db_engine, world):
    """Ejecución histórica con corte 30 días antes del último dato: su shadow se evalúa contra lo realizado y se escribe en model_evaluations."""
    run = world["rh"]
    assert _rows(db_engine, "SELECT COUNT(*) AS n FROM shadow_forecast_values WHERE forecast_run_id = :r", r=run)[0]["n"] > 0
    with db_engine.begin() as conn:
        _, job = create_run(conn, world["c"])
        conn.execute(text("UPDATE training_jobs SET job_type = 'evaluate_actuals', forecast_run_id = NULL WHERE id = :j"), {"j": job})
    resp = client.post(f"/v1/tenants/{world['c']}/evaluate-actuals", headers=AUTH, json={"training_job_id": job, "lookback_runs": 10})
    assert resp.status_code == 200, resp.text
    body = resp.json()
    shadow = body["shadow_prospective"]
    assert shadow["runs_with_shadow"] >= 1 and any(e["run_id"] == run for e in shadow["evaluated"])
    evals = _rows(db_engine, "SELECT * FROM model_evaluations WHERE tenant_id = :t AND forecast_run_id = :r AND evaluation_type = 'shadow_prospective' "
                             "ORDER BY id", t=world["c"], r=run)
    overall = [e for e in evals if e["level"] == "overall"]
    assert overall and all(e["wape"] is not None and e["n_obs"] > 0 and e["is_selected"] is False and e["core"] == "demand" for e in overall)
    assert any(e["level"] == "horizon" and e["level_key"].startswith("h") for e in evals)
    for e in overall:
        m = e["metrics"]
        assert m["shadow"] is True and m["served"] is False and set(m["exclusions"]) >= {"series_days", "evaluated_series_days", "stockout_days",
                                                                                       "missing_source_days", "closed_days"}
        assert m["comparison"]["served_wape_same_series_days"] is not None and m["sample"]["n_series"] > 0
        assert e["extra"]["evaluation_scope"] == "prospective" and e["bias"] is not None
    # los días evaluados son los posteriores al corte y observados (30)
    cutoff = pd.Timestamp(world["hist_cutoff"])
    assert pd.Timestamp(world["last"]) - cutoff == pd.Timedelta(days=30)
    # reevaluar es idempotente
    before = len(evals)
    again = client.post(f"/v1/tenants/{world['c']}/evaluate-actuals", headers=AUTH, json={"lookback_runs": 10})
    assert again.status_code == 200
    after = _rows(db_engine, "SELECT COUNT(*) AS n FROM model_evaluations WHERE tenant_id = :t AND forecast_run_id = :r "
                             "AND evaluation_type = 'shadow_prospective'", t=world["c"], r=run)[0]["n"]
    assert after == before


# ───────────────────────────── censura idéntica a la evaluación servida (datos fabricados) ─────────────────────────────

D0 = date(2026, 3, 2)
CUTOFF, LAST = 59, 119
YHAT = (4.0, 2.0)
OUTAGE = range(90, 100)


def iso(offset: int) -> str:
    return (D0 + timedelta(days=offset)).isoformat()


@contextmanager
def rolled_back(db_engine):
    with db_engine.connect() as conn:
        tx = conn.begin()
        try:
            yield conn
        finally:
            tx.rollback()


def seed_sales(conn, w: World, off: dict[int, range] | None = None) -> None:
    off = off or {}
    for d in range(0, LAST + 1):
        for b in range(len(w.branch_ids)):
            if d not in off.get(b, ()):
                add_document(conn, w, number=f"S{b}-{d:04d}", sale_date=iso(d), branch=b, lines=[(p, YHAT[p], 10.0) for p in (0, 1)])


def publish(conn, w: World, served_factor: float = 1.25, shadow_factor: float = 1.0) -> int:
    """Ejecución vigente con pronóstico servido `served_factor`×real y una predicción de shadow `shadow_factor`×real (banda ±10 %)."""
    t = w.tenant_id
    run_id = int(conn.execute(text(
        "INSERT INTO forecast_runs (tenant_id, version, run_type, status, triggered_by, horizon_days, cutoff_date, options, is_current, created_at, updated_at) "
        "VALUES (:t, 1, 'production', 'completed', 'user', 90, :c, '{}'::jsonb, true, now(), now()) RETURNING id"), {"t": t, "c": iso(CUTOFF)}).scalar_one())
    served, shadow = [], []
    for step in range(1, LAST - CUTOFF + 1):
        ds = iso(CUTOFF + step)
        for b in w.branch_ids:
            for p_idx, p in enumerate(w.product_ids):
                y = YHAT[p_idx]
                served.append({"t": t, "r": run_id, "k": f"series:{b}:{p}", "b": b, "p": p, "ds": ds, "s": step, "y": y * served_factor})
                shadow.append({"t": t, "r": run_id, "k": f"series:{b}:{p}", "b": b, "p": p, "ds": ds, "s": step, "y": y * shadow_factor,
                               "lo": y * shadow_factor * 0.9, "hi": y * shadow_factor * 1.1})
    conn.execute(text(
        "INSERT INTO forecast_values (tenant_id, forecast_run_id, node_key, level, branch_id, product_id, granularity, target_date, period_days, "
        "horizon_step, yhat, lo_80, hi_80) VALUES (:t, :r, :k, 'series', :b, :p, 'day', :ds, 1, :s, :y, :y, :y)"), served)
    conn.execute(text(
        "INSERT INTO shadow_forecast_values (tenant_id, forecast_run_id, model_family, node_key, level, branch_id, product_id, target_date, horizon_step, "
        "yhat, lo_80, hi_80) VALUES (:t, :r, 'auto_ets', :k, 'series', :b, :p, :ds, :s, :y, :lo, :hi)"), shadow)
    return run_id


def evaluate(conn, w: World) -> dict:
    repo = TenantRepository(conn, w.tenant_id)
    repo.analytic_reads()
    return _evaluate(conn, repo, 10)


def shadow_overall(conn, w: World, run_id: int) -> dict:
    return dict(conn.execute(text("SELECT wape::float8 AS wape, bias::float8 AS bias, coverage_80::float8 AS coverage_80, n_obs, metrics FROM model_evaluations "
                                  "WHERE tenant_id = :t AND forecast_run_id = :r AND evaluation_type = 'shadow_prospective' AND level = 'overall'"),
                             {"t": w.tenant_id, "r": run_id}).mappings().one())


def test_shadow_evaluation_applies_the_same_censorship_as_the_served_evaluation(db_engine):
    """Caída de la integración declarada + quiebres de stock: ni el shadow ni el servido cuentan esos días como ceros, y los cuentan igual."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=1)
        seed_sales(conn, w, off={0: OUTAGE})
        conn.execute(text("INSERT INTO data_coverage_gaps (tenant_id, entity, branch_id, gap_from, gap_to, reason, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :b, :f, :e, 'integration_outage', 'declared', now(), now())"),
                     {"t": w.tenant_id, "b": w.branch_ids[0], "f": iso(90), "e": iso(99)})
        for d in (70, 71):
            conn.execute(text("INSERT INTO inventory_snapshots (tenant_id, branch_id, product_id, snapshot_date, on_hand, created_at, updated_at) "
                              "VALUES (:t, :b, :p, :d, 0, now(), now())"), {"t": w.tenant_id, "b": w.branch_ids[0], "p": w.product_ids[0], "d": iso(d)})
        run_id = publish(conn, w)
        result = evaluate(conn, w)
        served_exclusions = result["evaluated_runs"][0]["exclusions"]
        assert served_exclusions["missing_source_days"] == 20 and served_exclusions["stockout_days"] == 3
        family = result["shadow_prospective"]["evaluated"][0]["families"]["auto_ets"]
        assert family["exclusions"] == served_exclusions, "MISMA censura: mismas series-día excluidas por clase"
        stored = shadow_overall(conn, w, run_id)
        assert stored["metrics"]["exclusions"] == served_exclusions
        assert stored["n_obs"] == served_exclusions["evaluated_series_days"] == 97
        assert stored["wape"] == pytest.approx(0.0, abs=1e-6) and stored["bias"] == pytest.approx(0.0, abs=1e-6), "el shadow acertó los días evaluables"
        assert stored["coverage_80"] == pytest.approx(1.0)
        cmp = stored["metrics"]["comparison"]
        assert cmp["served_wape_same_series_days"] == pytest.approx(0.25, abs=1e-6), "el servido sobreestimaba 25 %"
        assert cmp["relative_improvement_vs_served"] == pytest.approx(1.0), "comparación sobre las mismas series y días no censurados"
        assert cmp["n_served_compared"] == 97
        served_overall = conn.execute(text("SELECT wape::float8, n_obs FROM model_evaluations WHERE forecast_run_id = :r AND evaluation_type = 'realized' "
                                           "AND level = 'overall'"), {"r": run_id}).one()
        assert served_overall[1] == stored["n_obs"] == 97 and served_overall[0] == pytest.approx(0.25, abs=1e-6)
        # W3-D: el período REALMENTE evaluado (no el de las predicciones), el denominador y el estado del WAPE, como en las filas de segmento (W3-A)
        m = stored["metrics"]
        assert m["evaluated_period"] == {"first_date": iso(CUTOFF + 1), "last_date": iso(LAST), "n_days": 50}, "60 días con predicción, 10 sin fuente"
        assert m["denominator"]["sum_actual"] == m["sample"]["sum_actual"] > 0 and m["denominator"]["unit"] == "unidades"
        assert m["denominator"]["min_required"] == 30.0 and m["wape_status"] == "ok" and m["wape_unstable"] is False
        # idempotente
        again = evaluate(conn, w)
        assert shadow_overall(conn, w, run_id)["n_obs"] == 97 and again["shadow_prospective"]["runs_with_shadow"] == 1
        assert conn.execute(text("SELECT COUNT(*) FROM model_evaluations WHERE forecast_run_id = :r AND evaluation_type = 'shadow_prospective' "
                                 "AND level = 'overall'"), {"r": run_id}).scalar_one() == 1


def test_a_shadow_wape_over_too_few_real_sales_is_flagged_unstable_with_the_companys_threshold(db_engine):
    """W3-D: el mismo umbral `demand.wape_min_denominator` de las métricas por segmento (W3-A) decide si el WAPE del shadow es estable, y la empresa puede cambiarlo."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=1)
        seed_sales(conn, w)
        conn.execute(text("UPDATE tenants SET settings = CAST(:s AS jsonb) WHERE id = :t"), {"t": w.tenant_id, "s": '{"cores": {"demand": {"wape_min_denominator": 100000}}}'})
        run_id = publish(conn, w)
        evaluate(conn, w)
        m = shadow_overall(conn, w, run_id)["metrics"]
        assert m["denominator"]["min_required"] == 100000.0 and m["denominator"]["sum_actual"] < 100000.0
        assert m["wape_status"] == "unstable" and m["wape_unstable"] is True
        assert m["evaluated_period"]["n_days"] == 60 and m["evaluated_period"]["first_date"] == iso(CUTOFF + 1)
        horizon_rows = conn.execute(text("SELECT metrics FROM model_evaluations WHERE forecast_run_id = :r AND evaluation_type = 'shadow_prospective' AND level = 'horizon'"),
                                    {"r": run_id}).scalars().all()
        assert horizon_rows and all("evaluated_period" in r and r["wape_status"] in {"ok", "unstable"} for r in horizon_rows), "cada nivel declara su período y estado"


def test_an_unresolvable_company_threshold_falls_back_to_the_core_default_and_the_shadow_is_still_evaluated(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=1)
        seed_sales(conn, w)
        conn.execute(text("UPDATE tenants SET settings = CAST(:s AS jsonb) WHERE id = :t"), {"t": w.tenant_id, "s": '{"cores": {"demand": {"clave_inventada": 1}}}'})
        run_id = publish(conn, w)
        evaluate(conn, w)
        m = shadow_overall(conn, w, run_id)["metrics"]
        assert m["denominator"]["min_required"] == 30.0 and m["wape_status"] == "ok"


def test_an_undeclared_outage_is_still_a_zero_in_both_evaluations_and_a_declared_one_is_not(db_engine):
    """Contraste: sin declarar (ni poder inferir) la caída, los ceros cuentan en ambas evaluaciones; la declaración los saca de las dos."""
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=1)
        seed_sales(conn, w, off={0: OUTAGE})
        run_id = publish(conn, w, served_factor=1.0, shadow_factor=1.0)
        result = evaluate(conn, w)
        assert shadow_overall(conn, w, run_id)["wape"] > 0.05 and result["evaluated_runs"][0]["wape"] > 0.05
        conn.execute(text("INSERT INTO data_coverage_gaps (tenant_id, entity, branch_id, gap_from, gap_to, reason, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :b, :f, :e, 'integration_outage', 'declared', now(), now())"),
                     {"t": w.tenant_id, "b": w.branch_ids[0], "f": iso(90), "e": iso(99)})
        result = evaluate(conn, w)
        stored = shadow_overall(conn, w, run_id)
        assert stored["wape"] == pytest.approx(0.0, abs=1e-9) and stored["n_obs"] == 100
        assert stored["metrics"]["exclusions"]["missing_source_days"] == 20 == result["evaluated_runs"][0]["exclusions"]["missing_source_days"]


def test_closures_are_closed_days_for_the_shadow_too(db_engine):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=2)
        seed_sales(conn, w, off={0: OUTAGE, 1: OUTAGE})
        run_id = publish(conn, w, served_factor=1.0, shadow_factor=1.0)
        conn.execute(text("INSERT INTO calendar_events (tenant_id, scope, code, name, category, starts_on, ends_on, status, known_at, created_at, updated_at) "
                          "VALUES (:t, 'tenant', 'feriado-local', 'feriado', 'closure', :s, :e, 'confirmed', now(), now(), now())"),
                     {"t": w.tenant_id, "s": iso(90), "e": iso(99)})
        evaluate(conn, w)
        stored = shadow_overall(conn, w, run_id)
        assert stored["metrics"]["exclusions"]["closed_days"] == 40 and stored["metrics"]["exclusions"]["missing_source_days"] == 0
        assert stored["wape"] == pytest.approx(0.0, abs=1e-9)


# ───────────────────────────── aislamiento: el shadow no puede tumbar lo servido ─────────────────────────────

def _small_company(db_engine, name):
    with db_engine.begin() as conn:
        return load_company(conn, generate_company("ferreteria_andina", seed=42, n_products=6, start="2025-06-01"), name)


def test_a_failing_shadow_persistence_is_isolated_and_the_served_run_still_completes(client, db_engine):
    tenant = _small_company(db_engine, "Empresa F (prueba ficticia, fallo del shadow)")

    def boom(conn, tenant_id, run_id, result, registry_ids):
        conn.execute(text("INSERT INTO shadow_forecast_values (tenant_id) VALUES (:t)"), {"t": tenant_id})      # viola NOT NULL: error de base de datos

    mp = pytest.MonkeyPatch()
    mp.setattr(forecast_run_module, "persist_shadow_forecasts", boom)
    try:
        run_id = _run(client, db_engine, tenant)
    finally:
        mp.undo()
    run = _rows(db_engine, "SELECT status, is_current, summary FROM forecast_runs WHERE id = :r", r=run_id)[0]
    assert run["status"] == "completed" and run["is_current"] is True
    assert "shadow_persist_failed" in run["summary"]["limitation_codes"]
    assert "shadow_persist_failed" in [x["code"] for x in run["summary"]["limitations"]]
    assert len(decisions(db_engine, tenant, run_id)) > 0, "las decisiones sí quedaron registradas"
    assert _rows(db_engine, "SELECT COUNT(*) AS n FROM shadow_forecast_values WHERE forecast_run_id = :r", r=run_id)[0]["n"] == 0
    assert _rows(db_engine, "SELECT COUNT(*) AS n FROM forecast_values WHERE forecast_run_id = :r", r=run_id)[0]["n"] > 0, "lo servido se publicó"
    assert _rows(db_engine, "SELECT COUNT(*) AS n FROM model_registry WHERE forecast_run_id = :r", r=run_id)[0]["n"] > 0


def test_failing_decisions_persistence_is_isolated_too_and_the_shadow_is_kept(client, db_engine):
    tenant = _small_company(db_engine, "Empresa G (prueba ficticia, fallo de las decisiones)")

    def boom(conn, tenant_id, run_id, result, registry_ids):
        conn.execute(text("INSERT INTO model_promotion_decisions (tenant_id) VALUES (:t)"), {"t": tenant_id})

    mp = pytest.MonkeyPatch()
    mp.setattr(forecast_run_module, "persist_promotion_decisions", boom)
    try:
        run_id = _run(client, db_engine, tenant)
    finally:
        mp.undo()
    run = _rows(db_engine, "SELECT status, summary FROM forecast_runs WHERE id = :r", r=run_id)[0]
    assert run["status"] == "completed" and "promotion_decisions_persist_failed" in run["summary"]["limitation_codes"]
    assert decisions(db_engine, tenant, run_id) == []
    assert _rows(db_engine, "SELECT COUNT(*) AS n FROM shadow_forecast_values WHERE forecast_run_id = :r", r=run_id)[0]["n"] > 0


def test_a_failing_prospective_shadow_evaluation_does_not_lose_the_served_evaluation(db_engine, monkeypatch):
    with rolled_back(db_engine) as conn:
        w = make_world(conn, n_branches=1)
        seed_sales(conn, w)
        run_id = publish(conn, w)

        def boom(conn_, repo, runs, day_ctx, categories, last):
            conn_.execute(text("INSERT INTO model_evaluations (tenant_id) VALUES (:t)"), {"t": w.tenant_id})      # error de base de datos

        monkeypatch.setattr(actuals_module, "evaluate_shadow_prospective", boom)
        result = evaluate(conn, w)
        shadow = result["shadow_prospective"]
        assert shadow["limitations"][0]["code"] == "shadow_evaluation_failed" and shadow["evaluated"] == [] and "error" in shadow
        served = conn.execute(text("SELECT COUNT(*) FROM model_evaluations WHERE forecast_run_id = :r AND evaluation_type = 'realized'"), {"r": run_id}).scalar_one()
        assert served > 0 and result["evaluated_runs"] and result["evaluated_runs"][0]["wape"] is not None, "la evaluación servida sobrevive"
        assert conn.execute(text("SELECT COUNT(*) FROM model_evaluations WHERE forecast_run_id = :r AND evaluation_type = 'shadow_prospective'"),
                            {"r": run_id}).scalar_one() == 0
        monkeypatch.undo()                  # la conexión sigue usable y, sin el fallo, el shadow se evalúa
        again = evaluate(conn, w)
        assert "limitations" not in again["shadow_prospective"] and again["shadow_prospective"]["runs_with_shadow"] == 1
        assert shadow_overall(conn, w, run_id)["n_obs"] == 60 * 2
