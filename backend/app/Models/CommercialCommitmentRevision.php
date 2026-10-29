<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Versión histórica de un compromiso (solo inserción). `recorded_at` es la ingestión; `known_at`, el conocimiento en la fuente. */
class CommercialCommitmentRevision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'known_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'recorded_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }
}
