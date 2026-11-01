"""W2-E · catálogo ÚNICO de horizontes 7/14/15/30/60/90 (ADR-V25-02): el motor y el backend declaran el mismo catálogo, Customer
usa su subconjunto documentado, la validación de configuración lo respeta y el contrato OpenAPI no conserva el catálogo antiguo."""

from __future__ import annotations

import json
import shutil
import subprocess
from pathlib import Path

import pytest
import yaml

from predictily_engine.customer import SUPPORTED_HORIZONS as CUSTOMER_HORIZONS
from predictily_engine.customer.service import _predict_horizon_support
from predictily_engine.demand import horizons as hz
from predictily_engine.platform.config import CORE_DEFAULTS, HORIZONS, resolve_config, validate_overrides

ROOT = Path(__file__).resolve().parents[3]
CATALOG = (7, 14, 15, 30, 60, 90)


def test_the_catalog_is_defined_once_and_every_core_derives_from_it():
    assert HORIZONS["catalog"] == CATALOG
    assert hz.SUPPORTED_HORIZONS == HORIZONS["demand"] == CATALOG, "demanda acepta todo el catálogo"
    assert CORE_DEFAULTS["demand"]["horizons"] == list(CATALOG)
    assert CUSTOMER_HORIZONS == HORIZONS["customer"] == (7, 14, 15, 30, 90)
    assert set(CUSTOMER_HORIZONS) <= set(CATALOG) and 60 not in CUSTOMER_HORIZONS, "Customer: subconjunto sin 60 (decisión documentada)"
    assert CORE_DEFAULTS["customer"]["horizons"] == list(CUSTOMER_HORIZONS)
    assert set(HORIZONS["cost"]) <= set(CATALOG), "el costo (30/90) tampoco sale del catálogo"


def test_configuration_validation_accepts_the_catalog_per_core_and_rejects_the_rest():
    assert validate_overrides("demand", {"horizons": [7, 15, 30]}) == []
    assert validate_overrides("demand", {"horizons": list(CATALOG)}) == []
    assert [e["code"] for e in validate_overrides("demand", {"horizons": [7, 45]})] == ["invalid_choice"]
    assert validate_overrides("customer", {"horizons": [7, 14, 15, 30, 90]}) == []
    assert [e["code"] for e in validate_overrides("customer", {"horizons": [60]})] == ["invalid_choice"], "60 no es de Customer"
    assert [e["code"] for e in validate_overrides("customer", {"horizons": []})] == ["invalid_choice"]
    cfg, _, source = resolve_config("customer", {"cores": {"customer": {"horizons": [15]}}})
    assert cfg["horizons"] == [15] and source["horizons"] == "tenant"
    assert resolve_config("demand", {})[0]["horizons"] == list(CATALOG)


def test_reason_codes_are_unified_while_legacy_codes_stay_for_existing_consumers():
    assert hz.STATUS_INSUFFICIENT == "insufficient_data"
    assert {hz.REASON_COVERAGE, hz.REASON_HORIZON} == {"coverage_insufficient", "insufficient_horizon"}
    assert hz.LEGACY_REASON_CODES["history_lt_2h"] == "coverage_insufficient"
    assert hz.LEGACY_REASON_CODES["no_backtest"] == "coverage_insufficient"
    assert hz.LEGACY_REASON_CODES["backtest_horizon_lt_h"] == "insufficient_horizon"
    assert hz.LEGACY_REASON_CODES["no_evaluated_observations"] == "insufficient_horizon"
    support = hz.horizon_support(30, history_days=40, plan=None)
    for info in support.values():
        assert info["status"] == "insufficient_data"
        for r in info["reasons"]:
            assert r["code"] in hz.LEGACY_REASON_CODES and r["reason_code"] == hz.LEGACY_REASON_CODES[r["code"]] and r["message"]
    assert hz.abstention_reason_codes(support[30]) == ["coverage_insufficient"]
    # Un respaldo guardado antes de W2-E (sin reason_code) se traduce por su code histórico.
    assert hz.abstention_reason_codes({"reasons": [{"code": "backtest_horizon_lt_h", "message": "x"}]}) == ["insufficient_horizon"]


class _Out:
    horizons = [7, 30]
    served = {7: {"role": "primary", "calibration_status": "calibrated"},
              30: {"role": "fallback", "calibration_status": "calibrated"}}


