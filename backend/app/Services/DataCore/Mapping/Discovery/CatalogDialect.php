<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

/**
 * Consultas de CATÁLOGO fijas de un motor (V2.5 §4). Son constantes del código: no reciben ni concatenan texto de usuario ni del origen, no leen filas de
 * negocio (solo metadatos del catálogo) y NO pasan por SqlQueryGuard a propósito: el guard rechaza `information_schema`, `sys.*` y `pg_*` para las consultas
 * escritas por personas; aquí las consultas las fija Predictily y el aislamiento es por construcción (ver SchemaDiscoveryService y su prueba de contrato).
 *
 * Cada consulta devuelve columnas con estos alias (minúsculas):
 *  - tablas:   schema_name, table_name, kind (table|view), estimated_rows
 *  - columnas: schema_name, table_name, column_name, data_type, column_type, is_nullable (YES|NO), ordinal_position, max_length
 *  - primarias: schema_name, table_name, column_name, position
 *  - foráneas: constraint_name, schema_name, table_name, column_name, ref_schema, ref_table, ref_column, position
 * En MySQL/MariaDB `schema_name` es NULL: el «esquema» es la base de datos de la conexión y la plantilla no la fija.
 */
interface CatalogDialect
{
    /** pgsql | mysql | sqlsrv */
    public function dialect(): string;

    /**
     * Sentencias fijas previas a las lecturas del catálogo (p. ej. READ UNCOMMITTED en SQL Server para no bloquear a los escritores del ERP).
     *
     * @return list<string>
     */
    public function sessionStatements(): array;

    public function tablesQuery(): string;

    public function columnsQuery(): string;

    public function primaryKeysQuery(): string;

    public function foreignKeysQuery(): string;

    /** Todas las consultas que ejecuta el descubrimiento, por nombre (para auditar que sean SELECT fijos). @return array<string, string> */
    public function queries(): array;

    /** Comillas de identificador del motor: abre, cierra. @return array{0: string, 1: string} */
    public function identifierQuotes(): array;
}
