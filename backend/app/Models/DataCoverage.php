<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hasta qué fecha está completa cada fuente (ADR-V2-05): fuera de la cobertura el dato es ausente, no cero. */
class DataCoverage extends Model
{
    use BelongsToTenant;

    protected $table = 'data_coverage';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'complete_from' => 'date:Y-m-d',
            'complete_through' => 'date:Y-m-d',
            'last_ingested_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
