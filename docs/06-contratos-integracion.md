# 06 · Contratos de integración

Este documento es **el contrato** entre los componentes de Predictily. Si un componente
necesita cambiarlo, debe actualizar este archivo y avisar en `docs/00-registro-avance.md`.

- **Esquema de datos**: la fuente de verdad son las migraciones de Laravel en
  `backend/database/migrations/` (descritas en `docs/03-modelo-datos.md`).
- **API pública** (`/api/v1`, Laravel): consumida por el frontend Vue y por integraciones
  REST de clientes. Especificación OpenAPI: `docs/openapi/predictily-api.yaml`.
- **API interna del motor** (FastAPI, Python): solo accesible desde la red interna,
  invocada por los Jobs de Laravel. OpenAPI autogenerada en `/openapi.json` y exportada
  a `docs/openapi/engine-api.json`.

---

## 1. Puertos y variables de entorno

| Servicio | Puerto dev | Notas |
|---|---|---|
| PostgreSQL 16 | 5432 | BD `predictily` (usuario `predictily` / `secret` en desarrollo) |
| Redis 7 | 6379 | colas, caché, sesiones |
| Backend Laravel | 8000 | `php artisan serve` en dev; php-fpm + nginx en docker |
| Motor Python | 8100 | `uvicorn predictily_engine.api.main:app --port 8100` |
| Frontend Vite | 5173 | proxy de `/api` y `/sanctum` hacia :8000 |
| Nginx (docker) | 8080 | sirve SPA y hace proxy de `/api`, `/sanctum` al backend |

