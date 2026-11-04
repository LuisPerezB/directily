<?php

/*
|--------------------------------------------------------------------------
| Endurecimiento de cargas (V2.5 §15, ADR-V25-15; defecto HIGH «ZIP bomb» de seguridad_ui)
|--------------------------------------------------------------------------
| Límites de los libros XLSX (un XLSX es un ZIP). Se inspecciona el libro ANTES de abrirlo (ZipArchiveInspector: directorio
| central y tamaños REALES descomprimidos, sin fiarse de los declarados) y se acota la lectura de encabezados y vista previa
| (XlsxReader + ZipReadGuard). Los valores por defecto admiten el histórico real (99 334 líneas ≈ 10 MB en XLSX, 12 entradas,
| ≈ 170 MB descomprimidos, razón máxima por entrada ≈ 23:1) y rechazan la bomba medida en la auditoría (680 KB → 200 MB de
| sharedStrings, razón 295:1). Todos son ajustables por entorno.
*/

$megabytes = static fn (string $name, int $default): int => (int) env($name, $default) * 1_048_576;

return [
    'xlsx' => [
        // Entradas del ZIP (un libro normal tiene decenas).
        'max_entries' => (int) env('PREDICTILY_XLSX_MAX_ENTRIES', 1000),
        // Tamaño descomprimido máximo por entrada y en total.
        'max_entry_bytes' => $megabytes('PREDICTILY_XLSX_MAX_ENTRY_MB', 512),
        'max_total_bytes' => $megabytes('PREDICTILY_XLSX_MAX_TOTAL_MB', 1024),
        // Cadenas compartidas y estilos: el lector los carga completos al abrir el libro. El tope se aplica a la parte a la que apunta
        // la relación del libro (no al nombre literal): una `xl/ss.xml` renombrada cuenta igual. Medido: 2-8 MB/s según la densidad
        // de cadenas (el histórico real ocupa ≈ 3 MB), así que 32 MB pueden tardar entre 4 s y 16 s; el presupuesto de tiempo
        // de la ventana interactiva (abajo) corta antes lo que se salga de lo razonable.
        'max_shared_strings_bytes' => $megabytes('PREDICTILY_XLSX_MAX_SHARED_STRINGS_MB', 32),
        // Partes de control (workbook.xml y sus relaciones): se leen enteras para resolver qué es cada parte.
        'max_control_part_bytes' => 8 * 1_048_576,
        // Razón descomprimido/comprimido máxima por entrada y global; solo se evalúa desde `ratio_min_bytes` (las entradas pequeñas
        // muy repetitivas son inofensivas).
        'max_compression_ratio' => (int) env('PREDICTILY_XLSX_MAX_RATIO', 100),
        'ratio_min_bytes' => 5 * 1_048_576,
        // Lectura de encabezados y vista previa (dentro de la petición de subida).
        'max_columns' => (int) env('PREDICTILY_XLSX_MAX_COLUMNS', 500),
        'analysis_rows' => 25,
        'analysis_max_scanned_rows' => (int) env('PREDICTILY_XLSX_ANALYSIS_MAX_SCANNED_ROWS', 20_000),
        // Bytes de una hoja que el lector puede consumir en la ventana interactiva antes de haber entregado las primeras filas
        // (nodos previos a `<sheetData>` o a la primera `<row>`). Medido: ≈ 6 MB/s de nodos, así que 8 MB ≈ 1,3 s. Las filas de
        // un libro real aparecen en los primeros KB; el recorrido posterior de la hoja (importación en cola) no tiene este tope.
        'analysis_max_sheet_bytes' => $megabytes('PREDICTILY_XLSX_ANALYSIS_MAX_SHEET_MB', 8),
        // Presupuesto de tiempo de TODA la ventana interactiva, apertura del libro (cadenas compartidas, estilos, encabezado de cada
        // hoja) incluida, medido en cada lectura de OpenSpout. No rige la lectura en cola.
        'analysis_timeout_seconds' => (float) env('PREDICTILY_XLSX_ANALYSIS_TIMEOUT', 10),
    ],
];
