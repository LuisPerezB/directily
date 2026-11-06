<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ADR-V26-01 · F2: retry_after de la conexión que atiende un trabajo debe superar su $timeout. Si no, Redis lo reentrega mientras
 * sigue corriendo y, con tries=1, el segundo intento lo marca fallido aunque el primero termine bien.
 */
final class QueueRetryAfterTest extends TestCase
{
    /** Margen mínimo entre el $timeout de un trabajo y retry_after de su conexión. */
    private const MARGIN_SECONDS = 60;

    /** @return array<class-string, int> $timeout declarado como valor por defecto en cada trabajo de app/Jobs */
    private function declaredTimeouts(): array
    {
        $timeouts = [];
        foreach (Finder::create()->files()->name('*.php')->in(app_path('Jobs')) as $file) {
            $class = 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (! class_exists($class)) {
                continue;
            }
            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            if (is_int($defaults['timeout'] ?? null)) {
                $timeouts[$class] = $defaults['timeout'];
            }
        }

        return $timeouts;
    }

    public function test_the_long_connection_outlives_every_declared_job_timeout(): void
    {
        $timeouts = $this->declaredTimeouts();
        $this->assertNotEmpty($timeouts, 'No se encontró ningún trabajo con $timeout declarado: la prueba dejó de vigilar algo.');

        $retryAfter = (int) config('queue.connections.redis-long.retry_after');
        foreach ($timeouts as $class => $timeout) {
            $this->assertGreaterThanOrEqual(
                $timeout + self::MARGIN_SECONDS,
                $retryAfter,
                "{$class} declara \$timeout={$timeout} s y retry_after de `redis-long` es {$retryAfter} s: Redis lo reentregaría en plena ejecución.",
            );
        }
    }

    public function test_the_long_connection_shares_redis_and_queue_names_with_the_default_one(): void
    {
        // Quien encola usa `redis`; el worker reserva con `redis-long`. Solo funciona si ambas apuntan a las mismas claves.
        $default = config('queue.connections.redis');
        $long = config('queue.connections.redis-long');

        $this->assertSame('redis', $long['driver']);
        $this->assertSame($default['connection'], $long['connection']);
        $this->assertSame($default['queue'], $long['queue']);
    }

    public function test_compose_runs_each_worker_on_a_connection_that_outlives_its_jobs(): void
    {
        $path = base_path('../docker-compose.yml');
        if (! is_file($path)) {
            $this->markTestSkipped('docker-compose.yml no está disponible (p. ej. dentro de la imagen del backend).');
        }
        $compose = (string) file_get_contents($path);

        // Worker de importaciones y tareas generales: conexión larga.
        $this->assertMatchesRegularExpression('/"queue:work",\s*"redis-long",\s*"--queue=imports,default"/', $compose);
        $this->assertSame(1, preg_match('/REDIS_LONG_QUEUE_RETRY_AFTER:\s*"(\d+)"/', $compose, $long));
        $this->assertGreaterThanOrEqual(max($this->declaredTimeouts()) + self::MARGIN_SECONDS, (int) $long[1]);

        // Worker de pronósticos: conexión normal, cuyo retry_after debe superar su --timeout y el de los trabajos del motor.
        $this->assertSame(1, preg_match('/"queue:work",\s*"redis",\s*"--queue=forecasting".*?"--timeout=(\d+)"/', $compose, $worker));
        $this->assertSame(1, preg_match('/REDIS_QUEUE_RETRY_AFTER:\s*"(\d+)"/', $compose, $short));
        $this->assertGreaterThanOrEqual((int) $worker[1] + self::MARGIN_SECONDS, (int) $short[1]);
        $this->assertGreaterThanOrEqual((int) config('engine.timeout', 900) + 60 + self::MARGIN_SECONDS, (int) $short[1]);
    }
}
