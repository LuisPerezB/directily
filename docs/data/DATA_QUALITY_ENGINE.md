# Data Quality Engine (V2.5 §5)

Decisión: `docs/architecture/adr/V25-ADRS.md` (ADR-V25-05 y ADR-V25-24). Migración:
`backend/database/migrations/2026_03_03_000100_create_data_quality_lineage_and_identity_tables.php`.

Estados de cada apartado: **IMPLEMENTADO/VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO/NO
IMPLEMENTADO**, **BLOQUEADO**, **NO APLICA**.

## 1. Contrato común de hallazgos

Un hallazgo tiene clase, código estable, entidad, referencia del registro sin PII, fila de origen, campo,
valor original acotado (el registro íntegro queda en Bronze), propuesta, si se aplicó y motivo. Clases:

| Clase | Significado | Efecto |
|---|---|---|
| `AUTO_FIX_SAFE` | normalización segura y reversible | se aplica y se registra (agregado) |
| `REVIEW_REQUIRED` | requiere decisión humana o un mapeo de valores | el registro va a cuarentena |
| `QUARANTINED` | dato inválido que no se puede corregir con seguridad | el registro va a cuarentena |
| `WARNING` | anomalía que no impide usar el dato | se informa |
| `BLOCKER` | impide la carga completa | la carga se bloquea |

<!-- Sección 2 a 4: paquete W2-A (ingestión). Sección 5 a 7: paquete W2-C (métricas y gates del motor). -->

## 2. Catálogo de códigos de ingestión y su clase (W2-A)

**IMPLEMENTADO / VERIFICADO.** Un solo catálogo código → clase, `App\Services\DataCore\Quality\DataQualityCatalog`
(`backend/app/Services/DataCore/Quality/DataQualityCatalog.php`). Parte de `Issue::DQ_CLASS_BY_CODE` (W1-A1: tipo y estado desconocidos =
REVIEW_REQUIRED), que sigue siendo la fuente de esos dos códigos, e incluye los códigos nuevos de W1. Pruebas:
`DataQualityCatalogTest` (parte de `Issue::DQ_CLASS_BY_CODE`; cada código que emite el importador está catalogado; cada código del catálogo
está documentado en este apartado; la huella `rules_version` cambia con las reglas, los parámetros y los mapeos) y
`IngestionFindingsTest::test_each_issue_code_maps_to_the_documented_class_by_severity`.

**Cómo se decide la clase.**

- Depende del **código** y de la **severidad**. Un código que el importador emite como error y como advertencia (`required`, `invalid_number`,
  `invalid_date`, `future_date`, `unknown_product`, `duplicate_in_file`…) tiene la clase de su versión *error*; la misma situación como
  *advertencia* es siempre WARNING (una advertencia no detiene el registro, así que nunca es BLOCKER, REVIEW_REQUIRED ni QUARANTINED).
- Un código sin catalogar se clasifica por severidad (error → QUARANTINED; el resto → WARNING) y la prueba del catálogo falla hasta que se
  documente aquí, así que no hay códigos huérfanos.
- Las marcas de la fuente tienen código propio: `future_date` / `invalid_date` sobre `source_recorded_at` se registran como
  `future_recorded_at` / `recorded_at_unreadable` (son los nombres de las estadísticas de W1).
- `GET /imports/{id}/errors` y `errors.csv` siguen entregando `dq_class` solo para los códigos de `Issue::DQ_CLASS_BY_CODE` (contrato de
  W1, probado por `DocumentVocabularyTest`); la clase completa de cada hallazgo está en `data_quality_issues.dq_class`.
- Criterio de las clases: **BLOCKER** = estructural, impide la carga completa y bloquea siempre; **REVIEW_REQUIRED** = valor no reconocido que
  exige una decisión (mapeo de valores, alta de sucursal, conversión de unidad); **QUARANTINED** = dato inválido que no se corrige con
  seguridad (se inventaría una fecha, una cantidad o un importe); **WARNING** = anomalía utilizable; **AUTO_FIX_SAFE** = normalización
  reversible que el importador ya aplica.

**BLOCKER**

| Código | Justificación |
|---|---|
| `bronze_capture_failed` | El original no pudo guardarse en Bronze ANTES de normalizar: sin él no hay linaje ni reprocesamiento; la carga se detiene. |
| `source_columns_missing` | El mapeo aprobado referencia columnas que la consulta ya no devuelve: ninguna fila puede leerse completa. |
| `empty_file` | El archivo no tiene encabezados o está vacío: no hay nada que cargar. |
| `unreadable_file` | El archivo no se pudo leer (formato corrupto o no soportado). |

**REVIEW_REQUIRED**

| Código | Justificación |
|---|---|
| `unknown_document_type` | Un tipo de documento fuera del vocabulario (cotización, proforma, guía…) no se cuenta como venta; se mapea por fuente (value_maps) o se excluye. |
| `unknown_document_status` | Un estado fuera del vocabulario (rechazada, reversada, no autorizada…) no se supone completado; se mapea por fuente (value_maps) o se excluye. |
| `unknown_branch` | La sucursal no existe y la empresa no autoriza crearla: se da de alta, se declara una sucursal por defecto o se excluye. |
| `unknown_category` | La categoría no existe y no se autoriza crearla: se da de alta o se excluye. |
| `unit_conversion_missing` | La unidad no se puede convertir a la unidad base del producto: falta declarar la conversión. |
| `conversion_conflict` | La conversión de unidades declarada contradice otra vigente: un humano decide cuál rige. |

**QUARANTINED**

| Código | Justificación |
|---|---|
| `required` | Falta un dato obligatorio; no se inventa (fecha, producto, cantidad, precio o número de documento). |
| `invalid_number` | Número no interpretable o imposible (cantidad/precio/descuento): corregirlo cambiaría el importe. |
| `invalid_date` | Fecha no interpretable: adivinarla movería la venta de día. |
| `future_date` | Fecha de emisión posterior a mañana: no es una venta ya ocurrida. |
| `negative_price` | Precio unitario negativo: un dato imposible que no se corrige con seguridad. |
| `invalid_value` | Valor fuera del dominio permitido del campo. |
| `duplicate_in_file` | Como error: número de línea repetido en el documento (el contenido no se puede conciliar). Como advertencia: fila idéntica repetida, que se omite (ver WARNING). |
| `document_conflict` | El mismo documento trae cabeceras distintas (fecha, sucursal, estado o cliente): no hay una versión única. |
| `currency_mismatch` | La moneda difiere de la de la empresa: no hay conversión declarada. |
| `unknown_product` | Como error: producto ausente del catálogo y sin autorización para crearlo. Como advertencia: se creará. |
| `capacity_unit_required` | La capacidad exige unidad y no la trae. |
| `negative_lot_quantity` | La cantidad de un lote es negativa: un lote negativo no existe y corregir el signo cambiaría el stock (W5-D, importación `inventory_lots`). |
| `expiry_implausible` | El vencimiento de un lote está a más de 15 años del corte: se presume un error de captura y adivinarlo movería pérdidas y compras (W5-D). |

**WARNING**

| Código | Justificación |
|---|---|
| `negative_quantity` | Cantidad negativa: se registra como devolución. |
| `zero_quantity` | Cantidad cero: la línea se omite. |
| `net_amount_mismatch` | El importe neto del archivo difiere de cantidad × precio − descuento; se conserva el del archivo. |
| `negative_stock` | Existencia negativa: se interpreta como quiebre de stock. |
| `unknown_supplier` | El proveedor no existe y se creará. |
| `unknown_status` | Estado no reconocido en una entidad que tolera un valor por defecto. |
| `unit_converted` | Se aplicó una conversión de unidades declarada. |
| `status_quantity_mismatch` | El estado del compromiso y las cantidades no concuerdan. |
| `status_overridden` | El estado declarado se reemplazó por el que imponen las fechas. |
| `scope_ref_ignored` | La referencia de alcance se ignoró porque el alcance no la admite. |
| `known_before_issued` | La fecha de conocimiento es anterior a la emisión. |
| `known_at_unknown` | No se conoce desde cuándo se sabía el dato (queda sin fecha de disponibilidad). |
| `known_after_start` | El dato se conoció después del inicio del evento. |
| `invalid_tax_id` | La identificación tributaria no es válida; no se usa para identificar al cliente. |
| `fulfilled_exceeds_quantity` | La cantidad cumplida excede la comprometida. |
| `cancelled_without_date` | Compromiso cancelado sin fecha de cancelación. |
| `cancelled_before_issued` | Compromiso cancelado antes de emitirse. |
| `document_number_shared_with_unknown_type` | Un documento reconocido comparte número con filas de un tipo desconocido (numeración por tipo o serie): se escribe y se avisa (W1, hallazgo 7). |
| `stale_document_skipped` | Llegó una versión ANTERIOR (por fecha de registro en la fuente) a la ya guardada: no se aplica (ADR-V25-23). |
| `stale_skipped` | Alias del anterior en estadísticas: documentos descartados por versión anterior. |
| `updated_without_source_order` | Se actualizó un documento sin poder comparar el orden de versiones (falta la fecha de registro en la fuente): gana la última carga. |
| `unchanged_mode_mismatch` | Documentos sin cambios cuyo modo de ingestión almacenado difiere del declarado: declarar otro modo no reescribe. |
| `future_recorded_at` | La fecha de registro en la fuente es posterior a ahora + tolerancia: se deja desconocida y no ordena versiones. |
| `recorded_at_unreadable` | La fecha de registro en la fuente es ilegible: se deja desconocida y no ordena versiones. |
| `missing_dates` | Días sin ventas dentro del rango cargado (feriados, cierres o datos faltantes). |
| `existing_document_changed` | Documentos que ya existían con contenido distinto: se actualizarán y se archivará la versión anterior. |
| `existing_commitment_changed` | Compromisos que ya existían con contenido distinto: se actualizarán. |
| `voided_document` | Documentos anulados: se guardan para trazabilidad y se excluyen de la demanda. |
| `empty_document` | Documento sin líneas válidas. |
| `documents_quarantined` | Documentos con hallazgos de revisión retenidos en cuarentena (no se descartan): se liberan o se descartan con un motivo. |
| `schema_drift` | El esquema de la fuente cambió respecto de la carga anterior (columnas agregadas o quitadas, sin valores). |
| `decimal_separator_ambiguous` | Número con una sola coma seguida de exactamente tres dígitos (p. ej. «1,234»): puede ser 1,234 o 1234. Se lee como miles (1234) salvo que la carga declare el separador decimal; confirmar el formato de la fuente. |
| `date_format_ambiguous` | Fecha en d/m/a con día y mes ≤ 12: se interpretó como día/mes (convención de la empresa); confirmar el formato de la fuente. |
| `alias_resolved` | El código del maestro de productos es un alias vigente de otro producto (cambio de código): se actualiza el producto canónico en lugar de crear un duplicado (ADR-V25-24). |
| `lot_expired_on_snapshot` | El lote ya estaba vencido en la fecha de corte: no es vendible; se escribe y el motor lo excluye de la existencia utilizable (W5-D). |
| `lot_expiry_missing` | Un producto perecible trae un lote sin vencimiento: el motor lo trata como existencia sin vencimiento, no inventa la fecha (W5-D). |
| `expiry_exceeds_shelf_life` | El vencimiento del lote excede la vida útil declarada del producto: revisar la vida útil o la fecha (W5-D). |
| `lots_exceed_on_hand` | Los lotes de una serie suman más que la existencia del snapshot del mismo día: el motor limita los lotes a la existencia (W5-D). |
| `perishable_without_shelf_life` | Producto perecible sin vida útil declarada: el plan no proyecta vencimientos sin vida útil ni lotes (W5-D). |
| `shelf_life_without_perishable` | Vida útil declarada en un producto no perecible: el plan no la usa (W5-D). |
| `lots_replaced` | Una foto de lotes se reemplazó: los lotes de la misma sucursal × producto × fecha que el archivo no trae se eliminan (W5-D). |

