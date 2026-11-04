<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\ImportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Sales\SalesLineNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Sales\Support\ReadsSalesVersions;
use Tests\TestCase;

/**
 * Endurecimiento de la escritura temporal de ventas (ADR-V25-23), hallazgos menores de la revisión adversarial de W1-A1:
 *  - `unchanged_mode_mismatch`: re-declarar el modo sobre documentos SIN cambios es un no-op, ahora visible;
 *  - cota ÚNICA de `source_recorded_at` (ahora + tolerancia, 5 minutos por defecto) en normalizador, importaciones y /sales/batch,
 *    con `future_recorded_at` contado en los tres caminos;
 *  - una fila de tipo no reconocido sigue siendo error, el documento reconocido con el mismo número SE ESCRIBE y recibe el aviso
 *    `document_number_shared_with_unknown_type` (la numeración suele ser por tipo o serie en los ERP);
 *  - una actualización sin `source_recorded_at` no borra la marca almacenada.
 */
final class SalesWriterHardeningTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, ReadsSalesVersions, RefreshDatabase;

    /** 2026-06-10 07:00 en Guayaquil (UTC-5). */
    private const T0 = '2026-06-10 12:00:00';

    private const T1 = '2026-06-12 15:30:00';

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    /** @return array<string, mixed> */
    private function doc(string $number = 'F-1', float $qty = 2, ?string $recordedAt = null, array $extra = []): array
    {
        return array_merge([
            'document_number' => $number, 'issued_at' => '2026-05-04T10:00:00-05:00', 'branch_code' => '001',
            'items' => [['product_sku' => 'A', 'product_name' => 'Producto A', 'quantity' => $qty, 'unit_price' => 5]],
        ], $recordedAt !== null ? ['source_recorded_at' => $recordedAt] : [], $extra);
    }

    /** @param list<array<string, mixed>> $documents */
    private function batch(array $documents, ?string $mode = null): TestResponse
    {
        return $this->postJson('/api/v1/sales/batch', ['documents' => $documents] + ($mode !== null ? ['ingestion_mode' => $mode] : []));
    }

    // ───────────────────────── unchanged_mode_mismatch (hallazgo 3) ─────────────────────────

    public function test_redeclaring_the_mode_over_unchanged_documents_is_a_visible_no_op_in_the_batch(): void
    {
        $this->travelTo(self::T0);
        $this->batch([$this->doc('F-1'), $this->doc('F-2')], 'backfill')->assertOk()->assertJsonPath('data.created', 2)->assertJsonPath('data.unchanged_mode_mismatch', 0);
        $before = $this->saleRow($this->tenant, 'F-1');

        // Mismo contenido declarado incremental: no se reescribe (contrato de escritura intacto) pero se cuenta y se informa.
        $this->travelTo(self::T1);
        $this->batch([$this->doc('F-1'), $this->doc('F-2')], 'incremental')->assertOk()
            ->assertJsonPath('data.unchanged', 2)->assertJsonPath('data.unchanged_mode_mismatch', 2)
            ->assertJsonPath('data.updated', 0)->assertJsonPath('data.revisions_archived', 0)->assertJsonPath('data.ingestion_mode', 'incremental');
        $after = $this->saleRow($this->tenant, 'F-1');
        $this->assertEquals($before, $after, 'el documento sin cambios no se toca: conserva su modo y su ventana de conocimiento');
        $this->assertSame('backfill', $after->ingestion_mode);
        $this->assertNull($after->version_known_from);

        // Mismo modo: sin discrepancia. Un documento nuevo o actualizado nunca cuenta como discrepancia.
        $this->batch([$this->doc('F-1'), $this->doc('F-2', 9), $this->doc('F-3')], 'backfill')->assertOk()
            ->assertJsonPath('data.unchanged', 1)->assertJsonPath('data.updated', 1)->assertJsonPath('data.created', 1)->assertJsonPath('data.unchanged_mode_mismatch', 0);
    }

    public function test_the_import_projects_counts_and_reports_unchanged_mode_mismatch(): void
    {
        $headers = self::SALES_HEADERS;
        $rows = fn () => [
            ['F-1', 'Factura', '', '2026-05-04 10:00:00', '001', 'CEM-50', 'Cemento 50kg', 'Cemento', '2', '8.50', '0', '0', '', '', '', 'Local'],
            ['F-2', 'Factura', '', '2026-05-04 10:00:00', '001', 'CEM-50', 'Cemento 50kg', 'Cemento', '3', '8.50', '0', '0', '', '', '', 'Local'],
        ];
        $run = function (string $mode) use ($headers, $rows): ImportJob {
            $job = $this->uploadAndValidate($this->tenant, $this->csv($headers, $rows()), options: ['ingestion_mode' => $mode]);
            $validated = $job->stats;
            $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
            $done = $this->withinTenant($this->tenant, fn () => ImportJob::query()->findOrFail($job->id));
            $this->assertSame($validated['unchanged_mode_mismatch'], $done->stats['unchanged_mode_mismatch'], 'la validación proyecta lo que hará la confirmación');

            return $done;
        };

        $this->travelTo(self::T0);
        $first = $run('backfill');
        $this->assertSame(0, $first->stats['unchanged_mode_mismatch']);

        $this->travelTo(self::T1);
        $same = $run('backfill');
        $this->assertSame(2, $same->stats['documents_unchanged']);
        $this->assertSame(0, $same->stats['unchanged_mode_mismatch']);

        $redeclared = $run('incremental');
        $this->assertSame(2, $redeclared->stats['documents_unchanged']);
        $this->assertSame(2, $redeclared->stats['unchanged_mode_mismatch']);
        $report = $this->getJson("/api/v1/imports/{$redeclared->id}")->assertOk()->json('data.quality_report');
        $this->assertSame(2, $report['summary']['unchanged_mode_mismatch']);
        $issue = collect($report['issues'])->firstWhere('code', 'unchanged_mode_mismatch');
        $this->assertSame(['info', 2], [$issue['severity'], $issue['count']]);
        $this->assertSame('backfill', $this->saleRow($this->tenant, 'F-1')->ingestion_mode, 'el no-op no cambia el modo almacenado');
    }

    // ───────────────────────── cota única de source_recorded_at (hallazgo 4) ─────────────────────────

    public function test_normalizer_bound_is_now_plus_a_configurable_tolerance(): void
    {
        $now = CarbonImmutable::parse('2026-06-10 12:00:00', 'UTC');
        $line = function (string $recorded, ?int $tolerance = null) use ($now): array {
            $normalizer = new SalesLineNormalizer(new ValueParser('America/Guayaquil'), 'USD', 'America/Guayaquil', $now, $tolerance);

            return $normalizer->normalize([
                'document_number' => 'F-1', 'issued_at' => '2026-06-01 10:00:00', 'branch_code' => '001', 'product_sku' => 'A', 'quantity' => '1', 'unit_price' => '5',
                'source_recorded_at' => $recorded,
            ]);
        };

        // Por defecto 5 minutos: now = 12:00 UTC = 07:00 en Guayaquil.
        [$ok] = $line('2026-06-10 07:05:00');
        $this->assertSame('2026-06-10 12:05:00+00:00', $ok['source_recorded_at'], 'justo en la cota (ahora + 5 min): se acepta');
        [$late, $issues] = $line('2026-06-10 07:05:01');
        $this->assertNull($late['source_recorded_at'], 'un segundo después de la cota: no ordena versiones');
        $this->assertSame(['future_date'], array_map(fn ($i) => $i->code, $issues));
        $this->assertSame('future', SalesLineNormalizer::recordedAtAnomaly($issues[0]));
        // La cota antigua aceptaba hasta el fin de MAÑANA.
        [$tomorrow] = $line('2026-06-11 07:00:00');
        $this->assertNull($tomorrow['source_recorded_at'], 'mañana ya no se acepta');
        // Tolerancia explícita y por configuración.
        $this->assertNotNull($line('2026-06-10 07:30:00', 30)[0]['source_recorded_at']);
        $this->assertNull($line('2026-06-10 07:30:01', 30)[0]['source_recorded_at']);
        $this->assertNull($line('2026-06-10 07:00:01', 0)[0]['source_recorded_at'], 'tolerancia 0: cualquier instante posterior a ahora');
        config(['predictily.imports.source_recorded_at_tolerance_minutes' => 60]);
        $this->assertNotNull($line('2026-06-10 08:00:00')[0]['source_recorded_at']);
        $this->assertNull($line('2026-06-10 08:00:01')[0]['source_recorded_at']);
        // Ilegible: otra anomalía, la misma consecuencia (NULL).
        [$bad, $badIssues] = $line('ayer por la tarde');
        $this->assertNull($bad['source_recorded_at']);
        $this->assertSame('unreadable', SalesLineNormalizer::recordedAtAnomaly($badIssues[0]));
    }

    public function test_batch_counts_future_recorded_at_and_stores_no_order_for_it(): void
    {
        $this->travelTo(self::T0); // 07:00 Guayaquil
        $response = $this->batch([
            $this->doc('F-1', 2, '2026-06-10T07:03:00-05:00'),   // dentro de la tolerancia: se guarda
            $this->doc('F-2', 2, '2026-06-10T07:20:00-05:00'),   // 20 min en el futuro: no ordena versiones
            $this->doc('F-3', 2, '2026-06-11T09:00:00-05:00'),   // mañana (la cota antigua lo aceptaba)
            $this->doc('F-4', 2, 'ayer por la tarde'),           // ilegible
            $this->doc('F-5', 2),                                // sin marca
        ])->assertOk()->assertJsonPath('data.created', 5)->assertJsonPath('data.future_recorded_at', 3)->assertJsonPath('data.recorded_at_unreadable', 1);

        $this->assertSame(3, count(array_filter($response->json('data.warnings'), fn ($w) => ($w['field'] ?? null) === 'source_recorded_at')));
        $this->assertSame('2026-06-10 12:03:00', $this->utc($this->saleRow($this->tenant, 'F-1')->source_recorded_at));
        foreach (['F-2', 'F-3', 'F-4', 'F-5'] as $number) {
            $this->assertNull($this->saleRow($this->tenant, $number)->source_recorded_at, "{$number}: la marca descartada queda NULL (la fila sí se escribe)");
        }
    }

    public function test_a_discarded_future_mark_cannot_make_later_real_versions_stale(): void
    {
        // Con la cota antigua (fin de mañana) la marca de mañana se guardaba y toda versión real posterior parecía «anterior».
        $this->travelTo(self::T0);
        $this->batch([$this->doc('F-1', 2, '2026-06-11T09:00:00-05:00')])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.future_recorded_at', 1);

        $this->travelTo(self::T1);
        $this->batch([$this->doc('F-1', 5, '2026-06-12T09:00:00-05:00')])->assertOk()
            ->assertJsonPath('data.stale_skipped', 0)->assertJsonPath('data.updated', 1)->assertJsonPath('data.updated_without_source_order', 1);
        $this->assertSame('2026-06-12 14:00:00', $this->utc($this->saleRow($this->tenant, 'F-1')->source_recorded_at));
        $this->assertEqualsWithDelta(5.0, (float) DB::table('sale_items')->where('sale_id', $this->saleRow($this->tenant, 'F-1')->id)->value('quantity'), 0.0001);
    }

    public function test_import_counts_future_recorded_at_with_the_same_bound(): void
    {
        $headers = [...self::SALES_HEADERS, 'Modificado en'];
        $row = fn (string $number, string $modified) => [$number, 'Factura', '', '2026-05-04 10:00:00', '001', 'CEM-50', 'Cemento 50kg', 'Cemento', '2', '8.50', '0', '0', '', '', '', 'Local', $modified];
        $this->travelTo(self::T0); // 07:00 Guayaquil
        $rows = [
            $row('F-1', '2026-06-10 07:03:00'), // dentro de la tolerancia
            $row('F-2', '2026-06-10 07:20:00'), // futuro
            $row('F-3', '2026-06-11 09:00:00'), // mañana
            $row('F-4', 'no-es-fecha'),         // ilegible
            $row('F-5', ''),                    // sin marca
        ];
        $job = $this->uploadAndValidate($this->tenant, $this->csv($headers, $rows));
        $this->assertSame(3, $job->stats['future_recorded_at']);
        $this->assertSame(1, $job->stats['recorded_at_unreadable']);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $done = $this->withinTenant($this->tenant, fn () => ImportJob::query()->findOrFail($job->id));
        $this->assertSame(['completed', 3, 1], [$done->status, $done->stats['future_recorded_at'], $done->stats['recorded_at_unreadable']]);

        $this->assertSame('2026-06-10 12:03:00', $this->utc($this->saleRow($this->tenant, 'F-1')->source_recorded_at));
        foreach (['F-2', 'F-3', 'F-4', 'F-5'] as $number) {
            $this->assertNull($this->saleRow($this->tenant, $number)->source_recorded_at, $number);
        }
        $summary = $this->getJson("/api/v1/imports/{$job->id}")->assertOk()->json('data.quality_report.summary');
        $this->assertSame([3, 1], [$summary['future_recorded_at'], $summary['recorded_at_unreadable']]);
    }

    // ───────────────────────── tipo desconocido con número compartido (hallazgo 7) ─────────────────────────

    public function test_a_recognized_document_sharing_its_number_with_an_unknown_type_row_is_written_with_a_warning_in_an_import(): void
    {
        $row = fn (string $number, string $type, string $sku) => [$number, $type, '', '2026-05-04 10:00:00', '001', $sku, 'Producto '.$sku, 'Cat', '2', '8.50', '0', '0', '', '', '', 'Local'];
        $rows = [
            $row('X-1', 'Factura', 'CEM-50'),   // factura X-1, dos líneas
            $row('X-1', 'Factura', 'PIN-01'),
            $row('X-1', 'Proforma', 'VAR-12'),  // proforma X-1: documento DISTINTO (numeración por tipo), tipo no reconocido
            $row('X-2', 'Factura', 'CEM-50'),   // sin relación
        ];
        $job = $this->uploadAndValidate($this->tenant, $this->csv(self::SALES_HEADERS, $rows));
        $this->assertSame(1, $job->stats['rows_error'], 'solo la fila de tipo desconocido');
        $this->assertSame(2, $job->stats['documents_valid'], 'las facturas X-1 y X-2');
        $this->assertSame(1, $job->stats['document_number_shared_with_unknown_type'], 'contado una vez por documento, no por fila');

        $errors = collect($this->getJson("/api/v1/imports/{$job->id}/errors?per_page=100")->assertOk()->json('data'));
        $this->assertSame([['unknown_document_type', 'error']], $errors->where('severity', 'error')->map(fn ($e) => [$e['error_code'], $e['severity']])->values()->all());
        $this->assertSame([['document_number_shared_with_unknown_type', 'warning', 'X-1']], $errors->where('error_code', 'document_number_shared_with_unknown_type')->map(fn ($e) => [$e['error_code'], $e['severity'], $e['raw_value']])->values()->all());

        $this->putJson("/api/v1/imports/{$job->id}/mapping", ['mapping' => $job->suggested_mapping, 'options' => ['skip_invalid_rows' => true]])->assertAccepted();
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $sale = $this->saleRow($this->tenant, 'X-1');
        $this->assertSame('invoice', $sale->document_type);
        $this->assertSame(['CEM-50', 'PIN-01'], DB::table('sale_items as si')->join('products as p', 'p.id', '=', 'si.product_id')->where('si.sale_id', $sale->id)->orderBy('si.line_number')->pluck('p.sku')->all(), 'el documento reconocido conserva solo sus propias líneas');
        $this->assertSame(['X-1', 'X-2'], DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->pluck('document_number')->all());
    }

    public function test_a_recognized_document_sharing_its_number_with_an_unknown_type_document_is_written_with_a_warning_in_a_batch(): void
    {
        $response = $this->batch([
            $this->doc('X-1'),                                                // reconocido (factura)
            $this->doc('X-1', 3, null, ['document_type' => 'cotizacion']),    // mismo número, tipo desconocido
            $this->doc('X-2'),                                                // sin relación
        ])->assertOk()->assertJsonPath('data.created', 2)->assertJsonPath('data.rejected', 1)->assertJsonPath('data.document_number_shared_with_unknown_type', 1);

        $this->assertSame([['index' => 1, 'code' => 'unknown_document_type', 'dq_class' => 'REVIEW_REQUIRED']], array_map(
            fn ($e) => ['index' => $e['index'], 'code' => $e['code'], 'dq_class' => $e['dq_class']], $response->json('data.errors'),
        ), 'solo el documento de tipo desconocido es error');
        $warning = collect($response->json('data.warnings'))->firstWhere('code', 'document_number_shared_with_unknown_type');
        $this->assertSame([0, 'warning', 'document_number'], [$warning['index'], $warning['severity'], $warning['field']], 'aviso sobre el documento reconocido');
        $this->assertSame(['X-1', 'X-2'], DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->pluck('document_number')->all());
        $this->assertSame('invoice', $this->saleRow($this->tenant, 'X-1')->document_type);
        $this->assertEqualsWithDelta(2.0, (float) DB::table('sale_items')->where('sale_id', $this->saleRow($this->tenant, 'X-1')->id)->value('quantity'), 0.0001, 'con las líneas del documento reconocido, no las de la cotización');
    }

    // ───────────────────────── marca almacenada vs. actualización sin marca (hallazgo 8) ─────────────────────────

    public function test_an_update_without_a_source_timestamp_keeps_the_stored_mark_and_is_still_counted(): void
    {
        $this->travelTo(self::T0);
        $this->batch([$this->doc('F-1', 2, '2026-06-01T10:00:00-05:00')])->assertOk()->assertJsonPath('data.created', 1);
        $stored = $this->utc($this->saleRow($this->tenant, 'F-1')->source_recorded_at);
        $this->assertSame('2026-06-01 15:00:00', $stored);

        // Sin marca: gana la última ingestión (se cuenta) pero la marca almacenada NO se borra.
        $this->travelTo(self::T1);
        $this->batch([$this->doc('F-1', 5)])->assertOk()->assertJsonPath('data.updated', 1)->assertJsonPath('data.updated_without_source_order', 1)->assertJsonPath('data.revisions_archived', 1);
        $this->assertSame($stored, $this->utc($this->saleRow($this->tenant, 'F-1')->source_recorded_at), 'la marca almacenada sobrevive a una actualización sin marca');
        $this->assertSame(1, (int) $this->saleRow($this->tenant, 'F-1')->current_revision);
        $this->assertSame($stored, $this->utc($this->headerRevisions($this->tenant, 'F-1')[0]->source_recorded_at), 'y la revisión archivada conserva la suya');

        // La marca conservada sigue ordenando: una versión con marca ANTERIOR a ella se descarta; una posterior se aplica y la reemplaza.
        $this->travelTo('2026-06-13 10:00:00');
        $this->batch([$this->doc('F-1', 8, '2026-05-30T10:00:00-05:00')])->assertOk()->assertJsonPath('data.stale_skipped', 1)->assertJsonPath('data.updated', 0);
        $this->batch([$this->doc('F-1', 9, '2026-06-12T10:00:00-05:00')])->assertOk()->assertJsonPath('data.updated', 1)->assertJsonPath('data.updated_without_source_order', 0);
        $this->assertSame('2026-06-12 15:00:00', $this->utc($this->saleRow($this->tenant, 'F-1')->source_recorded_at));

        // Y una actualización sin marca sobre un documento que nunca tuvo marca la deja en NULL.
        $this->batch([$this->doc('F-2', 1)])->assertOk();
        $this->batch([$this->doc('F-2', 2)])->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertNull($this->saleRow($this->tenant, 'F-2')->source_recorded_at);
    }
}
