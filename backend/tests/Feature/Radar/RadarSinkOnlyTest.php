<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Services\Radar\CommercialOpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A (decisión del arquitecto a): el Radar propone al Decision Engine SOLO por RecommendationSink::propose(); nunca escribe `decision_recommendations`
 * por su cuenta ni duplica su puntaje o su idempotencia.
 */
class RadarSinkOnlyTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    public function test_no_radar_source_creates_recommendations_directly(): void
    {
        $forbidden = [
            '/DecisionRecommendation::(query\(\)->)?(create|forceCreate|insert|upsert|firstOrCreate|updateOrCreate)\b/',
            '/new\s+DecisionRecommendation\b/',
            "/DB::table\\('decision_recommendations'\\)->(insert|insertGetId|upsert)/",
            '/->(score_formula_version|idempotency_key)\s*=[^=]/',
        ];
        $files = array_merge(
            glob(__DIR__.'/../../../app/Services/Radar/*.php') ?: [],
            glob(__DIR__.'/../../../app/Http/Controllers/Api/Radar/*.php') ?: [],
            glob(__DIR__.'/../../../app/Console/Commands/Radar/*.php') ?: [],
        );
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach ($forbidden as $pattern) {
                $this->assertSame(0, preg_match($pattern, $source), basename($file).' escribe decision_recommendations directamente ('.$pattern.')');
            }
        }
    }

    public function test_every_recommendation_in_the_table_arrives_through_the_sink_with_the_facade_fields_only(): void
    {
        $c = $this->radarCompany();
        $sink = $this->fakeSink();

        $this->withinTenant($c['tenant'], fn () => app(CommercialOpportunityService::class)->materialize($sink));

        $this->assertSame(12, count($sink->calls));
        $this->assertSame(12, DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->count(), 'una llamada por oportunidad (primera ejecución)');
        foreach ($sink->calls as $call) {
            $this->assertEmpty(array_diff(array_keys($call), CommercialOpportunityService::SINK_FIELDS));
            // El puntaje, la prioridad, la etiqueta de atribución, el experimento y la expiración los fija el Decision Engine, no el Radar.
            foreach (['score', 'score_formula_version', 'priority', 'attribution_label', 'experiment_group', 'expires_at', 'status', 'idempotency_key', 'tenant_id', 'incremental_estimate',
                'expected_contribution', 'availability', 'action_cost', 'next_step'] as $key) {
                $this->assertArrayNotHasKey($key, $call);
            }
        }
    }
}
