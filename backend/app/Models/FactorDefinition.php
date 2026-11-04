<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantOrPublic;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de factores (V2.1 §24.4; ADR-V25-25). `tenant_id` NULL = definición del catálogo público (lectura para todas las empresas; solo el
 * propietario de la plataforma la escribe). NO es un segundo almacén de features: apunta al almacenamiento nativo de cada familia (`storage`).
 * `is_active` del catálogo es la disponibilidad que otorga la plataforma (las 17 familias base nacen en false); la elección de cada empresa vive
 * en `tenants.settings.factor_activation` y nunca modifica esta fila.
 */
class FactorDefinition extends Model
{
    use BelongsToTenantOrPublic;

    /** Las 17 familias de V2.1 §24.4 (mismo orden que el contrato 2026_03_04). */
    public const FAMILIES = [
        'sales_history', 'prices_discounts', 'competition', 'search_trends', 'consumer_preferences', 'weather', 'epidemiology', 'animal_plant_health',
        'food_safety_alerts', 'trade_regulatory', 'export_statistics', 'orders_contracts', 'shared_projections', 'production_estimates',
        'reviews_satisfaction', 'capacity_inventory', 'calendar_events',
    ];

    /** Clases de disponibilidad temporal (ADR-V25-06). */
    public const AVAILABILITY_CLASSES = ['OBSERVED_HISTORICAL', 'STATIC', 'KNOWN_IN_ADVANCE', 'THIRD_PARTY_FORECAST', 'USER_SCENARIO', 'UNAVAILABLE_FOR_HORIZON'];

    /** Almacenamiento al que puede apuntar una definición: tablas nativas, series y eventos externos, y el contrato de pronósticos compartidos. */
    public const STORAGES = [
        'sales', 'product_price_history', 'promotions', 'calendar_events', 'commercial_commitments', 'inventory_snapshots', 'location_capacities',
        'external_indicator_observations', 'external_events', 'shared_forecast_grants',
    ];

    public const MISSING_STRATEGIES = ['disable_feature', 'last_valid_with_age', 'unavailable'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'is_active' => 'boolean',
            'is_informative_alert' => 'boolean',
            'max_staleness_days' => 'integer',
            'retention_days' => 'integer',
            'external_indicator_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
