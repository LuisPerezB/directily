"""Fixtures compartidas. Las pruebas de integración usan PostgreSQL con el esquema migrado
por Laravel (BD `predictily_engine_test`); se omiten si la BD no está disponible."""

from __future__ import annotations

import os
import sys
import tempfile
import warnings
from pathlib import Path

import pytest

TEST_DB_URL = os.environ.get(
    "ENGINE_TEST_DATABASE_URL", "postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily_engine_test")
os.environ["ENGINE_DATABASE_URL"] = TEST_DB_URL
os.environ["ENGINE_API_TOKEN"] = "test-token"
os.environ["ENGINE_ARTIFACTS_DIR"] = tempfile.mkdtemp(prefix="predictily-artifacts-")
os.environ.setdefault("ENGINE_N_JOBS", "4")
sys.path.insert(0, str(Path(__file__).parent))
warnings.filterwarnings("ignore")

from predictily_engine.pipeline.data import tenant_data_from_synthetic  # noqa: E402
from predictily_engine.synthetic import generate_company  # noqa: E402

AUTH = {"Authorization": "Bearer test-token"}


@pytest.fixture(scope="session")
def small_company():
    return generate_company("ferreteria_andina", seed=42, n_products=12, start="2025-04-01")


@pytest.fixture(scope="session")
def small_data(small_company):
    return tenant_data_from_synthetic(small_company)


@pytest.fixture(scope="session")
def pipeline_result(small_data):
    from predictily_engine.pipeline.core import run_pipeline

    return run_pipeline(small_data, horizon=90, seed=42, n_jobs=4)


# ───────────────────────────── integración ─────────────────────────────

@pytest.fixture(scope="session")
def db_engine():
    from sqlalchemy import text

    from predictily_engine.db.session import reset_engine

    try:
        engine = reset_engine(TEST_DB_URL)
        with engine.connect() as conn:
            conn.execute(text("SELECT 1 FROM forecast_values LIMIT 1"))
    except Exception as exc:  # pragma: no cover - depende del entorno
        pytest.skip(f"BD de pruebas no disponible o sin migrar ({exc.__class__.__name__}). Ejecute: "
                    "cd backend && DB_DATABASE=predictily_engine_test php artisan migrate:fresh --force")
    with engine.begin() as conn:
        conn.execute(text("TRUNCATE tenants, forecast_features RESTART IDENTITY CASCADE"))
    return engine


@pytest.fixture(scope="session")
def tenants(db_engine):
    """Dos empresas ficticias: A (ferretería, Sierra) y B (distribuidora, Costa)."""
    from loader import load_company

    a = generate_company("ferreteria_andina", seed=42, n_products=10, start="2025-05-01")
    b = generate_company("distribuidora_costa", seed=42, n_products=8, start="2025-09-01")
    with db_engine.begin() as conn:
        ta = load_company(conn, a, "Empresa A (prueba ficticia)")
        tb = load_company(conn, b, "Empresa B (prueba ficticia)")
    return {"A": ta, "B": tb}


@pytest.fixture(scope="session")
def client(db_engine):
    from fastapi.testclient import TestClient

    from predictily_engine.api.main import app

    return TestClient(app)
