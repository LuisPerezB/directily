<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Tipos y estados de documento desconocidos (defecto HIGH de auditoría): un tipo o estado NO vacío que no se reconoce ya no se cuenta
 * como venta; es un error de fila (unknown_document_type / unknown_document_status) de clase REVIEW_REQUIRED y el documento no se
 * escribe. Estado vacío = completada y tipo vacío = factura (convención documentada).
 */
final class UnknownDocumentTypeStatusTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, RefreshDatabase;

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

    /** @return list<string> */
    private function row(string $number, string $type, string $status): array
    {
        return [$number, $type, $status, '2026-01-05 10:00:00', '001', 'CEM-50', 'Cemento 50kg', 'Cemento', '2', '8.50', '0', '0', '', '', '', 'Local'];
    }

    /** @return list<list<string>> */
    private function mixedRows(): array
    {
        return [
            $this->row('COT-1', 'Cotización', ''),        // tipo desconocido
            $this->row('PRO-1', 'Proforma', 'Anulada'),   // tipo desconocido (aunque el estado sea válido)
            $this->row('F-2', 'Factura', 'Rechazada'),    // estado desconocido
            $this->row('F-3', 'Factura', 'No autorizada'), // estado desconocido
            $this->row('F-4', 'Factura', ''),             // estado vacío => completada
            $this->row('F-5', '', 'Autorizada'),          // tipo vacío => factura; «Autorizada» se reconoce
            $this->row('F-6', 'Factura', 'No'),           // complemento de una bandera «Anulada»
            $this->row('F-7', 'Factura', 'Anulada'),      // anulada
            $this->row('F-8', 'Factura', 'Pendiente'),    // pendiente
        ];
    }

    public function test_unknown_type_and_status_are_review_required_errors_and_are_not_written(): void
    {
        $job = $this->uploadAndValidate($this->tenant, $this->csv(self::SALES_HEADERS, $this->mixedRows()));

        $this->assertSame('validated', $job->status);
        $this->assertSame(4, $job->stats['rows_error']);
        $this->assertSame(4, $job->stats['documents_invalid']);
        $this->assertSame(5, $job->stats['documents_valid']);
        $this->assertSame(5, $job->stats['documents_new']);

        $errors = collect($this->getJson("/api/v1/imports/{$job->id}/errors?severity=error&per_page=100")->assertOk()->json('data'));
        $byCode = $errors->groupBy('error_code');
        $this->assertCount(2, $byCode['unknown_document_type']);
        $this->assertCount(2, $byCode['unknown_document_status']);
        foreach ($errors as $error) {
            $this->assertSame('error', $error['severity']);
            $this->assertSame('REVIEW_REQUIRED', $error['dq_class'], 'el detalle del error lleva la clase de calidad');
        }
        $this->assertSame(['Cotización', 'Proforma'], $byCode['unknown_document_type']->pluck('raw_value')->all());
        $this->assertSame(['Rechazada', 'No autorizada'], $byCode['unknown_document_status']->pluck('raw_value')->all());
        $this->assertSame('document_type', $byCode['unknown_document_type'][0]['column']);
        $this->assertSame('status', $byCode['unknown_document_status'][0]['column']);

        $csv = $this->get("/api/v1/imports/{$job->id}/errors.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('clase_calidad', $csv);
        $this->assertStringContainsString('REVIEW_REQUIRED', $csv);

        // No se puede confirmar con filas en revisión; con skip_invalid_rows solo se escribe lo reconocido.
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertStatus(409)->assertJsonPath('code', 'has_errors');
        $this->putJson("/api/v1/imports/{$job->id}/mapping", ['mapping' => $job->suggested_mapping, 'options' => ['skip_invalid_rows' => true]])->assertAccepted();
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        $stored = DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->get(['document_number', 'document_type', 'status'])
            ->mapWithKeys(fn ($s) => [$s->document_number => [$s->document_type, $s->status]])->all();
        $this->assertSame([
            'F-4' => ['invoice', 'completed'],
            'F-5' => ['invoice', 'completed'],
            'F-6' => ['invoice', 'completed'],
            'F-7' => ['invoice', 'voided'],
            'F-8' => ['invoice', 'pending'],
        ], $stored);
        foreach (['COT-1', 'PRO-1', 'F-2', 'F-3'] as $number) {
            $this->assertFalse(DB::table('sales')->where('document_number', $number)->exists(), "{$number} no debe escribirse");
        }
        $this->assertSame(0, DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->whereIn('s.document_number', ['COT-1', 'PRO-1', 'F-2', 'F-3'])->count());
    }

    public function test_empty_status_is_completed_and_empty_type_is_invoice(): void
    {
        $job = $this->uploadAndValidate($this->tenant, $this->csv(self::SALES_HEADERS, [$this->row('F-1', '', '')]));
        $this->assertSame(0, $job->stats['rows_error']);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $sale = DB::table('sales')->where('document_number', 'F-1')->first();
        $this->assertSame('invoice', $sale->document_type);
        $this->assertSame('completed', $sale->status);
    }

    public function test_batch_reports_review_required_errors_in_the_error_detail_and_writes_nothing_for_them(): void
    {
        $item = [['product_sku' => 'A', 'product_name' => 'Producto A', 'quantity' => 1, 'unit_price' => 5]];
        $documents = [
            ['document_number' => 'B-1', 'document_type' => 'cotizacion', 'issued_at' => '2026-05-04', 'branch_code' => '001', 'items' => $item],
            ['document_number' => 'B-2', 'status' => 'reversada', 'issued_at' => '2026-05-04', 'branch_code' => '001', 'items' => $item],
            ['document_number' => 'B-3', 'issued_at' => '2026-05-04', 'branch_code' => '001', 'items' => $item], // vacíos: factura / completada
            ['document_number' => 'B-4', 'document_type' => 'Nota de venta', 'status' => 'Emitida', 'issued_at' => '2026-05-04', 'branch_code' => '001', 'items' => $item],
        ];
        $response = $this->postJson('/api/v1/sales/batch', ['documents' => $documents])->assertOk()
            ->assertJsonPath('data.created', 2)->assertJsonPath('data.rejected', 2);

        $errors = collect($response->json('data.errors'));
        $this->assertCount(2, $errors);
        $type = $errors->firstWhere('code', 'unknown_document_type');
        $status = $errors->firstWhere('code', 'unknown_document_status');
        $this->assertSame(['index' => 0, 'item' => 0, 'field' => 'document_type', 'code' => 'unknown_document_type', 'severity' => 'error', 'dq_class' => 'REVIEW_REQUIRED'], array_diff_key($type, ['message' => 1]));
        $this->assertSame(['index' => 1, 'item' => 0, 'field' => 'status', 'code' => 'unknown_document_status', 'severity' => 'error', 'dq_class' => 'REVIEW_REQUIRED'], array_diff_key($status, ['message' => 1]));
        $this->assertNotEmpty($type['message']);

        $this->assertSame(['B-3', 'B-4'], DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->pluck('document_number')->all());
        $this->assertSame(['invoice', 'completed'], [DB::table('sales')->where('document_number', 'B-3')->value('document_type'), DB::table('sales')->where('document_number', 'B-3')->value('status')]);
        $this->assertSame(['sales_note', 'completed'], [DB::table('sales')->where('document_number', 'B-4')->value('document_type'), DB::table('sales')->where('document_number', 'B-4')->value('status')]);
    }

    public function test_a_batch_with_only_review_required_documents_is_unprocessable(): void
    {
        $item = [['product_sku' => 'A', 'quantity' => 1, 'unit_price' => 5]];
        $this->postJson('/api/v1/sales/batch', ['documents' => [['document_number' => 'B-1', 'document_type' => 'guia de remision', 'issued_at' => '2026-05-04', 'branch_code' => '001', 'items' => $item]]])
            ->assertUnprocessable()->assertJsonPath('data.errors.0.dq_class', 'REVIEW_REQUIRED');
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_an_unknown_type_row_stays_an_error_while_the_recognized_document_with_the_same_number_is_written_with_a_warning(): void
    {
        // Misma numeración: «Otro» (válido) y «Cotización» (desconocido). En los ERP la numeración suele ser por tipo o serie (la factura
        // 100 y la proforma 100 son documentos distintos), así que:
        //  (a) la fila de tipo desconocido sigue siendo error REVIEW_REQUIRED y no se escribe;
        //  (b) el documento reconocido con el mismo número SE ESCRIBE completo, con sus propias líneas (jamás se mezcla con la desconocida);
        //  (c) queda un AVISO (no error) `document_number_shared_with_unknown_type` sobre el documento reconocido, contado en las
        //      estadísticas y el resumen de calidad, para que el caso sea visible.
        $rows = [
            $this->row('X-1', 'Otro', ''),
            $this->row('X-1', 'Cotización', ''),
            $this->row('X-2', 'Otro', ''),
        ];
        $job = $this->uploadAndValidate($this->tenant, $this->csv(self::SALES_HEADERS, $rows));
        $this->assertSame(1, $job->stats['rows_error'], 'solo la fila de tipo desconocido es error');
        $this->assertSame(2, $job->stats['documents_valid']);
        $this->assertSame(2, $job->stats['documents_new']);
        $this->assertSame(1, $job->stats['document_number_shared_with_unknown_type'], 'un documento reconocido comparte número con filas de tipo desconocido');

        $errors = collect($this->getJson("/api/v1/imports/{$job->id}/errors?per_page=100")->assertOk()->json('data'));
        $this->assertSame(['unknown_document_type'], $errors->where('severity', 'error')->pluck('error_code')->all(), '(a) el error sigue siendo solo el del tipo desconocido');
        $warning = $errors->firstWhere('error_code', 'document_number_shared_with_unknown_type');
        $this->assertSame(['warning', 'X-1', null], [$warning['severity'], $warning['raw_value'], $warning['dq_class']], '(c) aviso, no error, sin clase de revisión');
        $this->assertNotContains('ambiguous_document_type', $errors->pluck('error_code')->all());
        $report = $this->getJson("/api/v1/imports/{$job->id}")->assertOk()->json('data.quality_report');
        $this->assertSame(1, $report['summary']['document_number_shared_with_unknown_type']);
        $this->assertSame(['warning', 1], [
            collect($report['issues'])->firstWhere('code', 'document_number_shared_with_unknown_type')['severity'],
            collect($report['issues'])->firstWhere('code', 'document_number_shared_with_unknown_type')['count'],
        ]);

        // (b) Con skip_invalid_rows se escribe lo reconocido: X-1 (tipo «other», su única línea) y X-2; la fila «Cotización» no.
        $this->putJson("/api/v1/imports/{$job->id}/mapping", ['mapping' => $job->suggested_mapping, 'options' => ['skip_invalid_rows' => true]])->assertAccepted();
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $stored = DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->get(['id', 'document_number', 'document_type'])
            ->mapWithKeys(fn ($s) => [$s->document_number => [$s->document_type, DB::table('sale_items')->where('sale_id', $s->id)->count()]])->all();
        $this->assertSame(['X-1' => ['other', 1], 'X-2' => ['other', 1]], $stored);
    }
}
