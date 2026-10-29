<?php

declare(strict_types=1);

namespace App\Services\DataCore\Units;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Conversiones DECLARADAS de unidades por empresa (tabla unit_conversions). Reglas:
 *  - misma unidad (tras normalizar tipografía): factor 1, no hace falta declarar nada;
 *  - unidad distinta sin conversión declarada ⇒ `null` (el llamador debe tratarlo como ERROR, nunca asumir 1);
 *  - preferencia: conversión del producto > general; sentido directo > inverso declarado (1/factor);
 *  - sin cadenas transitivas (A→B→C): cada paso debe declararse;
 *  - `valid_from`: se usa la vigente en la fecha pedida (la más reciente con valid_from ≤ fecha); sin fecha, la más reciente.
 */
final class UnitConverter
{
    /** @var array<string, list<array{id: int, product_id: ?int, valid_from: ?string, factor: float}>> */
    private array $index = [];

    public function __construct(Tenant $tenant)
    {
        foreach (DB::table('unit_conversions')->where('tenant_id', $tenant->id)->get(['id', 'product_id', 'from_unit', 'to_unit', 'factor', 'valid_from']) as $row) {
            $this->index[$this->key($row->from_unit, $row->to_unit)][] = [
                'id' => (int) $row->id,
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'valid_from' => $row->valid_from,
                'factor' => (float) $row->factor,
            ];
        }
    }

    public function resolve(?int $productId, ?string $from, ?string $to, ?string $onDate = null): ?ConversionResult
    {
        $from = UnitNormalizer::normalize($from);
        $to = UnitNormalizer::normalize($to);
        if ($from === null || $to === null) {
            return null;
        }
        if ($from === $to) {
            return new ConversionResult(1.0, 'general', false, null);
        }
        foreach ([true, false] as $productScope) { // el producto manda sobre lo general
            foreach ([[$from, $to, false], [$to, $from, true]] as [$a, $b, $inverse]) { // directo antes que inverso
                $candidates = array_filter($this->index[$this->key($a, $b)] ?? [], fn (array $c) => ($productScope
                    ? ($productId !== null && $c['product_id'] === $productId)
                    : $c['product_id'] === null)
                    && ($c['valid_from'] === null || $onDate === null || $c['valid_from'] <= $onDate));
                if ($candidates === []) {
                    continue;
                }
                usort($candidates, fn (array $x, array $y) => strcmp((string) $y['valid_from'], (string) $x['valid_from']));
                $best = $candidates[0];

                return new ConversionResult($inverse ? 1.0 / $best['factor'] : $best['factor'], $productScope ? 'product' : 'general', $inverse, $best['id']);
            }
        }

        return null;
    }

    private function key(string $from, string $to): string
    {
        return $from.'>'.$to;
    }
}
