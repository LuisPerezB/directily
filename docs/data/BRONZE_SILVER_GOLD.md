# Bronze, Silver y Gold en Predictily (V2.5)

Decisiones: `docs/architecture/adr/V25-ADRS.md` — ADR-V25-04 (capas sobre el esquema existente), ADR-V25-08 (almacén de objetos local y
S3) y ADR-V25-24 (Data Core P0: Bronze, linaje, reconciliación e identidades). Migración del contrato:
`backend/database/migrations/2026_03_03_000100_create_data_quality_lineage_and_identity_tables.php` (más la ampliación
`2026_03_07_000100_widen_schema_version_columns`).

Estados de cada apartado: **IMPLEMENTADO/VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO/NO IMPLEMENTADO**, **BLOQUEADO**,
**NO APLICA**. Todas las pruebas citadas viven en `backend/tests/Feature/Lineage/` y se ejecutan con
`cd backend && php artisan test --filter=<Clase>` (PostgreSQL).

## 1. Las tres capas y su implementación real

No hay lakehouse: las capas se asignan a lo que ya existe (ADR-V25-04).

| Capa | Qué es en Predictily | Dónde vive | Estado |
|---|---|---|---|
| **Bronze** | El original autorizado e **inmutable** (archivo subido, extracción SQL, lote REST, lote del Bridge), guardado íntegro **antes** de normalizar, con SHA-256, procedencia, versión de esquema y retención | `bronze_objects` (fila) + contenido en el almacén de objetos (`FilesystemBronzeStore` sobre un disco `local` o `bronze_s3`) | **IMPLEMENTADO/VERIFICADO** el almacén (§2–§5). La captura en los caminos de ingestión es de W2-A (ver `DATA_QUALITY_ENGINE.md`); este paquete no la modifica |
| **Silver** | Las tablas canónicas del backend con linaje: `sales` (+ `sales_document_revisions`), `sale_items` (+ `sale_item_revisions`), `inventory_snapshots`, `products`, `customers`, catálogos. La vista `sales_line_versions` es la **fuente única** de lectura as-of de ventas (ADR-V25-23) | PostgreSQL | **IMPLEMENTADO** (W1). Columnas de linaje `bronze_object_id`, `source_row` y `schema_version` en ventas, revisiones e inventario: creadas por el contrato; su escritura es de W2-A |
| **Gold** | El conjunto de datos que el motor construye en cada ejecución desde Silver (series diarias, cliente-producto, precios, inventario, features as-of). **No es un almacén persistido**: lo que se guarda es su identidad | `data_snapshots` (`hash`, `components`, `row_counts`, `coverage`) enlazado desde `forecast_runs.data_snapshot_id` y `core_runs.data_snapshot_id` | **IMPLEMENTADO** la huella y los conteos; **DISEÑADO/NO IMPLEMENTADO** persistir datasets Gold versionados |

Linaje entre capas: Gold → Silver por `data_snapshots.row_counts` y `hash`; Silver → Bronze por `sales.bronze_object_id` (y las
revisiones archivadas conservan el de su versión); Bronze → origen por `sha256`, `original_name`, `received_by_user_id` /
`received_from_device` y `data_source_id`. La **reconciliación por ventana** (§7) recorre las cuatro capas.

## 2. Contrato del almacén Bronze — IMPLEMENTADO/VERIFICADO

`App\Services\DataCore\Bronze\BronzeStore` (interfaz del arquitecto, **sin cambios**: `store`, `storeFile`, `read`, `purge`;
`test_it_binds_the_interface_to_the_filesystem_implementation` fija los cuatro métodos) e implementación `FilesystemBronzeStore`.

