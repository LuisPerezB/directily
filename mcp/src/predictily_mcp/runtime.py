"""Lo que comparten las herramientas de ambos servidores: de quién es la petición, la llamada a la API y la salida.

Regla central: la identidad sale de la credencial de la PERSONA, nunca de un argumento del modelo.
- stdio: un proceso por persona; el token es el de su entorno (`PREDICTILY_API_TOKEN`).
- HTTP: el token llega en la cabecera `Authorization: Bearer …` de CADA petición MCP y se reenvía tal cual a la API;
  el servidor no tiene un token propio, así que no puede actuar por nadie que no se haya autenticado.
"""

from collections.abc import Mapping
from typing import Any

import anyio
import httpx
from mcp.server.mcpserver import Context, Elicit, ElicitationResult
from mcp.server.mcpserver.exceptions import ToolError
from mcp.types import ToolAnnotations
from pydantic import BaseModel, Field

from .api import ApiError, PredictilyApi, explain
from .config import Settings
from .output import normalize, render
from .privacy import mask_pii
from .sources import SourceProfiles

# La API es un sistema externo (open world) y una lectura no cambia nada: repetirla es seguro.
READ = ToolAnnotations(read_only_hint=True, destructive_hint=False, idempotent_hint=True, open_world_hint=True)
# Escrituras que crean o cambian estado sin destruir nada.
WRITE = ToolAnnotations(read_only_hint=False, destructive_hint=False, idempotent_hint=False, open_world_hint=True)
# Escrituras que repetidas dejan el mismo estado (la ingesta es idempotente por clave natural).
WRITE_IDEMPOTENT = ToolAnnotations(read_only_hint=False, destructive_hint=False, idempotent_hint=True, open_world_hint=True)
# Herramientas que solo miran el propio servidor MCP (perfiles, carpeta de archivos).
LOCAL_READ = ToolAnnotations(read_only_hint=True, destructive_hint=False, idempotent_hint=True, open_world_hint=False)


class Confirmation(BaseModel):
    confirmar: bool = Field(description="Marque para aprobar exactamente lo descrito. Sin marcar, no se hace nada.")


# Tipo del parámetro de una herramienta que exige aprobación: `Annotated[Approval, Resolve(<pregunta>)]`. Ese parámetro
# lo llena el servidor con la respuesta de la PERSONA; no forma parte de los argumentos que puede escribir el modelo.
Approval = ElicitationResult[Confirmation]


def _bearer(headers: Mapping[str, str]) -> str | None:
    value = headers.get("authorization", "")
    scheme, _, token = value.partition(" ")
    token = token.strip()
    return token if scheme.lower() == "bearer" and token else None


