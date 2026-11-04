<?php

declare(strict_types=1);

namespace App\Services\DataCore\Bronze;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Purga de Bronze por retención de una empresa (ADR-V25-24). Primero alinea `retention_until` de sus objetos con la política
 * vigente (BronzeRetention); luego purga los vencidos —contenido borrado, fila en estado `purged`, auditoría `bronze.purged`— y
 * conserva los que tienen registros de cuarentena pendientes (su reprocesamiento los necesita). Un fallo en un objeto no detiene
 * a los demás y se informa.
 */
final class BronzePurger
{
    public function __construct(
        private readonly BronzeStore $store,
        private readonly BronzeRetention $retention,
        private readonly TenantContext $context,
    ) {}

    /** @return array{retention_days: int, policy_source: string, realigned: int, due: int, purged: int, held_pending_quarantine: int, failed: int} */
    public function run(Tenant $tenant, bool $dryRun = false, ?CarbonInterface $today = null): array
    {
        $realigned = $dryRun ? 0 : $this->retention->sync($tenant);
        $result = $this->retention->due($tenant, $today);
        $purged = 0;
        $failed = 0;
        if (! $dryRun) {
            foreach ($result['due'] as $object) {
                try {
                    $this->context->runAs($tenant, fn () => $this->store->purge($object, 'retention'));
                    $purged++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('No se pudo purgar un objeto Bronze', ['tenant_id' => $tenant->id, 'bronze_object_id' => $object->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return [
            'retention_days' => $this->retention->daysFor($tenant),
            'policy_source' => $this->retention->sourceFor($tenant),
            'realigned' => $realigned,
            'due' => count($result['due']),
            'purged' => $purged,
            'held_pending_quarantine' => count($result['held']),
            'failed' => $failed,
        ];
    }

    /**
     * Borra TODO el contenido Bronze de una empresa que se elimina definitivamente (LOPDP: la eliminación de la empresa incluye los
     * originales). Recorre cada disco con que se guardaron objetos (más el configurado) y borra el prefijo `tenants/<uuid>/bronze`.
     * Las filas desaparecen en cascada con la empresa. Si un disco falla, lanza la excepción: quien llama NO debe borrar la empresa
     * para poder reintentar, o los originales quedarían huérfanos.
     *
     * @return int objetos Bronze con contenido que se borraron
     */
    public function eraseTenant(Tenant $tenant): int
    {
        $disks = BronzeObject::withoutTenantScope()->where('tenant_id', $tenant->id)->get(['id', 'metadata', 'status'])->toBase()
            ->map(fn ($o) => $o->metadata['disk'] ?? null)->push((string) config('bronze.disk', 'local'))
            ->filter(fn ($name) => is_string($name) && config("filesystems.disks.{$name}") !== null)->unique();
        $objects = BronzeObject::withoutTenantScope()->where('tenant_id', $tenant->id)->where('status', '!=', 'purged')->count();
        foreach ($disks as $name) {
            Storage::disk($name)->deleteDirectory("tenants/{$tenant->uuid}/bronze");
        }

        return $objects;
    }
}