| Garantía | Cómo | Prueba |
|---|---|---|
| **Clave generada solo por el servidor** | `tenants/<uuid>/bronze/<entidad>/<aaaa>/<mm>/<sha256>.<ext>`; la entidad se valida con `^[a-z_]{2,30}\z` (sin permitir un salto de línea final), la extensión con lista blanca, el tipo con `BronzeObject::KINDS`; el nombre de archivo declarado es un dato descriptivo (`../../etc/passwd` no altera la clave) y el disco lo fija el servidor aun si la procedencia trae `metadata.disk` | `BronzeStoreTest::test_store_generates_the_key_on_the_server_and_registers_integrity_and_provenance`, `::test_the_key_never_comes_from_client_input` (incluye `"sales\n"`) |
| **Escritura única e idempotente** | mismo contenido + misma empresa + misma entidad ⇒ el objeto existente, sin reescribir (índice único `tenant_id, sha256, entity`); una carrera entre dos escrituras iguales converge al objeto ganador; jamás se sobrescribe un archivo ajeno en la clave generada | `::test_store_is_idempotent_for_the_same_content_tenant_and_entity`, `::test_concurrent_writes_of_the_same_content_converge_on_one_object`, `::test_store_never_overwrites_a_foreign_file_at_the_generated_key` |
| **Verificación al leer** | `read` recalcula el SHA-256 y lanza `BronzeIntegrityException` si el contenido cambió, falta o fue purgado | `::test_read_verifies_the_sha256_and_detects_altered_missing_or_purged_content` |
| **Aislamiento entre empresas** | escribe solo para la empresa activa; lee/purga solo objetos de la empresa activa; el mismo contenido en dos empresas son dos objetos con prefijos distintos; el modelo es fail-closed (`BelongsToTenant`) | `::test_companies_are_isolated_in_bronze`, `::test_purge_refuses_objects_of_another_company` |
| **Tamaño máximo** | `bronze.max_object_mb` (64 por defecto) se **aplica** antes de escribir (el contrato lo declaraba pero no lo aplicaba) | `::test_objects_over_the_size_limit_are_rejected_before_writing` |
| **`storeFile` en flujo** | calcula el SHA-256 con `hash_file` y sube por `writeStream` (no carga el archivo en memoria) con las mismas garantías | `::test_store_file_streams_from_disk_with_the_same_guarantees` |
| **Disco con que se guardó** | `metadata.disk`: cambiar `bronze.disk` después no deja ilegibles los objetos anteriores | `::test_objects_stay_readable_after_the_configured_disk_changes` |
| **Purga auditada** | `purge` borra el contenido, comprueba que ya no está y solo entonces marca `purged` (conserva huella y tamaño como evidencia) y escribe `bronze.purged` en la bitácora (sin la huella completa) | `::test_purge_deletes_the_content_marks_purged_and_is_audited` |
| **Sin objetos «fantasma»** | se comprueba el retorno de la escritura y que el contenido exista después de escribir: un disco con `throw => false` devuelve `false` sin lanzar, y antes eso dejaba una fila sin contenido mientras la carga seguía. Ahora cualquier fallo del almacén (retorno `false`, excepción de Flysystem, directorio sin permiso, «dice que escribió» sin hacerlo) es `BronzeIntegrityException` y **no se registra la fila**; `W2-A` detiene la carga (`bronze_capture_failed`) | `::test_a_failed_write_never_registers_a_phantom_object`, `::test_a_flysystem_exception_is_also_a_clean_integrity_failure`, `::test_an_unwritable_directory_on_a_real_local_disk_is_a_failure_not_a_phantom` |
| **Reutilizar un objeto verifica su contenido** | al recibir otra vez un contenido ya registrado y no purgado se comprueba `exists()`; si falta (borrado fuera de Predictily, fallo anterior) se **reescribe** con el mismo contenido y clave y se audita `bronze.content_restored`; si no se puede reescribir, falla en lugar de devolver un objeto sin contenido | `::test_reusing_an_existing_object_restores_content_that_went_missing`, `::test_reusing_an_object_with_its_content_present_does_not_touch_the_store` |
| **Rehidratación** | un objeto purgado que vuelve a recibirse (mismo SHA-256) recupera su contenido y su estado `stored`. `received_at` **conserva la primera recepción** (procedencia); la nueva queda en `metadata.last_rehydrated_at` y en el historial `metadata.rehydrations` (con la recepción original, el motivo de la purga y la fecha), y la retención corre desde la última recepción. Si la reescritura falla, el objeto sigue `purged`. Auditoría `bronze.rehydrated` | `::test_a_purged_object_received_again_is_restored_with_the_same_content`, `::test_a_failed_rehydration_leaves_the_object_purged`, `BronzeRetentionPurgeTest::test_a_rehydrated_object_keeps_its_first_receipt_and_the_retention_runs_from_the_last_one` |

