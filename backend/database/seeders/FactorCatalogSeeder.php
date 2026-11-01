<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FactorDefinition;
use App\Services\Intelligence\PlatformWrite;
use Illuminate\Database\Seeder;

/**
 * Catálogo público de factores: las 17 familias de V2.1 §24.4 como `factor_definitions` con tenant_id NULL (ADR-V25-16 y ADR-V25-25).
 *
 * - TODAS nacen con is_active = false: ninguna familia se activa por defecto y hoy NO existe ninguna integración externa (sin `source_key`
 *   ni licencia de fuente; `external_indicator_id` NULL). Las definiciones de almacenamiento nativo (ventas, precios, promociones, calendario,
 *   compromisos, inventario) apuntan a datos que Predictily ya guarda; las demás describen el contrato para cuando exista una fuente aprobada (P2).
 * - Idempotente y no destructivo: crea las definiciones que faltan y NUNCA modifica una existente (ni su metadato ni su is_active), de modo que
 *   los cambios hechos por el propietario de la plataforma sobreviven a una nueva ejecución. Evolucionar el catálogo base = agregar entradas aquí.
 * - Los umbrales (`max_staleness_days`) son valores iniciales orientativos y quedan marcados «a aprobar en el piloto»; no son universales.
 */
class FactorCatalogSeeder extends Seeder
{
    private const THRESHOLD_NOTE = 'Umbral inicial orientativo, a aprobar en el piloto; no es universal.';

    public function run(): void
    {
        PlatformWrite::run(function (): void {
            foreach (self::definitions() as $row) {
                $exists = FactorDefinition::publicCatalog()->where('key', $row['key'])->exists();
                if ($exists) {
                    continue;
                }
                FactorDefinition::query()->create(array_merge($row, ['is_active' => false]));
            }
        });
    }

