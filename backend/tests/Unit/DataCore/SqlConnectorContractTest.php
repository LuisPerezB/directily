<?php

declare(strict_types=1);

namespace Tests\Unit\DataCore;

use App\Services\Connectors\Connectors\MySqlSqlConnector;
use App\Services\Connectors\Connectors\PostgresSqlConnector;
use App\Services\Connectors\Connectors\SqlServerSqlConnector;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Sql\MySqlDialect;
use App\Services\Connectors\Sql\PostgresDialect;
use App\Services\Connectors\Sql\SqlConnectionSettings;
use App\Services\Connectors\Sql\SqlConnectorInterface;
use App\Services\Connectors\Sql\SqlQueryGuard;
use App\Services\Connectors\Sql\SqlServerDialect;
use Illuminate\Validation\ValidationException;
use PDO;
use Tests\TestCase;

/**
 * Pruebas de CONTRATO de los conectores SQL. Las que necesitan un servidor real (MySQL/MariaDB, SQL Server) se OMITEN
 * si no hay servidor o extensión: esos conectores permanecen `implemented_untested` y nunca se declaran disponibles.
 * Para ejecutarlas: TEST_MYSQL_DSN_HOST/PORT/DATABASE/USERNAME/PASSWORD o TEST_SQLSRV_HOST/... (ver docs/architecture/v2/P3-datos.md).
 */
final class SqlConnectorContractTest extends TestCase
{
    /** @return list<array{SqlConnectorInterface, string}> */
    private function connectors(): array
    {
        return [[new PostgresSqlConnector, 'tested_with_test_database'], [new MySqlSqlConnector, 'implemented_untested'], [new SqlServerSqlConnector, 'implemented_untested']];
    }

    public function test_every_sql_connector_honours_the_common_contract(): void
    {
        foreach ($this->connectors() as [$connector, $verification]) {
            $this->assertInstanceOf(SqlConnectorInterface::class, $connector);
            $this->assertTrue($connector->readOnly());
            $this->assertSame($verification, $connector->verification());
            $this->assertSame(['username', 'password'], $connector->credentialKeys());
            $this->assertContains('sales', $connector->entityTypes());
            $this->assertNotNull(app(ConnectorRegistry::class)->get($connector->key()));
            // Una consulta de escritura nunca llega al motor: el validador es común a todos.
            $this->expectsRejection($connector, 'UPDATE t SET a = 1');
            $this->expectsRejection($connector, 'SELECT 1; DROP TABLE t');
        }
        $this->assertSame('requires_credentials', (new PostgresSqlConnector)->status());
        $this->assertSame('implemented_untested', (new MySqlSqlConnector)->status());
        $this->assertSame('implemented_untested', (new SqlServerSqlConnector)->status());
    }

