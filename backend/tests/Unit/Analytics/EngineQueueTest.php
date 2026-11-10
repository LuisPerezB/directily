<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Jobs\Customer\RunCustomerPrediction;
use App\Jobs\Customer\RunCustomerTraining;
use App\Jobs\Forecasting\EvaluateActuals;
use App\Jobs\Forecasting\RefreshReadiness;
use App\Jobs\Forecasting\RunForecastPipeline;
use App\Jobs\Supply\RunSupplyPlan;
use App\Services\Forecasting\Support\EngineQueue;
use Tests\TestCase;

/** ADR-V26-01 · F3: los trabajos cortos del motor no comparten cola con los entrenamientos cuando el despliegue las separa. */
final class EngineQueueTest extends TestCase
{
    public function test_without_a_light_queue_every_engine_job_shares_the_same_queue(): void
    {
        config(['engine.queue' => 'forecasting', 'engine.light_queue' => null]);

        $this->assertSame('forecasting', EngineQueue::heavy());
        $this->assertSame('forecasting', EngineQueue::light());
        $this->assertSame('forecasting', (new RunCustomerPrediction(1, 2, 3))->queue);
        $this->assertSame('forecasting', (new EvaluateActuals(1))->queue);

        config(['engine.light_queue' => '']);   // variable definida pero vacía en .env
        $this->assertSame('forecasting', EngineQueue::light());
    }

    public function test_with_a_light_queue_short_jobs_leave_the_heavy_queue(): void
    {
        config(['engine.queue' => 'forecasting', 'engine.light_queue' => 'forecasting-light']);

        // Cortos: de ellos dependen el radar y las decisiones de la mañana.
        $this->assertSame('forecasting-light', (new RunCustomerPrediction(1, 2, 3))->queue);
        $this->assertSame('forecasting-light', (new EvaluateActuals(1))->queue);
        $this->assertSame('forecasting-light', (new RefreshReadiness(1))->queue);

        // Largos: entrenamiento y pronóstico.
        $this->assertSame('forecasting', (new RunForecastPipeline(1, 2, 3))->queue);
        $this->assertSame('forecasting', (new RunCustomerTraining(1, 2, 3))->queue);
        $this->assertSame('forecasting', (new RunSupplyPlan(1, 2, 3))->queue);
    }
}
