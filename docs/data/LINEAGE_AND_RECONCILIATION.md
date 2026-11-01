# Linaje y reconciliación de los datos de ventas

> **Alcance y estado.** Este documento describe el linaje TEMPORAL de las ventas (qué se sabía y cuándo) que escribe el backend:
> versiones de documento, orden de escritura, modos de ingestión, marca de agua de las sincronizaciones, huecos de cobertura y tipos/estados
> desconocidos. Implementa el ADR-V25-23 (`docs/architecture/adr/V25-ADRS.md`) sobre la migración
> `2026_03_01_000100_add_sales_knowledge_time_and_revisions`. El **reporte de reconciliación fuente → Bronze → Silver → Gold** (ADR-V25-24)
> está **DISEÑADO / NO IMPLEMENTADO** y pertenece a otro paquete (§11).
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**,
> **BLOQUEADO** o **NO APLICA**. Las pruebas están en `backend/tests/Feature/` y se ejecutan con
> `cd backend && DB_DATABASE=<bd_de_pruebas> php artisan test --filter=<Clase>`.

## 1. Por qué existe

La auditoría del 31-oct-2026 (`docs/roadmap/audit/2026-10-31/`) halló defectos HIGH de fuga temporal:

| Defecto | Efecto | Corrección |
|---|---|---|
| Las ventas cargadas tarde contaminan cortes pasados | Un backfill o una corrección posterior cambiaban el pasado reconstruido | Ventana de conocimiento por versión (`known_from`/`known_until`) y modo de ingestión (§3, §4) |
| Una anulación o corrección posterior reescribe el pasado | Una factura vigente al corte y anulada después desaparecía de los cortes pasados | La versión reemplazada se conserva en `sales_document_revisions` / `sale_item_revisions` (§3) |
| Reintentos o lotes tardíos sobrescriben la versión nueva | Un reenvío con una versión anterior revertía correcciones sin error | Orden por `source_recorded_at`: la versión anterior se descarta como `stale_skipped` (§4) |
| Tipos y estados desconocidos se cuentan como venta | Cotizaciones, proformas o documentos rechazados inflaban la demanda | Error de fila `REVIEW_REQUIRED`, el documento no se escribe (§6) |
| La marca de agua puede saltar al futuro | Una fila con `modificado_en` = 2099 dejaba sin extraer todo lo nuevo | `watermark = min(máx. source_recorded_at legible, inicio de la sincronización)` (§7) |

## 2. Capas y qué parte del linaje existe hoy

| Capa (ADR-V25-04) | Qué existe hoy | Estado |
|---|---|---|
| Fuente | ERP/SQL, archivo CSV/XLSX, API REST (`POST /sales/batch`), demo | Existe; el backend solo conoce lo que recibe |
| Bronze (objeto original inmutable) | El archivo importado queda en `tenants/{id}/imports/` con SHA-256 (`import_jobs.file_hash`); los lotes REST y las extracciones SQL no se conservan | DISEÑADO / NO IMPLEMENTADO: `bronze_objects` y `ObjectStore` (ADR-V25-24, paquete S01) |
| Silver (tablas canónicas) | `sales`/`sale_items` (versión vigente) + `sales_document_revisions`/`sale_item_revisions` (versiones reemplazadas) + vista `sales_line_versions`; procedencia por `import_job_id`, `superseded_by_import_job_id` y `data_source_id` | IMPLEMENTADO / VERIFICADO (§3–§4) |
| Gold (datasets del motor) | Lectura as-of desde `sales_line_versions` y huella de ventas as-of | DISEÑADO / NO IMPLEMENTADO en este paquete: lo implementa W1-A2 (motor) |
| Reporte fuente → Bronze → Silver → Gold por ventana | — | DISEÑADO / NO IMPLEMENTADO (`reconciliation_reports`, ADR-V25-24) |

## 3. Versiones de un documento de venta

Un documento se identifica por `(tenant_id, document_type, document_number)`. Cada versión tiene una ventana de conocimiento
`[known_from, known_until)`:

