"""Cliente HTTP: peticiones firmadas, reintentos con nonce nuevo, Retry-After, sin redirecciones y rutas de subida solo relativas."""

from __future__ import annotations

import json
import random
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Any

import pytest

from predictily_bridge import crypto
from predictily_bridge.config import ConfigError, ServerConfig
from predictily_bridge.retry import Backoff, RetryExhausted
from predictily_bridge.transport import DeviceRevoked, HttpServerAPI, Identity, ServerRejected


class Handler(BaseHTTPRequestHandler):
    script: list[tuple[int, dict[str, Any], dict[str, str]]] = []
    seen: list[dict[str, Any]] = []

    def log_message(self, *args: Any) -> None:
        pass

    def _handle(self) -> None:
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length)
        type(self).seen.append({"method": self.command, "path": self.path, "headers": dict(self.headers), "body": body})
        status, payload, headers = type(self).script.pop(0) if type(self).script else (200, {"data": {"ok": True}}, {})
        raw = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        for k, v in headers.items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(raw)

    do_GET = do_POST = do_PUT = _handle


@pytest.fixture
def server():
    Handler.script, Handler.seen = [], []
    httpd = HTTPServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=httpd.serve_forever, daemon=True)
    thread.start()
    yield f"http://127.0.0.1:{httpd.server_address[1]}"
    httpd.shutdown()
    httpd.server_close()


def api(url: str, sleeps: list[float] | None = None, attempts: int = 4) -> tuple[HttpServerAPI, crypto.DeviceKey]:
    key = crypto.DeviceKey.generate()
    sleeps = sleeps if sleeps is not None else []
    backoff = Backoff(max_attempts=attempts, sleep=sleeps.append, rng=random.Random(5))
    return HttpServerAPI(ServerConfig(url, allow_insecure_localhost=True, timeout_seconds=5), Identity("brt_" + "T" * 43, key), backoff), key


def test_every_request_is_signed_and_the_signature_verifies_with_the_devices_public_key(server: str) -> None:
    client, key = api(server)
    client.heartbeat({"agent_version": "0.1.0"})
    req = Handler.seen[0]
    h = {k.lower(): v for k, v in req["headers"].items()}
    assert h["authorization"] == "Bearer brt_" + "T" * 43
    assert h["x-bridge-key"] == key.public_b64
    message = crypto.request_message("POST", req["path"], "", h["x-bridge-timestamp"], h["x-bridge-nonce"], req["body"])
    assert crypto.verify(key.public_bytes, h["x-bridge-signature"], message)
    assert req["path"] == "/api/v1/bridge/heartbeat" and json.loads(req["body"]) == {"agent_version": "0.1.0"}


def test_get_signs_an_empty_body(server: str) -> None:
    client, key = api(server)
    Handler.script = [(200, {"data": [{"entity": "sales", "watermark": None, "checkpoint": None}]}, {})]
    assert client.checkpoints() == {"sales": {"entity": "sales", "watermark": None, "checkpoint": None}}
    h = {k.lower(): v for k, v in Handler.seen[0]["headers"].items()}
    assert crypto.verify(key.public_bytes, h["x-bridge-signature"], crypto.request_message("GET", "/api/v1/bridge/checkpoints", "", h["x-bridge-timestamp"], h["x-bridge-nonce"], b""))


def test_retries_use_a_fresh_nonce_and_back_off_with_jitter(server: str) -> None:
    sleeps: list[float] = []
    client, _ = api(server, sleeps)
    Handler.script = [(503, {"message": "x"}, {}), (502, {}, {}), (200, {"data": {"ok": 1}}, {})]
    assert client.heartbeat({}) == {"ok": 1}
    nonces = [{k.lower(): v for k, v in r["headers"].items()}["x-bridge-nonce"] for r in Handler.seen]
    assert len(set(nonces)) == 3, "cada intento firma con un nonce nuevo (el servidor rechaza repeticiones)"
    assert len(sleeps) == 2 and all(0 <= s <= 2 ** i for i, s in enumerate(sleeps))


def test_429_retry_after_is_honoured(server: str) -> None:
    sleeps: list[float] = []
    client, _ = api(server, sleeps)
    Handler.script = [(429, {}, {"Retry-After": "17"}), (200, {"data": {}}, {})]
    client.heartbeat({})
    assert sleeps[0] >= 17