    /**
     * Definiciones base: una o más por familia. `schema` documenta identificadores, fechas registradas y estado real del dato.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $own = 'Datos propios de la empresa (sin licencia de terceros).';
        $none = null;

        return [
            self::make('sales_history', 'sales_history.units_sold', 'Ventas históricas (unidades)', 'OBSERVED_HISTORICAL', 'sales', 'unidades', 'D', 'branch', $own, 'disable_feature', 7, null,
                'Base de todo pronóstico de demanda. Los días sin fuente son «missing_source», nunca ceros (ADR-V25-23). Visible desde la versión conocida al corte.',
                ['identifiers' => ['branch_id', 'product_id'], 'dates' => ['sale_date', 'issued_at', 'version_known_from'], 'data_status' => 'native'], false),
            self::make('prices_discounts', 'prices_discounts.price_history', 'Historial de precios de venta', 'OBSERVED_HISTORICAL', 'product_price_history', 'moneda por unidad', 'event', 'product', $own, 'last_valid_with_age', 30, null,
                'El precio vigente explica el volumen y el ingreso. El precio futuro solo es KNOWN_IN_ADVANCE si está confirmado con known_at anterior al corte; si no, se congela el último conocido como supuesto.',
                ['identifiers' => ['product_id', 'branch_id'], 'dates' => ['valid_from', 'valid_to'], 'data_status' => 'native'], false),
            self::make('prices_discounts', 'prices_discounts.promotions', 'Promociones y descuentos', 'KNOWN_IN_ADVANCE', 'promotions', '%', 'event', 'product', $own, 'disable_feature', null, null,
                'Las promociones planificadas se conocen antes de ocurrir y mueven la demanda; solo cuentan si estaban registradas (known_at) al corte.',
                ['identifiers' => ['product_id', 'category_id', 'branch_id'], 'dates' => ['starts_on', 'ends_on', 'known_at'], 'data_status' => 'native'], false),
            self::make('competition', 'competition.competitor_price', 'Precio de la competencia', 'OBSERVED_HISTORICAL', 'external_indicator_observations', 'moneda por unidad', 'W', 'region', $none, 'last_valid_with_age', 14, null,
                'Referencia de posicionamiento de precio. Requiere equivalencia explicable de producto y normalización por unidad, impuesto, canal y región (V2.1 §24.8). Una actualización retroactiva no reescribe lo visible al corte.',
                ['identifiers' => ['indicator code', 'scope_ref'], 'dates' => ['observed_at', 'published_at', 'available_at', 'revision'], 'data_status' => 'no_source'], false),
            self::make('search_trends', 'search_trends.search_interest', 'Interés de búsqueda', 'OBSERVED_HISTORICAL', 'external_indicator_observations', 'índice relativo', 'W', 'region', $none, 'last_valid_with_age', 14, null,
                'Señal temprana de demanda para productos de consumo visible. Es un índice relativo de un tercero: cada valor se usa solo desde su fecha de publicación.',
                ['identifiers' => ['indicator code', 'scope_ref'], 'dates' => ['period_date', 'published_at', 'available_at'], 'data_status' => 'no_source'], false),
            self::make('consumer_preferences', 'consumer_preferences.preference_index', 'Preferencias del consumidor', 'OBSERVED_HISTORICAL', 'external_indicator_observations', 'índice', 'M', 'country', $none, 'last_valid_with_age', 120, null,
                'Cambios de hábito (por ejemplo, sin gluten o bebidas saludables) que cambian la mezcla de producto. Series de baja frecuencia con rezago de publicación.',
                ['identifiers' => ['indicator code', 'scope_ref'], 'dates' => ['period_date', 'published_at', 'available_at', 'revision'], 'data_status' => 'no_source'], false),
            self::make('weather', 'weather.observed', 'Clima observado', 'OBSERVED_HISTORICAL', 'external_indicator_observations', '°C o mm', 'D', 'region', $none, 'last_valid_with_age', 3, null,
                'Temperatura y lluvia observadas explican demanda de bebidas, agro y construcción. Se usa el observado hasta el corte; no se imputa el clima futuro como si fuera medido.',
                ['identifiers' => ['indicator code', 'scope_ref'], 'dates' => ['observed_at', 'published_at', 'available_at'], 'data_status' => 'no_source'], false),
            self::make('weather', 'weather.forecast', 'Clima pronosticado', 'THIRD_PARTY_FORECAST', 'external_indicator_observations', '°C o mm', 'D', 'region', $none, 'unavailable', 2, null,
                'Un pronóstico de terceros solo es usable si se conoce cuándo se emitió (issued_at). Sin historial archivado de emisiones, el backtest con este factor es INVALID y se prohíbe (V2.1 §24.5).',
                ['identifiers' => ['indicator code', 'scope_ref'], 'dates' => ['issued_at', 'forecast_horizon_days', 'valid_from', 'valid_to', 'available_at'], 'data_status' => 'no_source', 'requires_issue_history' => true], false),
            self::make('epidemiology', 'epidemiology.surveillance_alert', 'Alertas epidemiológicas', 'OBSERVED_HISTORICAL', 'external_events', 'evento', 'event', 'country', $none, 'disable_feature', null, null,
                'Brotes y alertas oficiales que afectan la demanda de ciertos rubros. Se conservan como alerta informativa verificada aunque no mejoren el error numérico (V2.1 §24.7).',
                ['identifiers' => ['source_ref', 'jurisdiction'], 'dates' => ['published_at', 'available_at', 'effective_from', 'effective_to'], 'data_status' => 'manual_events_only'], true),
            self::make('animal_plant_health', 'animal_plant_health.sanitary_alert', 'Sanidad animal y vegetal', 'OBSERVED_HISTORICAL', 'external_events', 'evento', 'event', 'region', $none, 'disable_feature', null, null,
                'Plagas y enfermedades de cultivos y animales cambian oferta, precios y exportaciones (agro, exportadoras). Alerta informativa verificada, con corroboración registrada.',
                ['identifiers' => ['source_ref', 'jurisdiction'], 'dates' => ['published_at', 'available_at', 'effective_from', 'effective_to'], 'data_status' => 'manual_events_only'], true),
            self::make('food_safety_alerts', 'food_safety_alerts.safety_alert', 'Alertas de inocuidad', 'OBSERVED_HISTORICAL', 'external_events', 'evento', 'event', 'country', $none, 'disable_feature', null, null,
                'Retiros y alertas de inocuidad de alimentos y bebidas; un documento publicado después del corte no existía para el modelo. Alerta informativa verificada.',
                ['identifiers' => ['source_ref', 'jurisdiction'], 'dates' => ['published_at', 'available_at', 'effective_from', 'effective_to'], 'data_status' => 'manual_events_only'], true),
            self::make('trade_regulatory', 'trade_regulatory.regulatory_change', 'Restricciones comerciales y regulatorias', 'KNOWN_IN_ADVANCE', 'external_events', 'evento', 'event', 'country', $none, 'disable_feature', null, null,
                'Aranceles, cupos y requisitos de importación o exportación se publican antes de entrar en vigencia: son conocibles de antemano desde su publicación. Alerta informativa verificada.',
                ['identifiers' => ['source_ref', 'jurisdiction'], 'dates' => ['published_at', 'available_at', 'effective_from', 'effective_to'], 'data_status' => 'manual_events_only'], true),
            self::make('export_statistics', 'export_statistics.export_volume', 'Estadísticas de exportación', 'OBSERVED_HISTORICAL', 'external_indicator_observations', 'unidades o USD según la serie', 'M', 'country', $none, 'last_valid_with_age', 120, null,
                'Volumen y precio de exportación por destino para exportadoras. Las estadísticas se revisan: al corte se usa la revisión vigente a esa fecha, no la actual.',
                ['identifiers' => ['indicator code', 'scope_ref'], 'dates' => ['period_date', 'published_at', 'available_at', 'revision'], 'data_status' => 'no_source'], false),
            self::make('orders_contracts', 'orders_contracts.confirmed_commitments', 'Pedidos y contratos confirmados', 'KNOWN_IN_ADVANCE', 'commercial_commitments', 'unidades', 'event', 'product', $own, 'disable_feature', null, null,
                'Demanda ya comprometida por el cliente; no son ventas hasta entregarse. Visible desde su known_at. Un pronóstico compartido NO equivale a un pedido confirmado.',
                ['identifiers' => ['customer_id', 'product_id', 'external_id'], 'dates' => ['issued_at', 'known_at', 'valid_from', 'valid_to', 'expected_delivery_date'], 'data_status' => 'native'], false),
            self::make('shared_projections', 'shared_projections.authorized_forecast', 'Proyecciones compartidas con autorización', 'THIRD_PARTY_FORECAST', 'shared_forecast_grants', 'unidades', 'event', 'product', 'Concesión explícita del emisor (SharedForecastGrant).', 'unavailable', 14, null,
                'Proyección que otra empresa concede a esta (por ejemplo retailer a fabricante). Contrato DISEÑADO y DESACTIVADO (V2.1 §10): sin red colaborativa en P0; no se suma a pedidos confirmados.',
                ['identifiers' => ['grant id', 'product_id'], 'dates' => ['issued_at', 'expires_at', 'period_from', 'period_to'], 'data_status' => 'contract_disabled'], false),
            self::make('production_estimates', 'production_estimates.crop_estimate', 'Estimaciones de producción', 'THIRD_PARTY_FORECAST', 'external_indicator_observations', 'toneladas u otra unidad de la serie', 'M', 'region', $none, 'unavailable', 120, null,
                'Estimaciones de cosecha o producción de organismos y gremios que anticipan oferta y precio. Son pronósticos de terceros: exigen fecha de emisión archivada.',
                ['identifiers' => ['indicator code', 'scope_ref'], 'dates' => ['issued_at', 'valid_from', 'valid_to', 'available_at', 'revision'], 'data_status' => 'no_source', 'requires_issue_history' => true], false),
            self::make('reviews_satisfaction', 'reviews_satisfaction.sentiment_index', 'Reseñas y satisfacción', 'OBSERVED_HISTORICAL', 'external_indicator_observations', 'índice', 'W', 'product', $none, 'last_valid_with_age', 30, null,
                'Temas y sentimiento agrupados con incertidumbre; no se atribuye causalidad automática (V2.1 §24.8). Solo con permiso de acceso a la fuente; sin scraping prohibido.',
                ['identifiers' => ['indicator code', 'scope_ref'], 'dates' => ['period_date', 'published_at', 'available_at'], 'data_status' => 'no_source'], false),
            self::make('capacity_inventory', 'capacity_inventory.inventory_on_hand', 'Inventario disponible', 'OBSERVED_HISTORICAL', 'inventory_snapshots', 'unidades', 'D', 'branch', $own, 'last_valid_with_age', 7, null,
                'El inventario limita lo que se puede vender y distingue demanda de venta observada; el quiebre censura la venta. Se usa la foto vigente al corte.',
                ['identifiers' => ['branch_id', 'product_id'], 'dates' => ['snapshot_date'], 'data_status' => 'native'], false),
            self::make('capacity_inventory', 'capacity_inventory.location_capacity', 'Capacidad de almacenamiento', 'STATIC', 'location_capacities', 'unidad de capacidad', 'event', 'branch', $own, 'disable_feature', null, null,
                'Capacidad de bodega o planta que acota el abastecimiento y la producción; cambia pocas veces y rige desde su vigencia.',
                ['identifiers' => ['branch_id', 'warehouse_id'], 'dates' => ['valid_from', 'valid_to'], 'data_status' => 'native'], false),
            self::make('calendar_events', 'calendar_events.holidays_and_events', 'Calendario, feriados y eventos', 'KNOWN_IN_ADVANCE', 'calendar_events', 'días', 'event', 'country', $own, 'disable_feature', null, null,
                'Feriados, campañas y temporadas conocidas de antemano (heredado de V2). Un evento sin known_at no se usa a futuro en un backtest.',
                ['identifiers' => ['scope', 'scope_ref', 'code'], 'dates' => ['starts_on', 'ends_on', 'known_at', 'revision'], 'data_status' => 'native'], false),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function make(
        string $family, string $key, string $name, string $class, string $storage, string $unit, string $frequency, string $geo, ?string $license,
        string $missing, ?int $staleness, ?int $retention, string $justification, array $schema, bool $informative,
    ): array {
        $native = in_array($storage, ['sales', 'product_price_history', 'promotions', 'calendar_events', 'commercial_commitments', 'inventory_snapshots', 'location_capacities'], true);

        return [
            'family' => $family,
            'key' => $key,
            'name' => $name,
            'availability_class' => $class,
            'storage' => $storage,
            'external_indicator_id' => null,
            'unit' => $unit,
            'frequency' => $frequency,
            'geo_scope' => $geo,
            'source_key' => null,
            'license' => $license,
            'missing_strategy' => $missing,
            'max_staleness_days' => $staleness,
            'retention_days' => $retention,
            'sector_justification' => $justification.($staleness !== null ? ' '.self::THRESHOLD_NOTE : ''),
            'schema' => array_merge([
                'source_status' => $native ? 'Dato propio de la empresa; no depende de una integración externa.' : 'Sin integración activa: no hay fuente aprobada ni datos externos cargados.',
                'retention_note' => $native ? 'Sigue la política de retención de la empresa.' : 'A definir con la licencia de la fuente cuando exista.',
                'thresholds_status' => 'a_aprobar_en_el_piloto',
            ], $schema),
            'is_informative_alert' => $informative,
        ];
    }
}
