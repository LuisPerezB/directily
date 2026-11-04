"""Customer Core S05 · integración con PostgreSQL: columnas nuevas de `customer_predictions`, evaluaciones por segmento, artefactos firmados,
resumen y manifiesto, y degradación a agregado con IDs inestables o compradores genéricos. Mundos sintéticos FICTICIOS cargados como empresas.
"""

from __future__ import annotations

import json
from datetime import timedelta

import pytest
from sqlalchemy import text

from customer_fixtures import create_core_run, load_world
from predictily_engine.customer import S05_VERSION
from predictily_engine.customer.service import CustomerExecuteRequest, execute_customer_run, load_artifact
from predictily_engine.customer.synthetic import generate_world

pytestmark = pytest.mark.integration


def run(engine, tenant_id: int, operation: str, **kw) -> dict:
    with engine.begin() as conn:
        run_id, job_id = create_core_run(conn, tenant_id, operation, **kw)
    return execute_customer_run(run_id, CustomerExecuteRequest(tenant_id=tenant_id, training_job_id=job_id))


def q(engine, sql: str, **params):
    with engine.connect() as conn:
        return conn.execute(text(sql), params).mappings().all()


@pytest.fixture(scope="module")
def world():
    return generate_world(seed=7, n_customers=140, n_products=8, n_days=440, mean_interval_range=(20, 200), churn_share=0.1)


@pytest.fixture(scope="module")
def world_long():
    """800 días: alcanza para validar la ventana (cortes de ajuste y de evaluación con seguimiento maduro)."""
    return generate_world(seed=7, n_customers=140, n_products=8, n_days=800, mean_interval_range=(20, 200), churn_share=0.1)


@pytest.fixture(scope="module")
def env_long(db_engine, world_long):
    with db_engine.begin() as conn:
        a = load_world(conn, world_long, "W3-C S05 800 días (ficticia)")
    train = run(db_engine, a["tenant_id"], "train")
    last = world_long.start + timedelta(days=world_long.n_days - 1)
    predict = run(db_engine, a["tenant_id"], "predict", as_of=(last + timedelta(days=1)).isoformat())
    return {"tenant": a["tenant_id"], "train": train, "predict": predict, "last": last}


def window_fields(rows):
    return [(r["window_status"], r["window_p10_days"], r["window_p50_days"], r["window_p90_days"]) for r in rows]


@pytest.fixture(scope="module")
def env(db_engine, world):
    with db_engine.begin() as conn:
        a = load_world(conn, world, "W3-C S05 normal (ficticia)")
    train = run(db_engine, a["tenant_id"], "train")
    last = world.start + timedelta(days=world.n_days - 1)
    predict = run(db_engine, a["tenant_id"], "predict", as_of=(last + timedelta(days=1)).isoformat())
    return {"tenant": a["tenant_id"], "train": train, "predict": predict, "last": last}


# ───────────────────────────── columnas del contrato ─────────────────────────────

def test_predictions_persist_the_w3_columns_with_coherent_values(db_engine, env):
    rid, tid = env["predict"]["run_id"], env["tenant"]
    rows = q(db_engine, "SELECT * FROM customer_predictions WHERE core_run_id = :r AND tenant_id = :t", r=rid, t=tid)
    assert rows
    n = len(rows)
    for col in ("expected_orders", "quantity_per_order", "expected_units", "segment"):
        assert sum(r[col] is not None for r in rows) == n, col
    assert all(float(r["expected_orders"]) >= float(r["purchase_probability"]) - 1e-4 for r in rows), "E(N) ≥ P(N ≥ 1) persistido"
    assert all(abs(float(r["expected_units"]) - float(r["expected_orders"]) * float(r["quantity_per_order"])) < 5e-3 * max(1.0, float(r["expected_units"]))
               for r in rows)
    assert {r["window_status"] for r in rows} <= {"estimated", "insufficient_data", "overdue", "censored"}
    # con 440 días de historia la ventana no se puede validar: window_status lo dice y NO hay cuantiles
    summary = q(db_engine, "SELECT summary FROM core_runs WHERE id = :r", r=rid)[0]["summary"]["s05"]["window"]
    assert summary["validation_status"] != "validated"
    assert not any(r["window_status"] in ("estimated", "overdue") for r in rows)
    assert all(r["window_p10_days"] is None and r["window_p50_days"] is None and r["window_p90_days"] is None for r in rows)
    assert all(r["segment"].startswith("frequency:") for r in rows)
    # lo que ya se servía sigue ahí y la probabilidad es la de siempre
    assert all(0 < float(r["purchase_probability"]) < 1 and r["expected_quantity"] is not None and r["quantity_lo"] is not None for r in rows)
    flags = [json.loads(r["quality_flags"]) if isinstance(r["quality_flags"], str) else (r["quality_flags"] or []) for r in rows]
    assert not any("orders_raised_to_probability" in f for f in flags), "E(N) es un hurdle: ya no hay cota fila a fila"
    assert all(float(r["expected_orders"]) >= float(r["purchase_probability"]) - 1e-4 for r in rows)      # persistido con 4 decimales (la columna)
    assert sum(float(r["expected_orders"]) > float(r["purchase_probability"]) for r in rows) / n > 0.95, "salvo el redondeo de la columna, E(N) > P"
    assert all("s05_unavailable" not in f for f in flags)


