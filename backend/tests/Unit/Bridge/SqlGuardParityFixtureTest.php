<?php

declare(strict_types=1);

namespace Tests\Unit\Bridge;

use App\Services\Connectors\Sql\SqlQueryGuard;
use App\Services\Connectors\Sql\SqlQueryRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * W6-B · Paridad del guardia SELECT: el corpus compartido (bridge/tests/fixtures/sql_guard_cases.json) se generó con ESTE guardia y el agente Python lo
 * reproduce caso por caso. Si alguien cambia una regla de SqlQueryGuard, esta prueba falla y recuerda actualizar el puerto Python (bridge/src/
 * predictily_bridge/sqlguard.py) y regenerar el corpus.
 */
final class SqlGuardParityFixtureTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>}> */
    public static function cases(): array
    {
        $corpus = json_decode((string) file_get_contents(__DIR__.'/../../../../bridge/tests/fixtures/sql_guard_cases.json'), true, 16, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($corpus['cases'] as $case) {
            if ($case['origin'] === 'php') {
                $out[$case['name']] = [$case];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function test_php_guard_still_gives_the_verdict_the_python_port_is_tested_against(array $case): void
    {
        $sql = isset($case['build']) ? $case['build']['prefix'].str_repeat($case['build']['unit'], $case['build']['times']).$case['build']['suffix'] : $case['sql'];
        try {
            $validated = SqlQueryGuard::validate($sql, $case['dialect']);
        } catch (SqlQueryRejected $e) {
            $this->assertFalse($case['accept'], 'el corpus esperaba aceptar: '.$e->getMessage());
            $this->assertSame($case['reason'], $e->reason);

            return;
        }
        $this->assertTrue($case['accept'], 'el corpus esperaba rechazar con '.($case['reason'] ?? '?'));
        $this->assertSame($case['uses_since'], $validated->usesSince);
        $this->assertSame($case['rendered'], $validated->render('<SINCE>'));
    }

    public function test_corpus_is_substantial(): void
    {
        $this->assertGreaterThanOrEqual(100, count(self::cases()));
    }
}
