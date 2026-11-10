# Servidores MCP de Predictily (siembra de datos y análisis)

Fecha: 10-nov-2026 · Paquete `mcp/` (`predictily-mcp` 0.1.0, Python ≥ 3.12, SDK `mcp` 2.3.0) · Relacionado con
`CONNECTOR_FRAMEWORK.md` (cómo entran los datos), `../architecture/INTELLIGENCE_GATEWAY.md` (asistente con herramientas
tipadas), `../security/ENTERPRISE_SECURITY.md` (tokens con alcance) y `../operations/PILOT_RUNBOOK.md` (reglas del piloto).

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba que lo demuestra) · **IMPLEMENTADO / SIN VERIFICAR**
· **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO**.

> **Aviso destacado — qué se verificó y qué no.** Las 107 pruebas de `mcp/tests` corren contra una **API simulada**
> (ningún backend, ninguna base, ningún ERP). Demuestran qué pide cada herramienta a la API y qué entrega al modelo; **no
> demuestran que el backend real responda como dicen el contrato y la OpenAPI**. En el equipo donde se construyó no hay PHP
> ni PostgreSQL y Docker estaba detenido, así que **ningún servidor se ha ejecutado contra un backend real**: es el primer
> paso pendiente (§ 9).

> **Aviso destacado — datos hacia un LLM de terceros.** El servidor de análisis entrega resultados de la empresa al LLM que
> la persona conecte. Predictily no controla ese proveedor y **no existe un consentimiento por empresa** para ese envío
> (V25-SEC-040: DISEÑADO / NO IMPLEMENTADO): cualquier miembro con `forecast.view` puede emitir un token y conectarlo. La
> regla 4 del runbook del piloto prohíbe enviar datos del piloto a un proveedor LLM. Hasta que exista ese consentimiento,
> conectar un LLM externo es una decisión de la empresa y de quien opera la plataforma.

## 1. Qué son y qué no son