**Reglas que no se cumplen aún (honestidad).** Un objeto único mayor que `bronze.max_object_mb` ahora se rechaza; una extracción SQL
comprimida más grande que 64 MB detendría la captura con el BLOCKER `bronze_capture_failed` de W2-A. Si el piloto trae extracciones
mayores hay que subir `PREDICTILY_BRONZE_MAX_OBJECT_MB` (decisión de capacidad a aprobar en el piloto) o fragmentarlas.

## 3. Controladores de almacenamiento

La selección es solo configuración (`PREDICTILY_BRONZE_DISK`):

| Disco | Uso | Estado |
|---|---|---|
| `local` (por defecto) | desarrollo y pruebas (`storage/app/private`) | **IMPLEMENTADO/VERIFICADO** (`BronzeStoreTest`, `BronzeApiTest`) |
| `bronze_s3` | AWS S3 o un emulador S3 | **IMPLEMENTADO/VERIFICADO contra EMULADOR** (moto). **BLOQUEADO contra AWS real** (ver §3.3) |

### 3.1 Configuración del disco S3 (`backend/config/filesystems.php`, disco `bronze_s3`)

Todo por entorno; sin clave/secreto en el entorno el SDK usa su cadena de credenciales (rol IAM). **Nada de credenciales en código ni documentos.**

| Variable | Significado |
|---|---|
| `PREDICTILY_BRONZE_DISK=bronze_s3` | activa el disco S3 para Bronze |
| `PREDICTILY_BRONZE_S3_BUCKET` | bucket |
| `PREDICTILY_BRONZE_S3_REGION` | región (por defecto `AWS_DEFAULT_REGION` o `us-east-1`) |
| `PREDICTILY_BRONZE_S3_ENDPOINT` | solo emuladores (moto, MinIO) |
| `PREDICTILY_BRONZE_S3_PATH_STYLE=true` | direccionamiento por ruta (emuladores) |
| `PREDICTILY_BRONZE_S3_SSE` | cifrado del lado del servidor: `AES256` (SSE-S3) o `aws:kms` |
| `PREDICTILY_BRONZE_S3_KMS_KEY_ID` | clave KMS (con `aws:kms`) |
| `PREDICTILY_BRONZE_S3_KEY` / `_SECRET` | solo si no hay rol IAM |

Todas las variables `PREDICTILY_BRONZE_*` están documentadas (comentadas) en `backend/.env.example`.

El disco declara `throw => true`: un fallo del almacén se propaga y no se confunde con «el objeto no existe». El adaptador se instaló con un
único `composer require league/flysystem-aws-s3-v3` (paquete del ecosistema; arrastra `aws/aws-sdk-php`, `aws/aws-crt-php`,
`mtdowling/jmespath.php` y `symfony/filesystem`; ningún otro paquete se actualizó).

### 3.2 Prueba contra el emulador — **EMULADOR, no AWS**

`BronzeS3EmulatorTest` (etiquetada `#[Group('emulator')]`; su docblock y los mensajes dicen EMULADOR) crea un bucket en un servidor S3 local,
configura el disco `bronze_s3` con endpoint, path-style y `ServerSideEncryption=AES256`, y verifica: clave generada por el servidor bajo el prefijo
de la empresa; cabecera `ServerSideEncryption=AES256` en el objeto guardado; lectura con verificación SHA-256 y detección de un objeto
reescrito fuera de Predictily; idempotencia sin reescritura (mismo ETag); `storeFile` de un archivo mayor; purga que borra el objeto del bucket;
aislamiento por prefijo entre empresas. **Si no hay emulador la prueba se OMITE con un mensaje explícito** («EMULADOR: prueba OMITIDA…»).

```
# emulador (venv propio, no el del motor)
python3 -m venv <venv> && <venv>/bin/pip install "moto[server]"
<venv>/bin/moto_server -H 127.0.0.1 -p 5057
# prueba
cd backend && TEST_S3_EMULATOR_ENDPOINT=http://127.0.0.1:5057 php artisan test --filter=BronzeS3EmulatorTest
```

