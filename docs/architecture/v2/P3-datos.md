# P3 · Datos e integración (etapa 2)

Paquete P3 del plan de la etapa 2 (`docs/architecture/PREDICTILY_V2_GAP_ANALYSIS.md` §5). Cubre `instrucctions.md`
§6.C (core de Datos), §6.H (personalización desacoplada) y la parte de datos de §6.I. Decisiones aplicadas:
ADR-V2-04 (huellas), ADR-V2-05 (completitud de fuentes), ADR-V2-06 (configuración por precedencia) y ADR-V2-13
(conectores SQL).

Todo dato de ejemplo de este documento y de las pruebas es **ficticio**. Nada de lo descrito aquí se ha validado con datos
reales de un cliente ni con el ERP de un cliente (pendiente de acceso autorizado, ver §13).

---

## 1. Estado de las capacidades

| Capacidad | Estado | Evidencia |
|---|---|---|
| Conector PostgreSQL de solo lectura | **Probado de punta a punta contra una base PostgreSQL de pruebas** (no contra el ERP de un cliente) | `SqlSourceSyncTest` (E2-25) |
| Conector MySQL/MariaDB | **`implemented_untested`**: implementado, sin servidor de pruebas en este entorno | `SqlConnectorContractTest` (contrato común + prueba de servidor omitida) |
| Conector SQL Server | **`implemented_untested`**: implementado, sin servidor ni `pdo_sqlsrv` en este entorno | ídem |
| Validador de consultas de solo lectura | Probado (dialectos PostgreSQL, MySQL, SQL Server) | `SqlQueryGuardTest` (14 aceptadas y 41 rechazadas + invariantes) |
| Importación de compromisos, calendario, conversiones de unidades y almacenes (CSV/Excel/SQL/API) | Implementado y probado | `CommitmentsImportTest`, `CalendarEventsTest`, `CanonicalDataTest` |
| Cobertura de fuentes (`data_coverage`) declarada y por conector | Implementado y probado | `CanonicalDataTest`, `SqlSourceSyncTest`, `test_platform_effective_config.py` |
| Reporte de calidad extendido | Implementado y probado | `CanonicalDataTest::test_extended_quality_report_…` |
| Tres fechas (evento / disponibilidad en la fuente / ingestión) | Implementado en ventas, productos (precio/costo), compromisos, calendario | ver §3 |
| Configuración por precedencia y extensiones (2 perfiles sintéticos + empresa sin perfil) | Implementado y probado | `test_platform_extensions.py`, `test_platform_effective_config.py`, `EffectiveConfigApiTest` |
| Aislamiento de datos/extensiones (E2-17, parte datos) | Probado | `DataCoreIsolationTest` y las pruebas por entidad |
| Lectura de ERP reales (Soyoda, Contífico, …) | **No implementado / no probado** | `generic_odbc` y `contifico` siguen `planned` / `requires_credentials` |

Los perfiles `synthetic_retail_a` y `synthetic_process_b` son **sintéticos**: demuestran que dos negocios distintos usan las
mismas interfaces. No son entregables de rubro.

---

## 2. Contratos canónicos

Convenciones comunes (se aplican a todas las entidades salvo que se indique otra cosa):

- **Identidad**: siempre `(tenant_id, clave natural)`. Toda consulta filtra `tenant_id`; las FK entre tablas son compuestas
  `(tenant_id, x_id)`, de modo que la base de datos impide referenciar filas de otra empresa.
- **Zona horaria**: las fechas-hora se guardan en `timestamptz` (UTC). Una fecha-hora sin zona en un archivo o en una fuente
  SQL se interpreta en la zona de la empresa (`tenants.timezone`, por defecto `America/Guayaquil`) o, para fuentes SQL, en
  `source_timezone` y se convierte a la de la empresa. Las fechas puras (`date`) no se desplazan.
- **Moneda**: una sola por empresa (`tenants.currency`); otra moneda ⇒ error `currency_mismatch`.
- **Nulidad**: un valor desconocido queda `NULL` y nunca se sustituye por cero ni por la fecha de la carga. Los campos
  `known_at` / `source_recorded_at` / `price_known_at` / `cost_known_at` `NULL` significan «la fuente no declaró cuándo lo conocía».
- **Idempotencia**: reimportar el mismo contenido no cambia nada; un contenido distinto actualiza (y, donde se indica, agrega una revisión).
- **Cardinalidad**: se indica por entidad.

### 2.1 Ventas (`sales`) — campos nuevos

| Campo | Req. | Tipo | Regla |
|---|---|---|---|
| `source_recorded_at` | no | fecha-hora | Cuándo el sistema de origen registró o modificó el documento. Se guarda en `sales.source_recorded_at`. Inválida o futura ⇒ advertencia y `NULL`. **No** forma parte de `source_hash`: reimportar contenido idéntico no la cambia; un cambio de contenido adopta la nueva. También lo acepta `POST /sales/batch` (`documents[].source_recorded_at`). |