- **Versión vigente**: vive en `sales`/`sale_items`. `known_from` = `COALESCE(sales.version_known_from, inicio del día local de sale_date)` y `known_until` abierta.
- **Versiones reemplazadas**: viven en `sales_document_revisions` (cabecera) y `sale_item_revisions` (líneas) con su `revision`. `known_until` = `known_from` de la versión que las reemplazó.
- `sales.current_revision` cuenta las versiones (0 = primera). `sales.first_seen_at` es la primera ingestión y no se actualiza nunca.
- La vista `sales_line_versions` une vigentes y reemplazadas con su ventana: es la fuente única de lectura as-of.

```
        known_from V0        known_from V1 = known_until V0
              │                      │
 ─────────────┼──────────────────────┼──────────────────────────▶ tiempo (instante de ingestión)
              │◀──── V0 reemplazada ─▶│◀────── V1 vigente (known_until abierta) ───▶
```

**Visibilidad al corte C** (fecha local de la empresa, `tenants.timezone`): una versión es visible si
`known_from < medianoche local de (C + 1)` y (`known_until IS NULL` o `known_until >= medianoche local de (C + 1)`).

### Ejemplo con las pruebas (empresa en America/Guayaquil, UTC-5, sin horario de verano)

Documento `F-1` emitido el 2026-05-04 10:00 (-05:00) ⇒ `sale_date` 2026-05-04; el inicio de su día local es 2026-05-04 05:00 UTC.

| Instante (UTC) | Escritura | Efecto |
|---|---|---|
| 2026-06-10 12:00 | `POST /sales/batch` (incremental): líneas A×2, B×1 | V0: `known_from` = 2026-06-10 12:00; `first_seen_at` = 2026-06-10 12:00 |
| 2026-06-12 15:30 | Mismo documento con A×5 | V0 archivada con `known_until` = 2026-06-12 15:30 (`change_kind = lines_changed`); V1 vigente desde 2026-06-12 15:30 |

| Corte C (fecha local) | Versión visible | Por qué |
|---|---|---|
| 2026-06-09 | ninguna | El documento llegó el 10 de junio: un documento tardío no es visible antes de su llegada |
| 2026-06-10, 2026-06-11 | V0 (A×2) | V0 empezó a conocerse el 10 y su ventana sigue abierta hasta el 12 |
| 2026-06-12, 2026-06-20 | V1 (A×5) | Al cierre del 12 ya se conoce V1 y la ventana de V0 terminó |

Verificado por `SalesTemporalWriteTest::test_incremental_update_archives_header_and_lines_with_correct_windows` (IMPLEMENTADO / VERIFICADO).

## 4. Orden de escritura (`SalesDocumentWriter`)

Todo camino de escritura (importador, `POST /sales/batch`, sincronización SQL, demo) pasa por el mismo escritor y **debe fijar el modo de
ingestión**: sin modo, o con un modo inválido, el escritor lanza `InvalidArgumentException`
(`SalesTemporalWriteTest::test_the_writer_requires_an_explicit_valid_ingestion_mode`). Todo ocurre por lotes dentro de la transacción y del
bloqueo de aviso (advisory lock) por empresa.

| Situación del documento entrante | Resultado | Estadística |
|---|---|---|
| No existe | INSERT: `first_seen_at` = ahora, `current_revision` = 0, `ingestion_mode` = el de la escritura, `version_known_from` = NULL (backfill) o ahora (incremental) | `new` |
| Existe, mismo `source_hash` | Sin cambios (no se escribe) | `unchanged` |
| Existe, hash distinto, ambas con `source_recorded_at` y la entrante es **anterior** | Se descarta; no se escribe nada (tampoco productos, sucursales ni clientes nuevos) | `stale_skipped` |
| Existe, hash distinto, marcas iguales o entrante posterior | Se archiva la versión vigente y se reemplaza | `updated`, `revisions_archived` |
| Existe, hash distinto, falta alguna marca | Gana la última ingestión: se archiva y se reemplaza | `updated`, `revisions_archived`, `updated_without_source_order` |

Archivo de la versión vigente (justo antes de sobrescribir, en SQL por lotes: el número de consultas no crece con el número de documentos):

- Cabecera ACTUAL a `sales_document_revisions` con `revision` = `current_revision`, `known_from` = `version_known_from` vigente (puede ser NULL),
  `import_job_id` (import que tenía la versión), `superseded_by_import_job_id` (import que la reemplaza) y `change_kind`.
- Líneas vigentes a `sale_item_revisions` con el mismo `revision`.
- Luego se reemplazan cabecera y líneas con `current_revision + 1`, `ingestion_mode` y `version_known_from` de ESTA escritura.