def test_prediction_summary_and_limitations_describe_s05(db_engine, env):
    row = q(db_engine, "SELECT summary, limitations, target_definition FROM core_runs WHERE id = :r", r=env["predict"]["run_id"])[0]
    s = row["summary"]["s05"]
    assert s["rows"] > 0 and set(s["drift"]) <= {"7", "30", "90"} and s["orders_nested"]["rule"] == "cumulative_max"
    assert {"expected_orders", "quantity_per_order", "expected_units", "window"} <= set(row["target_definition"])
    assert "independencia" in row["target_definition"]["expected_units"]
    assert not any("No se ofrece ventana" in x for x in row["limitations"]), "la limitación heredada de que no había ventana se retiró"
    assert any("ventana de próxima compra" in x.lower() for x in row["limitations"])
    assert any("sin cifras" in x and "Ventana de recompra" in x for x in row["limitations"]), "se declara que la ventana se publica sin cifras"
    served = row["summary"]["served"]
    assert all(v["orders_family"] and v["per_order_objective"] and v["window_validation"] for v in served.values())
    assert all(v["orders_nested_corrected_rate"] is not None for v in served.values())


# ───────────────────────────── evaluaciones y manifiesto ─────────────────────────────

def test_training_persists_segment_rows_the_window_and_keeps_the_existing_keys(db_engine, env):
    rid, tid = env["train"]["run_id"], env["tenant"]
    rows = q(db_engine, "SELECT * FROM model_evaluations WHERE tenant_id = :t AND core_run_id = :r AND core = 'customer'", t=tid, r=rid)
    seg = [r for r in rows if r["level"] == "segment"]
    assert seg and all(r["horizon_days"] in (7, 30, 90) and r["evaluation_type"] == "holdout" for r in seg)
    dims = {r["level_key"].split(":")[0] for r in seg}
    assert dims == {"frequency", "value", "tenure", "history"}
    one = next(r for r in seg if r["metrics"]["status"] != "insufficient_data")
    assert one["metrics"]["served"]["brier"] is not None and one["metrics"]["reference"]["brier"] is not None and one["n_obs"] == one["metrics"]["n"]
    thin = [r for r in seg if r["metrics"]["status"] == "insufficient_data"]
    assert all(r["metrics"]["served"] is None for r in thin)
    keys = {r["level_key"] for r in rows if r["level"] == "overall"}
    assert {"h7:probability", "h7:expected_quantity", "h7:ranking", "coherence", "inactivity", "frequency"} <= keys     # lo existente
    assert {"h7:expected_orders", "h7:quantity_per_order", "h7:expected_units", "h7:ranking_s05", "h7:stability", "h7:segmenter", "window"} <= keys
    assert len([r for r in rows if r["level_key"] == "h7:expected_quantity"]) == 2, "las filas heredadas no se duplican"
    window = next(r for r in rows if r["level_key"] == "window")
    assert window["horizon_days"] is None and window["evaluation_type"] == "out_of_sample" and window["metrics"]["status"] in ("validated", "not_validated", "insufficient_data")
    ranking = next(r for r in rows if r["level_key"] == "h30:ranking_s05")["metrics"]
    assert ranking["margin_limitation"] and set(ranking["sources"]) == {"served", "reference", "popularity", "recency"}


