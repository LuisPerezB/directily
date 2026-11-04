# Contratos canónicos versionados (V2.5 §3)

Decisión: `docs/architecture/adr/V25-ADRS.md` (ADR-V25-24). Código: `backend/app/Support/Canonical/CanonicalContracts.php` y
`ContractFingerprint.php`. Prueba: `backend/tests/Feature/Lineage/CanonicalContractsTest.php`.

Estados de cada apartado: **IMPLEMENTADO/VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO/NO IMPLEMENTADO**, **BLOQUEADO**,
**NO APLICA**. Estados de una entidad en el catálogo: **IMPLEMENTADO** (tiene tablas, ningún campo imprescindible aplicable falta y no
hay brechas), **PARCIAL** (tiene tablas pero falta algún campo imprescindible o hay brechas) y **DISEÑADO** (sin tablas).

## 1. Qué es y qué NO es

Predictily no tiene un esquema «canónico» aparte: las tablas del backend (Silver) SON el modelo canónico. Este catálogo declara, para
cada una de las 31 entidades de V2.5 §3, qué tablas la implementan, sus campos, su estado y su **versión**, y fija esa versión con una
huella SHA-256 de las columnas reales. Cada registro Silver guarda en `schema_version` la versión del contrato con que se escribió
(`sales`, `sales_document_revisions`, `inventory_snapshots`; el resto de entidades se enlaza por su importación).

- **IMPLEMENTADO/VERIFICADO.** Las 31 entidades están catalogadas (4 IMPLEMENTADO · 24 PARCIAL · 3 DISEÑADO). La
  prueba `test_the_catalog_covers_each_entity_of_v25_section_3_exactly_once_and_in_order` fija la lista y el orden de §3.
- **NO es** un validador de datos en tiempo de ejecución ni un registro de esquemas editable: es un contrato en código, revisable en un
  PR, que una prueba contrasta con la base migrada.

## 2. Regla de versionado

Formato `<entidad>@<año>.<mes>.<correlativo>` (p. ej. `sales@2026.03.1`). El correlativo `0` marca una entidad DISEÑADA, sin tablas.

La **huella** (`fingerprint`) es el SHA-256 de las columnas reales de las tablas de la entidad leídas de `information_schema`: por tabla y
columna, tipo (`data_type` y `udt_name`), nulabilidad, longitud máxima, precisión y escala. No cubre el orden de las columnas, los valores
por defecto ni los índices; el orden de entrada es por bytes, así que la huella es idéntica en cualquier PostgreSQL.

**Si una migración cambia las columnas de una entidad, la prueba falla** hasta que:

1. se suba el correlativo de su `version` (`sales@2026.03.1` → `sales@2026.03.2`);
2. se actualicen su `fingerprint` y `fields` en `CanonicalContracts` (el mensaje de la prueba muestra la huella real);
3. se AGREGUE `<versión nueva>: <huella>` a `backend/tests/Fixtures/canonical_contract_fingerprints.json` (historial; las versiones ya
   publicadas no se editan: actualizar la huella sin subir el correlativo también falla);
4. se actualice este documento (una prueba verifica que cada entidad, su versión y su estado aparecen aquí).

Pruebas que lo demuestran: `test_fingerprints_fix_the_real_columns_and_fail_if_they_change_without_a_version_bump`,
`test_changing_a_real_column_is_detected_by_the_fingerprint_and_the_declared_fields` (agrega una columna, cambia un tipo y una
nulabilidad dentro de una transacción y comprueba que la huella cambia), `test_declared_fields_are_exactly_the_real_columns_of_each_table`
y `test_status_is_derived_from_tables_required_fields_and_known_gaps`.

**Coordinación entre paquetes (W2).** Las migraciones de otros paquetes que toquen tablas de este catálogo (p. ej. `external_events` en W2-F
o `sales` en W2-A) harán fallar la prueba al integrar: es el comportamiento buscado; el integrador sube la versión de la entidad afectada.

