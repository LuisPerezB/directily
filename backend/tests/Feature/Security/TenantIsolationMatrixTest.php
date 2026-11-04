<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\TenantRole;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\BronzeObject;
use App\Models\Product;
use App\Services\Conversation\AssistantLimiter;
use App\Services\Conversation\Exceptions\AssistantRateLimitedException;
use App\Services\Conversation\Tools\ToolContext;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Services\Plans\PlanLimitService;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Models\Support\ModelRegistryFixtures;
use Tests\TestCase;
use ZipArchive;

/** Trabajo de prueba CORRECTO: transporta la empresa y restablece el contexto con el middleware de cola. Anota qué productos vio. */
final class IsolationProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var array<int, list<string>> */
    public static array $seen = [];

    public function __construct(public int $tenantId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(): void
    {
        self::$seen[$this->tenantId] = Product::query()->orderBy('sku')->pluck('sku')->all();
    }
}

/** Trabajo de prueba INCORRECTO (sin el middleware): no tiene empresa en el contexto y toda consulta falla cerrada. */
final class IsolationUnscopedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tenantId) {}

    public function handle(): void
    {
        Product::query()->count();
    }
}

/** Trabajo de prueba que, dentro de la empresa A, intenta escribir un registro de la empresa B. */
final class IsolationCrossWriteJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tenantId, public int $otherTenantId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(): void
    {
        $product = new Product;
        $product->forceFill(['tenant_id' => $this->otherTenantId, 'sku' => 'CRUZADO', 'name' => 'Cruzado'])->save();
    }
}

/**
 * V2.5 · W6-A (ADR-V25-15: «pruebas negativas entre tenants en API, colas, caché, jobs concurrentes, almacén de objetos, registro de modelos y exportaciones»).
 * Las rutas se enumeran DEL ROUTER REAL: una ruta nueva sin autenticación, sin empresa o sin habilidad rompe esta prueba hasta que se revise. Lo que no se puede probar aquí
 * (URL prefirmada del Bridge, RLS, el almacén S3 real) tiene su prueba en su propio archivo y está nombrado en docs/architecture/TENANT_ISOLATION.md.
 */
final class TenantIsolationMatrixTest extends TestCase
{
    use InteractsWithTenants, ModelRegistryFixtures, RefreshDatabase;

    /** Rutas SIN autenticación de usuario, revisadas una a una (la autenticación del Bridge es por dispositivo: token + firma). */
    private const PUBLIC_ROUTES = [
        'POST api/v1/auth/register', 'POST api/v1/auth/login', 'POST api/v1/auth/mfa/challenge',
        // Con la bandera de concesiones apagada «no existen» (404 antes de autenticar): el verbo OPTIONS queda sin autenticación a propósito.
        'OPTIONS api/v1/intelligence/shared-forecast-grants', 'OPTIONS api/v1/intelligence/shared-forecast-grants/{grantId}', 'OPTIONS api/v1/intelligence/shared-forecast-grants/{grantId}/activate',
        'OPTIONS api/v1/intelligence/shared-forecast-grants/{grantId}/revoke', 'OPTIONS api/v1/intelligence/shared-forecast-grants/{grantId}/accesses',
        'OPTIONS api/v1/intelligence/received-forecast-grants', 'OPTIONS api/v1/intelligence/received-forecast-grants/{grantId}/forecast',
    ];

    /** Rutas de empresa sin habilidad propia: solo lectura del contexto de la persona en SU empresa. */
    private const TENANT_ROUTES_WITHOUT_ABILITY = ['GET api/v1/tenant', 'GET api/v1/tenant/usage'];

    /** Rutas autenticadas SIN empresa activa que no son de `auth/` ni de plataforma (`admin/`): cada una revisada. */
    private const AUTH_ONLY_ROUTES = [
        'GET api/v1/tenants', 'POST api/v1/tenants', 'GET api/v1/import-templates/{entityType}', 'GET api/v1/import-fields/{entityType}', 'GET api/v1/tenant/export/{export}/download',
    ];

    /** Prefijos cuyas rutas se prueban en su propia suite (bandera apagada = 404 antes de autenticar). */
    private const FLAG_GATED = ['api/v1/intelligence/shared-forecast-grants', 'api/v1/intelligence/received-forecast-grants'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
        IsolationProbeJob::$seen = [];
    }

