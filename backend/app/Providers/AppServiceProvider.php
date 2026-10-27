<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: se reinicia por petición/trabajo (seguro con Octane y workers de cola).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // Detecta consultas N+1 y asignaciones masivas indebidas en desarrollo/pruebas.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
