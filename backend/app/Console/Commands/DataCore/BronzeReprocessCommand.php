<?php

declare(strict_types=1);

namespace App\Console\Commands\DataCore;

use App\Exceptions\DomainStateException;
use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Services\DataCore\Quality\BronzeReprocessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan predictily:bronze-reprocess {id}` — re-ejecuta, de forma determinista, la normalización y la escritura de un objeto Bronze de
 * ventas (ADR-V25-04 y ADR-V25-24; W2-A). No destruye datos y es idempotente: una segunda ejecución reporta los documentos como `unchanged`.
 * Verifica el SHA-256 del original antes de leerlo. Sin esta orden, el original solo servía para auditoría.
 *
 * Si el original es VIEJO, no trae `source_recorded_at` y hay documentos con otro contenido que ya se escribieron DESPUÉS de él, reprocesarlo
 * los revertiría: se detiene con un aviso (código de salida 1) y `--force` lo confirma (la salida informa cuántos documentos más nuevos sobrescribió).
 */
final class BronzeReprocessCommand extends Command
{
    protected $signature = 'predictily:bronze-reprocess {id : id del objeto Bronze (bronze_objects.id)} {--json : salida en JSON} {--force : escribe aunque haya documentos más nuevos sin orden de fuente (los sobrescribe)}';

    protected $description = 'Reprocesa de forma determinista un objeto Bronze de ventas (idempotente: la segunda vez da unchanged).';

    public function handle(TenantContext $context, BronzeReprocessor $reprocessor): int
    {
        $object = BronzeObject::withoutTenantScope()->find((int) $this->argument('id'));
        if ($object === null) {
            $this->error('No existe un objeto Bronze con ese id.');

            return self::FAILURE;
        }
        $tenant = Tenant::query()->find($object->tenant_id);
        if ($tenant === null) {
            $this->error('La empresa del objeto Bronze ya no existe.');

            return self::FAILURE;
        }
        try {
            $result = $context->runAs($tenant, fn () => $reprocessor->reprocess($tenant, BronzeObject::query()->findOrFail($object->id), (bool) $this->option('force')));
        } catch (DomainStateException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('El reprocesamiento falló: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $d = $result['documents'];
        if ($result['overwrote_newer_documents'] > 0) {
            $this->warn("AVISO (--force): {$result['overwrote_newer_documents']} documento(s) más nuevos que este original, sin orden de fuente, fueron sobrescritos con su contenido.");
        }
        $this->info("Objeto Bronze #{$result['object_id']} ({$result['kind']}) reprocesado: nuevos {$d['new']}, actualizados {$d['updated']}, sin cambios {$d['unchanged']}, "
            ."versión anterior descartada {$d['stale_skipped']}, rechazados {$d['rejected']}.");

        return self::SUCCESS;
    }
}
