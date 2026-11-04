<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

/**
 * Clase de tipo común a los tres motores. El Auto Mapper razona con ESTA clase (no con el nombre nativo del tipo): `varchar`, `nvarchar` y
 * `text` son Text; `numeric`, `money` y `double` son Decimal; `timestamptz`, `datetime2` y `timestamp` son DateTime.
 */
enum TypeClass: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';
    case Binary = 'binary';
    case Other = 'other';

    public function isNumeric(): bool
    {
        return $this === self::Integer || $this === self::Decimal;
    }

    public function isTemporal(): bool
    {
        return $this === self::Date || $this === self::DateTime;
    }

    /**
     * @param  string  $dialect  pgsql | mysql | sqlsrv
     * @param  ?string  $columnType  tipo completo del catálogo (MySQL: `tinyint(1)`, `enum('a','b')`); null en los demás motores
     */
    public static function classify(string $dialect, string $dataType, ?string $columnType = null): self
    {
        $type = strtolower(trim($dataType));
        $full = strtolower(trim((string) $columnType));

        if ($dialect === 'mysql' && ($full === 'tinyint(1)' || $full === 'bit(1)')) {
            return self::Boolean;
        }

        return match (true) {
            in_array($type, ['boolean', 'bool', 'bit'], true) => self::Boolean,
            in_array($type, ['smallint', 'integer', 'int', 'int2', 'int4', 'int8', 'bigint', 'tinyint', 'mediumint', 'serial', 'bigserial', 'smallserial', 'year'], true) => self::Integer,
            in_array($type, ['numeric', 'decimal', 'real', 'float', 'float4', 'float8', 'double', 'double precision', 'money', 'smallmoney', 'dec', 'fixed'], true) => self::Decimal,
            $type === 'date' => self::Date,
            in_array($type, ['timestamp', 'timestamp without time zone', 'timestamp with time zone', 'timestamptz', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'], true) && ! ($dialect === 'sqlsrv' && $type === 'timestamp') => self::DateTime,
            in_array($type, ['char', 'character', 'varchar', 'character varying', 'nchar', 'nvarchar', 'text', 'ntext', 'tinytext', 'mediumtext', 'longtext', 'citext', 'bpchar', 'name', 'uuid', 'uniqueidentifier', 'enum', 'set'], true) => self::Text,
            in_array($type, ['bytea', 'binary', 'varbinary', 'image', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'rowversion'], true) || ($dialect === 'sqlsrv' && $type === 'timestamp') => self::Binary,
            default => self::Other,
        };
    }
}
