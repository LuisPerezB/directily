<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Huella reproducible de los datos usados por una ejecución (ADR-V2-04). La escribe el motor. */
class DataSnapshot extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'as_of' => 'date:Y-m-d',
            'components' => 'array',
            'row_counts' => 'array',
            'coverage' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
