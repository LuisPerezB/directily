"""W6-E · contratos sectoriales Retail / Export / Industry / Agro: PRESERVADOS, sin declarar cobertura (V2.5 §26 T15 y T17; §25.6; ADR-V25-16).

Qué fija este archivo:

1. **Los casos T15 (seis casos sectoriales de V2.1) y T17.03–T17.05 (conversión productiva) están `P3_PENDIENTE`**: cada uno es una prueba marcada con
   `skip` y ese motivo, para que aparezcan en cada ejecución como pendientes y nunca como aprobados. Cuando exista el módulo o el fixture de un caso,
   su prueba se escribe, se quita la marca y se actualizan `docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md` y `docs/intelligence/SECTOR_PROFILES.md`.
2. **Ninguna cobertura falsa**: el registro de perfiles sectoriales solo trae los dos perfiles SINTÉTICOS; no hay módulo de Export, Industry ni Agro; las
   empresas ficticias de `synthetic/` no son ninguno de los seis casos. Si alguien agrega uno de ellos, estas pruebas fallan y obligan a actualizar el estado.
3. **El contrato de extensión se conserva** (`SectorProfile`, `ExtensionProvider`, `register_profile`): un perfil real se agrega sin tocar el core.
4. **Los documentos coinciden con estas pruebas**: los casos pendientes figuran con su marca en los documentos sectoriales (si el repositorio los trae).

Todo es FICTICIO: no hay datos de ningún cliente (V25-SCT-006: BLOQUEADO hasta un piloto).
"""

from __future__ import annotations

import dataclasses
import importlib.util
import re
from pathlib import Path

import pytest

from predictily_engine.platform.extensions import (
    ExtensionProvider,
    SectorProfile,
    get_profile,
    register_profile,
    registered_profiles,
    validate_provider,
)
from predictily_engine.synthetic.catalogs import COMPANIES

PENDING = "P3_PENDIENTE"
ROOT = Path(__file__).resolve().parents[3]
DOCS = ROOT / "docs"

# (id en la matriz T01–T17, caso, qué falta). Mantener sincronizado con docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md.
PENDING_CASES = [
    ("T15.01", "Fabricante de agua embotellada", "sin fixture ni piloto; clima y búsqueda sin fuente; datos reales BLOQUEADOS"),
    ("T15.02", "Fabricante de alimentos sin gluten", "sin fixture ni piloto; certificación verificada vs no corroborada sin flujo; datos reales BLOQUEADOS"),
    ("T15.03", "Fabricante de insumos agrícolas", "sin fixture ni piloto; sanidad vegetal solo como eventos manuales; datos reales BLOQUEADOS"),
    ("T15.04", "Retail multisucursal con y sin factores", "competencia, clima y tendencias sin fuente; falta el perfil Retail registrado; datos reales BLOQUEADOS"),
    ("T15.05", "Productor agrícola", "estimación de cosecha separada de la demanda sin módulo; datos reales BLOQUEADOS"),
    ("T15.06", "Exportadora de camarón", "no existe el módulo Export (pedido → presentación y rendimiento → materia prima); datos reales BLOQUEADOS"),
    ("T17.03", "No mezclar peso terminado y peso bruto", "sin módulo productivo validado de punta a punta"),
    ("T17.04", "Flujo mínimo de Export: demanda → pedidos confirmados → inventario → materia prima", "no existe el módulo Export"),
    ("T17.05", "Mismo flujo para Retail, Industry y Agro en sus fixtures", "sin fixtures sectoriales ni módulo productivo"),
]
SECTOR_CODES = {"retail", "export", "industry", "manufacturing", "agro", "agroindustry"}
SECTOR_MODULES = ("export", "industry", "agro", "manufacturing", "production", "sectors")


@pytest.mark.parametrize(
    "case_id,title,missing",
    [pytest.param(i, t, m, id=i, marks=pytest.mark.skip(reason=f"{PENDING}: {t}; falta: {m}. No se declara cobertura (V2.5 §26)."))
     for i, t, m in PENDING_CASES])
def test_sector_case_is_covered(case_id, title, missing):
    """Marcador: se ejecuta como omitida con su motivo. Reemplazar por la prueba real cuando exista el módulo o el fixture del caso."""
    raise AssertionError(f"{case_id} {title}: no implementado ({PENDING})")