### 3.3 Lo que el emulador NO demuestra — BLOQUEADO

BLOQUEADO hasta que el propietario entregue cuenta AWS, presupuesto y credenciales (ADR-V25-08, ADR-V25-09): permisos IAM de mínimo privilegio,
SSE-KMS real (moto acepta `aws:kms` pero no valida la clave), política del bucket y *Block Public Access*, versionado y *Object Lock*,
reglas de ciclo de vida, rendimiento y consistencia reales. Con el versionado activo, borrar un objeto deja una versión anterior: la expiración
de versiones debe configurarse como infraestructura (DISEÑADO en el IaC de ADR-V25-09) para que la purga de retención sea efectiva.

## 4. Retención y purga por empresa — IMPLEMENTADO/VERIFICADO

- **Política:** `tenants.settings` → `data.bronze_retention_days` (días; `0` = sin vencimiento) con **precedencia** sobre
  `config('bronze.retention_days')`. Se fija con `PATCH /api/v1/tenant` (`settings.data.bronze_retention_days`, 0–3650; ability `settings.manage` =
  propietario y administrador). Un valor guardado inválido (negativo, texto, fuera de rango, booleano) se **ignora** y rige el de la plataforma:
  nunca se interpreta un valor corrupto como «purgar ya». Pruebas: `BronzeRetentionPurgeTest::test_the_company_policy_takes_precedence_over_the_platform_default_and_ignores_corrupt_values`,
  `::test_only_owner_or_admin_can_set_the_policy_through_the_tenant_settings_api`.
- **Alcance:** la política vigente se aplica a **todos** los objetos de la empresa, también a los ya guardados: vence el día `última recepción + días` (fecha
  de la zona horaria de la empresa; la última recepción es `received_at` o, si el objeto se purgó y volvió a recibirse, la rehidratación más reciente) y ese día inclusive se conserva. `retention_until` queda alineado con la política en cada corrida
  (`::test_a_policy_change_applies_to_objects_already_stored`, `::test_the_retention_date_is_inclusive_and_the_purge_is_idempotent`).
- **Comando:** `php artisan predictily:bronze-purge [--tenant=<uuid>] [--dry-run]`, programado a diario (03:45 America/Guayaquil, sin solapamiento).
  Borra el contenido, deja la fila en `purged` y escribe `bronze.purged` en la bitácora por objeto. Un fallo del almacén no marca el objeto como
  purgado, no detiene a los demás y deja el código de salida 1 (`::test_a_storage_failure_does_not_mark_the_object_purged_and_does_not_stop_the_others`).
- **Retención por cuarentena:** un objeto con registros de `quarantine_records` en estado `pending` **no** se purga (su reprocesamiento lo necesita);
  se informa como «retenidos por cuarentena pendiente» (`::test_objects_with_pending_quarantine_records_are_kept`).
- **Eliminación de la empresa (LOPDP):** `predictily:purge` borra ahora también los originales Bronze de una empresa cuya eliminación venció, **antes** de
  borrar la empresa; si el almacén falla, la empresa no se elimina y se reintenta en la próxima corrida
  (`::test_deleting_a_company_erases_its_bronze_originals_and_retries_when_the_store_fails`). Esta es una edición mínima de `PurgeCommand` (archivo compartido).
- **Aislamiento:** cada empresa se purga con su propia política y en su propio contexto (`::test_the_purge_command_removes_only_expired_objects_of_each_company_and_audits_it`).

**NO IMPLEMENTADO / limitaciones declaradas:** retención legal por objeto (*legal hold*) distinta de la cuarentena pendiente; política por entidad o por
fuente (hoy es una sola por empresa); la política de retención de las demás capas (ventas: `tenants.data_retention_months`, ya existente) es independiente;
`PATCH /tenant` reemplaza el subobjeto `settings.data` completo (hoy solo trae esta clave; si se agregan más, conviene una mezcla profunda).

## 5. API de metadatos y descarga — IMPLEMENTADO/VERIFICADO