**AUTO_FIX_SAFE**

| Código | Justificación |
|---|---|
| `whitespace_trimmed` | Espacios sobrantes al inicio, al final o repetidos: se recortan. |
| `decimal_separator_normalized` | Separador decimal/de miles distinto del punto: se normaliza a número. |
| `date_format_normalized` | Fecha en un formato inequívoco (ISO con barras/puntos o con día > 12): se normaliza a ISO. |
| `code_case_normalized` | Códigos (documento, SKU, sucursal) con minúsculas: se comparan en mayúsculas. |

## 3. Cuarentena y reprocesamiento (W2-A)

**IMPLEMENTADO / VERIFICADO** para documentos de **ventas** en importación de archivo y sincronización SQL
(`QuarantineFlowTest`, `LoadReconciliationTest::test_quarantined_documents_are_explained`, `SqlIngestionQualityTest::test_an_unknown_status_goes_to_quarantine_with_skip_invalid_rows_and_is_released_after_a_value_map`,
`IngestionQueryCostTest`).

**Qué se retiene y cuándo.**

| Situación | Resultado |
|---|---|
| `skip_invalid_rows = true` y el documento tiene hallazgos REVIEW_REQUIRED o QUARANTINED | el documento (todas sus filas) se guarda en `quarantine_records` **en lugar de descartarse**; el resto de la carga se escribe |
| `skip_invalid_rows = false` | comportamiento de siempre: la confirmación se bloquea (`has_errors`, 409); los hallazgos quedan registrados; no hay cuarentena |
| hallazgo BLOCKER | bloquea **siempre**: la carga se detiene (Bronze no disponible, archivo vacío o ilegible, mapeo SQL roto) y `confirm` responde `blocked_by_quality` (409) aunque haya `skip_invalid_rows` |
| fila sin número de documento | se retiene sola con la clave `sin_numero\|fila N` |

- Se retiene con la validación **proyectada** (`summary.documents_quarantined`; cuenta registros: una fila sin número es un registro) y se escribe
  al **confirmar**. Un reintento de la misma confirmación reemplaza los pendientes de esa importación. **Reprocesar el mismo original** (Bronze) no
  vuelve a retener lo que ya tiene un registro en CUALQUIER estado —pendiente (duplicado), liberado o superado (ya está en Silver o lo reemplazó
  otra carga) ni descartado (un descarte no se reabre)— y la clave de una fila sin número es estable (`sin_numero|fila N` + el objeto Bronze).
  Prueba: `QuarantineSafetyTest::test_reprocessing_does_not_duplicate_unnumbered_rows_nor_reopen_discarded_or_released_records`.
- **Payload** = filas normalizadas (campos canónicos con los mapeos de valores ya aplicados) y **minimizadas**: texto acotado, sin nombres de
  clientes, y el código de cliente **tal como se guarda en `customers`** (HMAC `id:…` para cédula/RUC; `CONSUMIDOR_FINAL`; junto con su tipo y huella HMAC,
  para poder crear al comprador al liberar, y nunca la cédula en claro). Los campos de texto libre (`document_type`, `status`, `channel`,
  `customer_segment`, `customer_province`, `product_name`, `category`) pasan por `PiiMasker` (una identificación en una columna mal mapeada queda
  `[ID]`). **Excepción documentada: un código de cliente de formato LIBRE** (p. ej. «CI 1710034065», que `CustomerIdentity` no reconoce como
  cédula por no ser exactamente 10 o 13 dígitos) se guarda tal cual, igual que en `customers.external_code`: enmascararlo en el payload crearía
  otro comprador al liberar. La API (`GET /{id}`) no devuelve nunca la huella `tax_id_hash`: solo el tipo del comprador. Enlazado a su objeto
  Bronze, importación y sincronización. `issue_codes` lista los códigos de error.
- Es por lotes: una pasada auxiliar sobre el archivo recoge las filas de los documentos retenidos y un `INSERT` por 500 registros; no hay
  consultas por documento (`IngestionQueryCostTest`: mismas consultas con 5 y con 60 documentos, con la mitad en cuarentena).

**API** (`QuarantineController`; aislada por empresa: otra empresa recibe 404):

| Ruta | Habilidad | Efecto |
|---|---|---|
| `GET /api/v1/data-quality/quarantine` | `data.view` | lista paginada; filtros `entity`, `status` (`pending`, `released`, `discarded`, `superseded`), `import_job_id`, `source_sync_id`, `per_page` |
| `GET /api/v1/data-quality/quarantine/{id}` | `data.view` | detalle con `payload` mínimo, `resolution` y los hallazgos del registro |
| `POST /api/v1/data-quality/quarantine/{id}/release` | `imports.manage` | revalida con los mapeos de valores **vigentes**; si es válido escribe y devuelve 200; si no, **422** `quarantine_still_invalid` con los hallazgos que quedan; **409 `newer_version_exists`** si sobrescribiría una versión más nueva (ver abajo). Cuerpo opcional `value_maps` y `force` |
| `POST /api/v1/data-quality/quarantine/{id}/discard` | `imports.manage` | `reason` obligatorio (3–500 caracteres); no escribe en Silver |

- **Mapeos vigentes al liberar** (de menor a mayor precedencia): los de la versión **aprobada y activa** del mapeo SQL de ventas de la fuente
  (para registros de una sincronización: se agregan creando y aprobando una versión nueva del mapeo, que exige `datasources.manage`), los
  de `import_jobs.options.value_maps` y los que se envíen en `value_maps` de la liberación. Estos últimos se validan contra el vocabulario
  canónico y, si la liberación tiene éxito, **se guardan en la importación** para las siguientes liberaciones.
- **Escritura**: `SalesDocumentWriter` en modo **`incremental`** (el documento es conocido desde la liberación, no desde su día de negocio:
  no reescribe el pasado), con `bronze_object_id` del original y `source_row` de su primera fila. Si ya había una versión igual
  (`unchanged`) o más nueva (`stale_skipped`), el registro igual queda `released` y el estado de escritura se informa en `write.state`.
- Los hallazgos del registro pasan a `reprocessed` (liberado) o `rejected` (descartado). Liberar y descartar quedan auditados
  (`quarantine.released`, `quarantine.discarded`).

**Liberar frente a cargas posteriores — IMPLEMENTADO / VERIFICADO** (`QuarantineSafetyTest`): liberar NUNCA sobrescribe en silencio una versión más nueva.

- **Supersesión.** Cuando una carga posterior (importación, sincronización, lote o la liberación de otro registro) deja BIEN en Silver un documento
  (`new`, `updated` o `unchanged`), sus registros PENDIENTES con la misma clave `tipo|NÚMERO` pasan a **`superseded`** (estado nuevo; `payload.resolution`
  = `{action: superseded, reason: later_load | released_record, at}`; una sentencia por lote). Liberar o descartar un registro superado responde 409
  `invalid_state`. Un registro de tipo no reconocido (`unknown|NÚMERO`) no se supera solo: el mismo número puede ser de otro tipo o serie, y ahí
  actúa la protección siguiente.
- **Versión más nueva.** Antes de escribir se clasifica el documento contra Silver. Si ya existe con OTRO contenido, se escribió DESPUÉS de que el
  registro entró a la cuarentena (`sales.updated_at > quarantine_records.created_at`) y no hay orden de fuente que decida (falta
  `source_recorded_at` en alguna de las dos versiones), responde **409 `newer_version_exists`** con `context.current_versions` (la versión vigente:
  tipo, número, estado, total, revisión, modo, marca de fuente y cuándo se escribió) y no escribe nada. Se puede **descartar** el registro o liberarlo
  con **`force = true`**: se escribe (la versión sobrescrita se archiva como siempre) y queda auditado (`quarantine.released` con
  `forced_over_newer_version`, los números de documento sobrescritos y el usuario) y en `payload.resolution`. Con orden de fuente manda la regla de W1
  (`stale_skipped` o actualización) y con una versión guardada ANTES de la cuarentena la liberación es la actualización esperada (sin `force`).

**Reprocesamiento desde Bronze** — **IMPLEMENTADO / VERIFICADO** (`BronzeReprocessTest`, `SqlIngestionQualityTest::test_reprocessing_the_raw_extraction_is_idempotent`, `IngestionHardeningTest`):
`php artisan predictily:bronze-reprocess {id} [--json] [--force]` re-ejecuta la normalización y la escritura de un objeto Bronze de **ventas**
(verifica su SHA-256 antes de leerlo). Usa el mapeo de columnas y las opciones de la última importación de ese original (archivo), la versión
del mapeo SQL con que se extrajo (extracción, con la misma transformación del conector) o las opciones y el modo del lote (REST). Es
**idempotente** (una segunda vez, los documentos vigentes dan `unchanged`), no destruye nada, no consume la cuota mensual de filas y no
reasigna la importación de la sincronización original (crea una importación propia marcada `reprocess_of_bronze_object_id`).