### `known_until` de la versión archivada

| Modo de la versión nueva | `known_until` | Efecto |
|---|---|---|
| `incremental` | el instante de esta escritura | La ventana de la anterior se cierra cuando Predictily recibe la nueva |
| `backfill` | el inicio del día local de la `sale_date` ANTERIOR | Ventana vacía: una re-carga histórica colapsa versiones (la anterior no se ve en ningún corte) |

En ambos casos `known_until` nunca queda por debajo del `known_from` efectivo de la versión archivada (`GREATEST`): jamás hay ventanas
invertidas. Esto matiza el contrato literal solo cuando la versión archivada era `incremental` y la nueva es `backfill`: el contrato daría un
`known_until` anterior a su `known_from`; aquí queda igual a su `known_from` (ventana vacía, mismo efecto de visibilidad).

`change_kind`: `status_changed` (solo cambia el estado, p. ej. completada → anulada), `lines_changed` (solo líneas), `header_changed` (fecha,
sucursal, cliente, canal o moneda; también un cambio que forma parte de la huella pero no de `sales`, como el segmento del cliente) o `mixed`
(más de uno a la vez).

Verificado por (IMPLEMENTADO / VERIFICADO): `SalesTemporalWriteTest::test_status_change_to_voided_is_recorded_as_status_changed`,
`::test_header_only_and_mixed_changes_are_classified`, `::test_backfill_reload_collapses_versions_into_an_empty_window`,
`::test_backfill_reload_that_moves_the_sale_date_still_collapses_on_the_previous_date`,
`::test_incremental_update_of_a_backfill_document_closes_the_window_at_the_ingestion`,
`::test_backfill_reload_of_an_incremental_document_never_leaves_an_inverted_window`,
`::test_older_incoming_version_is_stale_skipped_and_writes_nothing`, `::test_equal_or_newer_source_timestamp_is_applied_and_ordered`,
`::test_missing_source_timestamp_lets_the_last_ingestion_win_and_is_counted`,
`::test_updating_many_documents_costs_the_same_number_of_queries_as_updating_a_few`.

## 5. Modos de ingestión (`sales.ingestion_mode`)

| Camino | Declaración | Si no se declara | `ingestion_mode_basis` |
|---|---|---|---|
| Importación de archivo | `options.ingestion_mode` (`PUT /imports/{id}/mapping`) | `backfill` si la empresa no tiene ninguna fila en `sales` cuando EMPIEZA la escritura; si no, `incremental` | `declared`, `first_load`, `subsequent_load` |
| `POST /sales/batch` | campo `ingestion_mode` | `incremental` | `declared`, `api_default` |
| Sincronización SQL | solicitud (`POST /data-sources/{id}/sync`) o `source_mappings.options.ingestion_mode` (la solicitud manda) | `backfill` en la primera sincronización de la fuente y entidad que trajo datos; después `incremental` | `declared`, `first_sync`, `subsequent_sync` |
| Demo (`predictily:demo`, carga de datos de demostración) | fijo | `backfill` explícito, también al reimportar sobre una empresa con ventas | — |

Detalles de decisión:

- **Importación**: en la validación el modo es una *proyección* (se muestra en `stats`, no se fija). Se decide al confirmar con las ventas que
  existan en ese momento y se guarda en `import_jobs.options.ingestion_mode` (y en `stats`): dos archivos validados con la empresa vacía no son
  ambos «primera carga». Un reintento del mismo import conserva el modo con el que empezó a escribir.
- **Sincronización**: una sincronización completada con cero filas no cuenta como carga histórica; `needs_review` y `failed` tampoco.
- `backfill` **asume** que el documento era conocido desde el inicio de su día de negocio. Sin historial de estados de la fuente, el estado
  final se asume desde ese día: una anulación posterior cargada como backfill es una limitación declarada, no un dato inventado (§10).

Verificado por (IMPLEMENTADO / VERIFICADO): `SalesImportIngestionModeTest` (primera = backfill y segunda = incremental, declaración explícita,
decisión al iniciar la escritura, validación de la opción, demo), `SalesTemporalWriteTest::test_batch_defaults_to_incremental_and_a_late_document_is_not_visible_before_its_arrival`,
`SqlSyncTemporalContractTest` (primera sincronización, declaración en solicitud y en mapeo, sincronización vacía).

