"""CLI: validación de configuración, secretos cifrados, registro del dispositivo, estado sin secretos y rechazo de un dispositivo revocado."""

from __future__ import annotations

import json
import os
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import Any

import pytest

from fakes import SALES_SQL, make_erp
from predictily_bridge import cli
from predictily_bridge.keystore import ENV_VAR

TOML = """
[server]
url = "{url}"
allow_insecure_localhost = true

[agent]
batch_rows = 5
source_timezone = "America/Guayaquil"
overlap_minutes = 0

[source]
kind = "sqlite"
path = "{db}"

[[templates]]
id = "erp.demo.sales"
entity = "sales"
version = 1
recorded_at_column = "source_recorded_at"
order_by = ["document_number", "line_number"]
sql = '''{sql}'''
"""


class Enrol(BaseHTTPRequestHandler):
    seen: list[dict[str, Any]] = []

    def log_message(self, *a: Any) -> None:
        pass

    def do_POST(self) -> None:
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length") or 0)))
        type(self).seen.append({"path": self.path, "body": body})
        raw = json.dumps({"data": {"device_id": 5, "token": "brt_" + "Z" * 43, "tenant": {"uuid": "11111111-2222-3333-4444-555555555555"}, "data_source": {"id": 9, "name": "ERP"}, "protocol": 1}}).encode()
        self.send_response(201)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)


@pytest.fixture
def env(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.delenv(ENV_VAR, raising=False)
    Enrol.seen = []
    httpd = HTTPServer(("127.0.0.1", 0), Enrol)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    db = tmp_path / "erp.sqlite"
    make_erp(db).close()
    home = tmp_path / "home"
    home.mkdir(mode=0o700)
    config = home / "config.toml"
    config.write_text(TOML.format(url=f"http://127.0.0.1:{httpd.server_address[1]}", db=db, sql=SALES_SQL))
    config.chmod(0o600)
    yield {"home": home, "config": config, "base": ["--home", str(home), "--config", str(config), "--no-keyring"]}
    httpd.shutdown()
    httpd.server_close()


def run(env: dict, *args: str) -> int:
    return cli.main([*env["base"], *args])


def test_check_config_prints_template_fingerprints_and_the_pin_line(env, capsys: pytest.CaptureFixture[str]) -> None:
    assert run(env, "check-config", "--pin") == 0
    out = capsys.readouterr().out
    assert '"ok": true' in out and "erp.demo.sales" in out and 'templates_sha256 = "' in out


def test_invalid_config_exits_2_without_leaking(env, capsys: pytest.CaptureFixture[str]) -> None:
    env["config"].write_text(env["config"].read_text().replace('kind = "sqlite"', 'kind = "sqlite"\npassword = "hunter2"'))
    assert run(env, "check-config") == 2
    err = capsys.readouterr().err
    assert "Las contraseñas no van en config.toml" in err and "hunter2" not in err


def test_enroll_stores_identity_encrypted_and_never_prints_the_token(env, capsys: pytest.CaptureFixture[str]) -> None:
    assert run(env, "enroll", "--code", "BRG-0123-4567-89AB-CDEF-GHJK", "--name", "Servidor bodega") == 0
    out = capsys.readouterr()
    token = "brt_" + "Z" * 43
    assert token not in out.out and token not in out.err and json.loads(out.out)["enrolled"] is True
    request = Enrol.seen[0]
    assert request["path"] == "/api/v1/bridge/enrol" and request["body"]["device_name"] == "Servidor bodega" and request["body"]["platform"] in ("linux", "macos", "windows", "other")
    assert set(request["body"]) == {"code", "device_name", "public_key", "timestamp", "agent_version", "platform", "proof"}, "solo clave PÚBLICA y prueba: ningún secreto sale del equipo"
    home: Path = env["home"]
    for path in home.rglob("*"):
        if path.is_file():
            assert token.encode() not in path.read_bytes(), path.name
            if os.name == "posix":
                assert (path.stat().st_mode & 0o077) == 0 or path.name == "config.toml", f"{path.name} debe ser privado"
    device = json.loads((home / "device.json").read_text())
    assert device["device_id"] == 5 and len(device["fingerprint"]) == 64 and "token" not in json.dumps(device)
    # Un segundo registro exige --force.
    assert run(env, "enroll", "--code", "BRG-0123-4567-89AB-CDEF-GHJK") == 1


def test_status_has_no_secrets_and_run_refuses_a_revoked_device(env, capsys: pytest.CaptureFixture[str]) -> None:
    run(env, "enroll", "--code", "BRG-0123-4567-89AB-CDEF-GHJK")
    capsys.readouterr()
    assert run(env, "status") == 0
    status = capsys.readouterr().out
    assert '"enrolled": true' in status and "brt_" not in status and "device_key_seed" not in status and '"sales"' in status
    (env["home"] / "revoked.flag").write_text("revoked")
    (env["home"] / "revoked.flag").chmod(0o600)
    assert run(env, "run", "--once") == 3
    assert "revocado" in capsys.readouterr().err


def test_secret_set_reads_stdin_and_keeps_it_encrypted(env, monkeypatch: pytest.MonkeyPatch) -> None:
    import io

    monkeypatch.setattr("sys.stdin", io.StringIO("la-clave-del-erp\n"))
    assert run(env, "secret", "set", "source_password") == 0
    assert b"la-clave-del-erp" not in (env["home"] / "secrets.enc").read_bytes()


def test_run_before_enrolment_explains_what_to_do(env, capsys: pytest.CaptureFixture[str]) -> None:
    assert run(env, "run", "--once") == 2
    assert "enroll" in capsys.readouterr().err


def test_test_source_reports_the_read_only_mode(env, capsys: pytest.CaptureFixture[str]) -> None:
    assert run(env, "test-source") == 0
    out = json.loads(capsys.readouterr().out)
    assert out["kind"] == "sqlite" and "mode=ro" in out["read_only_enforcement"] and out["writable_account"] is False and out["verification"] == "tested"


def test_run_service_loops_until_stopped_and_ships_through_the_injected_server(env, tmp_path: Path) -> None:
    """El bucle de servicio (Windows/systemd): un ciclo completo y parada limpia cuando el SCM lo pide."""
    from fakes import FakeServer
    from helpers import Clock

    run(env, "enroll", "--code", "BRG-0123-4567-89AB-CDEF-GHJK")
    server = FakeServer()
    cycles = {"n": 0}
    clock = Clock()

    def stop() -> bool:
        cycles["n"] += 1
        return cycles["n"] > 3  # deja pasar el primer ciclo y pide la parada

    # El reloj real no importa: la API de pruebas no duerme; `run_forever` espera con el reloj del sistema, así que se acota el intervalo.
    home = str(env["home"])
    config, device = cli.load_config(__import__("argparse").Namespace(home=home, config=None, no_keyring=True))
    agent = cli.build_agent(config, device, server)
    agent.clock, agent.sleep = clock, clock.sleep
    agent.run_forever(stop, interval=10)
    assert len(server.rows()) == 24 and server.checkpoints_["sales"]["watermark"]
    assert cli.run_service(home, stop=lambda: True, api=server) == 0
    (env["home"] / "revoked.flag").write_text("revoked")
    (env["home"] / "revoked.flag").chmod(0o600)
    assert cli.run_service(home, stop=lambda: True, api=server) == 3