**Longitud de `schema_version`.** La migración de activación creó `sales.schema_version`, `sales_document_revisions.schema_version` e
`inventory_snapshots.schema_version` como `varchar(20)`, pero `inventory_snapshot@2026.03.1` mide 28 caracteres. La migración
`2026_03_07_000100_widen_schema_version_columns` las amplía a `varchar(40)` (aditiva; ampliar un varchar no reescribe la tabla en PostgreSQL;
`down` **aborta** sin cambiar nada si alguna fila ya guarda una versión de más de 20 caracteres, en vez de truncarla). Pruebas:
`test_schema_version_columns_fit_the_longest_contract_version`, `test_the_widening_migration_refuses_to_roll_back_over_long_values_instead_of_truncating_them`.

## 3. Catálogo de las 31 entidades

La columna *Clave* es la clave de `CanonicalContracts::CONTRACTS`; `sales`, `sales_line`, `inventory_snapshot`, `product`, `customer` y
`commercial_commitment` conservan la clave de `CanonicalContracts::VERSIONS` (la que usan hoy los caminos de ingestión; una prueba
verifica que ambas coinciden). La *Huella* es el SHA-256 completo en el código; aquí se muestran sus 12 primeros caracteres.

| Clave | Entidad (V2.5 §3) | Tabla(s) | Versión | Estado | Huella (SHA-256, 12 primeros) |
|---|---|---|---|---|---|
| `tenant` | Tenant | `tenants` | `tenant@2026.03.1` | IMPLEMENTADO | `d109afa65dda` |
| `branch` | Branch | `branches` | `branch@2026.03.1` | PARCIAL | `c1c0d16678e4` |
| `source_system` | SourceSystem | `data_sources` | `source_system@2026.03.1` | IMPLEMENTADO | `8a64cdfcfd9c` |
| `product` | Product | `products` | `product@2026.03.2` | PARCIAL | `3b7b4711a189` |
| `product_alias` | ProductAlias | `product_aliases` | `product_alias@2026.03.1` | IMPLEMENTADO | `a41ea0f4bd9d` |
| `category` | Category | `product_categories` | `category@2026.03.1` | PARCIAL | `03944e5c5aef` |
| `customer` | Customer | `customers` | `customer@2026.03.1` | PARCIAL | `9dbca8a6f259` |
| `customer_alias` | CustomerAlias | `customer_aliases` | `customer_alias@2026.03.1` | IMPLEMENTADO | `50a415e1daf3` |
| `supplier` | Supplier | `suppliers` | `supplier@2026.03.1` | PARCIAL | `5ab19f7a4244` |
| `sales` | SalesDocument | `sales`, `sales_document_revisions` | `sales@2026.03.1` | PARCIAL | `66e85cedd690` |
| `sales_line` | SalesLine | `sale_items`, `sale_item_revisions` | `sales_line@2026.03.1` | PARCIAL | `a14a3262c52d` |
| `commercial_commitment` | Order | `commercial_commitments`, `commercial_commitment_revisions` | `commercial_commitment@2026.03.1` | PARCIAL | `d9e0ae1469c1` |
| `commercial_commitment_item` | OrderLine | `commercial_commitment_items` | `commercial_commitment_item@2026.03.1` | PARCIAL | `7747bf36a37b` |
| `credit_note` | CreditNote | subtipo de `sales` | `credit_note@2026.03.1` | PARCIAL | — |
| `return` | Return | subtipo de `sales_line` | `return@2026.03.1` | PARCIAL | — |
| `price_history` | PriceHistory | `product_price_history` | `price_history@2026.03.1` | PARCIAL | `99b6c7e5c746` |
| `inventory_snapshot` | InventorySnapshot | `inventory_snapshots` | `inventory_snapshot@2026.03.1` | PARCIAL | `4dd1ff6e154e` |
| `inventory_movement` | InventoryMovement | `stock_movements` | `inventory_movement@2026.03.1` | PARCIAL | `fa00fdfc41ea` |
| `purchase_order` | PurchaseOrder | `purchase_orders`, `purchase_order_items` | `purchase_order@2026.03.1` | PARCIAL | `3aecdbe5f46c` |
| `inbound_shipment` | InboundShipment | — | `inbound_shipment@2026.03.0` | DISEÑADO | — |
| `supplier_lead_time` | SupplierLeadTime | `supplier_lead_times` | `supplier_lead_time@2026.03.1` | PARCIAL | `d7e935d85e64` |
| `cost_history` | CostHistory | `purchase_cost_history` | `cost_history@2026.03.1` | PARCIAL | `f04adea20827` |
| `unit_conversion` | UnitConversion | `unit_conversions` | `unit_conversion@2026.03.1` | PARCIAL | `2e059edb09fa` |
| `calendar_event` | CalendarEvent | `calendar_events` | `calendar_event@2026.03.1` | PARCIAL | `ad0379871526` |
| `external_signal` | ExternalSignal | `external_indicators`, `external_indicator_observations`, `external_events` | `external_signal@2026.03.1` | PARCIAL | `1d459e23f207` |
| `action` | Action | — | `action@2026.03.0` | DISEÑADO | — |
| `outcome` | Outcome | — | `outcome@2026.03.0` | DISEÑADO | — |
| `forecast` | Forecast | `forecast_runs`, `forecast_values`, `core_runs`, `customer_predictions` | `forecast@2026.03.2` | PARCIAL | `4922edb28276` |
| `recommendation` | Recommendation | `recommendations` | `recommendation@2026.03.1` | PARCIAL | `98539cafb20b` |
| `model_version` | ModelVersion | `model_registry` | `model_version@2026.03.2` | PARCIAL | `525988a7aa64` |
| `data_quality_report` | DataQualityReport | `data_quality_reports`, `data_quality_issues`, `quarantine_records` | `data_quality_report@2026.03.1` | PARCIAL | `5a8ce89c8534` |