Dos servidores [MCP](https://modelcontextprotocol.io) que dejan a un agente trabajar con Predictily **como una persona
concreta**:

| Servidor | Para qué | Escribe |
|---|---|---|
| **Siembra** (`predictily-mcp ingest`, 37 herramientas) | Llevar los datos del sistema del cliente a la base estructurada: registrar la fuente SQL, descubrir el esquema, mapear, sincronizar, cargar archivos y verificar lo cargado | Sí, solo por la API de ingesta |
| **Análisis** (`predictily-mcp insights`, 19 herramientas; 24 con las de cliente) | Consultar y presentar lo ya calculado: demanda, precisión, inventario, abastecimiento, radar, decisiones, resultados | No (solo lectura) |

Son **clientes de la API pública** `/api/v1` (`docs/06-contratos-integracion.md`). No son:

- **Un segundo importador.** Todo entra por el marco de conectores: original íntegro en Bronze → normalización →
  importador canónico (validación, idempotencia, linaje). Ninguna herramienta escribe en la base ni en el origen.
- **Un canal nuevo del Intelligence Gateway.** El gateway sigue alcanzándose solo por el asistente
  (`GatewayLimitsAndDisabledTest::test_the_gateway_is_only_reachable_through_the_assistant…` no cambió). El servidor de
  análisis usa las **rutas de resultados equivalentes**, las mismas que la SPA (§ 6).
- **Un proveedor LLM.** No llaman a ningún modelo; el modelo es el del cliente MCP de la persona.
- **Parte del backend.** No agregan rutas, tablas ni dependencias a `backend/` ni a `engine/`.

## 2. Arquitectura

```
persona ── cliente MCP (su LLM) ──stdio o HTTP──► predictily-mcp ──HTTPS, Bearer <token de la persona>──► /api/v1 (Laravel)
                                                       │                                                     ├─ habilidades del rol y del token
                                              perfiles de conexión                                           ├─ aislamiento por empresa
                                              (credenciales del ERP,                                         ├─ alcance comercial, plan, tasa
                                               fuera del modelo)                                             └─ conectores → Bronze → importador
```

| Pieza | Archivo (`mcp/src/predictily_mcp/`) | Responsabilidad |
|---|---|---|
| `Settings` | `config.py` | Variables de entorno; valores por defecto reservados; el token no aparece en `repr` |
| `PredictilyApi`, `explain` | `api.py` | Única salida HTTP; sin redirecciones; errores traducidos a un siguiente paso |
| `Runtime` | `runtime.py` | Identidad de CADA petición, llamada a la API, salida y política de aprobaciones |
| `mask_pii`, `shape_of`, `profile_rows` | `privacy.py` | Enmascarado (mismo criterio que `PiiMasker`) y forma de los valores sin su contenido |
| `normalize`, `render` | `output.py` | Paginación uniforme y tamaño acotado, siempre JSON válido |
| `SourceProfiles` | `sources.py` | Credenciales de la base del cliente, fuera del alcance del modelo |
| `build_ingest_server`, `build_insights_server` | `ingest.py`, `insights.py` | Herramientas |
| `main` | `cli.py` | Arranque `stdio` o `http` |

## 3. Identidad: con las credenciales de la persona — **IMPLEMENTADO / VERIFICADO** (contra API simulada)

La empresa, el usuario, el rol y las habilidades **no son argumentos**: salen del token (personal, ligado a una empresa,
con habilidades explícitas y caducidad; W6-A). Ninguna herramienta acepta `tenant_id`, `user_id` ni un token.

| Transporte | De dónde sale el token | Prueba |
|---|---|---|
| `stdio` (un proceso por persona) | `PREDICTILY_API_TOKEN` del entorno de esa persona | `test_api.py::test_every_request_carries_the_persons_token…` |
| `http` (un proceso, varias personas) | cabecera `Authorization: Bearer …` de **cada** petición, reenviada tal cual; el servidor no tiene token propio y el del entorno se ignora | `test_http.py::test_each_connection_reaches_the_api_with_its_own_persons_token`, `…without_a_personal_token_nothing_reaches_the_api`, `…has_no_token_of_its_own_to_fall_back_on` (uvicorn real) |

Otras garantías, con su prueba:

- El token **no se reenvía a otro host**: no se siguen redirecciones (`test_a_redirect_is_never_followed_with_the_token`).
- `http` en claro solo hacia el propio equipo; hacia otro host exige `https` o una declaración explícita
  (`test_plain_http_to_a_remote_host_is_refused_unless_declared`).
- El token no aparece en errores, en `repr` ni en lo que vuelve al modelo (`test_a_network_failure_is_reported_without_the_url_or_the_token`,
  `test_the_token_never_appears_in_repr`, `test_a_failing_connector_message_never_carries_the_environment_token`).
- Un 403 dice qué habilidad falta (`test_a_missing_ability_names_the_ability_the_token_needs`).

Habilidades por servidor: análisis → `data.view`, `forecast.view`, `alerts.view`, `decisions.view` (rol de consulta);
siembra → `data.view`, `imports.manage`, `datasources.manage` y, para plantillas, `settings.manage` (rol administrador).

**Límite:** en modo `http` el servidor confía en que el transporte esté cifrado; escucha en `127.0.0.1` y exponerlo exige
un proxy con TLS (no incluido). No hay OAuth: la credencial es el token Sanctum de la API.

## 4. Servidor de siembra

### 4.1 Flujo y rutas — **IMPLEMENTADO / VERIFICADO** (forma de las peticiones)

| Paso | Herramienta | Ruta de la API | Habilidad |
|---|---|---|---|
| Conectores, fuentes | `predictily_list_connectors`, `predictily_list_data_sources` | `GET /connectors`, `GET /data-sources` | `data.view` |
| Registrar fuente SQL | `predictily_create_sql_source` | `POST /data-sources` | `datasources.manage` |
| Probar, corregir, reactivar | `predictily_test_data_source`, `predictily_update_data_source` | `POST /data-sources/{id}/test`, `PATCH /data-sources/{id}` | `datasources.manage` |
| Esquema (metadatos) | `predictily_discover_schema` | `POST /data-sources/{id}/schema/discover` | `datasources.manage` |
| Auto Mapper | `predictily_propose_mappings`, `…_list/get/edit_mapping_template`, `predictily_check_template_drift` | `POST …/mapping-proposals`, `GET/PATCH /mapping-templates…`, `POST …/drift` | `datasources.manage` |
| Aprobar y aplicar plantilla\* | `predictily_approve_mapping_template`, `predictily_apply_mapping_template` | `POST /mapping-templates/{id}/approve`, `POST …/mapping-templates/{id}/apply` | `settings.manage` + `datasources.manage` |
| SELECT propio | `predictily_create_mapping`, `predictily_list_mappings`, `predictily_preview_mapping`, `predictily_deactivate_mapping` | `…/mappings`, `…/preview`, `…/deactivate` | `datasources.manage` |
| Aprobar mapeo\* | `predictily_approve_mapping` | `POST …/mappings/{id}/approve` | `datasources.manage` |
| Sincronizar | `predictily_start_sync`, `predictily_get_sync`, `predictily_list_syncs` | `POST …/sync`, `GET /syncs/{id}`, `GET …/syncs` | `datasources.manage` |
| Archivos | `predictily_upload_import_file`, `predictily_set_import_mapping`, `predictily_get_import`, `predictily_list_imports`, `predictily_list_import_errors`, `predictily_reject_import`, `predictily_get_import_fields` | `/imports…`, `/import-fields/{entidad}` | `imports.manage` / `data.view` |
| Confirmar importación\* | `predictily_confirm_import` | `POST /imports/{id}/confirm` | `imports.manage` |
| Verificar | `predictily_sales_summary`, `predictily_get_data_quality`, `predictily_list_quarantine`, `predictily_list_reconciliation`, `predictily_get_data_coverage` | `/sales/summary`, `/data-quality/…`, `/lineage/reconciliation`, `/data-coverage…` | `data.view` |

\* Decisión humana (§ 4.3). No se exponen a propósito: borrar una fuente, emitir tokens de conector, liberar o descartar una
cuarentena y registrar agentes Bridge (se hacen en la aplicación).

Detalles que recogen las lecciones del primer pull real (`PILOT_RUNBOOK.md` § 5 bis): una fuente creada con la prueba
fallida queda `inactive` y la herramienta dice cómo reactivarla; `predictily_update_data_source` **fusiona** la
configuración (la API reemplaza `config` entero); la descripción de `predictily_create_mapping` advierte de CTE y `ORDER BY`
en SQL Server; `statement_timeout_seconds` es un argumento.

### 4.2 Las credenciales del cliente no pasan por el modelo — **IMPLEMENTADO / VERIFICADO**

El agente nombra un **perfil** (`credentials_profile`); usuario y contraseña se leen del entorno del servidor MCP
(`PREDICTILY_SOURCE_<NOMBRE>_*`) o de un archivo local, y van directo a la API, que los guarda cifrados. La herramienta no
tiene ningún argumento donde escribir una contraseña y lo que ve el modelo de un perfil es su nombre, su conector y qué
claves trae (ni host ni usuario). Pruebas: `test_a_sql_source_is_created_with_the_profiles_credentials`,
`test_the_profile_listing_shows_names_only`, `test_what_the_model_can_see_of_a_profile_has_no_secret_and_no_host`,
`test_an_unreadable_profiles_file_does_not_echo_its_content`.

**Límite:** `GET /data-sources` devuelve `config` (host y base) y el modelo lo ve; no es un secreto, pero es un dato de la
infraestructura del cliente.

### 4.3 Aprobar es una decisión de la persona — **IMPLEMENTADO / VERIFICADO**

Aprobar un mapeo activa una consulta contra el ERP del cliente; confirmar una importación escribe los datos. El contrato
dice que son acciones humanas («nunca aprueba ni sincroniza»). Las cuatro herramientas marcadas piden la confirmación con
el mecanismo del protocolo (*elicitation*): el cliente MCP muestra a la persona **el texto exacto** (la consulta SELECT, o
el resumen de la importación) y su respuesta la inyecta el SDK en un parámetro que **no está en el esquema** de la
herramienta.

| `PREDICTILY_MCP_APPROVALS` | Comportamiento | Prueba |
|---|---|---|
| `elicit` (por defecto) | la persona confirma en su cliente; sin confirmación no se llama a la API | `test_approving_asks_the_person_and_shows_the_exact_query`, `test_nothing_is_approved_unless_the_person_confirms` |
| `elicit`, cliente sin *elicitation* | se rechaza y se indica aprobar en la aplicación | `test_a_client_that_cannot_ask_the_person_cannot_approve` |
| `deny` | siempre se rechaza | `test_with_approvals_denied_no_gated_tool_writes` |
| `allow` | el agente aprueba; decisión de quien opera el servidor | `test_with_approvals_allowed_the_owner_took_the_decision` |

El modelo no puede fabricar la aprobación pasándola como argumento (`test_the_model_cannot_supply_the_approval_itself`).

**Límites:** la garantía es tan fuerte como el cliente MCP: un cliente que respondiera la *elicitation* sin preguntar a
nadie aprobaría. La API no distingue una aprobación hecha por MCP de una hecha en la aplicación (queda auditada como la
persona del token). Iniciar una sincronización no pide confirmación: solo funciona con un mapeo ya aprobado.

### 4.4 Ningún valor del origen llega al modelo por defecto — **IMPLEMENTADO / VERIFICADO**

Con `PREDICTILY_MCP_ROW_SAMPLES=shapes` (por defecto) las tres salidas que traen filas se sustituyen por su **forma**
(letras → `A`, dígitos → `9`): la vista previa de un mapeo, la vista previa de un archivo subido y el valor crudo de un
error de importación. El descubrimiento de esquema ya es solo metadatos en la API. Pruebas:
`test_a_mapping_preview_gives_shapes_not_the_clients_rows`, `test_a_file_from_the_import_folder_is_uploaded_and_its_rows_stay_private`,
`test_import_errors_show_the_shape_of_the_offending_value`.

**Límites:** los **nombres** de tablas y columnas sí llegan al modelo; el `message` de un error de importación puede citar
un valor; `predictily_propose_mappings` con `sample=true` devuelve vocabulario de tipo y estado de documento (la API ya lo
filtra a una lista permitida); las estadísticas de la carga (conteos, rangos de fechas) son datos agregados del negocio.

### 4.5 Archivos — **IMPLEMENTADO / VERIFICADO**

Desactivado hasta declarar `PREDICTILY_MCP_IMPORT_DIR`. Solo `.csv` y `.xlsx` **dentro** de esa carpeta (sin salir por
`..` ni por enlaces), hasta 20 MB. Pruebas: `test_only_csv_or_xlsx_inside_the_import_folder_can_be_uploaded`,
`test_file_uploads_are_off_until_the_operator_names_a_folder`.

## 5. Servidor de análisis

### 5.1 Solo lectura — **IMPLEMENTADO / VERIFICADO**

Las 24 herramientas declaran `readOnlyHint` y una prueba las llama a todas y comprueba que el servidor solo emite `GET`
(`test_every_tool_is_declared_read_only_and_only_ever_reads`). No dispara pronósticos, no entrena, no cambia el estado de
una recomendación ni envía nada.

### 5.2 Catálogo

| Tema | Herramienta | Ruta | Habilidad |
|---|---|---|---|
| Resumen | `predictily_dashboard_summary` | `GET /dashboard/summary` | `forecast.view` |
| Demanda | `predictily_forecast_products`, `…_categories`, `…_series` | `GET /forecasts/…` | `forecast.view` |
| Confiabilidad | `predictily_forecast_accuracy`, `predictily_model_quality` | `GET /accuracy/summary`, `GET /models/evaluations` | `forecast.view` |
| Inventario | `predictily_inventory_summary`, `predictily_inventory_recommendations` | `GET /inventory/…` | `forecast.view` |
| Abastecimiento | `predictily_supply_plans`, `predictily_supply_plan_detail` | `GET /supply/plans…` | `forecast.view` |
| Comercial agregado | `predictily_radar_summary`, `predictily_decisions_summary`, `predictily_roi_summary` | `GET /radar/summary`, `/decisions/summary`, `/decisions/roi` | `forecast.view` / `decisions.view` |
| Historia y alertas | `predictily_sales_history`, `predictily_list_alerts` | `GET /sales/summary`, `/alerts` | `data.view` / `alerts.view` |
| Catálogos | `predictily_list_products`, `…_branches`, `…_categories` | `GET /products`, `/branches`, `/product-categories` | `data.view` |
| **Por cliente** (si se habilita) | `predictily_radar_opportunities`, `predictily_customer_predictions`, `predictily_list_decisions`, `predictily_explain_recommendation`, `predictily_outcomes` | `GET /radar/opportunities`, `/customer/predictions`, `/decisions…` | `forecast.view` / `decisions.view` |

Las herramientas por cliente exponen códigos de comprador y están **apagadas por defecto**
(`PREDICTILY_MCP_CUSTOMER_TOOLS`, espejo de `conversation.privacy.customer_tools_enabled`); apagadas, no figuran en el
catálogo ni se pueden llamar por nombre (`test_customer_level_tools_are_hidden_unless_the_operator_enables_them`,
`test_a_hidden_customer_tool_cannot_be_called_by_name`).

### 5.3 Cifras y calidad

Las instrucciones del servidor piden citar las cifras tal cual, con su corte y horizonte, y mencionar
`horizon_support.status` / `quality_status` cuando no son `ok`. El servidor entrega esos campos sin tocarlos
(`test_the_forecast_ranking_passes_the_documented_filters`). **Es una instrucción, no una garantía** (§ 6).

## 6. Diferencias con el Intelligence Gateway (léalas antes de elegir)

| Garantía del Gateway | Asistente (`/assistant/messages`) | Servidor MCP de análisis |
|---|---|---|
| Habilidad por herramienta, empresa y alcance comercial | sí (`IntelligenceGateway`) | **sí**, por la ruta equivalente (`tenant.ability`, `EnforceCommercialScope`) |
| Herramientas de solo lectura | sí | **sí** (§ 5.1) |
| Auditoría por llamada en `gateway_tool_calls` | sí | **no**: queda el registro de acceso de la API y `last_used_at` del token |
| Las cifras de la respuesta proceden de las herramientas (`NumericGrounding`) | sí, se descarta la respuesta | **no**: la respuesta la escribe el modelo de la persona, fuera de Predictily |
| Saneado de nombres y detector de inyección (`InjectionGuard`) | sí | **no**: solo se indica al modelo que los textos son datos |
| Topes de filas y de tamaño por resultado | 10 filas, 12 000 caracteres | paginación de la API + `PREDICTILY_MCP_MAX_RESULT_CHARS` (20 000) |
| Límite de uso del asistente (10/min, 300/día por empresa) | sí | **no**: aplica `throttle:api` |

Por qué se hizo así: exponer el gateway por una ruta cambia una garantía documentada y probada («ninguna ruta ejecuta
herramientas fuera del asistente»), y en el equipo de trabajo no se podían ejecutar las pruebas del backend (rutas,
OpenAPI, matriz de aislamiento). El servidor MCP no da a nadie más de lo que su token ya obtiene de la API; lo que pierde
son las defensas que el gateway pone **entre los datos y un modelo**. Ver § 9, punto 2.

## 7. Configuración

`mcp/.env.example` y `mcp/README.md` § 2. Todos los valores por defecto son los más reservados: aprobaciones con
confirmación humana, forma de los valores en lugar de filas, herramientas por cliente apagadas, carga de archivos
desactivada.

## 8. Estado por requisito

| Requisito | Estado | Evidencia / nota |
|---|---|---|
| Agente que siembra datos con las credenciales de la persona | **IMPLEMENTADO / VERIFICADO contra API simulada** | § 3, § 4; sin ejecución contra backend real |
| LLM a elección para consultar y presentar los análisis | **IMPLEMENTADO / VERIFICADO contra API simulada** | § 5; cualquier cliente MCP; sin ejecución contra backend real |
| V25-GWY-001 Gateway multi-proveedor | **PARCIAL** (sin cambio en el gateway) | MCP desacopla el proveedor por fuera del gateway; dentro sigue habiendo un solo adaptador |
| V25-SEC-040 / V25-FAC-041 Consentimiento por empresa para enviar datos a un tercero | **DISEÑADO / NO IMPLEMENTADO** | aviso al inicio; MCP lo hace más urgente |
| V25-GWY-016 Defensa ante inyección | **NO CUBIERTO por MCP** | § 6; el gateway la conserva para el asistente |
| V25-GWY-017 Cifras solo de servicios verificables | **PARCIAL** | las herramientas solo leen resultados persistidos; no se verifica la respuesta del modelo |
| Auditoría de accesos del agente | **PARCIAL** | registro de la API por token; sin fila por herramienta |
| Transporte HTTP multiusuario | **IMPLEMENTADO / VERIFICADO** (identidad por petición) | sin TLS propio, sin OAuth |
| Ejecución contra backend y ERP reales | **SIN VERIFICAR** | § 9, punto 1 |

## 9. Qué falta (honesto)

1. **Ejecutar contra un backend real** (demo ficticia primero): `predictily_whoami`, un ciclo de archivo completo con
   `data/demo/*.csv`, un ciclo SQL con la base ficticia `predictily_erp_source_p3` y las 24 herramientas de análisis sobre
   la empresa demo. Es probable que aparezcan diferencias de forma respecto a la OpenAPI (nombres de filtros, estados).
2. **Canal `mcp` en el gateway** (DISEÑADO): `GET /gateway/tools` y `POST /gateway/tools/{tool}` sobre
   `IntelligenceGateway::call()` con `channel: mcp`, catálogo propio en `conversation.gateway.channel_tools`, bandera de
   plataforma apagada por defecto y consentimiento por empresa. Recupera auditoría, saneado y topes para el servidor de
   análisis. Exige actualizar la prueba de rutas del gateway, la OpenAPI, la matriz de aislamiento y las superficies
   comerciales, con PHP disponible.
3. **Consentimiento por empresa** para el envío a un LLM externo (V25-SEC-040) y registro de qué token es «de agente».
4. **Pantalla de tokens** en la SPA (hoy se emiten por la API; `mcp/README.md` § 1).
5. Herramientas no incluidas: cobertura y huecos (escritura), cuarentena (liberar/descartar), Bridge, promociones,
   compromisos y eventos de calendario por API directa, lanzar un pronóstico o un entrenamiento.
6. Sin evaluación con un modelo real (preguntas y respuestas esperadas) ni medición de cuánto contexto consume cada herramienta.
7. Umbrales a aprobar en el piloto: `PREDICTILY_MCP_MAX_RESULT_CHARS`, espera máxima de 300 s, 20 MB por archivo.

## 10. Cómo verificarlo

```bash
cd mcp
python -m venv .venv && .venv/bin/pip install -e ".[dev]"     # Windows: .venv\Scripts\pip
.venv/bin/python -m pytest -q                                  # 107 pruebas; sin red, sin base
.venv/bin/predictily-mcp --version
# Inspector interactivo (requiere Node 18+ y un backend levantado):
PREDICTILY_API_URL=http://127.0.0.1:8000/api/v1 PREDICTILY_API_TOKEN=<token> \
  npx @modelcontextprotocol/inspector .venv/bin/predictily-mcp insights
```

Archivos de prueba: `test_units.py` (configuración, privacidad, salida, perfiles), `test_api.py` (cliente HTTP y errores),
`test_ingest.py` y `test_insights.py` (cliente MCP en memoria → herramienta → API simulada) y `test_http.py` (transporte
HTTP real con uvicorn y arranque por CLI). Además se comprobó a mano el arranque por `stdio` de ambos servidores en Windows
(listado de herramientas y una llamada con texto acentuado de ida y vuelta).
