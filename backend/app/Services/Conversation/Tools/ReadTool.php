<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Tenant;
use App\Services\Conversation\Gateway\DataScope;
use App\Services\Forecasting\Support\CoreServices;

/** Base común de las herramientas de solo lectura: horizonte autorizado por el plan y límite de filas. */
abstract class ReadTool implements AssistantTool
{
    public function isEnabled(): bool
    {
        return true;
    }

    public function ability(): string
    {
        return 'forecast.view';
    }

    /** Por defecto, resultados que no se atribuyen a un cliente; las herramientas por cliente o de gestión lo sobrescriben. */
    public function dataScope(): DataScope
    {
        return DataScope::Operational;
    }

    /**
     * Estado, procedencia (corrida, corte, calidad) y tamaño de las listas del resultado: nada de valores de negocio, nombres ni códigos.
     *
     * @return array<string, mixed>
     */
    public function summarize(ToolResult $result): array
    {
        $summary = ['status' => $result->status];
        $envelope = $result->envelope ?? [];
        foreach (['core', 'as_of', 'run_version', 'quality_status', 'result_type'] as $key) {
            if (isset($envelope[$key]) && (is_scalar($envelope[$key]))) {
                $summary[$key] = $envelope[$key];
            }
        }
        $rows = [];
        foreach ((array) ($result->data ?? []) as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z_]{1,40}$/', $key) === 1 && is_array($value) && array_is_list($value)) {
                $rows[$key] = count($value);
            }
        }
        if ($rows !== []) {
            $summary['rows'] = $rows;
        }

        return $summary;
    }

    /**
     * Horizonte pedido (que el plan debe autorizar: si no, PlanLimitExceededException → plan_limit y no se revela nada) o el
     * horizonte por defecto de la empresa si el plan lo permite, o el mayor permitido del dominio de la herramienta.
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<int>  $domain
     */
    protected function horizon(array $arguments, Tenant $tenant, array $domain): int
    {
        $allowed = array_values(array_intersect($domain, CoreServices::allowedHorizons($tenant)));
        if (isset($arguments['horizon'])) {
            $horizon = (int) $arguments['horizon'];
            CoreServices::assertHorizon($tenant, $horizon);

            return $horizon;
        }
        if ($allowed === []) {
            throw new PlanLimitExceededException('horizons', null, null, 'Su plan no incluye horizontes para esta consulta.');
        }
        $default = (int) ($tenant->effectiveSettings()['default_horizon_days'] ?? 30);

        return in_array($default, $allowed, true) ? $default : max($allowed);
    }

    /** @param array<string, mixed> $arguments */
    protected function limit(array $arguments, int $default = 5): int
    {
        $max = max(1, (int) config('conversation.limits.max_rows_per_tool', 10));

        return max(1, min($max, (int) ($arguments['limit'] ?? $default)));
    }

    /** @return array<string, mixed> propiedad de esquema JSON de `horizon` */
    protected function horizonSchema(array $domain): array
    {
        return ['type' => 'integer', 'enum' => $domain, 'description' => 'Horizonte en días. Si se omite, se usa el de la empresa. El plan puede no incluir todos.'];
    }

    /** @return array<string, mixed> */
    protected function limitSchema(): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) config('conversation.limits.max_rows_per_tool', 10), 'description' => 'Número máximo de filas.'];
    }
}