Subtipos sin tabla propia: `credit_note` es un `sales` con `document_type = credit_note` y `return` es una línea con `is_return` (o una
línea de nota de crédito). Entidades DISEÑADAS: `inbound_shipment` (el tránsito se aproxima con `inventory_snapshots.in_transit` y
`purchase_orders.expected_at`), `action` y `outcome` (Decision Engine y Outcome/ROI, W5).

## 4. Campos imprescindibles de §3 que faltan por entidad

V2.5 §3 exige, además de `tenant_id` en TODAS las entidades, `source_system_id`, ID de origen, zona horaria, moneda, unidades, huella del
registro y versión de esquema. La matriz muestra la columna que cubre cada uno, **FALTA** (aplica y no existe) o *n/a* (no aplica a la
entidad, p. ej. la moneda de una sucursal o la fuente de una línea que hereda la de su documento). Una referencia `tabla.columna` indica que
el dato se hereda de otra tabla. `tenant_id` está en todas las tablas de empresa (`test_the_tenant_isolation_column_exists_in_every_company_table`;
`tenants` es la raíz y las observaciones externas se aíslan por su indicador).

| Entidad | source_system_id | ID de origen | Zona horaria | Moneda | Unidad | Huella | Versión de esquema |
|---|---|---|---|---|---|---|---|
| `tenant` | n/a | n/a | `timezone` | `currency` | n/a | n/a | n/a |
| `branch` | **FALTA** | `code` | **FALTA** | n/a | n/a | **FALTA** | **FALTA** |
| `source_system` | `id` | n/a | `source_timezone` | n/a | n/a | `schema_fingerprint` | `schema_version` |
| `product` | **FALTA** | `sku` | n/a | n/a | `unit` | **FALTA** | **FALTA** |
| `product_alias` | `data_source_id` | `source_code` | n/a | n/a | n/a | n/a | n/a |
| `category` | **FALTA** | `code` | n/a | n/a | n/a | **FALTA** | **FALTA** |
| `customer` | **FALTA** | `external_code` | n/a | n/a | n/a | **FALTA** | **FALTA** |
| `customer_alias` | `data_source_id` | `source_code` | n/a | n/a | n/a | n/a | n/a |
| `supplier` | **FALTA** | `code` | n/a | n/a | n/a | **FALTA** | **FALTA** |
| `sales` | `data_source_id` | `document_number` | **FALTA** | `currency` | n/a | `source_hash` | `schema_version` |
| `sales_line` | n/a | `line_number` | n/a | n/a | **FALTA** | **FALTA** | **FALTA** |
| `commercial_commitment` | `data_source_id` | `external_id` | **FALTA** | `currency` | n/a | `source_hash` | **FALTA** |
| `commercial_commitment_item` | n/a | `line_number` | n/a | n/a | `unit` | **FALTA** | **FALTA** |
| `credit_note` | `sales.data_source_id` | `sales.document_number` | **FALTA** | `sales.currency` | n/a | `sales.source_hash` | `sales.schema_version` |
| `return` | `sales.data_source_id` | `sale_items.line_number` | **FALTA** | `sales.currency` | **FALTA** | `sales.source_hash` | `sales.schema_version` |
| `price_history` | **FALTA** | **FALTA** | n/a | `currency` | n/a | **FALTA** | **FALTA** |
| `inventory_snapshot` | **FALTA** | n/a | **FALTA** | n/a | **FALTA** | **FALTA** | `schema_version` |
| `inventory_movement` | **FALTA** | `reference` | **FALTA** | n/a | **FALTA** | **FALTA** | **FALTA** |
| `purchase_order` | **FALTA** | `external_id` | **FALTA** | **FALTA** | **FALTA** | **FALTA** | **FALTA** |
| `inbound_shipment` | **FALTA** | **FALTA** | **FALTA** | n/a | **FALTA** | **FALTA** | **FALTA** |
| `supplier_lead_time` | **FALTA** | n/a | n/a | n/a | n/a | **FALTA** | **FALTA** |
| `cost_history` | **FALTA** | **FALTA** | n/a | `currency` | `unit` | **FALTA** | **FALTA** |
| `unit_conversion` | **FALTA** | n/a | n/a | n/a | `from_unit` | **FALTA** | **FALTA** |
| `calendar_event` | **FALTA** | `code` | **FALTA** | n/a | n/a | **FALTA** | **FALTA** |
| `external_signal` | `source_version_id` | `code` | **FALTA** | n/a | `unit` | **FALTA** | **FALTA** |
| `action` | n/a | n/a | **FALTA** | n/a | n/a | **FALTA** | **FALTA** |
| `outcome` | n/a | n/a | **FALTA** | n/a | n/a | **FALTA** | **FALTA** |
| `forecast` | n/a | n/a | **FALTA** | `currency` | **FALTA** | `config_version` | `feature_set_version` |
| `recommendation` | n/a | n/a | **FALTA** | **FALTA** | **FALTA** | **FALTA** | **FALTA** |
| `model_version` | n/a | n/a | n/a | n/a | n/a | `artifact_sha256` | `feature_set_version` |
| `data_quality_report` | **FALTA** | n/a | n/a | n/a | n/a | `rules_version` | n/a |