| Ruta | Habilidad | Notas |
|---|---|---|
| `GET /api/v1/lineage/bronze` | `data.view` | metadatos de la empresa activa, filtros `entity`, `kind`, `status`, `data_source_id`, `sha256`, fechas |
| `GET /api/v1/lineage/bronze/{id}` | `data.view` | un objeto de otra empresa responde 404 |
| `GET /api/v1/lineage/bronze/{id}/content` | `datasources.manage` (propietario y administrador) | verifica el SHA-256; **siempre** adjunto `application/octet-stream` con `nosniff` y nombre generado por el servidor; 410 `bronze_purged`; 409 `bronze_integrity` |

Los metadatos **nunca** incluyen la clave del almacén ni el contenido (la exportación LOPDP también omite `object_key`). Cada descarga queda en la bitácora
(`bronze.downloaded`) y cada fallo de integridad también (`bronze.integrity_failed`). Pruebas: `BronzeApiTest` (alcance por empresa, filtros,
matriz de roles viewer/analyst/admin/owner, cabeceras seguras, auditoría, 410/409, autenticación).

## 6. Identidades: alias, cambio de código, fusión y división

### 6.1 Implementado/verificado

- `product_aliases` / `customer_aliases` con modelos `ProductAlias` y `CustomerAlias` (aislados por empresa; `kind` = `alias` o `code_change`).
- **Resolución** (`CatalogResolver`): antes de tratar un código como producto o cliente nuevo se consultan los alias — primero el de la **fuente**
  (`options['data_source_id']`), luego el **general** — y solo dentro de su **vigencia** (`valid_from`/`valid_to`, ambos incluidos; sin fecha rige hoy). Un
  alias de otra fuente no aplica; el alias gana al SKU del mismo código. Un código con alias vigente no crea un producto/cliente nuevo.
  `CatalogResolverAliasTest` (13 pruebas): precedencia alias → SKU, fuente específica/general/ajena, vigencias, `merge`/`split` sin efecto, clientes,
  importación y lote con el código viejo, aislamiento entre empresas.
- **API:** `GET/POST/DELETE /api/v1/catalog/products/{id}/aliases`, `POST /api/v1/catalog/products/{id}/code-change {old_code, new_code, effective_from}` y lo
  análogo para `/catalog/customers/{id}/…`. El **cambio de código** deja como SKU visible el código nuevo y crea un alias `code_change` del viejo, que **sigue
  resolviendo al mismo producto**; no se reescribe ninguna fila histórica (`CatalogResolverAliasTest::test_an_import_with_the_old_code_after_a_code_change_resolves_to_the_same_product`).
  Habilidades: lectura `data.view`; escritura `catalog.manage`. Errores 422 con `code` estable (`alias_is_own_code`, `alias_code_in_use`, `alias_overlap`,
  `alias_kind_designed`, `alias_kind_use_code_change`, `alias_generic_customer`, `old_code_mismatch`, `code_change_same`, `code_change_future`). Pruebas: `IdentityAliasApiTest` (13).
- **Clientes (LOPDP):** el código se **minimiza** antes de guardarse (una cédula o un RUC nunca se almacenan ni se devuelven en claro, ni van a la bitácora); el
  consumidor final no admite alias.

### 6.2 Decisiones de diseño (a aprobar en el piloto)

- `effective_from` de un cambio de código no puede ser futura (no existen cambios programados) y queda en la nota del alias y en la auditoría. El alias del código
  viejo es **abierto** (sin `valid_to`): el ERP puede reenviar historia con el código viejo en cualquier momento y no debe crear un duplicado. Si el código viejo
  se reutiliza para otro producto, hay que eliminar el alias (`DELETE`) antes; mientras exista, el catálogo resuelve el código al producto original. Es una
  decisión de continuidad de historia sobre «código reutilizado»; revisar con datos reales.
- **La vigencia se evalúa con la fecha de CADA documento**, no con la de hoy: `SalesDocumentWriter` pasa `sale_date` a `productId`/`customerIdFor` y junta las fechas de los
  documentos que usan un código con alias para decidir si hay que crear el producto/cliente directo; `checkProduct` (importación, lote y liberación de cuarentena) recibe la misma
  fecha. Un documento de 2025 con un alias válido solo en 2025 resuelve al producto del alias; uno de 2026 con el mismo código crea (o usa) el producto directo
  (`CatalogResolverAliasTest::test_ingestion_resolves_aliases_with_the_date_of_each_document_not_with_todays`, `::test_a_csv_import_resolves_aliases_with_the_document_date`,
  `::test_ingestion_resolves_customer_aliases_with_the_date_of_each_document`). **Limitación:** los demás importadores (inventario, compromisos, promociones, plazos, conversiones,
  calendario) resuelven con la fecha de hoy; su fila no siempre trae una fecha de documento.
