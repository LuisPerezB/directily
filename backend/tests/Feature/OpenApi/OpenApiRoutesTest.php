<?php

declare(strict_types=1);

namespace Tests\Feature\OpenApi;

use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * La especificación pública (docs/openapi/predictily-api.yaml, generada por infra/scripts/merge_openapi.py) describe EXACTAMENTE las
 * rutas reales de /api/v1: ni faltan operaciones ni sobran. Exclusiones: ninguna. Lee el YAML generado (formato de bloque regular)
 * sin depender de una librería YAML; la validación OpenAPI completa la hace `merge_openapi.py --strict`.
 */
class OpenApiRoutesTest extends TestCase
{
    private const SPEC = __DIR__.'/../../../../docs/openapi/predictily-api.yaml';

    /** Operaciones reales que se excluyen a propósito de la documentación (con motivo). Vacío. */
    private const EXCLUDED = [];

    private static function normalize(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $path);
    }

    /** @return list<string> "METODO /ruta" documentadas */
    private function documented(): array
    {
        $lines = preg_split('/\R/', (string) file_get_contents(self::SPEC)) ?: [];
        $inPaths = false;
        $path = null;
        $ops = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'paths:')) {
                $inPaths = true;

                continue;
            }
            if ($inPaths && preg_match('/^\S/', $line) === 1) {
                break;   // siguiente sección de primer nivel (components:)
            }
            if ($inPaths && preg_match('/^  (\/\S*):\s*$/', $line, $m) === 1) {
                $path = self::normalize($m[1]);
            } elseif ($inPaths && $path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m) === 1) {
                $ops[] = strtoupper($m[1]).' '.$path;
            }
        }

        return $ops;
    }

    /** @return list<string> */
    private function real(): array
    {
        $ops = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            $path = self::normalize('/'.ltrim(substr($route->uri(), strlen('api/v1')), '/'));
            foreach ($route->methods() as $method) {
                if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    $ops[] = "{$method} {$path}";
                }
            }
        }

        return $ops;
    }

    public function test_every_real_api_route_is_documented_and_nothing_extra_is_documented(): void
    {
        $documented = $this->documented();
        $real = array_values(array_diff(array_unique($this->real()), self::EXCLUDED));

        $this->assertNotEmpty($documented, 'No se pudo leer la especificación.');
        $this->assertSame(count($documented), count(array_unique($documented)), 'Operaciones duplicadas en la especificación.');
        $this->assertSame([], array_values(array_diff($real, $documented)), 'Rutas reales sin documentar en docs/openapi (ejecute merge_openapi.py tras editar v2.yaml).');
        $this->assertSame([], array_values(array_diff($documented, $real)), 'Rutas documentadas que no existen.');
        $this->assertCount(count($real), $documented);
    }

    public function test_stage2_areas_are_documented(): void
    {
        $documented = implode("\n", $this->documented());
        foreach (['POST /assistant/messages', 'GET /assistant/status', 'POST /models/customer/rollback', 'POST /models/demand/rollback', 'GET /customer/predictions', 'POST /supply/plans',
            'GET /supply/plans/{}', 'PUT /data-coverage', 'GET /commitments/{}', 'POST /data-sources/{}/sync', 'GET /tenant/effective-config', 'GET /platform/extensions'] as $operation) {
            $this->assertStringContainsString($operation, $documented);
        }
    }
}
