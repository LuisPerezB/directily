# P5b · Plataforma transversal (etapa 2)

Paquete de plataforma que no pertenece a un core: **asistente conversacional opcional** (ADR-V2-12), **exportación LOPDP** ampliada, **contrato OpenAPI** de
la etapa 2 y **seguridad** (`docs/07` §13–15). Propietario de los archivos: `backend/app/Services/Conversation/**`, `backend/app/Http/**/Conversation/**`,
`backend/config/conversation.php`, `backend/app/Services/Privacy/TenantDataExporter.php`, `docs/openapi/**`, `infra/scripts/merge_openapi.py`.

## 1. Estado

| Entregable | Estado | Evidencia |
|---|---|---|
| Servicio conversacional opcional (proveedor configurable, Ollama Cloud, herramientas de lista blanca, límites, auditoría) | Implementado y probado **con proveedor falso (sin red)**. No se probó contra Ollama Cloud real: no hay clave en el entorno | §2 y §6 |
| Autonomía de los cuatro cores sin proveedor (E2-24, parte autonomía) | Implementado y probado | `CoresWorkWithoutAssistantTest`, `CoresDoNotDependOnAssistantTest` |
| Exportación LOPDP con las tablas de la etapa 2 (incluidas las de P3 y P4) | Implementado y probado | `TenantDataExporterStage2Test` |
| OpenAPI 3.1 de la etapa 2 (`v2.yaml`) + fusión validada con `core` y `analytics` | Implementado: 83 operaciones nuevas; **182 operaciones documentadas = 182 rutas reales**, 0 exclusiones | §4, `OpenApiRoutesTest` |
| Sección de seguridad (conectores SQL, capa conversacional, retención) | Implementado | `docs/07` §13–15 |

## 2. Asistente conversacional opcional

### 2.1 Arquitectura

```
Vue (P5a) ──► POST /api/v1/assistant/messages ──► AssistantController ──► AssistantService
                                                                           │  ConversationManager.provider()  ──► OllamaCloudProvider | NullProvider | (otros: extend())
                                                                           │  AssistantLimiter (tasa por usuario, tope diario por empresa)
                                                                           │  bucle ≤ 3 rondas: proveedor.chat(mensajes, herramientas permitidas)
                                                                           ▼
                                                                    ToolRegistry (lista blanca) ── ability + plan + esquema cerrado ──► servicios internos de resultados
                                                                           │                                                          (DashboardService, ProductForecastQuery, AccuracyService,
                                                                           │                                                           InventoryQuery, CustomerPrediction, SupplyPlan) en la empresa activa
                                                                           ▼
                                                           AssistantReply { answer, figures[tool, arguments, status, data, envelope], grounding, usage }
```

- El **proveedor** (`Contracts/ConversationProvider`) solo recibe mensajes y devuelve texto y/o llamadas a herramientas. No toca la base ni el motor.
  `OllamaCloudProvider` usa la API nativa `POST {CONVERSATION_BASE_URL}/api/chat` con `Authorization: Bearer <clave>`, `stream=false`, sin reintentos
  y con tiempos acotados. `NullProvider` es el proveedor apagado. Otro proveedor se añade con `ConversationManager::extend('nombre', fn () => …)`.
- **Sin clave** (`CONVERSATION_API_KEY` vacía) el proveedor no está disponible y **no se hace ninguna llamada de red**.
- Los cores no importan nada de `App\Services\Conversation`; la dependencia es solo en sentido contrario (el asistente consume resultados).

### 2.2 API

| Ruta | Habilidad | Detalle |
|---|---|---|
| `GET /api/v1/assistant/status` | `data.view` | `{enabled, reason, provider, model, tools[{name, available_to_you}], limits, usage_today, notice}`. `reason` ∈ `missing_api_key`, `disabled_by_config`, `provider_disabled`, `unknown_provider`, `disabled_for_plan`, `invalid_base_url`, `missing_model`. Nunca devuelve la clave ni el endpoint |
| `POST /api/v1/assistant/messages` | `data.view` | Body `{message (≤ 1000), history? [{role: user\|assistant, content}] (≤ 6)}` → `{data: AssistantReply}`. Errores: 503 `assistant_disabled` (+`reason`), 402 plan sin asistente, 422, 429 `assistant_rate_limited` / `assistant_quota_exceeded` (+`Retry-After`), 502 `assistant_provider_error` |

