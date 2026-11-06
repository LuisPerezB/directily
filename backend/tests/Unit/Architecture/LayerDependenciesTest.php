<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regla de capas entre los dominios de `app/Services` (ADR-V26-01 · F7): un dominio solo puede depender de dominios de SU capa o de
 * una capa INFERIOR. Así un cambio en la capa de lectura no puede romper la ingesta, y cada capa se entiende sin leer las de arriba.
 *
 *   Lectura      Analytics · Conversation · Exports · Scenarios · Promotions · Privacy
 *      ↓
 *   Decisión     Radar · Decision · Outcomes · Pipeline
 *      ↓
 *   Núcleos      Forecasting · Customer · Supply · Models · Intelligence
 *      ↓
 *   Ingesta      Imports · DataCore · Sales · Catalog · Bridge · Connectors · Demo
 *      ↓
 *   Plataforma   Auth · Tenancy · Plans · Audit
 *
 * Qué comprueba: las sentencias `use App\Services\<Dominio>\...` de cada archivo de `app/Services`. No ve las referencias con nombre
 * completo dentro del código ni el acoplamiento a través de modelos, trabajos o eventos.
 *
 * Es un trinquete: las referencias que ya incumplían la regla están en KNOWN_EXCEPTIONS con su corrección pendiente. Una nueva hace
 * fallar la prueba; una corregida también, hasta que se quite de la lista (para que la lista nunca mienta).
 *
 * No necesita la aplicación ni la base de datos: solo lee archivos.
 */
final class LayerDependenciesTest extends TestCase
{
    /** De arriba (0) a abajo. */
    private const LAYERS = [
        'Lectura' => ['Analytics', 'Conversation', 'Exports', 'Scenarios', 'Promotions', 'Privacy'],
        'Decisión' => ['Radar', 'Decision', 'Outcomes', 'Pipeline'],
        'Núcleos' => ['Forecasting', 'Customer', 'Supply', 'Models', 'Intelligence'],
        'Ingesta' => ['Imports', 'DataCore', 'Sales', 'Catalog', 'Bridge', 'Connectors', 'Demo'],
        'Plataforma' => ['Auth', 'Tenancy', 'Plans', 'Audit'],
    ];

    /**
     * Referencias hacia una capa superior que existían al fijar la regla (2026-11-06). «archivo -> clase» => corrección pendiente.
     * Las tres clases de Analytics son ayudas de lectura compartidas que también usan las capas de decisión y de plataforma: lo
     * correcto es bajarlas de capa, pero las usan más de diez archivos cada una y no se movieron sin poder ejecutar las pruebas.
     */
    private const KNOWN_EXCEPTIONS = [
        'Decision/AggregateDemandLoader.php -> App\Services\Analytics\Support\ForecastNode' => 'bajar ForecastNode a Forecasting\Support',
        'Decision/Generators/InventoryMismatchGenerator.php -> App\Services\Analytics\RunResolver' => 'bajar RunResolver a Forecasting',
        'Decision/Generators/ReplenishmentGenerator.php -> App\Services\Analytics\RunResolver' => 'bajar RunResolver a Forecasting',
        'Radar/SupplyReadiness.php -> App\Services\Analytics\RunResolver' => 'bajar RunResolver a Forecasting',
        'Radar/CustomerCommercialProfile.php -> App\Services\Analytics\Support\SalesQuery' => 'bajar SalesQuery a Sales',
        'Plans/ActiveSeriesCounter.php -> App\Services\Analytics\Support\SalesQuery' => 'invertir con una interfaz en App\Contracts que implemente Sales',
    ];

    private static function servicesPath(): string
    {
        return dirname(__DIR__, 3).'/app/Services';
    }

    /** @return array<string, int> dominio => posición de su capa (0 = la de más arriba) */
    private static function ranks(): array
    {
        $ranks = [];
        foreach (array_values(self::LAYERS) as $position => $domains) {
            foreach ($domains as $domain) {
                $ranks[$domain] = $position;
            }
        }

        return $ranks;
    }

    /** @return list<string> «archivo -> clase» de cada referencia hacia una capa superior */
    private static function upwardReferences(): array
    {
        $ranks = self::ranks();
        $root = self::servicesPath();
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $domain = explode('/', $relative)[0];
            if (! isset($ranks[$domain])) {
                continue;   // lo detecta test_every_domain_belongs_to_exactly_one_layer
            }
            preg_match_all('/^use (App\\\\Services\\\\([A-Za-z]+)[A-Za-z0-9_\\\\]*)/m', (string) file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $class, $target]) {
                if (isset($ranks[$target]) && $ranks[$target] < $ranks[$domain]) {
                    $found[] = "{$relative} -> {$class}";
                }
            }
        }
        sort($found);

        return $found;
    }

    public function test_every_domain_belongs_to_exactly_one_layer(): void
    {
        $domains = array_map('basename', (array) glob(self::servicesPath().'/*', GLOB_ONLYDIR));
        $classified = array_merge(...array_values(self::LAYERS));

        $this->assertSame([], array_values(array_diff($domains, $classified)), 'Dominio nuevo en app/Services sin capa: añádalo a LAYERS.');
        $this->assertSame([], array_values(array_diff($classified, $domains)), 'LAYERS menciona dominios que ya no existen.');
        $this->assertSame(count($classified), count(array_unique($classified)), 'Un dominio aparece en más de una capa.');
    }

    public function test_no_domain_depends_on_a_higher_layer_except_the_known_exceptions(): void
    {
        $new = array_values(array_diff(self::upwardReferences(), array_keys(self::KNOWN_EXCEPTIONS)));

        $this->assertSame([], $new, "Referencias nuevas hacia una capa superior. Mueva la clase compartida a la capa inferior o use una interfaz en App\\Contracts:\n".implode("\n", $new));
    }

    public function test_the_known_exceptions_still_exist(): void
    {
        $fixed = array_values(array_diff(array_keys(self::KNOWN_EXCEPTIONS), self::upwardReferences()));

        $this->assertSame([], $fixed, "Estas excepciones ya no existen: quítelas de KNOWN_EXCEPTIONS.\n".implode("\n", $fixed));
    }
}
