<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Connectors\Sql\SqlHostPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * La política anti-SSRF vista desde la API (ADR-V25-15): un administrador de empresa con `datasources.manage` no puede apuntar un
 * conector SQL a metadatos de nube ni a la red interna, ni con formas numéricas, ni con IPv4 mapeada en IPv6; y lo que recibe es un
 * mensaje genérico que no revela la red. Solo IP literales: no depende de DNS ni de ningún servidor.
 */
final class SqlSourceHostPolicyApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    /** @return array<string, mixed> */
    private function payload(string $host): array
    {
        return [
            'type' => 'connector', 'connector_key' => 'sql_postgresql', 'name' => 'ERP de prueba',
            'config' => ['host' => $host, 'database' => 'erp'], 'credentials' => ['username' => 'lector', 'password' => 'clave-secreta-ssrf'],
        ];
    }

    public function test_numeric_host_forms_are_rejected_when_the_source_is_saved(): void
    {
        foreach (['2130706433', '0x7f.0.0.1', '0177.0.0.1', '0xA9FEA9FE', '0xa9.0xfe.0xa9.0xfe', '127.1'] as $host) {
            $this->postJson('/api/v1/data-sources', $this->payload($host))->assertStatus(422)->assertJsonValidationErrors(['config.host']);
        }
        $this->assertSame(0, DB::table('data_sources')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_metadata_loopback_and_private_destinations_leave_the_source_inactive_with_a_generic_message(): void
    {
        foreach (['169.254.169.254', '::ffff:169.254.169.254', '[::ffff:a9fe:a9fe]', 'fd00:ec2::254', '127.0.0.1', '::1', '10.0.0.5', '192.168.1.10', '100.64.0.1', 'fc00::5', '0.0.0.0'] as $host) {
            $response = $this->postJson('/api/v1/data-sources', $this->payload($host))->assertCreated();
            $response->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.last_error', SqlHostPolicy::DENIED_MESSAGE);
            $this->assertStringNotContainsString('clave-secreta-ssrf', $response->getContent());
            $id = $response->json('data.id');
            // La prueba de conexión devuelve el mismo mensaje genérico: no distingue metadatos, loopback ni red privada.
            $this->postJson("/api/v1/data-sources/{$id}/test")->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.message', SqlHostPolicy::DENIED_MESSAGE);
        }
    }

    public function test_a_closed_allow_list_rejects_other_destinations_even_if_they_are_public(): void
    {
        config(['connectors.sql.allowed_hosts' => ['erp.cliente.example']]);
        $response = $this->postJson('/api/v1/data-sources', $this->payload('8.8.8.8'))->assertCreated();
        $response->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.last_error', SqlHostPolicy::DENIED_MESSAGE);
    }
}
