<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Tabla `bridge_enrolment_codes`: código de registro de un solo uso (solo su SHA-256). Es una credencial: nunca se exporta ni se lista. */
class BridgeEnrolmentCode extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
