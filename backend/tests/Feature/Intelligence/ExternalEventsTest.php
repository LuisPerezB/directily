<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Enums\TenantRole;
use App\Models\ExternalEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use App\Services\Intelligence\Exceptions\PublicCatalogReadOnlyException;
use App\Services\Intelligence\ExternalEventService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Intelligence\Support\IntelligenceFixtures;
use Tests\TestCase;

/** Eventos externos manuales con procedencia: segregación proponer/verificar, sin published_at no verificable, vencimiento y aislamiento. */
final class ExternalEventsTest extends TestCase
{
    use IntelligenceFixtures, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $analyst;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00', 'America/Guayaquil'));
        [$this->tenant, $this->owner] = $this->createTenantWithUser(TenantRole::Owner, ['timezone' => 'America/Guayaquil']);
        $this->analyst = $this->addUserToTenant($this->tenant, TenantRole::Analyst);
        $this->admin = $this->addUserToTenant($this->tenant, TenantRole::Admin);
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'family' => 'food_safety_alerts', 'event_type' => 'sanitary_document', 'title' => 'Alerta de inocuidad en lote de harina', 'summary' => 'Texto resumido por una persona.',
            'source_url' => 'https://fuente.example.test/alerta/123', 'source_ref' => 'ALR-123', 'authority' => 'Agencia de regulación (ficticia)',
            'published_at' => '2026-10-20T09:00:00-05:00', 'effective_from' => '2026-10-21', 'effective_to' => '2026-12-31', 'jurisdiction' => 'EC',
            'corroboration' => 'official', 'affects' => ['regions' => ['sierra']], 'is_informative_alert' => false,
        ], $override);
    }

    /** Cuerpo de verify/reject: la versión que el usuario autenticado acaba de leer (review_token) más los campos propios de la operación. */
    private function review(int $id, array $extra = []): array
    {
        return ['review_token' => (string) $this->getJson("/api/v1/intelligence/events/{$id}")->json('data.review_token', 'sin-token')] + $extra;
    }

    private function propose(User $by, array $override = []): int
    {
        return (int) $this->actingAsTenantUser($by, $this->tenant)->postJson('/api/v1/intelligence/events', $this->payload($override))->assertCreated()->json('data.id');
    }

    public function test_an_event_is_born_proposed_with_full_provenance_and_cannot_forge_its_state(): void
    {
        $response = $this->actingAsTenantUser($this->analyst, $this->tenant)->postJson('/api/v1/intelligence/events', $this->payload([
            'status' => 'VERIFIED', 'extracted_by' => 'llm', 'proposed_by' => 999, 'verified_by' => $this->owner->id, 'tenant_id' => 12345,
        ]))->assertCreated();

        $response->assertJsonPath('data.status', 'PROPOSED')->assertJsonPath('data.extracted_by', 'manual')->assertJsonPath('data.proposed_by', $this->analyst->id)
            ->assertJsonPath('data.reviewed_by', null)->assertJsonPath('data.scope', 'tenant')->assertJsonPath('data.usable_for_prediction', false)
            ->assertJsonPath('data.source_url', 'https://fuente.example.test/alerta/123')->assertJsonPath('data.authority', 'Agencia de regulación (ficticia)')
            ->assertJsonPath('data.jurisdiction', 'EC')->assertJsonPath('data.corroboration', 'official')->assertJsonPath('data.effective_to', '2026-12-31');
        $row = DB::table('external_events')->find($response->json('data.id'));
        $this->assertSame($this->tenant->id, $row->tenant_id);
        $this->assertNull($row->available_at, 'Un evento propuesto aún no está disponible para el sistema.');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.event_proposed')->count());
    }

    public function test_the_proposer_cannot_verify_or_reject_their_own_event_but_another_user_can(): void
    {
        $id = $this->propose($this->admin);

        // Segregación de funciones: el administrador que lo propuso no puede verificarlo ni rechazarlo.
        $this->actingAsTenantUser($this->admin, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))->assertStatus(403)->assertJsonPath('code', 'segregation_of_duties');
        $this->postJson("/api/v1/intelligence/events/{$id}/reject", $this->review($id, ['reason' => 'No me convence']))->assertStatus(403)->assertJsonPath('code', 'segregation_of_duties');
        $this->assertSame('PROPOSED', DB::table('external_events')->where('id', $id)->value('status'));

        // Otro usuario autorizado (propietario) sí; queda quién revisó, cuándo y su disponibilidad real.
        $verified = $this->actingAsTenantUser($this->owner, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id, ['note' => 'Contrastado con la fuente oficial.']))->assertOk();
        $verified->assertJsonPath('data.status', 'VERIFIED')->assertJsonPath('data.reviewed_by', $this->owner->id)->assertJsonPath('data.review_note', 'Contrastado con la fuente oficial.')
            ->assertJsonPath('data.usable_for_prediction', true)->assertJsonPath('data.validity_state', 'AVAILABLE');
        $row = DB::table('external_events')->find($id);
        $this->assertNotNull($row->verified_at);
        $this->assertNotNull($row->available_at, 'Al verificarse el evento pasa a estar disponible; antes no existía para un backtest.');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.event_verified')->count());
        // Un evento verificado es inmutable.
        $this->patchJson("/api/v1/intelligence/events/{$id}", ['title' => 'Cambiado'])->assertStatus(409)->assertJsonPath('code', 'invalid_event_state');
        $this->deleteJson("/api/v1/intelligence/events/{$id}")->assertStatus(409);
        $this->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))->assertStatus(409);
    }

    public function test_only_the_proposer_edits_or_withdraws_a_pending_event_so_segregation_cannot_be_bypassed(): void
    {
        $id = $this->propose($this->analyst);
        $otherAnalyst = $this->addUserToTenant($this->tenant, TenantRole::Analyst);

        // Otro usuario con permiso de catálogo —incluso un administrador— no puede cambiar el contenido para luego verificarlo.
        foreach ([$otherAnalyst, $this->admin, $this->owner] as $other) {
            $this->actingAsTenantUser($other, $this->tenant)->patchJson("/api/v1/intelligence/events/{$id}", ['title' => 'Contenido alterado por otro usuario'])
                ->assertStatus(403)->assertJsonPath('code', 'only_proposer_can_change');
            $this->deleteJson("/api/v1/intelligence/events/{$id}")->assertStatus(403)->assertJsonPath('code', 'only_proposer_can_change');
        }
        $this->assertSame('Alerta de inocuidad en lote de harina', DB::table('external_events')->where('id', $id)->value('title'));
        // El proponente sí; y luego otro usuario autorizado verifica exactamente lo que el proponente dejó.
        $this->actingAsTenantUser($this->analyst, $this->tenant)->patchJson("/api/v1/intelligence/events/{$id}", ['title' => 'Título corregido por el proponente'])->assertOk();
        $this->actingAsTenantUser($this->admin, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))->assertOk()->assertJsonPath('data.title', 'Título corregido por el proponente');
        // Un evento ya rechazado sale del flujo: cualquier usuario con permiso de catálogo puede limpiarlo.
        $rejected = $this->propose($this->analyst, ['title' => 'Propuesta que se rechaza']);
        $this->actingAsTenantUser($this->owner, $this->tenant)->postJson("/api/v1/intelligence/events/{$rejected}/reject", $this->review($rejected, ['reason' => 'No aplica']))->assertOk();
        $this->actingAsTenantUser($otherAnalyst, $this->tenant)->deleteJson("/api/v1/intelligence/events/{$rejected}")->assertOk();
    }

    public function test_verify_and_reject_are_bound_to_the_version_the_reviewer_read(): void
    {
        $id = $this->propose($this->analyst);
        $url = "/api/v1/intelligence/events/{$id}";

        // El revisor (administrador) lee el evento …
        $seen = $this->actingAsTenantUser($this->admin, $this->tenant)->getJson($url)->assertOk()->json('data');
        $this->assertSame(40, strlen($seen['review_token']));
        // … y el proponente lo edita DESPUÉS de esa lectura (cambia título, URL, fecha de publicación y jurisdicción).
        $this->actingAsTenantUser($this->analyst, $this->tenant)->patchJson($url, ['title' => 'Alerta MODIFICADA', 'source_url' => 'https://otra.example.test/x', 'jurisdiction' => 'XX'])->assertOk();

        // Verificar o rechazar lo que vio es imposible: 409 con la versión actual; nada cambia.
        $this->actingAsTenantUser($this->admin, $this->tenant)->postJson("{$url}/verify", ['review_token' => $seen['review_token']])
            ->assertStatus(409)->assertJsonPath('code', 'event_changed_since_review');
        $this->postJson("{$url}/reject", ['reason' => 'No me convence', 'review_token' => $seen['review_token']])->assertStatus(409)->assertJsonPath('code', 'event_changed_since_review');
        $this->assertSame('PROPOSED', DB::table('external_events')->where('id', $id)->value('status'));

        // Sin versión revisada no se verifica ni se rechaza (422), ni con una versión inventada (409).
        $this->postJson("{$url}/verify", [])->assertStatus(422)->assertJsonPath('code', 'review_token_required');
        $this->postJson("{$url}/reject", ['reason' => 'No me convence'])->assertStatus(422)->assertJsonPath('code', 'review_token_required');
        $this->postJson("{$url}/verify", ['review_token' => str_repeat('0', 40)])->assertStatus(409);

        // Releído el contenido actual, ahora sí; la huella también viaja en If-Match (con o sin comillas).
        $current = $this->getJson($url)->assertOk()->json('data');
        $this->assertNotSame($seen['review_token'], $current['review_token']);
        $this->postJson("{$url}/verify", [], ['If-Match' => '"'.$current['review_token'].'"'])->assertOk()->assertJsonPath('data.title', 'Alerta MODIFICADA')->assertJsonPath('data.status', 'VERIFIED');
    }

    public function test_verify_compares_against_the_locked_row_not_against_a_stale_copy(): void
    {
        $id = $this->propose($this->analyst);
        $service = app(ExternalEventService::class);

        // Copia en memoria que el controlador ya cargó (con su token) y edición concurrente por otra petición ANTES de que el servicio verifique.
        [$stale, $staleToken] = $this->withinTenant($this->tenant, function () use ($id): array {
            $event = ExternalEvent::query()->findOrFail($id);

            return [$event, ExternalEventService::reviewToken($event)];
        });
        DB::table('external_events')->where('id', $id)->update(['title' => 'Editado por una petición concurrente', 'updated_at' => now()]);

        try {
            $this->withinTenant($this->tenant, fn () => $service->verify($this->tenant, $this->owner, $stale, $staleToken));
            $this->fail('Debía rechazarse: la fila bloqueada ya cambió.');
        } catch (IntelligenceRuleException $e) {
            $this->assertSame('event_changed_since_review', $e->errorCode);
            $this->assertSame(409, $e->status);
        }
        $this->assertSame('PROPOSED', DB::table('external_events')->where('id', $id)->value('status'));
        $this->assertSame('Editado por una petición concurrente', DB::table('external_events')->where('id', $id)->value('title'));
    }

    public function test_the_review_token_ignores_saves_without_changes_but_tracks_every_reviewable_field(): void
    {
        $id = $this->propose($this->analyst, ['affects' => ['regions' => ['sierra', 'costa'], 'products' => []]]);
        $url = "/api/v1/intelligence/events/{$id}";
        $this->actingAsTenantUser($this->analyst, $this->tenant);
        $first = $this->getJson($url)->json('data.review_token');

        // Guardar los mismos valores no cambia la versión; tampoco el orden de las claves.
        $this->patchJson($url, ['title' => 'Alerta de inocuidad en lote de harina', 'affects' => ['products' => [], 'regions' => ['sierra', 'costa']]])->assertOk();
        $this->assertSame($first, $this->getJson($url)->json('data.review_token'));
        // Cualquier campo que el revisor ve la cambia.
        $seen = [$first];
        foreach ([['summary' => 'Otro resumen'], ['authority' => 'Otra autoridad'], ['jurisdiction' => 'XX'], ['source_ref' => 'ALR-999'], ['effective_to' => '2027-01-31'],
            ['published_at' => '2026-10-21T09:00:00-05:00'], ['corroboration' => 'corroborated'], ['is_informative_alert' => true], ['affects' => ['regions' => ['costa']]]] as $change) {
            $this->patchJson($url, $change)->assertOk();
            $token = $this->getJson($url)->json('data.review_token');
            $this->assertNotContains($token, $seen, 'El cambio '.json_encode($change).' debe cambiar la versión revisable.');
            $seen[] = $token;
        }
    }

    public function test_available_at_is_never_earlier_than_the_publication_date(): void
    {
        // Una publicación unos minutos en el futuro (desfase de reloj, tolerado) no puede quedar «disponible» antes de existir.
        $published = now()->addMinutes(4);
        $id = $this->propose($this->analyst, ['published_at' => $published->toIso8601String()]);

        $this->actingAsTenantUser($this->owner, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))->assertOk();

        $row = DB::table('external_events')->find($id);
        $this->assertGreaterThanOrEqual(strtotime((string) $row->published_at), strtotime((string) $row->available_at), 'available_at >= published_at');
        $this->assertGreaterThanOrEqual(strtotime((string) $row->verified_at), strtotime((string) $row->available_at), 'available_at >= verified_at');
        // Con una publicación pasada, queda disponible al verificar.
        $past = $this->propose($this->analyst, ['title' => 'Publicada hace semanas', 'published_at' => '2026-10-01T09:00:00-05:00']);
        $this->actingAsTenantUser($this->owner, $this->tenant)->postJson("/api/v1/intelligence/events/{$past}/verify", $this->review($past))->assertOk();
        $this->assertSame(DB::table('external_events')->where('id', $past)->value('verified_at'), DB::table('external_events')->where('id', $past)->value('available_at'));
    }

    public function test_source_url_cannot_carry_embedded_credentials(): void
    {
        $this->actingAsTenantUser($this->analyst, $this->tenant);

        foreach (['https://usuario:clave@fuente.example.test/a', 'https://usuario@fuente.example.test/a', 'http://:clave@fuente.example.test/a'] as $url) {
            $this->postJson('/api/v1/intelligence/events', $this->payload(['source_url' => $url]))->assertStatus(422)->assertJsonValidationErrors('source_url');
        }
        $this->assertSame(0, DB::table('external_events')->where('source_url', 'like', '%clave%')->count());
        // También al editar.
        $id = $this->propose($this->analyst);
        $this->patchJson("/api/v1/intelligence/events/{$id}", ['source_url' => 'https://usuario:clave@fuente.example.test/b'])->assertStatus(422)->assertJsonValidationErrors('source_url');
        // Una URL normal (incluso con puerto y parámetros) sí.
        $this->postJson('/api/v1/intelligence/events', $this->payload(['source_url' => 'https://fuente.example.test:8443/a?id=1#parte']))->assertCreated();
    }

    public function test_reject_requires_a_reason_a_different_user_and_keeps_the_event_out_of_use(): void
    {
        $id = $this->propose($this->analyst);

        $this->actingAsTenantUser($this->admin, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/reject", $this->review($id, []))->assertStatus(422);
        $rejected = $this->postJson("/api/v1/intelligence/events/{$id}/reject", $this->review($id, ['reason' => 'La fuente no corrobora el hecho.']))->assertOk();

        $rejected->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.usable_for_prediction', false)->assertJsonPath('data.review_note', 'La fuente no corrobora el hecho.');
        $this->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))->assertStatus(409);
        // Un evento rechazado puede borrarse (con auditoría); uno verificado no.
        $this->actingAsTenantUser($this->analyst, $this->tenant)->deleteJson("/api/v1/intelligence/events/{$id}")->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.event_deleted')->count());
    }

    public function test_only_admin_or_owner_verify_and_analyst_or_viewer_cannot(): void
    {
        $id = $this->propose($this->analyst);
        $viewer = $this->addUserToTenant($this->tenant, TenantRole::Viewer);
        $otherAnalyst = $this->addUserToTenant($this->tenant, TenantRole::Analyst);

        $this->actingAsTenantUser($otherAnalyst, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))->assertStatus(403)->assertJsonPath('required_ability', 'settings.manage');
        $this->actingAsTenantUser($viewer, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))->assertStatus(403);
        $this->postJson('/api/v1/intelligence/events', $this->payload())->assertStatus(403)->assertJsonPath('required_ability', 'catalog.manage');
        $this->getJson('/api/v1/intelligence/events')->assertOk();
        $this->assertSame('PROPOSED', DB::table('external_events')->where('id', $id)->value('status'));
    }

    public function test_without_published_at_the_event_cannot_be_verified_for_predictive_use(): void
    {
        $id = $this->propose($this->analyst, ['published_at' => null]);

        $this->actingAsTenantUser($this->owner, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))
            ->assertStatus(422)->assertJsonPath('code', 'published_at_required');
        $this->assertSame('PROPOSED', DB::table('external_events')->where('id', $id)->value('status'));
        $this->getJson("/api/v1/intelligence/events/{$id}")->assertOk()->assertJsonPath('data.validity_state', 'INVALID');

        // Al completar la fecha de publicación se puede verificar.
        $this->actingAsTenantUser($this->analyst, $this->tenant)->patchJson("/api/v1/intelligence/events/{$id}", ['published_at' => '2026-10-25T08:00:00-05:00'])->assertOk();
        $this->actingAsTenantUser($this->owner, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))->assertOk()->assertJsonPath('data.status', 'VERIFIED');
    }

    public function test_incomplete_provenance_blocks_verification_and_a_future_publication_is_rejected(): void
    {
        $id = $this->propose($this->analyst, ['authority' => null, 'jurisdiction' => null, 'source_url' => null, 'source_ref' => 'REF-1']);

        $this->actingAsTenantUser($this->owner, $this->tenant)->postJson("/api/v1/intelligence/events/{$id}/verify", $this->review($id))
            ->assertStatus(422)->assertJsonPath('code', 'provenance_incomplete')->assertJsonPath('context.missing', ['source_url', 'authority', 'jurisdiction']);
        $this->actingAsTenantUser($this->analyst, $this->tenant)->postJson('/api/v1/intelligence/events', $this->payload(['published_at' => '2026-11-15T00:00:00-05:00']))
            ->assertStatus(422)->assertJsonPath('code', 'published_at_in_future');
        // Sin ninguna referencia de la fuente (ni URL ni ID) el evento ni siquiera se propone.
        $this->postJson('/api/v1/intelligence/events', $this->payload(['source_url' => null, 'source_ref' => null]))->assertStatus(422);
        // La URL solo puede ser http(s): no se guardan esquemas peligrosos (y nunca se descarga).
        $this->postJson('/api/v1/intelligence/events', $this->payload(['source_url' => 'javascript:alert(1)']))->assertStatus(422);
        $this->postJson('/api/v1/intelligence/events', $this->payload(['source_url' => 'file:///etc/passwd']))->assertStatus(422);
        $this->postJson('/api/v1/intelligence/events', $this->payload(['family' => 'inventada']))->assertStatus(422);
        $this->postJson('/api/v1/intelligence/events', $this->payload(['effective_to' => '2026-10-01']))->assertStatus(422);
    }

    public function test_events_expire_when_their_validity_ends(): void
    {
        $verifiedId = $this->propose($this->analyst, ['effective_to' => '2026-11-05']);
        $proposedId = $this->propose($this->analyst, ['title' => 'Otro evento propuesto', 'effective_to' => '2026-11-05']);
        $openId = $this->propose($this->analyst, ['title' => 'Evento sin fin de vigencia', 'effective_to' => null]);
        $rejectedId = $this->propose($this->analyst, ['title' => 'Evento rechazado', 'effective_to' => '2026-11-05']);
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->postJson("/api/v1/intelligence/events/{$verifiedId}/verify", $this->review($verifiedId))->assertOk();
        $this->postJson("/api/v1/intelligence/events/{$rejectedId}/reject", $this->review($rejectedId, ['reason' => 'No aplica']))->assertOk();

        // Antes de vencer nada cambia.
        $this->assertSame(0, app(ExternalEventService::class)->expireDue());

        // Pasada la vigencia, la API ya lo muestra vencido (estado efectivo) aunque el proceso no haya corrido…
        $this->travelTo(CarbonImmutable::parse('2026-11-06 08:00:00', 'America/Guayaquil'));
        $this->getJson("/api/v1/intelligence/events/{$verifiedId}")->assertJsonPath('data.status', 'VERIFIED')->assertJsonPath('data.effective_status', 'EXPIRED')->assertJsonPath('data.usable_for_prediction', false);
        $this->assertCount(2, $this->getJson('/api/v1/intelligence/events?status=EXPIRED')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/intelligence/events?status=PROPOSED')->assertOk()->json('data'), 'Solo el evento propuesto sin vencer sigue como PROPOSED efectivo.');
        // … y un evento vencido ya no puede verificarse.
        $this->postJson("/api/v1/intelligence/events/{$proposedId}/verify", $this->review($proposedId))->assertStatus(422)->assertJsonPath('code', 'event_expired');

        // El proceso lo persiste como EXPIRED (idempotente); el rechazado y el sin fin de vigencia no se tocan.
        $this->artisan('predictily:intelligence-expire')->assertExitCode(0);
        $this->assertSame('EXPIRED', DB::table('external_events')->where('id', $verifiedId)->value('status'));
        $this->assertSame('EXPIRED', DB::table('external_events')->where('id', $proposedId)->value('status'));
        $this->assertSame('PROPOSED', DB::table('external_events')->where('id', $openId)->value('status'));
        $this->assertSame('REJECTED', DB::table('external_events')->where('id', $rejectedId)->value('status'));
        $this->assertSame(0, app(ExternalEventService::class)->expireDue());
        $this->postJson("/api/v1/intelligence/events/{$verifiedId}/verify", $this->review($verifiedId))->assertStatus(409);
    }

    public function test_informative_alerts_are_listed_apart_and_are_never_predictive_features(): void
    {
        $alertId = $this->propose($this->analyst, ['title' => 'Alerta oficial de inocuidad', 'is_informative_alert' => true]);
        $signalId = $this->propose($this->analyst, ['title' => 'Señal de campaña', 'family' => 'trade_regulatory', 'is_informative_alert' => false]);
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->postJson("/api/v1/intelligence/events/{$alertId}/verify", $this->review($alertId))->assertOk()->assertJsonPath('data.usable_for_prediction', false)->assertJsonPath('data.status', 'VERIFIED');
        $this->postJson("/api/v1/intelligence/events/{$signalId}/verify", $this->review($signalId))->assertOk()->assertJsonPath('data.usable_for_prediction', true);

        $events = collect($this->getJson('/api/v1/intelligence/events')->assertOk()->json('data'))->pluck('id')->all();
        $alerts = collect($this->getJson('/api/v1/intelligence/events/informative-alerts')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$signalId], $events);
        $this->assertSame([$alertId], $alerts);
        // Una alerta verificada se muestra aunque no sea feature predictiva.
        $this->getJson('/api/v1/intelligence/events/informative-alerts?status=VERIFIED')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_listing_filters_pagination_and_not_found(): void
    {
        $a = $this->propose($this->analyst, ['family' => 'weather', 'event_type' => 'storm', 'title' => 'Tormenta tropical']);
        $this->propose($this->analyst, ['family' => 'epidemiology', 'event_type' => 'outbreak', 'title' => 'Brote regional']);
        $this->getJson('/api/v1/intelligence/events?family=weather')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a);
        $this->getJson('/api/v1/intelligence/events?event_type=outbreak')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/intelligence/events?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/intelligence/events?status=NOPE')->assertStatus(422);
        $this->getJson('/api/v1/intelligence/events/999999')->assertStatus(404);
    }

    public function test_events_are_isolated_between_companies_and_public_events_are_read_only(): void
    {
        $mine = $this->propose($this->analyst);
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $adminB = $this->addUserToTenant($tenantB, TenantRole::Admin);
        $publicId = (int) DB::table('external_events')->insertGetId([
            'tenant_id' => null, 'family' => 'trade_regulatory', 'event_type' => 'tariff', 'title' => 'Evento público de ejemplo', 'published_at' => now(), 'status' => 'VERIFIED',
            'is_informative_alert' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // La empresa B no ve ni toca el evento de A (404), pero sí ve el público.
        $this->actingAsTenantUser($adminB, $tenantB);
        $this->getJson("/api/v1/intelligence/events/{$mine}")->assertStatus(404);
        $this->patchJson("/api/v1/intelligence/events/{$mine}", ['title' => 'Hackeo'])->assertStatus(404);
        $this->postJson("/api/v1/intelligence/events/{$mine}/verify", $this->review($mine))->assertStatus(404);
        $this->postJson("/api/v1/intelligence/events/{$mine}/reject", $this->review($mine, ['reason' => 'abc']))->assertStatus(404);
        $this->deleteJson("/api/v1/intelligence/events/{$mine}")->assertStatus(404);
        $idsB = collect($this->getJson('/api/v1/intelligence/events')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($mine, $idsB);
        $this->assertContains($publicId, $idsB);
        $this->getJson("/api/v1/intelligence/events/{$publicId}")->assertOk()->assertJsonPath('data.scope', 'public');
        // El evento público se lee pero ninguna empresa lo escribe.
        $this->patchJson("/api/v1/intelligence/events/{$publicId}", ['title' => 'Hackeo'])->assertStatus(403)->assertJsonPath('code', 'public_catalog_read_only');
        $this->postJson("/api/v1/intelligence/events/{$publicId}/reject", $this->review($publicId, ['reason' => 'abc']))->assertStatus(403);
        $this->deleteJson("/api/v1/intelligence/events/{$publicId}")->assertStatus(403);
        $this->assertSame('Evento público de ejemplo', DB::table('external_events')->where('id', $publicId)->value('title'));
        $this->assertSame('PROPOSED', DB::table('external_events')->where('id', $mine)->value('status'));
        // El modelo también lo impide.
        $this->expectException(PublicCatalogReadOnlyException::class);
        $this->withinTenant($tenantB, fn () => ExternalEvent::query()->findOrFail($publicId)->delete());
    }

    public function test_affects_cannot_reference_products_of_another_company(): void
    {
        [$tenantB] = $this->createTenantWithUser();
        $foreignProduct = $this->insertProduct($tenantB, 'AJENO-1');
        $ownProduct = $this->insertProduct($this->tenant, 'PROPIO-1');
        $this->actingAsTenantUser($this->analyst, $this->tenant);

        $this->postJson('/api/v1/intelligence/events', $this->payload(['affects' => ['products' => [$foreignProduct]]]))->assertStatus(422)->assertJsonPath('code', 'affects_not_found');
        $this->postJson('/api/v1/intelligence/events', $this->payload(['affects' => ['products' => [$ownProduct]]]))->assertCreated()->assertJsonPath('data.affects.products.0', $ownProduct);
    }

    public function test_events_require_authentication_and_the_tenant_header(): void
    {
        $this->flushHeaders();
        $this->getJson('/api/v1/intelligence/events')->assertStatus(401);
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->flushHeaders();
        $this->getJson('/api/v1/intelligence/events')->assertStatus(400);
    }

    public function test_this_package_has_no_external_integration_or_llm_extraction(): void
    {
        $id = $this->propose($this->analyst);

        $row = DB::table('external_events')->find($id);
        $this->assertSame('manual', $row->extracted_by);
        $this->assertSame([], json_decode($row->extraction, true));
        $this->assertNull($row->source_version_id);
        $this->assertSame(0, DB::table('source_versions')->count(), 'No hay fuentes externas registradas: ninguna integración activa.');
        $this->assertSame(0, DB::table('external_indicators')->count());
    }
}
