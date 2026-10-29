<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

use App\Models\DataSource;
use App\Services\Connectors\ConnectorTestResult;
use App\Services\Connectors\Sql\SqlConnectionSettings;
use App\Services\Connectors\Sql\SqlConnectorInterface;
use App\Services\Connectors\Sql\SqlDialect;
use App\Services\Connectors\Sql\SqlSourceConnection;
use App\Services\Connectors\Sql\SqlSourceException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Conector SQL de solo lectura (ADR-V2-13). Credenciales (usuario/clave) cifradas en data_sources.credentials_encrypted;
 * host, puerto, base y opciones no secretas en data_sources.config. Nunca devuelve ni registra la clave.
 */
abstract class AbstractSqlConnector extends AbstractConnector implements SqlConnectorInterface
{
    public function entityTypes(): array
    {
        return ['sales', 'products', 'inventory', 'commitments', 'branches', 'suppliers', 'lead_times', 'calendar_events', 'unit_conversions', 'warehouses', 'promotions'];
    }

    public function credentialKeys(): array
    {
        return ['username', 'password'];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function open(DataSource $source): SqlSourceConnection
    {
        $dialect = $this->dialect();
        if (! $dialect->driverAvailable()) {
            throw new SqlSourceException('Falta la extensión PHP pdo_'.$dialect->name().' en este servidor.');
        }
        $credentials = (array) ($source->credentials_encrypted ?? []);
        $username = (string) ($credentials['username'] ?? '');
        $password = (string) ($credentials['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new SqlSourceException('La fuente no tiene credenciales configuradas.');
        }
        $tenant = app(TenantContext::class)->get();
        $settings = SqlConnectionSettings::fromConfig((array) ($source->config ?? []), $tenant?->timezone ?: 'America/Guayaquil', $dialect->defaultPort());

        return SqlSourceConnection::open($dialect, $settings, $username, $password);
    }

    public function test(DataSource $source): ConnectorTestResult
    {
        $base = ['verification' => $this->verification(), 'driver' => 'pdo_'.$this->dialect()->name(), 'read_only_enforcement' => $this->readOnlyEnforcement()];
        if (! $this->dialect()->driverAvailable()) {
            return new ConnectorTestResult(false, 'Falta la extensión PHP pdo_'.$this->dialect()->name().' en este servidor; instálela para usar este conector.', $base + ['driver_available' => false]);
        }
        try {
            $this->validateInput((array) ($source->config ?? []), (array) ($source->credentials_encrypted ?? []));
            $connection = $this->open($source);
            try {
                $info = $connection->describe();
            } finally {
                $connection->close();
            }

            return new ConnectorTestResult(true, 'Conexión de solo lectura establecida.', $base + ['driver_available' => true] + $info);
        } catch (ValidationException $e) {
            return new ConnectorTestResult(false, 'Configuración incompleta o no válida.', $base + ['errors' => array_keys($e->errors())]);
        } catch (SqlSourceException $e) {
            return new ConnectorTestResult(false, $e->getMessage(), $base);
        } catch (Throwable $e) {
            // Nunca propagar detalles internos (podrían incluir datos de la conexión).
            return new ConnectorTestResult(false, 'No se pudo abrir la conexión con la fuente.', $base);
        }
    }

    public function validateInput(array $config, ?array $credentials): void
    {
        $dialect = $this->dialect();
        $errors = [];
        $unknown = array_diff(array_keys($config), $dialect->configKeys());
        foreach ($unknown as $key) {
            $errors["config.{$key}"][] = in_array(strtolower((string) $key), ['password', 'pass', 'secret', 'token', 'username', 'user'], true)
                ? 'Las credenciales van en «credentials», no en «config».'
                : "Clave de configuración no reconocida: {$key}.";
        }
        $host = (string) ($config['host'] ?? '');
        if ($host === '' || preg_match('/^[A-Za-z0-9._:\-\[\]]+$/', $host) !== 1) {
            $errors['config.host'][] = 'Indique un host válido (nombre o IP).';
        }
        $port = $config['port'] ?? $dialect->defaultPort();
        if (! is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
            $errors['config.port'][] = 'Puerto no válido.';
        }
        $database = (string) ($config['database'] ?? '');
        if ($database === '' || preg_match('/^[A-Za-z0-9._\-]+$/', $database) !== 1) {
            $errors['config.database'][] = 'Indique el nombre de la base de datos (letras, números, punto, guion).';
        }
        if (isset($config['sslmode']) && ! in_array($config['sslmode'], ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            $errors['config.sslmode'][] = 'sslmode no válido.';
        }
        if (isset($config['source_timezone']) && ! in_array($config['source_timezone'], timezone_identifiers_list(), true)) {
            $errors['config.source_timezone'][] = 'Zona horaria no válida.';
        }
        foreach (['connect_timeout_seconds' => [1, 30], 'statement_timeout_seconds' => [1, 600]] as $key => [$min, $max]) {
            if (isset($config[$key]) && (! is_numeric($config[$key]) || (int) $config[$key] < $min || (int) $config[$key] > $max)) {
                $errors["config.{$key}"][] = "Debe estar entre {$min} y {$max}.";
            }
        }
        $credentials ??= [];
        foreach (array_diff(array_keys($credentials), ['username', 'password']) as $key) {
            $errors["credentials.{$key}"][] = 'Solo se aceptan «username» y «password».';
        }
        foreach (['username', 'password'] as $key) {
            if (! isset($credentials[$key]) || ! is_string($credentials[$key]) || $credentials[$key] === '') {
                $errors["credentials.{$key}"][] = 'Es obligatorio.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    abstract public function dialect(): SqlDialect;

    /** Cómo se impide la escritura en el motor. */
    abstract protected function readOnlyEnforcement(): string;

    public function toArray(): array
    {
        return parent::toArray() + ['driver_available' => $this->dialect()->driverAvailable()];
    }
}
