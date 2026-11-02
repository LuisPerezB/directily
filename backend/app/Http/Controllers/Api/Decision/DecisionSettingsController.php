<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Decision;

use App\Http\Controllers\Controller;
use App\Http\Requests\Decision\DecisionSettingsRequest;
use App\Models\Tenant;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\ScoreFormula;
use App\Services\Forecasting\Support\CoreServices;
use App\Support\Results\JsonMap;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Parámetros del Decision Engine de la empresa (`tenants.settings.decision`), entre ellos la fracción de control del experimento (por defecto 0 = sin
 * experimento). Todos los valores por defecto son PROVISIONALES: se muestran con su procedencia para que la empresa los apruebe en el piloto.
 *
 * - GET /decisions/settings  valores efectivos, valores por defecto y la versión de la fórmula del score (`decisions.view`).
 * - PUT /decisions/settings  actualiza las claves enviadas (`settings.manage`); con fracción de control > 0 registra una semilla si no hay una.
 */
final class DecisionSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->context->require())]);
    }

    public function update(DecisionSettingsRequest $request): JsonResponse
    {
        $tenant = $this->context->require();
        $changes = $request->validated();
        $before = DecisionSettings::forTenant($tenant)->all();   // valores efectivos antes del cambio (los de la empresa sobre los por defecto)
        $stored = (array) ($tenant->effectiveSettings()['decision'] ?? []);
        foreach ($changes as $key => $value) {
            $stored[$key] = is_array($value) && ! array_is_list($value) ? array_replace((array) ($stored[$key] ?? []), $value) : $value;
        }
        $generatedSeed = false;
        $fraction = (float) ($stored['control_fraction'] ?? 0);
        if ($fraction > 0 && empty($stored['experiment_seed'])) {
            $stored['experiment_seed'] = bin2hex(random_bytes(16));   // la semilla del experimento queda registrada en la configuración y en cada asignación
            $generatedSeed = true;
        }
        $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['decision' => $stored])])->save();
        $after = DecisionSettings::forTenant($tenant)->all();

        // Auditoría con el valor anterior y el nuevo de cada clave que CAMBIÓ (quién aprueba qué, el umbral de costo, el costo de contacto…). La semilla del experimento
        // es un secreto: se registra que cambió o se generó, NUNCA su valor.
        $diff = [];
        foreach (array_keys($changes) as $key) {
            if ($key === 'experiment_seed') {
                $diff[$key] = ['changed' => ($before[$key] ?? null) !== ($after[$key] ?? null)];

                continue;
            }
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                $diff[$key] = ['old' => $before[$key] ?? null, 'new' => $after[$key] ?? null];
            }
        }
        if ($generatedSeed) {
            $diff['experiment_seed'] = ['generated' => true];
        }
        CoreServices::audit('decision.settings_updated', $tenant, ['fields' => array_keys($diff), 'changes' => $diff, 'control_fraction' => $fraction]);

        return response()->json(['data' => $this->payload($tenant->refresh())]);
    }

    /** @return array<string, mixed> */
    private function payload(Tenant $tenant): array
    {
        $settings = DecisionSettings::forTenant($tenant);
        $effective = $settings->all();
        $effective['experiment_seed'] = $effective['experiment_seed'] === null ? null : '[registrada]';   // la semilla no se devuelve en claro; queda en experiment_assignments.policy

        return [
            'settings' => JsonMap::orEmpty($effective),
            'defaults' => JsonMap::orEmpty(DecisionSettings::DEFAULTS),
            'score_formula' => ['id' => ScoreFormula::ID, 'revision' => ScoreFormula::REVISION, 'version' => ScoreFormula::fromSettings($settings)->version(), 'parameters' => JsonMap::orEmpty(ScoreFormula::fromSettings($settings)->parameters())],
            'currency' => $settings->currency(),
            'note' => 'Los valores por defecto son provisionales y no son umbrales universales: se aprueban con datos reales de la empresa en el piloto. Fracción de control 0 = sin experimento.',
        ];
    }
}
