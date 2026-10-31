<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `exchange_rates`. Tasa de cambio explícita (con fecha y fuente). Sin tasa, una oferta o presupuesto en otra moneda no es comparable.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class ExchangeRate extends Model
{
    use BelongsToTenant;

    protected $table = 'exchange_rates';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['rate' => 'float', 'valid_on' => 'date:Y-m-d', 'known_at' => 'datetime'];
    }
}