def test_gives_up_after_the_attempt_limit_and_reports_offline(server: str) -> None:
    client, _ = api(server, attempts=3)
    Handler.script = [(500, {}, {})] * 3
    with pytest.raises(RetryExhausted):
        client.heartbeat({})
    assert len(Handler.seen) == 3


def test_unreachable_server_is_retryable_then_exhausted() -> None:
    client, _ = api("http://127.0.0.1:1", attempts=2)
    with pytest.raises(RetryExhausted):
        client.heartbeat({})


def test_4xx_is_not_retried_and_carries_code_and_context(server: str) -> None:
    client, _ = api(server)
    Handler.script = [(422, {"message": "Columnas desconocidas", "code": "unknown_columns", "context": {"unknown": ["x"]}}, {})]
    with pytest.raises(ServerRejected) as e:
        client.declare_upload({"entity": "sales"})
    assert (e.value.status, e.value.code, e.value.context) == (422, "unknown_columns", {"unknown": ["x"]}) and len(Handler.seen) == 1


def test_revoked_device_raises_a_distinct_error(server: str) -> None:
    client, _ = api(server)
    Handler.script = [(401, {"message": "revocado", "code": "device_revoked"}, {})]
    with pytest.raises(DeviceRevoked):
        client.checkpoints()


def test_redirects_are_never_followed_so_the_token_cannot_leak_to_another_host(server: str) -> None:
    client, _ = api(server)
    Handler.script = [(307, {}, {"Location": "http://evil.example/steal"})]
    with pytest.raises(ServerRejected):
        client.heartbeat({})
    assert len(Handler.seen) == 1


def test_upload_goes_to_the_configured_server_only_and_without_credentials(server: str) -> None:
    client, _ = api(server)
    path = "/api/v1/bridge/uploads/7/" + "ab" * 32 + "?e=sales&signature=zzz"
    Handler.script = [(201, {"data": {"status": "stored"}}, {})]
    assert client.put_batch(path, b"\x1f\x8bcontenido") == {"status": "stored"}
    req = Handler.seen[0]
    assert req["method"] == "PUT" and req["path"] == path and req["body"] == b"\x1f\x8bcontenido"
    h = {k.lower(): v for k, v in req["headers"].items()}
    assert h["content-type"] == "application/gzip"
    assert "authorization" not in h and "x-bridge-signature" not in h, "la URL prefirmada es la autorización: no se envía el token"
    for hostile in ("http://evil.example/api/v1/bridge/uploads/1/x", "//evil.example/api/v1/bridge/uploads/1/x", "https://evil.example/x", "/otra/ruta", "/api/v1/sales"):
        with pytest.raises(ServerRejected) as e:
            client.put_batch(hostile, b"x")
        assert e.value.code == "unsafe_upload_url"
    assert len(Handler.seen) == 1, "ninguna de las rutas hostiles salió del equipo"


def test_enrol_sends_a_proof_of_possession_and_no_token(server: str) -> None:
    key = crypto.DeviceKey.generate()
    client = HttpServerAPI(ServerConfig(server, allow_insecure_localhost=True), Identity("", key), Backoff(sleep=lambda s: None))
    Handler.script = [(201, {"data": {"device_id": 3, "token": "brt_x"}}, {})]
    assert client.enrol("brg-0123-4567-89ab-cdef-ghjk", "Servidor", key, "linux", now=1_760_000_100)["device_id"] == 3
    req = Handler.seen[0]
    body = json.loads(req["body"])
    assert "authorization" not in {k.lower() for k in req["headers"]}
    import hashlib

    code_hash = hashlib.sha256(b"0123456789ABCDEFGHJK").hexdigest()
    assert crypto.verify(key.public_bytes, body["proof"], crypto.enrol_message(code_hash, key.public_b64, 1_760_000_100))
    assert body["public_key"] == key.public_b64 and "private" not in json.dumps(body) and crypto.b64u(key.seed) not in json.dumps(body)


def test_plain_http_to_a_remote_host_is_refused_at_construction() -> None:
    with pytest.raises(ConfigError):
        HttpServerAPI(ServerConfig("http://erp.cliente.example"), Identity("t", crypto.DeviceKey.generate()))
