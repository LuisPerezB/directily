<?php

declare(strict_types=1);

namespace Tests\Unit\Radar;

use App\Models\DecisionRecommendation;
use App\Services\Radar\AgendaStatusMap;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * V2.5 · W5-A (V25-AGD-006 y -007): los seis estados heredados de la Agenda V2.2, su matriz de transiciones y su correspondencia con el workflow Enterprise.
 */
final class AgendaStatusMapTest extends TestCase
{
    public function test_the_legacy_states_are_the_six_of_the_contract_in_the_same_order(): void
    {
        $this->assertSame(DecisionRecommendation::AGENDA_STATUSES, AgendaStatusMap::STATES);
        $this->assertSame(['pendiente', 'contactado', 'interesado', 'pedido_recibido', 'pospuesto', 'descartado'], AgendaStatusMap::STATES);
        $this->assertSame(AgendaStatusMap::STATES, array_keys(AgendaStatusMap::MAP));
        $this->assertSame(AgendaStatusMap::STATES, array_keys(AgendaStatusMap::TRANSITIONS));
    }

    public function test_the_mapping_table_to_the_workflow_is_the_documented_one_and_every_target_is_a_real_workflow_state(): void
    {
        $expected = [
            'pendiente' => 'suggested', 'contactado' => 'in_progress', 'interesado' => 'in_progress',
            'pedido_recibido' => 'executed', 'pospuesto' => null, 'descartado' => 'rejected',
        ];
        foreach ($expected as $legacy => $workflow) {
            $this->assertSame($workflow, AgendaStatusMap::impliedWorkflowStatus($legacy), $legacy);
            $this->assertSame($workflow, AgendaStatusMap::MAP[$legacy]['workflow']);
            $this->assertNotSame('', AgendaStatusMap::MAP[$legacy]['note'], 'cada regla del mapeo se explica en palabras');
            if ($workflow !== null) {
                $this->assertContains($workflow, DecisionRecommendation::STATUSES);
            }
        }
        // Un pendiente con vendedor asignado equivale a «assigned»; los demás estados no dependen de la asignación.
        $this->assertSame('assigned', AgendaStatusMap::impliedWorkflowStatus('pendiente', true));
        $this->assertSame('in_progress', AgendaStatusMap::impliedWorkflowStatus('contactado', true));
        $this->assertSame(AgendaStatusMap::VERSION, 'radar-agenda-map-v1');
    }

    public function test_an_unknown_legacy_state_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgendaStatusMap::impliedWorkflowStatus('enviado');
    }

    public function test_the_whole_transition_matrix(): void
    {
        $allowed = [
            'pendiente' => ['contactado', 'pedido_recibido', 'pospuesto', 'descartado'],
            'contactado' => ['interesado', 'pedido_recibido', 'pospuesto', 'descartado'],
            'interesado' => ['contactado', 'pedido_recibido', 'pospuesto', 'descartado'],
            'pospuesto' => ['pendiente', 'contactado', 'pedido_recibido', 'descartado'],
            'pedido_recibido' => [],
            'descartado' => [],
        ];
        foreach (AgendaStatusMap::STATES as $from) {
            foreach (AgendaStatusMap::STATES as $to) {
                $this->assertSame(in_array($to, $allowed[$from], true), AgendaStatusMap::canTransition($from, $to), "{$from} → {$to}");
            }
            $this->assertSame($allowed[$from], AgendaStatusMap::allowedFrom($from));
            $this->assertFalse(AgendaStatusMap::canTransition($from, $from), 'no hay transición a sí mismo');
        }
        $this->assertFalse(AgendaStatusMap::canTransition('pendiente', 'enviado'), 'un destino que no es de los seis nunca se permite');
    }

    public function test_final_states_are_dead_ends_and_every_other_state_can_reach_one(): void
    {
        foreach (AgendaStatusMap::CLOSED_STATES as $final) {
            $this->assertSame([], AgendaStatusMap::allowedFrom($final));
            $this->assertTrue(AgendaStatusMap::isClosed($final));
        }
        foreach (['pendiente', 'contactado', 'interesado', 'pospuesto'] as $open) {
            $this->assertFalse(AgendaStatusMap::isClosed($open));
            $seen = [$open];
            $queue = [$open];
            while ($queue !== []) {
                $current = array_shift($queue);
                foreach (AgendaStatusMap::allowedFrom($current) as $next) {
                    if (! in_array($next, $seen, true)) {
                        $seen[] = $next;
                        $queue[] = $next;
                    }
                }
            }
            $this->assertNotEmpty(array_intersect($seen, AgendaStatusMap::CLOSED_STATES), "{$open} debe poder cerrarse");
        }
    }

    public function test_a_missing_agenda_status_reads_as_pending(): void
    {
        $this->assertSame('pendiente', AgendaStatusMap::effective(null));
        $this->assertSame('pendiente', AgendaStatusMap::effective(''));
        $this->assertSame('contactado', AgendaStatusMap::effective('contactado'));
        $this->assertSame(AgendaStatusMap::allowedFrom('pendiente'), AgendaStatusMap::allowedFrom(null));
        $this->assertTrue(AgendaStatusMap::canTransition(null, 'contactado'));
    }

    public function test_the_published_table_has_one_row_per_state_with_its_next_states(): void
    {
        $table = AgendaStatusMap::table();

        $this->assertSame(AgendaStatusMap::STATES, array_column($table, 'legacy'));
        foreach ($table as $row) {
            $this->assertSame(['legacy', 'workflow', 'note', 'allowed_next'], array_keys($row));
            $this->assertSame(AgendaStatusMap::TRANSITIONS[$row['legacy']], $row['allowed_next']);
        }
    }

    public function test_the_workflow_states_that_accept_feedback_are_the_open_ones(): void
    {
        $this->assertSame(['suggested', 'assigned', 'reviewed', 'approved', 'in_progress', 'executed'], AgendaStatusMap::FEEDBACK_WORKFLOW_STATUSES);
        $this->assertSame(['expired', 'measured', 'closed'], AgendaStatusMap::TERMINAL_WORKFLOW_STATUSES);
        foreach (['rejected', 'expired', 'measured', 'closed'] as $closed) {
            $this->assertNotContains($closed, AgendaStatusMap::FEEDBACK_WORKFLOW_STATUSES);
        }
        foreach (array_merge(AgendaStatusMap::FEEDBACK_WORKFLOW_STATUSES, AgendaStatusMap::TERMINAL_WORKFLOW_STATUSES) as $status) {
            $this->assertContains($status, DecisionRecommendation::STATUSES);
        }
    }
}