- **Maestro de productos:** una fila cuyo SKU es un alias vigente de otro producto **actualiza el producto canónico** (con su código vigente) y deja un hallazgo WARNING
  `alias_resolved`; no crea un duplicado. Si el archivo trae el código viejo y el nuevo, la segunda fila es `duplicate_in_file`
  (`CatalogResolverAliasTest::test_a_product_master_import_with_an_old_code_updates_the_canonical_product_instead_of_duplicating_it`, `::test_a_product_master_with_both_the_old_and_the_new_code_reports_a_duplicate`).

### 6.3 Fusión y división — DISEÑADO/NO IMPLEMENTADO

La API **no** crea alias `merge` ni `split` (422 `alias_kind_designed`) y el resolvedor los ignora (`CatalogResolverAliasTest::test_merge_and_split_kinds_are_not_applied_by_the_resolver`).
Semántica diseñada, sin reescritura:

- **Fusión (A y B son el mismo producto C):** se registra un alias `merge` de B → C con `valid_from` = fecha de la fusión y `known_at` (cuándo se supo). Las
  filas históricas de B **no se tocan**; el motor agrupa por la identidad canónica C aplicando el alias *as-of*: el pasado reconstruido a un corte anterior a `known_at` no
  cambia (misma regla de visibilidad temporal de ADR-V25-23). Una fusión conocida tarde nunca altera un backtest pasado.
- **División (A pasa a ser A1 y A2):** requiere una regla de reparto del historial (por fecha de corte, por sucursal o por proporción) que es una **decisión humana**
  y no se infiere; se registra un alias `split` con su regla y `valid_from`. Las filas anteriores de A siguen en A; las posteriores, en A1/A2.
- **Clientes homónimos:** la identidad canónica es el código autorizado minimizado; dos clientes distintos con el mismo nombre no se fusionan nunca de forma automática.
- Prerrequisitos para implementarlo: `known_at` en los alias, una vista `product_identity_map` as-of que consuma el motor y pruebas T02 de no-fuga con una fusión
  tardía. Hoy la brecha se mitiga con el cambio de código y los alias.

## 7. Reconciliación por ventana — IMPLEMENTADO/VERIFICADO

`php artisan predictily:reconcile {tenant_uuid} --entity=sales --from=AAAA-MM-DD --to=AAAA-MM-DD` y `GET /api/v1/lineage/reconciliation[/{id}]`. Servicio
`WindowReconciler`; escribe `reconciliation_reports` con scope `window`. Detalle de capas, métricas, reglas de explicación y estados en
`docs/data/LINEAGE_AND_RECONCILIATION.md` (sección «Reconciliación por ventana»). Resumen:

| Capa | Fuente | Si falta |
|---|---|---|
| source | totales declarados por las cargas (informes `import`/`sync` de W2-A con `layers.source`) | «no disponible» con la razón; **ninguna cifra inventada** |
| bronze | objetos referenciados por los documentos de la ventana o por las cargas; `rows` es `null` si algún objeto no declara sus filas (nunca una suma parcial) | «no disponible» con la razón |
| silver | versiones **vigentes** de `sales_line_versions` y `sales` de la ventana | siempre disponible |
| gold | `data_snapshots.row_counts.sales` de la última ejecución de demanda completada que llega al fin de la ventana | «no disponible» con la razón |

**Nunca `balanced` sin comparar:** una capa no disponible o no comparable (cargas solapadas, ventana que corta una carga, sin ejecución de demanda que cubra la ventana) se registra
como discrepancia visible `layer_not_compared`, queda en `unavailable_layers` con su razón y fuera de `compared_layers`; el informe solo es `balanced` si se compararon fuente →
Silver, el linaje Bronze → Silver y Silver → Gold sin diferencias. El mismo archivo cargado dos veces (mismo objeto Bronze) cuenta una vez. La fuente se compara también con las filas de
los originales de las cargas (`source → bronze`, `rows`). Pruebas: `WindowReconciliationTest`, `WindowReconciliationLoadsTest` (con las cargas reales de W2-A).

