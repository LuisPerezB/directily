# Linaje y reconciliación de los datos de ventas

> **Alcance y estado.** Este documento describe el linaje TEMPORAL de las ventas (qué se sabía y cuándo) que escribe el backend:
> versiones de documento, orden de escritura, modos de ingestión, marca de agua de las sincronizaciones, huecos de cobertura y tipos/estados
> desconocidos. Implementa el ADR-V25-23 (`docs/architecture/adr/V25-ADRS.md`) sobre la migración
> `2026_03_01_000100_add_sales_knowledge_time_and_revisions`. La **reconciliación por ventana fuente → Bronze → Silver → Gold** (ADR-V25-24) está
> **IMPLEMENTADO / VERIFICADO** (W2-B; sección «Reconciliación por ventana» al final); la reconciliación por carga, el linaje Silver y la captura Bronze son de W2-A (§13).
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**,
> **BLOQUEADO** o **NO APLICA**. Las pruebas están en `backend/tests/Feature/` y se ejecutan con
> `cd backend && DB_DATABASE=<bd_de_pruebas> php artisan test --filter=<Clase>`. Las de la sincronización SQL necesitan además una base
> «ERP de origen» de pruebas; sin ella se OMITEN con un mensaje que dice qué queda sin verificar (§12).

## 1. Por qué existe

La auditoría del 31-oct-2026 (`docs/roadmap/audit/2026-10-31/`) halló defectos HIGH de fuga temporal:

| Defecto | Efecto | Corrección |
|---|---|---|
| Las ventas cargadas tarde contaminan cortes pasados | Un backfill o una corrección posterior cambiaban el pasado reconstruido | Ventana de conocimiento por versión (`known_from`/`known_until`) y modo de ingestión (§3, §4) |
| Una anulación o corrección posterior reescribe el pasado | Una factura vigente al corte y anulada después desaparecía de los cortes pasados | La versión reemplazada se conserva en `sales_document_revisions` / `sale_item_revisions` (§3) |
| Reintentos o lotes tardíos sobrescriben la versión nueva | Un reenvío con una versión anterior revertía correcciones sin error | Orden por `source_recorded_at`: la versión anterior se descarta como `stale_skipped` (§4) |
| Tipos y estados desconocidos se cuentan como venta | Cotizaciones, proformas o documentos rechazados inflaban la demanda | Error de fila `REVIEW_REQUIRED`, el documento no se escribe (§6) |
| La marca de agua puede saltar al futuro | Una fila con `modificado_en` = 2099 dejaba sin extraer todo lo nuevo | `watermark = min(máx. source_recorded_at legible, inicio de la sincronización)` (§7) |
| Una re-carga histórica sobre un documento con revisiones incrementales lo dejaba visible DOS veces (revisión adversarial, HIGH) | Doble conteo as-of: backfill (qty 2) → incremental (qty 5) → backfill (qty 7) mostraba las revisiones 0 (2) y 2 (7) en los cortes entre la fecha de venta y la ingestión incremental | La re-carga backfill colapsa TODA la cadena archivada en el mismo lote SQL; invariante «0 o 1 versión visible por corte» probada con las 336 secuencias de 2 a 4 escrituras (§4) |

## 2. Capas y qué parte del linaje existe hoy

| Capa (ADR-V25-04) | Qué existe hoy | Estado |
|---|---|---|
| Fuente | ERP/SQL, archivo CSV/XLSX, API REST (`POST /sales/batch`), demo | Existe; el backend solo conoce lo que recibe |
| Bronze (objeto original inmutable) | `bronze_objects` (W2-A, §13): el archivo importado, la extracción SQL (NDJSON comprimido) y el cuerpo de `POST /sales/batch` se guardan íntegros ANTES de normalizar, con SHA-256, huella de esquema y procedencia; `import_jobs.stored_path` se conserva por compatibilidad | IMPLEMENTADO / VERIFICADO (`BronzeLineageTest`, `SqlIngestionQualityTest`) |
| Silver (tablas canónicas) | `sales`/`sale_items` (versión vigente) + `sales_document_revisions`/`sale_item_revisions` (versiones reemplazadas) + vista `sales_line_versions`; procedencia por `import_job_id`, `superseded_by_import_job_id` y `data_source_id`; linaje `bronze_object_id`, `source_row` y `schema_version` (W2-A) | IMPLEMENTADO / VERIFICADO (§3–§4) |
| Gold (datasets del motor) | Lectura as-of desde `sales_line_versions` y huella de ventas as-of | DISEÑADO / NO IMPLEMENTADO en este paquete: lo implementa W1-A2 (motor) |
| Reporte fuente → Bronze → Silver por carga | `reconciliation_reports` con `scope import` o `sync` (W2-A, §13); el reporte por ventana con Gold es de W2-B | IMPLEMENTADO / VERIFICADO (`LoadReconciliationTest`) |

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
`known_from < medianoche local de (C + 1)` y (`known_until IS NULL` o `known_until >= medianoche local de (C + 1)`). Dicho de otro modo, la
versión es visible en los cortes cuya medianoche local (C + 1) cae en `(known_from, known_until]`.

**INVARIANTE (IMPLEMENTADO / VERIFICADO, §4):** las ventanas de las versiones de un documento son DISJUNTAS: en todo corte hay 0 o 1 versión
visible por documento, jamás dos; y una vez pasada la última escritura hay exactamente 1 y es la vigente. Ninguna ventana queda invertida
(`known_until < known_from`).

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
| Existe, mismo `source_hash` (o su `legacy_source_hash` con el código de cliente crudo: `docs/data/DATA_QUALITY_ENGINE.md` §4.2) | Sin cambios (no se escribe, no cambia el modo almacenado). Si el `ingestion_mode` almacenado difiere del declarado en esta escritura, además se cuenta la discrepancia | `unchanged` (+ `unchanged_mode_mismatch`) |
| Existe, hash distinto, ambas con `source_recorded_at` y la entrante es **anterior** | Se descarta; no se escribe nada (tampoco productos, sucursales ni clientes nuevos) | `stale_skipped` |
| Existe, hash distinto, marcas iguales o entrante posterior | Se archiva la versión vigente y se reemplaza | `updated`, `revisions_archived` |
| Existe, hash distinto, falta alguna marca | Gana la última ingestión: se archiva y se reemplaza; la marca almacenada **no se borra** (`COALESCE(entrante, almacenada)`) | `updated`, `revisions_archived`, `updated_without_source_order` |

