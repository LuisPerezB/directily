<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Services\Demo\DemoDataService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Las empresas demo declaran `settings.data_origin = demo`: el ROI y los resultados (W5-C) lo advierten. */
final class DemoDataOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_companies_declare_their_data_origin_and_keep_other_settings(): void
    {
        $this->seed(PlanSeeder::class);
        $service = app(DemoDataService::class);
        $slug = array_key_first((array) config('predictily.demo.tenants'));
        $this->assertNotNull($slug, 'hay al menos un dataset demo configurado');

        $tenant = $service->ensureTenant($slug);
        $this->assertSame('demo', $tenant->fresh()->settings['data_origin'] ?? null);

        $tenant->settings = array_merge($tenant->settings ?? [], ['service_level' => 0.9]);
        $tenant->save();
        $again = $service->ensureTenant($slug);
        $this->assertSame($tenant->id, $again->id);
        $this->assertSame('demo', $again->fresh()->settings['data_origin']);
        $this->assertSame(0.9, $again->fresh()->settings['service_level']);
    }
}