`customers.is_generic` se marca `true` al crear clientes de tipo consumidor final (`9999999999999`/`9999999999`): no representan
un comprador recurrente.

### 2.2 Productos (`products`) — campos nuevos

| Campo | Req. | Tipo | Regla |
|---|---|---|---|
| `price_known_at` | no | fecha-hora | Desde cuándo la fuente conocía el precio vigente. Se copia a `product_price_history.known_at` cuando el precio cambia. |
| `cost_known_at` | no | fecha-hora | Ídem para el costo (`purchase_cost_history.known_at`). El costo queda además expresado en la unidad del producto (`purchase_cost_history.unit`). |

Vacío ⇒ `NULL` (desconocido). Una fecha futura o inválida es un error.

### 2.3 Compromisos comerciales (`entity_type = commitments`)

Cotización (`quote`), pedido confirmado (`order`) y contrato (`contract`). **No son ventas realizadas** y no se suman a la
demanda; son una vista separada con su estado, fechas y cantidades pendientes.

- **Granularidad de archivo**: una fila por línea. **Identidad**: `(commitment_type, external_id)`; líneas por `(compromiso, line_number)`.
- **Cardinalidad**: un compromiso → N líneas; cada línea → 1 producto; cliente y sucursal opcionales.
- **Versiones**: `commercial_commitment_revisions` guarda una revisión por cada alta o cambio de contenido (snapshot de cabecera y
  líneas, `known_at`, `recorded_at` = ingestión). La cancelación por API agrega otra.

| Campo | Req. | Tipo | Regla |
|---|---|---|---|
| `commitment_type` | sí | texto | `quote`/`order`/`contract` (sinónimos: cotización, proforma, pedido, orden de venta, contrato, convenio). |
| `external_id` | sí | texto | Número en el sistema de origen. |
| `status` | no | texto | `open`, `confirmed`, `partially_fulfilled`, `fulfilled`, `cancelled`, `expired` (o equivalentes en español). Si falta se deriva: con `cancelled_at` ⇒ cancelado; cumplido ≥ comprometido ⇒ cumplido; algo cumplido ⇒ parcial; si no, `open` (cotización) o `confirmed`. |
| `issued_at` | sí | fecha-hora | Emisión. No puede ser futura. |
| `known_at` | no | fecha-hora | Cuándo la fuente conocía **esta versión**. `NULL` = desconocido. No puede ser futura. |
| `valid_from`, `valid_to`, `expected_delivery_date`, `delivery_date` (línea) | no | fecha | `valid_to ≥ valid_from`. |
| `cancelled_at` | no | fecha-hora | Cancelación. Con fecha ⇒ estado cancelado (se avisa si el estado original difería). Estado cancelado **sin** fecha ⇒ advertencia `cancelled_without_date`: fecha desconocida. |
| `branch_code`, `customer_code` | no | texto | Sucursal existente; cliente por el mismo mecanismo de ventas (cédula/RUC solo como HMAC). |
| `currency` | no | texto | Debe ser la de la empresa. |
| `line_number` | no | entero | Si falta, orden de aparición. Repetido ⇒ `duplicate_in_file`. |
| `product_sku` | sí | texto | Se crea el producto si `create_missing_products`. |
| `quantity` | sí | número | > 0, en `unit` (o en la unidad del producto). |
| `quantity_fulfilled` | no | número | ≥ 0 (por defecto 0). Mayor que `quantity` ⇒ advertencia; el pendiente se toma como 0. |
| `unit` | no | texto | Si difiere de la unidad del producto exige una conversión **declarada** (§2.5); si no, error `unit_conversion_missing`. Con conversión: `quantity`, `quantity_fulfilled` y `unit_price` se convierten y se conserva el original en `source_quantity`/`source_unit`/`conversion_factor`. |
| `unit_price` | no | número | Precio por la unidad indicada (se convierte a precio por unidad del producto). |

**Reglas de estado a un corte** (`GET /commitments?as_of=…`, `App\Services\DataCore\Commitments\CommitmentAsOf`; las debe usar
cualquier consumidor, p. ej. Customer Core):

1. Versiones visibles = revisiones con `known_at` **no nulo** y `≤ corte`. Sin ninguna, el compromiso **no existe** para ese corte.
2. Se usa la última versión visible (cabecera, líneas y cumplido tal como se conocían).
3. Cancelado al corte si `cancelled_at ≤ corte`. Estado cancelado sin fecha ⇒ bandera `cancellation_date_unknown`, pendiente 0.
4. Pendiente por línea = `max(0, cantidad − cumplida)`; 0 si está cancelado.
5. Un compromiso nunca se suma a la demanda probabilística: lo reconcilia el consumidor (P2) con estas cantidades y estado.

### 2.4 Calendario (`entity_type = calendar_events`, tabla `calendar_events`)

