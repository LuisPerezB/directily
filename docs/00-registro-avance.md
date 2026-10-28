# 00 · Registro de avance

Estado por componente al 2026-10-28. Leyenda: ✅ implementado y probado · 🟡 implementado, pruebas
parciales · ⏳ pendiente · 🔌 interfaz desacoplada (requiere credenciales o servicio externo; NO se
presenta como activa).

## Verificación global

| Verificación | Resultado |
|---|---|
| Prueba de aceptación MVP de extremo a extremo (`e2e/mvp_acceptance.py`, API real + cola + motor) | ✅ 32/32 verificaciones (criterios 1–13 del prompt maestro) |
| Backend PHPUnit sobre PostgreSQL (`cd backend && php artisan test`) | ✅ 155 pruebas, 1 105 aserciones |
| Motor pytest (`cd engine && .venv/bin/python -m pytest -q`) | ✅ 43 pruebas (34 unitarias + 9 de integración con PostgreSQL) |
| Frontend (`npm run type-check && npm run lint && npm run test -- --run && npm run build`) | ✅ 68 pruebas, build 1,3 MB |
| OpenAPI pública unificada (`docs/openapi/predictily-api.yaml`, 76 rutas) | ✅ generada con `infra/scripts/merge_openapi.py` |
| `docker compose config` (base y producción) | ✅ |
| Imágenes Docker backend y motor construidas y arrancadas | ✅ (imagen `web` no reconstruida tras corregir el frontend: ver pendientes) |
| Respaldo y restauración (`infra/scripts/backup.sh`, `restore.sh --verify`) | ✅ probado contra PostgreSQL local |

Resultados medidos en datos demo ficticios (no son promesas de precisión para empresas reales):
WAPE diario por producto-sucursal en backtesting rolling-origin 0,487 (modelo seleccionado) vs 0,645
(Seasonal Naive); cobertura empírica del intervalo 80 % = 0,798. Pronóstico completo de la empresa demo
A (183 series, 90 días): ~30 s en 4 vCPU sin GPU; empresa B (80 series): ~13 s.

## Estado por componente

| Componente | Estado | Notas |
|---|---|---|
| Esquema de datos (50 tablas, migraciones versionadas) | ✅ | FKs compuestas `(tenant_id, x_id)`; índices justificados en docs/03 |
| Multi-tenancy (contexto, scope fail-closed, middleware, jobs, FKs) | ✅ | Pruebas de aislamiento en core, analytics, motor y E2E |
| Autenticación (Sanctum SPA + tokens por empresa), roles y abilities | ✅ | Rate limit de login, auditoría |
| Empresas, sucursales, usuarios, configuración, consentimiento | ✅ | |
| Planes SaaS, límites (402) y consumo | ✅ | Asignación manual por superadmin; sin pasarela de pagos |
| Importación CSV/XLSX (detección, mapeo, validación, calidad, idempotencia) | ✅ | 99 334 líneas en ~41 s; reimportación sin duplicados |
| Ingesta REST (`/sales/batch`) | ✅ | Misma lógica que el importador |
| Conectores ERP/POS específicos (Contífico, ODBC) | 🔌 | Declarados `requires_credentials` / `planned` |
| Privacidad LOPDP (logs sin PII, HMAC de cédulas, exportación, eliminación diferida, retención) | ✅ | Revisión legal pendiente (docs/07) |
| Motor: perfilado, censura por quiebre, feriados EC, características | ✅ | |
| Motor: Nivel A (StatsForecast) y Nivel B (LightGBM global por empresa) | ✅ | Selección por clase de demanda contra baseline, con respaldo |
| Motor: backtesting rolling-origin, métricas, intervalos conformales, reconciliación | ✅ | |
| Motor: recomendaciones de inventario y alertas de quiebre | ✅ | Probabilidad empírica con regla de Laplace (nunca 0 %/100 %) |
| Motor: evaluación vs reales y detección de drift | ✅ | |
| Motor: Nivel C (sectorial), D (personalizado), E (TimesFM) | ⏳ / 🔌 | Diseño en registro de modelos; TimesFM desactivado hasta verificar licencia |
| Indicadores macroeconómicos (INEC, BCE...) | 🔌 | Esquema y consultas as-of probadas; sin conectores |
| Orquestación por colas, programación, reintentos | ✅ | |
| API analítica: dashboard, pronósticos, precisión, inventario, alertas | ✅ | |
| Simulador de escenarios (determinista, con supuestos explícitos) | ✅ | Elasticidad/causalidad: fase posterior |
| Exportaciones CSV y reporte ejecutivo | ✅ | |
| Frontend: 13 pantallas, i18n, modo oscuro, roles | ✅ | Prueba de humo con Playwright en 16 rutas |
| Documentación (01–08, manual, entrenamiento, pendientes) | ✅ | |
| Docker Compose, Dockerfiles, nginx, Caddy TLS, respaldos | ✅ / 🟡 | Producción con dominio real no probada |

