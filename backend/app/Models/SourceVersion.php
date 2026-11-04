<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantOrPublic;
use Illuminate\Database\Eloquent\Model;

/** Versión/revisión de una fuente con licencia y huella (ADR-V25-25). `tenant_id` NULL = fuente pública. Hoy no hay fuentes externas activas. */
class SourceVersion extends Model
{
    use BelongsToTenantOrPublic;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'retrieved_at' => 'datetime',
            'coverage' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
