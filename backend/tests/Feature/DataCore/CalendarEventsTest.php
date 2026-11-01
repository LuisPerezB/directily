<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Models\CalendarEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataCore\Support\ImportsCsv;
use Tests\TestCase;

/** Calendario con alcance (país, región, empresa, sucursal, categoría, producto, sector), revisiones y estado de conocimiento. */
final class CalendarEventsTest extends TestCase
{
    use BuildsImportFiles, ImportsCsv, InteractsWithTenants, RefreshDatabase;

    private const HEADERS = ['scope', 'scope_ref', 'code', 'name', 'category', 'starts_on', 'ends_on', 'status', 'known_at', 'source'];

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 15:00:00', 'America/Guayaquil'));
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->importCsv('products', ['sku', 'name', 'category'], [['CEM-50', 'Cemento 50kg', 'Cemento']]);
    }

    /** @return list<string|null> */
    private function row(string $scope, ?string $ref, string $code, array $override = []): array
    {
        $base = ['scope' => $scope, 'scope_ref' => $ref, 'code' => $code, 'name' => 'Evento '.$code, 'category' => 'holiday', 'starts_on' => '2026-12-24', 'ends_on' => '2026-12-26',
            'status' => 'confirmed', 'known_at' => '2026-01-10 08:00:00', 'source' => 'prueba'];

        return array_map(fn (string $h) => array_key_exists($h, $override) ? $override[$h] : $base[$h], self::HEADERS);
    }

    /** @return array<string, mixed> */
    private function events(string $query = ''): array
    {
        return $this->getJson('/api/v1/calendar-events'.$query)->assertOk()->json();
    }

    public function test_import_supports_every_scope_and_stores_stable_references(): void
    {
        $branchId = (int) DB::table('branches')->where('tenant_id', $this->tenant->id)->value('id');
        $productId = (int) DB::table('products')->where('tenant_id', $this->tenant->id)->value('id');
        $categoryId = (int) DB::table('product_categories')->where('tenant_id', $this->tenant->id)->value('id');
        $job = $this->importCsv('calendar_events', self::HEADERS, [
            $this->row('pais', 'ec', 'navidad'),
            $this->row('region', 'Sierra', 'fundacion_quito', ['category' => 'feriado local']),
            $this->row('empresa', null, 'inventario_anual', ['category' => 'sector_event']),
            $this->row('sucursal', '001', 'aniversario_local', ['category' => 'campaign']),
            $this->row('producto', 'CEM-50', 'oferta_cemento', ['category' => 'promotion']),
            $this->row('categoria', 'Cemento', 'temporada_obra', ['category' => 'commercial_season']),
            $this->row('sector', 'Ferretería', 'feria_sector', ['category' => 'sector_event']),
        ]);
        $this->assertSame(7, $job->stats['events_new']);
        $refs = DB::table('calendar_events')->where('tenant_id', $this->tenant->id)->pluck('scope_ref', 'code')->all();
        $this->assertSame('EC', $refs['navidad']);
        $this->assertSame('sierra', $refs['fundacion_quito']);
        $this->assertNull($refs['inventario_anual']);
        $this->assertSame("branch:{$branchId}", $refs['aniversario_local']);
        $this->assertSame("product:{$productId}", $refs['oferta_cemento']);
        $this->assertSame("category:{$categoryId}", $refs['temporada_obra']);
        $this->assertSame('sector:ferreteria', $refs['feria_sector']);
        $this->assertSame('local_holiday', DB::table('calendar_events')->where('tenant_id', $this->tenant->id)->where('code', 'fundacion_quito')->value('category'));
        $this->assertCount(7, $this->events()['data']);
        $this->assertCount(1, $this->events('?scope=region')['data']);
    }

    public function test_validation_and_unknown_knowledge_are_reported_not_invented(): void
    {
        $job = $this->importCsv('calendar_events', self::HEADERS, [
            $this->row('country', 'ECU', 'a'),                                        // país no ISO-2
            $this->row('branch', '999', 'b'),                                          // sucursal inexistente
            $this->row('product', 'NO-EXISTE', 'c'),
            $this->row('country', 'EC', 'd', ['ends_on' => '2026-12-01']),             // termina antes de empezar
            $this->row('country', 'EC', 'e', ['category' => 'inventado']),
            $this->row('country', 'EC', 'f', ['known_at' => '2027-05-01 00:00:00']),   // conocimiento futuro
            $this->row('country', 'EC', 'g', ['known_at' => '']),                      // sin known_at: válido pero advertido
        ], confirm: false);
        $codes = collect($this->importErrors($job))->pluck('error_code')->all();
        foreach (['invalid_value', 'unknown_branch', 'unknown_product', 'invalid_date', 'future_date'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertContains('known_at_unknown', array_column($this->importErrors($job, 'warning'), 'error_code'));
        $this->assertSame(1, $job->stats['rows_valid']);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertStatus(409); // hay errores: no se importa nada
        $this->importCsv('calendar_events', self::HEADERS, [$this->row('country', 'EC', 'g', ['known_at' => ''])]);
        $event = $this->events()['data'][0];
        $this->assertNull($event['known_at'], 'known_at desconocido queda NULL: no se rellena con la fecha de la carga');
        $this->assertTrue($event['known_at_unknown']);
    }

    public function test_reimport_is_idempotent_and_changes_create_revisions(): void
    {
        $first = $this->importCsv('calendar_events', self::HEADERS, [$this->row('country', 'EC', 'navidad', ['known_at' => '2026-06-01 00:00:00'])]);
        $this->assertSame(1, $first->stats['events_new']);
        $again = $this->importCsv('calendar_events', self::HEADERS, [$this->row('country', 'EC', 'navidad', ['known_at' => '2026-06-01 00:00:00'])]);
        $this->assertSame(1, $again->stats['events_unchanged']);
        $this->assertSame(1, DB::table('calendar_events')->where('tenant_id', $this->tenant->id)->count());

        // Cambia el estado (evento cancelado): nueva revisión que conserva la anterior.
        $revised = $this->importCsv('calendar_events', self::HEADERS, [$this->row('country', 'EC', 'navidad', ['status' => 'cancelled', 'known_at' => '2026-10-20 00:00:00'])]);
        $this->assertSame(1, $revised->stats['events_revised']);
        $this->assertSame([0, 1], DB::table('calendar_events')->where('tenant_id', $this->tenant->id)->orderBy('revision')->pluck('revision')->map(fn ($r) => (int) $r)->all());

        // Última revisión por defecto; al corte, la última conocida entonces.
        $this->assertSame('cancelled', $this->events()['data'][0]['status']);
        $atCutoff = $this->events('?as_of='.urlencode('2026-08-01T00:00:00-05:00'));
        $this->assertSame('confirmed', $atCutoff['data'][0]['status'], 'el 1/8 aún no se conocía la cancelación');
        $this->assertSame([], $this->events('?as_of='.urlencode('2026-05-01T00:00:00-05:00'))['data'], 'antes de conocerse el evento no existe');

        // known_at desconocido que luego se informa: se completa sin crear revisión.
        $this->importCsv('calendar_events', self::HEADERS, [$this->row('country', 'EC', 'dia_madre', ['known_at' => '', 'starts_on' => '2027-05-09', 'ends_on' => '2027-05-09'])]);
        $this->assertNotContains('dia_madre', array_column($this->events('?as_of='.urlencode('2026-10-01T00:00:00-05:00'))['data'], 'code'), 'sin known_at no es visible a un corte');
        $filled = $this->importCsv('calendar_events', self::HEADERS, [$this->row('country', 'EC', 'dia_madre', ['known_at' => '2026-02-01 00:00:00', 'starts_on' => '2027-05-09', 'ends_on' => '2027-05-09'])]);
        $this->assertSame(1, $filled->stats['events_known_at_updated']);
        $this->assertSame(1, DB::table('calendar_events')->where('tenant_id', $this->tenant->id)->where('code', 'dia_madre')->count());
    }

    public function test_api_crud_creates_revisions_and_cancels_instead_of_deleting(): void
    {
        $created = $this->postJson('/api/v1/calendar-events', ['scope' => 'country', 'scope_ref' => 'EC', 'code' => 'black_friday', 'name' => 'Black Friday', 'category' => 'international_peak',
            'starts_on' => '2026-11-27', 'ends_on' => '2026-11-29', 'known_at' => '2026-09-01T00:00:00-05:00'])->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame(0, $created->json('data.revision'));
        $this->patchJson("/api/v1/calendar-events/{$id}", ['ends_on' => '2026-11-30', 'name' => 'Black Friday extendido'])->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.ends_on', '2026-11-30');
        $this->patchJson("/api/v1/calendar-events/{$id}", ['name' => 'obsoleta'])->assertStatus(409)->assertJsonPath('code', 'stale_revision'); // la revisión 0 ya no es la última
        $latest = $this->events('?scope=country')['data'][0];
        $this->deleteJson('/api/v1/calendar-events/'.$latest['id'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.revision', 2);
        $this->assertSame(3, DB::table('calendar_events')->where('tenant_id', $this->tenant->id)->count(), 'el historial se conserva');
        $this->postJson('/api/v1/calendar-events', ['scope' => 'country', 'code' => 'x', 'name' => 'x', 'category' => 'holiday', 'starts_on' => '2026-12-01'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_public_calendar_is_read_only_and_other_tenants_never_see_company_events(): void
    {
        DB::table('calendar_events')->insert(['tenant_id' => null, 'scope' => 'country', 'scope_ref' => 'EC', 'code' => 'publico', 'name' => 'Evento público', 'category' => 'holiday',
            'starts_on' => '2026-12-25', 'ends_on' => '2026-12-25', 'status' => 'confirmed', 'known_at' => '2020-01-01 00:00:00+00', 'source' => 'publico', 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->importCsv('calendar_events', self::HEADERS, [$this->row('tenant', null, 'privado_a')]);
        $publicId = (int) DB::table('calendar_events')->whereNull('tenant_id')->value('id');
        $privateId = (int) DB::table('calendar_events')->where('tenant_id', $this->tenant->id)->value('id');

        $codes = array_column($this->events()['data'], 'code');
        $this->assertEqualsCanonicalizing(['publico', 'privado_a'], $codes);
        $this->assertSame(['privado_a'], array_column($this->events('?include_public=0')['data'], 'code'));
        $this->patchJson("/api/v1/calendar-events/{$publicId}", ['name' => 'hackeado'])->assertNotFound();
        $this->deleteJson("/api/v1/calendar-events/{$publicId}")->assertNotFound();

        [$tenantB, $userB] = $this->createTenantWithUser();
        $this->actingAsTenantUser($userB, $tenantB);
        $this->assertSame(['publico'], array_column($this->events()['data'], 'code'), 'B ve el calendario público pero nada de A');
        $this->patchJson("/api/v1/calendar-events/{$privateId}", ['name' => 'robado'])->assertNotFound();
        $this->deleteJson("/api/v1/calendar-events/{$privateId}")->assertNotFound();
        $this->assertSame('Evento privado_a', DB::table('calendar_events')->where('id', $privateId)->value('name'));

        // Fail-closed: sin empresa en contexto el modelo no consulta.
        $this->expectException(TenantNotResolvedException::class);
        CalendarEvent::query()->count();
    }
}
