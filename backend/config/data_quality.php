<?php

/**
 * Calidad de ingestión (V2.5 · W2-A; ADR-V25-05 y ADR-V25-24). Parámetros del Data Quality Engine de ingestión.
 *
 * Todos los valores son PUNTOS DE PARTIDA «a aprobar en el piloto»: no son umbrales universales. Forman parte de la
 * huella `rules_version` de cada informe, así que cambiarlos queda registrado.
 */
return [
    /** Filas máximas de `data_quality_issues` por informe; el exceso se agrega en `issue_counts` con la marca `truncated`. */
    'max_issue_rows_per_report' => (int) env('PREDICTILY_DQ_MAX_ISSUE_ROWS', 2000),

    /** Longitud máxima del valor original / propuesto que se guarda en un hallazgo (el registro íntegro queda en Bronze). */
    'original_value_max_chars' => 200,

    /** Muestras (por código y campo) que acompañan un hallazgo AUTO_FIX_SAFE agregado. */
    'auto_fix_samples' => 3,

    /** Tolerancia (valor absoluto) con que se comparan importes netos entre capas de la reconciliación. */
    'reconciliation_amount_tolerance' => 0.01,
];