class Runtime:
    def __init__(
        self,
        settings: Settings,
        *,
        api: PredictilyApi | None = None,
        profiles: SourceProfiles | None = None,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        self.settings = settings
        self.api = api or PredictilyApi(settings, transport=transport)
        self.profiles = profiles if profiles is not None else SourceProfiles()

    # ── identidad ────────────────────────────────────────────────────────────────────────────────────────────────

    def credentials(self, ctx: Context) -> tuple[str, str | None]:
        """(token, empresa) de la persona que hace ESTA petición."""
        if self.settings.http_mode:
            headers = {k.lower(): v for k, v in (ctx.headers or {}).items()}
            token = _bearer(headers)
            if token is None:
                raise ToolError(
                    "Falta la cabecera «Authorization: Bearer <token personal de Predictily>» en la conexión MCP. "
                    "Cada persona se conecta con su propio token; este servidor no tiene uno compartido."
                )
            return token, headers.get("x-tenant-id") or self.settings.tenant_id
        if not self.settings.api_token:
            raise ToolError("El servidor MCP no tiene PREDICTILY_API_TOKEN configurado.")
        return self.settings.api_token, self.settings.tenant_id

    # ── API ──────────────────────────────────────────────────────────────────────────────────────────────────────

    async def call(
        self,
        ctx: Context,
        method: str,
        path: str,
        *,
        ability: str | None = None,
        params: Mapping[str, Any] | None = None,
        json: Any = None,
        data: Mapping[str, Any] | None = None,
        files: Mapping[str, Any] | None = None,
    ) -> Any:
        """Llama a la API como la persona de la petición. Un error de la API llega al modelo explicado (`is_error`)."""
        token, tenant_id = self.credentials(ctx)
        try:
            return await self.api.request(method, path, token=token, tenant_id=tenant_id, params=params, json=json, data=data, files=files)
        except ApiError as error:
            raise ToolError(explain(error, ability=ability)) from None

    async def get(self, ctx: Context, path: str, *, ability: str | None = None, **params: Any) -> Any:
        return await self.call(ctx, "GET", path, ability=ability, params=params)

    async def poll(self, ctx: Context, path: str, *, done: Any, wait_seconds: int, ability: str | None = None) -> tuple[Any, bool]:
        """Lee `path` hasta que `done(payload)` sea cierto o pasen `wait_seconds`. Devuelve (último estado, ¿terminó?)."""
        deadline = anyio.current_time() + max(0, wait_seconds)
        polls = 0
        while True:
            payload = await self.call(ctx, "GET", path, ability=ability)
            if done(payload):
                return payload, True
            if anyio.current_time() + self.settings.poll_interval_seconds > deadline:
                return payload, False
            polls += 1
            try:
                await ctx.report_progress(polls, None, "Esperando a que termine el trabajo en la cola…")
            except Exception:  # noqa: BLE001 — el progreso es cortesía: un cliente que no lo admite no rompe la espera
                pass
            await anyio.sleep(self.settings.poll_interval_seconds)

    # ── salida ───────────────────────────────────────────────────────────────────────────────────────────────────

    def out(self, payload: Any) -> str:
        """Respuesta para el modelo: paginación uniforme, PII enmascarada y tamaño acotado."""
        return render(mask_pii(normalize(payload)), self.settings.max_result_chars)

    # ── aprobación humana ────────────────────────────────────────────────────────────────────────────────────────

    def approval_request(self, ctx: Context, what: str) -> Elicit[Confirmation] | Confirmation:
        """Lo que devuelve la «pregunta» (resolver) de una herramienta con aprobación, según `PREDICTILY_MCP_APPROVALS`.

        En Predictily aprobar un mapeo (activa una consulta contra el ERP del cliente) o confirmar una importación es
        una acción humana explícita: el modelo propone y prepara; quien aprueba es la persona. `elicit` devuelve la
        pregunta para que el cliente MCP se la haga a la persona (la respuesta la inyecta el SDK en un parámetro que el
        modelo no puede escribir); `allow` la da por aprobada porque quien opera el servidor asumió esa decisión; `deny`
        la rechaza. La pregunta lleva el texto exacto de lo que se aprueba: si ese texto cambia, se vuelve a preguntar.
        """
        mode = self.settings.approvals
        if mode == "allow":
            return Confirmation(confirmar=True)
        if mode == "deny":
            raise ToolError(
                "Las aprobaciones desde el agente están desactivadas (PREDICTILY_MCP_APPROVALS=deny). "
                "La persona debe aprobarlo en la aplicación de Predictily. Pendiente de aprobar:\n" + what
            )
        capabilities = ctx.client_capabilities
        if capabilities is None or capabilities.elicitation is None:
            raise ToolError(
                "Esta acción requiere la confirmación explícita de una persona y este cliente MCP no puede pedirla "
                "(no admite «elicitation»). Opciones: aprobarlo en la aplicación de Predictily, o que quien opera el "
                "servidor declare PREDICTILY_MCP_APPROVALS=allow asumiendo esa decisión. Pendiente de aprobar:\n" + what
            )
        return Elicit("Predictily necesita su confirmación antes de continuar.\n\n" + what, Confirmation)

    @staticmethod
    def ensure_approved(approval: Approval) -> None:
        """Corta la herramienta salvo que la persona haya aceptado Y marcado la confirmación."""
        if approval.action != "accept" or not approval.data.confirmar:
            raise ToolError("La persona no confirmó la acción: no se cambió nada. No lo reintente sin que ella lo pida.")
