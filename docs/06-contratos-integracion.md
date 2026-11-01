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

**Artefactos de modelos (regla única, demanda y comportamiento de compra)**: `model_registry.artifact_path` se guarda **relativo a `ENGINE_ARTIFACTS_DIR`**,
con `/` (`{tenant}/run-{id}/{familia}.joblib`, `{tenant}/customer/run-{id}/h{H}_{objetivo}_{modelo}.joblib`); el SHA-256 va en `artifact_sha256`. El backend usa la
**misma variable `ENGINE_ARTIFACTS_DIR`** (`config('engine.artifacts_dir')`) apuntando al mismo volumen —en Docker `engine-artifacts` montado en solo lectura— para
**verificar el SHA-256 al revertir modelos**. Un archivo visible cuyo hash no coincide impide la reversión (409 `rollback_artifact_mismatch`). Si el backend no ve el
archivo, la respuesta lo informa explícitamente (`artifacts_verification.status = not_verifiable|partially_verified` con `reason` y `message`; `artifacts_verified` /
`artifact_sha256_verified` quedan en `null`) y el motor lo verifica al cargar el artefacto. Las filas anteriores a esta regla (ruta absoluta, o `storage/artifacts/…` relativa al
directorio de trabajo del motor) se siguen leyendo.

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
| `document_type` | no | texto | tipo_documento, tipo | factura→invoice, nota de venta→sales_note, nota de crédito / devolución→credit_note, otro→other; vacío = invoice. Un tipo NO vacío que no se reconoce (cotización, proforma, pedido, guía…) es ERROR `unknown_document_type` (clase `REVIEW_REQUIRED`): el documento no se escribe |
| `status` | no | texto | estado, anulada | anulada/anulado/void/cancelada (y las banderas sí/1/true)→voided; pendiente/borrador→pending; emitida/autorizada/completada/pagada… (y no/0/false)→completed; vacío = completed. Un estado NO vacío que no se reconoce (rechazada, reversada, no autorizada…) es ERROR `unknown_document_status` (clase `REVIEW_REQUIRED`): el documento no se escribe |
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

Los códigos de estado o de tipo propios de un ERP (p. ej. `A`, `V`) no se reconocen: en una **sincronización SQL** se traducen con
`source_mappings.options.value_maps.status` / `.document_type` (valor de origen ⇒ valor canónico); en archivos, hasta que existan mapas de
valores por fuente (paquete S01), se normalizan en el archivo o se importan solo las filas válidas (`skip_invalid_rows`).

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
`skip_invalid_rows` (false: si hay errores no se puede confirmar; true: se importan solo filas válidas),
`ingestion_mode` (solo ventas; `backfill` | `incremental` | vacío = automático, ADR-V25-23): vacío ⇒ `backfill` si la empresa
no tiene ninguna fila en `sales` cuando EMPIEZA la escritura (primera carga histórica) e `incremental` si ya tiene. En la
validación se muestra como proyección (`stats.ingestion_mode`, `stats.ingestion_mode_basis`); al confirmar se decide con
las ventas existentes en ese momento y el valor resuelto queda en `import_jobs.options.ingestion_mode` (un reintento del mismo
import conserva el modo con el que empezó). Un valor inválido, o enviarlo para otra entidad, es 422 `options.ingestion_mode`.
`value_maps` (solo ventas, ADR-V25-24): `{document_type: {"<crudo>": "<canónico>"}, status: {...}}`, se aplican ANTES del reconocimiento de
vocabulario (coincidencia exacta y, si no, por clave sin tildes ni mayúsculas); el destino debe ser un valor del vocabulario canónico
(`factura`, `nota de venta`, `nota de crédito`, `otro` / `completada`, `anulada`, `pendiente`, o sus equivalentes en inglés) o es 422
`options.value_maps.<campo>`; se registran en `metrics.transformations` y en `rules_version` del informe de calidad. Con `skip_invalid_rows`, un
documento con hallazgos `REVIEW_REQUIRED` o `QUARANTINED` se retiene en la cuarentena (§3.14.2) en lugar de descartarse.

### 2.6 Códigos de error/advertencia de importación
`required`, `invalid_date`, `future_date`, `invalid_number`, `negative_quantity` (warning: devolución),
`zero_quantity` (warning), `negative_price`, `duplicate_in_file`, `unknown_branch`,
`unknown_product` (warning si se crearán), `currency_mismatch`, `net_amount_mismatch` (warning),
`document_conflict` (misma clave con distinto contenido dentro del archivo),
`unknown_document_type` y `unknown_document_status` (**error**, `dq_class = REVIEW_REQUIRED`; antes el tipo desconocido era una
advertencia y se contaba como venta `other`, y el estado desconocido se contaba como completada),
`document_number_shared_with_unknown_type` (**warning**, sin `dq_class`: un documento reconocido comparte número con filas de tipo no reconocido del mismo
archivo o lote; el documento reconocido SE ESCRIBE con sus propias líneas —la numeración suele ser por tipo o serie— y las filas de tipo desconocido siguen siendo
error; se cuenta una vez por documento en `stats.document_number_shared_with_unknown_type`),
`voided_document` (info), `existing_document_changed` (warning: se actualizará y la versión anterior se archiva),
`stale_document_skipped` (warning, reporte: documentos con una versión ANTERIOR a la guardada, no se aplican),
`updated_without_source_order` (info, reporte: actualizaciones sin fecha de registro en la fuente; gana la última carga; la marca almacenada se conserva),
`unchanged_mode_mismatch` (info, reporte: documentos sin cambios cuyo modo de ingestión almacenado difiere del declarado; no se reescriben),
`missing_dates` (reporte: días sin ventas en el rango).

`dq_class` (ADR-V25-05) acompaña a los hallazgos que exigen revisión humana: `REVIEW_REQUIRED` en `unknown_document_type` y
`unknown_document_status`, y `null` en los demás. No es una columna de `import_errors`: se deriva del código y se entrega en
`GET /imports/{id}/errors` (`dq_class`), en `errors.csv` (columna `clase_calidad`) y en el detalle de errores de `POST /sales/batch`.
La clase de TODOS los códigos (cinco clases: `BLOCKER`, `REVIEW_REQUIRED`, `QUARANTINED`, `WARNING`, `AUTO_FIX_SAFE`) está en el catálogo único
`DataQualityCatalog` y en `data_quality_issues.dq_class` (`docs/data/DATA_QUALITY_ENGINE.md` §2). Códigos nuevos de W2-A: `schema_drift`,
`date_format_ambiguous`, `documents_quarantined`, `empty_file`, `unreadable_file`, `bronze_capture_failed`, `source_columns_missing` y las normalizaciones
agregadas `whitespace_trimmed`, `decimal_separator_normalized`, `date_format_normalized`, `code_case_normalized`.

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

### 2.9 Etapa 2 · campos nuevos en ventas y productos (tres fechas)
- `sales`: `source_recorded_at` (fecha-hora, opcional): cuándo el sistema de origen registró o modificó el documento. `NULL` = desconocida;
  no entra en `source_hash`. También en `POST /sales/batch` (`documents[].source_recorded_at`).
- `products`: `price_known_at`, `cost_known_at` (fecha-hora, opcionales): desde cuándo la fuente conocía el precio/costo vigente
  (`product_price_history.known_at`, `purchase_cost_history.known_at`). `NULL` = desconocida, nunca la fecha de la carga.
- Una fecha de disponibilidad futura o inválida en productos es error; en ventas, advertencia y `NULL`.

### 2.10 `entity_type = commitments` (cotización / pedido / contrato; una fila por línea)
Obligatorios: `commitment_type` (`quote|order|contract`), `external_id`, `issued_at`, `product_sku`, `quantity`. Opcionales: `status`, `known_at`,
`valid_from`, `valid_to`, `expected_delivery_date`, `cancelled_at`, `branch_code`, `customer_code`, `currency`, `line_number`,
`quantity_fulfilled`, `unit`, `unit_price`, `delivery_date`. Idempotente por `(commitment_type, external_id)`; un cambio de contenido
actualiza y agrega una revisión (`commercial_commitment_revisions`). Estado derivado si falta; cancelación con `cancelled_at`; una unidad
distinta de la del producto exige una conversión declarada (`unit_conversion_missing`). No son ventas ni se suman a la demanda.
Reglas de estado a un corte (`GET /commitments?as_of=`): ver `docs/architecture/v2/P3-datos.md` §2.3.
Códigos nuevos: `unit_conversion_missing`, `unit_converted` (advertencia), `fulfilled_exceeds_quantity`, `cancelled_without_date`,
`status_overridden`, `status_quantity_mismatch`, `known_before_issued`, `cancelled_before_issued`, `unknown_status`, `existing_commitment_changed`.

### 2.11 `entity_type = calendar_events`
Obligatorios: `scope` (`country|region|tenant|branch|category|product|sector`), `code`, `name`, `category`, `starts_on`. Opcionales: `scope_ref`
(obligatorio salvo `tenant`), `ends_on`, `status` (`planned|confirmed|cancelled`), `known_at`, `source`. Identidad `(scope, scope_ref, code, starts_on)` +
revisión. `known_at` vacío ⇒ `NULL` (advertencia `known_at_unknown`): el evento no se usa como conocido a futuro de un corte histórico.
Códigos: `known_at_unknown`, `known_after_start`, `scope_ref_ignored`.

