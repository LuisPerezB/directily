<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\Mapping\ColumnMatcher;
use Tests\TestCase;

final class ColumnMatcherTest extends TestCase
{
    public function test_suggests_mapping_from_typical_ecuadorian_exports(): void
    {
        $fields = (new ImportFieldRegistry)->fields(ImportEntity::Sales);
        $mapping = (new ColumnMatcher)->suggest($fields, ['N° Comprobante', 'FECHA EMISIÓN', 'Establecimiento', 'Cód. Producto', 'Descripción', 'Cant.', 'P. Unitario', 'Dscto', 'IVA 15%', 'Total Línea', 'RUC/CI Cliente', 'Columna rara']);

        $this->assertSame('N° Comprobante', $mapping['document_number']);
        $this->assertSame('FECHA EMISIÓN', $mapping['issued_at']);
        $this->assertSame('Establecimiento', $mapping['branch_code']);
        $this->assertSame('Cód. Producto', $mapping['product_sku']);
        $this->assertSame('Descripción', $mapping['product_name']);
        $this->assertSame('Cant.', $mapping['quantity']);
        $this->assertSame('P. Unitario', $mapping['unit_price']);
        $this->assertSame('Dscto', $mapping['discount']);
        $this->assertSame('IVA 15%', $mapping['tax']);
        $this->assertSame('Total Línea', $mapping['net_amount']);
        $this->assertSame('RUC/CI Cliente', $mapping['customer_code']);
        $this->assertNotContains('Columna rara', $mapping);
        $this->assertSame(count(array_filter($mapping)), count(array_unique(array_filter($mapping))), 'Una columna no puede asignarse a dos campos');
    }

    public function test_canonical_headers_map_exactly(): void
    {
        $fields = (new ImportFieldRegistry)->fields(ImportEntity::Inventory);
        $mapping = (new ColumnMatcher)->suggest($fields, ['snapshot_date', 'branch_code', 'product_sku', 'on_hand', 'reserved', 'in_transit', 'unit_cost']);
        foreach ($mapping as $field => $column) {
            $this->assertSame($field, $column);
        }
    }
}
