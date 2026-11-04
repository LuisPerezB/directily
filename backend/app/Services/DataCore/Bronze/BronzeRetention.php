<?php

declare(strict_types=1);

namespace App\Services\DataCore\Bronze;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Política de retención de Bronze por empresa (V2.5 §3; ADR-V25-24).
 *
 * Precedencia: `tenants.settings` → `data.bronze_retention_days` (días; 0 = sin vencimiento) sobre
 * `config('bronze.retention_days')`. Un valor de empresa inválido (no entero, negativo o por encima de
 * `bronze.retention_max_days`) se ignora y rige el de la plataforma: nunca se interpreta un valor corrupto como
 * «purgar ya». La política vigente se aplica a TODOS los objetos de la empresa (también a los ya guardados):
 * vence el día `última recepción + días` (fecha de la zona horaria de la empresa; la última recepción es `received_at` o, si el objeto
 * se purgó y volvió a recibirse, la rehidratación más reciente); se conserva ese día inclusive.
 */
final class BronzeRetention
{
    public const SETTING_KEY = 'data.bronze_retention_days';

    public function __construct(private readonly TenantContext $context) {}

    /** Días de retención vigentes de la empresa (0 = sin vencimiento). */
    public function daysFor(Tenant $tenant): int
    {
        $override = self::tenantOverride($tenant);

        return $override ?? max(0, (int) config('bronze.retention_days', 0));
    }

    /** 'tenant' si la política viene de la empresa; 'platform' si es el valor por defecto de la plataforma. */
    public function sourceFor(Tenant $tenant): string
    {
        return self::tenantOverride($tenant) !== null ? 'tenant' : 'platform';
    }

    /** Fecha (Y-m-d) hasta la que se conserva un objeto recibido en `$receivedAt`, o null si no vence. */
    public function untilFor(Tenant $tenant, CarbonInterface $receivedAt): ?string
    {
        $days = $this->daysFor($tenant);
        if ($days <= 0) {
            return null;
        }

        return Carbon::instance($receivedAt)->setTimezone($tenant->timezone ?: 'UTC')->addDays($days)->toDateString();
    }

    /**
     * Recalcula `retention_until` de los objetos no purgados de la empresa con la política vigente.
     *
     * @return int objetos cuya fecha cambió
     */
    public function sync(Tenant $tenant): int
    {
        return $this->context->runAs($tenant, function () use ($tenant): int {
            $changed = 0;
            BronzeObject::query()->where('status', '!=', 'purged')->orderBy('id')->chunkById(500, function ($objects) use ($tenant, &$changed): void {
                foreach ($objects as $object) {
                    $until = $this->untilFor($tenant, self::lastReceipt($object));
                    if ($until !== $object->retention_until?->toDateString()) {
                        $object->forceFill(['retention_until' => $until])->save();
                        $changed++;
                    }
                }
            });

            return $changed;
        });
    }

    /**
     * Objetos vencidos a la fecha `$today` (por defecto, hoy en la zona horaria de la empresa) con la política VIGENTE (se evalúa
     * sobre `received_at`, no sobre `retention_until`, así que un simulacro no depende de haber alineado las filas) y no retenidos:
     * un objeto con registros de cuarentena pendientes se conserva porque su reprocesamiento lo necesita.
     *
     * @return array{due: list<BronzeObject>, held: list<BronzeObject>}
     */
    public function due(Tenant $tenant, ?CarbonInterface $today = null): array
    {
        $days = $this->daysFor($tenant);
        if ($days <= 0) {
            return ['due' => [], 'held' => []];
        }
        $zone = $tenant->timezone ?: 'UTC';
        $day = ($today !== null ? Carbon::instance($today)->setTimezone($zone) : Carbon::now($zone))->toDateString();

        return $this->context->runAs($tenant, function () use ($days, $zone, $day): array {
            $due = [];
            $held = [];
            BronzeObject::query()->where('status', '!=', 'purged')
                ->whereRaw("((COALESCE(NULLIF(metadata->>'last_rehydrated_at', '')::timestamptz, received_at) AT TIME ZONE ?)::date + ?::int) < ?::date", [$zone, $days, $day])
                ->orderBy('id')->each(function (BronzeObject $object) use (&$due, &$held): void {
                    $pending = DB::table('quarantine_records')->where('tenant_id', $object->tenant_id)
                        ->where('bronze_object_id', $object->id)->where('status', 'pending')->exists();
                    if ($pending) {
                        $held[] = $object;
                    } else {
                        $due[] = $object;
                    }
                });

            return ['due' => $due, 'held' => $held];
        });
    }

    /** Última recepción del contenido: la rehidratación más reciente si el objeto fue purgado y volvió a recibirse; si no, `received_at`. */
    public static function lastReceipt(BronzeObject $object): CarbonInterface
    {
        $rehydrated = $object->metadata['last_rehydrated_at'] ?? null;

        return is_string($rehydrated) && $rehydrated !== '' ? Carbon::parse($rehydrated) : Carbon::instance($object->received_at);
    }

    private static function tenantOverride(Tenant $tenant): ?int
    {
        $value = data_get($tenant->settings ?? [], self::SETTING_KEY);
        if ($value === null || is_bool($value) || (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/', $value) === 1))) {
            return null;
        }
        $days = (int) $value;

        return $days >= 0 && $days <= (int) config('bronze.retention_max_days', 3650) ? $days : null;
    }
}
