<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Support\Issue;
use App\Services\Sales\IngestionModeResolver;
use App\Services\Sales\SalesLineNormalizer;
use InvalidArgumentException;
use Tests\TestCase;

/** Vocabulario reconocido de tipos y estados de documento y su efecto en el normalizador (ADR-V25-23 / defecto HIGH de auditoría). */
final class DocumentVocabularyTest extends TestCase
{
    public function test_empty_status_is_completed_by_convention(): void
    {
        foreach ([null, '', '   '] as $raw) {
            $this->assertSame('completed', ValueParser::documentStatus($raw));
        }
    }

    public function test_recognized_statuses_keep_their_meaning(): void
    {
        foreach (['EMITIDA', 'Autorizada', 'autorizado', 'Completada', 'completed', 'Pagada', 'vigente', 'No', 'n', '0', 'FALSE'] as $raw) {
            $this->assertSame('completed', ValueParser::documentStatus($raw), "«{$raw}» es completada");
        }
        foreach (['ANULADA', 'anulado', 'Void', 'voided', 'cancelada', 'Si', 'S', '1', 'true'] as $raw) {
            $this->assertSame('voided', ValueParser::documentStatus($raw), "«{$raw}» es anulada");
        }
        foreach (['Pendiente', 'pending', 'Borrador', 'draft'] as $raw) {
            $this->assertSame('pending', ValueParser::documentStatus($raw), "«{$raw}» es pendiente");
        }
    }

    public function test_unrecognized_statuses_are_null_never_a_sale(): void
    {
        foreach (['rechazada', 'reversada', 'devuelta', 'no autorizada', 'NO AUTORIZADO', 'en proceso', 'pendiente de pago', 'xyz', '2'] as $raw) {
            $this->assertNull(ValueParser::documentStatus($raw), "«{$raw}» no se reconoce");
        }
    }

    public function test_unrecognized_document_types_are_null(): void
    {
        foreach (['cotizacion', 'Cotización', 'proforma', 'pedido', 'guia de remision', 'reserva'] as $raw) {
            $this->assertNull(ValueParser::documentType($raw), "«{$raw}» no se reconoce");
        }
        $this->assertSame('invoice', ValueParser::documentType(''));
        $this->assertSame('other', ValueParser::documentType('Otro'));
    }

    /** @return array<string, mixed> */
    private function raw(array $overrides = []): array
    {
        return array_merge([
            'document_number' => 'F-1', 'document_type' => 'Factura', 'status' => '', 'issued_at' => '2026-01-05 10:00:00', 'branch_code' => '001',
            'product_sku' => 'A', 'quantity' => '2', 'unit_price' => '5',
        ], $overrides);
    }

    public function test_normalizer_turns_unknown_type_and_status_into_review_required_errors(): void
    {
        $normalizer = new SalesLineNormalizer(new ValueParser('America/Guayaquil'), 'USD', 'America/Guayaquil');

        [$line, $issues] = $normalizer->normalize($this->raw(['document_type' => 'Cotización']));
        $this->assertNull($line);
        $this->assertSame(['unknown_document_type'], array_map(fn (Issue $i) => $i->code, $issues));
        $this->assertTrue($issues[0]->isError());
        $this->assertSame('REVIEW_REQUIRED', $issues[0]->dqClass);
        $this->assertSame('REVIEW_REQUIRED', $issues[0]->toArray(3)['dq_class']);
        $this->assertSame('Cotización', $issues[0]->rawValue);

        [$line, $issues] = $normalizer->normalize($this->raw(['status' => 'Reversada']));
        $this->assertNull($line);
        $this->assertSame(['unknown_document_status'], array_map(fn (Issue $i) => $i->code, $issues));
        $this->assertSame('REVIEW_REQUIRED', $issues[0]->dqClass);

        // Ambos a la vez: dos errores; el resto de errores de la fila siguen reportándose.
        [, $issues] = $normalizer->normalize($this->raw(['document_type' => 'pedido', 'status' => 'rechazada', 'quantity' => 'abc']));
        $this->assertEqualsCanonicalizing(['unknown_document_type', 'unknown_document_status', 'invalid_number'], array_map(fn (Issue $i) => $i->code, $issues));

        // Reconocido: la línea se normaliza sin incidencias.
        [$line, $issues] = $normalizer->normalize($this->raw(['status' => 'Autorizada']));
        $this->assertSame([], $issues);
        $this->assertSame('completed', $line['status']);
        $this->assertSame('invoice', $line['document_type']);
    }

    public function test_document_key_uses_a_marker_for_unknown_types(): void
    {
        $normalizer = new SalesLineNormalizer(new ValueParser, 'USD', 'America/Guayaquil');
        $this->assertSame('unknown|X-1', $normalizer->documentKey(['document_number' => 'x-1', 'document_type' => 'cotizacion']));
        $this->assertSame('other|X-1', $normalizer->documentKey(['document_number' => 'x-1', 'document_type' => 'otro']));
        $this->assertSame('invoice|X-1', $normalizer->documentKey(['document_number' => 'x-1']));
    }

    public function test_non_review_codes_have_no_dq_class(): void
    {
        $this->assertNull(Issue::error('invalid_date', 'x')->dqClass);
        $this->assertNull(Issue::dqClassFor('required'));
        $this->assertArrayNotHasKey('dq_class', Issue::error('required', 'x')->toArray());
    }

    public function test_ingestion_mode_normalization(): void
    {
        $this->assertNull(IngestionModeResolver::normalize(null));
        $this->assertNull(IngestionModeResolver::normalize(''));
        $this->assertSame('backfill', IngestionModeResolver::normalize('backfill'));
        $this->assertSame('incremental', IngestionModeResolver::normalize('incremental'));
        foreach (['Backfill', 'semanal', 'full', 1, true] as $invalid) {
            try {
                IngestionModeResolver::normalize($invalid);
                $this->fail('Debió rechazar '.var_export($invalid, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
