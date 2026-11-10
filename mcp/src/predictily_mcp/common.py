"""Tipos de argumento y herramientas que comparten los dos servidores."""

from typing import Annotated, Any

from mcp.server.mcpserver import Context, MCPServer
from mcp.server.mcpserver.exceptions import ToolError
from pydantic import Field

from .runtime import READ, Runtime

# ── argumentos reutilizados ──────────────────────────────────────────────────────────────────────────────────────
Id = Annotated[int, Field(ge=1, description="Identificador numérico (de la herramienta de listado correspondiente).")]
OptionalId = Annotated[int | None, Field(ge=1, description="Identificador numérico para filtrar; omítalo para no filtrar.")]
Page = Annotated[int, Field(ge=1, le=100000, description="Página de resultados (empieza en 1).")]
PerPage = Annotated[int, Field(ge=1, le=100, description="Resultados por página (máx. 100). Use valores pequeños.")]
IsoDate = Annotated[str, Field(pattern=r"^\d{4}-\d{2}-\d{2}$", description="Fecha AAAA-MM-DD.")]
OptionalDate = Annotated[str | None, Field(pattern=r"^\d{4}-\d{2}-\d{2}$", description="Fecha AAAA-MM-DD; omítala para no filtrar.")]
WaitSeconds = Annotated[
    int,
    Field(ge=0, le=300, description="Segundos que la herramienta espera a que el trabajo termine antes de responder (0 = no espera; máx. 300)."),
]


def data_of(payload: Any) -> Any:
    """`data` de una respuesta `{data: …}` de la API (o la respuesta tal cual)."""
    return payload["data"] if isinstance(payload, dict) and "data" in payload else payload


def register_whoami(server: MCPServer, rt: Runtime, *, purpose: str) -> None:
    @server.tool(name="predictily_whoami", title="Quién soy y en qué empresa trabajo", annotations=READ)
    async def predictily_whoami(ctx: Context) -> str:
        """Identifica a la persona del token, su empresa activa, su rol y el estado de los datos de esa empresa.

        Llámela PRIMERO: confirma que el token funciona y dice qué hay cargado (sucursales, productos, ventas,
        inventario, pronóstico) y con qué políticas corre este servidor MCP. No cambia nada.

        Devuelve JSON: {user:{id,name,email(enmascarado)}, tenant:{uuid,name,sector,timezone,currency,role,plan,
        onboarding:{has_branches,has_products,has_sales,has_inventory,has_forecast,completed_at}}, server_policy:{…}}.
        Si el token no está ligado a una empresa y no se configuró PREDICTILY_TENANT_ID, `tenant` trae el motivo y la
        lista de empresas disponibles.
        """
        # Una respuesta con otra forma (otra versión de la API) no debe romper la herramienta: se informa lo que haya.
        me = data_of(await rt.call(ctx, "GET", "auth/me"))
        me = me if isinstance(me, dict) else {}
        user = me["user"] if isinstance(me.get("user"), dict) else {}
        result: dict[str, Any] = {"user": {k: user.get(k) for k in ("id", "name", "email")}}
        try:
            tenant = data_of(await rt.call(ctx, "GET", "tenant"))
            tenant = tenant if isinstance(tenant, dict) else {}
            plan = tenant["plan"] if isinstance(tenant.get("plan"), dict) else {}
            result["tenant"] = {
                **{k: tenant.get(k) for k in ("uuid", "name", "sector", "timezone", "currency", "role")},
                "plan": {k: plan.get(k) for k in ("code", "name")},
                "onboarding": tenant.get("onboarding"),
            }
        except ToolError as error:
            tenants = me["tenants"] if isinstance(me.get("tenants"), list) else []
            result["tenant"] = {
                "error": str(error),
                "available": [{k: t.get(k) for k in ("uuid", "name", "role")} for t in tenants if isinstance(t, dict)],
                "hint": "Emita un token ligado a una empresa, o configure PREDICTILY_TENANT_ID con el uuid de una de estas.",
            }
        result["server_policy"] = {
            "purpose": purpose,
            "approvals": rt.settings.approvals,
            "row_samples": rt.settings.row_samples,
            "customer_tools": rt.settings.customer_tools,
        }
        return rt.out(result)
