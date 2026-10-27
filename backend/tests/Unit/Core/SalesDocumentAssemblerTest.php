<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Services\Sales\SalesDocumentAssembler;
use Tests\TestCase;

final class SalesDocumentAssemblerTest extends TestCase
{
    /** @return array<string, mixed> */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'doc_key' => 'invoice|F-1', 'document_type' => 'invoice', 'document_number' => 'F-1', 'status' => 'completed',
            'issued_at' => '2026-01-02 15:00:00+00:00', 'sale_date' => '2026-01-02', 'branch_code' => '001', 'customer_code' => null,
            'customer_segment' => null, 'customer_province' => null, 'channel' => null, 'currency' => 'USD', 'product_sku' => 'A',
            'product_name' => 'A', 'category' => null, 'quantity' => 2.0, 'unit_price' => 5.0, 'discount' => 1.0, 'tax' => 1.08,
            'net_amount' => 9.0, 'unit_cost' => null, 'is_return' => false, 'line_number' => null,
        ], $overrides);
    }

    public function test_groups_lines_assigns_numbers_and_computes_totals_and_hash(): void
    {
        $a = new SalesDocumentAssembler;
        $this->assertSame([], $a->add($this->line()));
        $this->assertSame([], $a->add($this->line(['product_sku' => 'B', 'net_amount' => 3.0, 'tax' => 0.0, 'discount' => 0.0])));
        $this->assertSame([], $a->add($this->line(['product_sku' => 'C', 'is_return' => true, 'net_amount' => 2.0, 'tax' => 0.0, 'discount' => 0.0])));
        $doc = $a->complete('invoice|F-1');

        $this->assertSame([1, 2, 3], array_column($doc['lines'], 'line_number'));
        $this->assertEqualsWithDelta(10.0, $doc['subtotal'], 1e-9); // 9 + 3 - 2 (devolución)
        $this->assertEqualsWithDelta(11.08, $doc['total'], 1e-9);
        $this->assertSame(64, strlen($doc['source_hash']));

        $b = new SalesDocumentAssembler;
        $b->add($this->line());
        $b->add($this->line(['product_sku' => 'B', 'net_amount' => 3.0, 'tax' => 0.0, 'discount' => 0.0]));
        $b->add($this->line(['product_sku' => 'C', 'is_return' => true, 'net_amount' => 2.0, 'tax' => 0.0, 'discount' => 0.0]));
        $this->assertSame($doc['source_hash'], $b->complete('invoice|F-1')['source_hash'], 'Mismo contenido → misma huella');
    }

    public function test_conflicting_header_invalidates_document(): void
    {
        $a = new SalesDocumentAssembler;
        $a->add($this->line());
        $issues = $a->add($this->line(['sale_date' => '2026-01-03', 'product_sku' => 'B']));
        $this->assertSame('document_conflict', $issues[0]->code);
        $this->assertNull($a->complete('invoice|F-1'));
    }

    public function test_identical_rows_are_skipped_and_repeated_line_numbers_rejected(): void
    {
        $a = new SalesDocumentAssembler;
        $a->add($this->line(['line_number' => 1]));
        $dup = $a->add($this->line(['line_number' => 1]));
        $this->assertSame('warning', $dup[0]->severity);
        $err = $a->add($this->line(['line_number' => 1, 'product_sku' => 'Z']));
        $this->assertTrue($err[0]->isError());
        $this->assertNull($a->complete('invoice|F-1'));
    }
}
