<?php

declare(strict_types=1);

namespace App\Services\Supply;

use App\Models\Alert;
use App\Models\CoreRun;
use Carbon\CarbonImmutable;

/** Transiciones de estado de una corrida de decisiones del lado de Laravel (el motor actualiza el detalle). */
class SupplyRunRecorder
{
    public function markFailed(CoreRun $run, string $message): void
    {
        $run->refresh();
        if ($run->status === 'completed') {
            return;
        }
        $run->forceFill([
            'status' => 'failed',
            'error_message' => mb_substr($message, 0, 2000),
            'completed_at' => CarbonImmutable::now(),
            'is_current' => false,
        ])->save();

        Alert::raise([
            'type' => 'run_failed',
            'severity' => 'warning',
            'title' => "La corrida {$run->core}/{$run->operation} v{$run->version} no pudo completarse",
            'message' => $message,
            'payload' => ['core_run_id' => $run->id, 'core' => $run->core, 'operation' => $run->operation, 'version' => $run->version],
            'related_type' => 'core_run',
            'related_id' => $run->id,
            'dedup_key' => "supply_run_failed:{$run->id}",
        ]);
    }
}