Archivo de la versión vigente (justo antes de sobrescribir, en SQL por lotes: el número de consultas no crece con el número de documentos):

- Cabecera ACTUAL a `sales_document_revisions` con `revision` = `current_revision`, `known_from` = `version_known_from` vigente (puede ser NULL),
  `import_job_id` (import que tenía la versión), `superseded_by_import_job_id` (import que la reemplaza) y `change_kind`.
- Líneas vigentes a `sale_item_revisions` con el mismo `revision`.
- Luego se reemplazan cabecera y líneas con `current_revision + 1`, `ingestion_mode` y `version_known_from` de ESTA escritura.

### `known_until` de la versión archivada y de las revisiones más antiguas

| Modo de la versión nueva | `known_until` de la versión que se archiva | `known_until` de las revisiones MÁS ANTIGUAS del documento |
|---|---|---|
| `incremental` | el instante de esta escritura | recortado a ese instante si terminaba después de él (con relojes monótonos no ocurre; defensa ante desfases de reloj entre servidores) |
| `backfill` | su propio `known_from` efectivo: ventana vacía (si era backfill, el inicio del día local de su `sale_date`; si era incremental, el instante en que se conoció) | también su `known_from` efectivo: **toda la cadena archivada queda colapsada** |

Todo en el MISMO lote SQL que archiva la versión (una sola sentencia `UPDATE ... FROM tenants` por lote de hasta 1000 documentos; el número
de consultas no crece con el número de documentos). Solo se tocan ventanas no vacías que realmente se solaparían con la nueva.
`known_until` nunca queda por debajo del `known_from` efectivo de la versión archivada: jamás hay ventanas invertidas. Esto matiza el
contrato literal solo cuando la versión archivada era `incremental` y la nueva es `backfill`: el contrato daría un `known_until` anterior a su
`known_from`; aquí queda igual a su `known_from` (ventana vacía, mismo efecto de visibilidad).

**Por qué toda la cadena y no solo la versión inmediata (hallazgo HIGH de la revisión adversarial).** La versión nueva `backfill` es conocida
desde el inicio del día local de SU `sale_date` (`version_known_from` NULL). Cerrar solo la ventana de la versión inmediata dejaba las revisiones
más antiguas con ventana no vacía solapadas con ella: backfill (qty 2, T0) → incremental (qty 5, T1) → backfill (qty 7, T2) dejaba la revisión
0 con la ventana `(inicio del día, T1]` y la revisión 2 con `(inicio del día, ∞)`, y todo corte entre la fecha de venta y T1 contaba el documento
dos veces (2 y 7). 144 de las 336 secuencias de 2 a 4 escrituras violaban la invariante.

**Decisión: colapsar la cadena, no recortarla en la `sale_date` nueva.** Se evaluaron las dos opciones válidas del encargo:
(a) recortar cada revisión a `known_until = max(known_from, min(known_until, known_from de la nueva))` y (b) colapsar. Se eligió (b) porque
(1) es lo que dice el contrato («una re-carga backfill colapsa versiones») y lo que ya hacía la versión inmediata (pruebas existentes: ventana
vacía también cuando la re-carga cambia la `sale_date`); (2) no depende de que la re-carga cambie la fecha de venta, así que el resultado no
varía según el orden de las fechas; (3) una re-carga histórica DECLARA el documento como conocido desde su día de negocio con el contenido
nuevo, lo que sustituye a todo lo que se sabía antes. Coste asumido: en las lecturas as-of se pierde el conocimiento incremental intermedio de
esa cadena (las filas de revisión se conservan intactas —hash, procedencia, `change_kind`— para auditoría; solo dejan de ser visibles). Con (a) el
resultado difiere solo cuando la nueva `sale_date` es POSTERIOR al `known_from` de una revisión anterior (la re-carga mueve la venta hacia
adelante): (b) no muestra el documento antes de su nuevo día de negocio.

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

Archivo y ventanas disjuntas (IMPLEMENTADO / VERIFICADO), `SalesVersionWindowsInvariantTest`:

- `::test_backfill_incremental_backfill_leaves_exactly_one_visible_version_at_every_cutoff` (el caso del hallazgo, con ventanas y cortes explícitos),
  `::test_incremental_incremental_backfill_collapses_both_closed_incremental_windows`,
  `::test_backfill_reload_that_moves_the_sale_date_after_incremental_revisions_stays_disjoint` (cambio de `sale_date`),
  `::test_backfill_incremental_incremental_keeps_back_to_back_windows` (sin re-carga histórica no se colapsa nada),
  `::test_an_incremental_write_with_a_clock_behind_the_history_does_not_overlap_older_windows`, `::test_windows_never_invert_after_any_write`.
- `::test_every_sequence_of_two_to_four_writes_keeps_zero_or_one_visible_version_per_cutoff`: enumera las **336** secuencias de 2 a 4 escrituras
  (backfill | incremental) × 2 fechas de venta y, tras CADA una, comprueba en todos los cortes (una consulta por secuencia) que hay 0 o 1
  versión visible por documento, que ninguna ventana está invertida, que la revisión vigente es la esperada y que, pasada la última
  escritura, se ve exactamente la vigente. Corre en dos zonas: America/Guayaquil (UTC-5, sin horario de verano) y **Europe/Madrid** (offset
  positivo y horario de verano: las escrituras atraviesan el cambio de hora del 29-mar-2026 y ese día dura 23 horas). Recorre el árbol de
  secuencias en profundidad con puntos de guardado (SAVEPOINT), así que cada escritura se hace una vez por nodo: ~5 s en total para las dos zonas.
  Con el defecto presente falla en 144 de 336 secuencias (Guayaquil) y 22 (Madrid).
