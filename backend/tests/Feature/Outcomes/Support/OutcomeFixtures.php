<?php

declare(strict_types=1);

namespace Tests\Feature\Outcomes\Support;

use App\Contracts\Decision\RecommendationSink;
use App\Enums\TenantRole;
use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Decision\Workflow;
use App\Services\Outcomes\OutcomeMeasurementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Decision\Support\DecisionFixtures;

/**
 * Mundo determinista FICTICIO de la medición de outcomes (V2.5 · W5-C): el de W5-B (`DecisionFixtures`) con el corte en el PASADO, de modo que la ventana de
 * observación (as_of + 1 … as_of + horizonte) y el margen de espera ya hayan pasado, más ayudas para registrar compras por cliente, llevar una recomendación a
 * «ejecutada» con la hora de la acción fijada y asignar el experimento con grupos conocidos.
 */
trait OutcomeFixtures
{
    use DecisionFixtures;

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Guayaquil')->startOfDay();
    }

    /**
     * @return array{tenant: Tenant, owner: User, admin: User, analyst: User, analyst2: User, viewer: User, cat: array, run: int, asOf: string}
     */
    protected function outcomeWorld(int $daysAgo = 40, array $tenantAttributes = []): array
    {
        return $this->decisionWorld($this->today()->subDays($daysAgo)->toDateString(), true, $tenantAttributes);
    }

    /** Cliente adicional (el catálogo trae c1, c2 y el genérico c3). */
    protected function newCustomer(Tenant $tenant, string $code): int
    {
        return (int) DB::table('customers')->insertGetId([
            'tenant_id' => $tenant->id, 'external_code' => $code, 'customer_type' => 'company', 'is_generic' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<int> */
    protected function newCustomers(Tenant $tenant, int $n, string $prefix = 'CLI-T'): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['tenant_id' => $tenant->id, 'external_code' => sprintf('%s%04d', $prefix, $i), 'customer_type' => 'company', 'is_generic' => false, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('customers')->insert($rows);

        return DB::table('customers')->where('tenant_id', $tenant->id)->where('external_code', 'like', $prefix.'%')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Documento de venta de un cliente con UNA línea. `$knownFrom` (ISO) lo declara ingestión incremental con esa hora de conocimiento (llegada tardía);
     * sin él es carga histórica (conocido desde el día de la venta). `$issuedAt` fija la hora de emisión.
     */
    protected function purchase(Tenant $tenant, int $customerId, int $product, string $date, float $qty, float $price, ?float $cost, ?string $knownFrom = null, ?string $issuedAt = null, bool $isReturn = false, string $status = 'completed', string $docType = 'invoice'): int
    {
        $this->documentCounter++;
        $branch = (int) DB::table('branches')->where('tenant_id', $tenant->id)->value('id');
        $saleId = (int) DB::table('sales')->insertGetId([
            'tenant_id' => $tenant->id, 'branch_id' => $branch, 'customer_id' => $customerId, 'document_type' => $docType,
            'document_number' => '002-001-'.str_pad((string) (100000 + $this->documentCounter + random_int(0, 99999)), 9, '0', STR_PAD_LEFT),
            'status' => $status, 'issued_at' => $issuedAt ?? $date.' 12:00:00-05', 'sale_date' => $date, 'currency' => 'USD', 'source_hash' => str_repeat('b', 64),
            'ingestion_mode' => $knownFrom === null ? 'backfill' : 'incremental', 'version_known_from' => $knownFrom, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'tenant_id' => $tenant->id, 'sale_id' => $saleId, 'line_number' => 1, 'product_id' => $product, 'branch_id' => $branch, 'sale_date' => $date,
            'quantity' => $qty, 'unit_price' => $price, 'discount_amount' => 0, 'net_amount' => $qty * $price, 'tax_amount' => 0, 'unit_cost' => $cost,
            'is_return' => $isReturn, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $saleId;
    }

    /** Propone un contacto cliente × producto (por el Decision Engine) en el corte del mundo. @param array<string, mixed> $override */
    protected function contactFor(array $w, int $customerId, string $product = 'p1', array $override = []): DecisionRecommendation
    {
        return $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w, $override + [
            'customer_id' => $customerId, 'product_id' => $w['cat']['products'][$product],
        ])));
    }

    /**
     * Transición humana del workflow con el analista del mundo, mandando siempre el `expected_version` vigente (la versión leída): lo exija o no la regla, así
     * la ayuda sirve con el bloqueo optimista de W5-B tanto en las transiciones de revisión como en las demás.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function stepAs(array $w, int $recommendationId, string $to, string $key, array $extra = [], ?User $actor = null, TenantRole $role = TenantRole::Analyst): void
    {
        $this->withinTenant($w['tenant'], function () use ($recommendationId, $to, $key, $extra, $actor, $role, $w): void {
            $workflow = app(Workflow::class);
            $workflow->transition($recommendationId, $to, $actor ?? $w['analyst'], $role, $key, ['expected_version' => $workflow->versionOf($recommendationId)] + $extra);
        });
    }

    /** Lleva un contacto a «ejecutado» con el analista y fija la hora en que quedó registrada la acción. */
    protected function executeAt(array $w, DecisionRecommendation $rec, string $at): void
    {
        $id = (int) $rec->id;
        $this->stepAs($w, $id, 'assigned', 'o-assign-'.$id, ['assignee_user_id' => $w['analyst']->id]);
        $this->stepAs($w, $id, 'reviewed', 'o-review-'.$id);
        $this->stepAs($w, $id, 'in_progress', 'o-start-'.$id);
        $this->stepAs($w, $id, 'executed', 'o-exec-'.$id);
        DB::table('decision_events')->where('recommendation_id', $id)->where('to_status', 'executed')->update(['created_at' => $at]);
    }

    /**
     * Parámetros de outcomes de la empresa. Con `$smallSamples` baja los PISOS (solo en pruebas, con `config()`: 20 por grupo y 5 compradores en producción) para ejercitar la
     * mecánica con muestras pequeñas; el piso real lo prueban `IttAnalysisTest` y `OutcomeWindowAndSettingsTest`.
     *
     * @param  array<string, mixed>  $outcomes
     */
    protected function outcomeSettings(array $w, array $outcomes, bool $smallSamples = true): void
    {
        if ($smallSamples) {
            config(['outcomes.floors' => ['min_group_size' => 2, 'min_buyers_per_arm' => 1, 'bootstrap_resamples' => 500]]);
        }
        $tenant = $w['tenant']->refresh();
        $settings = $tenant->effectiveSettings();
        $settings['outcomes'] = array_merge($settings['outcomes'] ?? [], $outcomes);
        $tenant->forceFill(['settings' => $settings])->save();
    }

    protected function day(int $daysAgo): string
    {
        return $this->today()->subDays($daysAgo)->toDateString();
    }

    protected function iso(int $daysAgo, string $time = '10:00:00'): string
    {
        return $this->day($daysAgo).' '.$time.'-05';
    }

    /**
     * Mide las recomendaciones de la empresa del mundo.
     *
     * @return array<string, mixed>
     */
    protected function runMeasure(array $w, bool $dry = false, ?int $lag = null, ?CarbonImmutable $now = null): array
    {
        return app(OutcomeMeasurementService::class)->measure($w['tenant'], $now, $dry, $lag);
    }

    protected function statusOf(int $recommendationId): string
    {
        return (string) DB::table('decision_recommendations')->where('id', $recommendationId)->value('status');
    }

    /** @return array<string, mixed> */
    protected function outcomeOf(int $recommendationId): array
    {
        $row = DB::table('decision_outcomes')->where('recommendation_id', $recommendationId)->first();
        $this->assertNotNull($row, "la recomendación {$recommendationId} debería tener resultado");
        $row = (array) $row;
        $row['evidence'] = json_decode((string) $row['evidence'], true);

        return $row;
    }

    /**
     * Marca una recomendación de contacto como parte de un experimento con el grupo indicado, tal como lo deja el Decision Engine al generar (asignación
     * registrada con su política, grupo en la recomendación y clave del experimento en el evento de propuesta). Grupos conocidos, sin depender del hash.
     */
    protected function assignExperiment(Tenant $tenant, DecisionRecommendation $rec, string $group, string $key = 'exp-prueba', float $fraction = 0.5): void
    {
        DB::table('experiment_assignments')->insertOrIgnore([
            'tenant_id' => $tenant->id, 'experiment_key' => $key, 'unit_type' => 'customer', 'unit_key' => 'customer:'.$rec->customer_id, 'group' => $group,
            'policy' => json_encode(['algorithm' => 'sha256-uniform-v1', 'control_fraction' => $fraction, 'seed' => 'semilla-de-prueba', 'unit' => 'customer', 'stratification' => null]),
            'assigned_at' => now()->subMinute(),
        ]);
        DB::table('decision_recommendations')->where('id', $rec->id)->update(['experiment_group' => $group]);
        $event = DB::table('decision_events')->where('recommendation_id', $rec->id)->whereNull('from_status')->where('to_status', 'suggested')->first();
        $payload = json_decode((string) $event->payload, true);
        $payload['experiment'] = ['experiment_key' => $key, 'group' => $group];
        DB::table('decision_events')->where('id', $event->id)->update(['payload' => json_encode($payload)]);
    }
}
