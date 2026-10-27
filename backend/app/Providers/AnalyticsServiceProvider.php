<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Forecasting\EngineClient;
use Illuminate\Support\ServiceProvider;

/**
 * Registro de servicios del módulo analítico (backend-analytics).
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EngineClient::class, fn () => EngineClient::fromConfig());
    }
}