- **Granularidad**: un evento por rango de fechas. **Identidad**: `(tenant | público, scope, scope_ref, code, starts_on)` + `revision`.
- **Alcance** (`scope`): `country` (`scope_ref` = ISO-2, p. ej. `EC`), `region` (`sierra`…), `tenant` (toda la empresa, sin referencia),
  `branch` (`branch:{id}`), `category` (`category:{id}`), `product` (`product:{id}`), `sector` (`sector:{slug}`). En archivos y API la
  referencia de sucursal/producto/categoría se escribe por código/SKU/nombre y se guarda normalizada.
- **Calendario público**: `tenant_id` NULL; todas las empresas lo leen; ninguna lo escribe.
- **Categorías**: `holiday`, `local_holiday`, `commercial_season`, `campaign`, `promotion`, `sector_event`, `agricultural_season`,
  `international_peak`, `exceptional`. **Estados**: `planned`, `confirmed`, `cancelled`.
- **Versiones**: un cambio de nombre/categoría/fin/estado agrega una **revisión** (la anterior se conserva). Un cambio solo de
  `known_at` hacia una fecha anterior (o informar uno antes desconocido) actualiza la revisión sin crear otra. Un evento que se
  reprograma (cambia `starts_on`) es otro evento: cancele el anterior y agregue el nuevo.
- `known_at` vacío ⇒ `NULL` + advertencia `known_at_unknown`: **el evento no se usa como conocido a futuro en un corte histórico**
  (`GET /calendar-events?as_of=` y `load_calendar_events(as_of=…)` del motor lo excluyen).

### 2.5 Conversiones de unidades (`entity_type = unit_conversions`)

`cantidad_destino = cantidad_origen × factor`. **Identidad**: `(producto | general, from_unit, to_unit, valid_from)`.

- Normalización **solo tipográfica** (minúsculas, sin acentos, sin punto final). `und` y `unidad` son unidades distintas hasta que
  se declare su conversión (factor 1 incluido).
- Se aceptan el sentido directo y el inverso (`1/factor`); **sin cadenas** A→B→C: cada paso debe declararse.
- Preferencia: producto > general; directo > inverso; la vigente a la fecha (`valid_from ≤ fecha`).
- Errores: origen = destino, factor ≤ 0, producto inexistente, contradicción con el sentido inverso ya declarado
  (`conversion_conflict`), repetidas en el archivo.
- Servicio PHP: `App\Services\DataCore\Units\UnitConverter::resolve($productId, $from, $to, $fecha)` devuelve `null` si no hay
  conversión declarada (el llamador debe tratarlo como error).

### 2.6 Almacenes (`entity_type = warehouses`)

Un almacén por sucursal: `code` (único por empresa), `name`, `branch_code` (existente), `storage_capacity` (≥ 0, vacío = desconocida,
nunca 0) y `capacity_unit` (obligatoria si hay capacidad), `is_active`. Upsert por `(empresa, code sin distinguir mayúsculas)`.

### 2.7 Cobertura de fuentes (`data_coverage`)

`entity` ∈ `sales, inventory, purchases, costs, promotions, customers, commitments, calendar, externals`; por fuente y/o sucursal
(`NULL` = toda la empresa). `complete_from` / `complete_through` = rango en el que la fuente es **completa**. Fuera de él un día sin
registros es **ausente**, no cero (ADR-V2-05).

- **Declarada por el usuario** (`PUT /data-coverage`, `basis = declared`): `complete_through` obligatoria y no futura.
- **Inferida por el conector** (`basis = inferred`) tras cada sincronización, con esta regla:
  1. solo si la sincronización terminó (`completed`) con **cero** filas con error;
  2. solo si el mapeo no la desactiva (`options.coverage.enabled = false`);
  3. `complete_through` = día local de inicio de la sincronización − `lag_days` (por defecto 1: «completa hasta ayer»). Ajuste `lag_days`
     (0–30) al rezago real si la fuente es un almacén alimentado por ETL;
  4. nunca retrocede y nunca pisa una declaración del usuario para la misma fuente;
  5. `complete_from`: en carga **completa**, la menor fecha de evento extraída; una incremental no la modifica.
  Solo aplica a `sales` y `commitments` (las entidades con fecha de evento); el resto solo actualiza `last_ingested_at`.
- **Cobertura efectiva** (`GET /data-coverage` → `effective`; `CoverageService::effective`; `effective_coverage` del motor):
  1. declaración del usuario sin fuente (de la sucursal pedida o de toda la empresa);
  2. si no, **mínimo** de `complete_through` y **máximo** de `complete_from` entre las fuentes (todas deben ser completas);
  3. si no, sin cobertura (`rule: none`): todo día sin registros es ausente.
  `classify_day(cobertura, día, hay_registros)` del motor devuelve `observed` / `zero_observed` / `absent`.

### 2.8 Mapeos autorizados (`source_mappings`) y sincronizaciones (`source_syncs`)

Ver §4. Un mapeo es una consulta/vista **autorizada y versionada** por fuente y entidad; una sincronización es una ejecución de esa consulta.

---

## 3. Las tres fechas