### 2.12 `entity_type = unit_conversions` y `entity_type = warehouses`
- `unit_conversions`: `from_unit`*, `to_unit`*, `factor`* (> 0), `product_sku` (vacío = general), `valid_from`, `source`. Sin conversiones silenciosas ni cadenas.
  Códigos: `conversion_conflict`.
- `warehouses`: `code`*, `name`*, `branch_code`*, `storage_capacity`, `capacity_unit` (obligatoria con la capacidad: `capacity_unit_required`), `is_active`.

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
| POST | `/sales/batch` [T] `imports.manage` | `{ingestion_mode?: backfill\|incremental, documents:[{document_number, document_type?, status?, issued_at, branch_code, currency?, channel?, customer_code?, source_recorded_at?, items:[{product_sku, product_name?, category?, quantity, unit_price, discount?, tax?, unit_cost?, line_number?}]}]}` (máx. 500 docs) → `{data:{created, updated, unchanged, stale_skipped, revisions_archived, updated_without_source_order, unchanged_mode_mismatch, future_recorded_at, recorded_at_unreadable, document_number_shared_with_unknown_type, rejected, ingestion_mode, ingestion_mode_basis, bronze_object_id, errors:[{index, item?, field, code, severity, message, dq_class?}], warnings}}` — el cuerpo se guarda íntegro en Bronze (`api_batch`) ANTES de normalizar y cada documento escrito lleva `bronze_object_id`, `source_row` (posición en el lote) y `schema_version`; si Bronze no está disponible: 409 `bronze_unavailable` y no se procesa nada. Idempotente por `(document_type, document_number)`. `ingestion_mode` por defecto `incremental` (`ingestion_mode_basis = api_default`); `backfill` declara una carga histórica. Un documento existente con contenido distinto archiva su versión vigente; si `source_recorded_at` es ANTERIOR al almacenado se descarta (`stale_skipped`) sin escribir nada. 422 solo si no hay ningún documento válido ni descartado por versión anterior |
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
| GET | `/imports/{id}/errors` [T] | query `severity, page` → `[{row_number, column, error_code, severity, message, raw_value, dq_class}]` (`dq_class` = `REVIEW_REQUIRED` en `unknown_document_type` / `unknown_document_status`; `null` en los demás) |
| GET | `/imports/{id}/errors.csv` [T] | descarga de errores |
| POST | `/imports/{id}/confirm` [T] | 202 → `importing` (solo desde `validated`; 409 `has_errors` si hay errores y `skip_invalid_rows=false`; 409 `blocked_by_quality` si el informe tiene un hallazgo BLOCKER, también con `skip_invalid_rows`) |
| POST | `/imports/{id}/reject` [T] | → `rejected` |
| GET | `/import-templates/{entity_type}` | CSV de plantilla con encabezados canónicos |
| GET | `/import-fields/{entity_type}` | definición de campos (para el asistente): `[{key, label, required, type, description, synonyms}]` |

`ImportJob = {id, entity_type, original_filename, file_format, file_size, status, progress, detected_columns:[string], suggested_mapping:{campo: columna}, column_mapping, options, preview:{headers:[..], rows:[[..]]}, normalized_preview:[{...}], stats:{rows_total, rows_valid, rows_error, rows_warning, duplicates_in_file, documents_total, documents_new, documents_updated, documents_unchanged, voided_documents, return_lines, date_from, date_to, missing_days, products_new, branches_new; ventas (ADR-V25-23): stale_skipped, revisions_archived, updated_without_source_order, unchanged_mode_mismatch, future_recorded_at, recorded_at_unreadable, document_number_shared_with_unknown_type, ingestion_mode, ingestion_mode_basis (declared\|first_load\|subsequent_load); calidad (ADR-V25-24): documents_quarantined}, quality_report:{score, issues:[{code, severity, message, count}], summary}, error_message, created_at, validated_at, finished_at, user:{id,name}}`.

### 3.7 Fuentes de datos
| Método | Ruta | Detalle |
|---|---|---|
| GET | `/data-sources` [T] | `[{id, type, connector_key, name, status, last_synced_at, last_error, config}]` (nunca credenciales) |
| POST | `/data-sources` [T] `datasources.manage` | `{type: file_upload|rest_api|connector, name, connector_key?, config?, credentials?}` |
| PATCH/DELETE | `/data-sources/{id}` [T] | |
| POST | `/data-sources/{id}/tokens` [T] | crea token de API ligado a la empresa para `rest_api` → `{data:{token}}` (se muestra una sola vez) |
| GET | `/connectors` [T] | conectores registrados `[{key, name, description, status: available|requires_credentials|implemented_untested|planned, verification, entity_types, credential_keys}]`. Etapa 2: `sql_postgresql` (`requires_credentials`, `verification: tested_with_test_database`), `sql_mysql` y `sql_sqlserver` (`implemented_untested`: implementados sin servidor de pruebas; nunca «disponibles») |
| POST | `/data-sources/{id}/test` [T] `datasources.manage` | prueba de conexión; en conectores SQL abre la transacción de solo lectura y devuelve `{ok, message, details:{server_version, warnings[], verification, read_only_enforcement}}` (sin credenciales) |

### 3.8 Pronósticos y trabajos  (backend-analytics)
| Método | Ruta | Detalle |
|---|---|---|
| POST | `/forecast-runs` [T] `forecast.run` | `{horizon_days?: 7|14|15|30|60|90 (def. el mayor que permita el plan), cutoff_date?: date (crea run histórico)}` → 202 `{data: ForecastRun}` (status queued) + `training_job` |
| GET | `/forecast-runs` [T] | paginado, más reciente primero |
| GET | `/forecast-runs/{id}` [T] | `ForecastRun` |
| GET | `/jobs/{id}` [T] | `TrainingJob = {id, job_type, status, stage, progress, attempts, error_message, queued_at, started_at, finished_at, duration_ms, result, forecast_run_id}`. `attempts` = intentos reales de ejecución: **lo cuenta solo el backend** (una vez por intento de la cola, `TrainingJobRecorder::markAttempt`); el motor nunca lo incrementa (un trabajo ejecutado una vez informa 1; con un reintento, 2) |
| GET | `/forecasts/series` [T] | ver abajo |
| GET | `/forecasts/products` [T] | query `run_id?, horizon(7|14|15|30|60|90), branch_id?, category_id?, search?, sort(change_pct|-change_pct|forecast_units|-forecast_units|revenue), page` → `[{product:{id,sku,name,category}, history_units (mismo nº de días previos), forecast_units, change_pct, trend: up|down|flat, forecast_revenue, lo_80, hi_80, model_family, case_label (etapa 2: caso especial de la serie o null), demand_class, wape (backtest de la serie si existe)}]`; respuesta con `horizon_support` (§3.8.1) |
| GET | `/forecasts/categories` [T] | igual por categoría |
| GET | `/dashboard/summary` [T] | query `horizon(7|14|15|30|60|90, def tenant.default_horizon_days), branch_id?` → ver abajo; añade `horizon_support` |

`ForecastRun = {id, version, run_type, status, triggered_by, horizon_days, cutoff_date, data_from, data_to, feature_set_version, is_current, selection, summary, error_message, started_at, completed_at, created_at, training_job:{...}, config_version, data_snapshot_id, effective_config, evaluation_manifest (solo en GET /forecast-runs/{id}), envelope}` — los seis últimos son **aditivos** (etapa 2 · P1).

`GET /forecasts/series` query: `run_id?` (def. vigente), `branch_id?`, `category_id?`, `product_id?`,
`granularity` (day|week|month, def. day), `horizon` (7|14|15|30|60|90), `history_days` (def. 90; máx 730).
Selección de nodo: sin filtros→`total`; branch→`branch:{b}`; category→`category:{c}`;
branch+category→`branch_category:{b}:{c}`; product→`product:{p}`; product+branch→`series:{b}:{p}`.
Respuesta:
```json
{"data":{
  "node":{"key":"product:45","level":"product","label":"Cemento Portland 50kg"},
  "granularity":"day",
  "history":[{"date":"2026-07-01","units":12.0,"revenue":95.4,"stockout":false}],
  "forecast":[{"date":"2026-10-28","yhat":13.2,"lo_80":9.1,"hi_80":17.4,"lo_95":7.0,"hi_95":20.1,"revenue":105.6}],
  "meta":{"run_id":12,"run_version":5,"generated_at":"...","cutoff_date":"2026-10-27","model_family":"lightgbm_global","models":{"lightgbm_global":120,"seasonal_naive":15},"price_assumption":"último precio neto promedio (28 días)","intervals_available":true,
          "horizon_support":{"horizon":30,"status":"ok","reasons":[],"history_days":574,"required_history_days":60,"backtest_horizon_days":90,"independent_holdout":true,"evaluated_obs":36}},
  "envelope":{...}
}}
```
Cada punto de `forecast` añade, de forma aditiva: `p50` (mediana; **null** salvo que el motor la haya evaluado y publicado),
`quality_status` (`ok|degraded|insufficient_data`) y `case_label` (producto nuevo, descontinuado, apertura…; null = serie regular).
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

#### 3.8.1 Etapa 2 · P1 Demanda: respaldo por horizonte y envoltura

