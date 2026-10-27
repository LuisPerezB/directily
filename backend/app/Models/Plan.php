<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plan SaaS (asignación manual por superadmin en el MVP, sin pasarela de pagos). */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'description', 'limits', 'monthly_price_usd', 'is_active'];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'is_active' => 'boolean',
            'monthly_price_usd' => 'decimal:2',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
