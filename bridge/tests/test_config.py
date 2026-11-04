"""Configuración local: sin secretos en claro, HTTPS obligatorio, plantillas validadas con el guardia SELECT y fijadas por huella."""

from __future__ import annotations

import os
from pathlib import Path

import pytest

from predictily_bridge import config as cfg

BASE = {
    "server": {"url": "https://predictily.example"},
    "agent": {"source_timezone": "America/Guayaquil"},
    "source": {"kind": "postgresql", "host": "erp.interno", "database": "erp", "user": "lector", "password_secret": "pg"},
    "templates": [{"id": "erp.sales", "entity": "sales", "version": 1, "sql": "SELECT a AS document_number, m AS source_recorded_at FROM t WHERE m >= :since",
                   "recorded_at_column": "source_recorded_at", "order_by": ["document_number"]}],
}


def conf(**overrides):
    import copy

    raw = copy.deepcopy(BASE)
    for section, values in overrides.items():
        if values is None:
            raw.pop(section, None)
        elif isinstance(values, dict) and isinstance(raw.get(section), dict):
            raw[section].update(values)
        else:
            raw[section] = values
    return cfg.parse(raw)


def test_valid_configuration_parses_and_picks_the_latest_template_version() -> None:
    c = conf(templates=[BASE["templates"][0], {**BASE["templates"][0], "version": 3}, {**BASE["templates"][0], "version": 2}])
    assert c.template_for("sales").version == 3 and c.entities() == ["sales"] and c.template_for("products") is None


@pytest.mark.parametrize("section,key", [("source", "password"), ("source", "token"), ("agent", "password")])
def test_plaintext_secrets_are_refused(section: str, key: str) -> None:
    with pytest.raises(cfg.ConfigError, match="Las contraseñas no van en config.toml"):
        conf(**{section: {key: "hunter2"}})


def test_password_must_come_from_the_encrypted_store_or_the_environment() -> None:
    with pytest.raises(cfg.ConfigError, match="password_secret"):
        conf(source={"password_secret": None})


@pytest.mark.parametrize("url", ["http://predictily.example", "ftp://x", "predictily.example", "http://evil.example:8080"])
def test_server_url_must_be_https(url: str) -> None:
    with pytest.raises(cfg.ConfigError, match="https"):
        conf(server={"url": url})


def test_plain_http_is_only_for_explicit_localhost_testing() -> None:
    conf(server={"url": "http://127.0.0.1:8000", "allow_insecure_localhost": True})
    with pytest.raises(cfg.ConfigError):
        conf(server={"url": "http://127.0.0.1:8000"})
    with pytest.raises(cfg.ConfigError):
        conf(server={"url": "http://erp.cliente.example", "allow_insecure_localhost": True})


@pytest.mark.parametrize("sql", [
    "UPDATE t SET a = 1", "SELECT 1; DROP TABLE t", "DELETE FROM t", "SELECT * INTO x FROM t", "SELECT pg_sleep(10)", "SELECT * FROM pg_shadow",
    "SELECT * FROM t FOR UPDATE", "WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d", "SELECT 1 /*! 2 */", "SELECT $1",
])
def test_templates_must_pass_the_read_only_guard(sql: str) -> None:
    with pytest.raises(cfg.ConfigError, match="solo lectura"):
        conf(templates=[{**BASE["templates"][0], "sql": sql}])


def test_template_rules() -> None:
    t = BASE["templates"][0]
    for bad, message in [
        ({"order_by": []}, "order_by"), ({"order_by": ["a b"]}, "no válido"), ({"order_by": ["a", "a"]}, "repite"), ({"entity": "customers"}, "no permitida"),
        ({"version": 0}, "version"), ({"id": "X"}, "no válido"), ({"kind": "mysql"}, "fuente es"), ({"extra": 1}, "desconocidas"), ({"recorded_at_column": "a;b"}, "no válido"),
    ]:
        with pytest.raises(cfg.ConfigError, match=message):
            conf(templates=[{**t, **bad}])
    with pytest.raises(cfg.ConfigError, match="duplicada"):
        conf(templates=[t, t])


def test_source_timezone_is_required_for_incremental_sql() -> None:
    with pytest.raises(cfg.ConfigError, match="source_timezone"):
        conf(agent={"source_timezone": None})
    with pytest.raises(cfg.ConfigError, match="Zona horaria"):
        conf(agent={"source_timezone": "Marte/Olympus"})


def test_template_pin_detects_changed_queries() -> None:
    pinned = conf().templates_fingerprint()
    conf(agent={"templates_sha256": pinned})
    changed = {**BASE["templates"][0], "sql": "SELECT a AS document_number, m AS source_recorded_at FROM otra_tabla WHERE m >= :since"}
    with pytest.raises(cfg.ConfigError, match="huella"):
        conf(agent={"templates_sha256": pinned}, templates=[changed])


def test_file_templates() -> None:
    raw = {"server": BASE["server"], "agent": {}, "source": {"kind": "csv"},
           "templates": [{"id": "csv.sales", "entity": "sales", "version": 1, "path": "/data/*.csv", "columns": {"document_number": "Nro", "issued_at": "Fecha"}}]}
    assert cfg.parse(raw).templates[0].kind == "csv"
    bad = {**raw, "templates": [{**raw["templates"][0], "sql": "SELECT 1"}]}
    with pytest.raises(cfg.ConfigError, match="no admite sql"):
        cfg.parse(bad)


@pytest.mark.skipif(os.name != "posix", reason="permisos POSIX")
def test_config_file_must_not_be_world_readable(tmp_path: Path) -> None:
    path = tmp_path / "config.toml"
    path.write_text('[server]\nurl = "https://x.example"\n[source]\nkind = "csv"\n[[templates]]\nid = "csv.sales"\nentity = "sales"\nversion = 1\npath = "/d/*.csv"\ncolumns = {document_number = "A"}\n')
    path.chmod(0o644)
    with pytest.raises(cfg.ConfigError, match="otros"):
        cfg.load(path)
    path.chmod(0o640)
    assert cfg.load(path).source.kind == "csv"


def test_password_resolution(monkeypatch: pytest.MonkeyPatch) -> None:
    c = conf()
    assert cfg.password(c.source, lambda name: "del-almacen" if name == "pg" else None) == "del-almacen"
    with pytest.raises(cfg.ConfigError, match="almacén cifrado"):
        cfg.password(c.source, lambda name: None)
    env = conf(source={"password_secret": None, "password_env": "ERP_PW_TEST"})
    monkeypatch.setenv("ERP_PW_TEST", "de-entorno")
    assert cfg.password(env.source, lambda n: None) == "de-entorno"
    monkeypatch.delenv("ERP_PW_TEST")
    with pytest.raises(cfg.ConfigError, match="ERP_PW_TEST"):
        cfg.password(env.source, lambda n: None)