- **Seguridad frente a versiones más nuevas.** Reprocesar un original VIEJO que no trae `source_recorded_at` ganaría por «última ingestión» y revertiría
  documentos que se escribieron después. Antes de escribir se proyecta (validación); si algún documento existente con otro contenido se escribió
  DESPUÉS del original y no hay orden de fuente, el comando se detiene con un aviso (código de salida 1, API: 409 `reprocess_would_overwrite_newer`) y no
  deja una importación colgada. **`--force`** lo confirma y la salida (y `--json` → `overwrote_newer_documents`) informa cuántos documentos más
  nuevos sobrescribió. Con orden de fuente manda `stale_skipped`, así que no hay aviso. Prueba:
  `IngestionHardeningTest::test_reprocessing_an_old_original_that_would_revert_newer_data_is_refused_unless_forced` (archivo y lote).
- **Marcado.** El informe de calidad de un reprocesamiento lleva `dataset = «<entidad>_reprocess»`, `summary.reprocess_of_bronze_object_id` y
  `metrics.reprocess_of_bronze_object_id`: el % de filas inválidas de los gates (que suma por dataset) no cuenta dos veces las filas ya contadas
  por la carga original. Su reconciliación no es una carga nueva: sin `window_from`/`window_to` y con `layers.reprocess_of_bronze_object_id`.

**Estado.**

| Estado | Elemento |
|---|---|
| IMPLEMENTADO / VERIFICADO | cuarentena de ventas (archivo y sincronización SQL), API de revisión, liberación con revalidación, descarte con motivo, aislamiento y habilidades, BLOCKER bloqueante, reprocesamiento idempotente de archivo, extracción SQL y lote |
| DISEÑADO / NO IMPLEMENTADO | cuarentena de entidades distintas de ventas (sus filas inválidas se descartan con `skip_invalid_rows`, pero el original queda en Bronze y los hallazgos en `data_quality_issues`); cuarentena de los documentos que `POST /sales/batch` rechaza (se devuelven con su detalle al integrador y el cuerpo íntegro queda en Bronze); editar el payload antes de liberar; liberación masiva; alerta por registros pendientes antiguos |
| RESUELTO | un documento liberado se escribe con el código de cliente en la forma de `customers`; si el origen lo reenvía idéntico ya no se archiva una revisión sin cambio visible: `source_hash` usa esa forma (ver «Huella del documento» en §4.2) |
| LIMITACIÓN | `import_errors` (tabla de W1) solo enmascara las columnas de cliente y los correos: su `raw_value` y su `message` pueden citar una identificación mal mapeada como tipo o estado. Los hallazgos de `data_quality_issues` y el payload de la cuarentena sí la enmascaran |
| LIMITACIÓN | varios tipos desconocidos distintos que comparten el mismo número se retienen en UN registro `unknown\|NÚMERO` (su payload conserva cada fila con su tipo crudo); un mapeo parcial deja el registro en 422 hasta mapear todos |

## 4. Informes de ingestión, mapeos de valores y deriva de esquema (W2-A)

### 4.1 Informe de calidad de cada carga

**IMPLEMENTADO / VERIFICADO** (`IngestionFindingsTest`, `BronzeLineageTest`, `SqlIngestionQualityTest`). Toda importación (validación y
confirmación; cada una reemplaza el informe anterior de la misma importación), toda sincronización SQL (su importación) y todo lote
`POST /sales/batch` (alcance `sales_batch`) amplía su fila de `data_quality_reports`:

| Columna | Contenido |
|---|---|
| `dataset` | entidad (`sales`, `inventory`, `products`…) |
| `issue_counts` | `{clase: n}` con las cinco clases (hallazgos observados, no solo los guardados); si se aplicó el tope: `truncated: true`, `issue_rows_stored`, `issue_rows_omitted` (lea solo las cinco claves de clase para sumar) |
| `rules_version` | SHA-256 del catálogo código → clase, los parámetros de las reglas (tolerancia de `source_recorded_at`, tope de filas, longitud de valores, tolerancia de importes), `DataQualityCatalog::CODE_VERSION` y los **mapeos de valores** de la carga |
| `metrics` | las métricas del resumen actual + `transformations` (`value_maps` aplicados con su conteo y `auto_fix` por código y campo) + `schema` (huella, versión y deriva) + `lineage` (objeto Bronze y versión del contrato) |
| `source_sync_id`, `bronze_object_id` | la sincronización y el objeto Bronze del original |
| `summary`, `issues`, `score` | **sin cambios** (los consumen `ImportJobResource`, `DataQualityController`, el tablero, la exportación LOPDP y el criterio 4 del E2E) |

Cada hallazgo va a `data_quality_issues`: `dq_class`, `code`, `entity`, `record_ref` (**tipo|número de documento**, sin PII), `source_row`,
`field`, `original_value` (**200 caracteres como máximo**), `proposed_value`, `applied` y `reason`.

- **Enmascarado (PII).** Nombres e identificaciones de clientes (`customer_code`, `customer_name`…) se reemplazan por `[CLIENTE]`. TODO lo demás pasa
  por `PiiMasker` —`original_value`, `proposed_value` **y `reason`**, que cita el valor crudo («Tipo de documento no reconocido ('1710034065')» →
  `('[ID]')`)— salvo las columnas que son un código real de negocio: **solo `document_number`, `product_sku` (`sku` en productos) y `branch_code`**
  (un número de documento de 10 dígitos no es una cédula). `document_type` y `status` NO están exentos: una columna mal mapeada puede traer ahí una
  cédula. `record_ref` es `tipo|número de documento`. Prueba: `IngestionHardeningTest::test_an_identification_in_a_mismapped_column_never_reaches_the_findings_and_the_payload_masks_free_text`.

- **Tope**: `data_quality.max_issue_rows_per_report` (2000 por defecto; variable `PREDICTILY_DQ_MAX_ISSUE_ROWS`). Al llegar al tope se conservan
  los hallazgos de **mayor prioridad** (un BLOCKER o REVIEW_REQUIRED desplaza a un WARNING ya guardado) y el exceso se cuenta en
  `issue_counts` con la marca `truncated`. Los agregados (AUTO_FIX_SAFE y los hallazgos de resumen) no consumen el tope.
- **AUTO_FIX_SAFE nunca es una fila por celda**: una fila por (código, campo) con conteo y muestra (3 ejemplos), `applied = true`. Se registran
  `whitespace_trimmed`, `decimal_separator_normalized`, `date_format_normalized` (año primero, o día > 12, o formato declarado) y
  `code_case_normalized`. El valor propuesto de `decimal_separator_normalized` es EXACTAMENTE el que produce `ValueParser::number` (la función del
  importador, con las opciones de la carga), no una réplica: «1.234,56» → 1234.56, «1,234.56» → 1234.56, «(1,5)» → −1.5. Una sola coma seguida de
  exactamente tres dígitos («1,234») es **ambigua** (¿1,234 o 1234?): el importador la lee como miles y se informa como WARNING
  `decimal_separator_ambiguous` —salvo que la carga declare `decimal_separator`, caso en que es una corrección segura con lo que guardará el
  importador— (`IngestionHardeningTest::test_the_proposed_number_is_what_the_importer_stores_and_the_ambiguous_case_is_a_warning`). Una fecha d/m/a con
  día y mes ≤ 12 y distintos es igualmente **ambigua**: se informa como WARNING `date_format_ambiguous` (el importador la lee como día/mes,
  convención de la empresa). Ambas advertencias llevan `applied = true`: la convención SE aplicó. Las normalizaciones de una fila con errores no se cuentan. En una sincronización SQL
  las conversiones (tipos, zonas horarias, mapeos de valores) las hace el conector al preparar el CSV y quedan en `transformations`, no como
  AUTO_FIX.
- **Reprocesos.** El informe de un reprocesamiento desde Bronze (§3) lleva `dataset = «<entidad>_reprocess»` y `reprocess_of_bronze_object_id` en
  `summary` y `metrics`, para que el % de filas inválidas de los gates no cuente las mismas filas dos veces.
- **Hallazgos de resumen** (sin fila: `stale_document_skipped`, `missing_dates`, `voided_document`, `documents_quarantined`…) se agregan con su conteo.
- Parámetros (tope 2000, 3 muestras, tolerancia de importes 0,01, tolerancia de fecha de registro 5 min): **puntos de partida a aprobar en el
  piloto**, no umbrales universales; viajan en `rules_version`.

### 4.2 Bronze antes de normalizar y linaje

**IMPLEMENTADO / VERIFICADO** (`BronzeLineageTest`, `SqlIngestionQualityTest`), siempre a través de `BronzeStore` (no se reimplementa el almacén):

| Camino | Qué se guarda | Cuándo | Enlace |
|---|---|---|---|
| Archivo subido | el archivo íntegro (`file_upload`, CSV/XLSX) | al terminar el análisis (solo lee encabezados y vista previa), antes de mapear, validar o normalizar | `import_jobs.bronze_object_id` (se conserva `stored_path`) |
| Sincronización SQL | NDJSON comprimido (`sql_extract`, `ndjson.gz`) con las columnas que usa el mapeo aprobado, una línea por fila de origen tal como la devolvió la consulta | mientras se extrae, **antes** de convertir cada fila; si falla, la sincronización se detiene (BLOCKER) | `source_syncs.bronze_object_id` y `import_jobs.bronze_object_id` (el CSV de preparación NO se guarda como original) |
| `POST /sales/batch` | el cuerpo validado (`documents`, `options`, `ingestion_mode`) (`api_batch`) | antes de normalizar; si falla, 409 `bronze_unavailable` y no se procesa nada | respuesta `data.bronze_object_id` |
| Filas canónicas por API (compromisos, calendario…) | el CSV generado (`api_batch`) | al subirlo | `import_jobs.bronze_object_id` |

- El original se guarda **tal cual llegó**, incluidas las identificaciones de clientes que envía la fuente (como ya ocurría con el archivo
  subido): es el original autorizado y su fidelidad es lo que permite reprocesar. Los controles son los del almacén (acceso del servidor y por
  empresa, retención y purga por empresa). Minimizar el código de cliente en Bronze es una decisión de política que queda abierta (con la huella
  invariante de abajo ya no rompería el reprocesamiento).
