"""M4 de la revisión: la habilidad que la CLI exige a quien publica (`forecast.run`) se espeja de `TenantRole::abilities()` del backend y se ANCLA al código PHP.

La prueba evalúa el código fuente del backend (`backend/app/Enums/TenantRole.php`: las listas de habilidades por rol y sus propagaciones `...$otra`) y lo compara con
`mlops/policy.ROLE_ABILITIES`. Si el backend cambia qué roles tienen `forecast.run` o `models.approve` sin que cambie el espejo, esta prueba falla. Se omite (con mensaje)
si el código del backend no está junto al motor (p. ej. una imagen de Docker solo del motor)."""

from __future__ import annotations

import re
from pathlib import Path

import pytest

from predictily_engine.mlops import policy

SOURCE = Path(__file__).resolve().parents[3] / "backend" / "app" / "Enums" / "TenantRole.php"


def _php_role_abilities() -> dict[str, set[str]]:
    text = SOURCE.read_text(encoding="utf-8")
    body = text[text.index("public function abilities()"):text.index("public function can(")]
    lists: dict[str, set[str]] = {}
    for name, content in re.findall(r"\$(\w+)\s*=\s*\[(.*?)\];", body, flags=re.S):
        items: set[str] = set()
        for spread in re.findall(r"\.\.\.\$(\w+)", content):
            items |= lists[spread]
        items |= set(re.findall(r"'([a-z_]+\.[a-z_]+)'", content))
        lists[name] = items
    arms = dict(re.findall(r"self::(\w+)\s*=>\s*\$(\w+),", body.split("return match")[1]))
    return {role.lower(): lists[var] for role, var in arms.items()}


@pytest.mark.skipif(not SOURCE.exists(), reason="el código del backend no está junto al motor: el espejo de habilidades no se puede anclar")
def test_the_engine_mirror_matches_the_backends_role_abilities():
    php = _php_role_abilities()
    assert set(php) == set(policy.ROLE_ABILITIES) == {"owner", "admin", "analyst", "viewer"}
    for role, abilities in php.items():
        for ability in ("forecast.run", "models.approve"):
            assert (ability in abilities) == policy.role_can(role, ability), (role, ability)
        assert policy.ROLE_ABILITIES[role] == frozenset(a for a in abilities if a in ("forecast.run", "models.approve")), role


def test_role_can_fails_closed_for_unknown_roles():
    assert policy.role_can("analyst", "forecast.run") and not policy.role_can("viewer", "forecast.run")
    assert not policy.role_can("superadmin", "forecast.run") and not policy.role_can(None, "forecast.run") and not policy.role_can("owner", "tenant.delete")
    assert policy.PUBLISH_ABILITY == "forecast.run"