def test_train_summary_and_manifest_state_what_the_holdout_does_and_does_not_do(db_engine, env):
    row = q(db_engine, "SELECT summary, evaluation_manifest, limitations FROM core_runs WHERE id = :r", r=env["train"]["run_id"])[0]
    summary, manifest = row["summary"], row["evaluation_manifest"]
    assert summary["s05_version"] == S05_VERSION and summary["window"]["status"] in ("validated", "not_validated", "insufficient_data")
    for h in ("7", "30", "90"):
        item = summary["horizons"][h]["s05"]
        if item["status"] == "ok":
            assert {"orders", "per_order", "expected_units", "ranking", "segments", "cold_start", "stability", "drift"} <= set(item)
            assert item["ranking"]["margin"] is None and item["ranking"]["margin_limitation"]
    s05 = manifest["s05"]
    assert "no ajusta" in s05["holdout_policy"] and set(s05["components"]) >= {"orders", "per_order", "window", "segments", "ranking_economic"}
    w = s05["window"]
    assert max(w["fit_cutoffs"] or ["0000-00-00"]) < min(w["eval_cutoffs"]), "ajuste de la ventana anterior a su evaluación"
    starts = w["holdout_starts"]
    assert len(starts) == 3 and all(max(w["fit_cutoffs"] or ["0000-00-00"]) < x for x in starts), "ningún corte de ajuste cae en el holdout de ningún horizonte"
    assert w["groups"] and "not_assessable_share" in w
    ref = manifest["s05"]["horizons"]["7"]["drift_reference"]
    assert ref["n"] > 0 and len(ref["proportions"]) == len(ref["edges"]) + 1 and abs(sum(ref["proportions"]) - 1) < 1e-9, "la distribución de referencia del drift queda en el manifiesto"
    fin = s05["orders_finalization"]
    assert fin["status"] == "ok" and fin["horizons"] == [7, 30, 90] and "máximo acumulado" in fin["rule"]
    assert "adjusted_share" in fin["per_horizon"]["30"]["nesting"] and "mass_share" in fin["per_horizon"]["30"]["nesting"]
    assert manifest["final_holdout_policy"] and "holdout_not_used_for" in manifest["horizons"]["7"]


def test_signed_artifacts_carry_s05_and_verify_before_loading(db_engine, env):
    tid = env["tenant"]
    prod = q(db_engine, "SELECT * FROM model_registry WHERE tenant_id = :t AND core = 'customer' AND status = 'production'", t=tid)
    assert prod
    seen = {"probability": False, "quantity": False}
    for r in prod:
        art, why = load_artifact(dict(r), tid)
        assert why is None and art is not None
        assert r["artifact_compat"]["signature"], "firmado (ADR-V25-20)"
        target = r["hyperparameters"]["target"]
        s05 = art.get("s05")
        assert s05 and s05["version"] == S05_VERSION
        if target == "quantity":
            assert s05["orders"]["alpha"] >= 0 and s05["orders"]["form"] == "hurdle-v1" and s05["orders"]["scale"] > 0
            assert s05["per_order"]["interval_log_q"][0] < s05["per_order"]["interval_log_q"][1]
            assert s05["window"]["params"]["max_days"] == 365 and "validation_status" in s05["window"]
            assert "groups" in s05["window"]["validation"] and "not_assessable_share" in s05["window"]["validation"]
        else:
            assert s05["segmenter"].version == "cust-seg-v1" and s05["drift_reference"]["n"] > 0
        seen[target] = True
    assert all(seen.values())


def test_idempotent_prediction_rerun_keeps_one_row_per_relation_and_horizon(db_engine, env):
    tid = env["tenant"]
    again = run(db_engine, tid, "predict", as_of=(env["last"] + timedelta(days=1)).isoformat())
    dup = q(db_engine, "SELECT customer_id, product_id, horizon_days, COUNT(*) AS n FROM customer_predictions WHERE core_run_id = :r "
                       "GROUP BY 1, 2, 3 HAVING COUNT(*) > 1", r=again["run_id"])
    assert not dup
    a = q(db_engine, "SELECT customer_id, product_id, horizon_days, expected_orders, window_p50_days, segment FROM customer_predictions WHERE core_run_id = :r "
                     "ORDER BY customer_id, product_id, horizon_days", r=env["predict"]["run_id"])
    b = q(db_engine, "SELECT customer_id, product_id, horizon_days, expected_orders, window_p50_days, segment FROM customer_predictions WHERE core_run_id = :r "
                     "ORDER BY customer_id, product_id, horizon_days", r=again["run_id"])
    assert [dict(x) for x in a] == [dict(x) for x in b], "mismos datos y modelos ⇒ mismas salidas (reproducible)"