`AssistantReply.status`:

| `status` | Cuándo | `answer` |
|---|---|---|
| `answered` | Hubo al menos una herramienta con resultado (`ok`/`no_data`) y el texto del modelo no contiene cifras ajenas a ellas | Texto del modelo (PII enmascarada, ≤ 3000 caracteres) |
| `no_applicable_tool` | El modelo no llamó a ninguna herramienta válida (o llamó a una no listada) | Respuesta **fija**: «No puedo responder esa pregunta con los resultados ya calculados…» + lo que sí puede consultar. Nunca texto libre del modelo |
| `tool_denied` | Las herramientas pedidas fallaron por habilidad (`forbidden`) o plan (`plan_limit`) | Mensaje fijo con el motivo; sin datos |
| `ungrounded_rejected` | El texto citó cifras que no proceden de las herramientas | Mensaje fijo; las `figures` se conservan y `grounding.ungrounded_numbers` lista las cifras descartadas |
| `no_answer` | Hubo resultados pero el modelo no produjo texto | Mensaje fijo |

`figures[]` contiene, **tal como lo devolvió la herramienta**, `status` (`ok`, `no_data`, `forbidden`, `plan_limit`, `invalid_arguments`, `not_found`,
`tool_not_allowed`, `error`), `data` (las cifras) y `envelope` (procedencia, ADR-V2-03: corrida, `as_of`, horizonte, `result_type`, `quality_status`,
supuestos y limitaciones). La interfaz debe mostrar las cifras desde `figures`, nunca desde el texto.

### 2.3 Herramientas de lista blanca (solo lectura)

| Herramienta | Servicio interno (equivale a) | Habilidad | Plan / horizonte | Envoltura |
|---|---|---|---|---|
| `dashboard_summary` | `DashboardService` (`GET /dashboard/summary`) | `forecast.view` | horizonte ∈ plan | `DemandEnvelope` |
| `product_forecast` | `ProductForecastQuery` (`GET /forecasts/products`) | `forecast.view` | horizonte ∈ plan | `DemandEnvelope` |
| `forecast_accuracy` | `AccuracyService` (`GET /accuracy/summary`) | `forecast.view` | — | `DemandEnvelope`, `evaluation` |
| `inventory_recommendations` | `InventoryQuery` (`GET /inventory/recommendations`) | `forecast.view` | — | `DemandEnvelope` |
| `customer_predictions` | `CustomerPrediction` (`GET /customer/predictions`) | `forecast.view` | horizonte ∈ plan ∩ {7, 30, 90}; apagable con `CONVERSATION_CUSTOMER_TOOLS=false` | `CustomerEnvelope` |
| `supply_plan` | `SupplyRunService::latestPlan` + `SupplyPlan` (`GET /supply/plans/{id}`) | `forecast.view` | — | `ResultEnvelope` (`optimized_plan`) |

Reglas comunes (`ToolRegistry`): herramienta no registrada o deshabilitada → `tool_not_allowed`; habilidad exigida por la ruta equivalente (rol y, con token
Bearer real, también la habilidad del token; mismo criterio que `EnsureTenantAbility`); argumentos validados con esquema cerrado — **cualquier clave fuera del
esquema, incluido `tenant_id`, se rechaza** (`invalid_arguments`) y las referencias (`branch_id`, `product_id`) se validan contra la empresa activa
(`TenantRule`); ejecución dentro de `TenantContext::runAs(tenant)`; un horizonte fuera del plan responde `plan_limit` sin datos. Ninguna herramienta escribe ni
dispara cálculos. `supply_plan` y `inventory_recommendations` son **sugerencias**: Predictily no envía pedidos ni compromete fondos.

### 2.4 Garantías y cómo se prueban

