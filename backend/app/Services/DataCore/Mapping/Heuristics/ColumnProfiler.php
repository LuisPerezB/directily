<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

use App\Services\DataCore\Mapping\Discovery\DiscoveredTable;

/** Lee muestras estadísticas acotadas del origen (ver Discovery\ColumnSampler) o las simula en las pruebas. */
interface ColumnProfiler
{
    /**
     * @param  list<string>  $patternColumns  columnas de texto cuyo tipo real (fecha, número, booleano) se confirma con una muestra
     * @param  list<string>  $categoricalColumns  columnas de vocabulario cuyos valores distintos se piden (tipo y estado de documento)
     * @return array<string, ColumnProfile> columna → perfil (solo las que se pudieron perfilar)
     */
    public function profile(DiscoveredTable $table, array $patternColumns, array $categoricalColumns): array;
}
