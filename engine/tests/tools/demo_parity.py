"""Verificación «antes/después» de lo que sirve la demo FICTICIA (docs/ml/PRIVATE_TENANT_TRAINING.md §9.2).

Corre el pipeline de demanda y el Customer Core (entrenar + predecir) sobre la demo con el árbol del motor que se indique y vuelca lo servido a un archivo; luego
`compare_demo_parity.py` compara dos volcados (p. ej. el de `git archive <commit base> engine` y el del árbol modificado).

    mkdir -p /tmp/base && git archive 552f6c0 engine | tar -x -C /tmp/base
    export ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<base de pruebas migrada>
    PYTHONPATH=/tmp/base/engine   engine/.venv/bin/python engine/tests/tools/demo_parity.py /tmp/base/engine /tmp/parity_base.pkl
    PYTHONPATH=$PWD/engine        engine/.venv/bin/python engine/tests/tools/demo_parity.py $PWD/engine        /tmp/parity_cur.pkl
    engine/.venv/bin/python engine/tests/tools/compare_demo_parity.py /tmp/parity_base.pkl /tmp/parity_cur.pkl

DESTRUCTIVO: vacía las empresas (`TRUNCATE tenants … CASCADE`) de la base de pruebas indicada; por eso se niega a correr si el nombre de la base no contiene «test».
La base NO se toma del código: se lee de ENGINE_TEST_DATABASE_URL (ninguna credencial versionada). Las claves de firma son efímeras.
"""

from __future__ import annotations

import json
import os
import pickle
import sys
import tempfile
import time
import warnings

warnings.filterwarnings("ignore")

# Guarda de la base de PRUEBAS (W6-E · M4). Copia en línea de `predictily_engine/db/safety.py` (esta herramienta corre también contra el árbol BASE de un
# `git archive`, que no trae ese módulo); `tests/unit/test_db_safety.py` verifica que las copias rechazan lo mismo. Analiza la URL con `make_url`, exige
# `url.database`, el nombre con «test» como palabra y una cadena de consulta sin `dbname`/`host`/`options`…; después de conectar se comprueba `current_database()`.
import re as _re

_TEST_NAME = _re.compile(r"(^|[_\-.])test([_\-.]|$)", _re.IGNORECASE)
_ALLOWED_QUERY = frozenset({"sslmode", "sslrootcert", "sslcert", "sslkey", "connect_timeout", "application_name", "keepalives", "keepalives_idle",
                            "keepalives_interval", "keepalives_count", "target_session_attrs", "gssencmode", "channel_binding"})


def parse_test_database_url(url):
    from sqlalchemy.engine import make_url

    try:
        parsed = make_url(url)
    except Exception as exc:          # noqa: BLE001
        raise PermissionError(f"URL de base de datos ilegible: {type(exc).__name__}") from exc
    if not parsed.drivername.startswith("postgresql"):
        raise PermissionError(f"Solo PostgreSQL: {parsed.drivername!r}.")
    if not parsed.database:
        raise PermissionError("La URL no declara la base de datos (url.database vacío): se rechaza.")
    if not _TEST_NAME.search(parsed.database):
        raise PermissionError(f"La base «{parsed.database}» no parece de pruebas (su nombre debe contener «test» como palabra).")
    forbidden = sorted(k for k in parsed.query if k.lower() not in _ALLOWED_QUERY)
    if forbidden:
        raise PermissionError(f"La cadena de consulta trae parámetros que pueden redirigir la conexión: {forbidden}.")
    if "/" in (parsed.host or "") or "," in (parsed.host or ""):
        raise PermissionError("El host de la URL es ambiguo: se rechaza.")
    return parsed