| Garantía | Mecanismo | Prueba |
|---|---|---|
| El LLM no calcula | Las cifras viajan en `figures`; el texto se verifica con `NumericGrounding` (acepta valores de las herramientas con su redondeo, porcentajes de razones ≤ 1, fechas, tamaños de lista; rechaza invenciones y sumas del modelo) | `NumericGroundingTest` (18), `test_text_with_invented_or_derived_numbers_is_discarded…`, `test_dates_percentages_and_list_markers…` |
| Cifras = las de la API de resultados | `figures[0].data.kpis` idéntico a `GET /dashboard/summary`; valor esperado calculado de forma independiente desde el fixture | `test_dashboard_tool_is_called_and_figures_keep_the_tool_provenance` |
| Herramienta correcta | Seis herramientas con datos de demanda, precisión, inventario, compradores y plan | `test_product_accuracy_inventory_and_other_tools…`, `test_customer_predictions_tool…`, `test_supply_plan_tool…` |
| Sin herramienta aplicable no hay texto libre | `no_applicable_tool` | `test_question_without_applicable_tool_gets_the_fixed_refusal…` |
| Lista blanca | `tool_not_allowed`; `run_sql` y similares no se ejecutan | `test_tools_outside_the_whitelist_are_rejected_and_never_executed` |
| `tenant_id` y claves ajenas rechazadas | Validación de esquema cerrado | `test_unknown_arguments_including_tenant_id_are_rejected` |
| Aislamiento entre empresas | `TenantContext` + `BelongsToTenant` + `TenantRule`; la empresa B tiene demanda, producto, comprador y plan propios que A nunca ve | `test_a_company_never_sees_another_companys_results` |
| Habilidades | Token con solo `data.view` usa el asistente pero ninguna herramienta (`forbidden`) y la API directa también se lo niega | `test_tools_require_the_ability_of_the_equivalent_route` |
| Plan | Plan Básico (7/14/30): 90 días → `plan_limit` sin datos; 30 días responde | `test_plan_horizons_are_respected…` |
| Desactivado sin clave / por configuración / por plan, sin red | 503 `assistant_disabled`; 402 por plan; `Http::assertNothingSent()` | `test_service_is_disabled_without_api_key_and_never_calls_the_network`, `…switched_off_by_configuration…`, `test_plan_can_exclude_the_assistant` |
| Los cuatro cores funcionan sin proveedor (E2-24) | Resultados y solicitudes de los cuatro cores con el asistente apagado y sin salidas a la red | `CoresWorkWithoutAssistantTest` |
| Ningún core depende de la capa conversacional | Escaneo estático de `app/`, `routes/`, `database/`, `config/`, `bootstrap/` y del motor | `CoresDoNotDependOnAssistantTest` |
| Tamaño, tasa, tope diario | 422; 429 por usuario (otro usuario y otra empresa no se ven afectados); 429 diario por empresa | `test_message_size_is_limited`, `test_rate_per_user_and_daily_quota_per_company_are_enforced` |
| Sin datos personales al proveedor ni en logs | `PiiMasker` en mensaje, historial y resultados; no viajan nombre de la empresa ni correo del usuario; logs y auditoría solo con metadatos | `test_personal_data_is_masked_before_reaching_the_provider…` |
| Fallo del proveedor aislado | 502 sin cuerpo ni clave; los resultados siguen disponibles | `test_provider_failure_returns_502…`, `OllamaCloudProviderTest::test_errors_never_leak…`, `…timeouts…` |
| Formato de la solicitud a Ollama Cloud | URL, Bearer, `tools`, mensajes `tool`, argumentos vacíos como `{}` | `OllamaCloudProviderTest::test_request_shape…` |

### 2.5 Configuración (copiar al `.env` del servidor; sin valores reales en el repositorio)

```
CONVERSATION_ENABLED=true
CONVERSATION_PROVIDER=ollama_cloud        # ollama_cloud | none
CONVERSATION_BASE_URL=https://ollama.com
CONVERSATION_API_KEY=                      # vacío = servicio desactivado
CONVERSATION_MODEL=gpt-oss:120b            # verificar el catálogo vigente de la nube con soporte de herramientas
CONVERSATION_TIMEOUT_SECONDS=30
CONVERSATION_DEADLINE_SECONDS=45
CONVERSATION_MAX_MESSAGE_CHARS=1000
CONVERSATION_RATE_PER_MINUTE=10
CONVERSATION_DAILY_LIMIT=300
CONVERSATION_CUSTOMER_TOOLS=true
```

### 2.6 Limitaciones conocidas

