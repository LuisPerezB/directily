<?php

/*
|--------------------------------------------------------------------------
| Endurecimiento de cargas (V2.5 §15, ADR-V25-15; defecto HIGH «ZIP bomb» de seguridad_ui)
|--------------------------------------------------------------------------
| Límites de los libros XLSX (un XLSX es un ZIP). Se inspecciona el directorio central ANTES de abrir el archivo
| (ZipArchiveInspector) y se acota la lectura de encabezados y vista previa (XlsxReader). Los valores por defecto admiten el
| histórico real (99 334 líneas ≈ 10 MB en XLSX, 12 entradas, ≈ 170 MB descomprimidos, razón máxima por entrada ≈ 23:1) y
| rechazan la bomba medida en la auditoría (680 KB → 200 MB de sharedStrings, razón 295:1). Todos son ajustables por entorno.
*/

$megabytes = static fn (string $name, int $default): int => (int) env($name, $default) * 1_048_576;

return [
    'xlsx' => [
        // Entradas del ZIP (un libro normal tiene decenas).
        'max_entries' => (int) env('PREDICTILY_XLSX_MAX_ENTRIES', 1000),
        // Tamaño descomprimido máximo por entrada y en total.
        'max_entry_bytes' => $megabytes('PREDICTILY_XLSX_MAX_ENTRY_MB', 512),
        'max_total_bytes' => $megabytes('PREDICTILY_XLSX_MAX_TOTAL_MB', 1024),
        // Cadenas compartidas: el lector las carga completas al abrir el libro, sin poder interrumpirse (≈ 8 MB/s medidos: 32 MB ≈ 4 s),
        // así que este tope manda sobre el tiempo de la petición de subida.
        'max_shared_strings_bytes' => $megabytes('PREDICTILY_XLSX_MAX_SHARED_STRINGS_MB', 32),
        // Razón descomprimido/comprimido máxima por entrada y global; solo se evalúa desde `ratio_min_bytes` (las entradas pequeñas
        // muy repetitivas son inofensivas).
        'max_compression_ratio' => (int) env('PREDICTILY_XLSX_MAX_RATIO', 100),
        'ratio_min_bytes' => 5 * 1_048_576,
        // Lectura de encabezados y vista previa (dentro de la petición de subida).
        'max_columns' => (int) env('PREDICTILY_XLSX_MAX_COLUMNS', 500),
        'analysis_rows' => 25,
        'analysis_max_scanned_rows' => (int) env('PREDICTILY_XLSX_ANALYSIS_MAX_SCANNED_ROWS', 20_000),
        // Se cuenta con el libro ya abierto (el recorrido de filas sí es interrumpible).
        'analysis_timeout_seconds' => (float) env('PREDICTILY_XLSX_ANALYSIS_TIMEOUT', 10),
    ],
];
