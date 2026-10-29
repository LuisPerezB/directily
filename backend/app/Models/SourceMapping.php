<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consulta/vista autorizada para extraer una entidad de una fuente SQL. Versionada: cambiar la consulta, el
 * mapeo de campos o las opciones crea una versión nueva SIN aprobar. Solo una versión aprobada y activa sincroniza.
 */
class SourceMapping extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'field_map' => 'array',
            'options' => 'array',
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