| Noción | Dónde queda | Cuándo es desconocida |
|---|---|---|
| **Evento** (cuándo ocurrió/rige) | `sales.issued_at`/`sale_date`, `commercial_commitments.issued_at`, `calendar_events.starts_on`, `valid_from` | nunca (obligatoria) |
| **Disponibilidad en la fuente** (cuándo la fuente lo conocía) | `sales.source_recorded_at`, `commercial_commitments.known_at` (+ revisiones), `calendar_events.known_at`, `product_price_history.known_at`, `purchase_cost_history.known_at` | `NULL`: el archivo/consulta no la trae. **No se reconstruye.** |
| **Ingestión** en Predictily | `created_at`/`updated_at`, `commercial_commitment_revisions.recorded_at`, `import_jobs`, `source_syncs` | siempre conocida |

Una carga histórica actual **no demuestra** que algo se conocía antes: por eso `known_at` jamás se rellena con la fecha de la carga.
Consecuencia para los consumidores: lo que tiene `known_at` `NULL` **no puede usarse como conocido a futuro de un corte pasado**; la
variable que lo requiera debe excluirse del backtest y declararse la limitación (`instrucctions.md` §6.C). Limitaciones conocidas:
no hay historial de versiones de ventas (solo la actual + `source_recorded_at`); la cantidad de un compromiso sí se versiona; el inventario
(`inventory_snapshots`) solo tiene la fecha del corte (evento) y la ingestión: la disponibilidad en la fuente queda **desconocida** y no se declara.

---

## 4. Conectores SQL de solo lectura (ADR-V2-13)

### 4.1 Catálogo (`GET /connectors`)

| `key` | `status` | `verification` | Notas |
|---|---|---|---|
| `sql_postgresql` | `requires_credentials` | `tested_with_test_database` | Transacción `REPEATABLE READ READ ONLY`, `statement_timeout`, lectura por cursor del servidor. |
| `sql_mysql` (MySQL y MariaDB) | `implemented_untested` | `implemented_untested` | `START TRANSACTION READ ONLY`, `max_execution_time` (MySQL) / `max_statement_time` (MariaDB), lectura sin búfer. |
| `sql_sqlserver` | `implemented_untested` | `implemented_untested` | Requiere `pdo_sqlsrv`. **No existe modo de solo lectura por transacción**: la garantía es el validador + una cuenta `db_datareader`. |
| `generic_odbc` | `planned` | `planned` | Sin cambios. |

Los estados son: `available` (funciona sin configuración externa), `requires_credentials`, **`implemented_untested`** (nuevo: implementado
pero no comprobado contra un servidor real; nunca se presenta como disponible) y `planned`. `verification` dice qué se comprobó de verdad:
`tested`, `tested_with_test_database`, `implemented_untested`, `not_verified`, `planned`.

### 4.2 Fuente, credenciales y prueba de conexión

`POST /data-sources` con `type: connector`, `connector_key: sql_postgresql|sql_mysql|sql_sqlserver`:

- `config` (no secreta): `host`, `port`, `database`, `sslmode` (`prefer` por defecto; use `require`/`verify-full` en producción),
  `source_timezone`, `connect_timeout_seconds`, `statement_timeout_seconds`, `sync_schedule` (`manual`|`hourly`|`daily`); SQL Server además
  `encrypt`, `trust_server_certificate`; MySQL `charset`. Claves desconocidas o secretos dentro de `config` ⇒ 422.
- `credentials` (`username`, `password`): cifradas en `data_sources.credentials_encrypted` (cast `encrypted:array`, `APP_KEY`). **Nunca**
  se devuelven por API (solo `has_credentials`), ni aparecen en errores, `last_error` o registros: los mensajes de PDO se sanean.
- `POST /data-sources/{id}/test` abre la conexión de solo lectura y devuelve versión del servidor y **advertencias** (p. ej. «la cuenta puede
  escribir»). Si falta el controlador PDO lo dice (`ok: false`), no falla.
- Política de destino (`config/connectors.php`): siempre se bloquean direcciones de metadatos de nube (169.254.0.0/16), `0.0.0.0` y multicast;
  `PREDICTILY_SQL_DENY_PRIVATE=true` bloquea además redes privadas/loopback y `PREDICTILY_SQL_ALLOWED_HOSTS` fija una lista blanca (recomendado
  en un SaaS público). No cubre DNS rebinding.

### 4.3 Consultas autorizadas y política de solo lectura

`POST /data-sources/{id}/mappings` `{entity, query, field_map, options}` (ability `datasources.manage` en **todas** las rutas de mapeo):

- Una versión nueva (`version` + 1) **nace sin aprobar**; `POST …/approve` la activa (y desactiva la anterior de esa entidad); solo la versión
  aprobada y activa sincroniza. Cambiar consulta/mapeo/opciones siempre crea otra versión. `POST …/preview` muestra los primeros campos
  canónicos (con `customer_code` enmascarado) sin importar nada.
- `entity` ∈ `sales, products, inventory, commitments, branches, suppliers, lead_times, promotions, calendar_events, unit_conversions,
  warehouses`. `customers` y `costs` no tienen importador propio (los clientes salen de las ventas) ⇒ 422.
