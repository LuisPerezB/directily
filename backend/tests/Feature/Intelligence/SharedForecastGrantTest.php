<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Enums\TenantRole;
use App\Models\SharedForecastGrant;
use App\Models\SharedForecastGrantAccess;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Intelligence\SharedGrants\SharedForecastGrantService;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\Feature\Intelligence\Support\IntelligenceFixtures;
use Tests\TestCase;

/**
 * SharedForecastGrant (V2.1 §10; T14; ADR-V25-25): contrato DESACTIVADO por bandera y pruebas negativas P0 de aislamiento. Emisor A, receptor B y
 * tercero C. Los datos son ficticios; los marcadores «SECRETO» no pueden aparecer jamás en nada que lea el receptor o el tercero.
 */
final class SharedForecastGrantTest extends TestCase
{
    use CustomerFixtures, IntelligenceFixtures, RefreshDatabase;

    private const BASE = '/api/v1/intelligence/shared-forecast-grants';

    private const RECEIVED = '/api/v1/intelligence/received-forecast-grants';

    private Tenant $issuer;

    private User $issuerOwner;

    private Tenant $recipient;

    private User $recipientOwner;

    private Tenant $third;

    private User $thirdOwner;

    /** @var array{branch: int, products: array<string, int>, customers: array<string, int>} */
    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00', 'America/Guayaquil'));
        [$this->issuer, $this->issuerOwner] = $this->createTenantWithUser(TenantRole::Owner);
        [$this->recipient, $this->recipientOwner] = $this->createTenantWithUser(TenantRole::Owner);
        [$this->third, $this->thirdOwner] = $this->createTenantWithUser(TenantRole::Owner);
        $this->catalog = $this->seedCustomerCatalog($this->issuer);
        DB::table('customers')->where('id', $this->catalog['customers']['c1'])->update(['external_code' => 'CLIENTE-SECRETO-A']);
        $this->seedOneSale($this->issuer, $this->catalog, '2026-10-01');
        DB::table('sales')->where('tenant_id', $this->issuer->id)->update(['document_number' => 'FAC-SECRETA-A']);
        $this->seedIssuerForecast();
    }

    private function enable(bool $on = true): void
    {
        config(['intelligence.shared_forecast_grants.enabled' => $on]);
    }

    /** Pronóstico completado y vigente del emisor: nivel producto (día y semana), categoría, serie por sucursal y total; con ingresos y precio supuestos. */
    private function seedIssuerForecast(int $version = 1, bool $current = true): int
    {
        if ($current) {
            DB::table('forecast_runs')->where('tenant_id', $this->issuer->id)->update(['is_current' => false]);
        }
        $runId = (int) DB::table('forecast_runs')->insertGetId([
            'tenant_id' => $this->issuer->id, 'version' => $version, 'run_type' => 'production', 'status' => 'completed', 'triggered_by' => 'user', 'horizon_days' => 30,
            'frequency' => 'D', 'is_current' => $current, 'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows = [];
        $base = ['tenant_id' => $this->issuer->id, 'forecast_run_id' => $runId, 'unit_price_assumed' => 8.5, 'revenue_yhat' => 999.99, 'model_family' => 'modelo_secreto_a'];
        foreach ($this->catalog['products'] as $key => $productId) {
            for ($d = 1; $d <= 10; $d++) {
                $date = sprintf('2026-11-%02d', $d);
                $y = 10 * $version + $d + ($key === 'p2' ? 100 : 0);
                $rows[] = $base + ['node_key' => "product:{$productId}", 'level' => 'product', 'product_id' => $productId, 'granularity' => 'day', 'target_date' => $date, 'period_days' => 1, 'horizon_step' => $d,
                    'yhat' => $y, 'lo_80' => $y - 2, 'hi_80' => $y + 2, 'lo_95' => $y - 4, 'hi_95' => $y + 4];
                $rows[] = $base + ['node_key' => "series:{$this->catalog['branch']}:{$productId}", 'level' => 'series', 'branch_id' => $this->catalog['branch'], 'product_id' => $productId, 'granularity' => 'day',
                    'target_date' => $date, 'period_days' => 1, 'horizon_step' => $d, 'yhat' => 777.7777, 'lo_80' => 770.7777, 'hi_80' => 780.7777];
            }
            $rows[] = $base + ['node_key' => "product:{$productId}", 'level' => 'product', 'product_id' => $productId, 'granularity' => 'week', 'target_date' => '2026-11-02', 'period_days' => 7, 'horizon_step' => 1,
                'yhat' => 555.5555, 'lo_80' => 500, 'hi_80' => 600, 'lo_95' => 450, 'hi_95' => 650];
            $rows[] = $base + ['node_key' => "product:{$productId}", 'level' => 'product', 'product_id' => $productId, 'granularity' => 'week', 'target_date' => '2026-11-09', 'period_days' => 7, 'horizon_step' => 2,
                'yhat' => 556.5556, 'lo_80' => 500, 'hi_80' => 600, 'lo_95' => 450, 'hi_95' => 650];
            $rows[] = $base + ['node_key' => "product:{$productId}", 'level' => 'product', 'product_id' => $productId, 'granularity' => 'month', 'target_date' => '2026-11-01', 'period_days' => 30, 'horizon_step' => 1,
                'yhat' => 444.4444, 'lo_80' => 400, 'hi_80' => 500, 'lo_95' => 350, 'hi_95' => 550];
        }
        $rows[] = $base + ['node_key' => 'total', 'level' => 'total', 'granularity' => 'day', 'target_date' => '2026-11-01', 'period_days' => 1, 'horizon_step' => 1, 'yhat' => 4242.4242, 'lo_80' => 4000, 'hi_80' => 4400];
        DB::table('forecast_values')->insert(array_map(fn (array $r) => $r + ['branch_id' => null, 'product_id' => null, 'category_id' => null, 'lo_80' => null, 'hi_80' => null, 'lo_95' => null, 'hi_95' => null], $rows));

        return $runId;
    }

    /** @return array<string, mixed> */
    private function grantPayload(array $override = []): array
    {
        return array_merge([
            'recipient_tenant_uuid' => $this->recipient->uuid, 'scope' => 'product', 'subjects' => [$this->catalog['products']['p1']],
            'period_from' => '2026-11-01', 'period_to' => '2026-11-05', 'granularity' => 'day', 'allowed_content' => ['point_forecast', 'interval_80'],
            'expires_at' => '2026-12-31T23:59:00-05:00', 'purpose' => 'Planificación conjunta de la demanda del distribuidor',
        ], $override);
    }

    /** Crea y activa una concesión del emisor al receptor; devuelve su id. */
    private function activeGrant(array $override = []): int
    {
        $this->enable();
        $id = (int) $this->actingAsTenantUser($this->issuerOwner, $this->issuer)->postJson(self::BASE, $this->grantPayload($override))->assertCreated()->json('data.id');
        $this->postJson(self::BASE."/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active');

        return $id;
    }

    private function asRecipient(): static
    {
        return $this->actingAsTenantUser($this->recipientOwner, $this->recipient);
    }

    private function asThird(): static
    {
        return $this->actingAsTenantUser($this->thirdOwner, $this->third);
    }

    /** @return list<string> acciones de la bitácora en orden */
    private function accessLog(int $grantId): array
    {
        return DB::table('shared_forecast_grant_accesses')->where('grant_id', $grantId)->orderBy('id')->pluck('action')->all();
    }

    // ═════════════ Desactivado por bandera ═════════════

    public function test_the_feature_is_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('intelligence.shared_forecast_grants.enabled'), 'La bandera de concesiones debe ser false por defecto.');
        $this->assertFalse(app(SharedForecastGrantService::class)->enabled());
    }

    public function test_with_the_flag_off_no_route_is_functional_even_for_unauthenticated_callers(): void
    {
        $this->enable(false);
        $routes = [
            ['GET', self::BASE], ['POST', self::BASE], ['GET', self::BASE.'/1'], ['POST', self::BASE.'/1/activate'], ['POST', self::BASE.'/1/revoke'],
            ['GET', self::BASE.'/1/accesses'], ['GET', self::RECEIVED], ['GET', self::RECEIVED.'/1/forecast'],
        ];
        $missing = $this->getJson('/api/v1/intelligence/ruta-que-no-existe')->assertStatus(404)->json();

        // Sin sesión: 404 (no 401), indistinguible de una ruta inexistente.
        foreach ($routes as [$method, $uri]) {
            $this->flushHeaders();
            $this->json($method, $uri, $method === 'POST' ? $this->grantPayload() : [])->assertStatus(404)->assertExactJson($missing);
        }
        // Con sesión de propietario en cualquiera de las empresas: también 404.
        foreach ([[$this->issuerOwner, $this->issuer], [$this->recipientOwner, $this->recipient]] as [$user, $tenant]) {
            $this->actingAsTenantUser($user, $tenant);
            foreach ($routes as [$method, $uri]) {
                $this->json($method, $uri, $method === 'POST' ? $this->grantPayload() : [])->assertStatus(404)->assertExactJson($missing);
            }
        }
        $this->assertSame(0, DB::table('shared_forecast_grants')->count());
        $this->assertSame(0, DB::table('shared_forecast_grant_accesses')->count());
        // Defensa en profundidad: el servicio también se niega a operar.
        $this->expectException(NotFoundHttpException::class);
        app(SharedForecastGrantService::class)->create($this->issuer, $this->issuerOwner, $this->grantPayload());
    }

    /** @return array<string, array{0: mixed, 1: bool}> valor de la bandera => ¿queda encendida? */
    public static function flagValues(): array
    {
        return [
            'true booleano' => [true, true], 'texto true' => ['true', true], 'texto 1' => ['1', true], 'on' => ['on', true], 'yes' => ['yes', true], 'entero 1' => [1, true],
            'false booleano' => [false, false], 'texto false' => ['false', false], 'texto 0' => ['0', false], 'off' => ['off', false], 'no' => ['no', false],
            'vacío' => ['', false], 'null' => [null, false], 'basura' => ['quizá', false], 'enabled' => ['enabled', false], 'entero 2' => [2, false], 'arreglo' => [['true'], false],
        ];
    }

    #[DataProvider('flagValues')]
    public function test_the_flag_is_fail_closed_for_any_value_that_is_not_a_recognizable_true(mixed $value, bool $expected): void
    {
        config(['intelligence.shared_forecast_grants.enabled' => $value]);

        $this->assertSame($expected, SharedForecastGrantService::isEnabled());
        $this->assertSame($expected, app(SharedForecastGrantService::class)->enabled());
        // La misma decisión en la ruta: encendida => la ruta existe (401 sin sesión); apagada => 404.
        $this->flushHeaders();
        $this->getJson(self::RECEIVED)->assertStatus($expected ? 401 : 404);
    }

    /** @return array<string, array{0: mixed, 1: bool}> los valores que pueden venir de una variable de entorno (siempre texto, o ausente) */
    public static function envFlagValues(): array
    {
        return array_filter(self::flagValues(), fn (array $case) => is_string($case[0]) || is_bool($case[0]) || $case[0] === null);
    }

    #[DataProvider('envFlagValues')]
    public function test_the_config_file_parses_the_environment_variable_fail_closed(mixed $value, bool $expected): void
    {
        $name = 'PREDICTILY_SHARED_FORECAST_GRANTS_ENABLED';
        $previous = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        try {
            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
            $config = require config_path('intelligence.php');
        } finally {
            foreach ([[&$_ENV, $previous[0]], [&$_SERVER, $previous[1]]] as [&$bag, $old]) {
                $old === null ? $bag = array_diff_key($bag, [$name => 1]) : $bag[$name] = $old;
            }
        }

        $this->assertSame($expected, $config['shared_forecast_grants']['enabled']);
    }

    public function test_every_http_verb_on_the_grant_urls_is_a_plain_404_with_the_flag_off(): void
    {
        $this->enable(false);
        $unknown = $this->getJson('/api/v1/intelligence/ruta-que-no-existe');
        $unknown->assertStatus(404);
        $uris = [
            '/api/v1/intelligence/shared-forecast-grants', '/api/v1/intelligence/shared-forecast-grants/1', '/api/v1/intelligence/shared-forecast-grants/1/activate',
            '/api/v1/intelligence/shared-forecast-grants/1/revoke', '/api/v1/intelligence/shared-forecast-grants/1/accesses', '/api/v1/intelligence/received-forecast-grants',
            '/api/v1/intelligence/received-forecast-grants/1/forecast',
        ];

        foreach ($uris as $uri) {
            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $method) {
                $response = $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json']);
                $this->assertSame(404, $response->getStatusCode(), "{$method} {$uri} debe ser 404 con la bandera apagada");
                $this->assertNull($response->headers->get('Allow'), "{$method} {$uri} no debe delatar la ruta con la cabecera Allow");
                if ($method !== 'HEAD') {
                    $this->assertSame($unknown->getContent(), $response->getContent(), "{$method} {$uri}: mismo cuerpo que una ruta inexistente");
                }
            }
        }
    }

    public function test_with_the_flag_on_unsupported_verbs_are_405_and_options_lists_the_allowed_ones(): void
    {
        $this->enable();

        $this->flushHeaders();
        // OPTIONS responde sin sesión (preflight): 204 y los verbos de la URI.
        $this->call('OPTIONS', '/api/v1/intelligence/shared-forecast-grants')->assertStatus(204)->assertHeader('Allow', 'GET, HEAD, POST, OPTIONS');
        $this->call('OPTIONS', '/api/v1/intelligence/received-forecast-grants/7/forecast')->assertStatus(204)->assertHeader('Allow', 'GET, HEAD, OPTIONS');
        $this->call('OPTIONS', '/api/v1/intelligence/shared-forecast-grants/7/activate')->assertStatus(204)->assertHeader('Allow', 'POST, OPTIONS');
        // Un verbo no registrado: 405 estándar (la función está encendida, la ruta existe).
        $this->actingAsTenantUser($this->issuerOwner, $this->issuer);
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, '/api/v1/intelligence/shared-forecast-grants')->assertStatus(405);
        }
        $this->json('GET', '/api/v1/intelligence/shared-forecast-grants/1/activate')->assertStatus(405);
    }

    public function test_an_existing_grant_stops_being_readable_if_the_feature_is_turned_off_again(): void
    {
        $id = $this->activeGrant();
        $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();

        $this->enable(false);

        $this->getJson(self::RECEIVED."/{$id}/forecast")->assertStatus(404);
        $this->assertSame(['read'], $this->accessLog($id), 'Con la función apagada ni siquiera se intenta leer.');
    }

    // ═════════════ Reglas del contrato (emisor) ═════════════

    public function test_issuer_creates_a_draft_with_every_contract_field_and_it_is_audited(): void
    {
        $this->enable();

        $response = $this->actingAsTenantUser($this->issuerOwner, $this->issuer)->postJson(self::BASE, $this->grantPayload())->assertCreated();

        $response->assertJsonPath('data.status', 'draft')->assertJsonPath('data.effective_status', 'draft')->assertJsonPath('data.scope', 'product')
            ->assertJsonPath('data.subjects', [$this->catalog['products']['p1']])->assertJsonPath('data.period_from', '2026-11-01')->assertJsonPath('data.period_to', '2026-11-05')
            ->assertJsonPath('data.granularity', 'day')->assertJsonPath('data.allowed_content', ['point_forecast', 'interval_80'])->assertJsonPath('data.forecast_version', '1')
            ->assertJsonPath('data.recipient.uuid', $this->recipient->uuid)->assertJsonPath('data.purpose', 'Planificación conjunta de la demanda del distribuidor')
            ->assertJsonPath('data.accesses.read', 0);
        $row = DB::table('shared_forecast_grants')->find($response->json('data.id'));
        $this->assertSame($this->issuer->id, $row->issuer_tenant_id);
        $this->assertSame($this->recipient->id, $row->recipient_tenant_id);
        $this->assertSame($this->issuerOwner->id, $row->created_by);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.shared_grant_created')->where('tenant_id', $this->issuer->id)->count());
    }

    public function test_grant_validation_rules(): void
    {
        $this->enable();
        $this->actingAsTenantUser($this->issuerOwner, $this->issuer);
        $foreignProduct = $this->insertProduct($this->recipient, 'AJENO-1');
        $inactive = Tenant::query()->create(['name' => 'Empresa suspendida', 'sector' => 'ferreteria', 'status' => 'suspended']);

        // Emisor ≠ receptor.
        $this->postJson(self::BASE, $this->grantPayload(['recipient_tenant_uuid' => $this->issuer->uuid]))->assertStatus(422)->assertJsonPath('code', 'recipient_must_differ');
        $this->postJson(self::BASE, $this->grantPayload(['recipient_tenant_uuid' => (string) Str::uuid()]))->assertStatus(422)->assertJsonPath('code', 'recipient_not_found');
        $this->postJson(self::BASE, $this->grantPayload(['recipient_tenant_uuid' => $inactive->uuid]))->assertStatus(422)->assertJsonPath('code', 'recipient_not_found');
        // Solo se conceden productos o categorías PROPIOS del emisor.
        $this->postJson(self::BASE, $this->grantPayload(['subjects' => [$foreignProduct]]))->assertStatus(422)->assertJsonPath('code', 'subjects_not_found');
        $this->postJson(self::BASE, $this->grantPayload(['scope' => 'category', 'subjects' => [$this->catalog['products']['p1']]]))->assertStatus(422)->assertJsonPath('code', 'subjects_not_found');
        $this->postJson(self::BASE, $this->grantPayload(['subjects' => []]))->assertStatus(422);
        // Contenido: nunca ventas ni clientes (ni nada fuera de la lista concedible).
        foreach (['sales', 'customers', 'revenue', 'unit_price', 'cost'] as $forbidden) {
            $this->postJson(self::BASE, $this->grantPayload(['allowed_content' => ['point_forecast', $forbidden]]))->assertStatus(422)->assertJsonPath('code', 'content_not_allowed');
        }
        // Periodo, granularidad y vencimiento.
        $this->postJson(self::BASE, $this->grantPayload(['period_from' => '2026-11-10', 'period_to' => '2026-11-01']))->assertStatus(422);
        $this->postJson(self::BASE, $this->grantPayload(['granularity' => 'hour']))->assertStatus(422);
        $this->postJson(self::BASE, $this->grantPayload(['expires_at' => '2026-10-01T00:00:00-05:00']))->assertStatus(422)->assertJsonPath('code', 'expires_in_the_past');
        $this->postJson(self::BASE, $this->grantPayload(['purpose' => 'x']))->assertStatus(422);
        $this->postJson(self::BASE, $this->grantPayload(['forecast_version' => 99]))->assertStatus(422)->assertJsonPath('code', 'forecast_version_not_found');
        $this->assertSame(0, DB::table('shared_forecast_grants')->count());
    }

    public function test_the_database_enforces_distinct_parties_and_valid_period_and_expiry(): void
    {
        $base = [
            'issuer_tenant_id' => $this->issuer->id, 'recipient_tenant_id' => $this->recipient->id, 'scope' => 'product', 'subjects' => '[]', 'period_from' => '2026-11-01',
            'period_to' => '2026-11-05', 'granularity' => 'day', 'allowed_content' => '[]', 'issued_at' => '2026-10-29 10:00:00', 'expires_at' => '2026-12-31 10:00:00',
            'purpose' => 'Prueba de restricciones', 'created_at' => now(), 'updated_at' => now(),
        ];
        foreach ([
            'mismo emisor y receptor' => ['recipient_tenant_id' => $this->issuer->id],
            'periodo invertido' => ['period_from' => '2026-11-10', 'period_to' => '2026-11-01'],
            'vence antes de emitirse' => ['expires_at' => '2026-10-01 10:00:00'],
        ] as $case => $override) {
            try {
                DB::transaction(fn () => DB::table('shared_forecast_grants')->insert($override + $base));
                $this->fail("La BD debe rechazar: {$case}.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, DB::table('shared_forecast_grants')->count());
    }

    public function test_only_owner_or_admin_manage_grants_and_a_viewer_of_the_issuer_cannot(): void
    {
        $this->enable();
        $analyst = $this->addUserToTenant($this->issuer, TenantRole::Analyst);
        $viewer = $this->addUserToTenant($this->issuer, TenantRole::Viewer);
        $admin = $this->addUserToTenant($this->issuer, TenantRole::Admin);

        foreach ([$analyst, $viewer] as $user) {
            $this->actingAsTenantUser($user, $this->issuer)->postJson(self::BASE, $this->grantPayload())->assertStatus(403)->assertJsonPath('required_ability', 'settings.manage');
            $this->getJson(self::BASE)->assertStatus(403);
        }
        $id = (int) $this->actingAsTenantUser($admin, $this->issuer)->postJson(self::BASE, $this->grantPayload())->assertCreated()->json('data.id');
        $this->actingAsTenantUser($viewer, $this->issuer)->postJson(self::BASE."/{$id}/activate")->assertStatus(403);
        $this->postJson(self::BASE."/{$id}/revoke")->assertStatus(403);
    }

    public function test_activation_requires_a_completed_forecast_and_a_draft(): void
    {
        $this->enable();
        DB::table('forecast_values')->where('tenant_id', $this->issuer->id)->delete();
        DB::table('forecast_runs')->where('tenant_id', $this->issuer->id)->delete();
        $this->actingAsTenantUser($this->issuerOwner, $this->issuer);
        $id = (int) $this->postJson(self::BASE, $this->grantPayload())->assertCreated()->assertJsonPath('data.forecast_version', null)->json('data.id');

        $this->postJson(self::BASE."/{$id}/activate")->assertStatus(422)->assertJsonPath('code', 'no_forecast_to_share');

        $this->seedIssuerForecast();
        $this->postJson(self::BASE."/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.forecast_version', '1');
        $this->postJson(self::BASE."/{$id}/activate")->assertStatus(409)->assertJsonPath('code', 'invalid_grant_state');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.shared_grant_activated')->count());
    }

    // ═════════════ Lectura del receptor: alcance, contenido y bitácora ═════════════

    public function test_the_recipient_reads_exactly_the_granted_scope_and_nothing_else(): void
    {
        $id = $this->activeGrant();

        $response = $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();

        $data = $response->json('data');
        // Solo el producto concedido (p1), solo los días del periodo y solo el contenido permitido.
        $this->assertCount(5, $data);
        $this->assertSame(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'], array_column($data, 'period_start'));
        foreach ($data as $i => $row) {
            $this->assertSame($this->catalog['products']['p1'], $row['subject']['id']);
            $this->assertSame('CEM-01', $row['subject']['sku']);
            $this->assertSame('shared_projection', $row['kind']);
            $this->assertEquals(11 + $i, $row['point_forecast']);
            $this->assertEquals(9 + $i, $row['interval_80']['lo']);
            $this->assertArrayNotHasKey('interval_95', $row, 'interval_95 no se concedió.');
            $this->assertEqualsCanonicalizing(['kind', 'subject', 'period_start', 'period_days', 'point_forecast', 'interval_80'], array_keys($row));
        }
        $response->assertJsonPath('meta.granularity', 'day')->assertJsonPath('meta.forecast_version', '1')->assertJsonPath('grant.status', 'active')->assertJsonPath('grant.subjects_count', 1)
            ->assertJsonPath('grant.issuer.uuid', $this->issuer->uuid);
        // La concesión que ve el receptor no incluye los identificadores internos del emisor.
        $this->assertArrayNotHasKey('subjects', $response->json('grant'));
        $this->assertArrayNotHasKey('issuer_tenant_id', $response->json('grant'));
        $this->assertSame(['read'], $this->accessLog($id));
        $this->assertSame($this->recipient->id, (int) DB::table('shared_forecast_grant_accesses')->where('grant_id', $id)->value('accessed_by_tenant_id'));
        $this->assertSame($this->recipientOwner->id, (int) DB::table('shared_forecast_grant_accesses')->where('grant_id', $id)->value('user_id'));
    }

    public function test_the_recipient_never_receives_sales_customers_prices_revenue_or_other_issuer_data(): void
    {
        $id = $this->activeGrant(['allowed_content' => ['point_forecast', 'interval_80', 'interval_95']]);

        $body = $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk()->getContent();
        $list = $this->getJson(self::RECEIVED)->assertOk()->getContent();

        foreach ([$body, $list] as $json) {
            foreach (['CLIENTE-SECRETO-A', 'FAC-SECRETA-A', 'modelo_secreto_a', 'revenue', 'unit_price', 'unit_cost', 'current_cost', 'current_price', 'customer', 'sale_', 'branch', 'Matriz', '999.99', '8.5', '4242.42', '777.77', '555.55', '556.55', '444.44'] as $secret) {
                $this->assertStringNotContainsStringIgnoringCase($secret, $json, "El receptor no debe recibir «{$secret}».");
            }
        }
        // Tampoco el producto que no se concedió (p2: CEM... VAR-12).
        $this->assertStringNotContainsString('VAR-12', $body);
        $this->assertStringNotContainsString('Varilla', $body);
        // Y el receptor no obtiene nada de las ventas del emisor por ninguna otra ruta (pertenecen a la empresa del emisor).
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_requests_outside_the_granted_scope_are_denied_and_logged(): void
    {
        $id = $this->activeGrant();
        $p2 = $this->catalog['products']['p2'];
        $this->asRecipient();

        // Otro producto del emisor (no concedido).
        $this->getJson(self::RECEIVED."/{$id}/forecast?subject_id={$p2}")->assertStatus(403)->assertJsonPath('code', 'outside_grant_scope')->assertJsonPath('context.outside', ['subject']);
        // Fechas fuera del periodo concedido (antes, después) y mezcladas.
        $this->getJson(self::RECEIVED."/{$id}/forecast?from=2026-10-25&to=2026-11-03")->assertStatus(403)->assertJsonPath('context.outside', ['period']);
        $this->getJson(self::RECEIVED."/{$id}/forecast?from=2026-11-03&to=2026-11-09")->assertStatus(403)->assertJsonPath('context.outside', ['period']);
        // Otra granularidad.
        $this->getJson(self::RECEIVED."/{$id}/forecast?granularity=week")->assertStatus(403)->assertJsonPath('context.outside', ['granularity']);
        // Contenido que no se concedió o que nunca se concede.
        $this->getJson(self::RECEIVED."/{$id}/forecast?include[]=interval_95")->assertStatus(403)->assertJsonPath('context.outside', ['content']);
        $this->getJson(self::RECEIVED."/{$id}/forecast?include[]=sales")->assertStatus(403)->assertJsonPath('context.outside', ['content']);
        $this->getJson(self::RECEIVED."/{$id}/forecast?include[]=customers")->assertStatus(403);
        // Todo junto.
        $this->getJson(self::RECEIVED."/{$id}/forecast?subject_id={$p2}&from=2026-01-01&granularity=month&include[]=sales")->assertStatus(403)
            ->assertJsonPath('context.outside', ['subject', 'period', 'granularity', 'content']);

        $this->assertSame(array_fill(0, 8, 'denied_scope'), $this->accessLog($id), 'Cada intento fuera de alcance queda registrado.');
        // Dentro del alcance (subconjunto exacto) sí se puede, incluidos los extremos del periodo.
        $this->getJson(self::RECEIVED."/{$id}/forecast?from=2026-11-05&to=2026-11-05&subject_id={$this->catalog['products']['p1']}&include[]=point_forecast")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('read', last($this->accessLog($id)));
    }

    /** @return list<array<string, mixed>> filas del pronóstico que lee el receptor de una concesión semanal o mensual */
    private function periodRows(string $granularity, string $from, string $to, ?int $product = null): array
    {
        $id = $this->activeGrant(['granularity' => $granularity, 'period_from' => $from, 'period_to' => $to, 'allowed_content' => ['point_forecast'], 'subjects' => [$product ?? $this->catalog['products']['p1']]]);

        return $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk()->json('data');
    }

    public function test_weekly_and_monthly_periods_are_served_only_when_fully_inside_the_granted_period(): void
    {
        // Semana lun 2-nov … dom 8-nov y lun 9-nov … dom 15-nov; mes 1-nov … 30-nov (30 días).
        // Contenida exactamente: se sirve.
        $this->assertSame(['2026-11-02'], array_column($this->periodRows('week', '2026-11-02', '2026-11-08'), 'period_start'));
        $this->assertSame(['2026-11-02', '2026-11-09'], array_column($this->periodRows('week', '2026-11-02', '2026-11-15'), 'period_start'));
        // Solape parcial por el EXTREMO FINAL (la semana empieza dentro pero termina el 8, después del 5): no se sirve.
        $this->assertSame([], $this->periodRows('week', '2026-11-02', '2026-11-05'));
        $this->assertSame([], $this->periodRows('week', '2026-11-02', '2026-11-07'), 'Falta un día: la semana no está contenida.');
        // Solape parcial por el EXTREMO INICIAL (la semana del 2 empieza antes del 3): no se sirve; la del 9 termina el 15, después del 9.
        $this->assertSame([], $this->periodRows('week', '2026-11-03', '2026-11-09'));
        // Periodo concedido que corta ambas semanas: ninguna.
        $this->assertSame(['2026-11-09'], array_column($this->periodRows('week', '2026-11-03', '2026-11-15'), 'period_start'), 'Solo la semana del 9 está entera; la del 2 empieza antes.');
        // Mes (30 días: 1 → 30 nov).
        $this->assertSame(['2026-11-01'], array_column($this->periodRows('month', '2026-11-01', '2026-11-30'), 'period_start'));
        $this->assertSame([], $this->periodRows('month', '2026-11-01', '2026-11-29'), 'El mes termina el 30: el 29 lo deja fuera.');
        $this->assertSame([], $this->periodRows('month', '2026-11-02', '2026-11-30'), 'Empieza antes del periodo concedido.');
        $this->assertSame([], $this->periodRows('month', '2026-11-01', '2026-11-03'), 'Un periodo de 3 días no concede un mes entero.');
        $this->assertSame(['2026-11-01'], array_column($this->periodRows('month', '2026-10-15', '2026-12-15'), 'period_start'), 'Un periodo mayor que el mes lo contiene.');
        // Y el valor semanal nunca incluye días no concedidos: ningún dato fuera del periodo.
        $this->assertStringNotContainsString('555.55', json_encode($this->periodRows('week', '2026-11-03', '2026-11-15')));
    }

    public function test_the_requested_window_is_also_applied_to_whole_periods(): void
    {
        $id = $this->activeGrant(['granularity' => 'week', 'period_from' => '2026-11-02', 'period_to' => '2026-11-15', 'allowed_content' => ['point_forecast']]);
        $this->asRecipient();

        // Pedir una ventana dentro de lo concedido que corta una semana: esa semana no se sirve.
        $this->assertSame(['2026-11-09'], array_column($this->getJson(self::RECEIVED."/{$id}/forecast?from=2026-11-04&to=2026-11-15")->assertOk()->json('data'), 'period_start'));
        $this->assertSame([], $this->getJson(self::RECEIVED."/{$id}/forecast?from=2026-11-02&to=2026-11-06")->assertOk()->json('data'));
        $this->assertSame(['2026-11-02'], array_column($this->getJson(self::RECEIVED."/{$id}/forecast?from=2026-11-02&to=2026-11-08")->assertOk()->json('data'), 'period_start'));
        $this->getJson(self::RECEIVED."/{$id}/forecast")->assertOk()->assertJsonPath('meta.period_rule', fn ($v) => str_contains($v, 'totalmente contenidos'));
    }

    public function test_an_expired_grant_denies_the_recipient_and_logs_it(): void
    {
        $id = $this->activeGrant(['expires_at' => '2026-11-10T00:00:00-05:00']);
        $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();

        // Vencida pero SIN que el proceso programado haya corrido: se deniega por el estado efectivo.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 00:00:01', 'America/Guayaquil'));
        $this->getJson(self::RECEIVED."/{$id}/forecast")->assertStatus(403)->assertJsonPath('code', 'grant_expired');
        $this->getJson(self::RECEIVED)->assertOk()->assertJsonPath('data.0.status', 'expired');
        $this->assertSame('active', DB::table('shared_forecast_grants')->where('id', $id)->value('status'));
        $this->assertSame(['read', 'denied_expired'], $this->accessLog($id));

        // El proceso lo persiste (idempotente) y sigue denegando.
        $this->artisan('predictily:intelligence-expire')->assertExitCode(0);
        $this->assertSame('expired', DB::table('shared_forecast_grants')->where('id', $id)->value('status'));
        $this->getJson(self::RECEIVED."/{$id}/forecast")->assertStatus(403)->assertJsonPath('code', 'grant_expired');
        $this->assertSame(['read', 'denied_expired', 'denied_expired'], $this->accessLog($id));
        // El emisor ya no puede revivirla ni activarla.
        $this->actingAsTenantUser($this->issuerOwner, $this->issuer)->postJson(self::BASE."/{$id}/activate")->assertStatus(409);
        $this->postJson(self::BASE."/{$id}/revoke")->assertStatus(409);
    }

    public function test_a_revoked_grant_denies_the_recipient_immediately_and_logs_it(): void
    {
        $id = $this->activeGrant();
        $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();

        $revoked = $this->actingAsTenantUser($this->issuerOwner, $this->issuer)->postJson(self::BASE."/{$id}/revoke")->assertOk();
        $revoked->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.effective_status', 'revoked');
        $this->assertSame($this->issuerOwner->id, (int) DB::table('shared_forecast_grants')->where('id', $id)->value('revoked_by'));
        $this->assertNotNull(DB::table('shared_forecast_grants')->where('id', $id)->value('revoked_at'));

        $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertStatus(403)->assertJsonPath('code', 'grant_revoked');
        $this->getJson(self::RECEIVED."/{$id}/forecast?subject_id={$this->catalog['products']['p2']}")->assertStatus(403)->assertJsonPath('code', 'grant_revoked');
        $this->getJson(self::RECEIVED)->assertOk()->assertJsonPath('data.0.status', 'revoked');
        $this->assertSame(['read', 'denied_revoked', 'denied_revoked'], $this->accessLog($id));
        $this->actingAsTenantUser($this->issuerOwner, $this->issuer)->postJson(self::BASE."/{$id}/revoke")->assertStatus(409);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.shared_grant_revoked')->count());
    }

    public function test_a_grant_is_not_served_when_the_issuer_company_is_no_longer_active(): void
    {
        $id = $this->activeGrant();
        $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();

        foreach (['suspended', 'pending_deletion'] as $status) {
            DB::table('tenants')->where('id', $this->issuer->id)->update(['status' => $status]);
            $this->getJson(self::RECEIVED."/{$id}/forecast")->assertStatus(403)->assertJsonPath('code', 'issuer_unavailable');
        }
        $this->assertSame(['read', 'denied_scope', 'denied_scope'], $this->accessLog($id));
        DB::table('tenants')->where('id', $this->issuer->id)->update(['status' => 'active']);
        $this->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();
    }

    public function test_a_draft_is_invisible_to_the_recipient(): void
    {
        $this->enable();
        $id = (int) $this->actingAsTenantUser($this->issuerOwner, $this->issuer)->postJson(self::BASE, $this->grantPayload())->assertCreated()->json('data.id');

        $this->asRecipient()->getJson(self::RECEIVED)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::RECEIVED."/{$id}/forecast")->assertStatus(404);
        $this->assertSame(['denied_scope'], $this->accessLog($id));
    }

    // ═════════════ Tercero e inversión de roles ═════════════

    public function test_a_third_company_can_never_read_or_manage_a_grant_and_the_attempt_is_logged(): void
    {
        $id = $this->activeGrant();
        $missing = $this->asThird()->getJson(self::RECEIVED.'/987654/forecast')->assertStatus(404)->json();

        // Leer: 404 idéntico al de una concesión que no existe (no revela que existe).
        $this->getJson(self::RECEIVED."/{$id}/forecast")->assertStatus(404)->assertExactJson($missing);
        $this->getJson(self::RECEIVED."/{$id}/forecast?subject_id={$this->catalog['products']['p1']}")->assertStatus(404);
        // No la ve en listados ni por la vía de administración.
        $this->getJson(self::RECEIVED)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE."/{$id}")->assertStatus(404);
        $this->getJson(self::BASE."/{$id}/accesses")->assertStatus(404);
        $this->postJson(self::BASE."/{$id}/activate")->assertStatus(404);
        $this->postJson(self::BASE."/{$id}/revoke")->assertStatus(404);
        $this->assertSame('active', DB::table('shared_forecast_grants')->where('id', $id)->value('status'), 'El tercero no pudo revocarla.');

        // Los intentos de lectura quedan en la bitácora con la empresa que los hizo, y en la auditoría de plataforma.
        $this->assertSame(['denied_scope', 'denied_scope'], $this->accessLog($id));
        $this->assertSame([$this->third->id, $this->third->id], DB::table('shared_forecast_grant_accesses')->where('grant_id', $id)->pluck('accessed_by_tenant_id')->map(fn ($v) => (int) $v)->all());
        $this->assertGreaterThanOrEqual(3, DB::table('audit_logs')->where('action', 'intelligence.shared_grant_denied')->where('tenant_id', $this->third->id)->count());

        // El emisor ve el intento sin conocer la identidad del tercero.
        $log = $this->actingAsTenantUser($this->issuerOwner, $this->issuer)->getJson(self::BASE."/{$id}/accesses")->assertOk();
        $log->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.actor', 'other_company')->assertJsonPath('data.0.action', 'denied_scope');
        $this->assertStringNotContainsString((string) $this->third->uuid, $log->getContent());
        $this->assertArrayNotHasKey('accessed_by_tenant_id', $log->json('data.0'));
        $this->assertArrayNotHasKey('user_id', $log->json('data.0'));
        $this->getJson(self::BASE."/{$id}")->assertOk()->assertJsonPath('data.accesses.denied', 2);
    }

    public function test_the_recipient_cannot_manage_the_grant_it_received_and_the_issuer_cannot_read_through_the_recipient_path(): void
    {
        $id = $this->activeGrant();

        // El receptor (parte del contrato) tampoco puede administrarlo: ni verlo como emisor, ni activarlo, revocarlo o ver la bitácora del emisor.
        $this->asRecipient()->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE."/{$id}")->assertStatus(404);
        $this->getJson(self::BASE."/{$id}/accesses")->assertStatus(404);
        $this->postJson(self::BASE."/{$id}/revoke")->assertStatus(404);
        $this->postJson(self::BASE."/{$id}/activate")->assertStatus(404);
        $this->assertSame('active', DB::table('shared_forecast_grants')->where('id', $id)->value('status'));
        // El emisor no lee por la vía del receptor (404) y el intento se registra.
        $this->actingAsTenantUser($this->issuerOwner, $this->issuer)->getJson(self::RECEIVED."/{$id}/forecast")->assertStatus(404);
        $this->assertSame(['denied_scope'], $this->accessLog($id));
        $this->getJson(self::RECEIVED)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_viewers_of_the_recipient_can_read_but_not_manage_and_unauthenticated_calls_are_rejected(): void
    {
        $this->enable();
        $this->getJson(self::RECEIVED.'/1/forecast')->assertStatus(401);
        $this->getJson(self::BASE)->assertStatus(401);
        $id = $this->activeGrant();
        $viewer = $this->addUserToTenant($this->recipient, TenantRole::Viewer);

        $this->actingAsTenantUser($viewer, $this->recipient)->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();
        $this->getJson(self::BASE)->assertStatus(403);
        $this->assertSame($viewer->id, (int) DB::table('shared_forecast_grant_accesses')->where('grant_id', $id)->value('user_id'));
    }

    // ═════════════ Aislamiento a nivel de modelo y bitácora ═════════════

    public function test_models_only_expose_a_grant_to_its_two_parties_and_fail_closed_without_a_company(): void
    {
        $id = $this->activeGrant();
        $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();
        $this->asThird()->getJson(self::RECEIVED."/{$id}/forecast")->assertStatus(404);

        $this->assertSame(1, $this->withinTenant($this->issuer, fn () => SharedForecastGrant::query()->count()));
        $this->assertSame(1, $this->withinTenant($this->recipient, fn () => SharedForecastGrant::query()->count()));
        $this->assertSame(0, $this->withinTenant($this->third, fn () => SharedForecastGrant::query()->count()), 'Un tercero nunca ve la concesión.');
        $this->assertNull($this->withinTenant($this->third, fn () => SharedForecastGrant::query()->find($id)));
        // La bitácora: el emisor ve todo lo de sus concesiones; el receptor, solo lo suyo; el tercero, solo sus propios intentos denegados.
        $this->assertSame(2, $this->withinTenant($this->issuer, fn () => SharedForecastGrantAccess::query()->count()));
        $this->assertSame(1, $this->withinTenant($this->recipient, fn () => SharedForecastGrantAccess::query()->count()));
        $this->assertSame(1, $this->withinTenant($this->third, fn () => SharedForecastGrantAccess::query()->count()));
        $this->assertSame(0, $this->withinTenant($this->third, fn () => SharedForecastGrantAccess::query()->where('action', 'read')->count()));
        try {
            SharedForecastGrant::query()->count();
            $this->fail('Sin empresa en contexto la consulta debe fallar (fail-closed).');
        } catch (TenantNotResolvedException) {
            $this->assertTrue(true);
        }
        $this->expectException(TenantNotResolvedException::class);
        SharedForecastGrantAccess::query()->count();
    }

    public function test_the_access_log_is_append_only(): void
    {
        $id = $this->activeGrant();
        $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();

        $access = $this->withinTenant($this->recipient, fn () => SharedForecastGrantAccess::query()->firstOrFail());
        try {
            $access->update(['action' => 'denied_scope']);
            $this->fail('La bitácora no se modifica.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(LogicException::class);
        $access->delete();
    }

    public function test_the_forecast_version_is_pinned_when_the_grant_is_activated(): void
    {
        $id = $this->activeGrant();
        // El emisor vuelve a pronosticar (versión 2, vigente) con valores distintos: la concesión sigue sirviendo la versión 1 concedida.
        $this->seedIssuerForecast(2);

        $row = $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk()->json('data.0');

        $this->assertEquals(11, $row['point_forecast']);
        $this->assertSame('1', DB::table('shared_forecast_grants')->where('id', $id)->value('forecast_version'));
    }

    public function test_a_category_scope_grant_serves_only_category_level_rows_of_the_granted_categories(): void
    {
        $category = $this->insertCategory($this->issuer, 'Cementos');
        $other = $this->insertCategory($this->issuer, 'Hierros CONFIDENCIAL');
        $runId = (int) DB::table('forecast_runs')->where('tenant_id', $this->issuer->id)->value('id');
        foreach ([[$category, 301], [$other, 902]] as [$cat, $y]) {
            DB::table('forecast_values')->insert([
                'tenant_id' => $this->issuer->id, 'forecast_run_id' => $runId, 'node_key' => "category:{$cat}", 'level' => 'category', 'category_id' => $cat, 'granularity' => 'day',
                'target_date' => '2026-11-02', 'period_days' => 1, 'horizon_step' => 2, 'yhat' => $y, 'lo_80' => $y - 1, 'hi_80' => $y + 1,
            ]);
        }
        $id = $this->activeGrant(['scope' => 'category', 'subjects' => [$category], 'allowed_content' => ['point_forecast']]);

        $response = $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject.type', 'category')->assertJsonPath('data.0.subject.name', 'Cementos')->assertJsonPath('data.0.point_forecast', 301);
        $this->assertStringNotContainsString('CONFIDENCIAL', $response->getContent());
        $this->assertArrayNotHasKey('interval_80', $response->json('data.0'));
        $this->getJson(self::RECEIVED."/{$id}/forecast?subject_id={$other}")->assertStatus(403);
    }

    // ═════════════ Un pronóstico compartido NO es un pedido confirmado ═════════════

    public function test_a_shared_forecast_is_not_a_confirmed_order_and_writes_nothing_in_the_recipient(): void
    {
        $id = $this->activeGrant();
        $tables = ['commercial_commitments', 'commercial_commitment_items', 'commercial_commitment_revisions', 'sales', 'sale_items', 'forecast_runs', 'forecast_values', 'customer_predictions', 'recommendations'];
        $before = array_map(fn (string $t) => DB::table($t)->where('tenant_id', $this->recipient->id)->count(), $tables);

        $response = $this->asRecipient()->getJson(self::RECEIVED."/{$id}/forecast")->assertOk();

        // El contrato lo declara en la propia respuesta…
        $response->assertJsonPath('meta.is_confirmed_order', false)->assertJsonPath('meta.counts_as_committed_demand', false)->assertJsonPath('meta.kind', 'shared_projection');
        $this->assertStringContainsString('NO es un pedido confirmado', (string) $response->json('meta.notice'));
        foreach ($response->json('data') as $row) {
            $this->assertSame('shared_projection', $row['kind']);
            $this->assertArrayNotHasKey('committed_quantity', $row);
            $this->assertArrayNotHasKey('quantity', $row);
        }
        // … y no escribe nada en las tablas del receptor: ni compromisos, ni ventas, ni pronósticos propios.
        $after = array_map(fn (string $t) => DB::table($t)->where('tenant_id', $this->recipient->id)->count(), $tables);
        $this->assertSame($before, $after);
        $this->getJson('/api/v1/commitments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_no_demand_or_commitment_code_reads_the_shared_forecast_tables(): void
    {
        // La suma doble se evita por construcción: nada de compromisos, Customer, Supply ni Demand referencia las concesiones.
        $roots = ['app/Services/Customer', 'app/Services/Supply', 'app/Services/DataCore/Commitments', 'app/Services/Forecasting', 'app/Services/Analytics', 'app/Models/CommercialCommitment.php',
            'app/Models/CommercialCommitmentItem.php', 'app/Models/CommercialCommitmentRevision.php', 'app/Http/Controllers/Api/DataCore/CommitmentController.php'];
        $offenders = [];
        foreach ($roots as $relative) {
            $path = base_path($relative);
            $files = is_dir($path) ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))) : [new \SplFileInfo($path)];
            foreach ($files as $file) {
                if ($file->isFile() && preg_match('/shared_forecast|SharedForecast/', (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = $relative.'/'.$file->getFilename();
                }
            }
        }
        $this->assertSame([], $offenders, 'Un pronóstico compartido no puede alimentar compromisos ni los cores: se evita la doble suma.');
    }
}