- **Huella del documento (`source_hash`).** El comprador entra a la huella en la forma en que `customers` lo guarda (HMAC `id:…` para cédula/RUC;
  `CONSUMIDOR_FINAL`; los demás códigos, sin cambio): el mismo documento da la misma huella llegue la cédula (archivo, SQL, lote) o ya su forma
  guardada (liberado de la cuarentena, reprocesado). Para no reescribir lo ya cargado, cuando la forma difiere del código crudo el documento trae
  además `legacy_source_hash` (la huella con el código CRUDO, que es la que tienen guardada los documentos escritos antes de este cambio) y
  `SalesDocumentWriter::classify` acepta ambas como «sin cambios»: no hay revisión fantasma ni al reenviar un documento liberado ni al re-sincronizar
  datos antiguos. Prueba: `IngestionHardeningTest::test_the_document_hash_is_the_same_for_the_raw_code_and_the_stored_form_and_legacy_hashes_stay_unchanged`
  y `::test_a_released_document_resent_by_the_source_is_unchanged_and_a_legacy_hash_is_accepted`. (Cambia el valor de `source_hash` solo de los documentos
  con cédula/RUC que se vuelvan a escribir; el contrato temporal de W1 —ventanas, orden, archivo— no cambia.)
- **Linaje Silver**: `bronze_object_id`, `source_row` (primera fila del documento en el origen; en lotes, posición del documento) y
  `schema_version` (`CanonicalContracts::version('sales')`) en `sales`; `source_row` es la PRIMERA fila del documento en el origen aunque sea una línea de
  cantidad cero o repetida que se omite (`IngestionHardeningTest::test_source_row_is_the_first_row_of_the_document_even_when_it_is_a_zero_quantity_line`);
  las **revisiones archivadas conservan el linaje de SU versión**
  (`sales_document_revisions`). Inventario: mismo linaje en `inventory_snapshots` (`schema_version` = `inventory_snapshot@…`).
- La captura es idempotente: el mismo archivo para la misma empresa y entidad devuelve el mismo objeto.

### 4.3 Mapeos de valores por fuente

**IMPLEMENTADO / VERIFICADO** (`ValueMapsTest`, `SqlIngestionQualityTest`). `{document_type: {"<crudo>": "<canónico>"}, status: {...}}` en
`import_jobs.options.value_maps` (`PUT /imports/{id}/mapping`, solo ventas) y en `source_mappings.options.value_maps` (cualquier campo del
importador, como antes). Se aplican **antes** del reconocimiento de vocabulario; coincidencia exacta primero y, si no, por clave normalizada
(minúsculas, sin tildes). En ventas, el destino de `document_type` y `status` debe pertenecer al vocabulario canónico (lo que reconocen
`ValueParser::documentType` y `documentStatus`; p. ej. «anulada», «ANULADA» o «voided»): un destino fuera de él es 422 al guardar. Cada
aplicación queda en `metrics.transformations.value_maps` (valor crudo → canónico, ocurrencias) y el mapa entra en `rules_version`.

### 4.4 Deriva de esquema

**IMPLEMENTADO / VERIFICADO** (`SchemaDriftTest`, `SqlIngestionQualityTest`). Huella = SHA-256 de los encabezados normalizados (sin tildes,
minúsculas) y **ordenados**; en SQL, además el tipo observado de cada columna que devuelve la consulta. Se compara con la carga anterior de la
**misma fuente y entidad** (el último objeto Bronze; una fuente puede entregar entidades con columnas distintas, así que compararlas entre sí daría
deriva falsa). Sin `data_source_id`, con la última carga sin fuente de la misma entidad de la empresa. Si cambia: sube `schema_version`, el
objeto Bronze guarda huella, versión y lista de columnas y el informe registra el WARNING `schema_drift` con las columnas **agregadas, quitadas
y con tipo cambiado (sin valores)**. `data_sources.schema_fingerprint` guarda la huella combinada de las entidades de la fuente y
`data_sources.schema_version` sube con cada deriva. Limitaciones: en SQL los tipos son los del valor que entrega el controlador (un `numeric` de
PostgreSQL llega como texto), y un tipo aún desconocido (columna solo `NULL` en la extracción) no cuenta como cambio; la deriva de un
original idéntico byte a byte a uno ya guardado se informa en la sincronización pero el objeto conserva su huella original.

### 4.5 Reconciliación por carga

Ver `docs/data/LINEAGE_AND_RECONCILIATION.md` §13 (capas fuente → Bronze → Silver, categorías de discrepancia y estados).

### 4.6 Aclaraciones sobre cifras y límites conocidos (W2-A)

- **`rows_valid` no son filas escritas.** Es `rows_total − rows_error`: una fila sin error propio cuenta como válida aunque su documento haya quedado en
  cuarentena por otra fila. Lo realmente escrito está en `lines_written` / `silver` de la reconciliación; lo retenido, en `documents_quarantined`.
- **`documents_quarantined` y `silver.quarantined` cuentan REGISTROS** de cuarentena, no documentos: una fila sin número es un registro (con
  `documents` en 0 en la reconciliación) y varios tipos desconocidos que comparten número forman un solo registro `unknown\|NÚMERO`.
- **`unknown_amount_rows`** (capa fuente) son filas cuyo importe no se pudo derivar (falta cantidad o precio y no hay importe neto): aportan 0 al importe
  de la fuente y, como esas filas tienen error, también 0 a su destino; sirve para leer el importe de la fuente como cota inferior.
- **Reconciliación de una sincronización**: la capa fuente se cuenta sobre el CSV de preparación (1:1 con las filas extraídas, con mapeos de valores,
  tipos y zona horaria ya aplicados) y se contrasta con `rows_extracted`; Bronze guarda la extracción cruda y se compara por número de filas, no valor a valor.
- **Lote REST**: los documentos rechazados se devuelven con su detalle y no se retienen (el integrador es dueño del origen); su cuerpo íntegro queda en
  Bronze y sus hallazgos en `data_quality_issues`.
- **Bronze deduplicado**: reenviar los mismos bytes devuelve el mismo objeto; su huella y deriva son las de la primera vez.

## 5. Métricas de dataset (W2-C)

**Estado: IMPLEMENTADO/VERIFICADO** (módulo `engine/predictily_engine/quality/metrics.py` y `signals.py`; pruebas
`engine/tests/unit/test_quality_metrics.py` y `engine/tests/integration/test_quality_gates_integration.py`).

Qué son: las métricas de dataset AL CORTE que V2.5 §5 exige para cada dataset, calculadas por el motor antes de cada
ejecución y guardadas en `data_quality_reports.metrics` (JSON versionado `dq-metrics-v1`) junto con el resultado de los gates
(sección 6). Reglas que cumplen todas:

1. **As-of.** Se calculan sobre los datos que cada módulo ya cargó con la lectura as-of de W1 (`data/asof.py`: una versión es
   visible al corte C si `known_from < medianoche local (C+1)` y `known_until` es nula o `≥` esa medianoche) y sobre la
   clasificación de días de W1 (`profiling/days.py`). Lo que vive en la base (informes de ingestión, alias, desfase de ingestión,
   compradores) se lee con la MISMA regla de visibilidad y el mismo día de conocimiento (en una ejecución vigente, hoy); un informe,
   un alias o un documento creado después del corte no entra (prueba `test_signals_at_a_cutoff_do_not_change_when_data_arrives_after_it`).
2. **`missing_source` y `closed` nunca son ceros.** La proporción de ceros es `zero_confirmed / (sale + zero_confirmed)` sobre días
   observables; un quiebre (`stockout_censored`), un día de fuente ausente y un cierre no son días observables y se informan aparte
   (`zeros.zero_days_not_counted`). Prueba `test_zero_share_counts_only_confirmed_zeros_on_observable_days`.
3. **Lo que no se puede calcular se omite, no se aprueba.** Una señal de la base que falla o no se recogió queda
   `{"available": false, "reason": ...}` (con `"failed": true` si la lectura FALLÓ) y la regla que la usa queda en `not_evaluated` del gate con su
   razón: `no_data` (sin valor por falta de datos), `not_collected` (el llamador no recogió señales: ejecución sin base de datos), `signal_failed` o
   `metrics_error`. Si la causa es un fallo y la regla era BLOCK o DEGRADE, el gate emite además el motivo WARN `dq_signal_unavailable` (o
   `dq_metrics_unavailable`), que en demanda viaja en `limitations`: el resultado NUNCA es un PASS sin aviso.
4. **Solo agregados.** No se persiste una fila por serie ni por cliente; las distribuciones se resumen (mín., p10, mediana, p90, máx.).

