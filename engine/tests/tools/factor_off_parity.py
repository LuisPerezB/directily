"""Verificación «antes/después» del pipeline de factores APAGADO (W6-E · ADR-V25-16): lo que sirve la demo FICTICIA de demanda no cambia.

Corre el pipeline de demanda sobre la demo ficticia con el árbol del motor que se indique y vuelca lo servido y lo evaluado
(`forecast_values`, `model_evaluations`, `recommendations`, selección y resumen sin tiempos de reloj) a un archivo; luego
`compare_demo_parity.py` compara dos volcados (p. ej. el de `git archive <commit base> engine` y el del árbol modificado).

    mkdir -p /tmp/base && git archive <commit base> engine | tar -x -C /tmp/base
    export ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<base de pruebas migrada>
    PYTHONPATH=/tmp/base/engine engine/.venv/bin/python engine/tests/tools/factor_off_parity.py /tmp/base/engine /tmp/factor_base.pkl
    PYTHONPATH=$PWD/engine      engine/.venv/bin/python engine/tests/tools/factor_off_parity.py $PWD/engine      /tmp/factor_cur.pkl
    engine/.venv/bin/python engine/tests/tools/compare_demo_parity.py /tmp/factor_base.pkl /tmp/factor_cur.pkl

Variantes (se ejecutan además de la demo con la configuración por defecto, cada una en su propia empresa ficticia con los MISMOS datos):
- `enabled_without_activation`: `cores.factors.enabled = true` sin ninguna activación por factor ⇒ no entra ningún factor.
- `activation_without_switch`: `factor_activation.<clave>.active = true` con `factors.enabled` apagado ⇒ no entra ningún factor.
Ambas deben servir EXACTAMENTE lo mismo que la configuración por defecto (la activación sola no cambia nada). En el árbol BASE (sin el
cableado) también lo cumplen, de modo que la comparación base/actual abarca las tres. El resumen de la ejecución (`forecast_run`) se
compara solo en la variante por defecto: en las otras puede diferir a propósito (el interruptor encendido registra su estado).

DESTRUCTIVO: vacía las empresas (`TRUNCATE tenants … CASCADE`) de la base de pruebas indicada; por eso se niega a correr si la URL no es inequívocamente de
pruebas (analizada con `make_url`, con «test» como palabra, sin `dbname`/`host`/`options` en la consulta y con `current_database()` comprobado tras conectar). La base NO se toma del código: se lee de ENGINE_TEST_DATABASE_URL. Las claves de firma son efímeras. Todo es FICTICIO.
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


VARIANTS: dict[str, dict] = {
    "default": {},
    "enabled_without_activation": {"cores": {"factors": {"enabled": True}}},
    "activation_without_switch": {"factor_activation": {"weather.observed": {"active": True}}},
}
VOLATILE_COLUMNS = {"id", "forecast_run_id", "core_run_id", "model_registry_id", "created_at", "updated_at", "run_id", "data_snapshot_id",
                    "model_id", "tenant_id", "started_at", "completed_at"}
VOLATILE_KEYS = {"timings_seconds", "duration_ms", "timings", "elapsed_seconds", "shadow_elapsed_seconds"}


def _strip(value):
    """Quita de un JSON los tiempos de reloj (lo único que puede variar entre dos ejecuciones idénticas)."""
    if isinstance(value, dict):
        return {k: _strip(v) for k, v in value.items() if k not in VOLATILE_KEYS}
    if isinstance(value, list):
        return [_strip(v) for v in value]
    return value


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
    os.environ["ENGINE_ARTIFACTS_DIR"] = tempfile.mkdtemp(prefix="factor-parity-artifacts-")
    os.environ.setdefault("ENGINE_N_JOBS", "4")
    from predictily_engine.platform.signing import generate_keypair

    kid, private, public = generate_keypair("parity")
    os.environ.update({"ENGINE_SIGNING_KEY_ID": kid, "ENGINE_SIGNING_PRIVATE_KEY": private, "ENGINE_TRUSTED_PUBLIC_KEYS": f"{kid}:{public}"})
    import pandas as pd
    from sqlalchemy import text

    import predictily_engine
    from loader import create_run, load_company
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
    tenants: dict[str, int] = {}
    with engine.begin() as conn:
        for name, settings in VARIANTS.items():
            tenants[name] = load_company(conn, generate_company("ferreteria_andina", seed=42, n_products=12, start="2025-04-01"),
                                         f"Demo demanda ({name})", settings=settings)
    for name, tenant_id in tenants.items():
        with engine.begin() as conn:
            run_id, job_id = create_run(conn, tenant_id)
        status = execute_forecast_run(run_id, ExecuteRequest(tenant_id=tenant_id, training_job_id=job_id))["status"]
        print("demanda", name, status, round(time.perf_counter() - t0, 1))

    def dump(sql: str, **params):
        with engine.connect() as conn:
            frame = pd.read_sql(text(sql), conn, params=params)
        frame = frame[[c for c in frame.columns if c not in VOLATILE_COLUMNS]]
        for column in frame.columns:      # listas y diccionarios → texto estable para ordenar y comparar
            if frame[column].map(lambda v: isinstance(v, (list, dict))).any():
                frame[column] = frame[column].map(lambda v: json.dumps(_strip(v), sort_keys=True, default=str) if isinstance(v, (list, dict)) else v)
        return frame.sort_values(list(frame.columns), kind="mergesort").reset_index(drop=True) if len(frame) else frame

    payload: dict = {}
    for name, tenant_id in tenants.items():
        payload[f"{name}/forecast_values"] = dump("SELECT * FROM forecast_values WHERE tenant_id = :t", t=tenant_id)
        payload[f"{name}/model_evaluations"] = dump("SELECT * FROM model_evaluations WHERE tenant_id = :t", t=tenant_id)
        payload[f"{name}/recommendations"] = dump("SELECT * FROM recommendations WHERE tenant_id = :t", t=tenant_id)
        if name == "default":      # el resumen de las variantes puede diferir a propósito (p. ej. la limitación del interruptor encendido)
            payload[f"{name}/forecast_run"] = dump(
                "SELECT status, cutoff_date, data_from, data_to, feature_set_version, selection, summary, evaluation_manifest, config_version, "
                "effective_config FROM forecast_runs WHERE tenant_id = :t", t=tenant_id)
        payload[f"{name}/registry"] = dump(
            "SELECT version, model_family, status, role, core, artifact_sha256, training_data_hash, hyperparameters::text AS hp "
            "FROM model_registry WHERE tenant_id = :t", t=tenant_id)
    with open(out, "wb") as handle:
        pickle.dump(payload, handle)
    print({k: len(v) for k, v in payload.items()})
    return 0


if __name__ == "__main__":
    sys.exit(main())
