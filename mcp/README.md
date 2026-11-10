# Servidores MCP de Predictily

Dos servidores [MCP](https://modelcontextprotocol.io) para que un agente (el LLM que la persona elija) trabaje con
Predictily **con las credenciales de esa persona**:

| Servidor | Comando | Para qué | Herramientas |
|---|---|---|---|
| **Siembra** (`predictily_ingest_mcp`) | `predictily-mcp ingest` | Llevar los datos del sistema del cliente (ERP/POS por SQL, o archivos CSV/XLSX) a la base ya estructurada de Predictily | 37 |
| **Análisis** (`predictily_insights_mcp`) | `predictily-mcp insights` | Consultar y presentar los análisis ya calculados (pronósticos, inventario, abastecimiento, radar, decisiones, resultados) | 19 (24 con las de cliente) |

Ambos son **clientes de la API pública** `/api/v1`: no tocan la base de datos, no hablan con el motor y no agregan
ninguna ruta al backend. Lo que una persona puede hacer por MCP es exactamente lo que su rol y su token le permiten en
la API (habilidades, aislamiento por empresa, alcance comercial, límites del plan). Diseño, garantías y límites:
[`docs/integrations/MCP_SERVERS.md`](../docs/integrations/MCP_SERVERS.md).

## Instalación

Requiere Python 3.12 o superior. Sin relación con el venv del motor.

```bash
cd mcp
python -m venv .venv
.venv/bin/pip install -e ".[dev]"        # Windows: .venv\Scripts\pip install -e ".[dev]"
.venv/bin/python -m pytest -q            # 107 pruebas, sin red ni base de datos
```

## 1. El token de la persona

Cada persona usa **su propio** token personal: ligado a una empresa, con las habilidades mínimas y con caducidad
(`docs/security/ENTERPRISE_SECURITY.md`). La aplicación web aún no tiene pantalla para emitirlo; se hace por la API.
Estos pasos los ejecuta la persona, no el agente (su contraseña no se escribe en el chat):

```bash
API=http://127.0.0.1:8000/api/v1
# 1) Iniciar sesión como cliente API (si tiene MFA, la respuesta pide completar /auth/mfa/challenge).
curl -s -X POST $API/auth/login -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email": "<su correo>", "password": "<su contraseña>"}'          # → data.token (de sesión, con «*»)

# 2) Con ese token de sesión, emitir el token ACOTADO para el servidor MCP (se muestra una sola vez).
curl -s -X POST $API/auth/tokens -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer <token de sesión>" \
  -d '{"tenant": "<uuid de la empresa>", "name": "mcp-analisis", "expires_in_days": 30,
       "abilities": ["data.view", "forecast.view", "alerts.view", "decisions.view"]}'

# 3) Cerrar el token de sesión: el servidor MCP no debe usar un token con «*».
curl -s -X POST $API/auth/logout -H "Authorization: Bearer <token de sesión>"
```

| Servidor | Habilidades del token | Rol mínimo |
|---|---|---|
| Análisis | `data.view`, `forecast.view`, `alerts.view`, `decisions.view` | consulta (`viewer`) |
| Siembra | `data.view`, `imports.manage`, `datasources.manage` | administrador (`admin`) |
| Siembra, con plantillas del Auto Mapper | las anteriores + `settings.manage` | administrador (`admin`) |

Un token solo puede llevar habilidades que el rol concede (422 si no). Revocarlo (`DELETE /auth/tokens/{id}`) corta el
acceso del agente en la siguiente petición.

## 2. Configuración

Por variables de entorno (lista completa en [`.env.example`](.env.example)):

| Variable | Por defecto | Efecto |
|---|---|---|
| `PREDICTILY_API_URL` | — (obligatoria) | URL de la API, incluido `/api/v1`. `http` solo hacia el propio equipo |
| `PREDICTILY_API_TOKEN` | — (obligatoria en stdio) | Token personal de la persona |
| `PREDICTILY_TENANT_ID` | — | Solo si el token no está ligado a una empresa |
| `PREDICTILY_MCP_APPROVALS` | `elicit` | Aprobar mapeos/plantillas y confirmar importaciones: `elicit` (la persona confirma en su cliente), `deny` (solo en la aplicación), `allow` (el agente aprueba) |
| `PREDICTILY_MCP_ROW_SAMPLES` | `shapes` | Filas del sistema del cliente hacia el modelo: `shapes` (solo la forma: `2026-01-31` → `9999-99-99`) o `full` |
| `PREDICTILY_MCP_CUSTOMER_TOOLS` | `false` | Herramientas de análisis por cliente (códigos de comprador) |
| `PREDICTILY_MCP_IMPORT_DIR` | — | Carpeta de CSV/XLSX que el agente puede subir. Sin ella, la carga de archivos está desactivada |
| `PREDICTILY_SOURCE_<NOMBRE>_*` / `PREDICTILY_MCP_SOURCE_PROFILES` | — | Perfiles de conexión a la base del cliente (ver abajo) |

### Credenciales de la base del cliente: perfiles

La contraseña de la base del cliente **nunca pasa por el modelo**. Quien opera el servidor define un perfil; el agente
solo lo nombra (`credentials_profile: "erp"`), y el servidor envía usuario y contraseña directo a la API, que los guarda
cifrados y no los devuelve.

```bash
PREDICTILY_SOURCE_ERP_CONNECTOR=sql_sqlserver      # sql_sqlserver | sql_postgresql | sql_mysql
PREDICTILY_SOURCE_ERP_HOST=<host del ERP>
PREDICTILY_SOURCE_ERP_DATABASE=<base>
PREDICTILY_SOURCE_ERP_USERNAME=<cuenta de solo lectura>
PREDICTILY_SOURCE_ERP_PASSWORD=<contraseña>
PREDICTILY_SOURCE_ERP_TRUST_SERVER_CERTIFICATE=true
```

Use una cuenta de **solo lectura** en el origen (SQL Server: `db_datareader`) y declare el host en la lista blanca
anti-SSRF del backend (`PREDICTILY_SQL_ALLOWED_HOSTS` / `PREDICTILY_SQL_ALLOWED_CIDRS`).

## 3. Conectar un cliente MCP

**stdio** (un proceso por persona; lo lanza el cliente). Ejemplo de configuración (`.mcp.json`, Claude Desktop,
Claude Code, Cursor u otro cliente compatible):

```json
{
  "mcpServers": {
    "predictily-analisis": {
      "command": "/ruta/a/predictily/mcp/.venv/bin/predictily-mcp",
      "args": ["insights"],
      "env": {
        "PREDICTILY_API_URL": "http://127.0.0.1:8000/api/v1",
        "PREDICTILY_API_TOKEN": "<token personal acotado>"
      }
    },
    "predictily-siembra": {
      "command": "/ruta/a/predictily/mcp/.venv/bin/predictily-mcp",
      "args": ["ingest"],
      "env": {
        "PREDICTILY_API_URL": "http://127.0.0.1:8000/api/v1",
        "PREDICTILY_API_TOKEN": "<token personal con datasources.manage>",
        "PREDICTILY_SOURCE_ERP_CONNECTOR": "sql_sqlserver",
        "PREDICTILY_SOURCE_ERP_HOST": "<host>",
        "PREDICTILY_SOURCE_ERP_DATABASE": "<base>",
        "PREDICTILY_SOURCE_ERP_USERNAME": "<usuario de solo lectura>",
        "PREDICTILY_SOURCE_ERP_PASSWORD": "<contraseña>"
      }
    }
  }
}
```

En Windows el comando es `…\mcp\.venv\Scripts\predictily-mcp.exe`. Ese archivo contiene secretos: no lo versione.

**HTTP** (un proceso para varias personas). El servidor no tiene token propio: cada persona envía el suyo en la
cabecera `Authorization: Bearer …` de su conexión, y el servidor lo reenvía tal cual a la API.

```bash
PREDICTILY_API_URL=https://app.su-dominio.com/api/v1 predictily-mcp insights --transport http --port 8766
# cliente: URL http://127.0.0.1:8766/mcp + cabecera Authorization: Bearer <token personal>
```

Escucha en `127.0.0.1`. Para exponerlo, póngalo detrás de un proxy con TLS (el token viaja en cada petición).

## 4. Qué hace cada servidor

### Siembra

Orquesta los caminos de ingesta que ya existen (`docs/integrations/CONNECTOR_FRAMEWORK.md`); no hay un segundo importador.

| Paso | Herramientas |
|---|---|
| Contexto | `predictily_whoami`, `predictily_list_connectors`, `predictily_list_data_sources`, `predictily_list_source_profiles` |
| Fuente SQL | `predictily_create_sql_source`, `predictily_test_data_source`, `predictily_update_data_source` |
| Esquema (solo metadatos) | `predictily_discover_schema` |
| Mapeo automático | `predictily_propose_mappings`, `predictily_list_mapping_templates`, `predictily_get_mapping_template`, `predictily_edit_mapping_template`, `predictily_check_template_drift`, `predictily_approve_mapping_template`\*, `predictily_apply_mapping_template`\* |
| Mapeo con SELECT propio | `predictily_create_mapping`, `predictily_list_mappings`, `predictily_preview_mapping`, `predictily_approve_mapping`\*, `predictily_deactivate_mapping` |
| Sincronización | `predictily_start_sync`, `predictily_get_sync`, `predictily_list_syncs` |
| Archivos CSV/XLSX | `predictily_list_import_files`, `predictily_get_import_fields`, `predictily_upload_import_file`, `predictily_set_import_mapping`, `predictily_get_import`, `predictily_list_imports`, `predictily_list_import_errors`, `predictily_confirm_import`\*, `predictily_reject_import` |
| Verificación | `predictily_sales_summary`, `predictily_get_data_quality`, `predictily_list_quarantine`, `predictily_list_reconciliation`, `predictily_get_data_coverage` |

\* Decisión humana: según `PREDICTILY_MCP_APPROVALS`, la persona la confirma en su cliente MCP viendo la consulta exacta.

### Análisis

Todas de **solo lectura**. Las cifras salen de lo que Predictily ya calculó; el modelo no calcula.

| Tema | Herramientas |
|---|---|
| Contexto y catálogos | `predictily_whoami`, `predictily_list_products`, `predictily_list_branches`, `predictily_list_categories` |
| Demanda | `predictily_dashboard_summary`, `predictily_forecast_products`, `predictily_forecast_categories`, `predictily_forecast_series`, `predictily_sales_history`, `predictily_list_alerts` |
| Confiabilidad | `predictily_forecast_accuracy`, `predictily_model_quality` |
| Inventario y abastecimiento | `predictily_inventory_summary`, `predictily_inventory_recommendations`, `predictily_supply_plans`, `predictily_supply_plan_detail` |
| Comercial (agregado) | `predictily_radar_summary`, `predictily_decisions_summary`, `predictily_roi_summary` |
| Por cliente (`PREDICTILY_MCP_CUSTOMER_TOOLS=true`) | `predictily_radar_opportunities`, `predictily_customer_predictions`, `predictily_list_decisions`, `predictily_explain_recommendation`, `predictily_outcomes` |

## 5. Lo que debe saber antes de usarlo con datos reales

- **Los resultados van al LLM que la persona conecte.** Predictily no controla ese proveedor. No existe todavía un
  consentimiento por empresa para enviar datos a un tercero (V25-SEC-040): conectar un LLM externo es una decisión de la
  empresa. El runbook del piloto (`docs/operations/PILOT_RUNBOOK.md` §0) prohíbe enviar datos del piloto a un proveedor
  LLM: con datos de un piloto use un modelo local o no conecte el servidor de análisis.
- El servidor de siembra, por defecto, **no entrega al modelo ningún valor del sistema del cliente**: solo nombres de
  tablas y columnas, conteos, estadísticas de la carga y la forma de los valores.
- El enmascarado de PII es por expresiones regulares (correos, cédula/RUC, tokens): no detecta nombres propios en texto libre.
- Probado contra una API simulada. **No se ha ejecutado contra un backend real** (ver el documento de diseño).
