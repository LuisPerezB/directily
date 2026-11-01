<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Quality\AutoFixDetector;
use App\Services\DataCore\Quality\FindingCollector;
use App\Services\DataCore\Sync\CanonicalStager;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Sales\SalesDocumentAssembler;
use App\Services\Sales\SalesLineNormalizer;
use App\Support\Privacy\CustomerIdentity;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * Endurecimiento tras la revisión adversarial de W2-A: valores propuestos de las correcciones automáticas iguales a lo que guarda el importador,
 * PII fuera de los hallazgos y del payload, huella de documento invariante a la forma del código de cliente (sin revisión fantasma), cliente con
 * alias respetado al liberar, huella HMAC fuera de la API, primera fila del documento como linaje, reprocesamiento seguro (`--force`) y marcado.
 */
final class IngestionHardeningTest extends TestCase
{
    use BuildsImportFiles, IngestsSales, InteractsWithTenants, RefreshDatabase;

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

    // ── 3. El valor propuesto de la corrección automática es el que guarda el importador ──

    public function test_the_proposed_number_is_what_the_importer_stores_and_the_ambiguous_case_is_a_warning(): void
    {
        $job = $this->importSales([
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '1', '1,234.56'),   // miles con coma y decimal con punto
            $this->salesRow('F-2', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '1,234', '1'),       // AMBIGUA: ¿1,234 o 1234?
            $this->salesRow('F-3', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '1', '8,50'),        // coma decimal inequívoca
        ], ['skip_invalid_rows' => true]);
        $report = $this->qualityReport($job);
        $issues = $this->findings($report->id);

        $safe = $issues->where('code', 'decimal_separator_normalized')->where('field', 'unit_price')->first();
        $this->assertSame('AUTO_FIX_SAFE', $safe->dq_class);
        $this->assertStringContainsString('2 valores', $safe->reason, '«1,234.56» y «8,50»');
        $ambiguous = $issues->where('code', 'decimal_separator_ambiguous')->first();
        $this->assertSame(['WARNING', 'quantity', '1,234', '1234', true], [$ambiguous->dq_class, $ambiguous->field, $ambiguous->original_value, $ambiguous->proposed_value, (bool) $ambiguous->applied], 'la convención (miles) SE aplicó: por eso es una advertencia y no una corrección segura');
        $this->assertStringContainsString('1,234', $ambiguous->reason);