- `field_map`: `{campo_canónico: "columna_de_origen"}` o `{campo: {"const": valor}}`; deben mapearse los campos obligatorios del importador.
- `options`: `page_size` (100–20000), `overlap_minutes`, `max_rows`, `source_timezone`, `value_maps` (diccionarios de categorías, p. ej. estados del ERP),
  `coverage` (`enabled`, `lag_days`), `import` (`skip_invalid_rows`, `default_branch_code`, `create_missing_*`).
- **Validador** (`SqlQueryGuard`): exactamente **una** sentencia `SELECT` (o `WITH … SELECT`); rechaza `INSERT/UPDATE/DELETE/MERGE`, DDL, DCL,
  `CALL/EXEC`, transacciones, `SELECT … INTO`, bloqueos de filas (`FOR UPDATE/SHARE`, `LOCK IN SHARE MODE`), `;` intermedios, funciones con efectos
  (`pg_sleep`, `lo_*`, `dblink`, `nextval`, `LOAD_FILE`, `xp_*`…), catálogos de sistema (`pg_*`, `information_schema`, `sys.*`, `mysql.*`), `\`, `$`, `?`, `@`,
  `#` y comentarios ejecutables. Analiza cadenas e identificadores entrecomillados antes de buscar palabras peligrosas y ejecuta **el texto sin
  comentarios que analizó**. Se revalida **siempre** antes de ejecutar (una consulta alterada en la base de datos no se ejecuta). Respuesta de
  rechazo: `422 {code: "sql_query_rejected", reason, errors.query}`.
  Si una columna se llama como una palabra vetada, enciérrela entre comillas del motor (`"set"`, `` `use` ``, `[share]`).
- **Defensa en profundidad**: el validador **no sustituye** a una cuenta de base de datos de solo lectura. PostgreSQL y MySQL/MariaDB además
  rechazan escrituras en la propia transacción de solo lectura (probado: `test_database_level_read_only_transaction_blocks_writes…`); SQL Server no.
- **`:since`**: único parámetro permitido. Se sustituye por un literal `'YYYY-MM-DD HH:MM:SS'` en la zona de la fuente (generado por Predictily,
  nunca por el usuario). En una carga completa vale `1900-01-01`. **La consulta debe seleccionar documentos completos** (p. ej. filtrar por
  la fecha de modificación de la cabecera, o `GREATEST(cabecera, líneas)`): un documento parcial reemplazaría las líneas del documento.

### 4.4 Sincronización (E2-25)

`POST /data-sources/{id}/sync` `{entity, mode: full|incremental}` → 202; `GET /data-sources/{id}/syncs`, `GET /syncs/{id}`. El Job
`SyncDataSource` (cola `imports`, `tries = 1`, tenant **explícito** vía `SetTenantContext`) hace:

1. **Ventana**: `incremental` usa la marca de agua de la última sincronización `completed` menos `overlap_minutes` (120 por defecto); sin marca
   previa, carga completa. Marca de agua = máx. `source_recorded_at` extraído si se mapea, o el inicio de la sincronización.
2. **Extracción** en páginas (memoria acotada, tope `max_rows`), conversión a **campos canónicos** (fechas con zona, números con punto, `value_maps`)
   en un CSV de preparación.
3. **Importación por el MISMO pipeline** que un archivo (`CanonicalImportRunner` → `ImportService`): validación, idempotencia por clave natural +
   `source_hash`, procedencia `import_jobs.data_source_id` y, en ventas, `sales.data_source_id`/`import_job_id`/`source_hash`/`source_recorded_at`.
   Respeta los límites del plan. **No hay inserciones directas.**
4. **Filas con error**: sin `options.import.skip_invalid_rows`, la sincronización queda `needs_review` (nada se importa; el `ImportJob` queda
   `validated` y se revisa/confirma con `/imports/{id}/errors` y `/imports/{id}/confirm`). Con la opción, se importan las válidas.
5. Éxito: el archivo generado se elimina (minimización: puede contener códigos de cliente), se actualiza `data_sources.last_synced_at` y la
   cobertura (§2.7). Fallo: `failed` + mensaje saneado; la fuente pasa a `error` si fue de conexión.
- **Una sola** sincronización activa por fuente y entidad (índice único parcial; 409 `sync_in_progress`).
- Reimportación completa: `documents_unchanged = N`, sin duplicados. Corrección en origen: `documents_updated`, las líneas se reemplazan,
  se conservan `data_source_id` y la clave natural, `import_job_id` y `source_recorded_at` pasan a los de la corrección.
- Programación: `php artisan predictily:sync-sources` (cada hora; sincroniza las fuentes con `sync_schedule` hourly/daily y mapeo aprobado).

### 4.5 Cómo ejecutar las pruebas de contrato de MySQL/MariaDB y SQL Server