def test_customer_prediction_reports_a_status_per_requested_horizon_and_never_a_made_up_number():
    abstained = {15: {"reason_code": "no_model", "message": "No hay modelo en producción para 15 días: entrene primero."}}
    support = _predict_horizon_support([7, 15, 30], _Out(), abstained, stale=False)
    assert {h: v["status"] for h, v in support.items()} == {"7": "ok", "15": "insufficient_data", "30": "degraded"}
    assert support["15"]["reasons"][0]["reason_code"] == "no_model"
    assert support["30"]["reasons"][0]["reason_code"] == "fallback_reference"
    # Sin salida (sin compras identificadas): todos los horizontes pedidos se abstienen con el motivo de datos.
    none = _predict_horizon_support([7, 15], None, {}, stale=False)
    assert {v["status"] for v in none.values()} == {"insufficient_data"}
    assert none["15"]["reasons"][0]["reason_code"] == "no_identified_buyers"
    assert _predict_horizon_support([7], _Out(), {}, stale=True)["7"]["status"] == "degraded"


# ───────────── contrato compartido (no depende de PHP) ─────────────

CONTRACT = ROOT / "docs" / "product" / "horizons.contract.json"


def test_the_engine_matches_the_shared_horizon_contract():
    """`docs/product/horizons.contract.json` es la fuente compartida: el backend la verifica en HorizonCatalogTest. Esta prueba nunca se omite."""
    assert CONTRACT.exists(), "falta el contrato compartido de horizontes"
    contract = json.loads(CONTRACT.read_text(encoding="utf-8"))
    for key in ("catalog", "demand", "customer", "cost"):
        assert tuple(contract[key]) == HORIZONS[key], f"HORIZONS[{key!r}] (motor) ≠ contrato compartido"


# ───────────── el backend declara el MISMO catálogo (comparación cruzada, si hay PHP) ─────────────

def _backend_horizons() -> dict:
    """Lee `backend/config/predictily.php` con PHP (stubs de env() y base_path() para no depender de Laravel ni de su .env)."""
    php = shutil.which("php")
    config = ROOT / "backend" / "config" / "predictily.php"
    if php is None or not config.exists():
        pytest.skip("PHP o backend/config/predictily.php no disponible: el contrato lo cubre Tests\\Unit\\Horizons\\HorizonCatalogTest")
    code = ("function env($k, $d = null) { return $d; } function base_path($p = '') { return $p; } "
            f"echo json_encode((require {json.dumps(str(config))})['horizons']);")
    out = subprocess.run([php, "-r", code], capture_output=True, text=True, timeout=60, check=True)
    return json.loads(out.stdout)


def test_the_backend_catalog_matches_the_engine_catalog():
    backend = _backend_horizons()
    assert tuple(backend["catalog"]) == HORIZONS["catalog"], "predictily.horizons.catalog (backend) ≠ HORIZONS['catalog'] (motor)"
    assert tuple(backend["customer"]) == HORIZONS["customer"], "predictily.horizons.customer (backend) ≠ HORIZONS['customer'] (motor)"


def _int_enums(node, found: list[tuple[str, list[int]]], path: str = "") -> None:
    if isinstance(node, dict):
        for k, v in node.items():
            if k == "enum" and isinstance(v, list) and v and all(isinstance(x, int) and not isinstance(x, bool) for x in v):
                found.append((path, v))
            else:
                _int_enums(v, found, f"{path}/{k}")
    elif isinstance(node, list):
        for i, v in enumerate(node):
            _int_enums(v, found, f"{path}[{i}]")


def test_the_openapi_contract_does_not_keep_the_old_catalog():
    """Toda enumeración entera de horizontes en los OpenAPI es el catálogo (demanda) o el subconjunto de Customer."""
    allowed = {CATALOG, tuple(sorted(HORIZONS["customer"]))}
    horizon_like = set(CATALOG)
    found: list[tuple[str, list[int]]] = []
    for name in ("core.yaml", "analytics.yaml", "v2.yaml"):
        _int_enums(yaml.safe_load((ROOT / "docs" / "openapi" / name).read_text(encoding="utf-8")), found, name)
    horizon_enums = [(p, v) for p, v in found if set(v) <= horizon_like and len(v) >= 3]
    assert horizon_enums, "debe haber enumeraciones de horizonte en el contrato"
    stale = [(p, v) for p, v in horizon_enums if tuple(v) not in allowed]
    assert not stale, f"enumeraciones de horizonte fuera del catálogo vigente: {stale}"