## Correcciones de la integración final (orquestador)

- Límite de subida de PHP (8 MB por defecto) bloqueaba el histórico de 19 MB en instalación nativa:
  documentado y respuesta 413 en español (`payload_too_large`). La imagen Docker ya usaba 25 MB.
- Workers de cola con `--memory` explícito (el valor por defecto de 128 MB detenía el worker tras
  importar 133 mil filas de inventario).
- Rankings de productos: cambios porcentuales sobre bases < 10 unidades se marcan `low_base`, se
  ordenan al final y no aparecen en "productos que suben/bajan" del dashboard.
- Intervalo 80 % del total del horizonte por producto tomado de las filas `granularity='horizon'`.
- `ANALYZE` de las tablas afectadas tras importaciones grandes (evita planes lentos en el motor).
- Probabilidad de quiebre empírica con regla de sucesión de Laplace.
- Prueba de aislamiento que dependía de la hora UTC (fecha local de la empresa).
- Especificaciones OpenAPI core/analytics unificadas (esquema de cookie de sesión alineado).

## Notas de los agentes
(Cada agente agrega aquí decisiones o cambios de contrato relevantes.)
- [backend-analytics] Interfaces disponibles para backend-core: `App\Jobs\Forecasting\RefreshReadiness(int $tenantId, ?int $trainingJobId = null)` y `App\Services\Forecasting\ForecastRunService::request(Tenant, ?User, int $horizonDays = 90, ?string $cutoffDate = null, string $triggeredBy = 'user'): ForecastRun` (lanza `ForecastRunConflictException` → 409 si hay un run activo). Registrado `App\Providers\AnalyticsServiceProvider` en bootstrap/providers.php (binding de `EngineClient`).
- [backend-analytics] **Requisito de cola**: `RunForecastPipeline` tiene timeout `ENGINE_TIMEOUT + 60` s. La conexión Redis de colas debe usar `retry_after` mayor (p. ej. `REDIS_QUEUE_RETRY_AFTER=1020` en `.env`/`.env.example`), si no, Redis reentrega el Job mientras aún corre. El motor responde 409 a ejecuciones concurrentes del mismo run y es idempotente para runs completados.
- [engine] 2026-10-27: **Datos demo listos** en `data/demo/` (ficticios, semilla 42, reproducibles). `ferreteria_andina`: 3 sucursales, 62 productos, 99 334 líneas de venta (2024-10-27→2026-10-26, 18.9 MB). `distribuidora_costa`: 2 sucursales, 40 productos, 42 035 líneas (2025-07-27→2026-10-26). Valores en archivos de ventas: `document_type` = `factura` | `nota de crédito` (cantidades positivas); `status` vacío (= completada) o `anulada`; `channel` canónico (store, wholesale, field_sales); `customer_code` 9999999999999 = consumidor final (código genérico SRI). Extras sin campos en el contrato: `{slug}_suppliers.csv` (`code,name,country,default_lead_time_days`) y `{slug}_promotions.csv` (`name,promo_type,discount_pct,starts_on,ends_on,branch_code,product_sku,category`; incluye una promoción futura). `*_sales_with_errors.csv` trae una columna extra `nota_calidad` (no mapear). Regenerar: `cd engine && .venv/bin/python -m predictily_engine.cli generate-demo --out ../data/demo --seed 42`.
- [backend-analytics] **Bug para backend-core** en `App\Services\Plans\PlanLimitService::limits()`: `array_replace_recursive` fusiona listas por índice, así que `horizons: [7, 30]` del plan sobre la base `[7, 30, 90]` del config produce `[7, 30, 90]` (el plan Básico seguiría permitiendo 90 días). Corrección sugerida: reemplazar `horizons` completo (p. ej. `if (isset($plan->limits['horizons'])) $base['horizons'] = $plan->limits['horizons'];` y lo mismo con overrides).
- [backend-analytics] Horizonte no incluido en el plan → **402** `plan_limit_exceeded` (vía `PlanLimitService::assertHorizon`); horizonte no soportado (≠ 7/30/90) → 422. Cada solicitud registra consumo `forecast_runs` en `tenant_usage`.
- [backend-analytics] Para backend-core / infra: (1) falta `lang/es/validation.php` (con `APP_LOCALE=es` los errores salen como "validation.required"); (2) añadir `REDIS_QUEUE_RETRY_AFTER=1020` y `ENGINE_*` a `.env.example`; (3) las rutas con contexto de empresa no deben usar route-model binding implícito: `SubstituteBindings` corre antes del middleware `tenant` y el global scope "fail-closed" lanzaría 500 — las rutas analíticas usan ids explícitos.
- [backend-analytics] **Propuesta retrocompatible al motor** (opcional): filas `forecast_values` con `granularity='horizon'`, `period_days=H` (7/30/90), `target_date=cutoff+1` y `lo_80/hi_80` del TOTAL del horizonte por nodo. Si existen, Laravel las usa en `dashboard.kpis.forecast_units_lo_80/hi_80`; si no, esos campos y `lo_80/hi_80` de `/forecasts/products` quedan `null` (sumar cuantiles diarios no da un intervalo válido).
- [backend-analytics] `GET /forecasts/series` agrega `stockout_share` (proporción de series sin stock) en niveles agregados y `meta.notes`; `model_evaluations.level_key` se acepta como `p45`/`b3-p45` o `product:45`/`series:3:45`; nivel `overall` con `is_selected=true` (si hay varios, se prefiere `model_family` compuesto `selection|auto`) y `is_baseline=true` (se prefiere `seasonal_naive`).
- [backend-analytics] Estado: 68 pruebas (Feature/Unit Analytics) en verde contra PostgreSQL (`predictily_test_b2`). OpenAPI en `docs/openapi/analytics.yaml`. Verificación E2E con el motor pendiente: al momento `engine/predictily_engine/api/` no expone rutas.
- [backend-core] 2026-10-27: **Núcleo implementado y probado** (auth Sanctum SPA+Bearer, empresas, miembros/roles, catálogos, ventas, importación CSV/XLSX, ingesta REST, conectores, privacidad, planes, demo). 85 pruebas core (`tests/Feature/Core`, `tests/Unit/Core`) en verde; suite completa 153 pruebas. OpenAPI en `docs/openapi/core.yaml` (validado con openapi-spec-validator).
- [backend-core] **Cambios de contrato (retrocompatibles)**: (1) `entity_type` admite además `branches`, `suppliers` y `promotions` (para cargar `data/demo/{slug}_*.csv` con el mismo importador). (2) `promotions.discount_pct` es **fracción 0–1** (0.15 = 15 %), igual que lee el motor; el importador acepta también `15` o `15%`. (3) `POST /auth/login` y `/auth/register` sin sesión SPA devuelven además `token` Bearer. (4) Rutas extra: `PUT /auth/password`, `POST /tenant/cancel-deletion`, `DELETE /data-sources/{id}/tokens/{tokenId}`, `POST /data-sources/{id}/test`. (5) Nuevo código de advertencia `unknown_document_type`, `unknown_supplier`, `negative_stock`, `invalid_tax_id`; `skip_invalid_rows` omite el **documento completo** si alguna de sus filas es inválida.
- [backend-core] **Nueva tabla** `tenant_exports` (migración `2026_01_01_000900`) para la exportación completa de datos (LOPDP).
- [backend-core] `SetTenantContext` ahora restaura el tenant previo (antes lo borraba): con `QUEUE_CONNECTION=sync` un Job dentro de una petición dejaba la petición sin empresa. `ResolveTenant` va antes de `SubstituteBindings` en la prioridad de middleware, por eso el route model binding implícito es seguro (las pruebas de IDs de otra empresa devuelven 404).
- [backend-core] Atención: en `response()->streamDownload()` el callback corre DESPUÉS de los middleware, con el TenantContext ya limpio → usar `TenantContext::runAs()` o filtrar por `tenant_id` explícito (corregido en errores de importación; analytics ya usa `runAs`).
- [backend-core] Corregido el bug de `PlanLimitService` reportado por analytics (listas como `horizons` ahora se reemplazan; mapas como `features` se combinan) con prueba. Agregados `lang/es/*.php` + `lang/es.json`, `APP_LOCALE=es`, `REDIS_QUEUE_RETRY_AFTER=1020` en `.env`/`.env.example`, `config/cors.php`.
- [backend-core] Demo: `php artisan predictily:demo [--fresh] [--forecast] [--only=slug]` importa ferretería (99 334 líneas + 133 302 existencias) en ~60 s; reimportar no duplica (44 269 documentos "sin cambios"). Usuarios `demo.ferreteria@predictily.test` / `demo.distribuidora@predictily.test`, contraseña solo-demo `Predictily2026`.
- [engine] 2026-10-28: **Motor listo para E2E.** Arranque: `cd engine && .venv/bin/uvicorn predictily_engine.api.main:app --host 0.0.0.0 --port 8100` (token `ENGINE_API_TOKEN`, ver `engine/.env.example`). Endpoints §4 implementados: `/health`, `/v1/capabilities`, `/v1/forecast-runs/{id}/execute`, `/v1/tenants/{id}/readiness`, `/v1/tenants/{id}/evaluate-actuals`. OpenAPI en `docs/openapi/engine-api.json`. Pruebas: 43 en verde (34 unitarias + 9 integración contra `predictily_engine_test`). Empresa demo A completa: 28 s de punta a punta vía HTTP.
- [engine] Alineado con backend-analytics: filas `forecast_values.granularity='horizon'` para total/branch/category/branch_category/product con `period_days = horizon_step = H` (7/30/90) e intervalos del total del horizonte. **Desviación**: `target_date = corte + H` (último día del horizonte), no `corte + 1`, porque la clave única `(forecast_run_id, node_key, granularity, target_date)` impediría tres filas con la misma fecha; Laravel filtra por `period_days`, así que no le afecta. Evaluaciones: fila compuesta `model_family='selection'` con `is_selected=true` en niveles overall/horizon/demand_class/branch/category/product (`p45`)/series (`b3-p45`)/total; baseline `seasonal_naive` con `is_baseline=true`; por clase, el ganador lleva `is_selected=true`. Realizadas (`evaluation_type='realized'`): `selection` en overall/horizon/total.
- [engine] Recomendación para backend-core: ejecutar `ANALYZE sales, sale_items, inventory_snapshots` al terminar `CommitImport` de archivos grandes (con estadísticas desactualizadas un run tardó 386 s una vez; el motor ahora usa `SET LOCAL enable_nestloop = off` en sus lecturas como protección).