| Métrica (ruta en `metrics`) | Definición | Fuente | Prueba que la demuestra |
|---|---|---|---|
| `coverage.first_day`, `last_day`, `calendar_days` | primer día de venta de la empresa, corte y días entre ambos | panel de demanda | `test_coverage_reports_first_last_day_and_the_share_of_branch_days_with_source` |
| `coverage.source_coverage_pct`, `missing_source_branch_days`, `missing_source_days`, `missing_source_by_basis` | fracción de sucursal-días (desde la primera venta de la sucursal) que NO son `missing_source`; días sin fuente en TODAS las sucursales; base `declared`/`inferred` | `Panel.df.day_status` | misma prueba y `test_a_company_wide_gap_counts_days_with_no_source_in_any_branch` |
| `invalid.invalid_pct` | Σ `rows_error` / Σ `rows_total` de los informes de ingestión del dataset `sales` creados en los últimos 180 días y antes del corte, contando SOLO cargas que entraron a Silver: importaciones `completed` (y sus sincronizaciones `completed`) y lotes `sales_batch` con documentos escritos. Quedan fuera las importaciones validadas, rechazadas o fallidas, los bloqueos estructurales y los reprocesos desde Bronze (marca `reprocess_of_bronze_object_id` de W2-A en el informe o en `import_jobs.options`, o dataset `<entidad>_reprocess`) | `data_quality_reports.summary` (+ `dataset` o `import_jobs.entity_type`), `import_jobs.status`, `source_syncs.status` | `test_invalid_pct_reads_only_ingestion_reports_of_the_dataset_inside_the_window_and_known_at_the_cutoff` |
| `key_integrity.*` | líneas con producto o sucursal inexistente o dado de baja / líneas; los productos inactivos (descontinuados) son historia válida y se cuentan aparte; líneas de clientes dados de baja (señal) | catálogo vs. ventas as-of; señal `identity` | `test_key_integrity_counts_lines_with_unknown_products_or_branches`, `test_an_inactive_product_is_valid_history_and_is_counted_apart` |
| `series_history.*` | distribución de días de historia y de días activos (con venta) de las series activas; series con menos de 28 días | `Panel.profile` | `test_series_history_reports_days_and_active_days_and_short_series` |
| `customer_history.*` (Customer) | compradores y relaciones comprador×producto con compras; distribución de días de compra por comprador; % de compradores y de relaciones con ≥ 2 días de compra | `CustomerData.purchases` | `test_customer_metrics_history_buyers_repeat_and_identity` |
| `zeros.*` | `zero_share` y conteo por estado de día; `zero_days_not_counted` | `Panel.df.day_status` | `test_zero_share_counts_only_confirmed_zeros_on_observable_days`, `test_stockout_days_are_not_observable_zeros_either` |
| `variability.*` | coeficiente de variación diario por serie (≥ 7 días observables y media > 0): mediana y p90 | `Panel.df` | `test_variability_is_the_cv_of_daily_units_on_observable_days` |
| `id_stability.*` | productos y clientes con ventas que tuvieron un alias `code_change`/`merge`/`split` con vigencia en el intervalo (corte − 90, corte], y proporción de productos con código nuevo (primera venta reciente; solo evaluable con al menos DOS ventanas de historia, 180 días); compradores nuevos (Customer, misma regla) | `product_aliases`, `customer_aliases`, `sales_line_versions` | `test_id_stability_counts_recent_alias_changes_and_new_codes_only_when_the_history_allows_it`, `test_customer_new_customer_share_is_not_evaluable_without_history_longer_than_the_window` |
| `identity.*` | proporción de unidades (180 días) de compradores identificados («modelables»), genéricos, anónimos y dados de baja; `basis` declara la fuente: `sales_line_versions_as_of` (baja de cliente AS-OF; la usan demanda y Customer) o, si la señal no está, `excluded_sales_stats` del Customer Core (baja en estado actual) | `sales_line_versions`, `customers`; respaldo de Customer: `excluded_sales_stats` | `test_identity_shares_split_identified_generic_anonymous_and_deleted_buyers_as_of_the_cutoff` |
| `price.coverage_share`, `price.catalog_price_share` | unidades con precio neto positivo / unidades; productos activos con precio de catálogo | ventas as-of; catálogo | `test_price_cost_and_inventory_presence` |
| `cost.coverage_share` | líneas con costo / líneas | `sales_stats` as-of | misma prueba |
| `inventory.*` | demanda: series activas con inventario y antigüedad (mediana, máx.) del último snapshot; abastecimiento: series pronosticadas con inventario y su antigüedad | `latest_inventory` / `PlanInputs.inventory` | `test_price_cost_and_inventory_presence`, `test_supply_metrics_inventory_age_lead_time_and_cost_coverage` |
| `closures_stockouts.*` | series-día cerradas, con quiebre y sin fuente / series-día | `Panel.df.day_status` | `test_closed_share_over_series_days`, `test_stockout_days_are_not_observable_zeros_either` |
| `freshness.days_since_last_sale` | días entre la fecha de referencia (hoy en una ejecución vigente; el corte en una reconstrucción histórica) y la última venta conocida | ventas as-of | `test_freshness_is_measured_against_the_run_reference_day` |
| `freshness.ingestion_lag.*` | desfase evento→ingestión (p50, p90, máx., fracción > 1 día y > 7 días) de los documentos `incremental` de los últimos 90 días | `sales.first_seen_at`, `sale_date` | `test_ingestion_lag_distribution_uses_only_incremental_documents_known_at_the_cutoff` |
| `lead_times.*`, `costs.*`, `forecast.*` (abastecimiento) | series con plazo explícito (producto, producto×sucursal u oferta con plazo); productos con costo conocido; series pronosticadas | `PlanInputs` | `test_supply_metrics_inventory_age_lead_time_and_cost_coverage` |

**Limitaciones declaradas (no esconder):**

- `invalid.*` solo cuenta informes que traen `rows_total` y `rows_error` en `summary`: hoy los de importación de archivos
  (`QualityReportBuilder`). Las sincronizaciones SQL y los lotes `POST /sales/batch` entrarán cuando W2-A les agregue ese resumen y
  el campo `dataset`; mientras tanto no aportan (ESTADO: DEPENDE DE W2-A, no inventado).
- `freshness.ingestion_lag` usa `sales.first_seen_at` (inmutable) y el `ingestion_mode` VIGENTE del documento; un documento cuya versión
  vigente pasó de `backfill` a `incremental` se cuenta como incremental.
- La estabilidad de IDs solo detecta cambios REGISTRADOS (alias) y la proporción de códigos nuevos sin alias; una recodificación silenciosa
  se ve únicamente como código nuevo reciente. La reconciliación monetaria y la fuga temporal (V2.5 §5 las nombra como bloqueos) no son
  métricas de este paquete: la primera es de W2-A/W2-B (reconciliación por carga y por ventana), la segunda de W2-D (validador de disponibilidad
  temporal). ESTADO: DISEÑADO/NO IMPLEMENTADO aquí.
- Estado NO versionado: el catálogo (productos y sucursales dados de baja, precio de catálogo) y los atributos de `customers` (`is_generic`, `external_code`) se leen
  en su estado VIGENTE; en una reconstrucción histórica una baja posterior al corte también cuenta (igual que `profile_at` solo aplica `is_active` al corte final). Las
  bajas de CLIENTES sí son as-of (`customers.deleted_at < medianoche local de C+1`) en la señal `identity`, que Customer prefiere. La cuenta propia del Customer
  Core (`CustomerRepository.excluded_sales_stats`, `data.excluded`) aplica la misma visibilidad de ventas pero toma `customers.deleted_at` en su estado ACTUAL; solo se usa
  como respaldo cuando la señal no está disponible y la métrica lo declara en `identity.basis`.
- Las métricas del dataset de `inventory`, `products` o `commitments` como datasets independientes no se calculan: solo se miden como presencia
  dentro del módulo que las usa.

## 6. Gates por módulo: PASS, WARN, DEGRADE, BLOCK (W2-C)

**Estado: IMPLEMENTADO/VERIFICADO** (módulos `quality/rules.py`, `gates.py`, `assessment.py`; pruebas
`engine/tests/unit/test_quality_gates.py` y `engine/tests/integration/test_quality_gates_integration.py`). Los **umbrales son valores
iniciales de diseño, sin calibración empírica: todos quedan «a aprobar en el piloto» con datos reales y ninguno es universal.**

### 6.1 Salida y efecto

Cada módulo evalúa su gate ANTES de entrenar y de inferir. La salida es la peor severidad de los motivos (con código estable):

| Salida | Efecto real | Dónde queda registrado |
|---|---|---|
| `PASS` | continúa sin cambios | `gate_results` |
| `WARN` | continúa; el aviso `[código] mensaje` va a los avisos de la ejecución | `warnings` (demanda), limitaciones de texto «Aviso de calidad de datos» (Customer, abastecimiento), manifiesto |
| `DEGRADE` | deshabilita las features opcionales que requieren el dato declarado insuficiente (sección 7) y baja el estado a `degraded` | `limitations` con el código y las features deshabilitadas, manifiesto, `quality_status = degraded` |
| `BLOCK` | termina la ejecución con `quality_status = insufficient_data` y el motivo (código estable) | ver abajo |

Efecto de `BLOCK` por módulo:

- **Demanda** (`pipeline/core.py::run_pipeline`, antes de construir features): lanza `InsufficientDataError` con `details = {code: "dq_gate_block",
  reason_codes, gate, rules_version}`; la API responde HTTP 422 `insufficient_data`; el run queda `failed` con el mensaje de los motivos, su
  `summary.quality_status = insufficient_data` y el informe de la ejecución (prueba `test_a_block_gate_stops_the_forecast_with_a_stable_code_and_leaves_its_report`).
- **Customer** (`customer/service.py`, entrenar y predecir): ningún horizonte se entrena ni se predice (`abstained` con `reason_code = dq_gate_block`),
  `quality_status = insufficient_data` (prueba `test_customer_block_ends_the_run_with_insufficient_data_and_a_stable_reason`).
- **Abastecimiento** (`supply/plan_runner.py`): la corrida termina sin plan (`insufficient_data`, `solver_status = not_solved`) con los códigos en
  `summary.reason_codes` (prueba `test_supply_block_is_off_by_default_and_the_tenant_can_turn_it_on`).

`DEGRADE` de Customer significa **degradar a agregado** (V2.5 §25.2: «si los IDs son inestables, deshabilitar recomendaciones individualizadas y
conservar Demand Core general»): no se entrena ni se predice por comprador, `quality_status = degraded`, el manifiesto trae `degraded_to = "aggregate"` y
el pronóstico agregado de demanda sigue disponible (prueba `test_customer_degrades_to_aggregate_no_models_are_trained_and_no_individual_predictions_are_written`).

### 6.2 Reglas (código estable → métrica → umbral)

