<?php

declare(strict_types=1);

namespace App\Http\Resources\Core;

use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin ImportJob */
final class ImportJobResource extends JsonResource
{
    public bool $withQuality = true;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $preview = $this->preview ?? [];
        $quality = $this->withQuality
            ? DB::table('data_quality_reports')->where('tenant_id', $this->tenant_id)->where('import_job_id', $this->id)->latest('id')->first(['score', 'issues', 'summary'])
            : null;

        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'original_filename' => $this->original_filename,
            'file_format' => $this->file_format,
            'file_size' => $this->file_size,
            'file_hash' => $this->file_hash,
            'status' => $this->status,
            'progress' => $this->progress,
            'detected_columns' => $this->detected_columns ?? [],
            'suggested_mapping' => $this->suggested_mapping ?? (object) [],
            'column_mapping' => $this->column_mapping ?? (object) [],
            'options' => $this->options ?? (object) [],
            'preview' => [
                'headers' => $preview['headers'] ?? [],
                'rows' => $preview['rows'] ?? [],
                'dialect' => $preview['dialect'] ?? null,
            ],
            'normalized_preview' => $preview['normalized'] ?? [],
            'stats' => $this->stats ?? (object) [],
            'quality_report' => $quality ? [
                'score' => (float) $quality->score,
                'issues' => json_decode((string) $quality->issues, true) ?? [],
                'summary' => json_decode((string) $quality->summary, true) ?? [],
            ] : null,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'user' => $this->relationLoaded('user') && $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
        ];
    }
}
