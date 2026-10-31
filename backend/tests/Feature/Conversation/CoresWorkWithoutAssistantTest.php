<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Conversation\Support\SeedsCustomerCatalogOnExistingTenant;
use Tests\Feature\Conversation\Support\SeedsSupplyPlan;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * E2-24 (parte autonomía): la ausencia del proveedor LLM no bloquea los cuatro cores (Datos, Demanda, Comportamiento de compra y
 * Abastecimiento), no hay ninguna salida de red hacia el proveedor y el asistente responde 503 sin afectar al resto de la API.
 */
class CoresWorkWithoutAssistantTest extends TestCase
{
    use AnalyticsFixtures;
    use CustomerFixtures;
    use RefreshDatabase;
    use SeedsCustomerCatalogOnExistingTenant;
    use SeedsSupplyPlan;

    public function test_the_four_cores_work_when_the_assistant_has_no_key(): void
    {
        Http::fake();
        Queue::fake();
        config(['conversation.enabled' => true, 'conversation.provider' => 'ollama_cloud', 'conversation.ollama.api_key' => null]);
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, '2026-10-20');
        $this->seedInventory($tenant, $cat, '2026-10-20');
        $this->seedForecastRun($tenant, $cat, '2026-10-20');
        $this->seedPredictionRun($tenant, $this->seedCustomerCatalogFor($tenant));
        $plan = $this->seedSupplyPlan($tenant, $cat);
        $api = $this->actingAsTenantUser($user, $tenant);

        // El asistente está apagado...
        $api->getJson('/api/v1/assistant/status')->assertOk()->assertJsonPath('data.enabled', false);
        $api->postJson('/api/v1/assistant/messages', ['message' => 'hola'])->assertStatus(503)->assertJsonPath('code', 'assistant_disabled');

        // ...y los cuatro cores siguen entregando y calculando resultados.
        // Datos
        $api->getJson('/api/v1/data-coverage')->assertOk();
        $api->getJson('/api/v1/data-quality/extended')->assertOk();
        // Demanda
        $api->getJson('/api/v1/dashboard/summary?horizon=30')->assertOk()->assertJsonPath('data.run.cutoff_date', '2026-10-20');
        $api->getJson('/api/v1/forecasts/products?horizon=30')->assertOk()->assertJsonCount(3, 'data');
        $api->getJson('/api/v1/inventory/recommendations')->assertOk();
        $api->postJson('/api/v1/forecast-runs', ['horizon_days' => 30])->assertStatus(202);
        // Comportamiento de compra
        $api->getJson('/api/v1/customer/predictions?horizon=30')->assertOk()->assertJsonPath('envelope.core', 'customer')->assertJsonCount(4, 'data');
        $api->postJson('/api/v1/customer/runs', ['operation' => 'predict'])->assertStatus(202);
        // Abastecimiento
        $api->getJson("/api/v1/supply/plans/{$plan['run']}")->assertOk()->assertJsonPath('data.plan.solver_status', 'optimal')->assertJsonCount(2, 'data.lines');
        $api->postJson('/api/v1/supply/plans', ['objective' => 'min_cost'])->assertStatus(202);

        Http::assertNothingSent();
    }
}