| Código estable | Módulo | Severidad | Métrica (ruta en `metrics`) | Se cumple si | Umbral | Dato que declara insuficiente |
|---|---|---|---|---|---|---|
| `dq_demand_insufficient_history` | demand | BLOCK | `coverage.calendar_days` | valor >= umbral | `demand_min_history_days` | — |
| `dq_demand_insufficient_coverage` | demand | BLOCK | `coverage.source_coverage_pct` | valor >= umbral | `demand_min_source_coverage_pct` | — |
| `dq_demand_invalid_rate_excessive` | demand | BLOCK | `invalid.invalid_pct` | valor <= umbral | `demand_block_invalid_pct` | — |
| `dq_demand_price_coverage_low` | demand | DEGRADE | `price.coverage_share` | valor >= umbral | `demand_min_price_coverage` | `price` |
| `dq_demand_source_coverage_below_target` | demand | WARN | `coverage.source_coverage_pct` | valor >= umbral | `demand_warn_source_coverage_pct` | — |
| `dq_demand_invalid_rate_high` | demand | WARN | `invalid.invalid_pct` | valor <= umbral | `demand_warn_invalid_pct` | — |
| `dq_demand_key_integrity_issues` | demand | WARN | `key_integrity.key_issue_pct` | valor <= umbral | `demand_warn_key_issue_pct` | — |
| `dq_demand_cost_coverage_low` | demand | WARN | `cost.coverage_share` | valor >= umbral | `demand_warn_min_cost_coverage` | — |
| `dq_demand_inventory_coverage_low` | demand | WARN | `inventory.coverage_share` | valor >= umbral | `demand_warn_min_inventory_coverage` | — |
| `dq_demand_stockouts_high` | demand | WARN | `closures_stockouts.stockout_pct` | valor <= umbral | `demand_warn_max_stockout_pct` | — |
| `dq_demand_closures_high` | demand | WARN | `closures_stockouts.closed_pct` | valor <= umbral | `demand_warn_max_closed_pct` | — |
| `dq_demand_data_stale` | demand | WARN | `freshness.days_since_last_sale` | valor <= umbral | `demand_warn_max_stale_days` | — |
| `dq_demand_ingestion_lag_high` | demand | WARN | `freshness.ingestion_lag.p90_days` | valor <= umbral | `demand_warn_max_lag_p90_days` | — |
| `dq_demand_short_series_majority` | demand | WARN | `series_history.short_series_pct` | valor <= umbral | `demand_warn_max_short_series_pct` | — |
| `dq_customer_insufficient_history` | customer | BLOCK | `coverage.identified_history_days` | valor >= umbral | `customer_min_history_days` | — |
| `dq_customer_too_few_buyers` | customer | BLOCK | `customer_history.buyers` | valor >= umbral | `customer_min_buyers` | — |
| `dq_customer_identified_share_low` | customer | DEGRADE | `identity.modeled_share_units` | valor >= umbral | `customer_min_identified_share` | `identified_buyers` |
| `dq_customer_product_ids_unstable` | customer | DEGRADE | `id_stability.product_change_share` | valor <= umbral | `customer_max_id_change_share` | `stable_ids` |
| `dq_customer_customer_ids_unstable` | customer | DEGRADE | `id_stability.customer_change_share` | valor <= umbral | `customer_max_id_change_share` | `stable_ids` |
| `dq_customer_new_product_codes_high` | customer | DEGRADE | `id_stability.new_product_code_share` | valor <= umbral | `customer_max_new_product_code_share` | `stable_ids` |
| `dq_customer_new_customers_high` | customer | WARN | `id_stability.new_customer_share` | valor <= umbral | `customer_warn_max_new_customer_share` | — |
| `dq_customer_repeat_buyers_low` | customer | WARN | `customer_history.repeat_buyers_pct` | valor >= umbral | `customer_warn_min_repeat_buyers_pct` | — |
| `dq_customer_availability_unknown` | customer | WARN | `availability.products_with_inventory_pct` | valor >= umbral | `customer_warn_min_availability_pct` | — |
| `dq_customer_data_stale` | customer | WARN | `freshness.days_since_last_sale` | valor <= umbral | `customer_warn_max_stale_days` | — |
| `dq_customer_invalid_rate_high` | customer | WARN | `invalid.invalid_pct` | valor <= umbral | `customer_warn_invalid_pct` | — |
| `dq_supply_no_inventory` | supply | BLOCK | `inventory.coverage_share` | valor >= umbral | `supply_min_inventory_coverage` | — |
| `dq_supply_inventory_coverage_low` | supply | WARN | `inventory.coverage_share` | valor >= umbral | `supply_warn_min_inventory_coverage` | — |
| `dq_supply_inventory_stale` | supply | WARN | `inventory.age_days_median` | valor <= umbral | `supply_inventory_max_age_days` | — |
| `dq_supply_lead_times_missing` | supply | DEGRADE | `lead_times.coverage_share` | valor >= umbral | `supply_min_lead_time_coverage` | `lead_times` |
| `dq_supply_cost_coverage_low` | supply | WARN | `costs.coverage_share` | valor >= umbral | `supply_warn_min_cost_coverage` | — |

Motivos que genera el propio gate (no están en la tabla): `dq_signal_unavailable` (WARN: una señal necesaria para una regla BLOCK o DEGRADE falló; lista las
reglas sin evaluar y la señal) y `dq_metrics_unavailable` (WARN: las métricas no se pudieron calcular). Ambos viajan en `limitations` y garantizan que un resultado
no evaluado nunca sea un PASS sin aviso (`test_a_failed_signal_behind_a_block_or_degrade_rule_emits_a_stable_warn_reason`,
`test_a_failure_computing_the_metrics_is_recorded_and_the_outcome_is_never_pass`).

### 6.3 Umbrales por defecto y su justificación

Son **33 umbrales** (4 ventanas de lectura y 29 umbrales de gate) en el bloque propio `quality_gates` de `engine/predictily_engine/platform/config.py`, con la precedencia **core → perfil sectorial → empresa**
(`settings.cores.quality_gates` de la empresa; una clave desconocida o un valor fuera de rango se RECHAZA, nunca se aplica en silencio). La procedencia de
cada clave y las claves sobrescritas quedan en `gate_results.<módulo>.thresholds_overridden`. Pruebas:
`test_thresholds_follow_the_core_profile_tenant_precedence_and_record_their_provenance`,
`test_a_tenant_override_changes_the_outcome_of_the_same_data_and_the_default_tenant_is_not_affected`,
`test_the_tenant_override_changes_the_gate_and_the_other_tenant_keeps_the_core_threshold`.

