<?php

declare(strict_types=1);

/*
 * Predictily · descubrimiento de esquema de una fuente SQL (piloto; docs/operations/PILOT_RUNBOOK.md §2).
 *
 * SOLO LECTURA: ejecuta consultas SELECT fijas sobre el catálogo del motor de origen (tablas, columnas, filas
 * estimadas, claves). No lee filas de negocio ni datos personales. Usa la fuente registrada en Predictily: las
 * credenciales se descifran en memoria y nunca se imprimen; la conexión pasa por SqlHostPolicy (IP fijada,
 * lista blanca) y, en SQL Server, ApplicationIntent=ReadOnly, LOCK_TIMEOUT 5 s y READ UNCOMMITTED (no bloquea
 * escritores del ERP).
 *
 * Uso: cd backend && php ../infra/scripts/pilot/sql_source_discovery.php <data_source_id> [directorio_salida]
 * Salida: JSON en reports/private/pilot/ (ignorado por git: repositorio público).
 */

use App\Models\DataSource;
use App\Models\Tenant;
use App\Services\DataCore\Sync\SourceMappingService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

$backend = getcwd();
if (! is_file($backend.'/artisan')) {
    fwrite(STDERR, "Ejecute desde backend/: cd backend && php ../infra/scripts/pilot/sql_source_discovery.php <id>\n");
    exit(2);
}
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 0);
$outDir = $argv[2] ?? dirname($backend).'/reports/private/pilot';
if ($id <= 0) {
    fwrite(STDERR, "Indique el id de la fuente de datos.\n");
    exit(2);
}

$source = DataSource::withoutGlobalScopes()->findOrFail($id);
$tenant = Tenant::query()->findOrFail($source->tenant_id);
$context = app(TenantContext::class);

$result = $context->runAs($tenant, function () use ($source): array {
    $connector = app(SourceMappingService::class)->connectorFor($source);
    $connection = $connector->open($source);
    $pdo = $connection->pdo();
    $dialect = $connection->dialect()->name();
    $q = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $out = ['dialect' => $dialect, 'captured_at_utc' => gmdate('c'), 'data_source_id' => $source->id];
    if ($dialect === 'sqlsrv') {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
        $out['server'] = $q("SELECT @@VERSION AS version, DB_NAME() AS current_db, CONVERT(varchar(40), SYSDATETIMEOFFSET(), 127) AS server_now,
                                    DATENAME(TZOFFSET, SYSDATETIMEOFFSET()) AS tz_offset, CONVERT(varchar(128), SERVERPROPERTY('Collation')) AS collation")[0] ?? [];
        $out['databases'] = $q('SELECT name, state_desc, CONVERT(varchar(10), create_date, 23) AS created FROM sys.databases ORDER BY name');
        $out['tables'] = $q('SELECT s.name AS schema_name, t.name AS table_name, SUM(p.rows) AS row_count, CONVERT(varchar(19), t.modify_date, 120) AS modified
                             FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id
                             JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
                             GROUP BY s.name, t.name, t.modify_date ORDER BY SUM(p.rows) DESC, t.name');
        $out['views'] = $q('SELECT s.name AS schema_name, v.name AS view_name FROM sys.views v JOIN sys.schemas s ON s.schema_id = v.schema_id ORDER BY 1, 2');
        $out['columns'] = $q('SELECT TABLE_SCHEMA AS schema_name, TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type,
                                     CHARACTER_MAXIMUM_LENGTH AS max_length, IS_NULLABLE AS nullable, ORDINAL_POSITION AS position
                              FROM INFORMATION_SCHEMA.COLUMNS ORDER BY TABLE_SCHEMA, TABLE_NAME, ORDINAL_POSITION');
        $out['primary_keys'] = $q("SELECT tc.TABLE_SCHEMA AS schema_name, tc.TABLE_NAME AS table_name, kcu.COLUMN_NAME AS column_name, kcu.ORDINAL_POSITION AS position
                                   FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                                     ON kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME AND kcu.TABLE_SCHEMA = tc.TABLE_SCHEMA
                                   WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' ORDER BY 1, 2, 4");
        $out['foreign_keys'] = $q('SELECT OBJECT_SCHEMA_NAME(fk.parent_object_id) AS schema_name, OBJECT_NAME(fk.parent_object_id) AS table_name,
                                          COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS column_name,
                                          OBJECT_NAME(fk.referenced_object_id) AS referenced_table, COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS referenced_column
                                   FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id ORDER BY 1, 2');
        $out['write_privilege_warnings'] = $connection->dialect()->writePrivilegeWarnings($pdo);
    } else {
        throw new RuntimeException("Descubrimiento implementado solo para SQL Server (dialecto {$dialect}).");
    }
    $connection->close();

    return $out;
});

if (! is_dir($outDir) && ! mkdir($outDir, 0700, true) && ! is_dir($outDir)) {
    throw new RuntimeException("No se pudo crear {$outDir}");
}
$db = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($result['server']['current_db'] ?? 'db'));
$file = sprintf('%s/discovery-ds%d-%s-%s.json', rtrim($outDir, '/'), $id, $db, gmdate('Ymd\THis\Z'));
file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
chmod($file, 0600);

$cols = [];
foreach ($result['columns'] as $c) {
    $cols[$c['schema_name'].'.'.$c['table_name']] = ($cols[$c['schema_name'].'.'.$c['table_name']] ?? 0) + 1;
}
printf("Base: %s · %s · offset %s\n", $result['server']['current_db'] ?? '?', strtok((string) ($result['server']['version'] ?? ''), "\n"), $result['server']['tz_offset'] ?? '?');
printf("Tablas: %d · vistas: %d · columnas: %d · bases visibles: %d\n", count($result['tables']), count($result['views']), count($result['columns']), count($result['databases']));
foreach ($result['write_privilege_warnings'] as $w) {
    echo "AVISO: {$w}\n";
}
echo "Tablas con más filas (estimadas):\n";
foreach (array_slice($result['tables'], 0, 60) as $t) {
    $key = $t['schema_name'].'.'.$t['table_name'];
    printf("  %-48s %12s filas · %3d columnas · mod. %s\n", $key, number_format((int) $t['row_count'], 0, ',', '.'), $cols[$key] ?? 0, $t['modified']);
}
echo "Archivo: {$file}\n";
