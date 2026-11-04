"""CLI de entrenamiento privado de DEMANDA (W4-B) con PostgreSQL migrado: paridad con el servicio, entrenamiento desde una exportación autorizada, publicación y ajuste acotado.

Empresa FICTICIA. La CLI entrena sin escribir en la base; el servicio (`execute_forecast_run`) es la referencia de paridad.
"""

from __future__ import annotations

import contextlib
import io
import json
import uuid
from datetime import timedelta
from pathlib import Path

import pytest
from sqlalchemy import text

from loader import create_run, load_company
from predictily_engine import cli
from predictily_engine.mlops import policy
from predictily_engine.pipeline.forecast_run import execute_forecast_run
from predictily_engine.pipeline.schemas import ExecuteRequest
from predictily_engine.platform.object_store import LocalObjectStore, is_tenant_models_key
from predictily_engine.selection.tuning import optuna_status
from predictily_engine.synthetic import generate_company

pytestmark = pytest.mark.integration
HORIZONS = [7, 30, 90]


def invoke(*argv) -> tuple[int, str, str]:
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        try:
            code = cli.main([str(a) for a in argv])
        except SystemExit as exc:
            code = int(exc.code or 0)
    return code, out.getvalue(), err.getvalue()


def ok_json(*argv) -> dict:
    code, out, err = invoke(*argv)
    assert code == 0, (argv, err)
    return json.loads(out)


def q(engine, sql, **params):
    with engine.connect() as conn:
        return conn.execute(text(sql), params).mappings().all()


@pytest.fixture(scope="module")
def demand(db_engine, tmp_path_factory):
    work = tmp_path_factory.mktemp("demanda")
    mp = pytest.MonkeyPatch()
    mp.setenv("ENGINE_OBJECT_STORE_DRIVER", "local")
    mp.setenv("ENGINE_OBJECT_STORE_ROOT", str(work / "objetos"))
    company = generate_company("ferreteria_andina", seed=42, n_products=12, start="2025-04-01")
    with db_engine.begin() as conn:
        tenant = load_company(conn, company, "Empresa W4-B demanda (ficticia)")
        user = conn.execute(text("INSERT INTO users (name, email, password, created_at, updated_at) VALUES ('Publicador demanda', :e, 'x', now(), now()) RETURNING id"),
                            {"e": f"{uuid.uuid4().hex[:10]}@ficticio.test"}).scalar_one()
        conn.execute(text("INSERT INTO tenant_users (tenant_id, user_id, role, created_at, updated_at) VALUES (:t, :u, 'analyst', now(), now())"), {"t": tenant, "u": user})
        tenant_uuid = str(conn.execute(text("SELECT uuid::text FROM tenants WHERE id = :t"), {"t": tenant}).scalar_one())
        last = conn.execute(text("SELECT MAX(sale_date) FROM sales WHERE tenant_id = :t"), {"t": tenant}).scalar_one()
        # Entradas que la lista blanca de la exportación debe conservar: eventos extraordinarios (públicos y de la empresa), un cierre y un hueco declarado de la fuente.
        conn.execute(text(
            "INSERT INTO calendar_events (tenant_id, scope, scope_ref, code, name, category, starts_on, ends_on, status, known_at, source, revision, created_at, updated_at) VALUES "
            "(NULL, 'country', NULL, 'FERIADO-X', 'Feriado nacional secreto', 'exceptional', :p1, :p2, 'confirmed', :k, 'oficial', 0, now(), now()),"
            "(:t, 'tenant', NULL, 'FERIA-Y', 'Feria privada del cliente', 'exceptional', :f1, :f2, 'confirmed', :k, 'nota interna', 0, now(), now()),"
            "(:t, 'tenant', NULL, 'CIERRE-Z', 'Cierre por inventario', 'closure', :c1, :c2, 'confirmed', :k, 'manual', 0, now(), now())"),
            {"t": tenant, "p1": last + timedelta(days=20), "p2": last + timedelta(days=21), "f1": last + timedelta(days=10), "f2": last + timedelta(days=12),
             "c1": last - timedelta(days=30), "c2": last - timedelta(days=29), "k": last - timedelta(days=100)})
        conn.execute(text("INSERT INTO data_coverage_gaps (tenant_id, entity, gap_from, gap_to, reason, basis, created_at, updated_at) "
                          "VALUES (:t, 'sales', :a, :b, 'holiday', 'declared', now(), now())"), {"t": tenant, "a": last - timedelta(days=60), "b": last - timedelta(days=58)})
        run_id, job_id = create_run(conn, tenant)
    service = execute_forecast_run(run_id, ExecuteRequest(tenant_id=tenant, training_job_id=job_id, horizons=HORIZONS))
    counts_before = _counts(db_engine, tenant)
    cli_args = ["--tenant-id", tenant, "--core", "demand", "--horizons", *HORIZONS]
    trained = ok_json("train", *cli_args, "--out", work / "d1")
    counts_after = _counts(db_engine, tenant)
    key = work / "export.key"
    auth = ["--authorized-export", "AUT-2026-0043", "--authorized-by", "Dueña de los datos", "--purpose", "Entrenar la demanda en local"]
    exported = ok_json("export-local", "--tenant-id", tenant, "--core", "demand", "--out", work / "exports", "--key-file", key, "--generate-key", *auth)
    from_export = ok_json("train", *cli_args, "--from-export", exported["export_dir"], "--key-file", key, "--out", work / "d2")
    yield {"tenant": tenant, "user": user, "uuid": tenant_uuid, "run_id": run_id, "service": service, "work": work, "trained": trained, "from_export": from_export,
           "exported": exported, "counts": (counts_before, counts_after), "key": key, "cli_args": cli_args}
    mp.undo()