## 6. Tipos y estados desconocidos

Un `document_type` o `status` **no vacío** que no se reconoce (ni por los sinónimos existentes) ya no se cuenta como venta: es un error de fila
`unknown_document_type` / `unknown_document_status` con `dq_class = REVIEW_REQUIRED`, y el documento no se escribe (se aplica la lógica
vigente de `skip_invalid_rows`). Vacío = `invoice` / `completed` (convención documentada, se mantiene).

- Estados reconocidos como **completada**: `completed`, `completada`, `emitida`, `autorizada`, `aprobada`, `vigente`, `activa`, `pagada`, `facturada`,
  `cerrada`, `finalizada`, `procesada`, `confirmada`, `registrada`, `contabilizada`, `vendida`, `entregada` y variantes en inglés, más el
  complemento de las banderas de anulación (`no`, `n`, `0`, `false`, `falso`). **anulada**: los sinónimos existentes (incluidas las banderas `sí`, `1`, `true`).
  **pendiente**: `pendiente`, `pending`, `borrador`, `draft`. Lista completa en `ValueParser::COMPLETED_STATUSES`. La lista de «completada» no
  existía como tal (todo lo no reconocido caía en `completed`); se hizo explícita para poder distinguir lo desconocido.
- `dq_class` no es una columna de `import_errors`: se deriva del código (`Issue::DQ_CLASS_BY_CODE`) y se entrega en `GET /imports/{id}/errors`,
  `errors.csv` (`clase_calidad`) y el detalle de errores de `POST /sales/batch`.
- Una fila con tipo desconocido no comparte clave con un documento `other` válido del mismo número (marcador `unknown` en la clave).
- **Impacto en el E2E**: ninguno. `data/demo/*_sales.csv` solo trae `factura` / `nota de crédito` y estados vacío / `anulada`; los
  `*_sales_with_errors.csv` solo traen `factura` con estado vacío, y su `nota_calidad` no incluye tipos ni estados desconocidos.
  `e2e/mvp_acceptance.py` exige `rows_error > 0` (no un número exacto) en ese archivo.

Verificado por (IMPLEMENTADO / VERIFICADO): `UnknownDocumentTypeStatusTest` (importación con `skip_invalid_rows`, lote, estado vacío, clave),
`DocumentVocabularyTest` (vocabulario y normalizador).

## 7. Marca de agua de la sincronización SQL

`watermark = min(máx. source_recorded_at extraído, inicio de la sincronización)`:

- Las fechas de registro **futuras o ilegibles** no cuentan para el máximo y se informan en `SourceSync.stats.future_recorded_at` (con
  `recorded_at_unreadable` como parte ilegible y un `watermark_note`). Sin ninguna fecha legible, la marca cae en el inicio de la sincronización.
- Una marca previa guardada en el futuro (por una versión anterior a este arreglo) se acota también al leerla, así que la siguiente incremental
  se recupera sola.
- La marca se guarda en UTC: antes se guardaba con el formato de la zona de la empresa y quedaba corrida por su desfase horario.

Verificado por (IMPLEMENTADO / VERIFICADO): `SqlSyncTemporalContractTest::test_a_future_recorded_at_does_not_advance_the_watermark_and_is_reported`,
`::test_unreadable_recorded_at_is_an_anomaly_and_falls_back_to_the_sync_start`, `::test_a_poisoned_previous_watermark_is_bounded_when_it_is_read`.

## 8. Huecos internos de cobertura

`data_coverage_gaps`: caídas de integración, migraciones u orígenes ausentes dentro del periodo que cubre una fuente. Los días de un hueco son
`missing_source` (ADR-V25-03), nunca ceros. API: `GET/POST /api/v1/data-coverage/gaps` y `DELETE /api/v1/data-coverage/gaps/{id}`
(lectura `data.view`; crear y borrar `imports.manage`, la misma habilidad que declara la cobertura). Los creados por la API son `basis = declared`;
`inferred` queda reservado a reglas documentadas. Los huecos pueden solaparse: quien los consume toma la UNIÓN.

Verificado por (IMPLEMENTADO / VERIFICADO): `CoverageGapsApiTest`. La **clasificación de días** `missing_source` en el panel del motor es de W1-A2.

