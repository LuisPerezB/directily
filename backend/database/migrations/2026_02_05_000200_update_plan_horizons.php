<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 2 · P1 (ADR-V2-10): horizontes de demanda 7/14/30/60/90. Actualiza SOLO los planes que aún
 * conservan los horizontes anteriores por defecto (los personalizados por el superadmin no se tocan):
 * [7, 30, 90] → [7, 14, 30, 60, 90] y [7, 30] → [7, 14, 30].
 */
return new class extends Migration
{
    private const MAP = [
        [[7, 30, 90], [7, 14, 30, 60, 90]],
        [[7, 30], [7, 14, 30]],
    ];

    public function up(): void
    {
        $this->swap(self::MAP);
    }

    public function down(): void
    {
        $this->swap(array_map(fn ($pair) => [$pair[1], $pair[0]], self::MAP));
    }

    /** @param list<array{0: list<int>, 1: list<int>}> $pairs */
    private function swap(array $pairs): void
    {
        foreach (DB::table('plans')->get(['id', 'limits']) as $plan) {
            $limits = json_decode((string) $plan->limits, true);
            if (! is_array($limits) || ! isset($limits['horizons']) || ! is_array($limits['horizons'])) {
                continue;
            }
            $current = array_map('intval', $limits['horizons']);
            foreach ($pairs as [$from, $to]) {
                if ($current === $from) {
                    $limits['horizons'] = $to;
                    DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
                    break;
                }
            }
        }
    }
};
