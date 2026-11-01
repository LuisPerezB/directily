<?php

declare(strict_types=1);

namespace App\Services\DataCore\Sync;

use App\Services\Connectors\Sql\SqlSourceException;
use App\Services\DataCore\Quality\DataQualityCatalog;

/**
 * El mapeo aprobado referencia columnas que la consulta ya no devuelve (cambió el origen o la consulta): problema estructural que impide
 * la carga completa. La sincronización se detiene y queda un hallazgo BLOCKER `source_columns_missing` (ADR-V25-05).
 *
 * @see DataQualityCatalog
 */
final class SourceColumnsMissingException extends SqlSourceException {}
