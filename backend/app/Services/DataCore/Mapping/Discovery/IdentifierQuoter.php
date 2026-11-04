<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

use InvalidArgumentException;

/**
 * Entrecomilla identificadores (esquema, tabla, columna) TOMADOS DEL CATÁLOGO del origen para las consultas que genera Predictily. Un identificador
 * se rechaza (nunca se «sanea») si trae caracteres de control, barra invertida, NUL, una codificación no válida o más de 128 caracteres; el resto se
 * entrecomilla duplicando la comilla del motor. Aun así, toda consulta generada pasa por SqlQueryGuard antes de ejecutarse.
 */
final class IdentifierQuoter
{
    public const MAX_LENGTH = 128;

    public static function isSafe(string $identifier): bool
    {
        return $identifier !== '' && strlen($identifier) <= self::MAX_LENGTH * 4 && mb_strlen($identifier) <= self::MAX_LENGTH && mb_check_encoding($identifier, 'UTF-8')
            && preg_match('/[\p{C}\\\\]/u', $identifier) !== 1 && trim($identifier) === $identifier;
    }

    /** @throws InvalidArgumentException */
    public static function quote(string $dialect, string $identifier): string
    {
        if (! self::isSafe($identifier)) {
            throw new InvalidArgumentException('Identificador no seguro para una consulta generada.');
        }

        return match ($dialect) {
            'pgsql' => '"'.str_replace('"', '""', $identifier).'"',
            'mysql' => '`'.str_replace('`', '``', $identifier).'`',
            'sqlsrv' => '['.str_replace(']', ']]', $identifier).']',
            default => throw new InvalidArgumentException('Dialecto SQL no soportado: '.$dialect),
        };
    }

    /** @throws InvalidArgumentException */
    public static function table(string $dialect, ?string $schema, string $name): string
    {
        return ($schema === null || $schema === '' || $dialect === 'mysql' ? '' : self::quote($dialect, $schema).'.').self::quote($dialect, $name);
    }
}
