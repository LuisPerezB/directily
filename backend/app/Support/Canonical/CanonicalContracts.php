<?php

declare(strict_types=1);

namespace App\Support\Canonical;

use InvalidArgumentException;

/**
 * Contratos canónicos versionados (V2.5 §3; ADR-V25-24).
 *
 * Una entrada por cada entidad de V2.5 §3 (31). Cada una declara:
 * - `entity`: nombre de la entidad en la especificación;
 * - `tables` / `fields`: tabla(s) que la implementan y sus columnas (las reales, en su orden ordinal);
 * - `implemented_by`: para subtipos sin tabla propia (nota de crédito, devolución), la entrada cuyas tablas los implementan;
 * - `version`: `<entidad>@<año>.<mes>.<correlativo>`; el correlativo `0` marca una entidad DISEÑADA sin implementación;
 * - `fingerprint`: SHA-256 de las columnas reales (ContractFingerprint), que fija la prueba `CanonicalContractsTest`;
 * - `required`: los campos imprescindibles de §3 que más faltan (ID de sistema de origen, ID de origen, zona horaria, moneda, unidad,
 *   huella del registro y versión de esquema): nombre de la columna que los cubre, `null` = FALTA, `false` = NO APLICA;
 * - `gaps`: otras brechas conocidas; `notes`: aclaraciones.
 * - `status`: IMPLEMENTADO (tiene tablas, ningún campo imprescindible aplicable falta y no hay brechas), PARCIAL (tiene tablas pero
 *   falta algún campo imprescindible o hay brechas) o DISEÑADO (sin tablas ni `implemented_by`). La prueba lo deriva de lo anterior.
 *
 * REGLA: si cambian las columnas de una entidad (cualquier migración), la prueba falla hasta que se SUBA su `version` (correlativo) y
 * se actualice su `fingerprint`. Cada registro Silver guarda en `schema_version` la versión con que se escribió; `schema_version` es
 * varchar(40) (migración 2026_03_07_000100) para que quepan los nombres largos.
 *
 * Tabla legible: docs/data/CANONICAL_CONTRACTS.md.
 */
final class CanonicalContracts
{
    public const IMPLEMENTED = 'IMPLEMENTADO';

    public const PARTIAL = 'PARCIAL';

    public const DESIGNED = 'DISEÑADO';

    /** Campos imprescindibles de V2.5 §3 que cada contrato declara en `required`. */
    public const REQUIRED_FIELDS = ['source_system_id', 'source_id', 'timezone', 'currency', 'unit', 'hash', 'schema_version'];

    /**
     * Versiones que escriben hoy los caminos de ingestión (clave heredada de la activación del contrato). Siguen siendo válidas; la
     * fuente de verdad de la versión de cada entidad es CONTRACTS (una prueba verifica que coinciden).
     *
     * @var array<string, string>
     */
    public const VERSIONS = [
        'sales' => 'sales@2026.03.1',
        'sales_line' => 'sales_line@2026.03.1',
        'inventory_snapshot' => 'inventory_snapshot@2026.03.1',
        'product' => 'product@2026.03.1',
        'customer' => 'customer@2026.03.1',
        'commercial_commitment' => 'commercial_commitment@2026.03.1',
    ];