- **`horizon_support`** (series, productos, categorías y dashboard): `{horizon, status, reasons[{code, reason_code, message}], history_days,
  required_history_days, backtest_horizon_days, independent_holdout, evaluated_obs}`. `status`: `ok` · `degraded` (sin período final
  independiente) · `insufficient_data` (historia < 2×H o backtest sin ventanas de H días: el número existe pero **no debe presentarse como
  completo**) · `not_computed` (H mayor que el horizonte de la ejecución) · `unknown` (ejecución anterior a la etapa 2).
- **`envelope`** (ADR-V2-03; en `GET /forecast-runs/{id}`, listado y `GET /forecasts/series`): `{tenant_id, run_id, core:'demand', run_version, status,
  as_of, horizon_start, horizon_end, target_definition, entity_keys, unit, currency, result_type:'forecast', model_version, data_snapshot_id,
  config_version, assumptions[], limitations[], limitation_codes[], quality_status, uncertainty_source, horizon_support, p50}`. `status` es el estado técnico
  del trabajo; `quality_status` la aptitud estadística (en series, la del horizonte pedido).
  **Forma JSON de los mapas (P6).** En todas las respuestas de la etapa 2 los campos que son mapas (`model_version`/`model_versions`, `target_definition`, `options`,
  `selection`, `summary`, `field_map`, `stats`, `horizon_support`, …) se emiten **siempre como objeto** `{}`, aunque estén vacíos o sus claves sean numéricas
  (`{"7": …, "30": …}`: horizontes o ids de producto); `null` significa «sin valor registrado». Las listas (`assumptions[]`, `limitations[]`, `horizons[]`,
  `entity_keys[]`) siempre son `[]`. Un cliente puede iterar las claves de un mapa sin comprobar si llegó como lista.

### 3.9 Precisión
| Método | Ruta | Detalle |
|---|---|---|
| GET | `/accuracy/summary` [T] | query `run_id?` → `{data:{run, backtest:{selected:{wape,mae,rmse,bias,coverage_80,coverage_95}, baseline:{...}, by_horizon:[{horizon_days, wape, baseline_wape}], by_demand_class:[...], by_branch:[...], models:[{model_family, wape, is_baseline, is_selected}]}, realized:{wape, mae, bias, n_obs, by_run:[{run_id, version, wape, n_obs}]}, explanation:[string], **evaluation_manifest, horizon_support{7..90}, p50** (aditivos)}}`; `backtest.selected|baseline` añaden `mase, rmsse, pinball{p10,p50,p90}, interval_width{80,95}, money{currency,wape,mae,bias,actual_total,forecast_total,n_obs}, sample{n_obs,n_series,n_windows,exclusions}`; `backtest.by_horizon` usa los tramos `h1-7,h8-14,h15-30,h31-60,h61-90` y `backtest.by_horizon_total:[{horizon_days,wape,baseline_wape,bias,mae,money,n_obs}]` el error del total de H días |
| GET | `/accuracy/comparison` [T] | query `run_id*, branch_id?, category_id?, product_id?` → `{data:{points:[{date, yhat, lo_80, hi_80, actual|null}], metrics:{wape, mae, bias, n_obs}}}` real vs pronosticado de una ejecución pasada |
| GET | `/model-evaluations` [T] | query `run_id*, evaluation_type?, level? (incl. horizon_total, total)` → filas de `model_evaluations` con `metrics` (jsonb) |
| GET | `/models` [T] | `model_registry` de la empresa; query `status?` (nuevo: `candidate|validated|production|retired|failed`, o legado: `fallback|archived|rejected`), `role? (primary|fallback)`, `core?`, `run_id?` → `[{id, version, model_family, strategy_level, status, legacy_status, core, role, demand_class, artifact_sha256, artifact_compat, validated_at, trained_at, training_data_from, training_data_to, series_count}]` |
| POST | `/models/demand/rollback` [T] `forecast.run` | `{run_id}` → 200 `{data:{from_run_id, to_run_id, to_version, to_cutoff_date, to_data_snapshot_id, to_config_version, restored_model_ids[], retired_model_ids[], artifacts_verified: true|null, artifacts_verification:{verified, status, registered, checked, reason, message}, restored_models[], note}}`. Re-apunta la ejecución **vigente** a una ejecución de **producción, completada y anterior**; restaura sus modelos `retired`→`production` y retira los actuales; auditoría `models.demand_rolled_back`. 404 (ejecución inexistente o de otra empresa), 409 con `code`: `no_current_run`, `rollback_already_current`, `rollback_target_not_production`, `rollback_target_not_previous`, `rollback_target_without_models`, `rollback_target_without_forecasts`, `rollback_artifact_mismatch`; 422 sin `run_id`. No reentrena |

### 3.10 Inventario, recomendaciones y alertas
| Método | Ruta | Detalle |
|---|---|---|
| GET | `/inventory/recommendations` [T] | query `run_id?, branch_id?, category_id?, risk_level?, type?, search?, sort(-stockout_probability|days_of_cover|-estimated_investment), page` → `[Recommendation + product:{id,sku,name}, branch:{id,name}]` |
| PATCH | `/inventory/recommendations/{id}` [T] `alerts.manage` | `{status: acknowledged|dismissed|ordered}` (solo registro; no ejecuta pedidos) |
| GET | `/inventory/summary` [T] | `{data:{snapshot_date, products_with_stock, total_on_hand_value, risk_counts:{low,medium,high,critical}, purchase_investment, coverage_days_median}}` |
| GET | `/alerts` [T] | query `status?, type?, severity?` paginado |
| PATCH | `/alerts/{id}` [T] `alerts.manage` | `{status: acknowledged|resolved}` |

`Recommendation = {id, type, branch_id, product_id, inventory_snapshot_date, on_hand, reserved, in_transit, available, lead_time_days, lead_time_source, review_period_days, service_level, demand_lead_time, demand_lead_time_std, safety_stock, reorder_point, suggested_qty, unit_cost, estimated_investment, days_of_cover, stockout_probability, stockout_date_estimate, risk_level, status, assumptions}`.

Semántica (validación 2026-10-28, F1–F3):
- `available` = existencias físicas utilizables hoy (`on_hand − reserved`); **no** incluye compras
  pendientes. `in_transit` = total pendiente de recibir (órdenes abiertas o, si no hay, el tránsito del
  inventario). La posición de inventario y cada recepción con su fecha van en
  `assumptions.inventory_position` y `assumptions.receipts[]`; el saldo se simula día a día, así que una
  orden que llega tarde no oculta un quiebre previo (`assumptions.stockout_before_receipt`).
- `demand_lead_time`, `safety_stock`, `reorder_point` y `suggested_qty` pueden ser `null` cuando el
  pronóstico no cubre plazo + revisión (`assumptions.coverage_insufficient = true`,
  `coverage_days_needed`, `coverage_days_available`, `suggested_qty_lower_bound`).
- `stockout_probability` y `demand_lead_time_std` son `null` cuando la incertidumbre es desconocida
  (`assumptions.uncertainty = "unknown"`); el stock de seguridad usa entonces una política conservadora
  explícita (`assumptions.safety_stock_policy`) y el riesgo una regla determinista (`assumptions.risk_rule`).
  Las limitaciones legibles para el usuario van en `assumptions.limitations[]`.

### 3.11 Simulador de escenarios
| Método | Ruta | Detalle |
|---|---|---|
| POST | `/scenarios/simulate` [T] `scenarios.run` | ver abajo (cálculo determinista, sin persistir) |
| GET/POST | `/scenarios` [T] | guardar/listar escenarios `{name, horizon_days, filters, assumptions}` |
| GET/DELETE | `/scenarios/{id}` [T] | |

