"""La documentación de contratos de features y de validación punto-en-el-tiempo no puede citar pruebas que no existen ni omitir features."""

from __future__ import annotations

import re
from pathlib import Path

from predictily_engine.availability import FEATURE_CONTRACTS, FEATURE_CONTRACTS_VERSION

ROOT = Path(__file__).resolve().parents[3]
TESTS = ROOT / "engine" / "tests"
FEATURE_DOC = ROOT / "docs" / "data" / "FEATURE_CONTRACTS.md"
PIT_DOC = ROOT / "docs" / "ml" / "POINT_IN_TIME_VALIDATION.md"


def _defined() -> set[str]:
    names: set[str] = set()
    for path in TESTS.rglob("*.py"):
        names.add(path.stem)
        names.update(re.findall(r"^def (test_\w+)", path.read_text(encoding="utf-8"), flags=re.M))
    return names


def test_every_test_cited_by_the_docs_exists():
    defined = _defined()
    for doc in (FEATURE_DOC, PIT_DOC):
        cited = set(re.findall(r"\btest_[a-z0-9_]+\b", doc.read_text(encoding="utf-8")))
        assert cited, doc
        missing = sorted(cited - defined)
        assert not missing, f"{doc.name} cita pruebas que no existen: {missing}"


def test_the_feature_contract_doc_lists_every_feature_and_the_registry_version():
    text = FEATURE_DOC.read_text(encoding="utf-8")
    assert FEATURE_CONTRACTS_VERSION in text
    for c in FEATURE_CONTRACTS:
        for name in (c.name, *[p for p in c.produced_as if not p.endswith("*")]):
            assert f"`{name}`" in text or name in text, f"FEATURE_CONTRACTS.md no menciona {name}"
