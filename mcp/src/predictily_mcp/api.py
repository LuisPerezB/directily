"""Cliente HTTP de la API pública de Predictily (`/api/v1`).

Una sola puerta de salida: cada herramienta pasa por `PredictilyApi.request` con el token de la persona. El servidor
MCP no guarda ni reutiliza tokens entre personas, no sigue redirecciones (un token no se reenvía a otro host) y nunca
incluye el token en un mensaje de error.
"""

from collections.abc import Mapping
from typing import Any

import httpx

from . import __version__
from .config import Settings


class ApiError(Exception):
    """Respuesta de error de la API (o fallo de transporte, con `status` 0)."""

    def __init__(
        self,
        status: int,
        message: str,
        *,
        code: str | None = None,
        errors: Mapping[str, Any] | None = None,
        retry_after: str | None = None,
        extra: Mapping[str, Any] | None = None,
    ) -> None:
        super().__init__(message)
        self.status = status
        self.message = message
        self.code = code
        self.errors = dict(errors or {})
        self.retry_after = retry_after
        self.extra = dict(extra or {})


def _clean_params(params: Mapping[str, Any] | None) -> dict[str, Any]:
    """Quita los vacíos y envía los booleanos como 1/0 (la regla `boolean` de Laravel no acepta «true»/«false»)."""
    clean: dict[str, Any] = {}
    for key, value in (params or {}).items():
        if value is None or value == "":
            continue
        clean[key] = int(value) if isinstance(value, bool) else value
    return clean


class PredictilyApi:
    def __init__(self, settings: Settings, transport: httpx.AsyncBaseTransport | None = None) -> None:
        self._settings = settings
        self._client = httpx.AsyncClient(
            base_url=settings.api_url + "/",
            timeout=httpx.Timeout(settings.timeout_seconds, connect=min(10.0, settings.timeout_seconds)),
            verify=settings.verify_tls,
            follow_redirects=False,
            transport=transport,
            headers={"Accept": "application/json", "User-Agent": f"predictily-mcp/{__version__}"},
        )

    async def aclose(self) -> None:
        await self._client.aclose()

    async def request(
        self,
        method: str,
        path: str,
        *,
        token: str,
        tenant_id: str | None = None,
        params: Mapping[str, Any] | None = None,
        json: Any = None,
        data: Mapping[str, Any] | None = None,
        files: Mapping[str, Any] | None = None,
    ) -> Any:
        headers = {"Authorization": f"Bearer {token}"}
        if tenant_id:
            headers["X-Tenant-ID"] = tenant_id
        try:
            response = await self._client.request(
                method, path.lstrip("/"), params=_clean_params(params), json=json, data=data, files=files, headers=headers
            )
        except httpx.TimeoutException:
            raise ApiError(0, "La API de Predictily no respondió a tiempo.", code="timeout") from None
        except httpx.TransportError as exc:
            # Solo la clase del error: el texto podría incluir la URL con detalles de la red del cliente.
            raise ApiError(0, f"No se pudo conectar con la API de Predictily ({type(exc).__name__}).", code="unreachable") from None

        if 200 <= response.status_code < 300:
            if response.status_code == 204 or not response.content:
                return None
            try:
                return response.json()
            except ValueError:
                raise ApiError(response.status_code, "La API respondió algo que no es JSON.", code="not_json") from None

        raise self._error(response)

    @staticmethod
    def _error(response: httpx.Response) -> ApiError:
        body: Any = None
        try:
            body = response.json()
        except ValueError:
            body = None
        if not isinstance(body, dict):
            body = {}
        message = body.get("message")
        if not isinstance(message, str) or not message.strip():
            message = f"La API respondió {response.status_code}."
        code = body.get("code") if isinstance(body.get("code"), str) else None
        errors = body.get("errors") if isinstance(body.get("errors"), dict) else None
        extra = {k: v for k, v in body.items() if k not in ("message", "code", "errors", "exception", "file", "line", "trace")}
        return ApiError(
            response.status_code,
            message.strip()[:600],
            code=code,
            errors=errors,
            retry_after=response.headers.get("Retry-After"),
            extra=extra,
        )


def explain(error: ApiError, *, ability: str | None = None) -> str:
    """Mensaje para el modelo: qué pasó y cuál es el siguiente paso útil. Nunca incluye el token."""
    parts = [f"Error {error.status}" if error.status else "Error de conexión"]
    if error.code:
        parts[0] += f" ({error.code})"
    parts[0] += f": {error.message}"

    if error.errors:
        details = []
        for field_name, messages in list(error.errors.items())[:12]:
            text = "; ".join(str(m) for m in messages) if isinstance(messages, list) else str(messages)
            details.append(f"- {field_name}: {text[:300]}")
        parts.append("Detalle:\n" + "\n".join(details))

    hint = _hint(error, ability)
    if hint:
        parts.append(hint)
    if error.extra:
        context = {k: v for k, v in error.extra.items() if k in ("reason", "limit", "current", "max", "context")}
        if context:
            parts.append(f"Contexto: {context}")
    return "\n".join(parts)


def _hint(error: ApiError, ability: str | None) -> str | None:
    match error.status:
        case 0:
            return "Compruebe que el backend esté levantado y que PREDICTILY_API_URL sea correcta (debe terminar en /api/v1)."
        case 401:
            return (
                "El token no es válido, caducó o fue revocado. La persona debe emitir uno nuevo en Seguridad → Tokens "
                "(POST /auth/tokens) y actualizar la configuración del servidor MCP."
            )
        case 402:
            return "El plan de la empresa no incluye esto o se alcanzó su límite. No se puede resolver desde el agente."
        case 403:
            needed = f" Esta herramienta requiere la habilidad `{ability}`." if ability else ""
            return (
                "El rol de la persona o su token no tiene permiso." + needed + " Un token solo puede llevar habilidades "
                "que el rol concede; emita otro token con esa habilidad o pida a un administrador que lo haga."
            )
        case 404:
            return "No existe o no pertenece a la empresa del token. Verifique el id con la herramienta de listado."
        case 409:
            return "El recurso no está en un estado que permita esta acción. Consulte su estado antes de reintentar."
        case 422:
            return "Corrija los argumentos indicados y vuelva a llamar a la herramienta."
        case 429:
            wait = f" Espere {error.retry_after} s." if error.retry_after else ""
            return "Se alcanzó el límite de peticiones." + wait + " No reintente en bucle."
        case status if status >= 500:
            return "Fallo interno de la API. Reintente más tarde; si persiste, revise los registros del backend."
    return None
