<?php

/*
|--------------------------------------------------------------------------
| Auto Mapper (V2.5 W6-C · §4; docs/integrations/AUTO_MAPPING.md)
|--------------------------------------------------------------------------
| TODOS los valores son PROVISIONALES: umbrales con su justificación, «a aprobar en el piloto» (nunca universales). La confianza que
| calcula el Auto Mapper es un PUNTAJE HEURÍSTICO entre 0 y 1 (nombre, tipo, contexto de tabla y, si hay, muestra); NO es una probabilidad
| calibrada: no se ha medido contra mapeos reales de clientes.
*/

return [
    // Puntaje mínimo para proponer una columna. Por debajo, el campo queda SIN mapear y marcado para revisión humana.
    // Justificación: 0,60 deja pasar una coincidencia exacta de sinónimo con tipo compatible (≥ 0,80) y una parcial con un token de más
    // (≈ 0,66), y deja fuera las que solo coinciden por una palabra genérica («codigo», «fecha») sin otra evidencia. A aprobar en el piloto.
    'min_confidence' => (float) env('PREDICTILY_MAPPING_MIN_CONFIDENCE', 0.60),

    // Si el segundo mejor candidato queda a menos de este margen del primero, el campo es AMBIGUO: no se elige ninguno.
    'ambiguity_margin' => (float) env('PREDICTILY_MAPPING_AMBIGUITY_MARGIN', 0.10),

    // Bandas informativas del puntaje (no son probabilidades): alta ≥ high, media ≥ min_confidence, baja por debajo.
    'high_band' => 0.80,

    'discovery' => [
        // Tope de tablas y columnas que devuelve un descubrimiento (orden: más filas estimadas primero); el resto se informa como truncado.
        'max_tables' => (int) env('PREDICTILY_MAPPING_MAX_TABLES', 2000),
        'max_columns' => (int) env('PREDICTILY_MAPPING_MAX_COLUMNS', 60000),
        // Tablas y columnas que se devuelven en la respuesta de /schema/discover (el catálogo completo se usa internamente).
        'response_max_tables' => 300,
    ],

    'sampling' => [
        // Muestras estadísticas de solo lectura: nunca filas completas, nunca columnas con nombre de dato personal.
        'enabled' => (bool) env('PREDICTILY_MAPPING_SAMPLING', true),
        // Filas leídas para confirmar el tipo y la forma de una columna de texto (se descartan; solo se conservan proporciones).
        'pattern_rows' => 200,
        // Valores distintos máximos de una columna categórica (tipo y estado de documento) para proponer mapeos de valores. También tope de los valores sin resolver que se guardan.
        'distinct_cap' => 25,
        // Longitud máxima de un valor distinto devuelto; más largo se descarta (no es un código de vocabulario).
        'value_max_length' => 40,
        // Frecuencia mínima (k-anonimato simple): un valor que aparece en menos filas NO se devuelve (un valor único puede ser un nombre, un teléfono o un dato suelto). Se cuenta
        // cuántos se omitieron. Justificación: un código de vocabulario real (tipo o estado de documento) se repite; los valores raros los resuelve la persona al revisar (el importador
        // los marca REVIEW_REQUIRED). A aprobar en el piloto.
        'min_value_count' => (int) env('PREDICTILY_MAPPING_MIN_VALUE_COUNT', 5),
        // Filas estimadas por encima de las cuales los valores distintos se leen solo del inicio de la tabla (`prefix_rows`), no de toda; el perfil se marca parcial.
        // Justificación: un GROUP BY completo dentro de una petición HTTP debe acotarse; con estos valores la lectura completa se limita a tablas de hasta medio millón de filas
        // estimadas. A aprobar en el piloto.
        'distinct_scan_max_rows' => (int) env('PREDICTILY_MAPPING_DISTINCT_SCAN_MAX_ROWS', 500_000),
        'prefix_rows' => (int) env('PREDICTILY_MAPPING_PREFIX_ROWS', 50_000),
        // Tiempo máximo de cada sentencia de muestra, por debajo del del conector (segundos). Una muestra que lo supera se omite (la columna queda sin perfil) y la propuesta sigue.
        // A aprobar en el piloto.
        'statement_timeout_seconds' => (int) env('PREDICTILY_MAPPING_SAMPLE_TIMEOUT', 5),
    ],

    // Uniones entre tablas: saltos máximos desde la tabla principal y tablas máximas en una consulta generada.
    'joins' => [
        'max_hops' => 3,
        'max_tables' => 6,
    ],

    // Entidades con importador canónico que el Auto Mapper sabe proponer. «clientes» no tiene importador propio: sus atributos
    // (código, segmento, provincia) viajan en la propuesta de ventas, unidos por clave foránea.
    'entities' => ['sales', 'products', 'inventory', 'suppliers', 'lead_times', 'branches'],

    // Sistemas de origen que acepta una plantilla (varchar(20)).
    'system_kinds' => ['erp', 'pos', 'crm', 'spreadsheet', 'api', 'bridge', 'other'],
];
