"""Paridad del guardia SELECT con el de PHP (SqlQueryGuard): mismo corpus, mismos veredictos, mismos códigos y mismo texto resultante."""

from __future__ import annotations

import json

import pytest

from predictily_bridge import sqlguard
from predictily_bridge.sqlguard import SqlQueryRejected

CORPUS = json.loads((__import__("pathlib").Path(__file__).parent / "fixtures" / "sql_guard_cases.json").read_text(encoding="utf-8"))["cases"]


def _sql(case: dict) -> str:
    if "build" in case:
        b = case["build"]
        return b["prefix"] + b["unit"] * b["times"] + b["suffix"]
    return case["sql"]


@pytest.mark.parametrize("case", CORPUS, ids=[c["name"] for c in CORPUS])
def test_same_verdict_reason_and_text_as_the_php_guard(case: dict) -> None:
    sql = _sql(case)
    if case["accept"]:
        validated = sqlguard.validate(sql, case["dialect"])
        assert validated.uses_since == case["uses_since"] if case["uses_since"] is not None else True
        if case["rendered"] is not None:
            assert validated.render("<SINCE>" if validated.uses_since else None) == case["rendered"]
    else:
        with pytest.raises(SqlQueryRejected) as e:
            sqlguard.validate(sql, case["dialect"])
        assert e.value.reason == case["reason"], f"{case['name']}: {e.value}"


def test_corpus_has_php_and_python_only_cases_and_both_verdicts() -> None:
    origins = {c["origin"] for c in CORPUS}
    assert origins == {"php", "python_only"}
    php = [c for c in CORPUS if c["origin"] == "php"]
    assert len(php) >= 100
    assert any(c["accept"] for c in php) and any(not c["accept"] for c in php)
    assert {c["dialect"] for c in php} == {"pgsql", "mysql", "sqlsrv"}


def test_what_is_executed_is_what_was_analyzed() -> None:
    v = sqlguard.validate("SELECT a /* DELETE FROM t */, 'x;y' AS s FROM t WHERE f >= :since -- DROP\n;", "pgsql")
    assert v.uses_since
    sql = v.render("'2026-01-01 00:00:00'")
    assert "DELETE" not in sql and "DROP" not in sql
    assert "'x;y'" in sql
    assert "f >= '2026-01-01 00:00:00'" in sql
    assert not sql.endswith(";")


def test_since_value_is_required_only_when_used() -> None:
    assert sqlguard.validate("SELECT 1", "pgsql").render() == "SELECT 1"
    with pytest.raises(SqlQueryRejected):
        sqlguard.validate("SELECT 1 FROM t WHERE a > :since", "pgsql").render()


def test_parameters_are_bound_never_interpolated() -> None:
    v = sqlguard.validate("SELECT a FROM t WHERE m >= :since AND n LIKE '%x%'", "pgsql")
    sql, params = v.bind("pyformat", {"since": "2026-01-01"})
    assert sql == "SELECT a FROM t WHERE m >= %(since)s AND n LIKE '%%x%%'"
    assert params == {"since": "2026-01-01"}
    sql, params = v.bind("qmark", {"since": "2026-01-01"})
    assert sql == "SELECT a FROM t WHERE m >= ? AND n LIKE '%x%'"
    assert params == ["2026-01-01"]
    # Un valor malicioso nunca llega al texto SQL.
    sql, params = v.bind("qmark", {"since": "1'; DROP TABLE t; --"})
    assert "DROP" not in sql


def test_extra_params_must_be_explicitly_allowed() -> None:
    with pytest.raises(SqlQueryRejected) as e:
        sqlguard.validate("SELECT 1 FROM t WHERE a > :ks0", "pgsql")
    assert e.value.reason == "parameter"
    v = sqlguard.validate("SELECT 1 FROM t WHERE a > :ks0 AND m >= :since", "pgsql", allowed_params=frozenset({"since", "ks0"}))
    assert v.param_names == ("ks0", "since")


def test_lone_surrogates_are_rejected_as_encoding() -> None:
    with pytest.raises(SqlQueryRejected) as e:
        sqlguard.validate("SELECT '\ud800'", "pgsql")
    assert e.value.reason == "encoding"