## 9. Diccionario de estadísticas

| Estadística | Dónde | Significado |
|---|---|---|
| `stale_skipped` | `import_jobs.stats`, respuesta de `/sales/batch`, `source_syncs.stats.import` | Documentos con una versión anterior a la almacenada (no se escribe nada) |
| `revisions_archived` | ídem | Versiones vigentes archivadas al reemplazarlas (= `documents_updated` al confirmar) |
| `updated_without_source_order` | ídem | Actualizaciones sin poder comparar `source_recorded_at` (gana la última ingestión) |
| `ingestion_mode`, `ingestion_mode_basis` | `import_jobs.stats`, `/sales/batch`, `source_syncs.stats` | Modo con el que se escribió y cómo se decidió |
| `future_recorded_at`, `recorded_at_unreadable` | `source_syncs.stats` | Filas con fecha de registro futura o ilegible (anomalía; no adelantan la marca) |

En la **validación** de una importación `revisions_archived`, `stale_skipped` y `updated_without_source_order` son una proyección de lo que hará la confirmación.

## 10. Supuestos y limitaciones declarados

1. `backfill` asume conocimiento desde el inicio del día de negocio; el estado final se asume desde ese día (sin historial de estados de la fuente: **BLOQUEADO** por el origen).
2. El orden entre versiones usa solo `source_recorded_at` de la fuente. Con marcas iguales se aplica la entrante; sin marca gana la última ingestión.
3. Una carga `backfill` posterior sobre un documento ya cargado `incremental` lo redeclara histórico: la versión vieja se colapsa y la nueva se considera conocida desde su día de negocio.
4. `first_seen_at` de las filas anteriores a la migración es su `created_at`.
5. La exportación LOPDP de la empresa incluye las revisiones, sus líneas, los huecos y las columnas de versión de `sales`; la vista `sales_line_versions` no se exporta (no guarda datos propios). IMPLEMENTADO / VERIFICADO: `TenantDataExporterTemporalSalesTest` y `TenantDataExporterStage2Test::test_every_tenant_table_is_classified_as_exported_or_deliberately_excluded` (que ahora distingue tablas de vistas).
6. La retención de datos (`data_retention_months`, `predictily:purge`) borra el documento y, en cascada, sus versiones archivadas. IMPLEMENTADO / VERIFICADO: `SalesTemporalWriteTest::test_retention_purge_removes_the_revisions_together_with_their_documents`.
7. El orden se compara contra el `source_recorded_at` de la última versión APLICADA. Un documento reenviado con el mismo contenido y una marca más nueva es `unchanged` (no se escribe, la marca almacenada no avanza); si después llegara tarde una versión con contenido anterior cuya marca esté entre ambas, se aplicaría. Se acepta porque `source_recorded_at` no forma parte de `source_hash` por diseño.

## 11. Estado resumido

| Estado | Elemento |
|---|---|
| IMPLEMENTADO / VERIFICADO | Modos de ingestión por camino; versiones de documento archivadas con ventanas; `stale_skipped`; estadísticas aditivas; tipos/estados desconocidos como `REVIEW_REQUIRED`; marca de agua acotada; huecos de cobertura (API); demo con backfill explícito; OpenAPI al día |
| DISEÑADO / NO IMPLEMENTADO | Reporte de reconciliación fuente → Bronze → Silver → Gold por ventana (`reconciliation_reports`); `bronze_objects` para lotes REST y extracciones SQL; linaje Silver (`bronze_object_id`, `source_row`, `schema_version`); cuarentena revisable (`quarantine_records`); `value_maps` de tipo y estado en importación de archivos; alerta tras N sincronizaciones con cero filas; huecos `inferred` por regla — paquetes S01 / ADR-V25-24 |
| DISEÑADO / NO IMPLEMENTADO (otro paquete) | Lectura as-of de Demand y Customer desde `sales_line_versions`; clasificación de días `zero_confirmed / missing_source / stockout_censored / closed`; huella as-of — W1-A2 (motor) |
| BLOQUEADO | Estado histórico real de una carga backfill (la fuente no lo entrega); sincronización de MySQL/SQL Server con este contrato (sin servidor de pruebas; la lógica no depende del conector) |
| NO APLICA | Modo de ingestión en productos, inventario, compromisos y demás entidades (solo las ventas son documentos versionados) |
