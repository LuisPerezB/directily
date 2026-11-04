"""Cliente HTTPS del agente: peticiones firmadas (token + Ed25519), reintentos con retroceso exponencial y jitter, y subida por URL prefirmada.

Garantías de seguridad:
 - HTTPS obligatorio y verificación de certificado (``ca_bundle`` opcional para CA corporativas); NUNCA se siguen redirecciones (el token y
   la firma no pueden desviarse a otro host);
 - la URL prefirmada que devuelve el servidor es una RUTA RELATIVA que se une al servidor configurado: una URL absoluta o con otro host se
   rechaza (el servidor no puede enviar al agente a otro destino);
 - cada intento (también los reintentos) usa un nonce y una marca de tiempo NUEVOS: una petición capturada no se puede repetir;
 - ningún mensaje de error incluye el token, la firma ni el cuerpo; el filtro de registro enmascara cualquier resto.
"""

from __future__ import annotations

import json
import ssl
import time
import urllib.error
import urllib.request
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any, Protocol
from urllib.parse import urlsplit

from predictily_bridge import PROTOCOL_VERSION, __version__, crypto
from predictily_bridge.config import ServerConfig, assert_server_url
from predictily_bridge.retry import Backoff, RetryableError

API = "/api/v1/bridge"
MAX_RESPONSE_BYTES = 5 * 1024 * 1024


class ServerRejected(Exception):
    """El servidor rechazó la petición de forma NO reintentable (4xx)."""

    def __init__(self, status: int, code: str, message: str, context: dict[str, Any] | None = None) -> None:
        super().__init__(f"{status} {code}: {message}")
        self.status = status
        self.code = code
        self.message = message
        self.context = context or {}


class DeviceRevoked(ServerRejected):
    """El dispositivo fue revocado: el agente deja de enviar y borra su búfer."""


class ServerAPI(Protocol):
    def heartbeat(self, payload: dict[str, Any]) -> dict[str, Any]: ...

    def checkpoints(self) -> dict[str, dict[str, Any]]: ...

    def declare_upload(self, body: dict[str, Any]) -> dict[str, Any]: ...

    def put_batch(self, path: str, content: bytes) -> dict[str, Any]: ...

    def commit(self, body: dict[str, Any]) -> dict[str, Any]: ...


@dataclass(frozen=True)
class Identity:
    token: str
    key: crypto.DeviceKey


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[no-untyped-def]
        return None


def _opener(server: ServerConfig) -> urllib.request.OpenerDirector:
    handlers: list[Any] = [_NoRedirect()]
    if server.url.startswith("https://"):
        context = ssl.create_default_context(cafile=server.ca_bundle) if server.ca_bundle else ssl.create_default_context()
        handlers.append(urllib.request.HTTPSHandler(context=context))
    return urllib.request.build_opener(*handlers)


def _classify(status: int, body: bytes, headers: Any) -> ServerRejected | RetryableError | None:
    if 200 <= status < 300:
        return None
    try:
        data = json.loads(body.decode("utf-8")) if body else {}
    except (ValueError, UnicodeDecodeError):
        data = {}
    code = str(data.get("code") or ("http_" + str(status)))
    message = str(data.get("message") or "Respuesta no exitosa del servidor.")[:300]
    if status == 401 and code == "device_revoked":
        return DeviceRevoked(status, code, message)
    if status in (408, 425, 429) or status >= 500:
        retry_after = None
        try:
            retry_after = float(headers.get("Retry-After")) if headers.get("Retry-After") else None
        except (TypeError, ValueError):
            pass
        return RetryableError(f"{status} {code}", retry_after)
    return ServerRejected(status, code, message, data.get("context") if isinstance(data.get("context"), dict) else None)


