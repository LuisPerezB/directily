<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frecuencia, riesgo de inactividad (ausencia respecto al patrón propio; NO «abandono») y cambio de comportamiento
 * de un comprador a la fecha de una predicción.
 */
class CustomerActivity extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'customer_activity';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'as_of' => 'date:Y-m-d',
            'last_purchase_date' => 'date:Y-m-d',
            'purchase_frequency_per_30d' => 'float',
            'median_interval_days' => 'float',
            'mean_interval_days' => 'float',
            'interval_p75_days' => 'float',
            'interval_p90_days' => 'float',
            'inactivity_risk' => 'float',
            'frequency_ratio' => 'float',
            'behavior_p_value' => 'float',
            'spend_recent' => 'float',
            'spend_prior' => 'float',
            'spend_ratio' => 'float',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
