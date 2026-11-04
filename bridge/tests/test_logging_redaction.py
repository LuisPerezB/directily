"""Ningún secreto en registros, mensajes de error ni salida: token, contraseña de la fuente, códigos de registro y firmas."""

from __future__ import annotations

import io
import logging
from pathlib import Path

import pytest

from fakes import FakeServer, make_erp
from helpers import Clock, make_agent, sqlite_config
from predictily_bridge import logging_utils as lu
from predictily_bridge.retry import RetryExhausted
from predictily_bridge.transport import ServerRejected

TOKEN = "brt_" + "S3cretTokenValue0123456789abcdefghijklmnopq"[:43].ljust(43, "x")


def test_redact_masks_tokens_passwords_codes_and_bearer() -> None:
    lu.clear_secrets()
    lu.register_secret("clave-erp-2026")
    text = f"fallo con {TOKEN} y Bearer abcdefghijklmnop y password=hunter2 pwd:otra UID=u;PWD=zzzz; clave-erp-2026 código BRG-0123-4567-89AB-CDEF-GHJK x-bridge-signature: AAAA"
    out = lu.redact(text)
    for secret in (TOKEN, "abcdefghijklmnop", "hunter2", "otra", "zzzz", "clave-erp-2026", "0123-4567", "AAAA"):
        assert secret not in out, (secret, out)
    lu.clear_secrets()


def test_the_logging_filter_scrubs_messages_args_and_tracebacks() -> None:
    lu.clear_secrets()
    lu.register_secret("pw-super-secreta")
    stream = io.StringIO()
    handler = logging.StreamHandler(stream)
    handler.addFilter(lu.RedactingFilter())
    log = logging.getLogger("redact-test")
    log.handlers = [handler]
    log.propagate = False
    log.setLevel(logging.DEBUG)
    log.error("conexión %s falló", "host=erp password=pw-super-secreta")
    try:
        raise RuntimeError(f"dsn=PWD=pw-super-secreta;UID=u token {TOKEN}")
    except RuntimeError:
        log.exception("boom")
    output = stream.getvalue()
    assert "pw-super-secreta" not in output and TOKEN not in output
    assert "***" in output
    lu.clear_secrets()


def test_a_full_run_with_failures_never_logs_secrets(tmp_path: Path, caplog: pytest.LogCaptureFixture) -> None:
    lu.clear_secrets()
    password = "Cl4ve-Del-ERP-9876"
    lu.register_secret(password)
    lu.register_secret(TOKEN)
    path = tmp_path / "erp.sqlite"
    make_erp(path).close()
    server = FakeServer()
    server.fail["put"].append(RetryExhausted(f"fallo con {TOKEN} y password={password}", Exception("x")))
    server.fail["commit"].append(ServerRejected(409, "batch_not_found", f"Authorization: Bearer {TOKEN}"))
    caplog.set_level(logging.DEBUG)
    logger = logging.getLogger("predictily_bridge")
    logger.addFilter(lu.RedactingFilter())
    for handler in caplog.handler, :
        handler.addFilter(lu.RedactingFilter())
    agent, _ = make_agent(sqlite_config(path, batch_rows=5), tmp_path / "home", server, Clock(), password=password)
    agent.run_once()
    agent.run_once()
    logs = caplog.text
    assert password not in logs and TOKEN not in logs
    health = str(agent.health_payload())
    assert password not in health and TOKEN not in health
    lu.clear_secrets()
