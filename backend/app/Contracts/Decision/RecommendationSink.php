<?php

declare(strict_types=1);

namespace App\Contracts\Decision;

use App\Models\DecisionRecommendation;

/**
 * Contrato del arquitecto (activación de W5, ADR-V25-12 y -13): punto único por el que cualquier fachada (Revenue Radar y Agenda de
 * W5-A, reposición, escenarios) propone una recomendación al Decision Engine. La implementación es de W5-B (DecisionEngine) y se
 * registra en AppServiceProvider; las fachadas NO escriben `decision_recommendations` directamente ni duplican su lógica.
 *
 * - `propose()` es idempotente: calcula `idempotency_key` = SHA-256(kind, as_of, horizonte, entidad, fuente) y, con los mismos
 *   insumos, devuelve la recomendación existente sin duplicarla. Con insumos nuevos crea otra y expira la anterior del mismo alcance
 *   solo si esa sigue en `suggested` sin seguimiento; si está EN CURSO (agenda contactado/interesado/pospuesto, o estado assigned,
 *   reviewed, approved o in_progress) no crea sucesora: devuelve la en curso y deja un evento `comment` (kind=refresh) con la fuente
 *   y los valores nuevos (revisión de W5-A/B, docs/decision/DECISION_ENGINE.md §14). La implementación ofrece además
 *   `proposeMany()` para lotes.
 * - Completa los campos del contrato de §11 que la fachada no aporta (score y su versión de fórmula, etiqueta de atribución, prioridad,
 *   expiración, asignación a experimento) y deja el evento inicial en `decision_events`.
 * - Nunca inventa valores económicos: sin costo confiable, `expected_contribution` queda NULL y la incertidumbre lo declara.
 */
interface RecommendationSink
{
    /**
     * @param  array<string, mixed>  $attributes  Campos de §11 que conoce la fachada: kind, as_of, horizon_days, entity_type,
     *                                            customer_id/product_id/branch_id, source_core_run_id/source_forecast_run_id,
     *                                            model_versions, probability, interval, assumptions, constraints, availability,
     *                                            action_cost, expected_gross_value, explanation, policy, next_step. Sin tenant_id
     *                                            (lo fija TenantContext) ni idempotency_key (la calcula la implementación).
     */
    public function propose(array $attributes): DecisionRecommendation;
}