Resumen de lo que falta (de 86 entidades; las DISEÑADAS cuentan):

- source_system_id: falta en 15 entidades.
- ID de origen: falta en 3 entidades.
- Zona horaria: falta en 15 entidades.
- Moneda: falta en 2 entidades.
- Unidad: falta en 8 entidades.
- Huella: falta en 20 entidades.
- Versión de esquema: falta en 20 entidades.

Lectura: lo único que casi todas las entidades de **datos de origen** (venta, compromiso, inventario, compras) cubren son la fuente y el ID de
origen; **no existe aún** la zona horaria por registro (rige `data_sources.source_timezone` o la de la empresa), la unidad en las líneas de
venta (rige `products.unit`), ni la huella por registro fuera de documentos de venta y compromisos. Cerrar estas brechas es trabajo de las
migraciones de cada entidad (siguiente oleada) y se verá aquí como una subida de versión.

Brechas conocidas fuera de los siete campos:

- `product`: fusión y división de SKU: DISEÑADAS (ver BRONZE_SILVER_GOLD.md)
- `customer`: fusión y división de clientes: DISEÑADAS
- `return`: no se vincula a la venta original por línea (solo related_sale_id del documento)
- `inbound_shipment`: sin tabla: el tránsito se aproxima con inventory_snapshots.in_transit y purchase_orders.expected_at
- `action`: Decision Engine y flujo de acciones: W5
- `outcome`: Outcome y ROI: W5
- `recommendation`: la recomendación ejecutable con aprobación y tareas es del Decision Engine (W5)
- `model_version`: aprobación separada, firma y estados validated/approved/production de ADR-V25-07 (W4)
- `data_quality_report`: los escritores de ingestión (W2-A) y de métricas/gates del motor (W2-C) se verifican al integrar

