<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Quality\DataQualityCatalog;
use App\Services\DataCore\Quality\FindingCollector;
use App\Services\Imports\Support\Issue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * Hallazgos persistidos de la ingestión (ADR-V25-05 y ADR-V25-24; W2-A): cada importación amplía su fila de `data_quality_reports` con
 * dataset, issue_counts por clase, rules_version y metrics, conserva `summary` e `issues` y deja un hallazgo por fila en
 * `data_quality_issues` (clase, código, entidad, referencia sin PII, fila, campo, valor acotado, propuesta, aplicado, motivo), con tope.
 */
final class IngestionFindingsTest extends TestCase
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

    public function test_the_report_is_widened_and_summary_and_issues_are_kept_as_consumers_expect(): void
    {
        $rows = [
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '2', '8.50', '1710034065'),
            $this->salesRow('F-2', 'Factura', 'Anulada', '2026-01-06 10:00:00', '001', 'CEM-50', '-1', '8.50'),   // cantidad negativa: WARNING
            $this->salesRow('F-3', 'Cotización', '', '2026-01-07 10:00:00', '001', 'CEM-50', '1', '8.50'),       // REVIEW_REQUIRED
            $this->salesRow('F-4', 'Factura', '', 'ayer', '001', 'CEM-50', '1', '8.50', '1710034065'),          // QUARANTINED
        ];
        $job = $this->importSales($rows, ['skip_invalid_rows' => true]);
        $report = $this->qualityReport($job);

        // Campos nuevos.
        $this->assertSame('sales', $report->dataset);
        $this->assertSame($job->bronze_object_id, (int) $report->bronze_object_id);
        $this->assertNull($report->source_sync_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $report->rules_version);
        $counts = $this->decode($report->issue_counts);
        $this->assertEqualsCanonicalizing(['BLOCKER', 'REVIEW_REQUIRED', 'QUARANTINED', 'WARNING', 'AUTO_FIX_SAFE'], array_keys($counts), 'las cinco clases, aunque no haya hallazgos de alguna');
        $this->assertSame(0, $counts['BLOCKER']);
        $this->assertSame(1, $counts['REVIEW_REQUIRED']);
        $this->assertSame(1, $counts['QUARANTINED']);
        $this->assertGreaterThanOrEqual(1, $counts['WARNING']);
        $this->assertArrayNotHasKey('truncated', $counts);
        $metrics = $this->decode($report->metrics);
        $this->assertSame(4, $metrics['rows_total'], 'metrics incluye las métricas del resumen actual');
        $this->assertArrayHasKey('transformations', $metrics);
        $this->assertSame('sales@2026.03.1', $metrics['lineage']['schema_version']);
        $this->assertSame($job->bronze_object_id, $metrics['lineage']['bronze_object_id']);

        // Contrato anterior intacto: score, summary (con sus claves) e issues (con code, severity, count, message).
        $summary = $this->decode($report->summary);
        foreach (['rows_total', 'rows_valid', 'rows_error', 'rows_warning', 'documents_total', 'documents_valid', 'documents_invalid', 'documents_new', 'date_from', 'date_to', 'error_rate', 'ingestion_mode'] as $key) {
            $this->assertArrayHasKey($key, $summary, "summary.{$key}");
        }
        $this->assertEqualsCanonicalizing(['code', 'severity', 'count', 'message'], array_keys($this->decode($report->issues)[0]));
        $api = $this->getJson("/api/v1/imports/{$job->id}")->assertOk()->json('data.quality_report');
        $this->assertEqualsCanonicalizing(['score', 'issues', 'summary'], array_keys($api));
        $this->assertSame($summary, $api['summary']);
        $this->assertSame($this->decode($report->issues), $api['issues']);
        $this->assertEquals((float) $report->score, $api['score']);

        // Hallazgos: clase, código, entidad, referencia, fila, campo, valor, propuesta, aplicado y motivo.
        $issues = $this->findings($report->id);
        $negative = $issues->firstWhere('code', 'negative_quantity');
        $this->assertSame(['WARNING', 'sales', 'invoice|F-2', 2, 'quantity', '-1', false], [$negative->dq_class, $negative->entity, $negative->record_ref, (int) $negative->source_row, $negative->field, $negative->original_value, (bool) $negative->applied]);
        $this->assertNotSame('', $negative->reason);
        $this->assertSame('open', $negative->status);
        $type = $issues->firstWhere('code', 'unknown_document_type');
        $this->assertSame(['REVIEW_REQUIRED', 'unknown|F-3', 3, 'document_type', 'Cotización'], [$type->dq_class, $type->record_ref, (int) $type->source_row, $type->field, $type->original_value]);
        $date = $issues->firstWhere('code', 'invalid_date');
        $this->assertSame(['QUARANTINED', 'invoice|F-4', 'issued_at', 'ayer'], [$date->dq_class, $date->record_ref, $date->field, $date->original_value]);
        $this->assertSame(DataQualityCatalog::classFor('stale_document_skipped'), 'WARNING');
    }

    public function test_validation_and_confirmation_each_leave_their_own_report_and_the_last_one_replaces_the_previous(): void
    {
        $job = $this->importSales([$this->salesRow('F-1', 'Cotización'), $this->salesRow('F-2')], ['skip_invalid_rows' => true], confirm: false);
        $afterValidation = $this->qualityReport($job);
        $this->assertSame(1, $this->decode($afterValidation->issue_counts)['REVIEW_REQUIRED']);
        $this->assertSame(0, DB::table('quarantine_records')->count(), 'la validación solo proyecta: la cuarentena se escribe al confirmar');
        $this->assertSame(1, $this->decode($afterValidation->summary)['documents_quarantined']);

        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $afterCommit = $this->qualityReport($job);
        $this->assertNotSame($afterValidation->id, $afterCommit->id);
        $this->assertSame(1, DB::table('data_quality_reports')->where('import_job_id', $job->id)->count(), 'un solo informe por importación (consumidores)');
        $this->assertSame(0, DB::table('data_quality_issues')->where('report_id', $afterValidation->id)->count(), 'los hallazgos del informe reemplazado se van con él');
        $this->assertSame(1, DB::table('quarantine_records')->count());
    }

    public function test_the_issue_rows_are_capped_and_the_excess_is_counted_with_a_truncated_mark_keeping_the_highest_priority(): void
    {
        config(['data_quality.max_issue_rows_per_report' => 4]);
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = $this->salesRow("W-{$i}", 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '-1', '8.50'); // 10 WARNING negative_quantity
        }
        $rows[] = $this->salesRow('R-1', 'Cotización'); // REVIEW_REQUIRED, llega DESPUÉS de llenar el tope
        $rows[] = $this->salesRow('Q-1', 'Factura', '', 'ayer');
        $job = $this->importSales($rows, ['skip_invalid_rows' => true], confirm: false);

        $report = $this->qualityReport($job);
        $counts = $this->decode($report->issue_counts);
        $this->assertGreaterThanOrEqual(10, $counts['WARNING'], 'los 10 avisos se cuentan aunque solo se guarden algunos');
        $this->assertTrue($counts['truncated']);
        $this->assertSame(4, $counts['issue_rows_stored']);
        $this->assertGreaterThanOrEqual(8, $counts['issue_rows_omitted']);
        $stored = $this->findings($report->id)->whereNotIn('code', ['unknown_product', 'voided_document', 'missing_dates']);
        $rowLevel = $stored->filter(fn ($i) => $i->source_row !== null);
        $this->assertLessThanOrEqual(4, $rowLevel->count(), 'tope de filas por informe');
        $this->assertContains('unknown_document_type', $rowLevel->pluck('code')->all(), 'el hallazgo de mayor prioridad desplaza a los avisos ya guardados');
        $this->assertContains('invalid_date', $rowLevel->pluck('code')->all());
    }

    public function test_safe_normalizations_are_recorded_as_aggregated_auto_fix_findings_never_one_row_per_cell(): void
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            // Coma decimal, espacios sobrantes, código en minúsculas y fecha con día > 12 (inequívoca): 4 normalizaciones por fila.
            $rows[] = $this->salesRow("a-{$i}", 'Factura', '', '25/01/2026', '001', ' cem-50 ', '2', '8,50');
        }
        $job = $this->importSales($rows);
        $report = $this->qualityReport($job);
        $auto = $this->findings($report->id)->where('dq_class', 'AUTO_FIX_SAFE');

        $byCode = $auto->groupBy('code');
        $this->assertEqualsCanonicalizing(['whitespace_trimmed', 'decimal_separator_normalized', 'date_format_normalized', 'code_case_normalized'], $byCode->keys()->all());
        // Una fila por (código, campo), no por celda.
        $this->assertEqualsCanonicalizing(
            ['decimal_separator_normalized:unit_price', 'code_case_normalized:document_number', 'code_case_normalized:product_sku', 'date_format_normalized:issued_at', 'whitespace_trimmed:product_sku'],
            $auto->map(fn ($f) => "{$f->code}:{$f->field}")->values()->all(),
        );
        $this->assertSame(5, $auto->count());
        foreach ($auto as $finding) {
            $this->assertTrue((bool) $finding->applied);
            $this->assertNull($finding->record_ref);
            $this->assertStringContainsString('12 valores', $finding->reason);
            $this->assertNotNull($finding->original_value);
            $this->assertNotNull($finding->proposed_value);
        }
        $decimal = $auto->firstWhere('code', 'decimal_separator_normalized');
        $this->assertSame('8,50', $decimal->original_value);
        $this->assertSame(60, $this->decode($report->issue_counts)['AUTO_FIX_SAFE'], '12 filas × 5 celdas normalizadas');
        $this->assertSame(12, $this->decode($report->metrics)['transformations']['auto_fix']['decimal_separator_normalized']['unit_price']);
    }

    public function test_an_ambiguous_day_month_date_is_a_warning_not_a_safe_fix_and_a_row_with_errors_records_no_fixes(): void
    {
        $job = $this->importSales([
            $this->salesRow('F-1', 'Factura', '', '03/04/2026', '001', 'CEM-50', '1', '8.50'),           // ambigua (3 de abril o 4 de marzo)
            $this->salesRow('F-2', 'Factura', '', '2026-01-05', '001', 'CEM-50', '1', '8,50'),           // coma decimal: auto-corrección
            $this->salesRow('F-3', 'Factura', '', 'no-fecha', '001', ' VAR-12 ', '1', '8,50'),           // fila con error: sus normalizaciones no se aplican
        ], ['skip_invalid_rows' => true], confirm: false);
        $issues = $this->findings($this->qualityReport($job)->id);
        $ambiguous = $issues->firstWhere('code', 'date_format_ambiguous');
        $this->assertSame('WARNING', $ambiguous->dq_class);
        $this->assertSame('03/04/2026', $ambiguous->original_value);
        $this->assertStringContainsString('día/mes', $ambiguous->reason);
        $decimal = $issues->firstWhere('code', 'decimal_separator_normalized');
        $this->assertStringContainsString('1 valores', $decimal->reason, 'solo la fila válida cuenta: la fila con error no aplicó nada');
        $this->assertNull($issues->firstWhere('code', 'whitespace_trimmed'));
    }

    public function test_findings_never_hold_names_or_identifications_and_values_are_bounded(): void
    {
        $long = str_repeat('x', 400);
        $job = $this->importSales([
            $this->salesRow('F-1', 'Factura', '', 'ayer', '001', 'CEM-50', '1', $long, '1710034065'),
            $this->salesRow('F-2', 'Factura', '', '2026-01-05', '001', 'CEM-50', 'mucho'),
        ], ['skip_invalid_rows' => true], confirm: false);
        $report = $this->qualityReport($job);
        foreach ($this->findings($report->id) as $finding) {
            foreach (['original_value', 'proposed_value', 'record_ref', 'reason'] as $column) {
                $this->assertStringNotContainsString('1710034065', (string) $finding->{$column}, "{$column} sin cédula");
            }
            $this->assertLessThanOrEqual(200, mb_strlen((string) $finding->original_value));
        }
        $price = $this->findings($report->id)->firstWhere('field', 'unit_price');
        $this->assertSame(200, mb_strlen($price->original_value), 'acotado a 200 caracteres');

        // Reglas de enmascarado de la pieza: nombres e identificaciones de clientes jamás; códigos de negocio sin tocar; correos sí.
        $this->assertSame('[CLIENTE]', FindingCollector::safeValue('customer_code', '1710034065'));
        $this->assertSame('[CLIENTE]', FindingCollector::safeValue('customer_name', 'Juan Pérez'));
        $this->assertSame('0000012345', FindingCollector::safeValue('document_number', '0000012345'), 'un número de documento de 10 dígitos no es una cédula');
        $this->assertStringNotContainsString('maria@correo.com', (string) FindingCollector::safeValue('notes', 'contacto maria@correo.com'));
        $this->assertStringNotContainsString('1710034065', (string) FindingCollector::safeValue('notes', 'cédula 1710034065'));
    }

    public function test_each_issue_code_maps_to_the_documented_class_by_severity(): void
    {
        $this->assertSame('REVIEW_REQUIRED', DataQualityCatalog::classFor('unknown_document_type', 'error'));
        $this->assertSame('QUARANTINED', DataQualityCatalog::classFor('invalid_date', 'error'));
        $this->assertSame('WARNING', DataQualityCatalog::classFor('invalid_date', 'warning'), 'una advertencia nunca retiene el registro');
        $this->assertSame('QUARANTINED', DataQualityCatalog::classFor('unknown_product', 'error'));
        $this->assertSame('WARNING', DataQualityCatalog::classFor('unknown_product', 'warning'));
        $this->assertSame('QUARANTINED', DataQualityCatalog::classFor('inventado_por_una_regla_nueva', 'error'), 'un código sin catalogar se clasifica por severidad');
        $this->assertSame('WARNING', DataQualityCatalog::classFor('inventado_por_una_regla_nueva', 'warning'));
        // Las marcas de la fuente tienen código propio.
        $this->assertSame(['future_recorded_at', 'WARNING'], DataQualityCatalog::resolve(Issue::warning('future_date', 'x', 'source_recorded_at')));
        $this->assertSame(['recorded_at_unreadable', 'WARNING'], DataQualityCatalog::resolve(Issue::warning('invalid_date', 'x', 'source_recorded_at')));
        $this->assertSame(['future_date', 'QUARANTINED'], DataQualityCatalog::resolve(Issue::error('future_date', 'x', 'issued_at')));
    }

    public function test_summary_level_findings_are_recorded_aggregated_with_their_count(): void
    {
        $this->importSales([$this->salesRow('F-1', 'Factura', 'Anulada', '2026-01-05 10:00:00')]);
        $job = $this->importSales([$this->salesRow('F-1', 'Factura', 'Anulada', '2026-01-05 10:00:00'), $this->salesRow('F-2', 'Factura', '', '2026-01-09 10:00:00')]);
        $issues = $this->findings($this->qualityReport($job)->id);

        $voided = $issues->firstWhere('code', 'voided_document');
        $this->assertSame(['WARNING', null, null], [$voided->dq_class, $voided->record_ref, $voided->source_row]);
        $this->assertStringContainsString('documentos anulados', $voided->reason);
        $this->assertNotNull($issues->firstWhere('code', 'missing_dates'), 'días sin ventas entre el 5 y el 9');
    }
}