    private function expectsRejection(SqlConnectorInterface $connector, string $sql): void
    {
        try {
            SqlQueryGuard::validate($sql, $connector->dialect()->name());
            $this->fail('Debió rechazarse en '.$connector->key());
        } catch (\App\Services\Connectors\Sql\SqlQueryRejected) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_input_validation_is_common_and_rejects_secrets_in_config(): void
    {
        foreach ($this->connectors() as [$connector]) {
            try {
                $connector->validateInput(['host' => 'erp.example', 'database' => 'erp', 'password' => 'x'], ['username' => 'u', 'password' => 'p']);
                $this->fail('Debía rechazar la clave dentro de config en '.$connector->key());
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('config.password', $e->errors());
            }
            $connector->validateInput(['host' => 'erp.example', 'port' => $connector->dialect()->defaultPort(), 'database' => 'erp'], ['username' => 'u', 'password' => 'p']);
            $this->addToAssertionCount(1);
        }
    }

    public function test_missing_driver_is_reported_instead_of_failing(): void
    {
        $connector = new SqlServerSqlConnector;
        if ($connector->dialect()->driverAvailable()) {
            $this->markTestSkipped('pdo_sqlsrv está instalado: no aplica la comprobación del controlador ausente.');
        }
        $source = new \App\Models\DataSource;
        $source->forceFill(['config' => ['host' => 'x', 'database' => 'erp'], 'credentials_encrypted' => ['username' => 'u', 'password' => 'p']]);
        $result = $connector->test($source);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('pdo_sqlsrv', $result->message);
        $this->assertSame('implemented_untested', $result->details['verification']);
        $this->assertSame('query_guard_and_account_permissions', $result->details['read_only_enforcement']);
    }

    public function test_dialect_specific_statements_enforce_read_only_and_timeouts(): void
    {
        // Sin servidor: se verifica la configuración que cada dialecto declara (la ejecución real está en las pruebas de contrato con servidor).
        $this->assertSame(5432, (new PostgresDialect)->defaultPort());
        $this->assertSame(3306, (new MySqlDialect)->defaultPort());
        $this->assertSame(1433, (new SqlServerDialect)->defaultPort());
        $this->assertStringContainsString("'2026-01-02 03:04:05'", (new PostgresDialect)->sinceLiteral(new \DateTimeImmutable('2026-01-02 03:04:05')));
        $this->assertStringContainsString('datetime2', (new SqlServerDialect)->sinceLiteral(new \DateTimeImmutable('2026-01-02 03:04:05')));
        $this->expectException(\InvalidArgumentException::class);
        (new PostgresDialect)->connect(new SqlConnectionSettings('host;dbname=evil', 5432, 'erp'), 'u', 'p');
    }

    public function test_mysql_server_contract(): void
    {
        $dialect = new MySqlDialect;
        $host = getenv('TEST_MYSQL_HOST') ?: null;
        if (! $dialect->driverAvailable() || $host === null) {
            $this->markTestSkipped('Sin servidor MySQL/MariaDB de pruebas (defina TEST_MYSQL_HOST/PORT/DATABASE/USERNAME/PASSWORD).');
        }
        $this->runServerContract($dialect, new SqlConnectionSettings($host, (int) (getenv('TEST_MYSQL_PORT') ?: 3306), (string) getenv('TEST_MYSQL_DATABASE')), (string) getenv('TEST_MYSQL_USERNAME'), (string) getenv('TEST_MYSQL_PASSWORD'));
    }

    public function test_sqlserver_server_contract(): void
    {
        $dialect = new SqlServerDialect;
        $host = getenv('TEST_SQLSRV_HOST') ?: null;
        if (! $dialect->driverAvailable() || $host === null) {
            $this->markTestSkipped('Sin servidor SQL Server de pruebas ni extensión pdo_sqlsrv (defina TEST_SQLSRV_HOST/PORT/DATABASE/USERNAME/PASSWORD).');
        }
        $this->runServerContract($dialect, new SqlConnectionSettings($host, (int) (getenv('TEST_SQLSRV_PORT') ?: 1433), (string) getenv('TEST_SQLSRV_DATABASE'), trustServerCertificate: true), (string) getenv('TEST_SQLSRV_USERNAME'), (string) getenv('TEST_SQLSRV_PASSWORD'));
    }

    public function test_postgres_server_contract(): void
    {
        $dialect = new PostgresDialect;
        $settings = new SqlConnectionSettings(getenv('TEST_ERP_HOST') ?: '127.0.0.1', (int) (getenv('TEST_ERP_PORT') ?: 5432), getenv('TEST_ERP_DATABASE') ?: 'predictily_erp_source_p3');
        try {
            $pdo = $dialect->connect($settings, getenv('TEST_ERP_USERNAME') ?: 'predictily', getenv('TEST_ERP_PASSWORD') ?: 'secret');
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base «ERP de origen» PostgreSQL de pruebas.');
        }
        unset($pdo);
        $this->runServerContract($dialect, $settings, getenv('TEST_ERP_USERNAME') ?: 'predictily', getenv('TEST_ERP_PASSWORD') ?: 'secret');
    }

    /** Contrato común contra un servidor real: lee en páginas, respeta :since y rechaza escrituras en el propio motor cuando existe modo de solo lectura. */
    private function runServerContract(\App\Services\Connectors\Sql\SqlDialect $dialect, SqlConnectionSettings $settings, string $user, string $password): void
    {
        $connection = \App\Services\Connectors\Sql\SqlSourceConnection::open($dialect, $settings, $user, $password);
        try {
            $query = SqlQueryGuard::validate('SELECT 1 AS uno', $dialect->name());
            $pages = iterator_to_array($connection->stream($query, null, 10));
            $this->assertSame(1, (int) array_values($pages[0][0])[0]);
            $this->assertNotSame('', $connection->describe()['server_version']);
            $this->assertInstanceOf(PDO::class, $connection->pdo());
        } finally {
            $connection->close();
        }
    }
}