| Clave (`quality_gates`) | Módulo | Defecto | Efecto | Regla | Justificación (todos «a aprobar en el piloto») |
|---|---|---|---|---|---|
| `id_lookback_days` | shared | `90` | WINDOW | Alias de cambio de código (vigencia en el intervalo (corte − N, corte]) y primeras ventas dentro de los N días anteriores al corte cuentan como «recientes»; los códigos nuevos solo se evalúan con ≥ 2 × N días de historia. | 90 días: un trimestre de operación, suficiente para ver una reasignación de códigos sin tratar todo el catálogo como nuevo. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `identity_window_days` | shared | `180` | WINDOW | Ventana de la proporción de unidades de compradores identificados, genéricos y anónimos. | 180 días: la misma ventana con que el Customer Core ya contabiliza lo excluido de la predicción individual. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `invalid_lookback_days` | shared | `180` | WINDOW | Informes de ingestión (importaciones y sincronizaciones `completed`, lotes con documentos escritos; sin reprocesos) creados en los N días anteriores al corte entran al % inválido. | 180 días cubren dos trimestres de cargas: un error ya corregido no pesa para siempre y una importación aislada no domina. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `lag_lookback_days` | shared | `90` | WINDOW | Documentos incrementales con fecha de venta en los N días anteriores al corte entran a la distribución del desfase. | 90 días es un trimestre: refleja el comportamiento reciente de la integración sin mezclar cargas históricas. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_block_invalid_pct` | demand | `0,5` | BLOCK | Filas inválidas / filas leídas (informes de ingestión de la ventana) > N ⇒ BLOCK. | Si más de la mitad de las filas recibidas se rechaza, lo que quedó en Silver no es una muestra representativa del negocio. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_min_history_days` | demand | `28` | BLOCK | Historia de la empresa (primera venta → corte) < N días ⇒ BLOCK. | 28 días es el mínimo del perfilado de series (profiling.series.MIN_HISTORY_DAYS): con menos de cuatro semanas completas ninguna serie es elegible y no se observa un ciclo semanal completo varias veces. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_min_source_coverage_pct` | demand | `0,5` | BLOCK | Fracción de sucursal-días con cobertura de fuente < N ⇒ BLOCK. | Si más de la mitad de los sucursal-días no tiene fuente (missing_source) la mayor parte de la señal de entrenamiento sería imputación por media móvil y no ventas observadas; el pronóstico extrapolaría valores inventados. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_min_price_coverage` | demand | `0,8` | DEGRADE | Unidades con precio neto positivo / unidades vendidas < N ⇒ DEGRADE: se neutraliza price_ratio. | price_ratio compara el precio observado con el precio mediano de la serie; con menos del 80 % de las unidades con precio, la razón se calcula sobre una minoría y el modelo aprendería un efecto precio que no existe. Sin la variable el modelo sigue siendo válido. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_invalid_pct` | demand | `0,05` | WARN | Filas inválidas / filas leídas > N ⇒ WARN. | 5 % de filas rechazadas es la tasa a partir de la cual el puntaje de calidad de la importación ya descuenta (QualityReportBuilder) y conviene revisar la cuarentena. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_key_issue_pct` | demand | `0,02` | WARN | Líneas con producto o sucursal inexistente o dado de baja / líneas > N ⇒ WARN. | Esas líneas se pronostican sin catálogo vigente: 2 % es un nivel de ruido tolerable; por encima indica un catálogo desalineado de las ventas. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_max_closed_pct` | demand | `0,3` | WARN | Series-día cerradas / series-día > N ⇒ WARN. | Un 30 % de cierres declarados (feriados, vacaciones, sucursales cerradas) deja poca historia efectiva por serie; puede ser real pero merece revisión. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_max_lag_p90_days` | demand | `7` | WARN | p90 del desfase evento→ingestión de los documentos incrementales > N ⇒ WARN. | Si el 10 % de los documentos llega más de una semana tarde, los últimos días del corte se corrigen después y las métricas recientes son provisionales. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_max_short_series_pct` | demand | `0,5` | WARN | Series activas con < 28 días de historia / series activas > N ⇒ WARN. | Esas series usan un respaldo etiquetado sin evaluación; si son la mayoría el pronóstico del catálogo es en gran parte un promedio. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_max_stale_days` | demand | `14` | WARN | Días entre la fecha de referencia y la última venta conocida > N ⇒ WARN. | Dos semanas sin ventas nuevas indican que la carga se detuvo; en ejecución vigente la recencia ya no representa el negocio. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_max_stockout_pct` | demand | `0,2` | WARN | Series-día con quiebre / series-día > N ⇒ WARN. | El perfilado de preparación avisa desde 5 % de quiebres; 20 % es el nivel en que la censura domina la historia y el WAPE se vuelve optimista. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_min_cost_coverage` | demand | `0,5` | WARN | Líneas con costo / líneas < N ⇒ WARN. | Misma fracción que usa el puntaje de preparación (profiling.readiness: has_costs ≥ 50 %): por debajo, el margen y la inversión estimada quedan incompletos. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_min_inventory_coverage` | demand | `0,5` | WARN | Series activas con inventario / series activas < N ⇒ WARN. | Sin inventario no se detectan quiebres (ventas censuradas) ni se generan recomendaciones de abastecimiento; con la mitad sin él, la mitad del catálogo queda sin esa protección. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `demand_warn_source_coverage_pct` | demand | `0,9` | WARN | Fracción de sucursal-días con cobertura de fuente < N ⇒ WARN. | Con un 10 % de días sin fuente ya se imputan huecos suficientes para sesgar la estacionalidad semanal; se avisa antes de bloquear. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_min_buyers` | customer | `5` | BLOCK | Compradores identificados con compras < N ⇒ BLOCK. | Con menos de cinco compradores no hay población para estimar probabilidades por comprador ni para separar entrenamiento y evaluación. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_min_history_days` | customer | `28` | BLOCK | Historia de compras identificadas (primera compra → corte) < N días ⇒ BLOCK. | 28 días: cuatro semanas es el mínimo para observar siquiera una recompra semanal; el dataset de cortes del Customer Core exige además su propio mínimo (min_history_days del core), de modo que este gate solo evita trabajo evidentemente vacío. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_max_id_change_share` | customer | `0,2` | DEGRADE | Productos (o clientes) con alias de cambio de código/fusión/división reciente / con ventas > N ⇒ DEGRADE a agregado. | Cuando el 20 % de las identidades cambió de código en el trimestre, las relaciones comprador×producto se rompen y la recompra individual se estima sobre historias partidas (V2.5 §25.2: IDs inestables ⇒ deshabilitar recomendaciones individualizadas). Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_max_new_product_code_share` | customer | `0,5` | DEGRADE | Productos cuya primera venta cae en la ventana reciente / productos con ventas > N ⇒ DEGRADE a agregado. | Código nuevo reciente sin alias es el síntoma de una reasignación no registrada; si la mitad del catálogo vendido es «nuevo» (con historia previa suficiente para que no sea simple arranque) no hay relación comprador×producto estable. Solo se evalúa con al menos DOS ventanas de historia (2 × 90 = 180 días): con menos, el catálogo es «nuevo» porque el negocio es joven. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_min_identified_share` | customer | `0,2` | DEGRADE | Unidades de compradores identificados / unidades totales (ventana) < N ⇒ DEGRADE a agregado. | Si menos del 20 % de las unidades se atribuye a compradores identificados y no genéricos, las recomendaciones individuales cubrirían una minoría y el resto sería residual no atribuible; V2.5 §25.2 pide conservar el Demand Core general. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_warn_invalid_pct` | customer | `0,05` | WARN | Filas inválidas / filas leídas (informes de ingestión) > N ⇒ WARN. | 5 %: misma tasa que en demanda; las filas rechazadas son compras que el modelo no ve. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_warn_max_new_customer_share` | customer | `0,5` | WARN | Compradores identificados cuya primera compra cae en la ventana / compradores > N ⇒ WARN. | Los compradores nuevos son normales en un negocio que crece; solo se avisa cuando son la mitad (posible recodificación de clientes) y con al menos dos ventanas de historia. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_warn_max_stale_days` | customer | `14` | WARN | Días entre la fecha de referencia y la última venta conocida > N ⇒ WARN. | Dos semanas sin ventas nuevas: la recencia de cada comprador se sobreestima (el core ya marca «stale» a los 7 días como degradación propia). Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_warn_min_availability_pct` | customer | `0,5` | WARN | Productos comprados con inventario conocido / productos comprados < N ⇒ WARN. | La censura por disponibilidad necesita inventario: sin él no se distingue «no compró» de «no había stock». Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `customer_warn_min_repeat_buyers_pct` | customer | `0,3` | WARN | Compradores con ≥ 2 días distintos de compra / compradores < N ⇒ WARN. | Sin recompra no hay patrón individual que aprender; con menos del 30 % de compradores recurrentes la probabilidad individual es poco informativa. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `supply_min_inventory_coverage` | supply | `0` | BLOCK | Series pronosticadas con inventario al corte / series pronosticadas < N ⇒ BLOCK (0 = desactivado). | Desactivado por defecto (0): sin inventario el plan ya se excluye serie por serie con el motivo `no_inventory` y la calidad queda `insufficient_data`, con la explicación por nodo que consumen el plan y las pruebas existentes; un BLOCK la reemplazaría por una corrida vacía. La empresa o el piloto pueden activarlo (p. ej. 0,05). Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `supply_min_lead_time_coverage` | supply | `0,5` | DEGRADE | Series con plazo explícito (supplier_lead_times del producto u oferta con plazo) / series planificables < N ⇒ DEGRADE: se deshabilitan las ofertas implícitas y la política de plazo observado. | Si menos de la mitad de las series tiene un plazo conocido, el plan usaría el plazo por defecto de la empresa como si fuera un dato; las ofertas implícitas (último costo + plazo por defecto) y la fecha supuesta de recepciones sin fecha dejan de ser aceptables. EFECTO en una empresa SIN plazos ni ofertas: pierde las ofertas implícitas y su plan queda sin nodos (`insufficient_data`, motivo `no_offer`) hasta que cargue plazos u ofertas; con el umbral en 0 recupera el comportamiento anterior. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `supply_inventory_max_age_days` | supply | `30` | WARN | Mediana de antigüedad del último inventario > N días ⇒ WARN. | 30 días es el umbral del informe de preparación (stale_inventory): más allá, las existencias de partida del plan no son vigentes. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `supply_warn_min_cost_coverage` | supply | `0,5` | WARN | Productos pronosticados con costo conocido / productos pronosticados < N ⇒ WARN. | Sin costo la inversión y el costo del plan quedan incompletos; la mitad es el mismo nivel que usa el puntaje de preparación. Valor inicial de diseño, a aprobar en el piloto; no es universal. |
| `supply_warn_min_inventory_coverage` | supply | `0,5` | WARN | Series pronosticadas con inventario / series pronosticadas < N ⇒ WARN. | Con menos de la mitad de las series con existencias conocidas, la mayor parte del catálogo queda fuera del plan; el aviso lo hace visible sin detener la ejecución. Valor inicial de diseño, a aprobar en el piloto; no es universal. |

**Los valores por defecto no bloquean fixtures, demo ni E2E.** Se verificó con las suites existentes del motor (resultados en 6.6) y con
`test_the_default_thresholds_do_not_block_the_existing_fixtures` (empresa sintética de la ferretería andina). Por eso el BLOCK de inventario de
abastecimiento (`supply_min_inventory_coverage`) está en 0 (desactivado): sin inventario el plan ya se excluye serie por serie y la prueba existente
`test_inventory_unknown_is_excluded_not_assumed_zero` depende de esa explicación por nodo. La empresa o el piloto pueden activarlo.

### 6.4 Persistencia y trazabilidad

Una fila de `data_quality_reports` por ejecución con `scope = 'run'` (independiente de la de preparación `forecasting_readiness`, que no cambia):

| Columna | Contenido |
|---|---|
| `dataset` | `sales` (demanda), `customers` (Customer), `supply` (abastecimiento) |
| `forecast_run_id` / `core_run_id` | la ejecución de demanda, o la corrida de Customer/abastecimiento |
| `as_of` | corte de la ejecución |
| `metrics` | sección 5 |
| `gate_results` | `{módulo: {outcome, reasons[], disabled_features[], not_evaluated[], config_version, thresholds_overridden[], applied_changes[]}}` |
| `rules_version` | SHA-256 de la versión del código de reglas, el esquema de métricas, las reglas, el mapa de features y los umbrales efectivos |
| `summary`, `issues` | resumen del gate y motivos en la forma heredada (`{code, severity, message, count}`) para los consumidores existentes |

El manifiesto del protocolo (`forecast_runs.evaluation_manifest.data_quality_gate`, `core_runs.evaluation_manifest.data_quality_gate` en Customer) y el
resumen de la ejecución (`summary.quality_gate`, con `report_id`) reflejan el resultado; las limitaciones `dq_*` viajan en `summary.limitations` (demanda) y
`core_runs.limitations` (Customer, abastecimiento), de donde la envoltura común (`quality_status`, `limitations`) las toma. Reintentar una ejecución reemplaza su
fila (no la duplica) y toda sentencia filtra por `tenant_id`.

### 6.5 Pendientes y bloqueos de esta sección (honestos)

- **HECHO (backend, edición mínima):** `quality_gates` se agregó a `EffectiveConfigController::CORES` y a los enums de `docs/openapi/v2.yaml`
  (`predictily-api.yaml` regenerado): `GET /tenant/effective-config?core=quality_gates` y `PUT /tenant/core-config/quality_gates` validan con el motor y guardan en
  `tenants.settings.cores.quality_gates` (prueba `EffectiveConfigApiTest::test_quality_gates_is_a_configurable_core_validated_by_the_engine`).
- **Configuración inválida:** una clave desconocida, un tipo o un rango inválido en `quality_gates` se rechaza con el MISMO error 422 `invalid_configuration` en los tres
  módulos, antes de cargar datos (`test_an_invalid_quality_gates_override_is_the_same_422_in_customer_and_supply`, `test_an_invalid_tenant_override_fails_the_run_with_a_clear_configuration_error`).
- **Efecto de `dq_supply_lead_times_missing` en empresas sin plazos:** una empresa que no tiene `supplier_lead_times` ni ofertas con plazo queda con cobertura de plazos 0 y
  pierde las ofertas implícitas; si además no tiene ofertas explícitas su plan queda sin nodos (`insufficient_data`, motivo `no_offer` por serie) hasta que cargue plazos u
  ofertas. Es deliberado (no planificar con un plazo supuesto), pero es un cambio de comportamiento visible: ponga `supply_min_lead_time_coverage` en 0 para recuperar el anterior
  (`test_supply_without_any_explicit_lead_time_degrades_and_a_company_that_planned_with_implicit_offers_gets_an_empty_plan`).
- **NO IMPLEMENTADO:** gates de la fase de ingestión (los hallazgos y la cuarentena son de W2-A); gates de bloqueo por fuga temporal o reconciliación monetaria
  (W2-D y W2-A/W2-B); `DEGRADE` de las features de precio de abastecimiento y de las de Customer más finas que «recomendaciones individuales».
- **NO CALIBRADO:** ninguno de los umbrales se calibró con datos reales; la sensibilidad (cuántas ejecuciones del piloto cambiarían de estado al mover cada umbral)
  se medirá en el piloto con `gate_results` y `rules_version` ya persistidos.