# ───────────────────────────── degradación a agregado ─────────────────────────────

@pytest.fixture(scope="module")
def unstable(db_engine, world):
    """Empresa con IDs de comprador inestables: >20 % de los compradores con un alias de fusión reciente (V2.5 §25.2)."""
    with db_engine.begin() as conn:
        w = load_world(conn, world, "W3-C S05 IDs inestables (ficticia)")
        end = world.start + timedelta(days=world.n_days - 1)
        ids = list(w["customers"].values())
        for i, cid in enumerate(ids[: max(8, len(ids) // 3)]):
            conn.execute(text(
                "INSERT INTO customer_aliases (tenant_id, customer_id, source_code, kind, valid_from, created_at, updated_at) "
                "VALUES (:t, :c, :code, 'merge', :v, :ca, now())"),
                {"t": w["tenant_id"], "c": cid, "code": f"OLD-{i}", "v": end - timedelta(days=30),
                 "ca": f"{(end - timedelta(days=60)).isoformat()} 08:00:00-05"})
    return w["tenant_id"]


def test_unstable_ids_disable_individual_recommendations_and_publish_an_aggregate_ranking(db_engine, unstable, world):
    train = run(db_engine, unstable, "train")
    assert train["quality_status"] == "degraded"
    row = q(db_engine, "SELECT summary, evaluation_manifest, limitations FROM core_runs WHERE id = :r", r=train["run_id"])[0]
    gate = row["evaluation_manifest"]["data_quality_gate"]
    assert gate["outcome"] == "DEGRADE" and gate["degraded_to"] == "aggregate"
    assert any(code in ("dq_customer_customer_ids_unstable", "dq_customer_product_ids_unstable") for code in
               [x["code"] for x in gate["reasons"]]), "motivo: IDs inestables"
    ind = row["summary"]["individual_recommendations"]
    assert ind["status"] == "disabled" and ind["individual_rows_written"] == 0 and ind["reason_code"] == "dq_individual_recommendations_disabled"
    assert {"customer.individual_predictions", "customer.individual_rankings"} <= set(ind["disabled_features"])
    ranking = ind["aggregate_ranking"]
    assert ranking["items"] and ranking["partial"] is True and ranking["source"] == "identified_purchases_units"
    assert all("customer_id" not in i for i in ranking["items"]), "el ranking agregado no nombra compradores"
    units = [i["units"] for i in ranking["items"]]
    assert units == sorted(units, reverse=True)
    assert row["evaluation_manifest"]["individual_recommendations"]["status"] == "disabled"
    assert q(db_engine, "SELECT COUNT(*) AS n FROM model_registry WHERE tenant_id = :t AND core = 'customer'", t=unstable)[0]["n"] == 0
    assert not q(db_engine, "SELECT 1 FROM model_evaluations WHERE tenant_id = :t AND core_run_id = :r AND level = 'segment'", t=unstable, r=train["run_id"])
    pred = run(db_engine, unstable, "predict", as_of=(world.start + timedelta(days=world.n_days)).isoformat())
    assert pred["quality_status"] == "degraded"
    prow = q(db_engine, "SELECT summary FROM core_runs WHERE id = :r", r=pred["run_id"])[0]["summary"]
    assert prow["horizons"] == [] and prow["individual_recommendations"]["aggregate_ranking"]["items"]
    assert all(v["status"] == "insufficient_data" and v["reasons"][0]["reason_code"] == "dq_individual_recommendations_disabled"
               for v in prow["horizon_support"].values())
    for table in ("customer_predictions", "customer_product_rankings", "customer_activity"):
        assert q(db_engine, f"SELECT COUNT(*) AS n FROM {table} WHERE core_run_id = :r", r=pred["run_id"])[0]["n"] == 0, table


def test_generic_buyers_degradation_marks_the_run_and_keeps_the_aggregate_ranking(db_engine, world):
    with db_engine.begin() as conn:
        w = load_world(conn, world, "W3-C S05 compradores genéricos (ficticia)", settings={"cores": {"quality_gates": {"customer_min_identified_share": 0.99}}})
    pred = run(db_engine, w["tenant_id"], "predict", as_of=(world.start + timedelta(days=world.n_days)).isoformat())
    summary = q(db_engine, "SELECT summary FROM core_runs WHERE id = :r", r=pred["run_id"])[0]["summary"]
    assert summary["quality_gate"]["outcome"] == "DEGRADE"
    ind = summary["individual_recommendations"]
    assert ind["status"] == "disabled" and "dq_customer_identified_share_low" in ind["reason_codes"] and ind["aggregate_ranking"]["items"]
    assert ind["aggregate_ranking"]["partial_note"], "se declara que el ranking excluye genéricos y anónimos"


def test_block_disables_individual_output_without_inventing_an_aggregate_ranking(db_engine, world):
    with db_engine.begin() as conn:
        w = load_world(conn, world, "W3-C S05 BLOCK (ficticia)", settings={"cores": {"quality_gates": {"customer_min_buyers": 100000}}})
    pred = run(db_engine, w["tenant_id"], "predict", as_of=(world.start + timedelta(days=world.n_days)).isoformat())
    summary = q(db_engine, "SELECT summary FROM core_runs WHERE id = :r", r=pred["run_id"])[0]["summary"]
    ind = summary["individual_recommendations"]
    assert pred["quality_status"] == "insufficient_data" and ind["reason_code"] == "dq_gate_block" and ind["aggregate_ranking"] is None


# ───────────────────────────── ventana con evidencia ─────────────────────────────

def test_a_validated_window_publishes_figures_only_for_validated_groups_and_declares_what_it_cannot_assess(db_engine, env_long):
    rid, tid = env_long["predict"]["run_id"], env_long["tenant"]
    row = q(db_engine, "SELECT summary, limitations FROM core_runs WHERE id = :r", r=rid)[0]
    w = row["summary"]["s05"]["window"]
    train = q(db_engine, "SELECT summary, evaluation_manifest FROM core_runs WHERE id = :r", r=env_long["train"]["run_id"])[0]
    tw = train["summary"]["window"]
    assert tw["status"] == w["validation_status"] and tw["follow_up_days"] == w["follow_up_days"] and tw["not_assessable_share"] == w["not_assessable_share"]
    rows = q(db_engine, "SELECT window_status, window_p10_days, window_p50_days, window_p90_days, quality_flags FROM customer_predictions "
                        "WHERE core_run_id = :r AND tenant_id = :t AND horizon_days = 7", r=rid, t=tid)
    figures = [r for r in rows if r["window_status"] in ("estimated", "overdue")]
    others = [r for r in rows if r["window_status"] not in ("estimated", "overdue")]
    assert all(r["window_p10_days"] is None for r in others), "sin estado con cifras no hay cuantiles"
    if w["validation_status"] == "validated":
        groups = tw["groups"]
        assert figures and groups["gaps:8_plus"]["status"] == "validated"
        assert sum(w["withheld"].values()) > 0 if w.get("withheld") else True
        flagged = [r for r in figures if "window_beyond_validation" in (json.loads(r["quality_flags"]) if isinstance(r["quality_flags"], str) else (r["quality_flags"] or []))]
        beyond = [r for r in figures if r["window_p90_days"] is None or float(r["window_p90_days"]) > w["follow_up_days"]]
        assert len(flagged) == len(beyond), "toda fila con P90 mayor que el seguimiento validado lleva la bandera"
        text = " ".join(row["limitations"])
        assert "no está validada" in text and f"{w['follow_up_days']} días" in text, "se publica la parte no evaluable en limitations"
        assert tw["not_assessable_share"] is not None and 0 <= tw["not_assessable_share"] <= 1
    else:
        assert not figures


def test_two_hundred_days_of_history_publish_no_window_figures_end_to_end(db_engine):
    world = generate_world(seed=5, n_customers=150, n_products=6, n_days=200, mean_interval_range=(10, 60))
    with db_engine.begin() as conn:
        t = load_world(conn, world, "W3-C S05 200 días (ficticia)")["tenant_id"]
    train = run(db_engine, t, "train")
    tw = q(db_engine, "SELECT summary FROM core_runs WHERE id = :r", r=train["run_id"])[0]["summary"]["window"]
    assert tw["status"] == "insufficient_data" and tw["coverage"] is None, "con 200 días ningún corte tiene seguimiento maduro suficiente"
    pred = run(db_engine, t, "predict", as_of=(world.start + timedelta(days=world.n_days)).isoformat())
    rows = q(db_engine, "SELECT window_status, window_p10_days, window_p50_days, window_p90_days FROM customer_predictions WHERE core_run_id = :r", r=pred["run_id"])
    assert len(rows) > 100, "hay predicciones (probabilidad, pedidos, cantidad)…"
    assert all(r["window_status"] in ("insufficient_data", "censored") and r["window_p10_days"] is None and r["window_p90_days"] is None for r in rows)
    assert any(r["window_status"] == "insufficient_data" for r in rows), "…y la ventana dice insufficient_data, sin una sola cifra"


# ───────────────────────────── S05 apagado ─────────────────────────────

def test_with_s05_disabled_nothing_of_s05_is_computed_persisted_or_declared(db_engine, world):
    with db_engine.begin() as conn:
        t = load_world(conn, world, "W3-C S05 apagado (ficticia)", settings={"cores": {"customer": {"s05_enabled": False}}})["tenant_id"]
    train = run(db_engine, t, "train")
    pred = run(db_engine, t, "predict", as_of=(world.start + timedelta(days=world.n_days)).isoformat())
    trow = q(db_engine, "SELECT summary, evaluation_manifest, limitations, target_definition FROM core_runs WHERE id = :r", r=train["run_id"])[0]
    prow = q(db_engine, "SELECT summary, limitations, target_definition FROM core_runs WHERE id = :r", r=pred["run_id"])[0]
    for row in (trow, prow):
        assert "s05" not in row["summary"] and "window" not in row["summary"] and "s05_version" not in row["summary"]
        assert not ({"expected_orders", "quantity_per_order", "expected_units", "window"} & set(row["target_definition"])), "no se declaran magnitudes que no se calculan"
        assert any("No se ofrece ventana" in x for x in row["limitations"]), "sin S05 se conserva la limitación de siempre"
        assert not any("hurdle" in x or "Ventana de recompra" in x or "utilidad económica" in x for x in row["limitations"])
    assert "s05" not in trow["evaluation_manifest"]
    assert all("s05" not in h for h in trow["summary"]["horizons"].values())
    ev = q(db_engine, "SELECT level, level_key FROM model_evaluations WHERE core_run_id = :r", r=train["run_id"])
    assert not [e for e in ev if e["level"] == "segment" or e["level_key"] in ("window",) or e["level_key"].endswith(("expected_orders", "ranking_s05", "segmenter"))]
    n = q(db_engine, "SELECT COUNT(*) AS n, COUNT(expected_orders) AS orders, COUNT(window_status) AS ws, COUNT(segment) AS seg FROM customer_predictions WHERE core_run_id = :r",
          r=pred["run_id"])[0]
    assert n["n"] > 0 and n["orders"] == 0 and n["ws"] == 0 and n["seg"] == 0, "columnas nuevas nulas; lo vigente sigue"
    prods = q(db_engine, "SELECT hyperparameters FROM model_registry WHERE tenant_id = :t AND core = 'customer' AND status = 'production'", t=t)
    assert prods


def test_with_s05_disabled_a_degraded_run_computes_no_individual_block_or_aggregate_ranking(db_engine, world):
    with db_engine.begin() as conn:
        t = load_world(conn, world, "W3-C S05 apagado + DEGRADE (ficticia)",
                       settings={"cores": {"customer": {"s05_enabled": False}, "quality_gates": {"customer_min_identified_share": 0.99}}})["tenant_id"]
    pred = run(db_engine, t, "predict", as_of=(world.start + timedelta(days=world.n_days)).isoformat())
    summary = q(db_engine, "SELECT summary FROM core_runs WHERE id = :r", r=pred["run_id"])[0]["summary"]
    assert summary["quality_gate"]["outcome"] == "DEGRADE" and "individual_recommendations" not in summary, "el bloque y el ranking agregado son de S05"
    assert q(db_engine, "SELECT COUNT(*) AS n FROM customer_predictions WHERE core_run_id = :r", r=pred["run_id"])[0]["n"] == 0
