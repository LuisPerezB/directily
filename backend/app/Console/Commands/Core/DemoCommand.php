<?php

declare(strict_types=1);

namespace App\Console\Commands\Core;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Crea las empresas de demostración (FICTICIAS) e importa data/demo/ con el importador real.
 *
 *   php artisan predictily:demo            # crea/actualiza (reimportar no duplica)
 *   php artisan predictily:demo --fresh    # elimina antes las empresas demo
 *   php artisan predictily:demo --forecast # además solicita un pronóstico por empresa
 */
final class DemoCommand extends Command
{
    protected $signature = 'predictily:demo {--fresh : Elimina y recrea las empresas demo} {--forecast : Solicita pronósticos al terminar} {--only= : Solo un dataset (ferreteria_andina|distribuidora_costa)}';

    protected $description = 'Carga las empresas de demostración ficticias usando el pipeline real de importación';

    public function handle(DemoDataService $demo): int
    {
        $this->warn('DATOS FICTICIOS: las empresas demo no representan a ninguna empresa real.');
        $slugs = $this->option('only') ? [(string) $this->option('only')] : array_keys((array) config('predictily.demo.tenants'));

        foreach ($slugs as $slug) {
            $config = config("predictily.demo.tenants.{$slug}");
            if ($config === null) {
                $this->error("Dataset desconocido: {$slug}");

                return self::FAILURE;
            }
            if ($this->option('fresh')) {
                $ids = DB::table('tenants')->where('name', $config['name'])->pluck('id');
                if ($ids->isNotEmpty()) {
                    DB::table('tenants')->whereIn('id', $ids)->delete();
                    $this->line("Empresa demo anterior eliminada ({$config['name']}).");
                }
            }
            $this->info("== {$config['name']}");
            $started = microtime(true);
            try {
                $tenant = $demo->ensureTenant($slug);
                $user = User::query()->where('email', $config['email'])->first();
                $demo->load($tenant, $slug, $user, fn (string $msg) => $this->line($msg));
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line(sprintf('Listo en %.1f s · usuario: %s · contraseña (solo demo): %s', microtime(true) - $started, $config['email'], config('predictily.demo.password')));

            if ($this->option('forecast')) {
                $this->requestForecast($tenant, $user);
            }
        }

        return self::SUCCESS;
    }

    private function requestForecast(Tenant $tenant, ?User $user): void
    {
        $service = 'App\\Services\\Forecasting\\ForecastRunService';
        if (! class_exists($service)) {
            $this->warn('El servicio de pronósticos no está disponible en esta instalación.');

            return;
        }
        try {
            $run = app($service)->request($tenant, $user, 90, null, 'system');
            $this->line("Pronóstico solicitado (versión {$run->version}). Requiere el worker de cola 'forecasting' y el motor Python activos.");
        } catch (Throwable $e) {
            $this->warn('No se pudo solicitar el pronóstico: '.$e->getMessage());
        }
    }
}
