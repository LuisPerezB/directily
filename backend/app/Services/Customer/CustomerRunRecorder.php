<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Models\Alert;
use App\Models\CoreRun;
use Carbon\CarbonImmutable;

/** Transiciones de estado de una corrida de comportamiento del lado de Laravel (el motor actualiza el detalle). */
class CustomerRunRecorder
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
            'type' => 'customer_run_failed',
            'severity' => 'warning',
            'title' => "La corrida de comportamiento de compra v{$run->version} ({$run->operation}) no pudo completarse",
            'message' => $message,
            'payload' => ['core_run_id' => $run->id, 'operation' => $run->operation, 'version' => $run->version],
            'related_type' => 'core_run',
            'related_id' => $run->id,
            'dedup_key' => "customer_run_failed:{$run->id}",
        ]);
    }
}