Petición: `{horizon_days: 7|14|15|30|60|90, filters:{branch_id?, category_id?, product_id?}, assumptions:{price_change_pct: -0.5..1.0, discount_pct: 0..0.9, cost_change_pct: -0.5..1.0, demand_change_pct: -0.9..2.0, lead_time_days?: 1..180, purchase_budget?: number, macro_note?: string}}`.
Respuesta:
```json
{"data":{
  "type":"simulation",
  "disclaimer":"Simulación matemática basada en supuestos ingresados por el usuario. No es una predicción estadística ni una estimación causal: el cambio de demanda NO se deriva del cambio de precio.",
  "base":{"units":1000,"revenue":8000,"cost":5200,"gross_margin":2800,"gross_margin_pct":0.35,"purchase_need_units":300,"purchase_need_cost":1560},
  "scenario":{...mismos campos...},
  "delta":{"units":..,"revenue":..,"gross_margin":..,"gross_margin_pct":..},
  "budget":{"purchase_budget":1000,"coverage_pct":0.64,"shortfall":560},
  "risks":[{"code":"negative_margin|margin_drop|budget_shortfall|stockout_risk|coverage_insufficient|low_data_quality|cost_unknown|price_without_demand_assumption","severity":"warning","message":"..."}],
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

### 3.14 Core de Datos (etapa 2 · P3) — detalle en `docs/architecture/v2/P3-datos.md`
| Método | Ruta | Detalle |
|---|---|---|
| GET/POST | `/data-sources/{id}/mappings`, GET `/{mapping}` [T] `datasources.manage` | consultas autorizadas: `{entity, query, field_map, options}`; cada cambio crea una versión **sin aprobar**. 422 `sql_query_rejected` (`reason`) si no es un único SELECT de solo lectura |
| POST | `/data-sources/{id}/mappings/{mapping}/preview`, `/approve`, `/deactivate` [T] `datasources.manage` | vista previa de campos canónicos (sin importar); aprobar activa la versión; solo la aprobada sincroniza |
| POST | `/data-sources/{id}/sync` [T] `datasources.manage` | `{entity, mode: full|incremental, ingestion_mode?: backfill\|incremental}` → 202 `SourceSync`; 409 `mapping_not_approved` / `sync_in_progress`. Job `SyncDataSource` (cola `imports`). `ingestion_mode` (solo `sales`; 422 para otra entidad): solicitud > `source_mappings.options.ingestion_mode` > regla (la primera sincronización de la fuente que trajo datos es `backfill`; las siguientes, `incremental`; una completada con 0 filas no cuenta) |
| GET | `/data-sources/{id}/syncs`, `/syncs/{id}` [T] `datasources.manage` | `SourceSync = {id, data_source_id, entity, mapping_version, mode, status: queued|running|completed|needs_review|failed, since, watermark, rows_extracted, pages, import_job_id, bronze_object_id, stats, error_message, started_at, finished_at}` (`bronze_object_id` = la extracción CRUDA guardada en Bronze antes de normalizar; `stats` incluye `transformations` y `schema`). `watermark` = mínimo entre el máximo `source_recorded_at` LEGIBLE extraído y el inicio de la sincronización: una fecha futura o ilegible no la adelanta y se cuenta en `stats.future_recorded_at` (con `stats.recorded_at_unreadable` como parte ilegible). En ventas, `stats.ingestion_mode` e `ingestion_mode_basis` (`declared\|first_sync\|subsequent_sync`). Una marca guardada en el futuro se acota también al leerla |
| GET | `/data-coverage` [T] | `{data:[{id, entity, data_source_id, branch_id, complete_from, complete_through, basis: declared|inferred, last_ingested_at, notes}], effective:{entidad:{complete_from, complete_through, rule: declared_by_user|min_over_sources|none, basis}}}` |
| PUT | `/data-coverage` [T] `imports.manage` | `{entity, complete_from?, complete_through*, data_source_id?, branch_id?, notes?}`; `complete_through` no puede ser futura |
| GET | `/data-coverage/gaps` [T] `data.view` | huecos INTERNOS de cobertura (ADR-V25-23): query `entity, data_source_id, branch_id, basis, from, to` (huecos que se solapan con el rango), `per_page` → `{data:[{id, entity, data_source_id, branch_id, gap_from, gap_to, days, reason, basis, notes, created_by, created_at}], meta}`. Sus días son `missing_source`, nunca ceros; pueden solaparse (el consumidor toma la unión) |
| POST | `/data-coverage/gaps` [T] `imports.manage` | `{entity*, gap_from*, gap_to*, data_source_id?, branch_id?, reason?, notes?}` → 201. `entity` ∈ `sales, inventory, purchases, costs, promotions, customers, commitments`; `gap_to` ≥ `gap_from` (ambos incluidos, máx. 3660 días); `data_source_id` y `branch_id` de la MISMA empresa y no dados de baja (422); `reason` ∈ `integration_outage, source_missing, migration, unknown` (defecto `unknown`); `basis` siempre `declared` (enviar `inferred` es 422). Auditoría `data_coverage.gap_declared` |
| DELETE | `/data-coverage/gaps/{id}` [T] `imports.manage` | 204; un hueco de otra empresa responde 404. Auditoría `data_coverage.gap_deleted` |
| GET | `/data-quality/extended` [T] | reporte ampliado: `temporal_coverage, sales_days, customer_identity, product_code_changes, price_history, units`; `?persist=1` lo guarda (`data_quality_reports`, scope `data_core`) |
| GET | `/data-quality/quarantine`, `/data-quality/quarantine/{id}` [T] `data.view` | cuarentena de ingestión (ADR-V25-24): documentos retenidos con hallazgos REVIEW_REQUIRED / QUARANTINED; query `entity, status (pending\|released\|discarded\|superseded), import_job_id, source_sync_id, per_page` → `{data:[{id, entity, record_key, status, issue_codes, rows, import_job_id, source_sync_id, bronze_object_id, resolution, created_at, resolved_by, resolved_at}], meta}`; el detalle agrega `payload` (mínimo, sin nombres de clientes) y `findings`. Otra empresa: 404 |
| POST | `/data-quality/quarantine/{id}/release` [T] `imports.manage` | `{value_maps?, force?}` revalida con los mapeos de valores vigentes y, si es válido, escribe con `SalesDocumentWriter` en modo `incremental` enlazado a Bronze → 200 `{data:{…, write:{state, documents, states, value_maps_added}}}`; si no, **422 `quarantine_still_invalid`** con `findings:[{source_row, code, dq_class, field, message}]`; 409 `invalid_state` si ya se resolvió o fue **superado** (`status = superseded`: una carga posterior dejó el documento bien); **409 `newer_version_exists`** (`context.current_versions`) si el documento ya está en Silver con otro contenido, escrito DESPUÉS de la cuarentena y sin orden de fuente: se descarta o se libera con `force=true` (auditado, `write.forced`) |
| POST | `/data-quality/quarantine/{id}/discard` [T] `imports.manage` | `{reason*}` (3–500 caracteres) → 200; no escribe en Silver; auditoría `quarantine.discarded` |
| GET | `/calendar-events` [T] | query `from, to, scope, category, status, include_public, as_of, limit`; con `as_of` solo revisiones con `known_at` ≤ corte (las de `known_at` desconocido no se devuelven) |
| POST/PATCH/DELETE | `/calendar-events[/{id}]` [T] `catalog.manage` | alta/edición (nueva **revisión**) / cancelación; solo eventos propios (el calendario público es de solo lectura). Pasan por el importador |
| GET | `/commitments`, `/commitments/{id}` [T] | filtros `type, status, customer_id, product_id, from, to`; `as_of` reconstruye el estado conocido al corte (`status_at_cutoff`, `pending_total`, `flags`, `known_at` efectivo = `COALESCE(known_at, ingestión)`, `known_basis: declared|ingestion`, `ingested_at`). **`as_of` de solo fecha (AAAA-MM-DD) = fin de ese día en la zona horaria de la empresa** (igual que el motor), no las 00:00 UTC: lo importado hoy sin `known_at` aparece con `as_of` = hoy (fecha local) y no con ayer; con hora explícita manda ese instante (sin zona, la de la empresa). `meta` informa `as_of` (instante efectivo), `timezone` y `as_of_basis` (`end_of_day_company_timezone`\|`instant`) |
| POST | `/commitments` [T] `imports.manage` | `{commitment_type, external_id, issued_at, …, items:[{product_sku, quantity, quantity_fulfilled?, unit?, unit_price?, …}]}` → 201 `created` / 200 `updated|unchanged`; 422 `validation_failed` con los errores del importador |
| POST | `/commitments/{id}/cancel` [T] `imports.manage` | `{cancelled_at?, known_at?}` → agrega una revisión; 409 `already_cancelled` |
| GET/POST | `/warehouses`, `/unit-conversions` [T] | consulta / alta-actualización (importador). `DELETE /unit-conversions/{id}` (`catalog.manage`) |
| GET | `/tenant/effective-config?core=` [T] | `{core, config_version, profile, precedence:[core,profile,tenant], keys:[{key,value,source}], tenant_overrides}`; 503 `engine_unavailable` |
| PUT | `/tenant/core-config/{core}` [T] `settings.manage` | `{overrides:{…}}`; el motor valida (claves desconocidas, tipos y rangos ⇒ 422 `invalid_config`); `{}` elimina los valores de empresa |
| GET | `/platform/extensions` [T] | perfil sectorial y proveedores que aplican a la empresa (vacío si no tiene perfil) |

Importaciones nuevas (`POST /imports`): `entity_type` ∈ `commitments`, `calendar_events`, `unit_conversions`, `warehouses` (§2.10–2.12).
`docs/openapi/predictily-api.yaml` se regenera con `python infra/scripts/merge_openapi.py` (incluye los huecos de cobertura y el modo de ingestión).

#### 3.14.1 Contrato temporal de ventas (V2.5 · ADR-V25-23) — resumen para integradores

- **Modo de ingestión** (`sales.ingestion_mode`): importación de archivo (`options.ingestion_mode`; automático ⇒ `backfill` si la empresa
  no tiene ventas al empezar la escritura, si no `incremental`), `POST /sales/batch` (`ingestion_mode`; por defecto `incremental`),
  sincronización SQL (solicitud > mapeo > primera sincronización con datos = `backfill`), demo (`backfill` explícito).
- **Versiones**: un documento existente con contenido distinto archiva su versión vigente (`sales_document_revisions` / `sale_item_revisions`)
  antes de reemplazarla; `current_revision` avanza en 1 y `first_seen_at` no cambia.
- **Orden**: una versión entrante con `source_recorded_at` ANTERIOR a la almacenada se descarta (`stale_skipped`, no se escribe nada). Sin marca
  de tiempo en alguna de las dos gana la última ingestión (`updated_without_source_order`) y la marca almacenada no se borra. Estadísticas aditivas
  en importaciones, lotes y sincronizaciones: `stale_skipped`, `revisions_archived`, `updated_without_source_order`, `unchanged_mode_mismatch`
  (documentos sin cambios con modo almacenado distinto del declarado: no se reescriben) y `future_recorded_at` / `recorded_at_unreadable`.
- **Cota de `source_recorded_at`**: ahora + tolerancia (5 minutos por defecto, `predictily.imports.source_recorded_at_tolerance_minutes`), igual en
  importaciones, `POST /sales/batch` y sincronizaciones SQL. Una marca posterior o ilegible no se guarda como orden de versión (NULL; la fila se escribe).
- **Ventanas de conocimiento**: las de las versiones de un documento son disjuntas; una re-carga `backfill` colapsa TODA la cadena archivada del
  documento (no solo la versión inmediata) para que ningún corte lo vea dos veces.
- **Tipos y estados**: no vacíos y desconocidos ⇒ error de fila `REVIEW_REQUIRED`, no se escribe (vacío = factura / completada); un documento
  reconocido con el mismo número que filas de tipo desconocido SE ESCRIBE con sus propias líneas y recibe el aviso `document_number_shared_with_unknown_type`
  (contado en `stats` de importaciones y en `data` de `POST /sales/batch`).
- **Marca de agua** de las sincronizaciones: acotada al inicio de la sincronización (futuras o ilegibles = anomalía `future_recorded_at`); sin fechas legibles
  conserva la marca previa (acotada) y, en la primera sincronización, cae en el inicio de la sincronización.
- **Huecos de cobertura**: `GET/POST /data-coverage/gaps`, `DELETE /data-coverage/gaps/{id}`.

| Estado | Qué | Prueba que lo demuestra |
|---|---|---|
| IMPLEMENTADO / VERIFICADO | Primera importación `backfill`, segunda `incremental`; declaración explícita respetada; decisión al iniciar la escritura | `SalesImportIngestionModeTest::test_first_import_is_backfill_and_the_second_is_incremental_by_default`, `::test_explicit_declaration_is_respected_over_the_default_rule`, `::test_the_mode_is_decided_when_the_write_starts_not_when_the_file_is_validated`, `::test_ingestion_mode_option_is_validated` |
| IMPLEMENTADO / VERIFICADO | `POST /sales/batch` incremental por defecto, modo inválido 422 | `SalesTemporalWriteTest::test_batch_defaults_to_incremental_and_a_late_document_is_not_visible_before_its_arrival`, `::test_invalid_ingestion_mode_is_rejected` |
| IMPLEMENTADO / VERIFICADO | Estadísticas de versiones en importaciones (validación = proyección) y reporte de calidad | `SalesImportIngestionModeTest::test_import_updates_archive_versions_and_report_the_new_statistics`, `::test_updates_without_a_source_timestamp_are_counted_and_reported` |
| IMPLEMENTADO / VERIFICADO | Demo con `backfill` explícito aun con ventas existentes | `SalesImportIngestionModeTest::test_demo_loads_sales_as_declared_backfill_even_on_a_company_with_sales` |
| IMPLEMENTADO / VERIFICADO | Tipo/estado desconocido ⇒ `REVIEW_REQUIRED` y no se escribe; vacíos por convención | `UnknownDocumentTypeStatusTest` (5 pruebas: importación y lote), `DocumentVocabularyTest` |
| IMPLEMENTADO / VERIFICADO | Sincronización SQL: modo (primera = backfill, siguientes = incremental, declarable), versiones y `stale_skipped` | `SqlSyncTemporalContractTest::test_first_sync_is_backfill_and_later_syncs_are_incremental_with_archived_versions`, `::test_an_older_version_from_the_source_is_stale_skipped_by_the_sync`, `::test_ingestion_mode_can_be_declared_in_the_request_and_overrides_the_mapping`, `::test_ingestion_mode_can_be_declared_in_the_mapping_options`, `::test_an_empty_first_sync_does_not_count_as_the_historical_load` |
| IMPLEMENTADO / VERIFICADO | Marca de agua acotada (futura, ilegible, marca previa envenenada) | `SqlSyncTemporalContractTest::test_a_future_recorded_at_does_not_advance_the_watermark_and_is_reported`, `::test_unreadable_recorded_at_is_an_anomaly_and_falls_back_to_the_sync_start`, `::test_a_poisoned_previous_watermark_is_bounded_when_it_is_read` |
| IMPLEMENTADO / VERIFICADO | Huecos de cobertura: CRUD, lista blanca, rango, fuente/sucursal de la misma empresa, habilidades y aislamiento | `CoverageGapsApiTest` (8 pruebas) |
| IMPLEMENTADO / VERIFICADO | OpenAPI al día con las rutas reales | `OpenApiRoutesTest`; `python infra/scripts/merge_openapi.py --check --strict` |
| IMPLEMENTADO / VERIFICADO | `value_maps` de tipo y estado en archivos y en la sincronización SQL; cuarentena `quarantine_records` (retener, listar, liberar, descartar) | `ValueMapsTest`, `QuarantineFlowTest`, `SqlIngestionQualityTest` (ver §3.14.2) |
| DISEÑADO / NO IMPLEMENTADO | Alerta tras N sincronizaciones consecutivas con 0 filas | ADR-V25-24 / paquete S01 (Data Quality Engine) |
| BLOQUEADO | Sincronización de MySQL y SQL Server con el contrato temporal | No hay servidor de pruebas en el entorno: solo PostgreSQL (`tested_with_test_database`); la lógica es independiente del conector |
| NO APLICA | `ingestion_mode` en productos, inventario y demás entidades | Solo las ventas son documentos versionados; enviarlo para otra entidad es 422 |

#### 3.14.2 Calidad de ingestión, Bronze, linaje y cuarentena (V2.5 · W2-A, ADR-V25-05 y ADR-V25-24) — resumen para integradores

- **Bronze antes de normalizar**: el archivo subido (`file_upload`), la extracción SQL (NDJSON comprimido, `sql_extract`, solo las columnas del mapeo aprobado) y el
  cuerpo de `POST /sales/batch` (`api_batch`) se guardan íntegros en `bronze_objects` antes de normalizar. Si el almacén falla la carga se detiene
  (hallazgo BLOCKER `bronze_capture_failed`): importación `failed`, sincronización `failed`, lote 409 `bronze_unavailable`.
- **Linaje Silver**: `sales.bronze_object_id`, `sales.source_row` (primera fila del documento en el origen; en lotes, posición del documento) y
  `sales.schema_version` (`CanonicalContracts::version('sales')`); lo mismo en `sales_document_revisions` (cada revisión conserva el linaje de SU
  versión) e `inventory_snapshots`.
- **Informe de calidad ampliado** (importación, sincronización y lote): `data_quality_reports.{dataset, issue_counts, rules_version, metrics}` más un hallazgo por fila en
  `data_quality_issues` (tope configurable por informe, 2000 por defecto, con la marca `truncated`); `summary` e `issues` no cambian.
- **Cuarentena**: con `skip_invalid_rows=true` un documento de ventas con hallazgos REVIEW_REQUIRED o QUARANTINED se retiene (no se descarta) y se libera tras agregar un
  mapeo de valores; sin `skip_invalid_rows` la confirmación se bloquea (`has_errors`). Un BLOCKER bloquea siempre (`blocked_by_quality`).
- **Deriva de esquema**: la huella de encabezados (en SQL, con tipos) por fuente y entidad cambia ⇒ sube `schema_version` y el informe registra el WARNING `schema_drift`
  con las columnas agregadas y quitadas (sin valores). `data_sources.schema_fingerprint` / `schema_version` reflejan la huella combinada y la versión de la fuente.
- **Reconciliación por carga**: al confirmar una importación o terminar una sincronización se crea un `reconciliation_reports` (`scope import|sync`) con las capas
  fuente → Bronze → Silver y las discrepancias explicadas (`docs/data/LINEAGE_AND_RECONCILIATION.md` §13).
- **Reprocesamiento**: `php artisan predictily:bronze-reprocess {id} [--force]` re-ejecuta de forma determinista un objeto Bronze de ventas; idempotente (la segunda vez, `unchanged`).
  Si el original es viejo, no trae `source_recorded_at` y revertiría documentos escritos después de él, se detiene con un aviso (409 `reprocess_would_overwrite_newer` en el
  servicio) y `--force` lo confirma. Su informe de calidad lleva `dataset = «<entidad>_reprocess»` para que los gates no cuenten dos veces las mismas filas.

### 3.15 Customer Core · comportamiento de compra (etapa 2 · P2) — detalle en `docs/architecture/v2/P2-comportamiento.md`
Todas bajo `/api/v1/customer`, [T], `auth:sanctum`. Lectura `forecast.view`; entrenar/predecir `forecast.run`. Horizontes: plan ∩ {7, 14, 15, 30, 90} (subconjunto de Customer del catálogo único; sin 60; un plan sin `features.customer` —Starter— responde **402** `features.customer` en todo el módulo; un horizonte fuera del plan: **402**
`plan_limit_exceeded`). Toda respuesta de resultados lleva `envelope` (ADR-V2-03): `{tenant_id, run_id, core:'customer', run_version, status, as_of, horizon_start, horizon_end,
target_definition, entity_keys, unit:'product_unit', currency, result_type, model_version, data_snapshot_id, config_version, assumptions[], limitations[], quality_status:
ok|degraded|insufficient_data, uncertainty_source:'empirical_holdout'}`. Sin corrida completada: 200 con `data` vacío y `envelope.quality_status = insufficient_data` (abstención explícita).

| Método | Ruta | Detalle |
|---|---|---|
| POST | `/customer/runs` `forecast.run` | `{operation: train|predict, horizons?: [7,14,15,30,90], as_of?: AAAA-MM-DD}` → **202** `CustomerRun`. `train`: entrena y evalúa (`as_of` = corte retroactivo anterior a hoy ⇒ `run_type = historical`, no se promueve). `predict`: recalcula variables a la fecha con los modelos en producción (`as_of` por defecto hoy; anterior a hoy ⇒ `historical`). **Solo una corrida `production` puede ser vigente (`is_current`) por operación; una `historical` nunca cambia `is_current` de otra ni se vuelve vigente, y las lecturas sin `run_id` (`/customer/predictions`, `/customer/evaluation`, perfil, conciliación) nunca sirven una retroactiva** (solo con `run_id` explícito). La misma regla vale para plan de abastecimiento, pronóstico de costos y demanda. Crea `core_runs` (core `customer`) + `training_jobs` (`job_type = customer_train|customer_predict`) y despacha `RunCustomerTraining`/`RunCustomerPrediction` a la cola `forecasting`. 409 `customer_run_in_progress` (una activa por operación); 402 plan; 422 validación; 429 |
| GET | `/customer/runs?operation&status`, `/customer/runs/{id}` | `CustomerRun = {id, operation, version, run_type: production|historical, status: queued|running|completed|failed, quality_status, result_type, triggered_by, as_of, horizons, is_current, config_version, data_snapshot_id, model_versions, summary, assumptions[], limitations[], error_message, started_at, completed_at, created_at, envelope, training_job}`. Pagina; otra empresa ⇒ 404 |
| GET | `/customer/predictions?horizon&customer_id&product_id&min_probability&order_by=probability|expected_quantity|expected_amount|quantity_if_purchase&direction&run_id&per_page&page` | corrida de predicción vigente (o `run_id`). Fila: `{customer:{id,code}, product:{id,sku,name,unit}, horizon_days, as_of, purchase_probability, calibration_status: calibrated|degraded|uncalibrated, probability_coherence_adjusted, quantity_if_purchase, quantity_interval:{lo,hi,level:0.8,method}, expected_quantity, committed_quantity, incremental_quantity, total_view_quantity, expected_amount:{value,currency,assumed_unit_price,basis}, median_interval_days, days_since_last_purchase, n_purchases, model:{family,role: primary|fallback}, quality_flags[]}` + `envelope` + `ranking_source` (fuente del ranking del horizonte) + `definitions`. 422 `horizon_not_available` (la corrida se abstuvo de ese horizonte); `customer_id`/`product_id` de otra empresa ⇒ 422 |
| GET | `/customer/customers/{id}/profile?horizon` | `{customer, status: predicted|no_individual_prediction|no_candidate_relations|no_prediction_run, fallback, as_of, activity:{last_purchase_date, days_since_last_purchase, n_purchase_days, purchase_frequency_per_30d, median_interval_days, mean_interval_days, inactivity:{status: active|at_risk|inactive_by_pattern|insufficient_history, risk, interval_p75_days, interval_p90_days, n_intervals, definition}, behavior_change:{status: increase|decrease|stable|insufficient_history, frequency_ratio, p_value, spend_ratio}}, rankings:{"7":[{rank, ranking_source, ranking_score, product, purchase_probability, expected_quantity, expected_amount}], …}, ranking_sources:{"7":"popularity", …}, ranking_source_note}`. `ranking_source` ∈ `model|popularity|recency`: fuente decidida en el holdout (regla `cust-ranking-v1`, ADR-P2-01; popularidad por defecto). Genérico o sin historial ⇒ `no_individual_prediction` + `fallback` (residual de la reconciliación). 404 si no es de la empresa |
| GET | `/customer/evaluation?horizon&run_id` | entrenamiento vigente (o `run_id`): `{run_id, as_of, manifest:{protocol_version, labels_mature_through, final_holdout_policy, horizons:{H:{status, tier, blocks:{train,calibration,selection,holdout:{cutoff_from,cutoff_to,label_window_from,label_window_to,n_cutoffs}}, block_rows, block_positives, promotion, …}}, coherence}, metrics:{"h30:probability":[{model_family, evaluation_type: holdout|selection, is_baseline, is_selected, n_obs, mae, bias, wape, rmse, metrics:{brier, log_loss, pr_auc, ece, calibration_curve:[{n, mean_predicted, observed_rate, observed_lo95, observed_hi95}]}, extra}], "h30:quantity_if_purchase", "h30:expected_quantity", "h30:expected_amount", "h30:ranking", "coherence", "inactivity", "frequency"}, models, summary, notes[]}`. Solo horizontes del plan |
| POST | `/models/customer/rollback` `forecast.run` (fuera del prefijo `/customer`) | `{model_registry_id}` → 200 `{restored:{id,version,model_family,role,status}, slot, horizon_days, objective, retired:[{id,version,model_family}], artifact_sha256_verified: true|null, artifact_verification:{verified, status: verified|partially_verified|not_verifiable|no_artifacts, registered, checked, reason: artifacts_dir_not_configured|artifacts_dir_not_accessible|artifact_files_not_found|null, message}, ranking_source, note}`. Re-sirve una versión `validated|retired` del core `customer` y retira la de producción **del mismo slot** (`hyperparameters.slot`); auditoría `models.customer_rolled_back`. 404 (otra empresa/otro core/inexistente), 402 (horizonte fuera del plan), 409: `rollback_already_current`, `rollback_target_not_restorable`, `rollback_target_without_slot`, `rollback_target_without_artifact`, `rollback_target_incompatible`, `rollback_artifact_mismatch`; 422 validación |
| GET | `/customer/reconciliation?horizon&status=ok|inconsistent|aggregate_unavailable|aggregate_incomplete&product_id&run_id` | por producto: `{product, status, severity, aggregate_quantity, aggregate_days, buyers_total_quantity, buyers_committed_quantity, buyers_incremental_quantity, buyers_expected_quantity, n_buyers, committed_outside_model, residual_quantity, residual_share, excess_quantity, excess_ratio}` + `summary.status_counts` + `policy`. Inconsistentes primero |

Tablas que lee Laravel (las escribe el motor): `customer_predictions`, `customer_product_rankings` (`ranking_source`, `ranking_score`), `customer_activity`, `customer_reconciliations` (migraciones `2026_02_03_000100` y `2026_02_03_000200`).
Programación: `predictily:customer-predict` (diaria 04:20, solo empresas con modelo en producción; **no** espera ventas nuevas) y `predictily:customer-train` (domingos 02:40, omite si no hay ventas
nuevas o hay un entrenamiento de menos de 7 días); `settings.customer_schedule = manual` las desactiva.

---

### 3.16 Decisiones comunes · abastecimiento, costos y escenarios (etapa 2 · P4) — detalle en `docs/architecture/v2/P4-decisiones.md`
Prefijo `/supply` [T]. Habilidades: `forecast.view` (leer), `forecast.run` (calcular), `catalog.manage` (configuración).
Las recomendaciones son sugerencias: **no envían pedidos ni comprometen fondos**. Los resultados llevan la envoltura común
`{"data": …, "envelope": {tenant_id, run_id, core, as_of, horizon_start, horizon_end, target_definition, entity_keys, unit,
currency, result_type, model_version, data_snapshot_id, config_version, assumptions, limitations, quality_status,
uncertainty_source}}`; `status` (técnico) ≠ `quality_status`.

| Método | Ruta | Detalle |
|---|---|---|
| POST | `/supply/plans` [T] `forecast.run` | 202 → `core_run` (`core=supply`, `operation=plan`) + job en `forecasting`. Body: `objective` (`min_cost`\|`max_margin`), `coverage_mode` (`soft`\|`hard`), `forecast_run_id?`, `horizon_days?`, `period_days?`, `undated_receipt_policy?`, `overdue_receipt_policy?`, `priorities{product_id:peso}`, `shortage_penalties{product_id:valor}`, `config{holding_cost_rate_annual, shortage_penalty_multiplier (>1), safety_stock_weight (<1), solver_time_limit_seconds, solver_mip_gap, use_implicit_offers, diagnose_unmet}`. 409 `supply_run_in_progress` si hay un plan activo |
| GET | `/supply/plans`, `/supply/plans/{id}` [T] `forecast.view` | corridas; el detalle devuelve `plan` (`solver_status`: `optimal`\|`feasible_with_gap`\|`time_limit`\|`infeasible`\|`heuristic_fallback`\|`not_solved`, `mip_gap`, costos, `unmet_units_total` y la **fecha de corte**: `as_of` = datos hasta ese día, `today` = hoy en la zona horaria de la empresa, `timezone`, `as_of_age_days` = `today − as_of`, `null` si no hay corte), `lines_summary` y `shortfalls_summary` (`total`, `shown`, `matched`, `truncated`; además `actionable_now` en líneas y `unmet_units`/`demand_units` en faltantes, sumados en la base de datos), `branches[]` (sucursales con líneas o faltantes, para filtrar), una **vista previa** acotada (primeras 20) de `lines[]` y `shortfalls[]` (el detalle ya no devuelve las listas completas: un plan real tiene miles de líneas; `?actionable_now=1&product_id&supplier_id` filtran la vista previa) y `constraints[]` (uso vs límite), `diagnosis`, `infeasibility`, `alternative_plan`, `offers[]` (comparables/excluidas con motivo), `comparison_coverage`, `offer_diagnostics`, `excluded_nodes`, `production` (`not_configured` sin recetas), `periods[]`, `balance` (**opt-in**: `null` = no solicitado por defecto, porque el balance por nodo y período del motor pesa cientos de KB; con `?include=balance` —lista separada por comas, lista blanca; otro valor → 422— es la lista `balance[]`) y `envelope` con `result_type=optimized_plan`. **`actionable_now` de una línea = «su `order_date` es la fecha de corte `as_of`»**, no «hoy»: solo equivale a «emitir hoy» cuando `as_of_age_days` es 0; si es mayor, el plan se calculó con datos anteriores a hoy y conviene regenerarlo |
| GET | `/supply/plans/{id}/lines` [T] `forecast.view` | líneas del plan **paginadas en el servidor** (`{data, links, meta}` estándar; `page`, `per_page` 1–100, por defecto 25). Filtros: `branch_id`, `product_id`, `supplier_id`, `search` (SKU o nombre, sin distinguir mayúsculas), `actionable_now`. `sort` por lista blanca (`order_date`, `expected_receipt_date`, `product`, `branch`, `supplier`, `quantity`, `unit_cost`, `line_cost`; prefijo `-` = descendente; otro valor → 422; desempate por `id`); sin `sort`, primero lo emitible en la fecha de corte y luego por fecha de emisión. Cada línea: proveedor, cantidad en unidad del producto y de la oferta, `order_date` = fecha sugerida de emisión, `expected_receipt_date`, `unit_cost`, `line_cost`, `actionable_now`, `binding_constraints[]`, `justification`. 404 si el plan no existe o es de otra empresa; una corrida sin plan devuelve una página vacía |
| GET | `/supply/plans/{id}/shortfalls` [T] `forecast.view` | demanda no atendida paginada en el servidor (mismo formato). Filtros: `branch_id`, `product_id`, `search`, `reason`. `sort` por lista blanca (`period_start`, `product`, `branch`, `demand`, `unmet`, `reason`; prefijo `-`); sin `sort`, por inicio del período. Cada fila: `branch`, `product`, `period_start`, `period_end`, `demand`, `unmet`, `reason` |
| GET/POST/PATCH/DELETE | `/supply/offers[/{id}]` [T] | ofertas: `supplier_id, product_id, branch_id?, unit_cost, currency, unit` (obligatorios), `min_order_qty?, order_multiple?, lead_time_days?, lead_time_std_days?, capacity_per_period?, capacity_period_days, fixed_order_cost, acquisition_cost_pct, acquisition_cost_per_unit, valid_from?, valid_to?, known_at?, is_active`; lo desconocido es `null`. 422 por clave natural duplicada o referencias de otra empresa. Cada oferta devuelve `comparability` `{comparable, issues[no_unit_conversion\|no_fx_rate], unit_conversion, exchange_rate}` (usa el `UnitConverter` de P3 y las tasas explícitas) |
| GET/POST/PATCH/DELETE | `/supply/exchange-rates`, `/supply/capacities`, `/supply/budgets`, `/supply/production/resources`, `/supply/production/recipes` [T] | tasas (`from_currency,to_currency,rate>0,valid_on,source,known_at?`), capacidad de almacén por sucursal (`capacity, capacity_unit`), presupuesto por período de emisión (`period_start, period_end, amount, currency`), recursos y recetas (`output_quantity, yield_rate∈(0,1], production_days, resource_id?, resource_usage_per_batch, lines[{input_product_id, quantity_per_batch}]`). Lectura `forecast.view`; escritura `catalog.manage` |
| POST, GET | `/supply/cost-forecasts`, `/supply/cost-forecasts/runs` [T] | solicitar (`as_of?`, `historical?`, `null_known_at_policy` `strict`\|`assume_valid_from`) y consultar (`?run_id&product_id&supplier_id&quality_status`): `method`, `reference_method`, `points[{step,target_date,yhat,reference,lo,hi}]`, `metrics{método:{selection,holdout}}`, `availability`, `limitations` |
| POST, GET | `/supply/scenarios/evaluate`, `/supply/scenarios/evaluations[/{id}]` [T] | síncrono (201). Body: `strategies[1..8]{key,name,price_change_pct,discount_pct,cost_change_pct,demand_change_pct (escalar o lista por período),use_cost_forecast,plan_run_id,supply_fill_rate,lead_time_delta_days,extra_supply[]}`, `horizon_days`, `filters`. **Los `*_pct` son fracciones, no porcentajes** (`0.05` = 5 %, `-0.10` = −10 %) y el escalar y **cada elemento** de la lista por período comparten los mismos límites: `price_change_pct` −0.9..5, `discount_pct` 0..0.95, `cost_change_pct` −0.9..5, `demand_change_pct` −0.95..5. Fuera de rango, no numérico u objeto en lugar de lista → **422** con el error en `strategies.N.<campo>` (antes un escalar de −3 se aceptaba y devolvía ingresos negativos); el motor repite la validación como última barrera. (El simulador `/scenarios/simulate` conserva sus límites propios de §3.11.) Respuesta: estrategias comparadas sobre la misma base, `inputs[]` clasificados `observed`\|`forecasted`\|`assumed`, `coherence`, `ranking_by_gross_margin`; `result_type=conditional_simulation` |
| POST | `/supply/causal/check` [T] `forecast.run` | verificación de requisitos causales; siempre `quality_status=insufficient_evidence`, `data.effect=null`, `estimator_enabled=false`; `conditional_simulation.available=true` |
| POST, GET | `/supply/simulations`, `/supply/simulations/{id}` [T] | simulación histórica de operación (202, asíncrona): `days`, `review_days`, `as_of`, `seed`; resultado por política (`current`, `reference`, `planner`) con servicio, no atendido, sobrestock, días de inventario, costos y capital; `result_type=evaluation` |

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
| GET | `/v1/capabilities` | modelos disponibles, versión del feature set, horizontes (catálogo 7/14/15/30/60/90; `horizon_catalog`), cuantiles (P10=`lo_80`, P90=`hi_80`, P50 solo si se evaluó), métricas y estados de modelo |

### 4.1 `POST /v1/forecast-runs/{run_id}/execute`
Petición:
```json
{"tenant_id": 3, "training_job_id": 41,
 "horizons": [7, 14, 15, 30, 60, 90],
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
6. Entrena el modelo final (si falla el ganador sirve el respaldo y todo lo demás se calcula para el
   modelo servido), pronostica `max(horizons)` días, calcula intervalos 80/95, reconcilia (bottom-up) y
   escribe `forecast_values` para niveles `series, product, category, branch, branch_category, total` en
   granularidad `day` (y `week`/`month` para niveles agregados) y `horizon` (totales de 7/14/15/30/60/90 días
   solicitados). Cada fila lleva `quality_status` (respaldo del horizonte) y, en series, `case_label` y
   `p50` (solo si se publicó). Horizontes sin respaldo: filas sin intervalo y `insufficient_data`.
7. Escribe `model_registry`, `model_evaluations`, `recommendations` y `alerts`
   (`stockout_risk`), `data_quality_reports` (scope `forecasting_readiness`, con `forecast_run_id`).
   En `model_evaluations`, `evaluation_type='backtest'` son las métricas principales: las del
   período final reservado cuando existe (`extra.evaluation_scope='holdout'`);
   `evaluation_type='selection_windows'` son las ventanas que eligieron el modelo (secundarias,
   optimistas). Ver `docs/04` §4–§5.
8. Marca run `completed` (y `is_current=true` si `run_type='production'`, desmarcando el anterior en
   la misma transacción, y pasando a `retired` los modelos de producción anteriores) y job `succeeded` con
   `result`. Escribe además `config_version`, `effective_config`, `data_snapshot_id` (tabla `data_snapshots`)
   y `evaluation_manifest`.

Etapa 2 · P1 (resumen; detalle en `docs/architecture/v2/P1-demanda.md`): `horizons` ⊂ {7,14,15,30,60,90}
(otro valor ⇒ 422 de validación); antes de cargar, el motor resuelve la configuración efectiva
(`demand`: core → perfil sectorial → `tenants.settings.cores.demand`; clave desconocida ⇒ 422
`invalid_configuration`, run `failed`) y la cobertura de ventas (`data_coverage`): el corte no supera el
menor `complete_through` efectivo; sin cobertura declarada se añade la limitación `coverage_undeclared`.
`summary` añade `quality_status`, `uncertainty_source`, `horizon_support`, `max_supported_horizon`, `p50`,
`served`, `special_cases`, `coverage`, `limitations[{code,message}]`, `assumptions[]`, `config_version`,
`data_snapshot_hash`.

Respuesta 200:
```json
{"run_id": 12, "status": "completed", "series_count": 135, "cutoff_date": "2026-10-27",
 "selection": {"smooth": {"model_family": "lightgbm_global", "model_registry_id": 77}, "intermittent": {...}, "fallback": {"model_family": "seasonal_naive", "model_registry_id": 78}},
 "metrics": {"selected_wape": 0.23, "baseline_wape": 0.31},
 "warnings": ["12 series con historia escasa o menos de 28 días usan respaldo etiquetado"],
 "duration_ms": 48211, "quality_status": "ok",
 "horizon_support": {"7": "ok", "14": "ok", "15": "ok", "30": "ok", "60": "ok", "90": "ok"}}
```
Errores: 404 run/tenant no coinciden · 409 run en ejecución por otro proceso · 422 configuración
inválida (`{"code":"invalid_configuration"}`) · 422 datos insuficientes (`{"code":"insufficient_data", "message": "...", "details": {...}}`; el motor
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
(deduplicada por `dedup_key`). Respuesta `{"evaluated_runs": [...], "drift_detected": bool, "realized_wape": 0.27}`
más, de forma **aditiva** (ADR-V25-03), `exclusions`.

Días excluidos de las métricas realizadas y de la detección de deriva (`exclusions`, aditivo; los consumidores existentes lo ignoran). Un
día sin ventas NO es siempre un cero: los días-serie del pronóstico que la fuente no cubre o en que la sucursal cerró no son ceros observados,
se marcan censurados (`forecast_actuals.is_censored`) y no entran a las métricas realizadas ni al drift, así que una caída de la integración no se
lee como un deterioro del pronóstico. Se clasifican con la misma función del entrenamiento (`profiling.days.classify_days`) y con el conocimiento
más reciente de las declaraciones. `exclusions` es un objeto de conteos enteros por clase, presente en cada elemento de `evaluated_runs[]`
(`evaluated_runs[].exclusions`, de ese run) y en la raíz de la respuesta (`exclusions`, suma de los runs evaluados):

| Clave | Significado |
|---|---|
| `series_days` | series-día del pronóstico comparadas con la realidad (nivel serie) |
| `evaluated_series_days` | series-día que SÍ entran a las métricas (no censuradas) |
| `missing_source_days` | excluidos por `missing_source`: fuera de la cobertura declarada de la fuente (`data_coverage`), dentro de un hueco declarado o inferido (`data_coverage_gaps`) o inferidos por la regla de actividad de la sucursal |
| `missing_source_inferred_days` | subconjunto de `missing_source_days` cuya base es `inferred` (regla, no declaración) |
| `closed_days` | excluidos por cierre: `calendar_events.category = 'closure'` de alcance empresa o sucursal |
| `stockout_days` | excluidos por quiebre de stock (la lógica previa a ADR-V25-03) |

Los cuatro conteos de exclusión son disjuntos entre sí salvo `missing_source_inferred_days` (contenido en `missing_source_days`); `series_days = evaluated_series_days + stockout_days + missing_source_days + closed_days`.
Los mismos conteos se guardan en `model_evaluations.extra.exclusions` de la evaluación `realized` de cada run.

### 4.4 Estados y valores permitidos que el motor escribe
- `training_jobs.status`: queued, running, succeeded, failed · `stage`: ver migración.
- `forecast_runs.status`: queued, running, completed, failed.
- `model_registry.status` (ADR-V2-07): candidate, validated, production, retired, failed; `role`: primary |
  fallback; `core`: demand. (Etapa 1: fallback = production + role fallback, archived = retired,
  rejected = validated; la API deriva `legacy_status`.)
- `model_evaluations.level` (demanda): overall, demand_class, horizon (tramos h1-7, h8-14, h15-30, h31-60,
  h61-90), horizon_total (H7…H90), total, branch, category, product, series; `metrics` jsonb con MASE/RMSSE,
  pinball, amplitud, dinero y muestra (`docs/04` §5).
- `forecast_values`: `p50`, `quality_status` (ok | degraded | insufficient_data), `case_label` (migración
  `2026_02_05_000100`); `model_family` puede ser `category_benchmark` o `declared_zero` en series con caso
  especial.
- `model_registry.model_family`: naive, seasonal_naive, window_average, auto_ets, croston_sba,
  adida, tsb, lightgbm_global, timesfm.
- `forecast_values.level`: total, branch, category, branch_category, product, series;
  `node_key` según la tabla del §3.8.
- `recommendations.type`: reorder, stockout_risk, overstock, no_action · `risk_level`: low,
  medium, high, critical.
- `alerts.type` (motor): stockout_risk, forecast_drift, data_quality, run_completed.

### 4.5 Rutas de plataforma y datos del motor (etapa 2 · P3)
| Método | Ruta | Uso |
|---|---|---|
| GET | `/v1/platform/effective-config?tenant_id&core` | configuración efectiva (core → perfil → empresa) con procedencia por clave y `config_version`; 422 `invalid_config` (`errors[]`) / `unknown_core`; 404 empresa |
| POST | `/v1/platform/validate-config` | `{tenant_id, core, overrides}` validación en seco |
| GET | `/v1/platform/profiles`, `/v1/platform/tenants/{tenant_id}/extensions` | perfiles registrados / proveedores de la empresa |
| GET | `/v1/data-core/calendar?tenant_id&start&end[&as_of]` | eventos de calendario (empresa + público); con `as_of`, solo lo conocido |
| GET | `/v1/data-core/coverage?tenant_id[&entity&branch_id]` | cobertura efectiva de las fuentes |

### 4.6 Rutas del Customer Core del motor (etapa 2 · P2)
| Método | Ruta | Uso |
|---|---|---|
| POST | `/v1/customer/runs/{run_id}/execute` | cuerpo `{tenant_id, training_job_id?}`. Ejecuta la corrida `core_runs` (core `customer`) según su `operation` (`train` | `predict`), `horizons` y `as_of`. Idempotente: completada ⇒ devuelve el resumen; en ejecución reciente ⇒ **409**; vencida ⇒ se retoma reemplazando resultados parciales; otra empresa ⇒ **404**; error ⇒ `failed`. Respuesta `{run_id, operation, status, quality_status, as_of, horizons, summary, duration_ms}` |
| GET | `/v1/customer/capabilities` | horizontes, operaciones, versiones del esquema de variables/etiquetas, definición de objetivos, familias de modelos y calibradores |

Valores que el motor escribe (Customer Core): `core_runs.result_type` `evaluation` (train) | `forecast` (predict); `quality_status` ok | degraded | insufficient_data; `model_registry.core = 'customer'`,
`scope = 'tenant'`, `hyperparameters.slot = "h{H}:probability|quantity"`, `kind` reference | complex, `model_family` `customer_{probability|quantity}_{reference|logistic|lgbm_small|lgbm_large|l2|l2_log1p|tweedie|gamma|poisson}`;
`model_evaluations.core = 'customer'` con `level_key` `h{H}:probability|quantity_if_purchase|expected_quantity|expected_amount|ranking` (`extra.selection` en `ranking` = decisión `cust-ranking-v1`), `coherence`, `inactivity`, `frequency` y `metrics` jsonb;
`alerts.type` (Laravel) `customer_run_failed`. Artefactos: `ENGINE_ARTIFACTS_DIR/{tenant}/customer/run-{id}/h{H}_{target}_{familia}.joblib` (SHA-256 en el registro).

---

### 4.7 Rutas de decisiones comunes del motor (etapa 2 · P4)
Bearer interno. `POST /v1/supply/runs/{run_id}/execute` `{tenant_id, training_job_id?}` ejecuta una corrida creada por Laravel en
`core_runs` según `(core, operation)`: `supply/plan`, `supply/simulate`, `cost/forecast`, `scenario/evaluate`. Es idempotente
(una corrida completada se devuelve sin recalcular; el plan, sus líneas y faltantes se reescriben por corrida) y 404 si la
corrida es de otra empresa. `POST /v1/supply/causal/check` `{tenant_id, request}` devuelve la envoltura con
`insufficient_evidence` (sin persistir). `GET /v1/supply/capabilities` lista versiones y estados del solver. Escribe
`supply_plans`, `supply_plan_lines`, `supply_plan_shortfalls`, `cost_forecasts`, `scenario_evaluations`, `data_snapshots`,
`core_runs` y `training_jobs`.

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
| `SyncDataSource` | imports | etapa 2: extrae la consulta autorizada de una fuente SQL de solo lectura y la importa por el mismo pipeline (`tries=1`, empresa explícita); programada por `predictily:sync-sources` |
| `RunCustomerTraining` / `RunCustomerPrediction` | forecasting | etapa 2 (P2): llaman `POST /v1/customer/runs/{id}/execute`; `tries=2`, backoff 60/180 s, `timeout=ENGINE_TIMEOUT+60`; idempotentes (una corrida completada no se reejecuta); no reintentan datos insuficientes (422) ni 401/404; si fallan marcan `core_runs` y `training_jobs` como `failed` y levantan la alerta `customer_run_failed` |

Programación (`routes/console.php`): diaria 02:00 America/Guayaquil `predictily:evaluate-actuals`;
según `settings.forecast_schedule` (daily/weekly) `predictily:schedule-forecasts`; diaria
`predictily:purge` (retención y eliminación diferida). Etapa 2 · P2: `predictily:customer-predict` (diaria 04:20) y `predictily:customer-train` (domingos 02:40), ambas en America/Guayaquil. El entrenamiento **no** se dispara por cada
factura: solo por programación, por acción del usuario o al completar una importación inicial.
