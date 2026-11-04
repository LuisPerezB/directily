<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Models\DecisionRecommendation;
use App\Services\Conversation\Gateway\DataScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Resumen del Decision Engine (GET /decisions/summary): conteos de recomendaciones por estado del workflow, tipo y etiqueta de atribución, las pendientes de asignar y las
 * que vencen en 24 horas. Solo lectura sobre lo ya persistido. Las del grupo de control de un experimento nunca se cuentan (el modelo no conoce los experimentos) y los
 * conteos son de lo VISIBLE para la persona según su alcance comercial (W6-A): un vendedor cuenta lo asignado a él y lo sin asignar de su cartera, igual que GET /decisions/summary.
 */
final class DecisionsSummaryTool extends ReadTool
{
    public function ability(): string
    {
        return 'decisions.view';
    }

    public function dataScope(): DataScope
    {
        return DataScope::CustomerPortfolio;
    }

    public function name(): string
    {
        return 'decisions_summary';
    }

    public function description(): string
    {
        return 'Resumen ya calculado de las recomendaciones del Decision Engine: cuántas hay por estado del workflow (sugerida, asignada, aprobada, en curso, ejecutada, medida…), por tipo y por '
            .'etiqueta de atribución, cuántas esperan asignación y cuántas vencen en 24 horas. Son oportunidades potenciales con atribución no demostrada, no ingresos recuperados.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => DecisionRecommendation::KINDS, 'description' => 'Tipo de recomendación a contar (opcional).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['kind' => ['sometimes', 'string', Rule::in(DecisionRecommendation::KINDS)]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $scope = $context->requireScope();
        $kind = isset($arguments['kind']) ? (string) $arguments['kind'] : null;
        $base = function () use ($scope, $kind): Builder {
            $query = DecisionRecommendation::query()->actionable()->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind));
            $scope->constrainRecommendations($query);

            return $query;
        };
        $count = fn (string $column): array => $base()->selectRaw("{$column} AS k, COUNT(*) AS n")->groupBy($column)->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();

        $total = $base()->count();
        $now = CarbonImmutable::now();
        $data = [
            'total' => $total,
            'by_status' => (object) $count('status'),
            'by_kind' => (object) $count('kind'),
            'by_attribution_label' => (object) $count('attribution_label'),
            'awaiting_assignment' => $base()->where('status', 'suggested')->count(),
            'expiring_within_24h' => $base()->whereIn('status', DecisionRecommendation::PRE_ACTION_STATUSES)->where('expires_at', '<=', $now->addDay())->where('expires_at', '>', $now)->count(),
            'latest_as_of' => ($latest = $base()->max('as_of')) !== null ? substr((string) $latest, 0, 10) : null,
            'attribution_note' => 'Las recomendaciones son oportunidades potenciales con atribución no demostrada; no son ingresos recuperados.',
            'scope' => $scope->isRestricted() ? 'alcance comercial del usuario' : 'toda la empresa',
        ];
        if ($total === 0) {
            return ToolResult::noData('No hay recomendaciones del Decision Engine en su alcance.', null, $data);
        }

        return ToolResult::ok($data, null);
    }

    /** @return array<string, mixed> */
    public function summarize(ToolResult $result): array
    {
        return ['status' => $result->status, 'total' => (int) ($result->data['total'] ?? 0), 'scope_restricted' => ($result->data['scope'] ?? '') === 'alcance comercial del usuario'];
    }
}