Se omiten si falta servidor/controlador. Para ejecutarlas defina `TEST_MYSQL_HOST/PORT/DATABASE/USERNAME/PASSWORD` o
`TEST_SQLSRV_HOST/PORT/DATABASE/USERNAME/PASSWORD` y ejecute `php artisan test tests/Unit/DataCore/SqlConnectorContractTest.php`.
Hasta que pasen contra un servidor real, esos conectores **permanecen `implemented_untested`**.

---

## 5. Reporte de calidad extendido

`GET /data-quality/extended[?persist=1]` (persiste en `data_quality_reports`, `scope = data_core`). Secciones:

| Sección | Qué dice |
|---|---|
| `temporal_coverage` | Por entidad (ventas, inventario, compromisos, promociones): rango observado, días con registros, cobertura efectiva y estado (`undeclared`, `declared`, `inferred`, `stale`, `data_beyond_coverage`, `no_data`). |
| `sales_days` | Días sin ventas: con cobertura, **ceros observados**; sin cobertura (o posteriores a ella), **ausentes**; tramos más largos. |
| `customer_identity` | Documentos sin cliente o con cliente genérico; proporción sin identificador estable (aviso > 30 %). |
| `product_code_changes` | Heurística: productos con el mismo nombre normalizado y códigos distintos; `sequential_…_possible_code_change` (períodos sin solape) o `parallel_…_possible_duplicate`. No hay historial de códigos en la fuente. |
| `price_history` | Productos con más de un precio en las líneas de venta, variaciones > 20 %, filas de `product_price_history` y cuántas sin `known_at`. |
| `units` | Costos con unidad distinta de la del producto sin conversión declarada, líneas de compromiso convertidas, categorías con unidades mezcladas. |

No es un puntaje de precisión de pronóstico.

---

## 6. Configuración por precedencia y extensiones (ADR-V2-06, §6.H)

**Precedencia** (`predictily_engine.platform.config.resolve_config`): valores del core → perfil sectorial (opcional, por `tenants.sector`) →
`tenants.settings` (claves de la etapa 1) → `tenants.settings.cores.<core>` (etapa 2). La procedencia por clave es `core`,
`profile:<código>@<versión>` o `tenant`.

**Validación**: clave desconocida, tipo distinto del valor por defecto o valor fuera de rango/lista ⇒ `ConfigValidationError` (subclase de
`ValueError`), nunca se aplica en silencio. Restricciones por clave en `CONFIG_CONSTRAINTS` (cada paquete puede añadir las de su sección).

**Reproducibilidad**: `effective_config_record(core, settings, sector)` devuelve `{config_version (SHA-256), effective_config, provenance,
profile}`; cada ejecución debe guardarlo en `effective_config`/`config_version`. Cambiar los parámetros de la empresa después **no** modifica
ejecuciones ya registradas (probado con `core_runs`).

**Endpoints**:

| Capa | Ruta | Detalle |
|---|---|---|
| Motor | `GET /v1/platform/effective-config?tenant_id&core` | `{tenant_id, core, sector, profile, config_version, precedence, config, provenance, keys:[{key,value,source}]}`; 422 `invalid_config`/`unknown_core`; 404 empresa. Lee **solo** la fila de esa empresa. |
| Motor | `POST /v1/platform/validate-config` | Validación en seco de `overrides`. |
| Motor | `GET /v1/platform/profiles`, `GET /v1/platform/tenants/{id}/extensions` | Perfiles y proveedores (vacío si la empresa no tiene perfil). |
| Backend | `GET /api/v1/tenant/effective-config?core=` (`data.view`) | Proxy: valor y procedencia por clave + `tenant_overrides`. 503 `engine_unavailable`. |
| Backend | `PUT /api/v1/tenant/core-config/{core}` `{overrides}` (`settings.manage`) | Valida con el motor y guarda en `tenants.settings.cores.<core>`; `{}` los elimina; auditoría `tenant.core_config_updated`. |
| Backend | `GET /api/v1/platform/extensions` (`data.view`) | Perfil y proveedores de la empresa. |

**Interfaces de extensión** (`predictily_engine.platform.extensions`; todas las extensiones usan las mismas firmas):

| Tipo | Firma | Salida |
|---|---|---|
| `calendar` | `fn(start, end, as_of) → list[CalendarEventRecord]` | Solo eventos con `known_at ≤ as_of`. |
| `feature` | `fn(dates, as_of, observations) → DataFrame` | Columnas = salidas declaradas, índice = fechas, sin nulos; solo observaciones `≤ as_of`. |
| `constraint` | `fn(as_of) → list[ConstraintRecord]` | Con unidad. |
| `transformation` | `fn(as_of) → list[TransformationRule]` | Insumos → productos, unidad, plazo, capacidad. |

Cada `ExtensionProvider` declara `version`, `inputs`, `outputs`, `units` (una por salida) y `temporal_availability`
(`known_at` | `deterministic` | `observed_until_as_of`). `validate_provider` y `run_provider_contract` comprueban esa declaración
(se ejecutan al registrar el perfil y en las pruebas). Una empresa **sin perfil** no recibe proveedores y usa solo el core.

