<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sincronización de una fuente SQL (extracción → importación). Ver App\Services\DataCore\Sync. */
class SourceSync extends Model
{
    use BelongsToTenant;

    public const ACTIVE = ['queued', 'running'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'since' => 'datetime',
            'watermark' => 'datetime',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(SourceMapping::class, 'source_mapping_id');
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }
}
