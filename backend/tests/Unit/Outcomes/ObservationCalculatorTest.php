<?php

declare(strict_types=1);

namespace Tests\Unit\Outcomes;

use App\Services\Outcomes\ObservationCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** V2.5 · W5-C: resumen puro de las compras de un cliente: devoluciones restadas sin ventas negativas, «después de la acción» estricto y alcance por producto. */
final class ObservationCalculatorTest extends TestCase
{
    private function line(int $sale, int $product, float $qty, float $net, string $issued, bool $return = false, int $number = 1, int $revision = 0): object
    {
        return (object) ['sale_id' => $sale, 'revision' => $revision, 'line_number' => $number, 'customer_id' => 7, 'product_id' => $product, 'quantity' => $qty, 'net_amount' => $net,
            'is_return' => $return, 'document_type' => 'invoice', 'issued_at' => $issued];
    }

    public function test_returns_are_netted_per_product_and_never_go_below_zero(): void
    {
        $calc = new ObservationCalculator;

        $r = $calc->summarize([
            $this->line(1, 10, 5, 40, '2026-10-01 10:00:00+00'),
            $this->line(2, 10, 2, 16, '2026-10-02 10:00:00+00', true),
            $this->line(3, 11, 1, 9, '2026-10-02 11:00:00+00'),
            $this->line(4, 11, 4, 36, '2026-10-03 11:00:00+00', true),   // devuelve más de lo comprado en la ventana (compras anteriores)
            $this->line(5, 12, 3, 30, '2026-10-03 12:00:00+00', true),   // solo devolución
        ], null, null);

        $this->assertEqualsWithDelta(3.0, $r['by_product'][10]['net_units'], 1e-9);
        $this->assertEqualsWithDelta(24.0, $r['by_product'][10]['net_amount'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $r['by_product'][11]['net_units'], 1e-9, 'se queda en cero, no en −3');
        $this->assertEqualsWithDelta(0.0, $r['by_product'][11]['net_amount'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $r['by_product'][12]['net_units'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $r['net_units'], 1e-9);
        $this->assertEqualsWithDelta(24.0, $r['net_amount'], 1e-9);
        $this->assertEqualsWithDelta(9.0, $r['returned_units'], 1e-9);
        $this->assertSame(2, $r['purchase_lines'], 'solo las líneas de venta son compras');
    }

    public function test_only_a_purchase_strictly_after_the_registered_action_counts_as_after(): void
    {
        $calc = new ObservationCalculator;
        $action = CarbonImmutable::parse('2026-10-05 12:00:00+00');
        $lines = [
            $this->line(1, 10, 1, 8, '2026-10-05 11:59:59+00'),   // antes
            $this->line(2, 10, 1, 8, '2026-10-05 12:00:00+00'),   // en el mismo instante: no es posterior
            $this->line(3, 10, 1, 8, '2026-10-05 12:00:01+00'),   // después
            $this->line(4, 10, 1, 8, '2026-10-09 09:00:00+00'),   // después
        ];

        $r = $calc->summarize($lines, null, $action);

        $this->assertSame(2, $r['purchases_after_action']);
        $this->assertSame(2, $r['purchases_before_action']);
        $this->assertSame(3, $r['first_after_action_ref'], 'la primera compra posterior a la acción');
        $this->assertSame(1, $r['first_ref']);
        $none = $calc->summarize($lines, null, null);
        $this->assertSame(0, $none['purchases_after_action'], 'sin acción registrada no hay «después»');
        $this->assertSame(0, $none['purchases_before_action']);
        $this->assertNull($none['first_after_action_ref']);
    }

    public function test_the_product_scope_limits_what_is_summarised(): void
    {
        $calc = new ObservationCalculator;
        $lines = [$this->line(1, 10, 2, 16, '2026-10-01 10:00:00+00'), $this->line(2, 11, 3, 30, '2026-10-01 10:00:00+00')];

        $this->assertEqualsWithDelta(2.0, $calc->summarize($lines, [10], null)['net_units'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $calc->summarize($lines, null, null)['net_units'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $calc->summarize($lines, [99], null)['net_units'], 1e-9);
        $this->assertSame([], $calc->summarize($lines, [99], null)['by_product']);
    }

    public function test_the_fingerprint_depends_on_the_lines_read_not_on_their_order(): void
    {
        $calc = new ObservationCalculator;
        $a = $this->line(1, 10, 2, 16, '2026-10-01 10:00:00+00');
        $b = $this->line(2, 10, 3, 24, '2026-10-02 10:00:00+00');

        $this->assertSame($calc->summarize([$a, $b], null, null)['fingerprint'], $calc->summarize([$b, $a], null, null)['fingerprint']);
        $this->assertNotSame($calc->summarize([$a, $b], null, null)['fingerprint'], $calc->summarize([$a], null, null)['fingerprint']);
        $changed = $this->line(2, 10, 3, 25, '2026-10-02 10:00:00+00');
        $this->assertNotSame($calc->summarize([$a, $b], null, null)['fingerprint'], $calc->summarize([$a, $changed], null, null)['fingerprint'], 'una corrección de importe cambia la huella');
        $this->assertSame(64, strlen($calc->summarize([], null, null)['fingerprint']));
    }

    public function test_sale_references_are_ids_only_and_capped(): void
    {
        $calc = new ObservationCalculator;
        $lines = [];
        for ($i = 1; $i <= 30; $i++) {
            $lines[] = $this->line($i, 10, 1, 8, '2026-10-01 10:00:00+00', false, $i);
        }

        $r = $calc->summarize($lines, null, null);

        $this->assertCount(20, $r['sale_ids']);
        $this->assertSame(range(1, 20), $r['sale_ids']);
        $this->assertSame(30, $r['purchase_lines']);
    }
}