- `::test_dst_company_with_a_positive_offset_has_correct_backfill_and_incremental_windows` (Madrid, ventanas backfill/incremental con los
  instantes UTC exactos antes y después del cambio de hora, y re-carga que mueve la fecha de venta).

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
- **Re-declarar el modo sobre documentos sin cambios es un no-op, ahora visible** (`unchanged_mode_mismatch`): un documento con el MISMO
  `source_hash` no se reescribe, así que declarar `backfill` (o `incremental`) en una carga que lo repite no cambia su `ingestion_mode`, su
  `version_known_from` ni su ventana. El contrato de escritura no cambia; la estadística cuenta los documentos sin cambios cuyo modo almacenado
  difiere del declarado y la importación la reporta como hallazgo `unchanged_mode_mismatch` (info). Es esperable y benigno en una
  sincronización incremental que re-lee el solape de una carga histórica; es la señal a mirar cuando alguien re-declara `backfill` para
  «corregir» la clasificación de documentos ya cargados (no surtirá efecto: habría que cambiar su contenido o cargarlos de nuevo).
- **Concurrencia de la regla automática (LIMITACIÓN DOCUMENTADA, §10.9).** `IngestionModeResolver` evalúa «primera carga» (`tenantHasSales`)
  y `forSync` FUERA del bloqueo de aviso por empresa del escritor.
- `backfill` **asume** que el documento era conocido desde el inicio de su día de negocio. Sin historial de estados de la fuente, el estado
  final se asume desde ese día: una anulación posterior cargada como backfill es una limitación declarada, no un dato inventado (§10).