class HttpServerAPI:
    def __init__(self, server: ServerConfig, identity: Identity, backoff: Backoff | None = None, clock: Callable[[], float] = time.time,
                 opener: urllib.request.OpenerDirector | None = None) -> None:
        assert_server_url(server)
        self.server = server
        self.identity = identity
        self.backoff = backoff or Backoff()
        self.clock = clock
        self._opener = opener or _opener(server)

    # ── API ──
    def heartbeat(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._signed("POST", f"{API}/heartbeat", payload)["data"]

    def checkpoints(self) -> dict[str, dict[str, Any]]:
        return {row["entity"]: row for row in self._signed("GET", f"{API}/checkpoints", None)["data"]}

    def declare_upload(self, body: dict[str, Any]) -> dict[str, Any]:
        return self._signed("POST", f"{API}/uploads", body)["data"]

    def commit(self, body: dict[str, Any]) -> dict[str, Any]:
        return self._signed("POST", f"{API}/checkpoints", body)["data"]

    def put_batch(self, path: str, content: bytes) -> dict[str, Any]:
        parts = urlsplit(path)
        if parts.scheme or parts.netloc or not path.startswith(f"{API}/uploads/"):
            raise ServerRejected(0, "unsafe_upload_url", "El servidor devolvió una URL de subida que no es una ruta relativa de su propia API; se rechaza.")

        def attempt() -> dict[str, Any]:
            return self._send("PUT", path, content, {"Content-Type": "application/gzip"})[1]["data"]

        return self.backoff.run(attempt)

    # ── enrolment (sin token: el código de un solo uso lo autoriza) ──
    def enrol(self, code: str, device_name: str, key: crypto.DeviceKey, platform: str, now: int | None = None) -> dict[str, Any]:
        timestamp = int(self.clock()) if now is None else now
        code_hash = _code_hash(code)
        body = {
            "code": code, "device_name": device_name, "public_key": key.public_b64, "timestamp": timestamp, "agent_version": __version__, "platform": platform,
            "proof": key.sign(crypto.enrol_message(code_hash, key.public_b64, timestamp)),
        }
        raw = json.dumps(body, separators=(",", ":")).encode("utf-8")
        return self.backoff.run(lambda: self._send("POST", f"{API}/enrol", raw, {"Content-Type": "application/json"})[1]["data"])

    # ── internos ──
    def _signed(self, method: str, path: str, payload: dict[str, Any] | None) -> dict[str, Any]:
        raw = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8") if payload is not None else b""

        def attempt() -> dict[str, Any]:
            timestamp = int(self.clock())
            nonce = crypto.new_nonce()
            message = crypto.request_message(method, path, "", timestamp, nonce, raw)
            headers = {
                "Authorization": f"Bearer {self.identity.token}", "X-Bridge-Key": self.identity.key.public_b64, "X-Bridge-Timestamp": str(timestamp),
                "X-Bridge-Nonce": nonce, "X-Bridge-Signature": self.identity.key.sign(message), "Content-Type": "application/json",
            }
            return self._send(method, path, raw if payload is not None else None, headers)[1]

        return self.backoff.run(attempt)

    def _send(self, method: str, path: str, body: bytes | None, headers: dict[str, str]) -> tuple[int, dict[str, Any]]:
        request = urllib.request.Request(self.server.url + path, data=body, method=method, headers={"Accept": "application/json", "User-Agent": f"predictily-bridge/{__version__} protocol/{PROTOCOL_VERSION}", **headers})
        try:
            with self._opener.open(request, timeout=self.server.timeout_seconds) as response:
                status, raw, response_headers = response.status, response.read(MAX_RESPONSE_BYTES), response.headers
        except urllib.error.HTTPError as e:
            status, raw, response_headers = e.code, e.read(MAX_RESPONSE_BYTES), e.headers
        except (urllib.error.URLError, TimeoutError, ConnectionError, ssl.SSLError, OSError) as e:
            raise RetryableError(f"Sin conexión con Predictily ({type(e).__name__})") from None
        problem = _classify(status, raw, response_headers)
        if problem is not None:
            raise problem
        try:
            return status, json.loads(raw.decode("utf-8")) if raw else {}
        except (ValueError, UnicodeDecodeError):
            raise RetryableError("Respuesta del servidor no válida") from None


def _code_hash(code: str) -> str:
    import re

    normalized = re.sub(r"[\s-]+", "", code.strip().upper())
    if normalized.startswith("BRG"):
        normalized = normalized[3:]
    normalized = normalized.translate(str.maketrans({"O": "0", "I": "1", "L": "1"}))
    return crypto.sha256_hex(normalized.encode("ascii"))
