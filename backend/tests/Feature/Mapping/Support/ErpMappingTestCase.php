<?php

declare(strict_types=1);

namespace Tests\Feature\Mapping\Support;

use App\Enums\TenantRole;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataCore\Support\ErpSourceDatabase;
use Tests\TestCase;

/**
 * Base de las pruebas del Auto Mapper contra el «ERP de origen» ficticio de PostgreSQL (esquemas en español). Sin la base de pruebas del ERP la prueba se omite
 * CON ruido (ver ErpSourceDatabase::skipMessage): no es un fallo, pero deja sin verificar el recorrido de punta a punta.
 */
abstract class ErpMappingTestCase extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, RefreshDatabase;

    protected MappingErpDatabase $erp;

    protected Tenant $tenant;

    protected User $owner;

    protected int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $erp = MappingErpDatabase::tryConnect();
        if ($erp === null) {
            $this->markTestSkipped(ErpSourceDatabase::skipMessage(
                'el Auto Mapper de punta a punta contra un ERP PostgreSQL con columnas en español: descubrimiento de esquema de solo lectura, propuesta determinista, aprobación humana, plantillas públicas y de empresa, deriva '
                .'de esquema, enmascaramiento de muestras, aislamiento entre empresas e importación de las filas canónicas esperadas.'
            ));
        }
        $this->erp = $erp;
        // El ERP de pruebas es diminuto: con la frecuencia mínima por defecto (5 filas) ningún valor de vocabulario se mostraría. MappingPrivacyTest prueba el valor por defecto.
        config(['mapping.sampling.min_value_count' => 1]);
        $this->erp->createSchema();
        $this->erp->seed();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->sourceId = $this->createSource($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->erp)) {
            $this->erp->dropSchema();
        }
        parent::tearDown();
    }

    /** Fuente PostgreSQL del ERP ficticio para la empresa activa en la petición. */
    protected function createSource(Tenant $tenant, array $overrides = []): int
    {
        $connection = $this->erp->connection();

        return (int) $this->postJson('/api/v1/data-sources', array_merge([
            'type' => 'connector', 'connector_key' => 'sql_postgresql', 'name' => 'ERP ficticio W6-C',
            'config' => ['host' => $connection['host'], 'port' => $connection['port'], 'database' => $connection['database'], 'statement_timeout_seconds' => 30],
            'credentials' => ['username' => $connection['username'], 'password' => $connection['password']],
        ], $overrides))->assertCreated()->json('data.id');
    }

    /** @param list<string>|null $entities */
    protected function propose(?array $entities = null, array $extra = [], ?int $sourceId = null): TestResponse
    {
        return $this->postJson('/api/v1/data-sources/'.($sourceId ?? $this->sourceId).'/mapping-proposals', array_filter(['entities' => $entities] + $extra, fn ($v) => $v !== null));
    }

    /** @return array<string, int> entidad → id de la plantilla propuesta */
    protected function proposeIds(array $entities, array $extra = []): array
    {
        $response = $this->propose($entities, $extra)->assertCreated();
        $ids = [];
        foreach ($response->json('data.proposals') as $proposal) {
            $ids[$proposal['entity']] = (int) $proposal['template']['id'];
        }

        return $ids;
    }

    protected function approve(int $templateId): TestResponse
    {
        return $this->postJson("/api/v1/mapping-templates/{$templateId}/approve");
    }

    protected function apply(int $templateId, ?int $sourceId = null): TestResponse
    {
        return $this->postJson('/api/v1/data-sources/'.($sourceId ?? $this->sourceId)."/mapping-templates/{$templateId}/apply");
    }

    /** @return array<string, mixed> */
    protected function sync(string $entity, string $mode = 'full'): array
    {
        $response = $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => $entity, 'mode' => $mode])->assertStatus(202);

        return $this->getJson('/api/v1/syncs/'.$response->json('data.id'))->assertOk()->json('data');
    }

    protected function sales(): Builder
    {
        return Sale::withoutTenantScope()->where('tenant_id', $this->tenant->id);
    }

    protected function owner(Tenant $tenant): User
    {
        return $tenant->users()->wherePivot('role', TenantRole::Owner->value)->firstOrFail();
    }
}
