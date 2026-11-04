<?php

declare(strict_types=1);

namespace Tests\Unit\Horizons;

use App\Support\Horizons\HorizonCatalog;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * W2-E · catálogo ÚNICO de horizontes 7/14/15/30/60/90 (ADR-V25-02): se declara una sola vez en el backend (`predictily.horizons`), el motor
 * declara el mismo (predictily_engine.platform.config.HORIZONS) y ningún otro lugar del backend conserva una lista propia.
 */
final class HorizonCatalogTest extends TestCase
{
    public function test_the_catalog_and_the_customer_subset_are_declared_once(): void
    {
        $this->assertSame([7, 14, 15, 30, 60, 90], HorizonCatalog::all());
        $this->assertSame(HorizonCatalog::all(), HorizonCatalog::demand(), 'demanda acepta todo el catálogo');
        $this->assertSame([7, 14, 15, 30, 90], HorizonCatalog::customer());
        $this->assertNotContains(60, HorizonCatalog::customer(), 'Customer no usa 60 (decisión documentada en config/predictily.php)');
        $this->assertTrue(HorizonCatalog::isValid(15));
        $this->assertFalse(HorizonCatalog::isValid(21));
        $this->assertSame([7, 15, 30], HorizonCatalog::ints(['30', 15, 7, 15]), 'normaliza: enteros únicos y ascendentes');
    }

    public function test_the_customer_subset_can_never_leave_the_catalog(): void
    {
        config(['predictily.horizons.customer' => [7, 21, 30, 120]]);
        $this->assertSame([7, 30], HorizonCatalog::customer());
    }

    public function test_the_backend_matches_the_shared_horizon_contract_without_needing_the_engine(): void
    {
        $path = base_path('../docs/product/horizons.contract.json');
        $this->assertFileExists($path, 'falta el contrato compartido de horizontes');
        $contract = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($contract['catalog'], config('predictily.horizons.catalog'));
        $this->assertSame($contract['catalog'], HorizonCatalog::all());
        $this->assertSame($contract['demand'], HorizonCatalog::demand());
        $this->assertSame($contract['customer'], HorizonCatalog::customer());
        $this->assertSame($contract['customer'], config('predictily.horizons.customer'));
    }

    public function test_the_backend_catalog_matches_the_engine_catalog(): void
    {
        $python = base_path('../engine/.venv/bin/python');
        $engine = base_path('../engine');
        if (! is_file($python) || ! is_dir($engine.'/predictily_engine')) {
            $this->markTestSkipped('El motor (engine/.venv) no está disponible: lo cubre engine/tests/unit/test_horizon_catalog.py desde su lado.');
        }
        $code = 'import json, sys; sys.path.insert(0, '.var_export($engine, true).'); '
            .'from predictily_engine.platform.config import HORIZONS; print(json.dumps({k: list(v) for k, v in HORIZONS.items()}))';
        $result = Process::timeout(120)->run([$python, '-I', '-c', $code]);
        $this->assertTrue($result->successful(), 'no se pudo leer el catálogo del motor: '.$result->errorOutput());
        $declared = json_decode(trim($result->output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(HorizonCatalog::all(), $declared['catalog'], 'predictily.horizons.catalog (backend) ≠ HORIZONS["catalog"] (motor)');
        $this->assertSame($declared['demand'], HorizonCatalog::demand());
        $this->assertSame($declared['customer'], HorizonCatalog::customer(), 'predictily.horizons.customer (backend) ≠ HORIZONS["customer"] (motor)');
    }

    public function test_no_backend_source_keeps_its_own_copy_of_the_horizon_list(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // Sin comentarios: una lista literal 7/14/15/30/60/90 (o su subconjunto de Customer) en una línea que habla de horizontes es un catálogo paralelo.
            $code = preg_replace('#/\*.*?\*/|//[^\n]*|\#[^\n]*#s', '', $source) ?? '';
            $lines = explode("\n", $code);
            foreach ($lines as $i => $line) {
                $context = ($lines[$i - 1] ?? '').' '.$line;
                if (preg_match('/\[\s*7\s*,\s*(14\s*,\s*)?(15\s*,\s*)?30\s*(,\s*(60\s*,\s*)?90)?\s*\]/', $line) === 1 && stripos($context, 'horizon') !== false) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($i + 1);
                }
            }
        }
        $this->assertSame([], $offenders, 'listas literales de horizontes fuera de config/predictily.php: usar App\Support\Horizons\HorizonCatalog');
    }
}