**Perfiles sintéticos incluidos** (`engine/predictily_engine/extensions/`): `synthetic_retail_a` (calendario, variable `foot_traffic_index`,
restricción de exhibición) y `synthetic_process_b` (calendario de paros, variable `ambient_temperature_c` con disponibilidad
`observed_until_as_of`, restricciones de lote/capacidad y una transformación insumo→producto). Para agregar un perfil real: crear su módulo
y registrarlo con `register_profile`; no se toca el core.

**Lectores del motor** (`predictily_engine.extensions.data_core`): `load_calendar_events(conn, tenant_id, start, end, as_of)` (una fila por evento: última revisión
conocida; sin `known_at` no se devuelve con `as_of`), `effective_coverage(conn, tenant_id, entity, branch_id)` y `classify_day`. También
expuestos en `GET /v1/data-core/calendar` y `GET /v1/data-core/coverage`.

---

## 7. Aislamiento (E2-17, parte datos/extensiones)

- Modelos nuevos con `BelongsToTenant` (fail-closed sin empresa); `CalendarEvent` ve lo propio **y** lo público, nunca lo de otra empresa, y
  solo escribe lo propio.
- FKs compuestas `(tenant_id, x_id)` en `source_mappings`, `source_syncs`, compromisos, ítems y revisiones (la base impide referenciar otra empresa).
- Las rutas de mapeo/sincronización resuelven el recurso con el scope de la empresa (404 si es de otra); credenciales cifradas por fuente.
- `SyncDataSource` transporta `tenantId` explícito; con la empresa B y una sincronización de A no hace nada.
- El motor lee la configuración de **una** empresa por `tenant_id`; los perfiles son globales pero sin estado.
- Pruebas: `DataCoreIsolationTest`, `SqlSourceSyncTest::test_other_tenant_…`, `CommitmentsImportTest::test_isolation_…`, `CalendarEventsTest::test_public_…`,
  `CanonicalDataTest::test_coverage_is_isolated…`, `test_platform_effective_config.py`.

---

## 8. API (resumen)

Prefijo `/api/v1`, header `X-Tenant-ID`. Abilities del rol de la empresa.

| Método | Ruta | Ability |
|---|---|---|
| GET/POST | `/data-sources/{id}/mappings`, `/{mapping}`, `/{mapping}/preview`, `/approve`, `/deactivate` | `datasources.manage` |
| POST/GET | `/data-sources/{id}/sync`, `/data-sources/{id}/syncs`, `/syncs/{id}` | `datasources.manage` |
| GET / PUT | `/data-coverage` | `data.view` / `imports.manage` |
| GET | `/data-quality/extended` | `data.view` |
| GET / POST / PATCH / DELETE | `/calendar-events` (DELETE = cancelar con nueva revisión) | `data.view` / `catalog.manage` |
| GET / POST | `/commitments`, `/commitments/{id}`, `POST /commitments/{id}/cancel` | `data.view` / `imports.manage` |
| GET / POST | `/warehouses`; `/unit-conversions` (+ DELETE) | `data.view` / `catalog.manage` |
| GET / PUT | `/tenant/effective-config`, `/tenant/core-config/{core}`, `/platform/extensions` | `data.view` / `settings.manage` |
| — | `/imports` con `entity_type` ∈ `commitments, calendar_events, unit_conversions, warehouses` | `imports.manage` |

Las escrituras por API de compromisos, calendario, almacenes y conversiones **pasan por el importador** (mismas validaciones y errores; 422
`validation_failed` con `errors` por campo). Contratos detallados: `docs/06-contratos-integracion.md` §2.9–2.12 y §3.14.

## 9. Migraciones propias

`2026_02_06_000100_create_datacore_support_tables`: `source_syncs` (índice único parcial: una sincronización activa por fuente/entidad),
`commercial_commitment_revisions`, y `source_quantity`/`source_unit`/`conversion_factor` en `commercial_commitment_items`. Aditiva y reversible.
Las tablas base (`warehouses`, `unit_conversions`, `commercial_commitments`, `calendar_events`, `source_mappings`, `data_coverage`, `known_at`…)
son de la migración `2026_02_02_000100` del arquitecto, sin cambios.

## 10. Necesidades para la UI (P5)

- Catálogo de conectores: aceptar el estado **`implemented_untested`** y mostrar `verification` (no presentarlo como disponible).
- Asistente de fuente SQL: formulario `config`/`credentials`, botón «probar conexión» (mostrar `details.warnings`), editor de consulta con
  vista previa de campos canónicos, aprobar versión, historial de versiones, botón «sincronizar» (full/incremental) y estado/errores de la
  sincronización (`needs_review` enlaza a `/imports/{id}/errors`).
- Pantalla de cobertura (`/data-coverage`): declarar `complete_through` por fuente/sucursal; mostrar `effective` y su `rule`.
- Reporte de calidad extendido (`/data-quality/extended`) por secciones; resaltar «ausente» frente a «cero observado».
- Calendario (lista por rango, alta/edición como revisión, badge «conocimiento desconocido»), compromisos (lista, detalle con pendiente y
  revisiones, cancelar), almacenes y conversiones de unidades.