## 5. Verificación

| Qué | Prueba |
|---|---|
| 31 entidades de §3, una vez y en orden | `test_the_catalog_covers_each_entity_of_v25_section_3_exactly_once_and_in_order` |
| Forma de cada entrada, formato de versión, DISEÑADO = correlativo 0 | `test_every_entry_has_the_documented_shape`, `test_versions_follow_the_format_and_designed_entities_stay_at_correlative_zero` |
| Versiones heredadas (`VERSIONS`) = contrato | mismo test de versiones |
| Estado derivado de tablas, campos y brechas | `test_status_is_derived_from_tables_required_fields_and_known_gaps` |
| Campos declarados = columnas reales | `test_declared_fields_are_exactly_the_real_columns_of_each_table` |
| Huella fijada y falla si cambian las columnas sin subir la versión | `test_fingerprints_fix_the_real_columns_and_fail_if_they_change_without_a_version_bump` |
| El mecanismo detecta columnas agregadas, tipos y nulabilidad | `test_changing_a_real_column_is_detected_by_the_fingerprint_and_the_declared_fields` |
| Las columnas que cubren los campos imprescindibles existen | `test_the_required_field_columns_exist` |
| `schema_version` cabe la versión más larga | `test_schema_version_columns_fit_the_longest_contract_version` |
| Este documento está al día | `test_the_documentation_lists_every_entity_with_its_version_and_status` |

Ejecución: `cd backend && php artisan test --filter=CanonicalContractsTest` (necesita PostgreSQL: lee `information_schema`).

## 6. Limitaciones conocidas del mecanismo (revisión adversarial de W2-B)

- La huella cubre tipo, nulabilidad, longitud, precisión y escala de las columnas; **no** cubre el orden de las columnas, los valores por defecto, los índices, las claves foráneas ni las
  restricciones `CHECK`: un cambio solo de índices o de restricciones no exige subir la versión.
- El historial `canonical_contract_fingerprints.json` y la huella de `CanonicalContracts` viven en el mismo repositorio: la protección contra «actualizar la huella sin subir la
  versión» es una convención que una prueba hace visible y una revisión de código debe sostener; no hay forma de impedir que el mismo PR edite ambos.
- Las entidades con varias tablas comparten una sola huella (cualquier tabla que cambie sube la versión de la entidad entera) y los subtipos (`credit_note`, `return`) no tienen
  huella propia: heredan la de su entidad.
- `fields` se declara a mano a partir de la base migrada; la prueba lo contrasta con las columnas reales, pero no valida que cada columna sea la «correcta» para el campo de §3 que
  declara en `required` (solo que exista).

## 7. Pendiente

- DISEÑADO/NO IMPLEMENTADO: contratos de las entidades de W5 (`Action`, `Outcome`) y de `InboundShipment`.
- DISEÑADO/NO IMPLEMENTADO: validación del contrato en la escritura (rechazar un registro cuyo `schema_version` no sea el vigente); hoy la
  versión se escribe, no se exige.
- PENDIENTE de integración: que los caminos de ingestión (W2-A) escriban `schema_version = CanonicalContracts::version(...)`; el catálogo y
  las columnas ya existen, pero este paquete no modifica esos caminos.