WRITE_TABLES = ("model_registry", "model_registry_events", "model_evaluations", "core_runs", "forecast_runs", "forecast_values", "training_jobs", "data_snapshots",
                "data_quality_reports", "model_promotion_decisions", "shadow_forecast_values", "recommendations", "audit_logs")


def _counts(engine, tenant_id):
    with engine.connect() as conn:
        return {t: conn.execute(text(f"SELECT count(*) FROM {t} WHERE tenant_id = :t"), {"t": tenant_id}).scalar() for t in WRITE_TABLES}


def _draft(directory: Path) -> dict:
    return json.loads((directory / "train.json").read_text())


def test_cli_demand_training_never_writes_to_the_database(demand):
    before, after = demand["counts"]
    assert before == after and before["forecast_values"] > 0                                           # el servicio sí escribió; la CLI no añadió ni una fila


def test_cli_demand_training_matches_the_service(db_engine, demand):
    """Paridad: mismos datos y semilla ⇒ misma huella de datos, mismos ganadores por clase y mismo WAPE del holdout que la ejecución del servicio."""
    draft = _draft(demand["work"] / "d1")
    row = q(db_engine, "SELECT * FROM model_registry WHERE tenant_id = :t AND forecast_run_id = :r AND model_family = 'lightgbm_global'", t=demand["tenant"], r=demand["run_id"])[0]
    assert row["training_data_hash"].strip() == draft["data_fingerprint"]
    run = q(db_engine, "SELECT summary, selection FROM forecast_runs WHERE id = :r", r=demand["run_id"])[0]
    assert draft["metrics"]["selected"]["overall"]["wape"] == pytest.approx(run["summary"]["metrics"]["selected_wape"], abs=1e-12)
    service_winners = {cls: v["model_family"] for cls, v in run["selection"].items()}
    assert draft["details"]["selection_winners"] == {k: v for k, v in service_winners.items() if k in draft["details"]["selection_winners"]} and draft["details"]["selection_winners"]
    assert [m["file"] for m in draft["models"]] == ["lightgbm_global.joblib"]
    registry = draft["models"][0]["registry"]
    assert registry["hyperparameters"] == row["hyperparameters"] and registry["series_count"] == row["series_count"] and registry["strategy_level"] == row["strategy_level"]
    assert registry["feature_set_version"] == row["feature_set_version"] and registry["library_versions"]["lightgbm"] == row["library_versions"]["lightgbm"]
    assert draft["models"][0]["sha256"] == row["artifact_sha256"]                                       # el mismo artefacto, byte a byte


def test_the_export_keeps_every_column_the_pipeline_needs_and_pseudonymizes_free_text(db_engine, demand):
    """Guarda de la lista blanca contra los cargadores reales: con eventos, cierres y huecos declarados, solo se descarta `id` de los eventos."""
    manifest = json.loads((Path(demand["exported"]["export_dir"]) / "export.manifest.json").read_text())
    assert manifest["minimization"]["dropped_columns"] == {"exceptional_events": ["id"]}
    assert manifest["tables"]["exceptional_events"]["rows"] >= 2 and manifest["tables"]["closures"]["rows"] == 1 and manifest["tables"]["coverage_gaps"]["rows"] == 1   # (>= 2: otros módulos dejan eventos públicos)
    from predictily_engine.mlops import exports

    loaded = exports.load_export(Path(demand["exported"]["export_dir"]), exports.load_key_file(demand["key"]), expect_tenant_id=demand["tenant"])
    events = loaded.data.exceptional_events
    assert {"FERIADO-X", "FERIA-Y"} <= set(events["code"]) and set(events["name"]) == set(events["code"]) and set(events["source"]) == {"export"}   # texto libre sustituido
    assert not ({"Feriado nacional secreto", "Feria privada del cliente", "nota interna"} & (set(events["name"]) | set(events["source"])))


def test_training_from_an_authorized_export_reproduces_the_database_training(db_engine, demand):
    d1, d2 = _draft(demand["work"] / "d1"), _draft(demand["work"] / "d2")
    assert d2["source"]["kind"] == "export" and d2["source"]["asof_fidelity"] == "approximate_no_versions" and d1["source"]["kind"] == "database"
    assert d2["data_fingerprint"] == d1["data_fingerprint"]                                             # mismos datos de entrenamiento tras minimizar y cifrar
    assert d2["details"]["selection_winners"] == d1["details"]["selection_winners"]
    assert d2["metrics"]["selected"]["overall"] == d1["metrics"]["selected"]["overall"]
    assert d2["models"][0]["sha256"] == d1["models"][0]["sha256"]
    names = [r["name"] for r in q(db_engine, "SELECT name FROM products WHERE tenant_id = :t", t=demand["tenant"])]
    blob = (Path(demand["exported"]["export_dir"]) / "data.enc").read_bytes()
    assert names and not any(n.encode() in blob for n in names)


