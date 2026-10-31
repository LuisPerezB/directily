<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;
use PDO;
use PDOException;
use Throwable;

/**
 * Conexión de solo lectura a una fuente SQL. Se abre ya dentro de la transacción de solo lectura del dialecto;
 * solo ejecuta consultas que superaron SqlQueryGuard (ValidatedQuery).
 */
final class SqlSourceConnection
{
    private const FULL_LOAD_SINCE = '1900-01-01 00:00:00';

    private ?PDO $pdo;

    private function __construct(PDO $pdo, private readonly SqlDialect $dialect, private readonly SqlConnectionSettings $settings)
    {
        $this->pdo = $pdo;
    }

    /** @throws SqlSourceException */
    public static function open(SqlDialect $dialect, SqlConnectionSettings $settings, string $username, string $password): self
    {
        SqlHostPolicy::assertAllowed($settings->host);
        try {
            $pdo = $dialect->connect($settings, $username, $password);
            $dialect->begin($pdo, $settings);
        } catch (Throwable $e) {
            throw new SqlSourceException(self::safeMessage($e, [$password, $username]), previous: null);
        }

        return new self($pdo, $dialect, $settings);
    }

    public function dialect(): SqlDialect
    {
        return $this->dialect;
    }

    /** Solo para pruebas de contrato (comprobar que el motor rechaza escrituras en la transacción de solo lectura). */
    public function pdo(): PDO
    {
        return $this->pdo ?? throw new SqlSourceException('La conexión está cerrada.');
    }

    /** @return array{server_version: string, warnings: list<string>} */
    public function describe(): array
    {
        try {
            return [
                'server_version' => mb_substr($this->dialect->serverVersion($this->pdo()), 0, 120),
                'warnings' => $this->dialect->writePrivilegeWarnings($this->pdo()),
            ];
        } catch (Throwable $e) {
            throw new SqlSourceException(self::safeMessage($e));
        }
    }

    /**
     * @return Generator<int, list<array<string, mixed>>>
     *
     * @throws SqlSourceException
     */
    public function stream(ValidatedQuery $query, ?CarbonInterface $since, int $pageSize): Generator
    {
        try {
            yield from $this->dialect->stream($this->pdo(), $this->render($query, $since), $pageSize);
        } catch (PDOException $e) {
            throw new SqlSourceException(self::safeMessage($e));
        }
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws SqlSourceException
     */
    public function preview(ValidatedQuery $query, ?CarbonInterface $since, int $limit): array
    {
        try {
            return $this->dialect->preview($this->pdo(), $this->render($query, $since), $limit);
        } catch (PDOException $e) {
            throw new SqlSourceException(self::safeMessage($e));
        }
    }

    public function close(): void
    {
        if ($this->pdo !== null) {
            $this->dialect->end($this->pdo);
            $this->pdo = null;
        }
    }

    private function render(ValidatedQuery $query, ?CarbonInterface $since): string
    {
        if (! $query->usesSince) {
            return $query->render();
        }
        $local = $since?->setTimezone($this->settings->sourceTimezone) ?? CarbonImmutable::parse(self::FULL_LOAD_SINCE, $this->settings->sourceTimezone);

        return $query->render($this->dialect->sinceLiteral($local));
    }

    /** @param list<string> $secrets */
    public static function safeMessage(Throwable $e, array $secrets = []): string
    {
        $message = $e->getMessage();
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 3) {
                $message = str_replace($secret, '***', $message);
            }
        }
        $message = preg_replace('/password=\S+/i', 'password=***', $message) ?? $message;

        return mb_substr($message, 0, 400);
    }
}
