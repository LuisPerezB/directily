<?php

/**
 * Medición de outcomes y ROI honesto (V2.5 · W5-C; ADR-V25-14; §12). Parámetros de partida del servicio `OutcomeMeasurementService`. Ninguno es un umbral universal:
 * todos son hipótesis con justificación en docs/decision/OUTCOME_MEASUREMENT.md y se aprueban (o se cambian por empresa en `tenants.settings.outcomes`, dentro de los
 * pisos de abajo) en el piloto con datos reales de cada empresa.
 *
 * - `settling_lag_days`: días que se espera DESPUÉS de cerrar la ventana de observación antes de medir. Es el margen para que lleguen los documentos de venta que
 *   se cargan tarde (lectura as-of: lo que se mide es lo que se conocía al cierre de ventana + este margen; un documento posterior no entra en esa medición y se
 *   cuenta aparte). 3 días cubre la carga diaria con un fin de semana; a aprobar en el piloto con el retraso real de ingestión de cada fuente.
 * - `confidence_level`: nivel de los intervalos de confianza del análisis por intención de tratamiento (0,80; 0,90; 0,95 o 0,99).
 * - `min_group_size`: unidades mínimas por grupo (tratamiento y control) para que el análisis por intención de tratamiento se publique como estimación. Por debajo,
 *   las aproximaciones del intervalo no son fiables y la etiqueta es `insufficient_sample`. 30 es la regla práctica habitual; a aprobar en el piloto.
 * - `min_buyers_per_arm`: compradores mínimos por grupo (unidades que compraron, con costo confiable) para publicar CUALQUIER intervalo monetario. La contribución por
 *   unidad es cero para la mayoría y de cola pesada para quien compra: con pocos compradores el intervalo es una ilusión de precisión (con un solo comprador, un
 *   intervalo «estrecho»). 10 es una hipótesis de partida; a aprobar en el piloto.
 * - `bootstrap_resamples` (B): remuestreos del intervalo percentil bootstrap de la contribución. 2000 estabiliza los percentiles 2,5 y 97,5 (error de simulación ≈ 0,02
 *   desviaciones estándar). El intervalo es determinista: la semilla se deriva de la empresa, el experimento y el horizonte y queda en la evidencia.
 * - `bootstrap_max_draws`: tope de números aleatorios por intervalo (cómputo por petición). Si B remuestreos lo superan, B baja hasta un mínimo de 500; por debajo no
 *   se publica el intervalo (`bootstrap_budget_exceeded`). 40 millones ≈ 4 s.
 * - `usage_prices`: precio por unidad de cada métrica de `tenant_usage_records` (p. ej. `train_cpu_seconds`). VACÍO por defecto: sin precios el uso de plataforma NO se
 *   convierte en dinero (ni se inventa un costo); el modelo de costos (AWS_COST_MODEL.md) los aporta.
 * - `floors`: lo MÁS BAJO que una empresa puede configurar. `min_group_size` ≥ 20 y `min_buyers_per_arm` ≥ 5: por debajo el intervalo no tiene sentido estadístico.
 *   (Solo las pruebas bajan estos pisos, con `config()`, para ejercitar la mecánica con muestras pequeñas.)
 * - `report_default_days` / `report_max_days`: periodo (por el corte `as_of`) de `GET /decisions/roi` cuando no se indica uno (180 días) y su máximo (730 días).
 */
return [
    'method_version' => 'outcomes-v1',
    'settling_lag_days' => 3,
    'confidence_level' => 0.95,
    'min_group_size' => 30,
    'min_buyers_per_arm' => 10,
    'bootstrap_resamples' => 2000,
    'bootstrap_max_draws' => 40000000,
    'usage_prices' => [],
    'floors' => ['min_group_size' => 20, 'min_buyers_per_arm' => 5, 'bootstrap_resamples' => 500],
    'report_default_days' => 180,
    'report_max_days' => 730,
];