Backend (`backend/.env`): variables estándar de Laravel más
`ENGINE_URL` (http://127.0.0.1:8100), `ENGINE_API_TOKEN`, `ENGINE_TIMEOUT` (segundos, 900),
`FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`.

Motor (`engine/.env`): `ENGINE_DATABASE_URL`
(`postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily`), `ENGINE_API_TOKEN`,
`ENGINE_ARTIFACTS_DIR` (`./storage/artifacts`), `ENGINE_RANDOM_SEED` (42),
`ENGINE_LOG_LEVEL` (INFO), `ENGINE_MAX_SERIES_LGBM` (límite de seguridad), `ENGINE_N_JOBS`.

Bases de datos de prueba: `predictily_test` (backend-core), `predictily_test_b2`
(backend-analytics), `predictily_engine_test` (motor).

---

## 2. Formato de importación (CSV/Excel) — campos canónicos

El asistente detecta columnas, sugiere mapeo por sinónimos (español/inglés) y el usuario
confirma. Los nombres de la izquierda son los **campos Predictily** (claves del mapeo).

### 2.1 `entity_type = sales` (una fila por línea de documento)

| Campo | Req. | Tipo | Sinónimos típicos | Reglas |
|---|---|---|---|---|
| `document_number` | sí | texto | numero_factura, nro_documento, factura, comprobante | clave de idempotencia junto a `document_type` |
| `document_type` | no | texto | tipo_documento, tipo | factura→invoice, nota de venta→sales_note, nota de crédito / devolución→credit_note; por defecto invoice |
| `status` | no | texto | estado, anulada | anulada/anulado/void/cancelada→voided; por defecto completed |
| `issued_at` | sí | fecha o fecha-hora | fecha, fecha_emision, fecha_venta | formatos: Y-m-d, d/m/Y, d-m-Y, Y-m-d H:i:s, serial Excel; `sale_date` se calcula en la zona horaria de la empresa |
| `branch_code` | cond. | texto | sucursal, establecimiento, local, bodega | requerido si hay >1 sucursal y no se define `options.default_branch_code` |
| `product_sku` | sí | texto | codigo, sku, codigo_producto, item | |
| `product_name` | no | texto | producto, descripcion, nombre | usado si se crea el producto |
| `category` | no | texto | categoria, linea, familia, grupo | |
| `quantity` | sí | número | cantidad, cant, unidades | negativa ⇒ línea de devolución (warning) |
| `unit_price` | sí | número | precio, precio_unitario, pvp | precio ORIGINAL antes de descuento |
| `discount` | no | número | descuento, dscto | monto; si termina en `%` se interpreta porcentaje |
| `tax` | no | número | iva, impuesto | monto |
| `net_amount` | no | número | subtotal, valor_neto, total_linea | si falta: qty*price − discount; si viene y difiere >1% ⇒ warning |
| `unit_cost` | no | número | costo, costo_unitario | costo histórico |
| `customer_code` | no | texto | cliente, codigo_cliente, ruc_cliente | **nunca** se guarda una cédula en claro: si parece cédula/RUC se guarda HMAC |
| `customer_segment` | no | texto | segmento, tipo_cliente | |
| `customer_province` | no | texto | provincia | |
| `channel` | no | texto | canal | local/tienda→store, mayoreo→wholesale, web, telefono→phone |
| `currency` | no | texto | moneda | ≠ moneda de la empresa ⇒ error `currency_mismatch` |
| `line_number` | no | entero | linea, item_no | si falta: orden de aparición dentro del documento |

### 2.2 `entity_type = products`
`sku`*, `name`*, `category`, `unit`, `brand`, `current_price`, `current_cost`, `is_active`.

### 2.3 `entity_type = inventory`
`snapshot_date`*, `branch_code`*, `product_sku`*, `on_hand`*, `reserved`, `in_transit`, `unit_cost`.
Idempotente por (branch, product, snapshot_date) → upsert.

### 2.4 `entity_type = lead_times`
`product_sku`, `supplier_code`, `branch_code`, `lead_time_days`*, `lead_time_std_days`.
(al menos uno de product_sku / supplier_code).

### 2.5 Opciones (`import_jobs.options`)
`date_format` (auto | `Y-m-d` | `d/m/Y` | `m/d/Y` | `d-m-Y`), `decimal_separator` (`.` | `,`),
`thousands_separator`, `csv_delimiter` (auto | `,` | `;` | `\t`), `default_branch_code`,
`create_missing_products` (true), `create_missing_branches` (false), `create_missing_categories` (true),
`skip_invalid_rows` (false: si hay errores no se puede confirmar; true: se importan solo filas válidas).

### 2.6 Códigos de error/advertencia de importación
`required`, `invalid_date`, `future_date`, `invalid_number`, `negative_quantity` (warning: devolución),
`zero_quantity` (warning), `negative_price`, `duplicate_in_file`, `unknown_branch`,
`unknown_product` (warning si se crearán), `currency_mismatch`, `net_amount_mismatch` (warning),
`document_conflict` (misma clave con distinto contenido dentro del archivo),
`voided_document` (info), `existing_document_changed` (warning: se actualizará),
`missing_dates` (reporte: días sin ventas en el rango).

### 2.7 Datos de demostración
`data/demo/` contiene datasets **ficticios** generados por `engine/predictily_engine/synthetic`
(semilla fija). Cada CSV empieza con los mismos encabezados canónicos de arriba. Archivos:
`{slug}_sales.csv`, `{slug}_products.csv`, `{slug}_inventory.csv`, `{slug}_lead_times.csv`,
`{slug}_branches.csv` (`code,name,province,canton,commercial_zone`),
`{slug}_promotions.csv` (campos de §2.8, incluido `known_at`: 7–21 días antes del inicio; las
promociones futuras, el último día de datos) y `{slug}_README.md`.
Slugs: `ferreteria_andina` (empresa demo A) y `distribuidora_costa` (empresa demo B).

### 2.8 `entity_type = promotions`
| Campo | Req. | Tipo | Sinónimos típicos | Reglas |
|---|---|---|---|---|
| `name` | sí | texto | nombre, promocion, campana | |
| `promo_type` | no | texto | tipo, tipo_promocion | percentage (defecto), fixed_amount, bundle, other |
| `discount_pct` | no | número | descuento, porcentaje, dscto | fracción (0.15) o porcentaje (15 o 15 %) |
| `starts_on` / `ends_on` | sí | fecha | desde, fecha_inicio / hasta, fecha_fin | fin ≥ inicio (ambos incluidos) |
| `branch_code`, `product_sku`, `category` | no | texto | sucursal, sku, categoria | alcance; vacío = todos; deben existir |
| `known_at` | no | fecha o fecha-hora | fecha_registro, fecha_de_registro, fecha_aprobacion, registrada_el, aprobada_el, fecha_planificacion | momento en que el negocio **aprobó o registró** la promoción, en la zona horaria de la empresa (solo fecha = medianoche local). Futura ⇒ error `future_date` (columna `known_at`). Si falta: una promoción nueva toma el momento de la importación; una existente conserva el suyo |

Idempotente por (name, starts_on, ends_on, sucursal, producto, categoría): una fila existente se
**actualiza** (no se borra y recrea) y conserva su historial; cambiar las fechas crea otra promoción.
Estadísticas: `promotions_new`, `promotions_updated`, `promotions_unchanged`. Cada alta o cambio
agrega una versión en `promotion_revisions` (`source = import`).

**Por qué importa `known_at`:** el motor solo usa una promoción en el futuro de un corte si ya se
conocía ese día (y con los valores vigentes entonces). Al cargar un histórico sin `known_at`, todas
las promociones figuran como conocidas el día de la carga: el backtest las verá como hechos pasados,
pero no como promociones planificadas, y medirá un pronóstico sin conocimiento previo de ellas.

---

## 3. API pública REST (Laravel) — `/api/v1`

### 3.1 Convenciones
- JSON, UTF-8. Fechas `YYYY-MM-DD`; fecha-hora ISO-8601 con zona.
- Autenticación SPA: `GET /sanctum/csrf-cookie` y luego cookies de sesión + header
  `X-XSRF-TOKEN`. Integraciones: `Authorization: Bearer <token>` (token ligado a una empresa).
- Empresa activa: header **`X-Tenant-ID: <tenant uuid>`** en todas las rutas marcadas **[T]**.
- Respuestas de recurso: `{ "data": {...} }`; listas paginadas:
  `{ "data": [...], "meta": { "current_page", "per_page", "total", "last_page" }, "links": {...} }`
  (formato estándar de Laravel API Resources). `per_page` máx. 100.
- Errores: `{ "message": "...", "code": "snake_case_opcional", "errors": { campo: [..] } }`.
  401 no autenticado · 403 sin permiso (`code: forbidden`) · 404 no existe o no pertenece a la
  empresa · 409 conflicto de estado · 422 validación · 429 límite de tasa ·
  402 límite del plan (`code: plan_limit_exceeded`, `limit`, `current`, `max`).
- Montos como números (float) con 2–4 decimales; cantidades como números.

### 3.2 Autenticación y cuenta
| Método | Ruta | Cuerpo / Query | Respuesta |
|---|---|---|---|
| POST | `/auth/register` | `name, email, password, password_confirmation, accept_terms(bool)` | 201 `{data:{user}}` (inicia sesión) |
| POST | `/auth/login` | `email, password, remember?` | 200 `{data:{user}}` · 422 credenciales · 429 |
| POST | `/auth/logout` | — | 204 |
| GET | `/auth/me` | — | `{data:{user:{id,name,email,locale,is_superadmin}, tenants:[{uuid,name,sector,role,onboarding_completed_at}]}}` |

### 3.3 Empresas, configuración y usuarios
| Método | Ruta | Detalle |
|---|---|---|
| GET | `/tenants` | empresas del usuario (`[{uuid,name,sector,role,...}]`) |
| POST | `/tenants` | `name*, legal_name, tax_id (RUC 13 dígitos), sector*, timezone, currency` → 201 `{data: Tenant}`; el usuario queda como `owner`; plan `trial` |
| GET | `/tenant` [T] | `{data: Tenant}` con `settings` efectivos, `plan:{code,name,limits}`, `role` del usuario, `onboarding:{has_branches,has_products,has_sales,has_inventory,has_forecast,completed_at}` |
| PATCH | `/tenant` [T] `settings.manage` | `name, legal_name, tax_id, sector, timezone, settings{...}` |
| POST | `/tenant/complete-onboarding` [T] | marca onboarding |
| PUT | `/tenant/shared-training-consent` [T] `tenant.consent` | `{allow:bool}` registra consentimiento con fecha y usuario |
| GET | `/tenant/usage` [T] | `{data:{period, metrics:{import_rows, forecast_runs, active_products, branches, users}, limits}}` |
| POST | `/tenant/demo-data` [T] `settings.manage` | carga datos ficticios de demostración en la empresa (solo si no tiene ventas) → 202 `{data:{import_job_ids:[...]}}` |
| POST | `/tenant/export` [T] `tenant.export_all` | exportación completa (LOPDP) → 202 `{data:{export_id}}`; `GET /tenant/export/{id}` → estado y `download_url` |
| DELETE | `/tenant` [T] `tenant.delete` | `{confirm_name}` → solicitud de eliminación (período de gracia 30 días) |
| GET | `/tenant/users` [T] `users.manage` | `[{id,name,email,role,joined_at}]` |
| POST | `/tenant/users` [T] `users.manage` | `{email, role, name?}` — agrega usuario existente o crea cuenta con contraseña temporal (devuelta una sola vez en dev) |
| PATCH | `/tenant/users/{user}` [T] | `{role}` (solo owner puede asignar/quitar owner; no se puede dejar sin owner) |
| DELETE | `/tenant/users/{user}` [T] | quita membresía |

`Tenant` = `{uuid, name, legal_name, tax_id, sector, country, currency, timezone, locale, status, settings, allow_shared_training, shared_training_consented_at, data_retention_months, onboarding_completed_at, created_at}`.

### 3.4 Catálogos
| Método | Ruta | Detalle |
|---|---|---|
| GET/POST | `/branches` [T] | `Branch = {id, code, name, province, canton, commercial_zone, address, is_active}` |
| PATCH/DELETE | `/branches/{id}` [T] `branches.manage` | DELETE desactiva si tiene ventas |
| GET | `/product-categories` [T] | `[{id, name, code, parent_id, products_count}]` |
| GET | `/products` [T] | query: `search, category_id, is_active, page, per_page, sort(name|sku|-units_90d)` → `Product = {id, sku, name, unit, brand, category:{id,name}, current_price, current_cost, is_active, stats:{units_90d, revenue_90d, last_sale_date}}` |
| GET | `/products/{id}` [T] | detalle + `stats` |
| POST/PATCH | `/products`, `/products/{id}` [T] `catalog.manage` | |
| GET | `/suppliers`, POST, PATCH [T] | |
| GET/POST | `/lead-times` [T] | `{id, product_id, supplier_id, branch_id, lead_time_days, lead_time_std_days, source}` |
| GET/POST/PATCH/DELETE | `/promotions` [T] | `{id,name,promo_type,discount_pct,starts_on,ends_on,branch_id,product_id,category_id,known_at}`. `known_at` (ISO 8601, opcional al crear o editar): cuándo el negocio conocía la promoción; sin zona se interpreta en la de la empresa; no puede ser futura (422); por defecto = alta. POST/PATCH con cambios y DELETE (baja lógica) agregan una versión; PATCH sin cambios no |
| GET | `/promotions/{id}/revisions` [T] | Historial (también de promociones dadas de baja): `[{revision, name, promo_type, discount_pct, starts_on, ends_on, branch_id, product_id, category_id, known_at, deleted, source(api\|import\|migration), recorded_at}]` |

### 3.5 Ventas e ingesta por API (conector REST)
| Método | Ruta | Detalle |
|---|---|---|
| GET | `/sales` [T] | query `date_from, date_to, branch_id, status, document_type, page` → `[{id, document_type, document_number, status, issued_at, sale_date, branch:{id,code,name}, total, items_count}]` |
| GET | `/sales/{id}` [T] | con `items` |
| POST | `/sales/batch` [T] `imports.manage` | `{documents:[{document_number, document_type?, status?, issued_at, branch_code, currency?, channel?, customer_code?, items:[{product_sku, product_name?, category?, quantity, unit_price, discount?, tax?, unit_cost?, line_number?}]}]}` (máx. 500 docs) → `{data:{created, updated, unchanged, errors:[{index, field, code, message}]}}` — idempotente por `(document_type, document_number)` |
| GET | `/sales/summary` [T] | query `date_from, date_to, branch_id, category_id, product_id, granularity(day|week|month)` → `{data:{series:[{period, units, revenue, documents}], totals:{units, revenue, documents, returns_units}}}` (excluye anuladas) |

### 3.6 Importaciones
Estados: `uploaded → awaiting_mapping → validating → validated → importing → completed`
(o `failed`, `rejected`).

| Método | Ruta | Detalle |
|---|---|---|
| GET | `/imports` [T] | lista paginada de `ImportJob` |
| POST | `/imports` [T] `imports.manage` | multipart: `file` (csv/xlsx, máx 20 MB), `entity_type` → 201 `ImportJob` en `awaiting_mapping` con `detected_columns`, `suggested_mapping`, `preview` (20 filas crudas) |
| GET | `/imports/{id}` [T] | `ImportJob` |
| PUT | `/imports/{id}/mapping` [T] | `{mapping:{campo: columna|null}, options:{...}}` → 202, pasa a `validating` (Job asíncrono). Repetible para corregir (desde `awaiting_mapping`, `validated` o `failed`) |
| GET | `/imports/{id}/errors` [T] | query `severity, page` → `[{row_number, column, error_code, severity, message, raw_value}]` |
| GET | `/imports/{id}/errors.csv` [T] | descarga de errores |
| POST | `/imports/{id}/confirm` [T] | 202 → `importing` (solo desde `validated`; 409 si hay errores y `skip_invalid_rows=false`) |
| POST | `/imports/{id}/reject` [T] | → `rejected` |
| GET | `/import-templates/{entity_type}` | CSV de plantilla con encabezados canónicos |
| GET | `/import-fields/{entity_type}` | definición de campos (para el asistente): `[{key, label, required, type, description, synonyms}]` |

`ImportJob = {id, entity_type, original_filename, file_format, file_size, status, progress, detected_columns:[string], suggested_mapping:{campo: columna}, column_mapping, options, preview:{headers:[..], rows:[[..]]}, normalized_preview:[{...}], stats:{rows_total, rows_valid, rows_error, rows_warning, duplicates_in_file, documents_total, documents_new, documents_updated, documents_unchanged, voided_documents, return_lines, date_from, date_to, missing_days, products_new, branches_new}, quality_report:{score, issues:[{code, severity, message, count}]}, error_message, created_at, validated_at, finished_at, user:{id,name}}`.

### 3.7 Fuentes de datos
| Método | Ruta | Detalle |
|---|---|---|
| GET | `/data-sources` [T] | `[{id, type, connector_key, name, status, last_synced_at, last_error, config}]` (nunca credenciales) |
| POST | `/data-sources` [T] `datasources.manage` | `{type: file_upload|rest_api|connector, name, connector_key?, config?, credentials?}` |
| PATCH/DELETE | `/data-sources/{id}` [T] | |
| POST | `/data-sources/{id}/tokens` [T] | crea token de API ligado a la empresa para `rest_api` → `{data:{token}}` (se muestra una sola vez) |
| GET | `/connectors` [T] | conectores registrados `[{key, name, description, status: available|requires_credentials|planned, entity_types}]` |

### 3.8 Pronósticos y trabajos  (backend-analytics)
| Método | Ruta | Detalle |
|---|---|---|
| POST | `/forecast-runs` [T] `forecast.run` | `{horizon_days?: 7|30|90 (def 90), cutoff_date?: date (crea run histórico)}` → 202 `{data: ForecastRun}` (status queued) + `training_job` |
| GET | `/forecast-runs` [T] | paginado, más reciente primero |
| GET | `/forecast-runs/{id}` [T] | `ForecastRun` |
| GET | `/jobs/{id}` [T] | `TrainingJob = {id, job_type, status, stage, progress, attempts, error_message, queued_at, started_at, finished_at, duration_ms, result, forecast_run_id}` |
| GET | `/forecasts/series` [T] | ver abajo |
| GET | `/forecasts/products` [T] | query `run_id?, horizon(7|30|90), branch_id?, category_id?, search?, sort(change_pct|-change_pct|forecast_units|-forecast_units|revenue), page` → `[{product:{id,sku,name,category}, history_units (mismo nº de días previos), forecast_units, change_pct, trend: up|down|flat, forecast_revenue, lo_80, hi_80, model_family, demand_class, wape (backtest de la serie si existe)}]` |
| GET | `/forecasts/categories` [T] | igual por categoría |
| GET | `/dashboard/summary` [T] | query `horizon(7|30|90, def tenant.default_horizon_days), branch_id?` → ver abajo |

`ForecastRun = {id, version, run_type, status, triggered_by, horizon_days, cutoff_date, data_from, data_to, feature_set_version, is_current, selection, summary, error_message, started_at, completed_at, created_at, training_job:{...}}`.

`GET /forecasts/series` query: `run_id?` (def. vigente), `branch_id?`, `category_id?`, `product_id?`,
`granularity` (day|week|month, def. day), `horizon` (7|30|90), `history_days` (def. 90; máx 730).
Selección de nodo: sin filtros→`total`; branch→`branch:{b}`; category→`category:{c}`;
branch+category→`branch_category:{b}:{c}`; product→`product:{p}`; product+branch→`series:{b}:{p}`.
Respuesta:
```json
{"data":{
  "node":{"key":"product:45","level":"product","label":"Cemento Portland 50kg"},
  "granularity":"day",
  "history":[{"date":"2026-07-01","units":12.0,"revenue":95.4,"stockout":false}],
  "forecast":[{"date":"2026-10-28","yhat":13.2,"lo_80":9.1,"hi_80":17.4,"lo_95":7.0,"hi_95":20.1,"revenue":105.6}],
  "meta":{"run_id":12,"run_version":5,"generated_at":"...","cutoff_date":"2026-10-27","model_family":"lightgbm_global","models":{"lightgbm_global":120,"seasonal_naive":15},"price_assumption":"último precio neto promedio (28 días)","intervals_available":true}
}}
```
Si la granularidad pedida no existe precalculada para el nodo, Laravel agrega los valores
diarios (`yhat`, `revenue`) y devuelve intervalos `null` con `intervals_available:false`.

`GET /dashboard/summary` respuesta:
```json
{"data":{
  "horizon":30, "run":{"id":12,"version":5,"generated_at":"...","cutoff_date":"..."} ,
  "kpis":{
    "forecast_units":1234.5, "forecast_units_lo_80":1100.0, "forecast_units_hi_80":1380.0,
    "forecast_revenue":10234.1, "previous_period_units":1180.0, "previous_period_revenue":9800.0,
    "change_pct":0.046, "stockout_risk_products":7, "purchase_investment":4567.8,
    "backtest_wape":0.23, "baseline_wape":0.31, "realized_wape":0.25
  },
  "top_increasing":[{"product_id":1,"sku":"..","name":"..","change_pct":0.35,"forecast_units":80}],
  "top_decreasing":[...],
  "stockout_risks":[{"product_id":..,"branch_id":..,"product_name":"..","branch_name":"..","days_of_cover":3.2,"stockout_probability":0.71,"risk_level":"high","suggested_qty":40}],
  "alerts":[Alert...],
  "data_status":{"has_sales":true,"has_inventory":true,"has_forecast":true,"last_sale_date":"...","readiness_score":82,"warnings":["..."]}
}}
```
Si no hay pronóstico vigente: `run:null`, KPIs de pronóstico `null` y `data_status.has_forecast=false`.

### 3.9 Precisión
| Método | Ruta | Detalle |
|---|---|---|
| GET | `/accuracy/summary` [T] | query `run_id?` → `{data:{run, backtest:{selected:{wape,mae,rmse,bias,coverage_80,coverage_95}, baseline:{...}, by_horizon:[{horizon_days, wape, baseline_wape}], by_demand_class:[...], by_branch:[...], models:[{model_family, wape, is_baseline, is_selected}]}, realized:{wape, mae, bias, n_obs, by_run:[{run_id, version, wape, n_obs}]}, explanation:[string]}}` |
| GET | `/accuracy/comparison` [T] | query `run_id*, branch_id?, category_id?, product_id?` → `{data:{points:[{date, yhat, lo_80, hi_80, actual|null}], metrics:{wape, mae, bias, n_obs}}}` real vs pronosticado de una ejecución pasada |
| GET | `/model-evaluations` [T] | query `run_id*, evaluation_type?, level?` → filas de `model_evaluations` |
| GET | `/models` [T] | `model_registry` de la empresa: `[{id, version, model_family, strategy_level, status, demand_class, trained_at, training_data_from, training_data_to, series_count}]` |

### 3.10 Inventario, recomendaciones y alertas
| Método | Ruta | Detalle |
|---|---|---|
| GET | `/inventory/recommendations` [T] | query `run_id?, branch_id?, category_id?, risk_level?, type?, search?, sort(-stockout_probability|days_of_cover|-estimated_investment), page` → `[Recommendation + product:{id,sku,name}, branch:{id,name}]` |
| PATCH | `/inventory/recommendations/{id}` [T] `alerts.manage` | `{status: acknowledged|dismissed|ordered}` (solo registro; no ejecuta pedidos) |
| GET | `/inventory/summary` [T] | `{data:{snapshot_date, products_with_stock, total_on_hand_value, risk_counts:{low,medium,high,critical}, purchase_investment, coverage_days_median}}` |
| GET | `/alerts` [T] | query `status?, type?, severity?` paginado |
| PATCH | `/alerts/{id}` [T] `alerts.manage` | `{status: acknowledged|resolved}` |

`Recommendation = {id, type, branch_id, product_id, inventory_snapshot_date, on_hand, reserved, in_transit, available, lead_time_days, lead_time_source, review_period_days, service_level, demand_lead_time, demand_lead_time_std, safety_stock, reorder_point, suggested_qty, unit_cost, estimated_investment, days_of_cover, stockout_probability, stockout_date_estimate, risk_level, status, assumptions}`.

### 3.11 Simulador de escenarios
| Método | Ruta | Detalle |
|---|---|---|
| POST | `/scenarios/simulate` [T] `scenarios.run` | ver abajo (cálculo determinista, sin persistir) |
| GET/POST | `/scenarios` [T] | guardar/listar escenarios `{name, horizon_days, filters, assumptions}` |
| GET/DELETE | `/scenarios/{id}` [T] | |

Petición: `{horizon_days: 7|30|90, filters:{branch_id?, category_id?, product_id?}, assumptions:{price_change_pct: -0.5..1.0, discount_pct: 0..0.9, cost_change_pct: -0.5..1.0, demand_change_pct: -0.9..2.0, lead_time_days?: 1..180, purchase_budget?: number, macro_note?: string}}`.
Respuesta:
```json
{"data":{
  "type":"simulation",
  "disclaimer":"Simulación matemática basada en supuestos ingresados por el usuario. No es una predicción estadística ni una estimación causal: el cambio de demanda NO se deriva del cambio de precio.",
  "base":{"units":1000,"revenue":8000,"cost":5200,"gross_margin":2800,"gross_margin_pct":0.35,"purchase_need_units":300,"purchase_need_cost":1560},
  "scenario":{...mismos campos...},
  "delta":{"units":..,"revenue":..,"gross_margin":..,"gross_margin_pct":..},
  "budget":{"purchase_budget":1000,"coverage_pct":0.64,"shortfall":560},
  "risks":[{"code":"negative_margin|budget_shortfall|stockout_risk|low_data_quality|cost_unknown","severity":"warning","message":"..."}],
  "assumptions_used":{...}, "base_run":{"id":12,"version":5}
}}
```
Reglas: unidades escenario = base × (1+demand_change_pct) (supuesto explícito); precio
escenario = precio supuesto × (1+price_change_pct) × (1−discount_pct); costo = último costo
conocido × (1+cost_change_pct). Si falta costo para productos, se informa `cost_unknown`.

### 3.12 Exportaciones y reportes
`GET /exports/forecasts.csv` (mismos filtros que `/forecasts/products` + `granularity`),
`GET /exports/recommendations.csv`, `GET /exports/accuracy.csv`, `GET /exports/sales-summary.csv`
— requieren `data.export`; quedan registradas en `audit_logs`.
`GET /reports/forecast-summary?horizon=30` → JSON listo para imprimir (resumen ejecutivo).

### 3.13 Auditoría y plataforma
- `GET /audit-logs` [T] `audit.view` — paginado.
- Superadmin (`users.is_superadmin`, sin [T]): `GET /admin/tenants`, `PATCH /admin/tenants/{uuid}`
  (`plan_code, status, plan_overrides`), `GET /admin/plans`, `GET /admin/usage`. El superadmin
  **no** accede a ventas/pronósticos de empresas a menos que sea miembro.

---

## 4. API interna del motor (FastAPI)

Base `ENGINE_URL`. Todas las rutas (excepto `/health`) exigen
`Authorization: Bearer <ENGINE_API_TOKEN>`; comparación en tiempo constante. 401 si falla.
El motor **lee** datos directamente de PostgreSQL filtrando SIEMPRE por `tenant_id` y **escribe**
solo en las tablas que le pertenecen (ver migración `2026_01_01_000700`). Laravel crea las filas
`forecast_runs` / `training_jobs` en `queued` y luego invoca al motor desde un Job de cola
(no desde la petición HTTP del usuario).

| Método | Ruta | Uso |
|---|---|---|
| GET | `/health` | `{status:"ok", version, db:"ok"|"error", models_available:[...]}` |
| POST | `/v1/forecast-runs/{run_id}/execute` | ejecuta el pipeline completo (síncrono; el Job de Laravel espera con timeout `ENGINE_TIMEOUT`) |
| POST | `/v1/tenants/{tenant_id}/readiness` | perfila series y calidad para pronóstico (rápido) |
| POST | `/v1/tenants/{tenant_id}/evaluate-actuals` | compara pronósticos pasados con ventas reales, detecta drift |
| GET | `/v1/capabilities` | modelos disponibles, versión del feature set, horizontes |

### 4.1 `POST /v1/forecast-runs/{run_id}/execute`
Petición:
```json
{"tenant_id": 3, "training_job_id": 41,
 "horizons": [7, 30, 90],
 "cutoff_date": null,
 "options": {"force_models": null, "max_backtest_windows": 3, "seed": 42}}
```
Comportamiento:
1. Verifica que `forecast_runs.id = run_id AND tenant_id = tenant_id` y `training_jobs` igual; si
   no coinciden → 404. Si el run ya está `completed` → 200 con el resumen existente (idempotente).
2. Marca job/run `running`; actualiza `training_jobs.stage` y `progress` a medida que avanza.
3. Carga ventas válidas (`sales.status='completed'`, `document_type IN ('invoice','sales_note','other')`,
   `sale_items.is_return=false`) con `sale_date <= cutoff` (cutoff = `cutoff_date` o última fecha
   con ventas). Las devoluciones se reportan aparte, no se restan de la demanda diaria.
4. Perfila series (`forecasting_series`), marca días censurados por quiebre de stock.
5. Genera características, ejecuta backtesting rolling-origin de candidatos, selecciona por
   clase de demanda con baseline obligatorio y respaldo.
6. Entrena el modelo final, pronostica `max(horizons)` días, calcula intervalos 80/95,
   reconcilia (bottom-up) y escribe `forecast_values` para niveles `series, product, category,
   branch, branch_category, total` en granularidad `day` (y `week`/`month` para niveles agregados).
7. Escribe `model_registry`, `model_evaluations` (backtest), `recommendations` y `alerts`
   (`stockout_risk`), `data_quality_reports` (scope `forecasting_readiness`, con `forecast_run_id`).
8. Marca run `completed` (y `is_current=true` si `run_type='production'`, desmarcando el anterior en
   la misma transacción) y job `succeeded` con `result`.

Respuesta 200:
```json
{"run_id": 12, "status": "completed", "series_count": 135, "cutoff_date": "2026-10-27",
 "selection": {"smooth": {"model_family": "lightgbm_global", "model_registry_id": 77}, "intermittent": {...}, "fallback": {"model_family": "seasonal_naive", "model_registry_id": 78}},
 "metrics": {"selected_wape": 0.23, "baseline_wape": 0.31},
 "warnings": ["12 series con menos de 28 días de historia usan promedio móvil"],
 "duration_ms": 48211}
```
Errores: 404 run/tenant no coinciden · 409 run en ejecución por otro proceso · 422 datos
insuficientes (`{"code":"insufficient_data", "message": "...", "details": {...}}`; el motor
marca run `failed` con ese mensaje) · 500 error interno (run/job `failed`, `error_message`).

### 4.2 `POST /v1/tenants/{tenant_id}/readiness`
Petición `{"training_job_id": null}` → 200
`{"tenant_id":3, "score": 78.5, "series_total": 140, "series_by_class": {"smooth":40,...},
"history_days": 540, "date_from": "...", "date_to": "...", "missing_days": 12,
"stockout_days_share": 0.04, "has_inventory": true, "has_costs": true,
"recommended_strategy": "A|B", "issues": [{"code","severity","message","count"}],
"data_quality_report_id": 9}`. Actualiza `forecasting_series` y crea `data_quality_reports`.

### 4.3 `POST /v1/tenants/{tenant_id}/evaluate-actuals`
Petición `{"training_job_id": 55, "lookback_runs": 10}` → para cada run completado con fechas
objetivo ya observadas, inserta/actualiza `forecast_actuals` y `model_evaluations`
(`evaluation_type='realized'`). Si el WAPE realizado supera al de backtest por más del umbral
(`max(1.3×backtest, backtest+0.10)`) en ≥14 días observados, crea alerta `forecast_drift`
(deduplicada por `dedup_key`). Respuesta `{"evaluated_runs": [...], "drift_detected": bool, "realized_wape": 0.27}`.

### 4.4 Estados y valores permitidos que el motor escribe
- `training_jobs.status`: queued, running, succeeded, failed · `stage`: ver migración.
- `forecast_runs.status`: queued, running, completed, failed.
- `model_registry.status`: candidate, production, fallback, archived, rejected.
- `model_registry.model_family`: naive, seasonal_naive, window_average, auto_ets, croston_sba,
  adida, tsb, lightgbm_global, timesfm.
- `forecast_values.level`: total, branch, category, branch_category, product, series;
  `node_key` según la tabla del §3.8.
- `recommendations.type`: reorder, stockout_risk, overstock, no_action · `risk_level`: low,
  medium, high, critical.
- `alerts.type` (motor): stockout_risk, forecast_drift, data_quality, run_completed.

---

## 5. Orquestación (colas de Laravel)

Colas Redis: `default`, `imports`, `forecasting`.

| Job (Laravel) | Cola | Qué hace |
|---|---|---|
| `AnalyzeImportFile` | imports | (síncrono en upload si < 2 MB) detecta columnas y vista previa |
| `ValidateImport` | imports | parsea todo el archivo, valida, cuenta duplicados/existentes, guarda errores y reporte |
| `CommitImport` | imports | upsert idempotente por bloques; al terminar despacha `RefreshReadiness` |
| `RefreshReadiness` | forecasting | llama `/readiness` |
| `RunForecastPipeline` | forecasting | llama `/execute`; `timeout=ENGINE_TIMEOUT`, `tries=2`, backoff 60 s; marca `failed` si el motor no responde |
| `EvaluateActuals` | forecasting | llama `/evaluate-actuals` |

Programación (`routes/console.php`): diaria 02:00 America/Guayaquil `predictily:evaluate-actuals`;
según `settings.forecast_schedule` (daily/weekly) `predictily:schedule-forecasts`; diaria
`predictily:purge` (retención y eliminación diferida). El entrenamiento **no** se dispara por cada
factura: solo por programación, por acción del usuario o al completar una importación inicial.
