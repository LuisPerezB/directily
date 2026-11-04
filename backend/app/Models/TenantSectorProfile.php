<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Perfil de relevancia sectorial de la empresa (V2.1 §24.2/§24.11). Una empresa puede tener varios, con prioridad (menor = mayor prioridad) y
 * alcance (empresa, sucursal, categoría o producto). `profile_key` es el código del perfil registrado en el motor (platform/extensions.py).
 */
class TenantSectorProfile extends Model
{
    use BelongsToTenant;

    public const SCOPES = ['tenant', 'branch', 'category', 'product'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
