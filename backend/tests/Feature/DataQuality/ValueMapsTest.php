<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Quality\ValueMaps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * Mapeos de valores por fuente (ADR-V25-24; W2-A): `import_jobs.options.value_maps` ({document_type: {crudo: canónico}, status: {...}}) se aplican
 * ANTES del reconocimiento de vocabulario, se validan contra el vocabulario canónico y quedan en `metrics.transformations` y en `rules_version`.
 */
final class ValueMapsTest extends TestCase
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

    /** @return list<list<string>> */
    private function rows(): array
    {
        return [
            $this->salesRow('F-1', 'Factura', 'Rechazada'),
            $this->salesRow('F-2', 'Factura', 'rechazada '),       // misma clave normalizada
            $this->salesRow('F-3', 'Cotización', 'Autorizada'),
            $this->salesRow('F-4', 'Factura', 'Autorizada'),
        ];
    }

    public function test_value_maps_are_applied_before_vocabulary_recognition_and_registered_as_transformations(): void
    {
        $plain = $this->importSales($this->rows(), [], confirm: false);
        $this->assertSame(3, $plain->stats['rows_error'], 'sin mapeos: 2 estados y 1 tipo desconocidos');

        $maps = ['status' => ['Rechazada' => 'anulada'], 'document_type' => ['Cotización' => 'Otro']];
        $job = $this->importSales($this->rows(), ['value_maps' => $maps]);

        $this->assertSame('completed', $job->status);
        $this->assertSame(0, $job->stats['rows_error']);
        $this->assertSame($maps, $job->options['value_maps'], 'el mapeo queda en las opciones de la importación');
        $sales = DB::table('sales')->orderBy('document_number')->get(['document_number', 'document_type', 'status'])->mapWithKeys(fn ($s) => [$s->document_number => [$s->document_type, $s->status]])->all();
        $this->assertSame(['F-1' => ['invoice', 'voided'], 'F-2' => ['invoice', 'voided'], 'F-3' => ['other', 'completed'], 'F-4' => ['invoice', 'completed']], $sales);

        $report = $this->qualityReport($job);
        $applied = $this->decode($report->metrics)['transformations']['value_maps'];
        $this->assertEquals(['from' => 'Rechazada', 'to' => 'anulada', 'count' => 2], $applied['status'][0], 'las dos filas, también la de «rechazada » (clave normalizada)');
        $this->assertEquals(['from' => 'Cotización', 'to' => 'Otro', 'count' => 1], $applied['document_type'][0]);

        // `rules_version` registra los mapeos aplicados: mismas filas sin mapeos → otra huella; mismos mapeos → misma huella.
        $this->assertNotSame($this->qualityReport($plain)->rules_version, $report->rules_version);
        $again = $this->importSales($this->rows(), ['value_maps' => $maps]);
        $this->assertSame($report->rules_version, $this->qualityReport($again)->rules_version);
        $other = $this->importSales($this->rows(), ['value_maps' => ['status' => ['Rechazada' => 'pendiente'], 'document_type' => ['Cotización' => 'Otro']]]);
        $this->assertNotSame($report->rules_version, $this->qualityReport($other)->rules_version);
    }

    public function test_targets_must_belong_to_the_canonical_vocabulary_and_only_sales_accepts_them(): void
    {
        $upload = $this->uploadImport($this->csv(self::SALES_HEADERS, $this->rows()))->assertCreated();
        $id = $upload->json('data.id');
        $mapping = $upload->json('data.suggested_mapping');

        $bad = fn (array $maps) => $this->putJson("/api/v1/imports/{$id}/mapping", ['mapping' => $mapping, 'options' => ['value_maps' => $maps]]);
        $bad(['status' => ['Rechazada' => 'quizás']])->assertUnprocessable()->assertJsonValidationErrors(['options.value_maps.status']);
        $bad(['document_type' => ['Cotización' => 'proforma']])->assertUnprocessable()->assertJsonValidationErrors(['options.value_maps.document_type']);
        $bad(['status' => ['Rechazada' => '']])->assertUnprocessable()->assertJsonValidationErrors(['options.value_maps.status']);
        $bad(['quantity' => ['uno' => '1']])->assertUnprocessable()->assertJsonValidationErrors(['options.value_maps.quantity']);
        $bad(['status' => 'anulada'])->assertUnprocessable();

        // Entidad distinta de ventas: los mapeos de tipo/estado no aplican.
        $products = $this->uploadImport($this->csv(['codigo', 'nombre'], [['A', 'Producto A']]), 'products', 'productos.csv')->assertCreated();
        $this->putJson('/api/v1/imports/'.$products->json('data.id').'/mapping', ['mapping' => $products->json('data.suggested_mapping'), 'options' => ['value_maps' => ['status' => ['x' => 'anulada']]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['options.value_maps']);

        $this->putJson("/api/v1/imports/{$id}/mapping", ['mapping' => $mapping, 'options' => ['value_maps' => ['status' => ['Rechazada' => 'ANULADA']]]])->assertAccepted();
    }

    public function test_matching_is_exact_first_then_by_normalized_key(): void
    {
        $maps = new ValueMaps(['status' => ['Rechazada' => 'anulada', 'rechazada' => 'pendiente'], 'document_type' => ['Cotización' => 'otro']]);
        $this->assertSame('anulada', $maps->apply('status', 'Rechazada'));
        $this->assertSame('pendiente', $maps->apply('status', 'rechazada'), 'la coincidencia exacta gana');
        $this->assertSame('anulada', $maps->apply('status', 'RECHAZADA '), 'si no hay exacta, por clave normalizada');
        $this->assertSame('otro', $maps->apply('document_type', 'COTIZACION'), 'sin tildes ni mayúsculas');
        $this->assertSame('Aprobada', $maps->apply('status', 'Aprobada'), 'lo que no está mapeado pasa sin cambios');
        $this->assertNull($maps->apply('status', null));
        $this->assertSame(3, collect($maps->hits()['status'])->sum('count'));

        $errors = [];
        $clean = ValueMaps::validate(['status' => ['X' => 'Anulada'], 'document_type' => ['Y' => 'factura']], 'sales', $errors, 'options.value_maps', ValueMaps::VOCABULARY_FIELDS);
        $this->assertSame([], $errors);
        $this->assertSame(['status' => ['X' => 'Anulada'], 'document_type' => ['Y' => 'factura']], $clean);
    }
}
