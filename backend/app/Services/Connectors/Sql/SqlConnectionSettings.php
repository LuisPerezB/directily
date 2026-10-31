<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/** Parámetros NO secretos de una fuente SQL (data_sources.config) más los límites de ejecución. */
final readonly class SqlConnectionSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $sslMode = 'prefer',
        public string $sourceTimezone = 'America/Guayaquil',
        public int $connectTimeout = 5,
        public int $statementTimeout = 60,
        public bool $encrypt = true,
        public bool $trustServerCertificate = false,
        public string $charset = 'utf8mb4',
        /** IP FIJADA por la política de destino (SqlHostPolicy): el dialecto se conecta a ella y nunca vuelve a resolver el host. */
        public ?string $resolvedAddress = null,
    ) {}

    /** Copia con el destino ya autorizado: `host` queda normalizado (TLS/registro) y la conexión usa la IP fijada. */
    public function pinnedTo(SqlResolvedTarget $target): self
    {
        return new self(
            host: $target->host,
            port: $this->port,
            database: $this->database,
            sslMode: $this->sslMode,
            sourceTimezone: $this->sourceTimezone,
            connectTimeout: $this->connectTimeout,
            statementTimeout: $this->statementTimeout,
            encrypt: $this->encrypt,
            trustServerCertificate: $this->trustServerCertificate,
            charset: $this->charset,
            resolvedAddress: $target->address,
        );
    }

    /** Dirección a la que debe abrirse el socket: la IP fijada o, en pruebas de dialecto sin política, el host indicado. */
    public function connectAddress(): string
    {
        return $this->resolvedAddress ?? $this->host;
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config, string $defaultTimezone, int $defaultPort): self
    {
        return new self(
            host: (string) ($config['host'] ?? ''),
            port: (int) ($config['port'] ?? $defaultPort),
            database: (string) ($config['database'] ?? ''),
            sslMode: (string) ($config['sslmode'] ?? 'prefer'),
            sourceTimezone: (string) ($config['source_timezone'] ?? $defaultTimezone),
            connectTimeout: max(1, min(30, (int) ($config['connect_timeout_seconds'] ?? 5))),
            statementTimeout: max(1, min(600, (int) ($config['statement_timeout_seconds'] ?? 60))),
            encrypt: (bool) ($config['encrypt'] ?? true),
            trustServerCertificate: (bool) ($config['trust_server_certificate'] ?? false),
            charset: (string) ($config['charset'] ?? 'utf8mb4'),
        );
    }
}