## 8. Pendiente y bloqueos de este documento

| Estado | Elemento |
|---|---|
| BLOQUEADO | S3 real en AWS (§3.3): cuenta, credenciales, KMS, política del bucket y ciclo de vida |
| DISEÑADO/NO IMPLEMENTADO | Fusión y división de identidades (§6.3); persistir datasets Gold versionados; `ObjectStore` de artefactos de modelos (W4) |
| DISEÑADO/NO IMPLEMENTADO | Subidas del Bridge por URL prefirmada PUT con validación de checksum (ADR-V25-08; W6 · S12) |
| IMPLEMENTADO/VERIFICADO (integración W2-A) | captura Bronze en los caminos de ingestión, linaje en Silver e informes por carga con `layers.source` y rango de fechas: `WindowReconciliationLoadsTest` reconcilia una ventana tras importaciones reales por la API |
| NO APLICA | Bronze de datos que no vienen de un origen externo (catálogos creados a mano en la aplicación) |

## 9. Limitaciones conocidas (revisión adversarial de W2-B)

Documentadas, no corregidas; ninguna rompe un invariante (aislamiento, inmutabilidad, verificación SHA-256, ningún número inventado).

| # | Limitación | Por qué se acepta / qué falta |
|---|---|---|
| L1 | `POST /products` (y cualquier alta fuera del maestro importado) puede crear un producto con un SKU que es alias vigente de OTRO producto; mientras el alias exista, la resolución del catálogo prefiere el alias | Validar el alta contra los alias es una regla de `ProductRequest` (catálogo base); hoy se resuelve borrando el alias (`DELETE`) antes de reutilizar el código |
| L2 | Los importadores distintos de ventas y maestro de productos resuelven alias con la fecha de hoy (§6.2) | Sus filas no siempre traen fecha de documento; pasar la fecha propia de cada una es trabajo por importador |
| L3 | Los alias de producto comparan sin distinguir mayúsculas (igual que los SKU) y los de cliente **distinguen** mayúsculas (igual que `customers.external_code`) | Coincide con cómo cada tabla identifica a su entidad; un ERP que cambie solo la capitalización de un código de cliente creará otro cliente |
| L4 | La descarga de contenido (`/lineage/bronze/{id}/content`) lee el objeto completo en memoria (hasta `bronze.max_object_mb`) y no admite `Range`; solo tiene el límite de tasa general de la API | Aceptable con el tope de 64 MB; con objetos mayores habría que transmitir desde el almacén y limitar la tasa por endpoint |
| L5 | La purga por retención solo recorre empresas `active`; las suspendidas no se purgan hasta reactivarse o eliminarse (la eliminación sí borra sus originales) | Conservador: no se borran datos de una cuenta suspendida; revisar con la política legal |
| L6 | La política de retención se guarda en `tenants.settings.data`, que `PATCH /tenant` reemplaza completo (mezcla superficial de `TenantService`) | Hoy `data` solo tiene una clave; con más claves hace falta una mezcla profunda |
| L7 | Gold se compara por ejecución completa (historia hasta su corte), no por ventana, y solo para el núcleo de demanda; Customer y Supply no se reconcilian por celdas. La base «ejecución vigente» usa el día de creación de la huella como aproximación de `known_until = hoy` | La huella no guarda ni la ventana de datos ni el conocimiento usado; registrarlos en `data_snapshots` lo haría exacto |
| L8 | Los objetos Bronze que no produjeron documentos de la ventana ni tienen informe por carga no son descubribles por ventana; las cargas que se solapan sin compartir el mismo original no se pueden sumar | Haría falta registrar el rango de fechas en el objeto y, para solapes, los documentos de cada carga |
| L9 | La reconciliación no se programa sola ni tiene ruta `POST`; una diferencia `unexplained` no bloquea el Gate A automáticamente | La evaluación de gates es de otra oleada |