    /**
     * @var array<string, array{
     *     entity: string, status: string, version: string, tables: list<string>, implemented_by: ?string,
     *     fields: array<string, string>, fingerprint: ?string, required: array<string, string|false|null>,
     *     gaps: list<string>, notes: string
     * }>
     */
    public const CONTRACTS = [
        'tenant' => [
            'entity' => 'Tenant',
            'status' => 'IMPLEMENTADO',
            'version' => 'tenant@2026.03.1',
            'tables' => ['tenants'],
            'implemented_by' => null,
            'fields' => [
                'tenants' => 'id uuid name legal_name tax_id sector country currency timezone locale plan_id plan_overrides settings allow_shared_training shared_training_consented_at shared_training_consented_by data_retention_months status onboarding_completed_at deletion_requested_at created_at updated_at deleted_at',
            ],
            'fingerprint' => 'd109afa65ddaa8e3d4f864688c3bb6a95b227bfae73cb80f79a812c8a735cada',
            'required' => [
                'source_system_id' => false,
                'source_id' => false,
                'timezone' => 'timezone',
                'currency' => 'currency',
                'unit' => false,
                'hash' => false,
                'schema_version' => false,
            ],
            'gaps' => [],
            'notes' => 'Raíz del aislamiento multi-tenant; el identificador canónico es id (uuid público).',
        ],
        'branch' => [
            'entity' => 'Branch',
            'status' => 'PARCIAL',
            'version' => 'branch@2026.03.1',
            'tables' => ['branches'],
            'implemented_by' => null,
            'fields' => [
                'branches' => 'id tenant_id code name province canton commercial_zone address latitude longitude opening_hours is_active created_at updated_at deleted_at',
            ],
            'fingerprint' => 'c1c0d16678e476d578d7ede9ea97f26c07f8799fd06ac35fbe75a7cff87998e8',
            'required' => [
                'source_system_id' => null,
                'source_id' => 'code',
                'timezone' => null,
                'currency' => false,
                'unit' => false,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'La zona horaria de la sucursal no se guarda: rige la de la empresa.',
        ],
        'source_system' => [
            'entity' => 'SourceSystem',
            'status' => 'IMPLEMENTADO',
            'version' => 'source_system@2026.03.1',
            'tables' => ['data_sources'],
            'implemented_by' => null,
            'fields' => [
                'data_sources' => 'id tenant_id type connector_key name config credentials_encrypted status last_synced_at last_error created_at updated_at deleted_at system_kind vendor source_timezone schema_version schema_fingerprint',
            ],
            'fingerprint' => '8a64cdfcfd9c10093556d5eeff100f29bf38be3199b05f7db6186f9d9e5af6aa',
            'required' => [
                'source_system_id' => 'id',
                'source_id' => false,
                'timezone' => 'source_timezone',
                'currency' => false,
                'unit' => false,
                'hash' => 'schema_fingerprint',
                'schema_version' => 'schema_version',
            ],
            'gaps' => [],
            'notes' => 'SourceSystem = data_sources ampliado (migración 2026_03_03): tipo, proveedor, zona horaria de origen, versión y huella del esquema.',
        ],
        'product' => [
            'entity' => 'Product',
            'status' => 'PARCIAL',
            'version' => 'product@2026.03.1',
            'tables' => ['products'],
            'implemented_by' => null,
            'fields' => [
                'products' => 'id tenant_id category_id sku name unit brand current_price current_cost is_active attributes created_at updated_at deleted_at',
            ],
            'fingerprint' => '2f3e028a48988ec010ed11fb5919260558063f088949d356045573624c33493e',
            'required' => [
                'source_system_id' => null,
                'source_id' => 'sku',
                'timezone' => false,
                'currency' => false,
                'unit' => 'unit',
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => ['fusión y división de SKU: DISEÑADAS (ver BRONZE_SILVER_GOLD.md)'],
            'notes' => 'Identidad canónica = products.id; el SKU visible puede cambiar (alias code_change).',
        ],
        'product_alias' => [
            'entity' => 'ProductAlias',
            'status' => 'IMPLEMENTADO',
            'version' => 'product_alias@2026.03.1',
            'tables' => ['product_aliases'],
            'implemented_by' => null,
            'fields' => [
                'product_aliases' => 'id tenant_id product_id data_source_id source_code kind valid_from valid_to notes created_by created_at updated_at',
            ],
            'fingerprint' => 'a41ea0f4bd9db6c1692448c12c2ea30b69a1242a18130ce6dba71496880f67a5',
            'required' => [
                'source_system_id' => 'data_source_id',
                'source_id' => 'source_code',
                'timezone' => false,
                'currency' => false,
                'unit' => false,
                'hash' => false,
                'schema_version' => false,
            ],
            'gaps' => [],
            'notes' => 'Código de origen → producto con vigencia; alias y code_change implementados, merge/split diseñados.',
        ],
        'category' => [
            'entity' => 'Category',
            'status' => 'PARCIAL',
            'version' => 'category@2026.03.1',
            'tables' => ['product_categories'],
            'implemented_by' => null,
            'fields' => [
                'product_categories' => 'id tenant_id parent_id code name created_at updated_at',
            ],
            'fingerprint' => '03944e5c5aef73aa38fb7905862093301e048ca6e70bc638c6e50216d34315b1',
            'required' => [
                'source_system_id' => null,
                'source_id' => 'code',
                'timezone' => false,
                'currency' => false,
                'unit' => false,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Jerarquía opcional por parent_id; la taxonomía por sector es otro contrato (ADR-V25-25).',
        ],
        'customer' => [
            'entity' => 'Customer',
            'status' => 'PARCIAL',
            'version' => 'customer@2026.03.1',
            'tables' => ['customers'],
            'implemented_by' => null,
            'fields' => [
                'customers' => 'id tenant_id external_code segment_id customer_type name_encrypted tax_id_hash province canton commercial_zone created_at updated_at deleted_at is_generic',
            ],
            'fingerprint' => '9dbca8a6f2599afa46be41bb3742ebe5de7ab970ff6d37304a06226a11c83a87',
            'required' => [
                'source_system_id' => null,
                'source_id' => 'external_code',
                'timezone' => false,
                'currency' => false,
                'unit' => false,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => ['fusión y división de clientes: DISEÑADAS'],
            'notes' => 'external_code ya está minimizado (cédula/RUC como huella HMAC); tax_id_hash identifica a la persona, no es la huella del registro.',
        ],
        'customer_alias' => [
            'entity' => 'CustomerAlias',
            'status' => 'IMPLEMENTADO',
            'version' => 'customer_alias@2026.03.1',
            'tables' => ['customer_aliases'],
            'implemented_by' => null,
            'fields' => [
                'customer_aliases' => 'id tenant_id customer_id data_source_id source_code kind valid_from valid_to notes created_by created_at updated_at',
            ],
            'fingerprint' => '50a415e1daf3b2bccc8cb74115b20362fe3b9a97bba3b3540ae3582ffb759af5',
            'required' => [
                'source_system_id' => 'data_source_id',
                'source_id' => 'source_code',
                'timezone' => false,
                'currency' => false,
                'unit' => false,
                'hash' => false,
                'schema_version' => false,
            ],
            'gaps' => [],
            'notes' => 'Igual que ProductAlias; el código se guarda minimizado, nunca la cédula o el RUC en claro.',
        ],
        'supplier' => [
            'entity' => 'Supplier',
            'status' => 'PARCIAL',
            'version' => 'supplier@2026.03.1',
            'tables' => ['suppliers'],
            'implemented_by' => null,
            'fields' => [
                'suppliers' => 'id tenant_id code name tax_id country default_lead_time_days is_active created_at updated_at deleted_at',
            ],
            'fingerprint' => '5ab19f7a4244bf7d0159c8c9a1ccc1cf1c917cd3dab57a30779303b05f972326',
            'required' => [
                'source_system_id' => null,
                'source_id' => 'code',
                'timezone' => false,
                'currency' => false,
                'unit' => false,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => '',
        ],
        'sales' => [
            'entity' => 'SalesDocument',
            'status' => 'PARCIAL',
            'version' => 'sales@2026.03.1',
            'tables' => ['sales', 'sales_document_revisions'],
            'implemented_by' => null,
            'fields' => [
                'sales' => 'id tenant_id branch_id data_source_id import_job_id document_type document_number status issued_at sale_date customer_id channel currency subtotal discount_total tax_total total related_sale_id source_hash created_at updated_at source_recorded_at ingestion_mode version_known_from current_revision first_seen_at bronze_object_id source_row schema_version',
                'sales_document_revisions' => 'id tenant_id sale_id revision document_type status issued_at sale_date branch_id customer_id channel currency subtotal discount_total tax_total total source_hash source_recorded_at ingestion_mode known_from known_until import_job_id superseded_by_import_job_id data_source_id change_kind created_at bronze_object_id source_row schema_version',
            ],
            'fingerprint' => '66e85cedd69021f01e540191b3c5b63971da0d1983a3f5191bae8238a025e5c1',
            'required' => [
                'source_system_id' => 'data_source_id',
                'source_id' => 'document_number',
                'timezone' => null,
                'currency' => 'currency',
                'unit' => false,
                'hash' => 'source_hash',
                'schema_version' => 'schema_version',
            ],
            'gaps' => [],
            'notes' => 'Versión vigente en sales y versiones reemplazadas en sales_document_revisions (ADR-V25-23). La zona horaria de las marcas es la de data_sources.source_timezone (vía data_source_id) o la de la empresa; no se copia al documento.',
        ],
        'sales_line' => [
            'entity' => 'SalesLine',
            'status' => 'PARCIAL',
            'version' => 'sales_line@2026.03.1',
            'tables' => ['sale_items', 'sale_item_revisions'],
            'implemented_by' => null,
            'fields' => [
                'sale_items' => 'id tenant_id sale_id line_number product_id branch_id sale_date quantity unit_price discount_amount net_amount tax_amount unit_cost is_return promotion_id created_at updated_at',
                'sale_item_revisions' => 'id tenant_id sale_id revision line_number product_id branch_id sale_date quantity unit_price discount_amount net_amount tax_amount unit_cost is_return promotion_id created_at',
            ],
            'fingerprint' => 'a14a3262c52df34fb6924f210a483fec4dc686926e289255c2049129fdbe4da3',
            'required' => [
                'source_system_id' => false,
                'source_id' => 'line_number',
                'timezone' => false,
                'currency' => false,
                'unit' => null,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'La moneda, la fuente y la versión de esquema se heredan del documento (sales). La línea no guarda su unidad: rige products.unit.',
        ],
        'commercial_commitment' => [
            'entity' => 'Order',
            'status' => 'PARCIAL',
            'version' => 'commercial_commitment@2026.03.1',
            'tables' => ['commercial_commitments', 'commercial_commitment_revisions'],
            'implemented_by' => null,
            'fields' => [
                'commercial_commitments' => 'id tenant_id customer_id branch_id data_source_id commitment_type external_id status issued_at known_at valid_from valid_to expected_delivery_date cancelled_at currency source_hash created_at updated_at',
                'commercial_commitment_revisions' => 'id tenant_id commitment_id import_job_id revision status known_at cancelled_at source_hash snapshot recorded_at',
            ],
            'fingerprint' => 'd9e0ae1469c1f4fdcb77c3de3c9bf68616af609a59e4a5bf9c5c3e7e592de0ad',
            'required' => [
                'source_system_id' => 'data_source_id',
                'source_id' => 'external_id',
                'timezone' => null,
                'currency' => 'currency',
                'unit' => false,
                'hash' => 'source_hash',
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Pedidos, cotizaciones y reservas (commitment_type): NO son ventas (V2.5 §3).',
        ],
        'commercial_commitment_item' => [
            'entity' => 'OrderLine',
            'status' => 'PARCIAL',
            'version' => 'commercial_commitment_item@2026.03.1',
            'tables' => ['commercial_commitment_items'],
            'implemented_by' => null,
            'fields' => [
                'commercial_commitment_items' => 'id tenant_id commitment_id line_number product_id quantity quantity_fulfilled unit unit_price delivery_date created_at updated_at source_quantity source_unit conversion_factor',
            ],
            'fingerprint' => '7747bf36a37bae59383ed73c92ec64a29ce5617484c04e3a7f39189aa8c7f623',
            'required' => [
                'source_system_id' => false,
                'source_id' => 'line_number',
                'timezone' => false,
                'currency' => false,
                'unit' => 'unit',
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'La moneda y la fuente se heredan del compromiso.',
        ],
        'credit_note' => [
            'entity' => 'CreditNote',
            'status' => 'PARCIAL',
            'version' => 'credit_note@2026.03.1',
            'tables' => [],
            'implemented_by' => 'sales',
            'fields' => [],
            'fingerprint' => null,
            'required' => [
                'source_system_id' => 'sales.data_source_id',
                'source_id' => 'sales.document_number',
                'timezone' => null,
                'currency' => 'sales.currency',
                'unit' => false,
                'hash' => 'sales.source_hash',
                'schema_version' => 'sales.schema_version',
            ],
            'gaps' => [],
            'notes' => 'Subtipo de SalesDocument (document_type = credit_note); no tiene tabla propia.',
        ],
        'return' => [
            'entity' => 'Return',
            'status' => 'PARCIAL',
            'version' => 'return@2026.03.1',
            'tables' => [],
            'implemented_by' => 'sales_line',
            'fields' => [],
            'fingerprint' => null,
            'required' => [
                'source_system_id' => 'sales.data_source_id',
                'source_id' => 'sale_items.line_number',
                'timezone' => null,
                'currency' => 'sales.currency',
                'unit' => null,
                'hash' => 'sales.source_hash',
                'schema_version' => 'sales.schema_version',
            ],
            'gaps' => ['no se vincula a la venta original por línea (solo related_sale_id del documento)'],
            'notes' => 'Devolución = línea con is_return o línea de una nota de crédito; no tiene tabla propia.',
        ],
        'price_history' => [
            'entity' => 'PriceHistory',
            'status' => 'PARCIAL',
            'version' => 'price_history@2026.03.1',
            'tables' => ['product_price_history'],
            'implemented_by' => null,
            'fields' => [
                'product_price_history' => 'id tenant_id product_id branch_id price currency valid_from valid_to source created_at known_at',
            ],
            'fingerprint' => '99b6c7e5c746bb04fb52e0d39af702a27c8a77083b26aa94f068504537a20efc',
            'required' => [
                'source_system_id' => null,
                'source_id' => null,
                'timezone' => false,
                'currency' => 'currency',
                'unit' => false,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Con known_at (cuándo se conoció el precio) para la disponibilidad temporal.',
        ],
        'inventory_snapshot' => [
            'entity' => 'InventorySnapshot',
            'status' => 'PARCIAL',
            'version' => 'inventory_snapshot@2026.03.1',
            'tables' => ['inventory_snapshots'],
            'implemented_by' => null,
            'fields' => [
                'inventory_snapshots' => 'id tenant_id branch_id product_id snapshot_date on_hand reserved in_transit unit_cost import_job_id created_at updated_at bronze_object_id source_row schema_version',
            ],
            'fingerprint' => '4dd1ff6e154e375c48e5ffff4438ee68a136cb4bdb2c7509ce37293a3cfc5561',
            'required' => [
                'source_system_id' => null,
                'source_id' => false,
                'timezone' => null,
                'currency' => false,
                'unit' => null,
                'hash' => null,
                'schema_version' => 'schema_version',
            ],
            'gaps' => [],
            'notes' => 'Enlazada a su carga por import_job_id y a Bronze por bronze_object_id.',
        ],
        'inventory_movement' => [
            'entity' => 'InventoryMovement',
            'status' => 'PARCIAL',
            'version' => 'inventory_movement@2026.03.1',
            'tables' => ['stock_movements'],
            'implemented_by' => null,
            'fields' => [
                'stock_movements' => 'id tenant_id branch_id product_id movement_type quantity occurred_at reference created_at',
            ],
            'fingerprint' => 'fa00fdfc41ea9826db547245ea4c9fa1f14414e79c380ccb23b3bdd30f31a04c',
            'required' => [
                'source_system_id' => null,
                'source_id' => 'reference',
                'timezone' => null,
                'currency' => false,
                'unit' => null,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Opcional (V2.5: «si disponible»).',
        ],
        'purchase_order' => [
            'entity' => 'PurchaseOrder',
            'status' => 'PARCIAL',
            'version' => 'purchase_order@2026.03.1',
            'tables' => ['purchase_orders', 'purchase_order_items'],
            'implemented_by' => null,
            'fields' => [
                'purchase_orders' => 'id tenant_id supplier_id branch_id external_id status ordered_at expected_at received_at total created_at updated_at',
                'purchase_order_items' => 'id tenant_id purchase_order_id product_id quantity_ordered quantity_received unit_cost created_at updated_at',
            ],
            'fingerprint' => '3aecdbe5f46c33a4a1d055cfcff730e415dd03a2b341c158815f0a5b77617f07',
            'required' => [
                'source_system_id' => null,
                'source_id' => 'external_id',
                'timezone' => null,
                'currency' => null,
                'unit' => null,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Sin moneda ni unidad: los importes se asumen en la moneda de la empresa.',
        ],
        'inbound_shipment' => [
            'entity' => 'InboundShipment',
            'status' => 'DISEÑADO',
            'version' => 'inbound_shipment@2026.03.0',
            'tables' => [],
            'implemented_by' => null,
            'fields' => [],
            'fingerprint' => null,
            'required' => [
                'source_system_id' => null,
                'source_id' => null,
                'timezone' => null,
                'currency' => false,
                'unit' => null,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => ['sin tabla: el tránsito se aproxima con inventory_snapshots.in_transit y purchase_orders.expected_at'],
            'notes' => 'DISEÑADO.',
        ],
        'supplier_lead_time' => [
            'entity' => 'SupplierLeadTime',
            'status' => 'PARCIAL',
            'version' => 'supplier_lead_time@2026.03.1',
            'tables' => ['supplier_lead_times'],
            'implemented_by' => null,
            'fields' => [
                'supplier_lead_times' => 'id tenant_id supplier_id product_id branch_id lead_time_days lead_time_std_days source valid_from created_at updated_at',
            ],
            'fingerprint' => 'd7e935d85e640592fd4acb18fa53ae6c7fc58be57aaec3fad864a393ca48aca7',
            'required' => [
                'source_system_id' => null,
                'source_id' => false,
                'timezone' => false,
                'currency' => false,
                'unit' => false,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Plazo por proveedor, producto y sucursal con vigencia (valid_from).',
        ],
        'cost_history' => [
            'entity' => 'CostHistory',
            'status' => 'PARCIAL',
            'version' => 'cost_history@2026.03.1',
            'tables' => ['purchase_cost_history'],
            'implemented_by' => null,
            'fields' => [
                'purchase_cost_history' => 'id tenant_id product_id supplier_id unit_cost currency valid_from purchase_order_id source created_at known_at unit',
            ],
            'fingerprint' => 'f04adea20827e3925025461fb6803e0b6ff0057175afe3ea0ca822fbf25adda3',
            'required' => [
                'source_system_id' => null,
                'source_id' => null,
                'timezone' => false,
                'currency' => 'currency',
                'unit' => 'unit',
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Con known_at. El costo de venta conocido en su fecha vive en sale_items.unit_cost.',
        ],
        'unit_conversion' => [
            'entity' => 'UnitConversion',
            'status' => 'PARCIAL',
            'version' => 'unit_conversion@2026.03.1',
            'tables' => ['unit_conversions'],
            'implemented_by' => null,
            'fields' => [
                'unit_conversions' => 'id tenant_id product_id from_unit to_unit factor valid_from source created_at updated_at',
            ],
            'fingerprint' => '2e059edb09fa9aa78672dcc22b5476bfb7a4a2033a0532a7950b69fabd7c0455',
            'required' => [
                'source_system_id' => null,
                'source_id' => false,
                'timezone' => false,
                'currency' => false,
                'unit' => 'from_unit',
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Declarada por producto con vigencia; no se suman unidades distintas sin conversión trazable.',
        ],
        'calendar_event' => [
            'entity' => 'CalendarEvent',
            'status' => 'PARCIAL',
            'version' => 'calendar_event@2026.03.1',
            'tables' => ['calendar_events'],
            'implemented_by' => null,
            'fields' => [
                'calendar_events' => 'id tenant_id scope scope_ref code name category starts_on ends_on status known_at source revision created_at updated_at',
            ],
            'fingerprint' => 'ad0379871526cd7159a56812b77e668b666561e47a947f7724664c9c969919be',
            'required' => [
                'source_system_id' => null,
                'source_id' => 'code',
                'timezone' => null,
                'currency' => false,
                'unit' => false,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Con alcance, estado de conocimiento (known_at) y revisión; las filas públicas llevan tenant_id NULL.',
        ],
        'external_signal' => [
            'entity' => 'ExternalSignal',
            'status' => 'PARCIAL',
            'version' => 'external_signal@2026.03.1',
            'tables' => ['external_indicators', 'external_indicator_observations', 'external_events'],
            'implemented_by' => null,
            'fields' => [
                'external_indicators' => 'id tenant_id code name source frequency unit country description affected_sectors connector_key is_active created_at updated_at',
                'external_indicator_observations' => 'id indicator_id period_date value published_at ingested_at revision source_url created_at observed_at available_at valid_from valid_to issued_at forecast_horizon_days source_version_id scope_ref quality_flags',
                'external_events' => 'id tenant_id family event_type title summary source_url source_ref authority published_at available_at effective_from effective_to jurisdiction corroboration status extracted_by extraction affects is_informative_alert source_version_id verified_by verified_at created_at updated_at proposed_by review_note',
            ],
            'fingerprint' => '1d459e23f207c712a0d75eb16988bcc0de695ac9e7925686630fa1ef7ee26639',
            'required' => [
                'source_system_id' => 'source_version_id',
                'source_id' => 'code',
                'timezone' => null,
                'currency' => false,
                'unit' => 'unit',
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => [],
            'notes' => 'Series externas (indicadores y observaciones con disponibilidad temporal) y eventos con procedencia (ADR-V25-25).',
        ],
        'action' => [
            'entity' => 'Action',
            'status' => 'DISEÑADO',
            'version' => 'action@2026.03.0',
            'tables' => [],
            'implemented_by' => null,
            'fields' => [],
            'fingerprint' => null,
            'required' => [
                'source_system_id' => false,
                'source_id' => false,
                'timezone' => null,
                'currency' => false,
                'unit' => false,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => ['Decision Engine y flujo de acciones: W5'],
            'notes' => 'DISEÑADO.',
        ],
        'outcome' => [
            'entity' => 'Outcome',
            'status' => 'DISEÑADO',
            'version' => 'outcome@2026.03.0',
            'tables' => [],
            'implemented_by' => null,
            'fields' => [],
            'fingerprint' => null,
            'required' => [
                'source_system_id' => false,
                'source_id' => false,
                'timezone' => null,
                'currency' => false,
                'unit' => false,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => ['Outcome y ROI: W5'],
            'notes' => 'DISEÑADO.',
        ],
        'forecast' => [
            'entity' => 'Forecast',
            'status' => 'PARCIAL',
            'version' => 'forecast@2026.03.2',
            'tables' => ['forecast_runs', 'forecast_values', 'core_runs', 'customer_predictions'],
            'implemented_by' => null,
            'fields' => [
                'forecast_runs' => 'id tenant_id version run_type status triggered_by user_id horizon_days frequency cutoff_date data_from data_to feature_set_version strategy options selection summary is_current error_message started_at completed_at created_at updated_at config_version effective_config data_snapshot_id evaluation_manifest',
                'forecast_values' => 'id tenant_id forecast_run_id node_key level branch_id product_id category_id granularity target_date period_days horizon_step yhat lo_80 hi_80 lo_95 hi_95 model_registry_id model_family unit_price_assumed revenue_yhat p50 quality_status case_label',
                'core_runs' => 'id tenant_id core operation version run_type status result_type quality_status uncertainty_source as_of horizons horizon_start horizon_end target_definition options config_version effective_config data_snapshot_id model_versions evaluation_manifest assumptions limitations summary is_current triggered_by user_id error_message started_at completed_at created_at updated_at',
                'customer_predictions' => 'id tenant_id core_run_id customer_id product_id horizon_days as_of purchase_probability probability_raw probability_coherence_adjusted calibration_status quantity_if_purchase quantity_lo quantity_hi expected_quantity committed_quantity incremental_quantity total_view_quantity assumed_unit_price expected_amount currency median_interval_days days_since_last_purchase n_purchases model_family model_role quality_flags expected_orders quantity_per_order expected_units window_p10_days window_p50_days window_p90_days window_status segment',
            ],
            'fingerprint' => '4922edb2827652531a0c5fa8471abeca07e7ee4916b2540f3e33a81237f20b7c',
            'required' => [
                'source_system_id' => false,
                'source_id' => false,
                'timezone' => null,
                'currency' => 'currency',
                'unit' => null,
                'hash' => 'config_version',
                'schema_version' => 'feature_set_version',
            ],
            'gaps' => [],
            'notes' => 'Pronóstico de demanda (forecast_runs/values) y ejecuciones de Customer/Supply (core_runs, customer_predictions). La huella de datos vive en data_snapshots.',
        ],
        'recommendation' => [
            'entity' => 'Recommendation',
            'status' => 'PARCIAL',
            'version' => 'recommendation@2026.03.1',
            'tables' => ['recommendations'],
            'implemented_by' => null,
            'fields' => [
                'recommendations' => 'id tenant_id forecast_run_id branch_id product_id type inventory_snapshot_date on_hand reserved in_transit available lead_time_days lead_time_source review_period_days service_level demand_lead_time demand_lead_time_std safety_stock reorder_point suggested_qty unit_cost estimated_investment days_of_cover stockout_probability stockout_date_estimate risk_level status assumptions acknowledged_by acknowledged_at created_at updated_at',
            ],
            'fingerprint' => '98539cafb20bbcf4810987378ac03333ea0ec6e4f02721b807350758a633610e',
            'required' => [
                'source_system_id' => false,
                'source_id' => false,
                'timezone' => null,
                'currency' => null,
                'unit' => null,
                'hash' => null,
                'schema_version' => null,
            ],
            'gaps' => ['la recomendación ejecutable con aprobación y tareas es del Decision Engine (W5)'],
            'notes' => 'Recomendación de reposición por producto y sucursal.',
        ],
        'model_version' => [
            'entity' => 'ModelVersion',
            'status' => 'PARCIAL',
            'version' => 'model_version@2026.03.1',
            'tables' => ['model_registry'],
            'implemented_by' => null,
            'fields' => [
                'model_registry' => 'id tenant_id version model_family strategy_level scope sector demand_class status hyperparameters feature_set_version training_data_from training_data_to training_data_hash series_count artifact_path artifact_sha256 library_versions random_seed training_job_id forecast_run_id trained_at promoted_at retired_at notes created_at updated_at core role core_run_id validated_at artifact_compat',
            ],
            'fingerprint' => 'b162a7f75871115c0073944df0042999eac018b788c382ecb9555d98c8ec0940',
            'required' => [
                'source_system_id' => false,
                'source_id' => false,
                'timezone' => false,
                'currency' => false,
                'unit' => false,
                'hash' => 'artifact_sha256',
                'schema_version' => 'feature_set_version',
            ],
            'gaps' => ['aprobación separada, firma y estados validated/approved/production de ADR-V25-07 (W4)'],
            'notes' => 'Registro de modelos de la etapa 2; el ciclo de vida completo es W4.',
        ],
        'data_quality_report' => [
            'entity' => 'DataQualityReport',
            'status' => 'PARCIAL',
            'version' => 'data_quality_report@2026.03.1',
            'tables' => ['data_quality_reports', 'data_quality_issues', 'quarantine_records'],
            'implemented_by' => null,
            'fields' => [
                'data_quality_reports' => 'id tenant_id import_job_id forecast_run_id scope score summary issues created_at dataset source_sync_id bronze_object_id core_run_id as_of metrics issue_counts gate_results rules_version',
                'data_quality_issues' => 'id tenant_id report_id dq_class code entity record_ref source_row field original_value proposed_value applied reason status resolved_by resolved_at created_at',
                'quarantine_records' => 'id tenant_id bronze_object_id import_job_id source_sync_id entity record_key payload issue_codes status resolved_by resolved_at created_at updated_at',
            ],
            'fingerprint' => '5a8ce89c85348bd9ebb2e5d8ac567c85953a5ce1a6b669a0c26905bb72fc30db',
            'required' => [
                'source_system_id' => null,
                'source_id' => false,
                'timezone' => false,
                'currency' => false,
                'unit' => false,
                'hash' => 'rules_version',
                'schema_version' => false,
            ],
            'gaps' => ['los escritores de ingestión (W2-A) y de métricas/gates del motor (W2-C) se verifican al integrar'],
            'notes' => 'Informes por dataset, hallazgos con clase y registros en cuarentena (migración 2026_03_03).',
        ],
    ];

    public static function version(string $entity): string
    {
        return self::CONTRACTS[$entity]['version'] ?? self::VERSIONS[$entity] ?? throw new InvalidArgumentException("Contrato canónico desconocido: {$entity}.");
    }

    /** @return array<string, mixed> */
    public static function contract(string $entity): array
    {
        return self::CONTRACTS[$entity] ?? throw new InvalidArgumentException("Contrato canónico desconocido: {$entity}.");
    }

    /** @return list<string> claves de los contratos, en el orden de V2.5 §3 */
    public static function entities(): array
    {
        return array_keys(self::CONTRACTS);
    }

    /** Columnas declaradas de una entrada (todas sus tablas), como lista de `tabla.columna`. */
    public static function declaredColumns(string $entity): array
    {
        $out = [];
        foreach (self::contract($entity)['fields'] as $table => $names) {
            foreach (explode(' ', $names) as $name) {
                $out[] = "{$table}.{$name}";
            }
        }

        return $out;
    }

    /** Estado que corresponde a una entrada según sus tablas, campos imprescindibles y brechas. */
    public static function derivedStatus(array $contract): string
    {
        if ($contract['tables'] === [] && ($contract['implemented_by'] ?? null) === null) {
            return self::DESIGNED;
        }
        $missing = array_filter($contract['required'], fn ($v) => $v === null);

        return $missing !== [] || $contract['gaps'] !== [] ? self::PARTIAL : self::IMPLEMENTED;
    }
}
