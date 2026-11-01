<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Services\DataCore\Quality\DataQualityCatalog;
use App\Services\DataCore\Quality\ValueMaps;
use App\Services\Imports\Support\Issue;
use Tests\TestCase;

/**
 * Catálogo único código → clase (ADR-V25-05; W2-A): parte de `Issue::DQ_CLASS_BY_CODE`, cubre cada código que emite el importador, está
 * documentado en docs/data/DATA_QUALITY_ENGINE.md y su huella (`rules_version`) cambia con las reglas, los parámetros y los mapeos.
 */
final class DataQualityCatalogTest extends TestCase
{
    public function test_the_catalog_starts_from_issue_dq_class_by_code(): void
    {
        foreach (Issue::DQ_CLASS_BY_CODE as $code => $class) {
            $this->assertSame($class, DataQualityCatalog::all()[$code][0], "{$code} conserva la clase de W1");
        }
    }

    public function test_the_catalog_has_the_w1_codes_with_their_classes(): void
    {
        $expected = [
            'unknown_document_type' => 'REVIEW_REQUIRED', 'unknown_document_status' => 'REVIEW_REQUIRED',
            'document_number_shared_with_unknown_type' => 'WARNING', 'unchanged_mode_mismatch' => 'WARNING',
            'future_recorded_at' => 'WARNING', 'recorded_at_unreadable' => 'WARNING', 'stale_skipped' => 'WARNING',
            'stale_document_skipped' => 'WARNING', 'updated_without_source_order' => 'WARNING',
            // Las cinco clases con un ejemplo estructural cada una.
            'bronze_capture_failed' => 'BLOCKER', 'source_columns_missing' => 'BLOCKER', 'unit_conversion_missing' => 'REVIEW_REQUIRED',
            'invalid_date' => 'QUARANTINED', 'negative_stock' => 'WARNING', 'schema_drift' => 'WARNING', 'whitespace_trimmed' => 'AUTO_FIX_SAFE',
        ];
        foreach ($expected as $code => $class) {
            $this->assertSame($class, DataQualityCatalog::all()[$code][0], $code);
            $this->assertNotSame('', DataQualityCatalog::rationale($code), "{$code} tiene justificación");
        }
        $this->assertEqualsCanonicalizing(DataQualityCatalog::CLASSES, ['BLOCKER', 'REVIEW_REQUIRED', 'QUARANTINED', 'WARNING', 'AUTO_FIX_SAFE']);
    }

    public function test_every_code_emitted_by_the_importers_is_in_the_catalog(): void
    {
        $emitted = [];
        $roots = [app_path('Services/Imports'), app_path('Services/Sales'), app_path('Services/DataCore/Sync'), app_path('Services/DataCore/Quality')];
        foreach ($roots as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match_all("/Issue::(?:error|warning)\\(\\s*'([a-z_]+)'/", $source, $m)) {
                    foreach ($m[1] as $code) {
                        $emitted[$code] = basename($file->getPathname());
                    }
                }
                // Resúmenes: 'code' => 'x' en los issues de resumen de los procesadores.
                if (str_contains($file->getPathname(), 'Processors') && preg_match_all("/\\['code' => '([a-z_]+)', 'severity'/", $source, $m)) {
                    foreach ($m[1] as $code) {
                        $emitted[$code] = basename($file->getPathname());
                    }
                }
            }
        }
        $this->assertGreaterThan(30, count($emitted), 'la exploración encuentra los códigos del importador');
        $missing = array_diff_key($emitted, DataQualityCatalog::all());
        $this->assertSame([], $missing, 'códigos emitidos sin clase en el catálogo (agréguelos con su justificación): '.json_encode($missing));
    }

    public function test_every_catalog_code_is_documented_in_the_engine_doc(): void
    {
        $doc = (string) file_get_contents(base_path('../docs/data/DATA_QUALITY_ENGINE.md'));
        $undocumented = array_values(array_filter(array_keys(DataQualityCatalog::all()), fn (string $code) => ! str_contains($doc, "`{$code}`")));
        $this->assertSame([], $undocumented, 'códigos del catálogo sin documentar en docs/data/DATA_QUALITY_ENGINE.md §2');
    }

    public function test_the_rules_version_is_a_stable_sha256_that_changes_with_parameters_and_value_maps(): void
    {
        $base = DataQualityCatalog::rulesVersion();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $base);
        $this->assertSame($base, DataQualityCatalog::rulesVersion(), 'estable para las mismas reglas');

        $withMaps = DataQualityCatalog::rulesVersion(['value_maps' => (new ValueMaps(['status' => ['RECHAZADA' => 'anulada']]))->digest()]);
        $this->assertNotSame($base, $withMaps, 'los mapeos de valores entran en la huella');
        $this->assertSame($withMaps, DataQualityCatalog::rulesVersion(['value_maps' => (new ValueMaps(['status' => ['RECHAZADA' => 'anulada']]))->digest()]));
        $this->assertNotSame($withMaps, DataQualityCatalog::rulesVersion(['value_maps' => (new ValueMaps(['status' => ['RECHAZADA' => 'pendiente']]))->digest()]));

        config(['predictily.imports.source_recorded_at_tolerance_minutes' => 30]);
        $this->assertNotSame($base, DataQualityCatalog::rulesVersion(), 'los parámetros de las reglas entran en la huella');
        config(['predictily.imports.source_recorded_at_tolerance_minutes' => 5, 'data_quality.max_issue_rows_per_report' => 17]);
        $this->assertNotSame($base, DataQualityCatalog::rulesVersion(), 'también el tope de filas por informe');
    }
}
