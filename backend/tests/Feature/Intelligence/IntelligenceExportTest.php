<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Privacy\TenantDataExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\Feature\Intelligence\Support\IntelligenceFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Exportación LOPDP de Factor Intelligence y taxonomía: cada empresa exporta lo suyo; el catálogo público no es suyo; las concesiones de pronóstico
 * (sin tenant_id: dos empresas) se exportan por parte —emisora o receptora— sin los datos, usuarios ni identificadores de la otra.
 */
final class IntelligenceExportTest extends TestCase
{
    use CustomerFixtures, IntelligenceFixtures, RefreshDatabase;

    /** @return array{0: array<string, string>, 1: array<string, int>} */
    private function exportOf(Tenant $tenant, User $owner): array
    {
        $exportId = $this->actingAsTenantUser($owner, $tenant)->postJson('/api/v1/tenant/export')->assertStatus(202)->json('data.export_id');
        $status = $this->getJson("/api/v1/tenant/export/{$exportId}")->assertOk()->assertJsonPath('data.status', 'completed');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) DB::table('tenant_exports')->where('id', $exportId)->value('path'))) === true);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return [$files, (array) $status->json('data.summary')];
    }

    /** @return list<array<string, string|null>> */
    private function csv(string $content): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n/', ltrim($content, "\xEF\xBB\xBF")) ?: [], fn ($l) => $l !== ''));
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');

        return array_map(fn (string $line) => array_combine($header, str_getcsv($line, ',', '"', '')), $lines);
    }

    public function test_company_rows_of_factors_events_and_taxonomy_are_exported_and_public_rows_and_other_companies_are_not(): void
    {
        Storage::fake('local');
        $this->seedFactorCatalog();
        [$a, $ownerA] = $this->createTenantWithUser();
        [$b, $ownerB] = $this->createTenantWithUser();
        $now = now();
        foreach ([[$a, 'MARCADOR-A'], [$b, 'MARCADOR-B']] as [$tenant, $marker]) {
            $factor = DB::table('factor_definitions')->insertGetId(['tenant_id' => $tenant->id, 'family' => 'competition', 'key' => "competition.{$marker}", 'name' => "Factor {$marker}", 'availability_class' => 'STATIC', 'storage' => 'sales', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('source_versions')->insert(['tenant_id' => $tenant->id, 'source_key' => 'manual', 'version_label' => $marker, 'notes' => "Versión {$marker}", 'created_at' => $now, 'updated_at' => $now]);
            DB::table('external_events')->insert(['tenant_id' => $tenant->id, 'family' => 'weather', 'event_type' => 'storm', 'title' => "Evento {$marker}", 'status' => 'PROPOSED', 'proposed_by' => $ownerA->id, 'review_note' => "Nota {$marker}", 'created_at' => $now, 'updated_at' => $now]);
            $node = DB::table('taxonomy_nodes')->insertGetId(['tenant_id' => $tenant->id, 'level' => 'sector', 'code' => "nodo-{$marker}", 'name' => "Nodo {$marker}", 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $product = $this->insertProduct($tenant, "SKU-{$marker}");
            DB::table('product_taxonomy_assignments')->insert(['tenant_id' => $tenant->id, 'product_id' => $product, 'taxonomy_node_id' => $node, 'provenance' => 'manual', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('tenant_sector_profiles')->insert(['tenant_id' => $tenant->id, 'profile_key' => "perfil_{$marker}", 'created_at' => $now, 'updated_at' => $now]);
            DB::table('factor_applicability_rules')->insert(['tenant_id' => $tenant->id, 'factor_definition_id' => $factor, 'justification' => "Regla {$marker}", 'created_at' => $now, 'updated_at' => $now]);
            DB::table('factor_evaluations')->insert(['tenant_id' => $tenant->id, 'factor_definition_id' => $factor, 'horizon_days' => 30, 'cutoff' => '2026-10-01', 'holdout_untouched' => true, 'decision' => 'inconclusive', 'notes' => "Evaluación {$marker}", 'created_at' => $now]);
        }
        $publicVersion = DB::table('source_versions')->insertGetId(['tenant_id' => null, 'source_key' => 'publica', 'version_label' => 'PUBLICA-NO-EXPORTAR', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('external_events')->insert(['tenant_id' => null, 'family' => 'weather', 'event_type' => 'storm', 'title' => 'EVENTO-PUBLICO-NO-EXPORTAR', 'status' => 'VERIFIED', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('taxonomy_nodes')->insert(['tenant_id' => null, 'level' => 'sector', 'code' => 'publico', 'name' => 'NODO-PUBLICO-NO-EXPORTAR', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->assertGreaterThan(0, $publicVersion);

        [$files, $summary] = $this->exportOf($a, $ownerA);

        foreach (['factor_definitions', 'source_versions', 'external_events', 'taxonomy_nodes', 'product_taxonomy_assignments', 'tenant_sector_profiles', 'factor_applicability_rules', 'factor_evaluations'] as $table) {
            $this->assertArrayHasKey("{$table}.csv", $files, "Falta {$table}.csv");
            $this->assertSame(1, $summary[$table], "Solo la fila de la empresa en {$table}: el catálogo público (17 familias) no es dato de la empresa.");
            $this->assertCount(1, $this->csv($files["{$table}.csv"]), $table);
        }
        $everything = implode("\n", $files);
        $this->assertStringNotContainsString('MARCADOR-B', $everything);
        foreach (['PUBLICA-NO-EXPORTAR', 'EVENTO-PUBLICO-NO-EXPORTAR', 'NODO-PUBLICO-NO-EXPORTAR', 'sales_history.units_sold'] as $publicMarker) {
            $this->assertStringNotContainsString($publicMarker, $everything, "El catálogo público no se exporta como dato de la empresa: {$publicMarker}");
        }
        $events = $this->csv($files['external_events.csv']);
        $this->assertSame((string) $ownerA->id, $events[0]['proposed_by'], 'El proponente del evento se exporta.');
        $this->assertSame('Nota MARCADOR-A', $events[0]['review_note']);
    }

    public function test_shared_forecast_grants_are_exported_to_each_party_without_the_data_or_identity_of_the_other(): void
    {
        Storage::fake('local');
        [$issuer, $issuerOwner] = $this->createTenantWithUser(TenantRole::Owner);
        [$recipient, $recipientOwner] = $this->createTenantWithUser(TenantRole::Owner);
        [$third, $thirdOwner] = $this->createTenantWithUser(TenantRole::Owner);
        $product = $this->insertProduct($issuer, 'EMISOR-1', 'Producto del emisor');
        $now = now();
        $grant = fn (string $status, string $purpose, ?int $recipientId = null) => (int) DB::table('shared_forecast_grants')->insertGetId([
            'issuer_tenant_id' => $issuer->id, 'recipient_tenant_id' => $recipientId ?? $recipient->id, 'scope' => 'product', 'subjects' => json_encode([$product]), 'period_from' => '2026-11-01',
            'period_to' => '2026-11-30', 'granularity' => 'day', 'allowed_content' => json_encode(['point_forecast']), 'forecast_version' => '3', 'issued_at' => $now,
            'expires_at' => $now->copy()->addDays(30), 'purpose' => $purpose, 'status' => $status, 'created_by' => $issuerOwner->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $active = $grant('active', 'PROPOSITO-ACTIVA');
        $draft = $grant('draft', 'PROPOSITO-BORRADOR');
        $toThird = $grant('active', 'PROPOSITO-PARA-TERCERO', $third->id);
        foreach ([[$active, $recipient, $recipientOwner, 'read'], [$active, $third, $thirdOwner, 'denied_scope'], [$active, $recipient, $recipientOwner, 'denied_expired']] as [$g, $t, $u, $action]) {
            DB::table('shared_forecast_grant_accesses')->insert(['grant_id' => $g, 'accessed_by_tenant_id' => $t->id, 'user_id' => $u->id, 'action' => $action, 'accessed_at' => $now]);
        }
        $this->assertGreaterThan(0, $draft + $toThird);

        // La exportación funciona aunque la bandera de concesiones esté apagada (portabilidad de lo que exista).
        config(['intelligence.shared_forecast_grants.enabled' => false]);
        [$issuerFiles, $issuerSummary] = $this->exportOf($issuer, $issuerOwner);
        [$recipientFiles, $recipientSummary] = $this->exportOf($recipient, $recipientOwner);
        [$thirdFiles, $thirdSummary] = $this->exportOf($third, $thirdOwner);

        // EMISOR: todo el contrato de lo que concedió (3 concesiones, incl. el borrador), con el receptor por UUID y su propio usuario creador.
        $issued = $this->csv($issuerFiles['shared_forecast_grants_issued.csv']);
        $this->assertCount(3, $issued);
        $this->assertSame(3, $issuerSummary['shared_forecast_grants_issued']);
        $byPurpose = collect($issued)->keyBy('purpose');
        $this->assertSame($recipient->uuid, $byPurpose['PROPOSITO-ACTIVA']['recipient_uuid']);
        $this->assertSame($third->uuid, $byPurpose['PROPOSITO-PARA-TERCERO']['recipient_uuid']);
        $this->assertSame("[{$product}]", $byPurpose['PROPOSITO-ACTIVA']['subjects']);
        $this->assertSame((string) $issuerOwner->id, $byPurpose['PROPOSITO-ACTIVA']['created_by']);
        $this->assertArrayNotHasKey('recipient_tenant_id', $issued[0], 'El receptor se identifica por UUID, no por su identificador interno.');
        // Bitácora sobre sus concesiones: quién (recipient / other_company), nunca el usuario ni la empresa que accedió.
        $onIssued = $this->csv($issuerFiles['shared_forecast_grant_accesses_on_issued.csv']);
        $this->assertCount(3, $onIssued);
        $this->assertEqualsCanonicalizing(['recipient', 'other_company', 'recipient'], array_column($onIssued, 'actor'));
        $this->assertSame(['id', 'grant_id', 'actor', 'action', 'accessed_at'], array_keys($onIssued[0]));
        $this->assertSame(0, $issuerSummary['shared_forecast_grants_received']);
        $this->assertCount(0, $this->csv($issuerFiles['shared_forecast_grant_accesses_made.csv']));

        // RECEPTOR: solo la concesión activa recibida (sin borradores ni las de otros), con el emisor por UUID, el NÚMERO de elementos y sin identificadores del emisor.
        $received = $this->csv($recipientFiles['shared_forecast_grants_received.csv']);
        $this->assertCount(1, $received);
        $this->assertSame('PROPOSITO-ACTIVA', $received[0]['purpose']);
        $this->assertSame($issuer->uuid, $received[0]['issuer_uuid']);
        $this->assertSame('1', $received[0]['subjects_count']);
        foreach (['subjects', 'created_by', 'revoked_by', 'issuer_tenant_id', 'recipient_tenant_id'] as $internal) {
            $this->assertArrayNotHasKey($internal, $received[0], "El receptor no recibe «{$internal}» del emisor.");
        }
        $everythingRecipient = implode("\n", $recipientFiles);
        $this->assertStringNotContainsString('PROPOSITO-BORRADOR', $everythingRecipient);
        $this->assertStringNotContainsString('PROPOSITO-PARA-TERCERO', $everythingRecipient);
        $this->assertStringNotContainsString('EMISOR-1', $everythingRecipient);
        $this->assertStringNotContainsString('Producto del emisor', $everythingRecipient);
        // Sus propios accesos (con sus usuarios), no los del tercero.
        $made = $this->csv($recipientFiles['shared_forecast_grant_accesses_made.csv']);
        $this->assertSame(['read', 'denied_expired'], array_column($made, 'action'));
        $this->assertSame([(string) $recipientOwner->id, (string) $recipientOwner->id], array_column($made, 'user_id'));
        $this->assertSame(0, $recipientSummary['shared_forecast_grants_issued']);
        $this->assertSame(0, $recipientSummary['shared_forecast_grant_accesses_on_issued']);

        // TERCERO (también receptor de otra concesión): ve solo la suya y SU intento denegado; nada de la concesión ajena.
        $thirdReceived = $this->csv($thirdFiles['shared_forecast_grants_received.csv']);
        $this->assertSame(['PROPOSITO-PARA-TERCERO'], array_column($thirdReceived, 'purpose'));
        $thirdMade = $this->csv($thirdFiles['shared_forecast_grant_accesses_made.csv']);
        $this->assertSame(['denied_scope'], array_column($thirdMade, 'action'));
        // El intento recayó en una concesión de la que el tercero NO es parte: se conserva la acción y el momento, pero no el id de ese contrato ajeno.
        $this->assertSame([''], array_column($thirdMade, 'grant_id'), 'El tercero no recibe el identificador de una concesión ajena.');
        $this->assertNotSame('', $made[0]['grant_id'], 'El receptor sí conoce el id de la concesión de la que es parte.');
        $this->assertStringNotContainsString('PROPOSITO-ACTIVA', implode("\n", $thirdFiles));
        $this->assertStringContainsString('shared_forecast_grants_', $recipientFiles['LEEME.txt']);
    }

    public function test_the_existing_classification_still_covers_every_tenant_table(): void
    {
        // Las tablas con tenant_id de W2-F ya estaban clasificadas por el contrato; las concesiones (sin tenant_id) NO están en TABLES a propósito.
        foreach (['factor_definitions', 'source_versions', 'external_events', 'taxonomy_nodes', 'product_taxonomy_assignments', 'tenant_sector_profiles', 'factor_applicability_rules', 'factor_evaluations'] as $table) {
            $this->assertArrayHasKey($table, TenantDataExporter::TABLES);
        }
        $this->assertArrayNotHasKey('shared_forecast_grants', TenantDataExporter::TABLES);
        $this->assertArrayNotHasKey('shared_forecast_grant_accesses', TenantDataExporter::TABLES);
        $this->assertContains('proposed_by', TenantDataExporter::TABLES['external_events']);
        $this->assertContains('review_note', TenantDataExporter::TABLES['external_events']);
    }
}
