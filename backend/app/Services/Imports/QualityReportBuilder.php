<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\ImportJob;
use Illuminate\Support\Facades\DB;

/**
 * Reporte de calidad de una importación (data_quality_reports, scope "import").
 * El puntaje 0-100 es ORIENTATIVO: resume tasa de errores, advertencias y días faltantes;
 * no es una medida de precisión de pronósticos.
 */
final class QualityReportBuilder
{
    /**
     * @param  array<string, mixed>  $stats
     * @param  list<array<string, mixed>>  $issues
     * @return array{score: float, issues: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function build(array $stats, array $issues): array
    {
        $rows = max(1, (int) ($stats['rows_total'] ?? 0));
        $errorRate = ($stats['rows_error'] ?? 0) / $rows;
        $warningRate = ($stats['rows_warning'] ?? 0) / $rows;
        $score = 100.0;
        $score -= min(60, $errorRate * 150);
        $score -= min(15, $warningRate * 30);
        if (isset($stats['days_with_sales'], $stats['missing_days']) && ($stats['days_with_sales'] + $stats['missing_days']) > 0) {
            $missingShare = $stats['missing_days'] / ($stats['days_with_sales'] + $stats['missing_days']);
            $score -= min(15, $missingShare * 40);
        }
        if (($stats['rows_total'] ?? 0) === 0) {
            $score = 0;
        }

        $summary = array_intersect_key($stats, array_flip([
            'rows_total', 'rows_valid', 'rows_error', 'rows_warning', 'duplicates_in_file', 'documents_total', 'documents_valid',
            'documents_invalid', 'documents_new', 'documents_updated', 'documents_unchanged', 'voided_documents', 'return_lines',
            'date_from', 'date_to', 'missing_days', 'products_new', 'branches_new',
        ]));
        $summary['error_rate'] = round($errorRate, 4);

        return ['score' => round(max(0, $score), 2), 'issues' => array_values($issues), 'summary' => $summary];
    }

    /** @param array{score: float, issues: list<array<string, mixed>>, summary: array<string, mixed>} $report */
    public function persist(ImportJob $job, array $report): void
    {
        DB::table('data_quality_reports')->where('tenant_id', $job->tenant_id)->where('import_job_id', $job->id)->delete();
        DB::table('data_quality_reports')->insert([
            'tenant_id' => $job->tenant_id,
            'import_job_id' => $job->id,
            'scope' => 'import',
            'score' => $report['score'],
            'summary' => json_encode($report['summary']),
            'issues' => json_encode($report['issues']),
            'created_at' => now(),
        ]);
    }
}