- Nunca se probó contra Ollama Cloud real (sin clave en el entorno): el formato de solicitud/respuesta (`/api/chat`, `tools`, `tool_calls`, mensajes `tool`) se
  comprobó con HTTP simulado según la API nativa documentada; el modelo por defecto debe confirmarse en el catálogo vigente.
- `NumericGrounding` es una comprobación conservadora, no formal: no detecta cifras escritas con letras ni cifras ajenas que coincidan por casualidad con una
  cifra de las herramientas; puede rechazar un texto correcto con formatos numéricos inusuales (en ese caso las `figures` siguen disponibles).
- La tasa por minuto usa el limitador de Laravel (no atómico entre `tooManyAttempts` y `hit`); el tope diario usa un contador de caché por empresa y día.
- No hay desactivación por empresa desde la interfaz (haría falta ampliar el esquema de `tenants.settings`); hoy se hace por plan (`plan_overrides.features.assistant=false`).

## 3. Exportación LOPDP

`TenantDataExporter::TABLES` incluye ahora, con `tenant_id = empresa`: `core_runs`, `data_snapshots`, `data_coverage`, `warehouses`, `unit_conversions`,
`commercial_commitments` (+ `_items`, `_revisions`), `calendar_events` (solo los de la empresa; el calendario público de `tenant_id` NULL no se exporta),
`data_sources` (**sin** `config`, `credentials_encrypted` ni `last_error`), `source_mappings`, `source_syncs` (sin `error_message`), `customer_predictions`,
`customer_product_rankings`, `customer_activity`, `customer_reconciliations` y las de P4 (`exchange_rates`, `supplier_offers`, `location_capacities`,
`purchase_budgets`, `production_resources`, `production_recipes`, `production_recipe_lines`, `supply_plans`, `supply_plan_lines`, `supply_plan_shortfalls`,
`cost_forecasts`, `scenario_evaluations`). `NOT_EXPORTED` clasifica el resto de tablas con `tenant_id` y su motivo.

Pruebas (`tests/Feature/Privacy/TenantDataExporterStage2Test.php`): el ZIP de la empresa A contiene todas las tablas nuevas con exactamente sus filas
(resumen y CSV), nada de la empresa B (marcador), ninguna credencial (`SECRETO-CRED-A`, host/usuario de conexión, errores internos) ni el calendario público;
ninguna cabecera de CSV contiene columnas de credenciales; la empresa B recibe solo lo suyo; y **toda tabla con `tenant_id` debe estar clasificada**
(exportada o excluida con motivo), de modo que una tabla futura sin decidir rompe la prueba.

## 4. OpenAPI

| Archivo | Contenido |
|---|---|
| `docs/openapi/core.yaml` | Etapa 1 · núcleo (sin cambios) |
| `docs/openapi/analytics.yaml` | Etapa 1 · analítica (corregida una descripción con comas en un mapa de flujo YAML que producía un documento inválido) |
| `docs/openapi/v2.yaml` | **Nuevo.** Etapa 2: Core de Datos (25 operaciones), plataforma (3), privacidad/catálogos (2 antes sin documentar), Comportamiento de compra (8), Abastecimiento (43) y asistente (2) = **83 operaciones**, 47 esquemas. Cada operación indica su habilidad (`x-ability`) |
| `docs/openapi/predictily-api.yaml` | Generado por `merge_openapi.py`: 131 rutas, **182 operaciones**, 107 componentes |

`infra/scripts/merge_openapi.py` (ampliado): une las tres partes **por operación** (conflicto si una misma ruta+método o un mismo componente difiere),
detecta rutas equivalentes salvo el nombre del parámetro, comprueba que toda `$ref` interna se resuelva, valida con `openapi-spec-validator` y contrasta con
las rutas reales de Laravel. Uso:

```
python3 -m venv .venv-openapi && .venv-openapi/bin/pip install pyyaml openapi-spec-validator
(cd backend && php artisan route:list --path=api/v1 --json > /tmp/routes.json)
.venv-openapi/bin/python infra/scripts/merge_openapi.py --routes /tmp/routes.json --strict          # genera, valida y contrasta
.venv-openapi/bin/python infra/scripts/merge_openapi.py --routes /tmp/routes.json --strict --check  # CI: no escribe; falla si no está al día
```