Verificado por (IMPLEMENTADO / VERIFICADO): `SalesWriterHardeningTest::test_redeclaring_the_mode_over_unchanged_documents_is_a_visible_no_op_in_the_batch` y
`::test_the_import_projects_counts_and_reports_unchanged_mode_mismatch` (hallazgo `unchanged_mode_mismatch`), `SalesImportIngestionModeTest` (primera = backfill y segunda = incremental, declaración explícita,
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
- **Cuarentena y mapeos de valores (W2-A).** Con `skip_invalid_rows` el documento con tipo o estado desconocido ya NO se descarta: se retiene en `quarantine_records` y se
  libera tras agregar un `value_maps` (`import_jobs.options.value_maps` o `source_mappings.options.value_maps`); ver `docs/data/DATA_QUALITY_ENGINE.md` §3 y §4.3
  (IMPLEMENTADO / VERIFICADO: `QuarantineFlowTest`, `ValueMapsTest`).
- `dq_class` no es una columna de `import_errors`: se deriva del código (`Issue::DQ_CLASS_BY_CODE`) y se entrega en `GET /imports/{id}/errors`,
  `errors.csv` (`clase_calidad`) y el detalle de errores de `POST /sales/batch`.
- Una fila con tipo desconocido no comparte clave con un documento `other` válido del mismo número (marcador `unknown` en la clave): nunca se
  mezcla con él. **Decisión del arquitecto (hallazgo 7): el documento reconocido con ese mismo número SE ESCRIBE completo, con sus propias
  líneas.** En los ERP la numeración suele ser por tipo o serie (la factura 100 y la proforma o guía 100 son documentos distintos); descartar la
  factura descartaría ventas reales. Por tanto: (a) la fila de tipo desconocido sigue siendo error `REVIEW_REQUIRED` y no se escribe;
  (b) el documento reconocido se escribe con sus propias líneas; (c) queda un AVISO (`warning`, no error; sin `dq_class`)
  `document_number_shared_with_unknown_type` sobre el documento reconocido, contado UNA vez por documento en `stats.document_number_shared_with_unknown_type`
  (importación: también en el resumen y los hallazgos del reporte de calidad y en `GET /imports/{id}/errors`; lote: en `data.document_number_shared_with_unknown_type`
  y en `data.warnings`). La limitación que esto acepta está en §10.11.
- **Impacto en el E2E**: ninguno. `data/demo/*_sales.csv` solo trae `factura` / `nota de crédito` y estados vacío / `anulada`; los
  `*_sales_with_errors.csv` solo traen `factura` con estado vacío, y su `nota_calidad` no incluye tipos ni estados desconocidos.
  `e2e/mvp_acceptance.py` exige `rows_error > 0` (no un número exacto) en ese archivo.

Verificado por (IMPLEMENTADO / VERIFICADO): `UnknownDocumentTypeStatusTest` (importación con `skip_invalid_rows`, lote, estado vacío,
`::test_an_unknown_type_row_stays_an_error_while_the_recognized_document_with_the_same_number_is_written_with_a_warning`),
`SalesWriterHardeningTest::test_a_recognized_document_sharing_its_number_with_an_unknown_type_row_is_written_with_a_warning_in_an_import` y
`::test_a_recognized_document_sharing_its_number_with_an_unknown_type_document_is_written_with_a_warning_in_a_batch`, `DocumentVocabularyTest` (vocabulario y normalizador).

## 7. Marca de agua de la sincronización SQL

`watermark = min(M, inicio de la sincronización)` con `M = máx. source_recorded_at legible extraído ?? marca previa (acotada) ?? inicio de la sincronización`
(`SourceSyncService::run`: `boundedWatermark(maxRecordedAt ?? previous ?? startedAt, startedAt)`):

- Las fechas de registro **futuras o ilegibles** no cuentan para el máximo y se informan en `SourceSync.stats.future_recorded_at` (con
  `recorded_at_unreadable` como parte ilegible y un `watermark_note`). Si la extracción no trae ninguna fecha legible, la marca CONSERVA la marca
  previa de la fuente y entidad (acotada al inicio de la sincronización); solo cuando no hay marca previa (primera sincronización) cae en el
  inicio de la sincronización. (Esta sección decía «sin ninguna fecha legible, la marca cae en el inicio de la sincronización»: era inexacto.)
- **Cota única de `source_recorded_at`** (hallazgo 4): *ahora + tolerancia*, con tolerancia configurable (`predictily.imports.source_recorded_at_tolerance_minutes`,
  variable `PREDICTILY_RECORDED_AT_TOLERANCE_MINUTES`, 5 minutos por defecto) para el desfase de reloj de la fuente. La aplica
  `SalesLineNormalizer::recordedAtBound` en TRES caminos: importación de archivo, `POST /sales/batch` y la preparación de la sincronización SQL
  (`CanonicalStager`, con el inicio de la sincronización como «ahora»). Una marca posterior a la cota: (i) no se guarda como orden de versión
  (`sales.source_recorded_at` queda NULL; la fila SÍ se escribe), (ii) se cuenta en `future_recorded_at` (en importaciones, lotes y sincronizaciones)
  y (iii) no adelanta la marca de agua. Antes el normalizador aceptaba hasta el fin de MAÑANA (una marca de mañana se guardaba y toda versión real
  posterior parecía «anterior» y se descartaba como `stale_skipped`) y la preparación acotaba al inicio de la sincronización sin tolerancia.
- Una marca previa guardada en el futuro (por una versión anterior a este arreglo) se acota también al leerla, así que la siguiente incremental
  se recupera sola.
- La marca se guarda en UTC: antes se guardaba con el formato de la zona de la empresa y quedaba corrida por su desfase horario.

Verificado por (IMPLEMENTADO / VERIFICADO): `SqlSyncTemporalContractTest::test_a_future_recorded_at_does_not_advance_the_watermark_and_is_reported`,
`::test_unreadable_recorded_at_is_an_anomaly_and_falls_back_to_the_sync_start`, `::test_a_poisoned_previous_watermark_is_bounded_when_it_is_read`,
`::test_the_sync_and_the_normalizer_share_the_same_recorded_at_bound` (usan la base ERP de pruebas, §12). La cota del normalizador y su efecto en
el orden de versiones: `SalesWriterHardeningTest::test_normalizer_bound_is_now_plus_a_configurable_tolerance`,
`::test_batch_counts_future_recorded_at_and_stores_no_order_for_it`, `::test_a_discarded_future_mark_cannot_make_later_real_versions_stale`,
`::test_import_counts_future_recorded_at_with_the_same_bound`.

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
| `unchanged_mode_mismatch` | `import_jobs.stats` (y su reporte de calidad), respuesta de `/sales/batch`, `source_syncs.stats.import` | Documentos SIN cambios (mismo `source_hash`) cuyo `ingestion_mode` almacenado difiere del declarado en esta carga: no se reescriben |
| `future_recorded_at`, `recorded_at_unreadable` | `source_syncs.stats` (preparación), `import_jobs.stats` (y su reporte de calidad), respuesta de `/sales/batch`, `source_syncs.stats.import` | Filas (líneas) con `source_recorded_at` posterior a ahora + tolerancia, o ilegible (`recorded_at_unreadable` es la parte ilegible): la marca se descarta (queda NULL, no ordena versiones; la fila se escribe) y no adelanta la marca de agua |
| `documents_quarantined` | `import_jobs.stats` (y su reporte de calidad) | Documentos con hallazgos REVIEW_REQUIRED o QUARANTINED retenidos en `quarantine_records` con `skip_invalid_rows` (W2-A; en la validación es una proyección). Ver `docs/data/DATA_QUALITY_ENGINE.md` §3 |

En la **validación** de una importación `revisions_archived`, `stale_skipped`, `updated_without_source_order` y `unchanged_mode_mismatch` son una proyección de lo que hará la confirmación.

## 10. Supuestos y limitaciones declarados

1. `backfill` asume conocimiento desde el inicio del día de negocio; el estado final se asume desde ese día (sin historial de estados de la fuente: **BLOQUEADO** por el origen).
2. El orden entre versiones usa solo `source_recorded_at` de la fuente. Con marcas iguales se aplica la entrante; sin marca gana la última ingestión.
3. Una carga `backfill` posterior sobre un documento ya cargado (`incremental` o `backfill`) lo redeclara histórico: la nueva versión se considera conocida desde su día de negocio y **toda** la cadena de revisiones anteriores se colapsa (ventana vacía; las filas se conservan para auditoría, pero dejan de ser visibles en lecturas as-of). Se pierde así el conocimiento incremental intermedio de esa cadena, y la fecha de venta nueva manda: si la re-carga la mueve hacia adelante, el documento no se ve antes de su nuevo día de negocio. Es el costo de evitar el doble conteo (§4). Verificado por `SalesVersionWindowsInvariantTest` (invariante sobre las 336 secuencias).
4. `first_seen_at` de las filas anteriores a la migración es su `created_at`.
5. La exportación LOPDP de la empresa incluye las revisiones, sus líneas, los huecos y las columnas de versión de `sales`; la vista `sales_line_versions` no se exporta (no guarda datos propios). IMPLEMENTADO / VERIFICADO: `TenantDataExporterTemporalSalesTest` y `TenantDataExporterStage2Test::test_every_tenant_table_is_classified_as_exported_or_deliberately_excluded` (que ahora distingue tablas de vistas).
6. La retención de datos (`data_retention_months`, `predictily:purge`) borra el documento y, en cascada, sus versiones archivadas. IMPLEMENTADO / VERIFICADO: `SalesTemporalWriteTest::test_retention_purge_removes_the_revisions_together_with_their_documents`.
7. El orden se compara contra el `source_recorded_at` de la última versión APLICADA. Una actualización que llega SIN marca gana por última ingestión (se cuenta en `updated_without_source_order`) pero **conserva** la marca almacenada (`COALESCE`): esa marca sigue ordenando las versiones siguientes (una con marca anterior a ella se descarta). IMPLEMENTADO / VERIFICADO: `SalesWriterHardeningTest::test_an_update_without_a_source_timestamp_keeps_the_stored_mark_and_is_still_counted`. Un documento reenviado con el mismo contenido y una marca más nueva es `unchanged` (no se escribe, la marca almacenada no avanza); si después llegara tarde una versión con contenido anterior cuya marca esté entre ambas, se aplicaría. Se acepta porque `source_recorded_at` no forma parte de `source_hash` por diseño.
8. Re-declarar el modo sobre documentos sin cambios no los reescribe (§5, `unchanged_mode_mismatch`). El no-op ya no es silencioso; el contrato de escritura no cambia.
9. **Carrera en la regla automática de «primera carga» (DISEÑADO, NO RESUELTO).** `IngestionModeResolver::tenantHasSales` se evalúa fuera del bloqueo de aviso por empresa. Se evaluó resolverla bajo el bloqueo y NO es barato: el bloqueo del escritor se toma por lote y se libera al confirmarlo, y la decisión debe valer para todos los lotes del import y para sus reintentos (se guarda en `import_jobs.options`), de modo que habría que decidirla en el primer lote y propagarla. Efecto: dos importaciones **sin modo declarado** que empiecen a escribir a la vez sobre una empresa vacía pueden resolver ambas `backfill`. Mitigación operativa: declarar `options.ingestion_mode` en la segunda carga (la declaración manda y no consulta nada). `forSync` no tiene esta carrera entre sincronizaciones de la misma fuente y entidad (el índice único de sincronizaciones activas las serializa y solo mira esa fuente y entidad) y `POST /sales/batch` no tiene regla automática. Pendiente: tomar la decisión dentro de la primera transacción de escritura del import.
10. El colapso de la cadena (§4) y la cota de `source_recorded_at` (§7) no reescriben datos ya guardados: las filas anteriores a estos arreglos conservan sus ventanas y marcas. La invariante solo se garantiza para escrituras posteriores; una revisión histórica ya solapada habría que corregirla con una migración de datos (no se aplicó: este paquete no modifica migraciones y no hay datos de producción afectados conocidos).
11. **Número compartido con una fila de tipo desconocido (decisión del arquitecto, §6).** El documento reconocido se escribe con sus propias líneas aunque comparta número con filas de tipo no reconocido. Si esas filas eran en realidad líneas SUYAS con el tipo mal escrito, el documento queda sin ellas (venta parcial) y solo el aviso `document_number_shared_with_unknown_type` y el error `unknown_document_type` de esas filas lo dejan visible: no hay forma de distinguir, desde el archivo o el lote, una numeración por tipo o serie de una línea mal tipificada. Mitigación: revisar los avisos y los errores `REVIEW_REQUIRED` antes de confirmar; el piloto con datos reales debe confirmar con el cliente si su ERP numera por tipo o serie. (Se evaluó descartar el documento reconocido —con un error `ambiguous_document_type`— y se rechazó porque descartaría ventas reales.)

## 11. Estado resumido

| Estado | Elemento |
|---|---|
| IMPLEMENTADO / VERIFICADO | Modos de ingestión por camino; versiones de documento archivadas con ventanas **disjuntas** (invariante 0 o 1 versión visible por corte, 336 secuencias en dos zonas horarias); `stale_skipped`; estadísticas aditivas (`unchanged_mode_mismatch`, `future_recorded_at` en los tres caminos); cota única de `source_recorded_at`; tipos/estados desconocidos como `REVIEW_REQUIRED` (el documento reconocido con número compartido se escribe con el aviso `document_number_shared_with_unknown_type`); marca de agua acotada; huecos de cobertura (API); demo con backfill explícito; OpenAPI al día |
| DISEÑADO / NO IMPLEMENTADO | Resolver la «primera carga» automática bajo el bloqueo de aviso (§10.9) |
| IMPLEMENTADO / VERIFICADO (W2-A) | `bronze_objects` para archivos, lotes REST y extracciones SQL; linaje Silver (`bronze_object_id`, `source_row`, `schema_version`); cuarentena revisable (`quarantine_records`); `value_maps` de tipo y estado en importación de archivos; reconciliación por carga — ver §13 y `docs/data/DATA_QUALITY_ENGINE.md` |
| DISEÑADO / NO IMPLEMENTADO | Alerta tras N sincronizaciones con cero filas; huecos `inferred` por regla |
| DISEÑADO / NO IMPLEMENTADO (otro paquete) | Lectura as-of de Demand y Customer desde `sales_line_versions`; clasificación de días `zero_confirmed / missing_source / stockout_censored / closed`; huella as-of — W1-A2 (motor) |
| BLOQUEADO | Estado histórico real de una carga backfill (la fuente no lo entrega); sincronización de MySQL/SQL Server con este contrato (sin servidor de pruebas; la lógica no depende del conector) |
| NO APLICA | Modo de ingestión en productos, inventario, compromisos y demás entidades (solo las ventas son documentos versionados) |

## 12. Pruebas que necesitan la base «ERP de origen» (cómo ejecutarlas)

Tres clases de pruebas ejercitan el conector SQL de punta a punta contra un ERP PostgreSQL ficticio: `SqlSyncTemporalContractTest`
(contrato temporal de la sincronización: modo de ingestión, versiones, `stale_skipped`, marca de agua, cota de `source_recorded_at`),
`SqlSourceSyncTest` (conector, solo lectura, pipeline de importación, permisos, programación) y `SqlConnectorContractTest::test_postgres_server_contract`.
Necesitan una base PostgreSQL VACÍA aparte de la de Predictily (cada prueba crea y elimina su propio esquema ficticio; todos los datos son ficticios).

| Qué | Cómo |
|---|---|
| Crear la base (una vez) | `createdb -h 127.0.0.1 -U predictily predictily_erp_source_p3` (nombre por defecto; contraseña local de desarrollo) |
| Otro nombre o servidor | variables `TEST_ERP_DATABASE`, `TEST_ERP_HOST`, `TEST_ERP_PORT`, `TEST_ERP_USERNAME`, `TEST_ERP_PASSWORD` (p. ej. una base por ejecución paralela: `TEST_ERP_DATABASE=predictily_erp_source_w1a`) |
| Lista blanca de destinos | Las pruebas declaran su propia lista blanca (`ErpSourceDatabase::authorizeInConnectorPolicy`); no hace falta tocar `PREDICTILY_SQL_ALLOWED_HOSTS` ni `PREDICTILY_SQL_ALLOWED_CIDRS` (de hecho, para que la suite sea hermética se ejecuta con ambas vacías) |

Ejecutarlas (la expresión de `--filter` es una regex de PHPUnit):

```
cd backend
DB_DATABASE=<bd_de_pruebas> TEST_ERP_DATABASE=<bd_erp> PREDICTILY_SQL_ALLOWED_HOSTS= PREDICTILY_SQL_ALLOWED_CIDRS= \
  php artisan test --filter='SqlSyncTemporalContractTest|SqlSourceSyncTest|SqlConnectorContractTest'
```

**Si la base no existe, esas pruebas se OMITEN (no fallan) y la omisión es explícita.** Cada una llama a `markTestSkipped` con un mensaje que
dice (1) que la causa es la ausencia de la base y por qué falló la conexión, (2) EXACTAMENTE qué comportamiento queda SIN VERIFICAR y (3) el
comando `createdb` y las variables para crearla (`ErpSourceDatabase::skipMessage`). El mensaje no incluye credenciales. PHPUnit lo muestra con
`--display-skipped`, y `--log-events-text <archivo>` lo deja siempre en el archivo («Test Skipped … OMITIDA SIN VERIFICAR …»). Una suite con estas
omisiones NO demuestra el contrato temporal de la sincronización SQL: el informe de la suite debe declararlo como «no verificado», no como verde.

## 13. Reconciliación por carga fuente → Bronze → Silver (W2-A)

> Paquete W2-A · ADR-V25-24. Marcas: **IMPLEMENTADO / VERIFICADO** con la prueba que lo demuestra (`backend/tests/Feature/DataQuality/`),
> **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO**, **NO APLICA**. La reconciliación **por ventana** (`scope window`) es de W2-B y se documenta en la sección siguiente.

**IMPLEMENTADO / VERIFICADO.** Al **confirmar** cada importación crea un `reconciliation_reports` con `scope = import`, y cada sincronización SQL uno con `scope = sync`
(la importación que corre dentro de la sincronización no crea el suyo: un solo informe por carga). La validación no concilia, porque no cargó nada.
El informe lleva `window_from` / `window_to` (rango de fechas de venta de la carga, que W2-B usa para sumar cargas dentro de una ventana), `layers` y `discrepancies`.

| Capa | Contenido (ventas) |
|---|---|
| `source` | lo **leído del origen**, también lo inválido: `rows`, `documents`, `lines`, `units` (valor absoluto de la cantidad de las líneas que no son devolución), `net_amount` (firmado: las devoluciones y notas de crédito restan), `voided`, `credit_notes`, `duplicates`; en sincronización, además `rows_extracted` |
| `bronze` | `object_id`, `kind`, `sha256`, `size_bytes`, `rows` (filas del original) y `schema_version` |
| `silver` | lo **escrito o ya vigente según las versiones vigentes**: `documents`, `lines`, `units`, `net_amount` de las **ventas efectivas** (documentos `completed`), `new` / `updated` / `unchanged` / `stale_skipped` / `quarantined`, `voided`, `credit_notes`, `voided_units`, `voided_net_amount` |
| `gold` | `null`: la capa Gold se concilia por ventana (W2-B), no por carga |

`documents` y `lines` comparan lo presente en Silver en cualquier estado; `units` e `net_amount` comparan las **ventas efectivas**. Cada fila del origen termina en
exactamente un destino: Silver o una categoría **conocida** de discrepancia:

| Categoría | Qué explica |
|---|---|
| `quarantine` | documentos retenidos en cuarentena (con hallazgos de revisión) |
| `rejected_rows` | filas inválidas no retenidas (p. ej. entidades sin cuarentena) |
| `stale` | documentos descartados por traer una versión anterior a la almacenada |
| `duplicates_in_file` | filas idénticas repetidas dentro del documento, omitidas |
| `zero_quantity` | líneas de cantidad cero, omitidas |
| `voided` / `pending` | documentos que SÍ están en Silver (trazabilidad) pero no cuentan como venta |

Cada discrepancia es `{from, to, metric, expected, observed, difference, categories, unexplained, explained, explanation}`. **Estado**: `balanced` (sin
diferencias), `explained` (todas explicadas por categorías conocidas; es el caso normal con anuladas, duplicados, versiones anteriores o cuarentena) o
`unexplained` (queda una diferencia sin categoría, o el objeto Bronze no contiene las mismas filas que se leyeron). Las demás entidades concilian por filas
(`source.rows` frente a `silver.rows`, con `rejected_rows` explicado).

Verificado por (IMPLEMENTADO / VERIFICADO): `LoadReconciliationTest` (`balanced` con las tres capas y su rango de fechas; `explained` por duplicado, anulado, versión anterior y
cuarentena; `unexplained` por Bronze con otras filas y por una diferencia sin categoría; entidades que no son ventas) y
`SqlIngestionQualityTest::test_the_import_report_is_linked_to_the_sync_with_transformations_and_the_sync_reconciles_all_layers` (sincronización: origen → Bronze → Silver).

**Linaje que se escribe** (IMPLEMENTADO / VERIFICADO, `BronzeLineageTest`, `SqlIngestionQualityTest`): `bronze_object_id`, `source_row` (primera fila del documento en el origen; en
lotes, posición del documento) y `schema_version` = `CanonicalContracts::version('sales')` en `sales`; las **revisiones archivadas conservan el linaje de su versión**; el inventario lleva el
mismo linaje. Captura Bronze antes de normalizar en los tres caminos (archivo, extracción SQL en NDJSON comprimido, cuerpo del lote). Detalle en `docs/data/DATA_QUALITY_ENGINE.md` §4.2.

**Estado resumido de esta sección.**

| Estado | Elemento |
|---|---|
| IMPLEMENTADO / VERIFICADO | reconciliación por carga (archivo y sincronización) con capas, categorías y estados; linaje en ventas, revisiones e inventario; Bronze de archivo, extracción SQL y lote; `predictily:bronze-reprocess` |
| DISEÑADO / NO IMPLEMENTADO | reconciliación de `POST /sales/batch` (el lote deja Bronze, linaje e informe de calidad, pero no `reconciliation_reports`); capa Gold por carga; bloqueo automático del Gate A por una diferencia `unexplained` (es de la evaluación de gates) |
| IMPLEMENTADO / VERIFICADO | el reprocesamiento desde Bronze no es una carga nueva: su informe de reconciliación va sin `window_from`/`window_to` (la reconciliación por ventana de W2-B no lo suma) y con `layers.reprocess_of_bronze_object_id`; la huella `source_hash` es invariante a la forma del código de cliente (`IngestionHardeningTest`) |
| LIMITACIÓN | en una sincronización la capa `source` se cuenta sobre el CSV de preparación (1:1 con las filas extraídas, ya con mapeos de valores, tipos y zona horaria aplicados) y se contrasta con `rows_extracted`; el original crudo (Bronze) se compara por número de filas, no valor a valor |

<!-- W2-B: reconciliación por ventana -->

## Reconciliación por ventana fuente → Bronze → Silver → Gold (W2-B)

> Paquete W2-B · ADR-V25-04 y ADR-V25-24. La reconciliación **por carga** (`scope import|sync`) es de W2-A y se documenta en su propia sección;
> esta describe la **por ventana** (`scope window`). Marcas: **IMPLEMENTADO/VERIFICADO** con la prueba que lo demuestra (todas en
> `backend/tests/Feature/Lineage/WindowReconciliationTest.php` salvo que se indique), **DISEÑADO/NO IMPLEMENTADO**, **BLOQUEADO**, **NO APLICA**.

### Qué hace — IMPLEMENTADO/VERIFICADO

```
cd backend && php artisan predictily:reconcile <tenant_uuid> --entity=sales --from=AAAA-MM-DD --to=AAAA-MM-DD
```

Compara, para una ventana de **fechas de negocio** de las ventas de UNA empresa (máximo `bronze.reconcile_max_window_days` = 366 días, ambos
extremos incluidos), cuatro capas y guarda un informe `reconciliation_reports` con `scope = window`, las capas, las discrepancias y el estado.
Salida del comando: la tabla de capas y una línea por discrepancia. **Códigos de salida:** 0 = `balanced` o `explained`; 1 = argumentos
inválidos (empresa inexistente, fechas, ventana demasiado larga, entidad no soportada); 2 = diferencias **sin explicar** (para programarlo y
alertar). Cada informe queda en la bitácora (`reconciliation.window`). Consulta: `GET /api/v1/lineage/reconciliation` (filtros `scope`, `entity`,
`status`, `from`, `to`) y `GET /api/v1/lineage/reconciliation/{id}`, ambos con `data.view`; un informe de otra empresa responde 404
(`::test_the_api_lists_and_shows_reports_of_the_active_company_only`). Solo la entidad `sales` está implementada; las demás responden «DISEÑADAS»
(`::test_it_validates_entity_dates_and_window_length`). Los informes son historial de solo inserción (`::test_reconciliation_reports_are_append_only_history`).

### Las cuatro capas

| Capa | De dónde sale | Métricas | Cuando no hay datos |
|---|---|---|---|
| **source** | `layers.source` de los informes **por carga** (`import`/`sync`, entidad `sales`) cuyo rango `window_from..window_to` queda **por completo dentro** de la ventana; de cada carga cuenta solo su informe más reciente, y de varias cargas del **mismo original** (mismo `bronze_object_id`: el mismo archivo enviado dos veces) solo la más reciente (`duplicate_loads_ignored`) | `rows`, `documents`, `lines`, `units`, `net_amount`, `voided`, `credit_notes` (más `duplicates` y `quarantined` si la carga los declaró) | `available = false` y la razón; **la capa no trae ni una cifra** (`::test_silver_counts_the_current_versions_and_an_absent_source_layer_is_reported_without_inventing_figures`) |
| **bronze** | objetos Bronze referenciados por los documentos de la ventana (`sales.bronze_object_id` y revisiones) o por las cargas seleccionadas | `objects`, `bytes`, `rows` (suma de las filas de los objetos: `row_count` o `metadata.rows_read`; **`null` si algún objeto no las declara**, nunca una suma parcial), `loads_rows` (filas de los originales de las cargas seleccionadas, `null` si no están todas), `objects_without_row_count`, `purged`, `object_ids` (hasta 100) | `available = false` y la razón (ningún documento ni carga de la ventana referencia un objeto) |
| **silver** | **versiones vigentes** de `sales_line_versions` (una por documento: invariante de W1, no se reimplementa) y los documentos de `sales` con `sale_date` en la ventana | `documents`, `voided`, `credit_notes`, `lines`, `units` y `net_amount` de las **ventas efectivas** (documentos `completed`; `units` suma las líneas que no son devolución y `net_amount` es el importe neto **firmado**: las devoluciones y notas de crédito restan; los anulados y pendientes no cuentan — la misma base que la reconciliación por carga de W2-A) más `sales_net_amount` y `returns_net_amount`, y `documents_without_bronze`, `documents_without_bronze_after_capture` | siempre disponible (ventana vacía = ceros) |
| **gold** | `data_snapshots.row_counts.sales` de la **última ejecución de demanda completada** (`forecast_runs.status = completed` con huella) cuyo rango de datos llega al fin de la ventana | `rows` (celdas sucursal × producto × día de ventas válidas, de TODA la historia hasta el corte de esa ejecución), `silver_cells_known_at_cutoff`, `silver_cells_known_at_run` | `available = false` y la razón (sin ejecución que cubra la ventana, o sin `row_counts` de ventas) |

Silver cuenta solo la versión vigente: una corrección posterior archiva la anterior y la ventana no suma líneas dos veces; los documentos de otra
empresa o fuera de la ventana no cuentan (`::test_silver_uses_only_the_current_version_of_a_corrected_document_and_only_the_window`).

### Comparaciones y estado

Solo se comparan las capas disponibles. Cada diferencia es una entrada `{from, to, metric, expected, observed, explained, explanation}`.

1. **source → silver** (`documents`, `lines`, `voided`, `credit_notes`, `units`, `net_amount`; importes con tolerancia de 0,01 más medio centavo por carga, porque cada carga redondea a centavos).
   Lo que `source` cuenta de TODO lo leído (también los anulados, las filas en cuarentena o rechazadas) se compara con las ventas efectivas de Silver, así que la diferencia normal
   (p. ej. un documento anulado) la explican los informes por carga: la parte explicada de una entrada `source → silver` de un informe de W2-A es `difference − unexplained`
   (la suma de sus categorías). Una entrada sin esos campos solo cuenta si el informe está `explained` y trae una explicación. La parte que las cargas no cubren queda «sin explicar» y
   el mensaje dice cuánto (`::test_a_voided_document_is_explained_by_the_load_report_in_the_w2a_format`, `::test_partially_explained_differences_report_how_much_remains_unexplained`).
   Un informe de carga `unexplained` sin banderas por entrada nunca explica nada (`::test_a_load_report_marked_unexplained_never_counts_as_explanation`).
   **No se compara** (y se informa la razón en `layers.source.reason`, con `comparable = false`) si alguna carga cruza el límite de la ventana (no se puede
   prorratear) o si los rangos de las cargas se solapan (los totales declarados no son aditivos) (`::test_loads_crossing_the_window_or_overlapping_are_reported_but_not_compared_and_never_balanced`).
   Se ignoran los informes fuera de la ventana, sin rango, sin totales declarados y los de otra empresa (`::test_only_the_latest_report_of_each_load_counts_and_unusable_reports_are_ignored`).
2. **bronze → silver (linaje):** cada documento de la ventana debe rastrearse a su objeto. Los que no tienen `bronze_object_id` y se ingirieron **antes** de la primera
   captura Bronze de la empresa (o la empresa no tiene objetos) son una brecha **explicada**; los ingeridos **después** de empezar la captura son **sin explicar**
   (una ruta de escritura que no captura el original) (`::test_bronze_layer_counts_the_objects_referenced_by_the_window_and_flags_lineage_gaps`).
3. **silver → gold:** las celdas de Gold se comparan con las de Silver reconstruidas con la regla as-of (`known_from`/`known_until` de la vista, igual que
   `engine/predictily_engine/data/asof.py`) al **corte** de la ejecución; si no coinciden se prueba el **día de la ejecución** (una ejecución vigente conoce lo recibido
   hasta hoy), y entonces la diferencia queda **explicada**; si no coincide con ninguna, es sin explicar (`::test_gold_cells_are_compared_with_silver_at_the_cutoff_of_the_covering_run`,
   `::test_a_live_run_that_knew_late_documents_is_explained_not_a_discrepancy_of_silver`). Gold se compara **por ejecución completa**, no por ventana: la ventana solo elige
   qué ejecución la cubre.

**Estado:** `balanced` (se compararon fuente → Silver, el linaje Bronze → Silver y Silver → Gold, y no hay ninguna diferencia), `explained` (todas las diferencias están
explicadas, **o alguna capa no se pudo comparar**) o `unexplained` (alguna sin explicar). **Nunca `balanced` sin comparar:** una capa no disponible o disponible pero no comparable
(cargas solapadas, ventana que corta una carga, sin ejecución de demanda que cubra la ventana) agrega una discrepancia visible
`{metric: layer_not_compared, from, to, explained: true, explanation: «Capa … NO comparada: <razón>»}`, lleva `compared = false` y la API la devuelve en `unavailable_layers` (con la
razón) y fuera de `compared_layers`; el comando la muestra como `NO COMPARADA`. Pruebas: `::test_a_report_is_never_balanced_when_a_layer_was_not_compared`,
`::test_loads_crossing_the_window_or_overlapping_are_reported_but_not_compared_and_never_balanced`, `::test_the_same_file_loaded_twice_counts_once_and_a_tampered_silver_is_unexplained`.
Fuente → Bronze (`rows`): con todas las cargas seleccionadas con objeto y filas conocidas, `source.rows` debe igualar `bronze.loads_rows`; si no, discrepancia sin explicar
(`::test_source_rows_are_compared_with_the_bronze_objects_of_the_loads_and_partial_sums_are_never_shown`).

### Contrato que esta reconciliación espera de los informes por carga (W2-A) — IMPLEMENTADO/VERIFICADO

Para que la capa source exista, cada informe por carga debe: tener `scope` `import` o `sync`, `entity = sales`, `import_job_id`/`source_sync_id` (identifican la carga),
`window_from`/`window_to` = rango de **fechas de documento** que leyó la carga, `layers.source` con los totales de TODO lo leído del origen (`documents`, `lines`, `voided`,
`credit_notes`, `units` de las líneas que no son devolución y `net_amount` firmado), un `status` honesto y, para lo que explica, discrepancias
`{from: source, to: silver, metric, expected, observed, difference, unexplained, explained, explanation}`. Verificado de punta a punta con la captura REAL de W2-A (`IngestionQuality::persistReconciliation`, `SalesLoadTally`): `WindowReconciliationLoadsTest` importa archivos CSV por la API y
reconcilia la ventana sin preparar a mano ningún informe: la capa source existe y se compara, las filas de los originales coinciden con las declaradas, el documento anulado queda
explicado por el informe de su carga (`units`, `net_amount`), un Silver alterado fuera de las cargas queda SIN EXPLICAR, el mismo archivo cargado dos veces cuenta una vez y las
exportaciones diarias solapadas quedan visiblemente sin comparar. Las demás pruebas de `WindowReconciliationTest` preparan los informes por carga a mano para controlar cada caso.

### Limitaciones — DISEÑADO/NO IMPLEMENTADO

- Sin ruta `POST` para pedir una reconciliación: solo el comando (programarlo es decisión de operación).
- Una diferencia `unexplained` de importe neto **no bloquea todavía el Gate A** de forma automática (ADR-V25-24): el informe es la evidencia; el bloqueo automático es de la evaluación de gates.
- Solo ventas. Inventario, compromisos y demás entidades: DISEÑADOS.
- Los objetos Bronze que no produjeron ningún documento de la ventana ni tienen informe por carga no son descubribles por ventana (haría falta registrar el rango de fechas en el objeto).
- Gold solo cuenta ventas válidas del motor de demanda; los demás cores (Customer, Supply) no se reconcilian por celdas.