- Configuración efectiva por core (tabla clave/valor/procedencia con insignia core/perfil/empresa), edición de valores de empresa y mensaje de
  claves rechazadas; mostrar el perfil sectorial aplicado y la nota «sin perfil: solo core».
- Importación: las entidades nuevas aparecen en `/import-fields/{entity}` y `/import-templates/{entity}`; las ventas aceptan `source_recorded_at`
  y los productos `price_known_at`/`cost_known_at`.

## 11. Reglas para los demás paquetes

- **P1 (Demanda)**: use `effective_coverage`/`classify_day` para decidir qué día sin ventas es cero observado o ausente; use
  `load_calendar_events(as_of=corte)` para el calendario (excluye lo desconocido); guarde `effective_config_record(...)` en la ejecución.
- **P2 (Customer)**: use las reglas de §2.3 para compromisos a un corte (`CommitmentAsOf` o su equivalente SQL); `customers.is_generic` ya se marca al importar.
- **P4 (Abastecimiento)**: use `UnitConverter` (o la tabla `unit_conversions` con las reglas de §2.5); un costo sin `known_at` no es conocido a futuro de un corte pasado.

## 12. Pruebas y resultados

Comandos (entorno: PHP 8.3, PostgreSQL 16, Python 3.12; sin semillas aleatorias en estas pruebas):

```
cd backend && DB_DATABASE=predictily_test_p3 php artisan test                          # suite completa
cd backend && DB_DATABASE=predictily_test_p3 php artisan test tests/Feature/DataCore tests/Unit/DataCore
cd engine && ENGINE_TEST_DATABASE_URL=postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily_engine_test_p3 \
    OMP_WAIT_POLICY=PASSIVE ENGINE_N_JOBS=1 .venv/bin/python -m pytest -q tests
```

Resultados de la última ejecución del paquete:

| Suite | Resultado |
|---|---|
| Backend completa | 300 pruebas, 298 aprobadas, 2 omitidas (contrato con servidor MySQL/MariaDB y SQL Server), 2584 aserciones, ≈ 60 s |
| Backend P3 (`tests/Feature/DataCore`, `tests/Unit/DataCore`) | 114 pruebas, 112 aprobadas, 2 omitidas, 1126 aserciones, ≈ 12 s |
| Motor P3 (`tests/unit/test_platform_extensions.py`, `tests/integration/test_platform_effective_config.py`) | 15 + 8 aprobadas |
| Motor completa | 370 aprobadas, 0 fallos, ≈ 5 min 34 s (con `OMP_WAIT_POLICY=PASSIVE ENGINE_N_JOBS=1`). Una ejecución anterior mostró 3 errores de preparación en `test_supply_integration.py` (P4) por estado compartido de la base; no se repitieron y esas pruebas pasan solas |

Mapa prueba → caso: E2-25 → `SqlSourceSyncTest`; E2-23 → `CommitmentsImportTest`; E2-17 (datos/extensiones) → `DataCoreIsolationTest` y las pruebas de aislamiento
de cada entidad; E2-05 (parte datos: `known_at` de costos, calendario y compromisos) → `CanonicalDataTest`, `CalendarEventsTest`, `CommitmentsImportTest`,
`test_platform_effective_config.py` (calendario `as_of`); configuración y extensiones (§6.H) → `test_platform_extensions.py`, `test_platform_effective_config.py`, `EffectiveConfigApiTest`.

La base «ERP de origen» ficticia es `predictily_erp_source_p3` (variables `TEST_ERP_HOST/PORT/DATABASE/USERNAME/PASSWORD`); las pruebas crean y
borran sus tablas `erp_*`. Si no existe, las pruebas de sincronización se omiten.

## 13. Limitaciones y pendientes

- Ningún ERP real conectado (Soyoda, Contífico, ODBC): el recorrido SQL se probó contra una base PostgreSQL ficticia. MySQL/MariaDB y SQL Server **sin probar**.
- Sin lectura de `customers` ni `costs` como entidades SQL propias (los clientes salen de las ventas; costos por productos).
- `source_recorded_at` no versiona ventas anteriores (solo la actual). Un compromiso modificado en el origen sin `known_at` no puede reconstruirse a un corte.
- Cancelación de compromiso por API usa la hora actual como `known_at` si no se indica (se conoce desde que se informa).
- El exportador de datos LOPDP (`TenantDataExporter`) aún no incluye las tablas nuevas.
- No se actualizó `docs/openapi/*.yaml` (documentado en `docs/06`).
- DNS rebinding no mitigado en la política de destino; en un SaaS público use `PREDICTILY_SQL_ALLOWED_HOSTS`.
- La inferencia de cobertura del conector asume que la fuente es transaccional; para almacenes con ETL ajuste `lag_days` o declare la cobertura.