def test_demand_bundle_is_packaged_verified_and_published_as_a_candidate(db_engine, demand):
    d = demand["work"] / "d1"
    ok_json("package", "--dir", d, "--sign")
    verified = ok_json("verify", "--dir", d, "--require-package", "--tenant-id", demand["tenant"])
    assert verified["ok"] and verified["artifacts"][0]["status"] == "signed"
    published = ok_json("publish", "--dir", d, "--env", "local", "--published-by-user-id", demand["user"])
    model = published["models"][0]
    assert is_tenant_models_key(model["object_key"], demand["uuid"]) and "/models/demand/" in model["object_key"] and published["status_written"] == "candidate"
    row = q(db_engine, "SELECT * FROM model_registry WHERE id = :i", i=model["model_registry_id"])[0]
    assert (row["status"], row["core"], row["model_family"], row["published_env"], row["published_by"]) == ("candidate", "demand", "lightgbm_global", "local", demand["user"])
    assert row["approved_at"] is None and row["forecast_run_id"] is None and row["artifact_path"] is None and row["artifact_sha256"] == model["sha256"]
    assert row["artifact_compat"]["data_gate"]["outcome"] in ("PASS", "WARN", "DEGRADE") and row["artifact_compat"]["signature"]["status"] == "signed"
    assert LocalObjectStore(Path(demand["work"] / "objetos")).exists(model["object_key"])
    with db_engine.connect() as conn:
        assert policy.basis_of_rows(conn, demand["tenant"], [dict(row)]) == ["not_approved"]
    # la versión servida por el servicio sigue siendo legado servible; la publicada, no
    listing = ok_json("list-models", "--tenant-id", demand["tenant"], "--core", "demand", "--limit", 100)
    by_basis = {m["serving_basis"] for m in listing["models"]}
    assert by_basis == {"legacy_pipeline", "not_approved"}
    # comparar la versión del servicio con la publicada: misma huella y mismas cifras del holdout
    legacy = [m for m in listing["models"] if not m["published"] and m["family"] == "lightgbm_global"][0]["version"]
    report = ok_json("compare", "--tenant-id", demand["tenant"], "--core", "demand", "--a", legacy, "--b", row["version"])
    assert report["comparable"] and [w for w in report["warnings"] if "bases distintas" not in w] == []          # (el servicio publica cifras por familia; el paquete, por selección)


def test_backtest_and_validate_data_for_demand(db_engine, demand):
    before = _counts(db_engine, demand["tenant"])
    bt = ok_json("backtest", *demand["cli_args"])
    assert bt["metrics_summary"]["selected"]["overall"]["wape"] is not None and bt["data_gate"] in ("PASS", "WARN", "DEGRADE")
    assert any(k.startswith("segment:") for k in bt["metrics_summary"]["selected"])                    # métricas por segmento (W3-A)
    assert any(k.startswith("horizon:") for k in bt["metrics_summary"]["selected"])                    # y por horizonte
    vd = ok_json("validate-data", "--tenant-id", demand["tenant"], "--core", "demand")
    assert vd["modules"]["demand"]["rules_version"] and vd["outcome"] in ("PASS", "WARN", "DEGRADE")
    bf = ok_json("build-features", "--tenant-id", demand["tenant"], "--core", "demand")
    assert bf["series"] > 0 and len(bf["data_fingerprint"]) == 64 and bf["data_fingerprint"] == _draft(demand["work"] / "d1")["data_fingerprint"]
    assert _counts(db_engine, demand["tenant"]) == before


@pytest.mark.skipif(not optuna_status()[0], reason="Optuna no está instalado: el ajuste acotado (tune) no está disponible en este entorno")
def test_tune_is_bounded_and_never_uses_the_holdout(demand):
    out = ok_json("tune", *demand["cli_args"], "--trials", 2, "--timeout-seconds", 30, "--max-cpu", 2, "--out", demand["work"] / "tuned")
    tuning = out["tuning"]
    assert tuning["enabled"] is True and tuning["holdout_used"] is False
    assert out["tuning_requested"]["optuna_n_trials"] == 2 and out["tuning_requested"]["optuna_timeout_seconds"] == 30
    assert (demand["work"] / "tuned" / "train.json").exists() and out["models"] == ["lightgbm_global.joblib"]


def test_tune_without_optuna_is_a_configuration_error(demand, monkeypatch):
    monkeypatch.setattr("predictily_engine.selection.tuning.optuna_status", lambda: (False, "Optuna no está instalado"))
    code, _, err = invoke("tune", *demand["cli_args"])
    assert code == 3 and "Optuna no está disponible" in err
