<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Services\DataCore\Mapping\Heuristics\ValueMapProposer;
use App\Services\DataCore\Quality\ValueMaps;
use Tests\TestCase;

final class ValueMapProposerTest extends TestCase
{
    /** @param list<string> $values @return list<array{value: string, count: int}> */
    private function distinct(array $values): array
    {
        return array_map(fn (string $v) => ['value' => $v, 'count' => 10], $values);
    }

    public function test_document_types_get_value_maps_only_when_the_vocabulary_is_unambiguous(): void
    {
        $r = (new ValueMapProposer)->propose('document_type', $this->distinct(['FC', 'NCR', 'FACTURA', 'NOTA DE CREDITO', 'COT', 'PRF', 'X', 'ZZZ']));

        $this->assertSame(['FC' => 'invoice', 'NCR' => 'credit_note'], array_map(fn (array $e) => $e['to'], $r['entries']));
        $this->assertSame(['FACTURA', 'NOTA DE CREDITO'], $r['recognized'], 'lo que el importador ya reconoce no necesita mapeo');
        $reasons = array_column($r['unresolved'], 'reason', 'value');
        $this->assertSame('no_es_documento_de_venta', $reasons['COT'], 'una cotización NO se mapea a factura');
        $this->assertSame('abreviatura_ambigua', $reasons['X']);
        $this->assertSame('no_reconocido', $reasons['ZZZ']);
        $this->assertArrayHasKey('PRF', $reasons);
    }

    public function test_statuses_resolve_obvious_abbreviations_and_leave_single_letters_unresolved(): void
    {
        $r = (new ValueMapProposer)->propose('status', $this->distinct(['EMI', 'ANU', 'PEN', 'A', 'E', 'RZD', 'Rechazada-x']));
        $to = array_map(fn (array $e) => $e['to'], $r['entries']);

        $this->assertSame('completed', $to['EMI']);
        $this->assertSame('voided', $to['ANU']);
        $this->assertSame('pending', $to['PEN']);
        $reasons = array_column($r['unresolved'], 'reason', 'value');
        $this->assertSame('abreviatura_ambigua', $reasons['A'], '«A» puede ser Activa o Anulada');
        $this->assertSame('abreviatura_ambigua', $reasons['E']);
        $this->assertSame('no_reconocido', $reasons['RZD']);
    }

    public function test_every_proposed_destination_is_accepted_by_the_value_map_validator(): void
    {
        $proposer = new ValueMapProposer;
        foreach (['document_type' => ['FC', 'NCR', 'NV_X', 'FACT_E'], 'status' => ['EMI', 'ANU', 'PEN', 'CERR', 'OK', 'cancel', 'rechazado']] as $field => $values) {
            $r = $proposer->propose($field, $this->distinct($values));
            $accepted = [];
            foreach ($r['entries'] as $raw => $entry) {
                $accepted[$raw] = $entry['to'];
            }
            $errors = [];
            ValueMaps::validate([$field => $accepted], 'sales', $errors, 'value_maps', ['document_type', 'status']);
            $this->assertSame([], $errors, "{$field}: ".json_encode($errors));
        }
    }

    public function test_numeric_status_codes_warn_unless_the_column_is_a_void_flag(): void
    {
        $proposer = new ValueMapProposer;
        $ambiguous = $proposer->propose('status', $this->distinct(['0', '1']));
        $this->assertNotEmpty($ambiguous['warnings'], 'el importador lee 1 como «anulada»');
        $this->assertStringContainsString('bandera de anulación', $ambiguous['warnings'][0]);

        $flag = $proposer->propose('status', $this->distinct(['0', '1']), columnIsVoidFlag: true);
        $this->assertSame([], $flag['warnings']);
    }
}