Resultado de la última ejecución: `131 rutas, 182 operaciones, 107 componentes · rutas reales: 182 · documentadas: 182 · excluidas (justificadas): 0`.
`ROUTE_EXCLUSIONS` está vacío; añadir una exclusión exige escribir el motivo. La prueba `OpenApiRoutesTest` (PHP, sin dependencias de Python) hace el mismo
contraste de rutas dentro de `php artisan test`.

Verificaciones adicionales hechas al redactar `v2.yaml` (no quedan como prueba permanente): las **83** habilidades documentadas coinciden con el middleware
`tenant.ability` de la ruta real; 67 de 68 respuestas reales de las rutas nuevas (generadas con datos de prueba) validan contra su esquema (la restante fue un
artefacto del volcado). Defectos reales de serialización que se documentaron tal cual en el esquema (ver §7): `model_versions`/`model_version` y `options` pueden
salir como lista cuando las claves son horizontes numéricos o el objeto está vacío.

## 5. Seguridad

Ver `docs/07-seguridad-multitenant.md` §13 (conectores SQL: cuenta de solo lectura, `PREDICTILY_SQL_ALLOWED_HOSTS` en SaaS público, redes privadas/VPN),
§14 (capa conversacional: qué se envía al proveedor, configuración y desactivación, límites, auditoría) y §15 (retención de artefactos).

## 6. Pruebas y comandos

Entorno: PHP 8.3.6, PostgreSQL 16, base `predictily_test_p5`; proveedor falso sin red.

```
cd backend && DB_DATABASE=predictily_test_p5 php artisan test tests/Unit/Conversation tests/Feature/Conversation   # 50 pruebas (28 unitarias + 22 de funcionalidad)
cd backend && DB_DATABASE=predictily_test_p5 php artisan test tests/Feature/Privacy tests/Feature/OpenApi         # 6 pruebas
cd backend && DB_DATABASE=predictily_test_p5 php artisan test                                                      # suite completa
cd backend && ./vendor/bin/pint --test <archivos propios>                                                          # estilo
```

Resultados de la última ejecución (31-oct-2026): conversación `50 pruebas, 50 aprobadas, 292 aserciones` (9,6 s); privacidad + OpenAPI `6/6, 5773 aserciones`
(2,7 s); **suite completa del backend `412 pruebas, 410 aprobadas, 2 omitidas, 9114 aserciones` (49,2 s)** — las 2 omitidas son preexistentes de P3 (sin servidor
MySQL/SQL Server de pruebas); Pint sin hallazgos en los archivos del paquete; `merge_openapi.py --routes … --strict --check` sin errores. No se ejecutaron
las suites del motor ni del frontend (fuera del alcance de este paquete).

## 7. Hallazgos y pendientes (para el arquitecto)

1. **Serialización de mapas con claves numéricas (P2/P4, no corregido aquí).** `JsonResource` renumera como lista un arreglo cuyas claves son todas numéricas:
   `CustomerRunResource` (`model_versions`) y `ResultEnvelope::fromCoreRun` (`model_version`) pierden las claves de horizonte `"7"/"30"/"90"`; `SupplyRunResource.options`
   vacío sale `[]`. `DemandEnvelope` ya lo evita con `(object)`. Corrección sugerida: convertir esos mapas con `(object)` (y actualizar `v2.yaml`, donde hoy figura `object|array`).
2. **Retención de artefactos sin implementar** (`docs/07` §15): no hay limpieza de modelos `candidate/failed`, ni de ejecuciones antiguas, ni borrado de
   `artifacts/{tenant_id}` del volumen del motor al purgar una empresa.
3. **Exportación**: tablas de etapa 1 todavía fuera (`alerts`, `scenarios`, `purchase_orders`, `purchase_order_items`, `stock_movements`, `external_indicators`),
   marcadas `PENDIENTE` en `TenantDataExporter::NOT_EXPORTED`.
4. **Variables `CONVERSATION_*`** no están en `backend/.env.example` (fuera de mi propiedad): copiar el bloque de §2.5.
5. **Interfaz (P5a)**: consumir `GET /assistant/status` para ocultar el panel si `enabled=false` y mostrar las cifras desde `figures`, no desde `answer`.
6. Validación contra Ollama Cloud real y elección del modelo: pendiente de clave y de EIPD/contrato con el proveedor.