    protected function tearDown(): void
    {
        $this->tearDownRegistry();
        parent::tearDown();
    }

    /** @return list<array{route: Route, key: string, method: string, middleware: list<string>}> */
    private function apiRoutes(): array
    {
        $out = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            $middleware = array_values(array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware()));
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $out[] = ['route' => $route, 'key' => implode('|', $methods).' '.$route->uri(), 'method' => $methods[0], 'middleware' => $middleware];
        }

        return $out;
    }

    /** @param list<string> $middleware */
    private function hasMiddleware(array $middleware, string $prefix): bool
    {
        return array_filter($middleware, fn (string $m): bool => $m === $prefix || str_starts_with($m, $prefix.':')) !== [];
    }

    /** @return list<string> */
    private function abilitiesOf(array $middleware): array
    {
        $abilities = [];
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'tenant.ability:')) {
                array_push($abilities, ...explode(',', substr($m, strlen('tenant.ability:'))));
            }
        }

        return array_values(array_unique($abilities));
    }

    /** URI con un valor que cumple las restricciones de la ruta (`whereNumber`, `whereUuid`…): «1» o un UUID de relleno. */
    private function concrete(Route $route): string
    {
        return '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function (array $m) use ($route): string {
            $regex = (string) ($route->wheres[$m[1]] ?? '');

            return str_contains($regex, '-') && str_contains($regex, '{8}') ? '11111111-1111-4111-8111-111111111111' : '1';
        }, $route->uri());
    }

    private function isFlagGated(string $uri): bool
    {
        foreach (self::FLAG_GATED as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // ── Inventario de rutas (por defecto, denegado) ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_every_route_without_user_authentication_is_a_reviewed_one(): void
    {
        $public = [];
        foreach ($this->apiRoutes() as $r) {
            if (! $this->hasMiddleware($r['middleware'], 'auth')) {
                $public[] = $r['key'];
            }
        }
        sort($public);
        $expected = self::PUBLIC_ROUTES;
        sort($expected);

        $this->assertSame([], array_values(array_diff($public, $expected)), 'Ruta nueva SIN autenticación: revísela y agréguela a PUBLIC_ROUTES solo si lo justifica.');
        $this->assertSame([], array_values(array_diff($expected, $public)), 'Una ruta pública revisada ya no existe o ahora exige autenticación: actualice la lista.');
    }

    public function test_no_route_exists_outside_the_api_except_the_reviewed_framework_ones(): void
    {
        // Una ruta en routes/web.php (o de un paquete) sin autenticación ni empresa quedaría fuera de todo el inventario de arriba: se enumera TODO lo que no es api/v1.
        $reviewed = [
            'GET /' => 'página de bienvenida (sin datos)',
            'GET sanctum/csrf-cookie' => 'cookie CSRF de la SPA (Sanctum)',
            'GET storage/{path}' => 'servidor de archivos locales del framework: solo enlaces firmados y temporales',
            'PUT storage/{path}' => 'ídem (subida con URL temporal firmada)',
            'GET up' => 'salud del servicio (sin datos)',
        ];
        $found = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $found[] = $method.' '.$route->uri();
            }
        }
        sort($found);
        $expected = array_keys($reviewed);
        sort($expected);

        $this->assertSame($expected, $found, 'Ruta nueva fuera de api/v1: revísela (autenticación, empresa, habilidad) y agréguela a esta lista solo si lo justifica.');
    }

    public function test_every_route_with_a_company_declares_an_ability_except_the_reviewed_ones(): void
    {
        $without = [];
        foreach ($this->apiRoutes() as $r) {
            if ($this->hasMiddleware($r['middleware'], 'auth') && $this->hasMiddleware($r['middleware'], 'tenant') && $this->abilitiesOf($r['middleware']) === []) {
                $without[] = $r['key'];
            }
        }
        sort($without);
        $this->assertSame(self::TENANT_ROUTES_WITHOUT_ABILITY, $without, 'Ruta de empresa sin habilidad: agregue tenant.ability:… o justifíquela en la lista.');
    }

    public function test_authenticated_routes_without_a_company_are_auth_platform_or_reviewed(): void
    {
        foreach ($this->apiRoutes() as $r) {
            $m = $r['middleware'];
            if (! $this->hasMiddleware($m, 'auth') || $this->hasMiddleware($m, 'tenant')) {
                continue;
            }
            $uri = substr($r['route']->uri(), strlen('api/v1/'));
            $path = $r['key'];
            if (str_starts_with($uri, 'auth/')) {
                continue;
            }
            if (str_starts_with($uri, 'admin/')) {
                $this->assertTrue($this->hasMiddleware($m, 'superadmin'), "{$path}: las rutas de plataforma exigen superadministrador");

                continue;
            }
            $this->assertContains($path, self::AUTH_ONLY_ROUTES, "{$path}: ruta autenticada sin empresa y sin revisar");
        }
        // Y a la inversa: toda ruta de superadministrador está bajo admin/.
        foreach ($this->apiRoutes() as $r) {
            if ($this->hasMiddleware($r['middleware'], 'superadmin')) {
                $this->assertStringStartsWith('api/v1/admin/', $r['route']->uri());
            }
        }
    }

    public function test_every_ability_a_route_requires_exists_and_the_hierarchy_holds(): void
    {
        $granted = [];
        foreach (TenantRole::cases() as $role) {
            array_push($granted, ...$role->abilities());
        }
        $required = [];
        foreach ($this->apiRoutes() as $r) {
            array_push($required, ...$this->abilitiesOf($r['middleware']));
        }
        $this->assertSame([], array_values(array_diff(array_unique($required), $granted)), 'Habilidad exigida por una ruta que ningún rol tiene (ruta inalcanzable o con errata).');

        // Jerarquía: lo propio de cada nivel no baja al anterior.
        $viewer = TenantRole::Viewer->abilities();
        foreach ($viewer as $ability) {
            $this->assertMatchesRegularExpression('/\.view$/', $ability, 'consulta solo tiene habilidades de lectura');
        }
        foreach (['users.manage', 'settings.manage', 'datasources.manage', 'models.approve', 'decisions.approve', 'branches.manage', 'audit.view'] as $ability) {
            $this->assertFalse(TenantRole::Analyst->can($ability), "analista no tiene {$ability}");
            $this->assertTrue(TenantRole::Admin->can($ability), "administrador sí tiene {$ability}");
        }
        foreach (['tenant.delete', 'tenant.consent', 'owners.manage', 'tenant.export_all'] as $ability) {
            $this->assertFalse(TenantRole::Admin->can($ability), "administrador no tiene {$ability}");
            $this->assertTrue(TenantRole::Owner->can($ability), "propietario sí tiene {$ability}");
        }
    }

    // ── Matriz rutas × empresa × rol (por la API) ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_without_credentials_every_company_route_is_401(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);   // los límites de tasa por persona (compartidos) se prueban en AuthTest; aquí interesa la autorización
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (! $this->hasMiddleware($r['middleware'], 'tenant') || $this->isFlagGated($r['route']->uri())) {
                continue;
            }
            $this->assertSame(401, $this->json($r['method'], $this->concrete($r['route']))->getStatusCode(), $r['key']);
            $checked++;
        }
        $this->assertGreaterThan(200, $checked);
    }

    public function test_a_member_of_company_a_gets_404_on_every_route_when_naming_company_b(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [, $userA] = $this->createTenantWithUser(TenantRole::Owner);
        [$tenantB] = $this->createTenantWithUser(TenantRole::Owner);
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (! $this->hasMiddleware($r['middleware'], 'tenant') || $this->isFlagGated($r['route']->uri())) {
                continue;
            }
            $response = $this->actingAsTenantUser($userA, $tenantB)->json($r['method'], $this->concrete($r['route']));
            $this->assertSame(404, $response->getStatusCode(), $r['key']);
            $this->assertSame('tenant_not_found', $response->json('code'), $r['key']);
            $checked++;
        }
        $this->assertGreaterThan(200, $checked);
    }

    public function test_a_role_without_the_ability_is_forbidden_on_every_company_route_and_nothing_is_written(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$tenant] = $this->createTenantWithUser(TenantRole::Owner);
        $users = [];
        foreach ([TenantRole::Viewer, TenantRole::Analyst, TenantRole::Admin] as $role) {
            $users[$role->value] = $this->addUserToTenant($tenant, $role);
        }
        $tablesBefore = $this->rowCounts();
        $checked = 0;
        $skippedBound = 0;
        foreach ($this->apiRoutes() as $r) {
            $abilities = $this->abilitiesOf($r['middleware']);
            if (! $this->hasMiddleware($r['middleware'], 'tenant') || $abilities === [] || $this->isFlagGated($r['route']->uri())) {
                continue;
            }
            // Con route model binding la empresa y el id se resuelven ANTES de la habilidad (404 si no existe): esa rama la cubren las pruebas de cada recurso.
            if ($r['route']->signatureParameters(['subClass' => Model::class]) !== []) {
                $skippedBound++;

                continue;
            }
            foreach ($users as $roleName => $user) {
                $missing = array_values(array_filter($abilities, fn (string $a): bool => ! TenantRole::from($roleName)->can($a)));
                if ($missing === []) {
                    continue;
                }
                $response = $this->actingAsTenantUser($user, $tenant)->json($r['method'], $this->concrete($r['route']));
                $label = "{$r['key']} como {$roleName}";
                $this->assertSame(403, $response->getStatusCode(), $label);
                $this->assertSame(['forbidden', $missing[0]], [$response->json('code'), $response->json('required_ability')], $label);
                $checked++;
            }
        }
        $this->assertGreaterThan(100, $checked, 'matriz rutas × roles sin la habilidad');
        $this->assertGreaterThan(0, $skippedBound);
        $this->assertSame($tablesBefore, $this->rowCounts(), 'ninguna petición denegada escribió nada');
    }

    public function test_a_role_with_every_ability_is_never_stopped_by_the_ability_check_on_safe_company_routes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        // Hermética: ninguna ruta de lectura habla con el motor real (p. ej. GET /platform/extensions): sin esto el resultado dependería de si el motor está encendido en la máquina
        // (apagado responde 503 `engine_unavailable`, encendido 200). Todo acceso HTTP saliente se simula; uno no simulado fallaría la prueba.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            $abilities = $this->abilitiesOf($r['middleware']);
            if ($r['method'] !== 'GET' || ! $this->hasMiddleware($r['middleware'], 'tenant') || $abilities === [] || $this->isFlagGated($r['route']->uri())
                || $r['route']->signatureParameters(['subClass' => Model::class]) !== []) {
                continue;
            }
            $response = $this->actingAsTenantUser($owner, $tenant)->json('GET', $this->concrete($r['route']));
            $this->assertNotSame(401, $response->getStatusCode(), $r['key']);
            $this->assertLessThan(500, $response->getStatusCode(), $r['key'].': no debe fallar con una empresa vacía');
            if (! $response->baseResponse instanceof StreamedResponse) {
                $this->assertNotSame('forbidden', $response->json('code'), $r['key'].': el propietario tiene todas las habilidades');
                $this->assertNotSame('tenant_not_found', $response->json('code'), $r['key']);
            }
            $checked++;
        }
        $this->assertGreaterThan(60, $checked);
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['products', 'branches', 'sales', 'imports', 'import_jobs', 'data_sources', 'suppliers', 'tenant_users', 'personal_access_tokens', 'decision_events', 'audit_logs'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }
        // El registro de auditoría de las propias peticiones denegadas no es «escritura de negocio»: se excluye del conteo.
        unset($counts['audit_logs']);

        return $counts;
    }

    // ── Colas ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_queued_job_for_company_a_never_reads_company_b(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $this->withinTenant($a, fn () => Product::query()->create(['sku' => 'SKU-A1', 'name' => 'A uno']));
        $this->withinTenant($a, fn () => Product::query()->create(['sku' => 'SKU-A2', 'name' => 'A dos']));
        $this->withinTenant($b, fn () => Product::query()->create(['sku' => 'SKU-B1', 'name' => 'B uno']));

        dispatch(new IsolationProbeJob($a->id));
        dispatch(new IsolationProbeJob($b->id));

        $this->assertSame(['SKU-A1', 'SKU-A2'], IsolationProbeJob::$seen[$a->id]);
        $this->assertSame(['SKU-B1'], IsolationProbeJob::$seen[$b->id]);
    }

    public function test_a_job_restores_the_callers_company_and_never_leaks_its_own(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $this->withinTenant($a, fn () => Product::query()->create(['sku' => 'SKU-A1', 'name' => 'A']));
        $context = app(TenantContext::class);

        // La petición actual es de B; el trabajo de A corre sincrónico DENTRO de ella (QUEUE_CONNECTION=sync): usa A y devuelve B.
        $context->set($b);
        dispatch(new IsolationProbeJob($a->id));
        $this->assertSame($b->id, $context->id(), 'el contexto previo se restablece');
        $this->assertSame(['SKU-A1'], IsolationProbeJob::$seen[$a->id]);
        $this->assertSame(0, Product::query()->count(), 'B no ve lo de A después del trabajo');
        $context->forget();
    }

    public function test_nested_and_interleaved_company_contexts_never_mix(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $this->withinTenant($a, fn () => Product::query()->create(['sku' => 'SKU-A1', 'name' => 'A']));
        $this->withinTenant($b, fn () => Product::query()->create(['sku' => 'SKU-B1', 'name' => 'B']));
        $context = app(TenantContext::class);

        $trace = $context->runAs($a, function () use ($context, $b): array {
            $outerBefore = Product::query()->pluck('sku')->all();
            $inner = $context->runAs($b, fn () => Product::query()->pluck('sku')->all());
            $outerAfter = Product::query()->pluck('sku')->all();

            return [$outerBefore, $inner, $outerAfter, $context->id()];
        });

        $this->assertSame([['SKU-A1'], ['SKU-B1'], ['SKU-A1'], $a->id], $trace);
        $this->assertFalse($context->has(), 'al terminar no queda ninguna empresa');
    }

    public function test_a_job_without_the_company_middleware_fails_closed_and_a_forged_company_is_not_found(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $this->withinTenant($b, fn () => Product::query()->create(['sku' => 'SKU-B1', 'name' => 'B']));

        try {
            dispatch(new IsolationUnscopedJob($a->id));
            $this->fail('Un trabajo sin SetTenantContext no debe poder consultar datos de empresa.');
        } catch (TenantNotResolvedException) {
            $this->addToAssertionCount(1);
        }
        try {
            dispatch(new IsolationProbeJob(987654321));
            $this->fail('Una empresa inexistente no debe resolverse.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->assertArrayNotHasKey(987654321, IsolationProbeJob::$seen);
    }

    public function test_a_job_of_company_a_cannot_write_a_record_of_company_b(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();

        $this->expectException(TenantNotResolvedException::class);
        try {
            dispatch(new IsolationCrossWriteJob($a->id, $b->id));
        } finally {
            $this->assertSame(0, DB::table('products')->where('sku', 'CRUZADO')->count());
        }
    }

    public function test_every_queued_job_of_the_application_carries_its_company_and_restores_the_context(): void
    {
        $base = base_path('app/Jobs');
        $checked = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(base_path('app/')), -4);
            $class = 'App\\'.str_replace('/', '\\', $relative);
            $reflection = new ReflectionClass($class);
            if (! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }
            $checked[] = $class;
            $this->assertTrue($reflection->hasProperty('tenantId'), "{$class}: debe transportar la empresa (public int \$tenantId)");
            // El middleware puede heredarse de una clase base (p. ej. RunSupplyPlan extiende RunSupplyOperation): se lee el archivo que lo DECLARA.
            $declaring = (string) $reflection->getMethod('middleware')->getFileName();
            $this->assertStringContainsString('SetTenantContext', (string) file_get_contents($declaring), "{$class}: debe declarar el middleware SetTenantContext");
        }
        $this->assertGreaterThanOrEqual(10, count($checked));
    }

    public function test_no_queued_class_lives_outside_app_jobs(): void
    {
        // La prueba de arriba recorre app/Jobs; una clase en cola en otro lugar (servicio, listener, notificación) quedaría sin empresa ni middleware: se busca en TODA la aplicación.
        $outside = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen(base_path('app/')));
            if ($file->getExtension() === 'php' && ! str_starts_with($relative, 'Jobs/') && str_contains((string) file_get_contents($file->getPathname()), 'ShouldQueue')) {
                $outside[] = $relative;
            }
        }

        $this->assertSame([], $outside, 'Clase en cola fuera de app/Jobs: muévala (con public int $tenantId y SetTenantContext) o revísela y agréguela a una lista justificada.');
    }

    public function test_every_use_of_the_file_store_in_the_application_is_reviewed(): void
    {
        // Archivo → por qué sus rutas no mezclan empresas. Un uso NUEVO de Storage:: (o de File::put/copy/move) falla aquí hasta que se revise y se agregue. Los directorios temporales de
        // preparación (File::deleteDirectory, file_put_contents en una carpeta temporal) no son el almacén de datos de la empresa.
        $reviewed = [
            'Console/Commands/Core/PurgeCommand.php' => 'purga de empresas vencidas: borra el árbol tenants/{id}/ de cada una',
            'Http/Controllers/Api/Core/TenantExportController.php' => 'descarga el ZIP de una exportación de SU empresa, tras comprobar pertenencia y habilidad',
            'Services/DataCore/Bronze/BronzePurger.php' => 'borra tenants/{uuid}/bronze de UNA empresa',
            'Services/DataCore/Bronze/FilesystemBronzeStore.php' => 'rutas tenants/{uuid}/bronze/… (la empresa va en la ruta)',
            'Services/Imports/ImportService.php' => 'rutas tenants/{id}/imports/{uuid}',
            'Services/Models/ModelApprovalGates.php' => 'artefactos publicados: la clave debe empezar por la empresa del contexto (assertOwnKey)',
            'Services/Models/Usage/StorageUsageSnapshot.php' => 'mide los artefactos de SU empresa (filas del registro con alcance de empresa)',
            'Services/Privacy/TenantDataExporter.php' => 'escribe tenants/{id}/exports/…',
        ];
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/\bStorage::|\bFile::(put|copy|move|append|replace)\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen(base_path('app/')));
            }
        }
        sort($found);
        $expected = array_keys($reviewed);
        sort($expected);

        $this->assertSame($expected, $found, 'Uso de archivos nuevo (o retirado): revise que su ruta lleve la empresa y actualice esta lista.');
    }

    // ── Caché ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_cache_entries_of_company_a_are_never_served_to_company_b(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $day = now($a->timezone ?: 'America/Guayaquil')->toDateString();
        Cache::put("plans:active_series:{$a->id}:{$day}", 4321, 600);
        $plans = app(PlanLimitService::class);

        $this->assertSame(4321, $plans->activeSeries($a), 'A lee su entrada');
        $this->assertNotSame(4321, $plans->activeSeries($b), 'B no recibe la entrada de A: la clave lleva la empresa');
        $this->assertNotNull(Cache::get("plans:active_series:{$b->id}:{$day}"));
    }

    public function test_the_assistant_daily_limit_and_rate_are_counted_per_company(): void
    {
        config(['conversation.limits.daily_limit_per_tenant' => 2, 'conversation.limits.rate_per_minute' => 0]);
        [$a, $userA] = $this->createTenantWithUser(TenantRole::Analyst);
        [$b, $userB] = $this->createTenantWithUser(TenantRole::Analyst);
        $limiter = app(AssistantLimiter::class);
        $contextA = new ToolContext($a, $userA, TenantRole::Analyst);
        $contextB = new ToolContext($b, $userB, TenantRole::Analyst);

        $limiter->hit($contextA);
        $limiter->hit($contextA);
        $this->expectException(AssistantRateLimitedException::class);
        try {
            $limiter->hit($contextA);
        } finally {
            $limiter->hit($contextB);   // la empresa B no consumió el tope de A
            $this->assertSame(1, $limiter->usageToday($contextB)['count']);
            $this->assertSame(2, $limiter->usageToday($contextA)['count']);
        }
    }

    public function test_every_use_of_the_cache_in_the_application_has_a_reviewed_key_scope(): void
    {
        // Archivo → por qué su clave no mezcla empresas. Un uso NUEVO de Cache:: o RateLimiter:: falla aquí hasta que se revise y se agregue.
        $reviewed = [
            'Services/Plans/PlanLimitService.php' => 'clave plans:active_series:{empresa}:{día}',
            'Services/Conversation/AssistantLimiter.php' => 'claves tenant:{empresa}:assistant:…',
            'Services/Outcomes/BootstrapInterval.php' => 'clave = SHA-256 de las entradas numéricas del remuestreo (direccionada por contenido; sin identificadores ni datos de empresa)',
            'Services/Auth/Mfa/MfaStepUp.php' => 'clave por token o por persona (la persona es global: el segundo factor es de la persona)',
            'Services/Auth/Mfa/MfaChallenge.php' => 'clave = SHA-256 del desafío aleatorio de un solo uso (y su candado)',
            'Services/Auth/Mfa/MfaService.php' => 'contadores y bloqueos por persona + IP y por IP (el segundo factor es de la persona, no de la empresa)',
        ];
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // Cualquier fachada o ayudante de caché (Cache::x(), cache(), ->cache()) o de límite de tasa que lleve contadores (RateLimiter::for, que solo declara el limitador, no cuenta).
            if (preg_match('/\bCache::[a-zA-Z]+\(|(?<![>\w$:])cache\(|->cache\(|RateLimiter::(hit|attempt|tooManyAttempts|clear|increment|remaining|availableIn|resetAttempts)\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen(base_path('app/')));
            }
        }
        sort($found);
        $expected = array_keys($reviewed);
        sort($expected);
        // AppServiceProvider declara los limitadores por petición (RateLimiter::for), que no es un uso de datos; no entra en la búsqueda.
        $this->assertSame($expected, $found, 'Uso de caché nuevo (o retirado): revise que su clave lleve la empresa y actualice esta lista.');
    }

    // ── Almacén de objetos (Bronze) ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_object_store_keys_live_under_the_company_prefix_and_company_b_cannot_reach_them(): void
    {
        Storage::fake('local');
        config(['bronze.disk' => 'local']);
        [$a, $ownerA] = $this->createTenantWithUser(TenantRole::Owner);
        [$b, $ownerB] = $this->createTenantWithUser(TenantRole::Owner);
        $context = app(TenantContext::class);
        $store = app(BronzeStore::class);

        $object = $context->runAs($a, fn () => $store->store($a, 'file_upload', 'sales', "doc;total\nSOLO-A;1\n", 'csv'));
        $this->assertStringStartsWith('tenants/'.$a->uuid.'/bronze/', $object->object_key);
        $this->assertStringNotContainsString($b->uuid, $object->object_key);

        // API: la empresa B no ve el objeto de A, ni su metadato ni su contenido.
        $this->actingAsTenantUser($ownerB, $b)->getJson("/api/v1/lineage/bronze/{$object->id}")->assertNotFound();
        $this->actingAsTenantUser($ownerB, $b)->getJson("/api/v1/lineage/bronze/{$object->id}/content")->assertNotFound();
        $this->assertSame([], $this->actingAsTenantUser($ownerB, $b)->getJson('/api/v1/lineage/bronze')->assertOk()->json('data'));
        $this->actingAsTenantUser($ownerA, $a)->getJson("/api/v1/lineage/bronze/{$object->id}")->assertOk();

        // Servicio: con B activa, ni se lee ni se purga el objeto de A, y el modelo ni lo encuentra.
        $context->runAs($b, function () use ($store, $object): void {
            $this->assertNull(BronzeObject::query()->find($object->id));
            foreach ([fn () => $store->read($object), fn () => $store->purge($object, 'intento ajeno')] as $attempt) {
                try {
                    $attempt();
                    $this->fail('B no puede operar un objeto de A.');
                } catch (\InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
        Storage::disk('local')->assertExists($object->object_key);
    }

    // ── Registro de modelos ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_model_of_company_b_cannot_be_read_validated_approved_or_promoted_by_company_a(): void
    {
        $a = $this->registryCompany();
        $b = $this->registryCompany();
        $foreign = $this->customerModel($b['tenant'], 'validated', [], false);

        $api = fn (string $role) => $this->actingAsTenantUser($a[$role], $a['tenant']);
        $api('owner')->getJson("/api/v1/models/{$foreign}")->assertNotFound();
        $api('analyst')->postJson("/api/v1/models/{$foreign}/validate")->assertNotFound();
        $api('approver')->postJson("/api/v1/models/{$foreign}/approve")->assertNotFound();
        $api('approver')->postJson("/api/v1/models/{$foreign}/reject", ['reason' => 'prueba'])->assertNotFound();
        $api('approver')->postJson("/api/v1/models/{$foreign}/promote")->assertNotFound();
        $this->assertSame('validated', $this->statusOf($foreign), 'el modelo de B no cambió');
        $this->assertSame([], $this->eventsOf($foreign));
        $this->assertNotContains($foreign, array_column($api('viewer')->getJson('/api/v1/models')->assertOk()->json('data'), 'id'));
    }

    // ── Exportaciones LOPDP ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_lopdp_export_of_company_a_contains_nothing_of_company_b(): void
    {
        Storage::fake('local');
        [$a, $ownerA] = $this->createTenantWithUser(TenantRole::Owner);
        [$b, $ownerB] = $this->createTenantWithUser(TenantRole::Owner);
        foreach ([[$a, 'SOLO-A'], [$b, 'SOLO-B']] as [$tenant, $marker]) {
            $branch = $this->createBranch($tenant, '001', "Sucursal {$marker}");
            $this->withinTenant($tenant, function () use ($tenant, $marker, $branch): void {
                Product::query()->create(['sku' => "SKU-{$marker}", 'name' => "Producto {$marker}"]);
                $customer = (int) DB::table('customers')->insertGetId([
                    'tenant_id' => $tenant->id, 'external_code' => "CLI-{$marker}", 'customer_type' => 'company', 'is_generic' => false,
                    'name_encrypted' => encrypt("Cliente {$marker}"), 'commercial_zone' => "ZONA-{$marker}", 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('sales')->insert([
                    'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'customer_id' => $customer, 'document_type' => 'invoice', 'document_number' => "DOC-{$marker}", 'status' => 'completed',
                    'issued_at' => now(), 'sale_date' => now()->toDateString(), 'currency' => 'USD', 'total' => 10, 'source_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('alerts')->insert(['tenant_id' => $tenant->id, 'type' => 'stockout_risk', 'severity' => 'high', 'title' => "Alerta {$marker}", 'message' => "Mensaje {$marker}",
                    'payload' => '{}', 'dedup_key' => "dedup-{$marker}", 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            });
            DB::table('tenant_users')->where('tenant_id', $tenant->id)->update(['commercial_role' => 'gerente', 'commercial_scope' => json_encode(['zones' => ["ZONA-{$marker}"]])]);
        }

        $id = $this->actingAsTenantUser($ownerA, $a)->postJson('/api/v1/tenant/export')->assertStatus(202)->json('data.export_id');
        $this->getJson("/api/v1/tenant/export/{$id}")->assertOk()->assertJsonPath('data.status', 'completed');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) DB::table('tenant_exports')->where('id', $id)->value('path'))) === true);
        $all = '';
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
            $all .= $files[(string) $zip->getNameIndex($i)]."\n";
        }
        $zip->close();

        $this->assertStringNotContainsString('SOLO-B', $all, 'nada de la empresa B en la exportación de A');
        $this->assertStringNotContainsString($b->uuid, $all);
        $this->assertStringNotContainsString($ownerB->email, $all);
        foreach (['SKU-SOLO-A' => 'products.csv', 'DOC-SOLO-A' => 'sales.csv', 'ZONA-SOLO-A' => 'tenant_users.csv', 'Cliente SOLO-A' => 'customer_names.csv', 'CLI-SOLO-A' => 'customers.csv'] as $marker => $file) {
            $this->assertStringContainsString($marker, $files[$file], "{$file}: lo propio sí sale");
        }
        // Las credenciales y secretos no salen aunque sean de A: el hash de la contraseña y el segundo factor no están en ningún archivo.
        $this->assertStringNotContainsString('mfa_secret_encrypted', $all);
        $this->assertStringNotContainsString((string) DB::table('users')->where('id', $ownerA->id)->value('password'), $all);
    }
}
