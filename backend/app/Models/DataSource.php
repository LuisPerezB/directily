<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabla `data_sources`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-core.
 */
class DataSource extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['credentials_encrypted'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'last_synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'credentials_encrypted' => 'encrypted:array',
        ];
    }
}