def assert_connected_to_test_database(conn):
    from sqlalchemy import text

    name = conn.execute(text("SELECT current_database()")).scalar()
    if not _TEST_NAME.search(name or ""):
        raise PermissionError(f"La conexión abierta es a la base «{name}», que no parece de pruebas: se aborta.")
    return name


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2
    tree, out = sys.argv[1], sys.argv[2]
    url = os.environ.get("ENGINE_TEST_DATABASE_URL", "")
    try:
        parse_test_database_url(url)
    except PermissionError as exc:
        print(f"error: defina ENGINE_TEST_DATABASE_URL con una base de PRUEBAS; este script vacía las empresas. {exc}", file=sys.stderr)
        return 3
    sys.path.insert(0, os.path.join(tree, "tests"))
    os.environ["ENGINE_DATABASE_URL"] = url
    os.environ["ENGINE_ARTIFACTS_DIR"] = tempfile.mkdtemp(prefix="demo-parity-artifacts-")
    os.environ.setdefault("ENGINE_N_JOBS", "4")
    from predictily_engine.platform.signing import generate_keypair

    kid, private, public = generate_keypair("parity")
    os.environ.update({"ENGINE_SIGNING_KEY_ID": kid, "ENGINE_SIGNING_PRIVATE_KEY": private, "ENGINE_TRUSTED_PUBLIC_KEYS": f"{kid}:{public}"})
    import pandas as pd
    from sqlalchemy import text

    import predictily_engine
    from customer_fixtures import create_core_run, load_world
    from loader import create_run, load_company
    from predictily_engine.customer.service import CustomerExecuteRequest, execute_customer_run
    from predictily_engine.customer.synthetic import generate_world
    from predictily_engine.db.session import reset_engine
    from predictily_engine.pipeline.forecast_run import execute_forecast_run
    from predictily_engine.pipeline.schemas import ExecuteRequest
    from predictily_engine.synthetic import generate_company

    print("predictily_engine desde:", predictily_engine.__file__)
    engine = reset_engine(url)
    with engine.connect() as conn:
        try:
            assert_connected_to_test_database(conn)          # la base REAL de la conexión, no la URL declarada
        except PermissionError as exc:
            print(f"error: {exc}", file=sys.stderr)
            return 3
    with engine.begin() as conn:
        conn.execute(text("TRUNCATE tenants, forecast_features RESTART IDENTITY CASCADE"))
    t0 = time.perf_counter()
    with engine.begin() as conn:
        demand = load_company(conn, generate_company("ferreteria_andina", seed=42, n_products=12, start="2025-04-01"), "Demo demanda")
        customer = load_world(conn, generate_world(seed=7, n_customers=140, n_products=8, n_days=440, mean_interval_range=(20, 200), churn_share=0.1), "Demo customer")
        run_id, job_id = create_run(conn, demand)
    print("demanda", execute_forecast_run(run_id, ExecuteRequest(tenant_id=demand, training_job_id=job_id))["status"], round(time.perf_counter() - t0, 1))
    horizons = [7, 14, 15, 30, 90]
    with engine.begin() as conn:
        run, job = create_core_run(conn, customer["tenant_id"], "train", horizons=horizons)
    execute_customer_run(run, CustomerExecuteRequest(tenant_id=customer["tenant_id"], training_job_id=job))
    with engine.begin() as conn:
        run, job = create_core_run(conn, customer["tenant_id"], "predict", horizons=horizons)
    response = execute_customer_run(run, CustomerExecuteRequest(tenant_id=customer["tenant_id"], training_job_id=job))
    print("customer predict", response["status"], round(time.perf_counter() - t0, 1))

    volatile = {"id", "forecast_run_id", "core_run_id", "model_registry_id", "created_at", "updated_at", "run_id", "data_snapshot_id", "model_id"}

    def dump(sql: str, **params):
        with engine.connect() as conn:
            frame = pd.read_sql(text(sql), conn, params=params)
        frame = frame[[c for c in frame.columns if c not in volatile]]
        for column in frame.columns:      # listas y diccionarios (p. ej. quality_flags) → texto estable para ordenar y comparar
            if frame[column].map(lambda v: isinstance(v, (list, dict))).any():
                frame[column] = frame[column].map(lambda v: json.dumps(v, sort_keys=True, default=str) if isinstance(v, (list, dict)) else v)
        return frame.sort_values(list(frame.columns), kind="mergesort").reset_index(drop=True) if len(frame) else frame

    t = customer["tenant_id"]
    payload = {
        "forecast_values": dump("SELECT * FROM forecast_values WHERE tenant_id = :t", t=demand),
        "customer_predictions": dump("SELECT * FROM customer_predictions WHERE tenant_id = :t", t=t),
        "customer_rankings": dump("SELECT * FROM customer_product_rankings WHERE tenant_id = :t", t=t),
        "customer_activity": dump("SELECT * FROM customer_activity WHERE tenant_id = :t", t=t),
        "customer_reconciliations": dump("SELECT * FROM customer_reconciliations WHERE tenant_id = :t", t=t),
        "registry": dump("SELECT version, model_family, status, role, core, artifact_sha256, training_data_hash, hyperparameters::text AS hp FROM model_registry "
                         "WHERE tenant_id IN (:a, :b)", a=demand, b=t),
        "recommendations": dump("SELECT * FROM recommendations WHERE tenant_id = :t", t=demand),
        "model_versions": json.dumps(response, default=str, sort_keys=True),
    }
    with engine.connect() as conn:
        payload["predict_model_versions"] = conn.execute(text(
            "SELECT model_versions::text, limitations::text, quality_status FROM core_runs "
            "WHERE id = (SELECT max(id) FROM core_runs WHERE tenant_id = :t AND operation = 'predict')"), {"t": t}).first()
    with open(out, "wb") as handle:
        pickle.dump(payload, handle)
    print({k: (len(v) if hasattr(v, "__len__") and not isinstance(v, str) else None) for k, v in payload.items()})
    return 0


if __name__ == "__main__":
    sys.exit(main())