### 6.6 Verificación

Ejecuciones del paquete (BD `predictily_engine_test_w2c`, migrada con el contrato de W2; desde un directorio sin `.env`):

| Ejecución | Resultado |
|---|---|
| Suite completa del motor (`engine/tests`), tras las correcciones de la revisión | **779 passed, 1 warning** en 315 s (incluye las pruebas de los demás paquetes de W2 presentes en el árbol en ese momento) |
| Pruebas de este paquete | 115: `test_quality_metrics.py` (24), `test_quality_gates.py` (71, de ellas 30 son la tabla de reglas probada regla por regla en su umbral) y `test_quality_gates_integration.py` (20, contra PostgreSQL) |
| Backend (edición mínima de `EffectiveConfigController::CORES`) | `EffectiveConfigApiTest` 6 passed (incluye `quality_gates`), `OpenApiRoutesTest` 2 passed, Pint sin cambios pendientes |
| Cambio a una prueba existente | `test_api_integration.py::test_execute_writes_versioned_results`: la consulta del informe de preparación se acota a `scope = 'forecasting_readiness'` (ahora la ejecución deja DOS filas por `forecast_run_id`: la de preparación y la de métricas/gates, `scope = 'run'`) y se agregan aserciones sobre la nueva fila. No se debilitó ninguna aserción. |

Qué NO se pudo verificar aquí: los E2E de `e2e/` (necesitan la pila completa); se acotó con (a) las suites del motor que ejercen el mismo código,
(b) la evaluación de los gates sobre las dos empresas de la demo sintética completa (ferretería andina y distribuidora costa → `PASS`, sin features
deshabilitadas) y (c) la proporción de compradores identificados de la demo de Customer (45 % y 84 % en 180 días, frente al 20 % mínimo por defecto).


## 7. Mapa feature → datos requeridos (W2-C)

**Estado: IMPLEMENTADO/VERIFICADO** para las features que el motor sabe deshabilitar (`quality/features.py`); **DISEÑADO** el criterio de suficiencia de
los datos que todavía no tienen regla. Qué hace cada pieza:

1. **Qué datos requiere cada feature.** `FEATURE_DATA`/`KIND_DATA` cubren TODO el feature set de demanda (`features/builder.py::feature_registry`): cada
   feature del builder resuelve a uno o más datos del vocabulario de abajo, y una prueba recorre el registro completo y falla si una feature no tiene mapa
   (`test_every_feature_of_the_demand_feature_set_has_a_data_requirement`).
2. **Qué datos se consideran insuficientes.** Una regla DEGRADE de la sección 6.2 declara un dato insuficiente (`data_kind`).
3. **Qué se deshabilita.** `DEGRADABLE_FEATURES`: las features, salidas y políticas que dependen de ese dato y que el motor sabe apagar, con su efecto real.

### 7.1 Vocabulario de datos requeridos

| Dato requerido (`required_data`) | Significado | Criterio de suficiencia hoy |
|---|---|---|
| `sales_history` | Historia de ventas válidas as-of (lags, medias móviles, nivel de la serie). | sin regla todavía (nunca se declara insuficiente por este gate) |
| `calendar` | Calendario de feriados, cierres y campañas conocido de antemano. | sin regla todavía (nunca se declara insuficiente por este gate) |
| `branches` | Sucursales de la empresa. | sin regla todavía (nunca se declara insuficiente por este gate) |
| `branch_region` | Región (costa/sierra) y cantón de cada sucursal. | sin regla todavía (nunca se declara insuficiente por este gate) |
| `product_category` | Categoría del producto. | sin regla todavía (nunca se declara insuficiente por este gate) |
| `promotions` | Promociones con fecha de disponibilidad (known_at). | sin regla todavía (nunca se declara insuficiente por este gate) |
| `price` | Precio neto observado en las líneas de venta. | `dq_demand_price_coverage_low` |
| `confirmed_future_price` | Precios futuros confirmados con known_at (product_price_history). | sin regla todavía (nunca se declara insuficiente por este gate) |
| `costs` | Costo unitario histórico en las líneas de venta o en el catálogo. | sin regla todavía (nunca se declara insuficiente por este gate) |
| `inventory` | Existencias por sucursal y producto (snapshots). | sin regla todavía (nunca se declara insuficiente por este gate) |
| `lead_times` | Plazos de entrega explícitos por producto, proveedor u oferta. | `dq_supply_lead_times_missing` |
| `identified_buyers` | Compras atribuidas a compradores identificados y no genéricos. | `dq_customer_identified_share_low` |
| `stable_ids` | Identidades de producto y cliente estables (sin cambios de código recientes). | `dq_customer_product_ids_unstable`, `dq_customer_customer_ids_unstable`, `dq_customer_new_product_codes_high` |
| `external_indicators` | Indicadores externos con disponibilidad temporal (pipeline de factores). | sin regla todavía (nunca se declara insuficiente por este gate) |

### 7.2 Features, salidas y políticas que DEGRADE deshabilita

| Feature / salida / política | Módulo | Tipo | Datos requeridos | Efecto cuando el gate la deshabilita |
|---|---|---|---|---|
| `price_ratio` | demand | model_feature | `price` | `builder_disabled_features:price_ratio`. Precio neto / precio mediano de la serie. Deshabilitada: el builder omite la columna (el modelo no la ve en el entrenamiento, el backtest ni la predicción); el supuesto de ingresos (último precio neto) no cambia. |
| `customer.individual_predictions` | customer | output | `identified_buyers`, `stable_ids` | `skip_individual_predictions`. Probabilidad y cantidad por comprador × producto. Deshabilitada: no se entrena ni se predice a nivel comprador; se conserva el pronóstico agregado del Demand Core (V2.5 §25.2). |
| `customer.individual_rankings` | customer | output | `identified_buyers`, `stable_ids` | `skip_individual_rankings`. Ranking de productos por comprador. Deshabilitado junto con las predicciones individuales de las que depende. |
| `customer.activity_risk` | customer | output | `identified_buyers`, `stable_ids` | `skip_activity_risk`. Riesgo de inactividad y cambio de comportamiento por comprador. Deshabilitado: exige la identidad estable del comprador. |
| `supply.implicit_offers` | supply | policy | `lead_times` | `config:use_implicit_offers=false`. Oferta implícita (último costo conocido + plazo por defecto) para productos sin oferta. Deshabilitada: esos productos se excluyen del plan con el motivo `no_offer` en lugar de planificarse con un plazo supuesto. |
| `supply.observed_lead_time_policy` | supply | policy | `lead_times` | `config:receipt_policy_observed_lead_time→lead_time_end`. Política de fecha de recepciones sin fecha según el plazo observado en las órdenes. Deshabilitada: si estaba configurada, se vuelve a `lead_time_end` (plazo conocido de la oferta). |

Efectos verificados: `price_ratio` se OMITE del entrenamiento, de las ventanas del backtest (también las reconstruidas as-of) y de la predicción por la vía del builder de W2-D
(`test_a_degrade_gate_omits_the_price_feature_in_training_backtest_windows_and_prediction`), con un resultado equivalente a neutralizarla
(`test_omitting_the_column_through_the_builder_is_equivalent_to_neutralizing_it`: predicciones idénticas); las políticas de abastecimiento se fuerzan sin mutar la configuración
original (`test_supply_degradation_forces_the_policies_without_mutating_the_caller_dicts`, `test_supply_degrade_disables_implicit_offers_and_the_plan_says_so`); Customer
no entrena ni predice por comprador (`test_customer_degrades_to_aggregate_no_models_are_trained_and_no_individual_predictions_are_written`). En cada caso el resultado lo registra en `limitations`, el manifiesto y
`gate_results.<módulo>.disabled_features`.

### 7.3 Mapa del builder de demanda (todas las features)

| Familia del registro (`kind`) | Datos requeridos | Features |
|---|---|---|
| `calendar` | `calendar` | indicadores del calendario de Ecuador, `dayofweek`, `month`, `day` |
| `lag`, `rolling` | `sales_history` | `lag1`, `lag7`, `lag14`, `lag28`, medias y desviaciones móviles, media estacional |
| `commercial` | `promotions` (`promo_discount`), `price` (`price_ratio`) | descuento de promociones; precio relativo (**degradable**) |
| `static` | `branches` (`branch_code_num`), `product_category` (`category_num`), `sales_history` (`series_level`) | sucursal, categoría, nivel de la serie |
| `geographic` | `branch_region` | región |
| `external` | `external_indicators` | indicadores externos (apagado por defecto) |

### 7.4 Integración con el registro de contratos de W2-D (punto único)

El vocabulario de 7.1 coincide con el cerrado de W2-D (`availability.contracts.DATASETS`) en los términos que ambos usan (`sales_history`, `price`, `promotions`,
`branches`, `branch_region`, `product_category`, `confirmed_future_price`, `external_indicators`); `costs`, `inventory`, `lead_times`, `identified_buyers`,
`stable_ids` y `calendar` son de los gates. El punto de integración es **`quality.features.connect_feature_contracts()`**: conecta
`availability.contracts.required_data_map` como proveedor (`set_contract_provider` acepta cualquier otro `{feature: required_data}`), de modo que los requisitos del
registro se SUMAN a los del mapa propio (`required_data`, `features_disabled_by`) y `check_registry(registry)` informa `unknown_data_kind`, `missing_in_registry` y
`requirement_mismatch`. Pruebas: `test_the_w2d_contract_registry_can_be_connected_and_checked` (registro simulado) y
`test_the_real_w2d_registry_is_consistent_with_this_package_when_it_is_present` (el registro real de W2-D en el árbol: sin problemas; se omite si el módulo no existe).

**Vía de DEGRADE en demanda (integrado):** `pipeline/core.py` construye `FeatureBuilder(..., disabled_features=dq.disabled_features)` (W2-D) y las ventanas as-of reconstruidas reciben la
misma política con `**features.options()`; ya no se usa `MaskedFeatureBuilder` en el pipeline. Esa clase (columna constante) queda en `quality/features.py` solo como respaldo
para un builder sin la opción y como referencia de la prueba de equivalencia. Este paquete no edita `features/builder.py` ni los archivos de W2-D.
**PENDIENTE de integración (arquitecto):** llamar a `connect_feature_contracts()` en el arranque del motor; hoy no se invoca automáticamente (solo las pruebas lo hacen).
