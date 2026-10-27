<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\TenantRole;
use App\Models\ImportJob;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\ImportService;
use App\Services\Imports\ImportStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carga los datasets FICTICIOS de data/demo/ usando el MISMO servicio de importación que un
 * cliente real (sin inserciones directas). Archivos generados por el motor
 * (predictily_engine.synthetic, semilla fija).
 */
final class DemoDataService
{
    /** Orden de carga: catálogos primero, luego transacciones. */
    public const FILES = [
        'branches' => ImportEntity::Branches,
        'suppliers' => ImportEntity::Suppliers,
        'products' => ImportEntity::Products,
        'sales' => ImportEntity::Sales,
        'inventory' => ImportEntity::Inventory,
        'lead_times' => ImportEntity::LeadTimes,
        'promotions' => ImportEntity::Promotions,
    ];

    public function __construct(private readonly ImportService $imports, private readonly TenantContext $context) {}

    public function path(string $slug, string $kind): string
    {
        return rtrim((string) config('predictily.demo.path'), '/')."/{$slug}_{$kind}.csv";
    }

    public function available(string $slug): bool
    {
        return is_file($this->path($slug, 'sales')) && is_file($this->path($slug, 'products'));
    }

    /** Crea (o reutiliza) la empresa demo y su usuario. */
    public function ensureTenant(string $slug): Tenant
    {
        $config = config("predictily.demo.tenants.{$slug}") ?? throw new RuntimeException("Dataset demo desconocido: {$slug}");

        return DB::transaction(function () use ($config): Tenant {
            $user = User::withTrashed()->where('email', $config['email'])->first();
            if ($user === null) {
                $user = User::query()->create(['name' => $config['user_name'], 'email' => $config['email'], 'password' => config('predictily.demo.password')]);
            } else {
                $user->restore();
            }
            $tenant = $user->tenants()->where('tenants.name', $config['name'])->first();
            if ($tenant === null) {
                $tenant = new Tenant;
                $tenant->fill([
                    'name' => $config['name'],
                    'legal_name' => $config['name'],
                    'sector' => $config['sector'],
                    'plan_id' => Plan::query()->where('code', 'profesional')->value('id') ?? Plan::query()->where('code', 'trial')->value('id'),
                ]);
                $tenant->save();
                $tenant->users()->attach($user->id, ['role' => TenantRole::Owner->value]);
            }

            return $tenant;
        });
    }

    /**
     * Importa todos los archivos del dataset de forma síncrona.
     *
     * @return array<string, ImportJob>
     */
    public function load(Tenant $tenant, string $slug, ?User $user = null, ?callable $progress = null): array
    {
        if (! $this->available($slug)) {
            throw new RuntimeException('No se encuentran los archivos demo en '.config('predictily.demo.path').'. Genérelos con: cd engine && .venv/bin/python -m predictily_engine.cli generate-demo --out ../data/demo --seed 42');
        }

        return $this->context->runAs($tenant, function () use ($tenant, $slug, $user, $progress): array {
            $jobs = [];
            foreach (self::FILES as $kind => $entity) {
                $path = $this->path($slug, $kind);
                if (! is_file($path)) {
                    continue;
                }
                $progress && $progress("Importando {$kind}…");
                $job = $this->imports->upload($tenant, $user, $path, $entity);
                if ($job->status !== ImportStatus::AWAITING_MAPPING) {
                    throw new RuntimeException("No se pudo analizar {$kind}: {$job->error_message}");
                }
                $job = $this->imports->importNow($job, null, ['create_missing_products' => true, 'skip_invalid_rows' => true]);
                if ($job->status !== ImportStatus::COMPLETED) {
                    throw new RuntimeException("La importación de {$kind} terminó en estado {$job->status}: {$job->error_message}");
                }
                $jobs[$kind] = $job;
                $progress && $progress(sprintf('  ✓ %s: %d filas válidas', $kind, $job->stats['rows_valid'] ?? 0));
            }
            $tenant->forceFill(['onboarding_completed_at' => now()])->save();

            return $jobs;
        });
    }
}