def test_the_registry_of_sector_profiles_only_holds_synthetic_profiles_so_no_sector_case_can_be_claimed():
    profiles = registered_profiles()
    assert {p.code for p in profiles} >= {"synthetic_retail_a", "synthetic_process_b"}
    assert all(p.synthetic for p in profiles), "un perfil real (Retail, Export, Industry, Agro) aún no existe: registrarlo exige actualizar T15"
    assert not ({p.code for p in profiles} & SECTOR_CODES)
    for code in SECTOR_CODES:
        assert get_profile(code) is None, code
    assert all("sintético" in p.description.lower() or p.code.startswith("synthetic_") for p in profiles)


def test_there_is_no_export_industry_or_agro_module_and_the_fictional_companies_are_not_a_t15_case():
    for name in SECTOR_MODULES:
        assert importlib.util.find_spec(f"predictily_engine.{name}") is None, (
            f"apareció predictily_engine.{name}: actualice T15/T17 (docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md), quite la marca {PENDING} "
            "de los casos que cubra y escriba su prueba")
    assert sorted(COMPANIES) == ["distribuidora_costa", "ferreteria_andina"], "las empresas ficticias no representan ninguno de los seis casos de V2.1"


def test_the_sector_extension_contract_is_preserved():
    """No se borra el contrato P3: un perfil real se agrega con `register_profile` sin tocar el core (V2.5 §25.6, ADR-V25-16)."""
    fields = {f.name for f in dataclasses.fields(SectorProfile)}
    assert {"code", "version", "description", "overrides", "providers", "synthetic"} <= fields
    assert {f.name for f in dataclasses.fields(ExtensionProvider)} >= {"kind", "name", "version", "inputs", "outputs", "units", "temporal_availability"}
    assert callable(register_profile) and callable(validate_provider)
    # Un perfil de prueba (NO sectorial) con la misma interfaz se registra y se resuelve sin tocar el core; se limpia al terminar.
    from predictily_engine.platform import extensions

    profile = SectorProfile(code="contract_probe_w6e", version="1.0.0", description="Sonda del contrato de extensión (no es un rubro)", synthetic=True)
    register_profile(profile)
    try:
        assert get_profile("contract_probe_w6e") is profile and profile.describe()["synthetic"] is True
    finally:
        extensions._REGISTRY.pop("contract_probe_w6e", None)


def read(path: Path) -> str | None:
    return path.read_text(encoding="utf-8") if path.exists() else None


def test_the_sector_documents_list_every_pending_case_with_its_marker_and_never_call_one_covered():
    """Coherencia documento ↔ prueba (se omite si el árbol de documentos no está, p. ej. dentro de la imagen del motor)."""
    if not DOCS.is_dir():
        pytest.skip("el árbol docs/ no está disponible en este entorno")
    docs = {name: read(DOCS / name) for name in ("testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md", "intelligence/SECTOR_PROFILES.md")}
    assert all(docs.values()), f"faltan documentos sectoriales: {[n for n, t in docs.items() if not t]}"
    acceptance = docs["testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md"]
    for case_id, title, _ in PENDING_CASES:
        row = next((line for line in acceptance.splitlines() if line.startswith("|") and case_id in line), None)
        assert row is not None, f"{case_id} falta en SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md"
        assert PENDING in row, f"{case_id} debe figurar como {PENDING} mientras no exista su prueba"
        assert not re.search(r"\b(APROBAD[OA]|CUBIERT[OA]|VERIFICAD[OA])\b", row.replace("NO aprobado", "").replace("no cubierto", "")), (
            f"{case_id}: no se declara cobertura sin prueba")
    profiles = docs["intelligence/SECTOR_PROFILES.md"]
    assert PENDING in profiles and "BLOQUEADO" in profiles
    for sector in ("Retail", "Export", "Industry", "Agro"):
        assert sector in profiles
    for name in ("RETAIL", "EXPORT", "INDUSTRY", "AGRO"):
        assert (DOCS / "product" / f"{name}_SECTOR_ACCEPTANCE.md").exists(), name
