<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use App\Exceptions\DomainStateException;
use Illuminate\Http\JsonResponse;

/**
 * Un libro XLSX incumple los límites de seguridad de carga (ZIP bomb, directorio central falsificado, demasiadas entradas,
 * archivos anidados, encabezados desmesurados, análisis demasiado lento). Código estable `xlsx_*` y estado HTTP 413 (tamaño descomprimido) o 422 (resto).
 * Extiende DomainStateException para que el análisis de una importación conserve el mensaje en español en `error_message`.
 */
final class UnsafeSpreadsheetException extends DomainStateException
{
    public const INVALID_ARCHIVE = 'xlsx_invalid_archive';

    public const ENCRYPTED = 'xlsx_encrypted';

    public const TOO_MANY_ENTRIES = 'xlsx_too_many_entries';

    public const UNCOMPRESSED_TOO_LARGE = 'xlsx_uncompressed_too_large';

    public const COMPRESSION_RATIO = 'xlsx_compression_ratio_exceeded';

    public const NESTED_ARCHIVE = 'xlsx_nested_archive';

    public const TOO_MANY_COLUMNS = 'xlsx_too_many_columns';

    public const HEADER_NOT_FOUND = 'xlsx_header_not_found';

    public const READ_TIMEOUT = 'xlsx_read_timeout';

    /** El directorio central declara tamaños (o un CRC) que no coinciden con lo que realmente se descomprime: archivo dañado o falsificado. */
    public const INCONSISTENT_ENTRY = 'xlsx_inconsistent_entry';

    /** @param array<string, mixed> $context */
    public function __construct(string $message, string $errorCode, public readonly int $status = 422, array $context = [])
    {
        parent::__construct($message, $errorCode, $context);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => ['file' => [$this->getMessage()]],
            'context' => $this->context ?: null,
        ], fn ($value) => $value !== null), $this->status);
    }
}