        // Lo propuesto coincide con lo que realmente guardó el importador (misma función: ValueParser::number).
        $parser = new ValueParser;
        $this->assertSame(1234.56, $parser->number('1,234.56'));
        $this->assertSame(1234.0, $parser->number('1,234'));
        $this->assertSame(-1.5, $parser->number('(1,5)'));
        $this->assertEquals(1234.56, (float) DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('s.document_number', 'F-1')->value('i.unit_price'));
        $this->assertEquals(1234.0, (float) DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('s.document_number', 'F-2')->value('i.quantity'));
        $this->assertEquals(8.5, (float) DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('s.document_number', 'F-3')->value('i.unit_price'));
    }

    public function test_the_detector_proposes_exactly_the_importer_value_for_each_documented_case(): void
    {
        $detector = new AutoFixDetector;
        $types = ['tax' => 'number', 'quantity' => 'number'];
        $cases = [
            ['1,234', 'quantity', 'decimal_separator_ambiguous', '1234'],
            ['1,234.56', 'quantity', 'decimal_separator_normalized', '1234.56'],
            ['(1,5)', 'tax', 'decimal_separator_normalized', '-1.5'],
            ['8,50', 'quantity', 'decimal_separator_normalized', '8.5'],
            ['1,234,567', 'quantity', 'decimal_separator_normalized', '1234567'],
            ['1.234,56', 'quantity', 'decimal_separator_normalized', '1234.56'],
        ];
        foreach ($cases as [$raw, $field, $code, $proposed]) {
            $found = $detector->detect([$field => $raw], $types, false, new ValueParser);
            $this->assertSame([[$code, $field, $raw, $proposed]], $found, $raw);
        }
        // Con el separador decimal declarado, «1,234» es inequívoco: 1,234 (y se propone lo que el importador guardará con esa opción).
        $declared = new ValueParser('America/Guayaquil', ['decimal_separator' => ',']);
        $this->assertSame([['decimal_separator_normalized', 'quantity', '1,234', '1.234']], $detector->detect(['quantity' => '1,234'], $types, false, $declared));
        $this->assertSame(1.234, $declared->number('1,234'));
        $this->assertSame([], $detector->detect(['quantity' => '1234'], $types, false, new ValueParser), 'sin coma no hay corrección');
    }

    // ── 4. PII fuera de los hallazgos y del payload ──

    public function test_an_identification_in_a_mismapped_column_never_reaches_the_findings_and_the_payload_masks_free_text(): void
    {
        $ids = ['1710034065', '1710034065001'];
        $rows = [];
        foreach ($ids as $i => $id) {
            $rows[] = $this->salesRow("Z-{$i}", $id, $id, '2026-01-05 10:00:00', '001', 'CEM-50', '1', '8.50', 'CI '.$id); // cédula como tipo, como estado y en un código libre
        }
        $job = $this->importSales([...$rows, $this->salesRow('F-OK')], ['skip_invalid_rows' => true]);

        $report = $this->qualityReport($job);
        $issues = $this->findings($report->id);
        $this->assertGreaterThanOrEqual(4, $issues->whereIn('code', ['unknown_document_type', 'unknown_document_status'])->count());
        foreach ($issues as $issue) {
            foreach (['original_value', 'proposed_value', 'reason', 'record_ref'] as $column) {
                foreach ($ids as $id) {
                    $this->assertStringNotContainsString($id, (string) $issue->{$column}, "{$column} de {$issue->code}");
                }
            }
        }
        $type = $issues->firstWhere('code', 'unknown_document_type');
        $this->assertSame('[ID]', $type->original_value);
        $this->assertStringContainsString('[ID]', $type->reason);
        // Las agregaciones y los avisos de resumen también pasan por el enmascarado.
        foreach (DB::table('data_quality_reports')->get() as $r) {
            foreach ($ids as $id) {
                $this->assertStringNotContainsString($id, (string) $r->issue_counts.(string) $r->metrics);
            }
        }

        // Payload: el texto libre se enmascara; el código de cliente de FORMATO LIBRE queda como en `customers.external_code` (excepción documentada).
        $payload = $this->decode(DB::table('quarantine_records')->where('record_key', 'like', 'unknown|Z-0')->value('payload'));
        $fields = $payload['rows'][0]['fields'];
        $this->assertSame(['[ID]', '[ID]'], [$fields['document_type'], $fields['status']]);
        $this->assertSame('CI 1710034065', $fields['customer_code'], 'excepción: un código libre no es una cédula para CustomerIdentity y así se guarda en customers');

        // Reglas de enmascarado: solo los códigos reales (documento, SKU, sucursal) quedan exentos.
        $this->assertSame('0000012345', FindingCollector::safeValue('document_number', '0000012345'));
        $this->assertSame('1710034065', FindingCollector::safeValue('product_sku', '1710034065'));
        $this->assertSame('[ID]', FindingCollector::safeValue('document_type', '1710034065'));
        $this->assertSame('[ID]', FindingCollector::safeValue('status', '1710034065'));
        $this->assertSame('El número 0000012345 se repite', FindingCollector::safeReason('document_number', 'El número 0000012345 se repite'));
        $this->assertSame("Estado no reconocido ('[ID]')", FindingCollector::safeReason('status', "Estado no reconocido ('1710034065')"));
    }

    // ── LOW 8: la huella HMAC del comprador no sale por la API ──

    public function test_the_api_never_returns_the_buyer_hmac_but_the_record_keeps_it_to_create_the_buyer_on_release(): void
    {
        $this->importSales([$this->salesRow('F-0'), $this->salesRow('COT-7', 'Cotización', '', '2026-01-07 10:00:00', '001', 'CEM-50', '3', '8.50', '1710034065')], ['skip_invalid_rows' => true]);
        $record = DB::table('quarantine_records')->where('record_key', 'unknown|COT-7')->first();
        $external = CustomerIdentity::fromCode('1710034065')['external_code'];
        $stored = $this->decode($record->payload)['customers'];
        $this->assertSame(CustomerIdentity::hmac('1710034065'), $stored[$external]['tax_id_hash'], 'la huella queda en el registro (para crear al comprador al liberar)');

        $body = $this->getJson("/api/v1/data-quality/quarantine/{$record->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('tax_id_hash', $body);
        $this->assertStringNotContainsString(CustomerIdentity::hmac('1710034065'), $body);
        $this->assertSame(['customer_type' => 'person'], $this->getJson("/api/v1/data-quality/quarantine/{$record->id}")->json("data.payload.customers.{$external}"));

        $this->postJson("/api/v1/data-quality/quarantine/{$record->id}/release", ['value_maps' => ['document_type' => ['Cotización' => 'Otro']]])->assertOk();
        $this->assertSame(CustomerIdentity::hmac('1710034065'), DB::table('customers')->where('external_code', $external)->value('tax_id_hash'));
    }

    // ── LOW 10: un cliente con alias vigente no se duplica al liberar ──

    public function test_release_respects_a_customer_alias_and_does_not_create_the_aliased_identity(): void
    {
        $external = CustomerIdentity::fromCode('1710034065')['external_code'];
        $canonical = DB::table('customers')->insertGetId(['tenant_id' => $this->tenant->id, 'external_code' => 'CANON-1', 'customer_type' => 'person', 'is_generic' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_aliases')->insert(['tenant_id' => $this->tenant->id, 'customer_id' => $canonical, 'source_code' => $external, 'kind' => 'alias', 'created_at' => now(), 'updated_at' => now()]);
        $this->importSales([$this->salesRow('F-0'), $this->salesRow('COT-7', 'Cotización', '', '2026-01-07 10:00:00', '001', 'CEM-50', '3', '8.50', '1710034065')], ['skip_invalid_rows' => true]);

        $id = DB::table('quarantine_records')->where('record_key', 'unknown|COT-7')->value('id');
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/release", ['value_maps' => ['document_type' => ['Cotización' => 'Otro']]])->assertOk();

        $this->assertSame(['CANON-1'], DB::table('customers')->orderBy('id')->pluck('external_code')->all(), 'no se creó otra identidad «id:…» para el código con alias');
        $this->assertSame($canonical, (int) DB::table('sales')->where('document_number', 'COT-7')->value('customer_id'));
    }

    // ── 5. Huella de documento invariante a la forma del código de cliente ──

    public function test_the_document_hash_is_the_same_for_the_raw_code_and_the_stored_form_and_legacy_hashes_stay_unchanged(): void
    {
        $doc = function (string $customer): array {
            $raw = ['document_number' => 'F-1', 'issued_at' => '2026-01-05 10:00:00', 'branch_code' => '001', 'product_sku' => 'A', 'quantity' => '1', 'unit_price' => '5', 'customer_code' => $customer];
            [$line] = (new SalesLineNormalizer(new ValueParser, 'USD', 'America/Guayaquil'))->normalize($raw);
            $assembler = new SalesDocumentAssembler;
            $assembler->add($line);

            return $assembler->drain()[0];
        };
        $external = CustomerIdentity::fromCode('1710034065')['external_code'];
        $raw = $doc('1710034065');
        $stored = $doc($external);
        $this->assertSame($raw['source_hash'], $stored['source_hash'], 'mismo documento, misma huella: llegue la cédula o ya su forma guardada');
        $this->assertArrayHasKey('legacy_source_hash', $raw);
        $this->assertNotSame($raw['source_hash'], $raw['legacy_source_hash'], 'la huella con el código CRUDO (la de los documentos escritos antes) se conserva aparte');
        $this->assertArrayNotHasKey('legacy_source_hash', $stored);
        $this->assertArrayNotHasKey('legacy_source_hash', $doc('CLI-77'), 'un código que no es cédula/RUC no cambia de forma: una sola huella');
        $this->assertSame($doc('CLI-77')['source_hash'], $doc('CLI-77')['source_hash'], 'determinista');
    }

    public function test_a_released_document_resent_by_the_source_is_unchanged_and_a_legacy_hash_is_accepted(): void
    {
        $this->importSales([$this->salesRow('F-0'), $this->salesRow('COT-7', 'Cotización', '', '2026-01-07 10:00:00', '001', 'CEM-50', '3', '8.50', '1710034065')], ['skip_invalid_rows' => true]);
        $id = DB::table('quarantine_records')->where('record_key', 'unknown|COT-7')->value('id');
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/release", ['value_maps' => ['document_type' => ['Cotización' => 'Otro']]])->assertOk();
        $this->assertSame(0, (int) DB::table('sales')->where('document_number', 'COT-7')->value('current_revision'));

        // El ERP reenvía el MISMO documento (ahora ya como «Otro», con la cédula): antes archivaba una revisión fantasma; ahora no cambia.
        $resend = [$this->salesRow('COT-7', 'Otro', '', '2026-01-07 10:00:00', '001', 'CEM-50', '3', '8.50', '1710034065')];
        $job = $this->importSales($resend);
        $this->assertSame([0, 1, 0], [$job->stats['documents_updated'], $job->stats['documents_unchanged'], $job->stats['revisions_archived']]);
        $this->assertSame(0, DB::table('sales_document_revisions')->count());

        // Un documento guardado por una versión ANTERIOR (huella con el código crudo) tampoco se reescribe al reenviarlo.
        $sale = DB::table('sales')->where('document_number', 'COT-7')->first();
        $raw = ['document_number' => 'COT-7', 'document_type' => 'Otro', 'issued_at' => '2026-01-07 10:00:00', 'branch_code' => '001', 'product_sku' => 'CEM-50', 'quantity' => '3', 'unit_price' => '8.50', 'customer_code' => '1710034065', 'channel' => 'Local'];
        [$line] = (new SalesLineNormalizer(new ValueParser, 'USD', 'America/Guayaquil'))->normalize($raw);
        $assembler = new SalesDocumentAssembler;
        $assembler->add($line);
        $doc = $assembler->drain()[0];
        $this->assertSame($sale->source_hash, $doc['source_hash'], 'la huella que calcula la prueba es la que guarda el importador');
        DB::table('sales')->where('id', $sale->id)->update(['source_hash' => $doc['legacy_source_hash']]);
        $again = $this->importSales($resend);
        $this->assertSame([0, 1], [$again->stats['documents_updated'], $again->stats['documents_unchanged']]);
        $this->assertSame($doc['legacy_source_hash'], DB::table('sales')->where('id', $sale->id)->value('source_hash'), 'sin cambios: no se reescribe');
    }

    // ── 5b. source_row = primera fila del documento en el origen ──

    public function test_source_row_is_the_first_row_of_the_document_even_when_it_is_a_zero_quantity_line(): void
    {
        $this->importSales([
            $this->salesRow('Z-1', 'Factura', '', '2026-01-08 10:00:00', '001', 'CEM-50', '0', '8.50'),
            $this->salesRow('Z-1', 'Factura', '', '2026-01-08 10:00:00', '001', 'VAR-12', '2', '8.50'),
            $this->salesRow('Z-2', 'Factura', '', '2026-01-08 10:00:00', '001', 'VAR-12', '1', '8.50'),
        ]);
        $this->assertSame([1, 3], DB::table('sales')->orderBy('document_number')->pluck('source_row')->map(fn ($v) => (int) $v)->all());
    }

    // ── 6. Reprocesar un original viejo sin orden de fuente avisa y exige --force ──

    public function test_reprocessing_an_old_original_that_would_revert_newer_data_is_refused_unless_forced(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        $old = $this->importSales([$this->salesRow('X-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '2', '8.50')]);
        $this->travelTo('2026-06-11 12:00:00');
        $new = $this->importSales([$this->salesRow('X-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '9', '8.50')]);
        $quantity = fn () => (float) DB::table('sale_items')->value('quantity');
        $this->assertSame(9.0, $quantity());
        $jobs = DB::table('import_jobs')->count();
        $revisions = DB::table('sales_document_revisions')->count();

        $this->travelTo('2026-06-12 12:00:00');
        $this->assertSame(1, Artisan::call('predictily:bronze-reprocess', ['id' => $old->bronze_object_id]));
        $output = Artisan::output();
        $this->assertStringContainsString('--force', $output);
        $this->assertStringContainsString('DESPUÉS', $output);
        $this->assertSame(9.0, $quantity(), 'sin --force no se revierte nada');
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame($revisions, DB::table('sales_document_revisions')->count());
        $this->assertSame($jobs + 1, DB::table('import_jobs')->count());
        $this->assertSame('rejected', DB::table('import_jobs')->orderByDesc('id')->value('status'), 'la proyección rechazada no deja una importación colgada');

        // El original MÁS NUEVO se reprocesa sin avisos (coincide con lo vigente).
        $this->assertSame(0, Artisan::call('predictily:bronze-reprocess', ['id' => $new->bronze_object_id, '--json' => true]));
        $this->assertSame(0, json_decode(trim(Artisan::output()), true)['overwrote_newer_documents']);

        // --force lo confirma y la salida informa cuántos documentos más nuevos sobrescribió.
        $this->assertSame(0, Artisan::call('predictily:bronze-reprocess', ['id' => $old->bronze_object_id, '--force' => true, '--json' => true]));
        $result = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $result['overwrote_newer_documents']);
        $this->assertSame(1, $result['documents']['updated']);
        $this->assertSame(2.0, $quantity());
        $this->assertSame(0, Artisan::call('predictily:bronze-reprocess', ['id' => $old->bronze_object_id, '--force' => true]));
        $this->assertStringContainsString('sin cambios 1', Artisan::output());
    }

    public function test_the_default_output_warns_when_force_overwrote_newer_documents_and_batches_follow_the_same_rule(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        $doc = fn (float $qty) => ['document_number' => 'B-1', 'document_type' => 'factura', 'issued_at' => '2026-01-07T10:00:00-05:00', 'branch_code' => '001',
            'items' => [['product_sku' => 'CEM-50', 'product_name' => 'Cemento', 'quantity' => $qty, 'unit_price' => 8.5]]];
        $oldObject = (int) $this->postJson('/api/v1/sales/batch', ['documents' => [$doc(2)]])->assertOk()->json('data.bronze_object_id');
        $this->travelTo('2026-06-11 12:00:00');
        $this->postJson('/api/v1/sales/batch', ['documents' => [$doc(9)]])->assertOk()->assertJsonPath('data.updated', 1);

        $this->travelTo('2026-06-12 12:00:00');
        $this->assertSame(1, Artisan::call('predictily:bronze-reprocess', ['id' => $oldObject]));
        $this->assertStringContainsString('--force', Artisan::output());
        $this->assertSame(9.0, (float) DB::table('sale_items')->value('quantity'));

        $this->assertSame(0, Artisan::call('predictily:bronze-reprocess', ['id' => $oldObject, '--force' => true]), Artisan::output());
        $this->assertSame(2.0, (float) DB::table('sale_items')->value('quantity'));
    }

    // ── 13. Reprocesos marcados y fecha cruda con el mismo formato que la extracción ──

    public function test_reprocess_reports_are_marked_so_gates_do_not_count_the_same_rows_twice(): void
    {
        $job = $this->importSales([$this->salesRow('F-1'), $this->salesRow('F-2', 'Factura', '', 'no-fecha')], ['skip_invalid_rows' => true]);
        $this->assertSame(0, Artisan::call('predictily:bronze-reprocess', ['id' => $job->bronze_object_id, '--json' => true]));
        $reprocessJob = (int) json_decode(trim(Artisan::output()), true)['import_job_id'];

        $original = DB::table('data_quality_reports')->where('import_job_id', $job->id)->first();
        $again = DB::table('data_quality_reports')->where('import_job_id', $reprocessJob)->first();
        $this->assertSame('sales', $original->dataset);
        $this->assertSame('sales_reprocess', $again->dataset);
        $this->assertSame($job->bronze_object_id, $this->decode($again->metrics)['reprocess_of_bronze_object_id']);
        $this->assertSame($job->bronze_object_id, $this->decode($again->summary)['reprocess_of_bronze_object_id']);
        // La suma por dataset que usan los gates (COALESCE(dataset, entidad) = 'sales') cuenta las filas de la carga UNA vez.
        $rows = (int) DB::selectOne("SELECT SUM((r.summary->>'rows_total')::int) AS n FROM data_quality_reports r LEFT JOIN import_jobs j ON j.tenant_id = r.tenant_id AND j.id = r.import_job_id WHERE r.tenant_id = ? AND COALESCE(r.dataset, j.entity_type) = 'sales' AND r.summary->>'rows_total' IS NOT NULL", [$this->tenant->id])->n;
        $this->assertSame(2, $rows);
        // Su reconciliación no es una carga nueva: sin rango de fechas (la reconciliación por ventana no la suma) y marcada.
        $recon = DB::table('reconciliation_reports')->where('import_job_id', $reprocessJob)->first();
        $this->assertNull($recon->window_from);
        $this->assertSame($job->bronze_object_id, $this->decode($recon->layers)['reprocess_of_bronze_object_id']);
        $this->assertNotNull(DB::table('reconciliation_reports')->where('import_job_id', $job->id)->value('window_from'));
    }

    public function test_the_raw_extraction_line_writes_dates_in_the_same_naive_format_the_staging_uses(): void
    {
        $stager = app(CanonicalStager::class);
        $line = new ReflectionMethod($stager, 'rawLine');
        $row = ['fecha' => new DateTimeImmutable('2026-01-05 09:15:00', new \DateTimeZone('UTC')), 'cant' => '10.0000', 'texto' => 'á'];
        $decoded = json_decode((string) $line->invoke($stager, $row, ['fecha', 'cant', 'texto']), true);
        $this->assertSame('2026-01-05 09:15:00', $decoded['fecha'], 'sin desplazamiento: igual que `format()` al preparar el CSV (la zona de la fuente la aplica el reproceso igual que la extracción)');
        $this->assertSame(['10.0000', 'á'], [$decoded['cant'], $decoded['texto']]);
    }
}
