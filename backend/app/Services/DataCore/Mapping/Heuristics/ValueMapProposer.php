<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

use App\Services\Imports\Parsing\ValueParser;

/**
 * Propone mapeos de valores (`value_maps`) para el tipo y el estado de documento de ventas a partir de los valores DISTINTOS observados (ya
 * enmascarados y acotados por el muestreo). Determinista: léxico de abreviaturas y raíces; lo ambiguo queda sin resolver para revisión humana.
 *
 * - Un valor que el importador ya reconoce (`ValueParser::documentType` / `documentStatus`) no necesita mapeo.
 * - Una cotización, proforma, guía de remisión, orden o traspaso NO es una venta: no se mapea a «factura» (el importador lo marcará REVIEW_REQUIRED).
 * - Una letra suelta («A», «E», «X») es ambigua (¿Activa o Anulada?): sin resolver.
 * - Códigos 0/1 en un «estado» que no es una bandera de anulación: el importador lee `1` como «anulada»; se advierte (nunca se supone).
 * Los destinos pertenecen al vocabulario canónico que valida ValueMaps (invoice, sales_note, credit_note; completed, voided, pending).
 */
final class ValueMapProposer
{
    private const NOT_A_SALE = '/(cotiz|proforma|presup|pedido|orden|guia|remision|transfer|traspaso|ajuste|compra|reserva|consigna|garantia)|^(cot|prf|prof|ped|oc|gr|gre|ord|tr|aj)$/';

    /**
     * @param  list<array{value: string, count: int}>  $distinct
     * @return array{entries: array<string, array{to: string, score: float, count: int}>, unresolved: list<array{value: string, reason: string, count: int}>, recognized: list<string>, warnings: list<string>}
     */
    public function propose(string $field, array $distinct, bool $columnIsVoidFlag = false): array
    {
        $entries = [];
        $unresolved = [];
        $recognized = [];
        $warnings = [];
        $boolish = false;
        foreach ($distinct as $item) {
            $value = $item['value'];
            $count = (int) $item['count'];
            $key = ValueParser::key($value);
            if ($key === '') {
                continue;
            }
            $known = $field === 'document_type' ? ValueParser::documentType($value) : ValueParser::documentStatus($value);
            if ($known !== null) {
                $recognized[] = $value;
                $boolish = $boolish || ($field === 'status' && in_array($key, ['0', '1', 'true', 'false', 's', 'n', 'si', 'no'], true));

                continue;
            }
            [$to, $score, $reason] = $field === 'document_type' ? $this->documentType($key) : $this->status($key);
            if ($to === null) {
                $unresolved[] = ['value' => $value, 'reason' => $reason, 'count' => $count];
            } else {
                $entries[$value] = ['to' => $to, 'score' => $score, 'count' => $count];
            }
        }
        if ($boolish && ! $columnIsVoidFlag) {
            $warnings[] = 'La columna de estado trae códigos 0/1, S/N o sí/no que el importador interpreta como una bandera de anulación (1 = anulada). Si en el origen «1» significa activa o vigente, declare un mapeo de valores antes de aprobar.';
        }

        return ['entries' => $entries, 'unresolved' => $unresolved, 'recognized' => $recognized, 'warnings' => $warnings];
    }

    /** @return array{0: ?string, 1: float, 2: string} */
    private function documentType(string $key): array
    {
        return match (true) {
            preg_match(self::NOT_A_SALE, $key) === 1 => [null, 0.0, 'no_es_documento_de_venta'],
            preg_match('/^(ncr|n_c|nota_cred.*|notacredito|ncredito|nc_.*|devol.*)$/', $key) === 1 => ['credit_note', 0.90, ''],
            preg_match('/^(nota_vent.*|notaventa|n_v|nv_.*|recibo|ticket_.*|boleta_.*)$/', $key) === 1 => ['sales_note', 0.85, ''],
            preg_match('/^(fc|ft|fv|fa|fe|fac_.*|fact_.*|facturas?|factura_.*)$/', $key) === 1 => ['invoice', 0.90, ''],
            strlen($key) === 1 => [null, 0.0, 'abreviatura_ambigua'],
            default => [null, 0.0, 'no_reconocido'],
        };
    }

    /** @return array{0: ?string, 1: float, 2: string} */
    private function status(string $key): array
    {
        return match (true) {
            strlen($key) === 1 => [null, 0.0, 'abreviatura_ambigua'],
            preg_match('/^(anu|anul.*|canc|cancel.*|void.*|baja|dado_de_baja)$/', $key) === 1 => ['voided', 0.90, ''],
            preg_match('/^(rechaz.*|revers.*|elimin.*|inval.*|descart.*)$/', $key) === 1 => ['voided', 0.70, ''],
            preg_match('/^(pen|pend.*|borrador|draft|abiert.*|en_proceso|por_.*)$/', $key) === 1 => ['pending', 0.80, ''],
            preg_match('/^(aut|apr|cer|cerr|fin|emi|vig|ok|compl.*|conta.*|facturad.*)$/', $key) === 1 => ['completed', 0.75, ''],
            default => [null, 0.0, 'no_reconocido'],
        };
    }
}
