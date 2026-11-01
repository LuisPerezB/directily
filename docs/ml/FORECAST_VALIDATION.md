# Validación de pronósticos de Demand: protocolo, métricas por horizonte y segmento y escenarios (V2.5 §6, §8 y §26)

> **Alcance y estado.** Este documento describe **cómo se mide** la calidad del pronóstico de Demand: el protocolo rolling-origin as-of con período
> final reservado, las métricas con sus fórmulas y denominadores, los segmentos y horizontes en que se persisten (`model_evaluations`,
> `level = 'segment'`), los escenarios de forma de serie con la prueba que afirma cada uno y lo que **no** se puede afirmar todavía. Implementa el
> paquete **W3-A** de V2.5 (requisitos V25-DEM-030..035, V25-SEL-013, -014, -016, -020, V25-HOR-008) sobre ADR-V25-03 (semántica de días),
> ADR-V25-23 (conocimiento as-of) y ADR-V25-21 (política de selección; la ejecuta W3-B, ver `docs/ml/FORECAST_SELECTION.md`).
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**,
> **BLOQUEADO** o **NO APLICA**. **Todos los datos de las pruebas son sintéticos y generados en la prueba**: demuestran fórmulas y propiedades del
> pipeline, **no precisión real**; la precisión por segmento con datos de un cliente queda **BLOQUEADA** hasta el piloto (§6). Las pruebas están en
> `engine/tests/` y se ejecutan desde `engine/` (o con `PYTHONPATH=<repo>/engine`) con `ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<bd_de_pruebas>`
> (solo las de integración usan la base; se migra con `cd backend && DB_DATABASE=<bd_de_pruebas> php artisan migrate:fresh --force`).

## 1. Protocolo de validación

| Elemento | Qué hace | Dónde / prueba | Estado |
|---|---|---|---|
| Ventanas de selección rolling-origin | 2-3 orígenes con escalones de 7-28 días según la historia (`backtest/rolling.py::TIERS`); cada ventana entrena con datos ≤ su corte, con perfil, clase y escalas recalculados en ese corte | `test_pipeline.py::test_future_after_a_backtest_cutoff_does_not_change_that_window` | IMPLEMENTADO / VERIFICADO (W1) |
| Conocimiento as-of por ventana | cada ventana usa las versiones de los documentos visibles en SU corte (`AsOfWindows`); los reales con que se evalúa son la verdad disponible al corte final | `test_asof_evaluation_integration.py` | IMPLEMENTADO / VERIFICADO (W1) |
| Período final reservado (holdout) | origen = corte − H del plan; sus fechas objetivo no comparten días con las ventanas de selección; **no interviene en la selección, la calibración ni los hiperparámetros** de lo que evalúa | `test_pipeline.py::test_holdout_is_disjoint_from_selection_windows`, `::test_holdout_evaluation_is_independent_of_holdout_data` | IMPLEMENTADO / VERIFICADO |
| Manifiesto del protocolo | `forecast_runs.evaluation_manifest` lista ventanas, fechas objetivo, residuos de calibración y `used_for_*` del período final; `demand/manifest.py::verify` devuelve las violaciones | `test_manifest.py`; W3-A: `test_segment_metrics_parity.py::test_the_final_period_decides_nothing_in_the_segment_metrics_of_the_selection_windows` | IMPLEMENTADO / VERIFICADO |
| Días no evaluables | quiebre de stock, fuente ausente y cierre (`stockout` en las filas del backtest; `day_status` dice cuál) **no son ceros observados**: no entran en métricas, selección ni calibración y se **cuentan por causa** | `test_day_status.py`; W3-A: §2 y §4 (escenario de quiebre) | IMPLEMENTADO / VERIFICADO |
| Alcance de cada cifra | `evaluation_type = 'backtest'` es la medida principal (holdout si existe; si no, las ventanas, declarado en `extra.evaluation_scope` y en `warnings`); `selection_windows` es la secundaria y optimista por construcción | `test_pipeline.py::test_compares_against_baseline` | IMPLEMENTADO / VERIFICADO |

Lo que W3-A **no** cambia: la selección, los intervalos, los pronósticos y las recomendaciones. Las métricas por segmento se calculan sobre las
mismas filas del backtest que ya existían (§5).

## 2. Métricas: fórmulas, denominadores y cuándo son nulas

Sea `e = pred − real` por serie y día evaluable (sin quiebre, fuente ausente ni cierre). Todas las sumas son sobre los días evaluables del
segmento, de los primeros `H` días del horizonte y de todas las ventanas del alcance.

| Métrica | Fórmula | Denominador / cobertura explícitos | Es `null` cuando |
|---|---|---|---|
| **WAPE** | `Σ|e| / Σ real` | `metrics.denominator.sum_actual` (Σ real, unidades) y `metrics.sample` (`evaluable_days`, `excluded_days`, `total_days`, `evaluable_share`, `n_series`, `n_windows`, `exclusions` por causa) | `Σ real = 0` (`wape_status = undefined`) **o** `Σ real < wape_min_denominator` (`unstable`): la **columna** `wape` queda nula; el valor crudo va en `metrics.wape_raw` (solo si `Σ real > 0`) |
| **MAE / RMSE** | `Σ|e| / n`, `√(Σe² / n)` | `n = evaluable_days` | no hay días evaluables o el segmento es `insufficient_data` |
| **MASE** | `media(|e| / MAE_naive7_entrenamiento)` | la escala es la del naive estacional de 7 días con datos ≤ el corte **de cada ventana** (`seasonal_naive_scales`); `n_scaled` observaciones la tienen, `exclusions.scale_undefined` cuenta las que no | ninguna observación tiene escala (serie constante, de ceros o con < 14 pares) |
| **RMSSE** | `√(media(e² / MSE_naive7_entrenamiento))` | igual que MASE | igual que MASE |
| **Sesgo** | `Σe / Σ real` (positivo = sobreestima) | el mismo denominador y estado que el WAPE | igual que el WAPE; `metrics.mean_error = Σe / n` (unidades/día) y `bias_raw` siguen disponibles |
| **Pinball P10/P50/P90** | media de `max(q·(y−f), (q−1)·(y−f))` con `f = lo_80, p50, hi_80` | solo filas con intervalo calibrado (`metrics.interval.n_scored`) | no hay período final independiente, la familia no tiene intervalos calibrados, o (P50) la mediana no se publica (`p50_decision`) |
| **Cobertura 80/95 y anchura** | proporción de reales dentro de `[lo, hi]`; media de `hi − lo` (y relativa a Σ real) | `metrics.interval` | igual que pinball; solo el **modelo servido** y el **baseline** tienen intervalos calibrados |
| **Total de H días** (`metrics.horizon_total`) | WAPE/MAE/sesgo del total de los `H` días por serie y ventana, **solo ventanas completas**; los días no evaluables restan de ambos lados | `n_pairs`, `n_incomplete_pairs`, `denominator` | `n_pairs = 0` o denominador inestable |

**Filas previas (V25-SEL-013)**: todas las filas de `model_evaluations` de Demand (`overall`, `demand_class`, `horizon`, `horizon_total`, `branch`, `category`, `product`, `series`, `total`)
declaran ahora `metrics.denominator.sum_actual`, `wape_status` y `wape_unstable` (aditivo; la columna `wape` de esas filas **no cambia**: los consumidores la leen tal cual y la marca solo avisa).
Prueba: `test_segment_metrics_parity.py::test_every_reported_wape_declares_its_denominator_and_never_has_a_zero_one`.

**Estados del WAPE** (`metrics.wape_status`): `ok` (denominador ≥ umbral), `unstable` (0 < denominador < umbral) e `undefined` (denominador 0). En los dos
últimos `metrics.wape_unstable = true`, y `metrics.alternative_metric` informa la métrica alternativa **explícita**: el **MAE escalado (MASE)** si la
escala existe (`name = mase`) o el **error absoluto agregado** `Σ|e|` y su promedio por día evaluable (`name = aggregate_absolute_error`), con
`used_because = wape_undefined | wape_unstable`. **Nunca** se persiste un WAPE con denominador 0, ni un cero en lugar de «no estimable».

Pruebas: `test_segment_metrics.py::test_every_metric_matches_the_independent_reference_for_every_dimension_horizon_and_family` (cada métrica, 6 segmentos × 2 horizontes × 3 familias, contra un cálculo directo sin la maquinaria de agregados), `::test_wape_status_separates_undefined_unstable_and_ok`, `::test_guarded_wape_never_divides_by_a_zero_denominator`, `::test_alternative_metric_prefers_the_scaled_mae_and_falls_back_to_the_aggregate_absolute_error`, `::test_all_zero_actuals_give_no_wape_but_an_explicit_alternative_and_never_a_zero_division`, `::test_without_a_defined_scale_the_alternative_is_the_aggregate_absolute_error`, `::test_a_small_denominator_is_wape_unstable_keeps_the_raw_value_aside_and_nulls_the_column`, `::test_the_wape_denominator_is_the_sum_of_actuals_and_excluded_days_are_counted_by_class_not_zeroed`. **IMPLEMENTADO / VERIFICADO.**

## 3. Segmentos y horizontes

### 3.1 Filas persistidas

`model_evaluations` con `core = 'demand'`, `level = 'segment'`, `level_key = '<dimensión>:<valor>'`, `horizon_days = H` y las métricas en `metrics` (jsonb,
`segment_metrics_version = demand-segment-metrics-v1`). Cada fila existe en los dos alcances (`evaluation_type = 'backtest'` y `'selection_windows'`); sin período final
independiente solo existe el alcance `backtest` calculado con las ventanas (`extra.evaluation_scope = 'selection_windows'`), sin pinball ni cobertura de intervalos (no hay prueba prospectiva).

- **Horizontes**: los del catálogo (7/14/15/30/60/90, `platform.config.HORIZONS`) **que el backtest alcanza**; `H` significa los **primeros H días del
  horizonte** (pasos 1..H, acumulativo) con el error diario por serie, más `metrics.horizon_total` para el total de `H` días. Un `H` mayor que el
  backtest **no genera fila** (no se inventa); `summary.segment_metrics.horizons_not_evaluated` lo declara. Prueba: `::test_horizons_are_cumulative_and_only_the_ones_the_backtest_reaches_are_emitted`.
- **Familias por segmento y horizonte** (`metrics.segment.roles`): `selection` (el modelo servido, `is_selected`), `seasonal_naive` (baseline),
  `lightgbm_global` (ML, si hubo) y el **mejor estadístico** del segmento (puede coincidir con el baseline: la fila lleva ambos roles). El mejor estadístico se
  elige por menor WAPE diario con las **ventanas de selección**, nunca con el período final (`::test_the_best_statistical_is_chosen_with_the_selection_windows_never_with_the_final_period`);
  sin período final independiente se elige con las mismas ventanas que se evalúan y la fila lo declara (`comparison.best_statistical_chosen_with`).

### 3.2 Dimensiones

| Dimensión | Valores (`level_key`) | Definición | Sin fuga |
|---|---|---|---|
| `demand_class` | `smooth`, `erratic`, `intermittent`, `lumpy` | la clase que la serie tenía **en el corte de su ventana** | por construcción del backtest (F4); `::test_a_series_changes_demand_class_between_windows_without_leaking_the_other_window` |
| `category` | `c<id>`, `none` | categoría del producto; las series cuyo producto **no tiene categoría** van a `category:none` (no se pierden de la partición) | catálogo estático; `test_segment_metrics.py::test_series_without_a_category_go_to_category_none_so_the_dimension_still_partitions` |
| `branch` | `b<id>` | sucursal | — |
| `volume_tier` | `top{N}`, `rest` (con el 20 % por defecto: `top20`; con `segment_volume_top_share = 0,1`: `top10`) | el `segment_volume_top_share` (20 %) superior de las series por unidades (imputadas en días no evaluables) de los últimos `segment_volume_lookback_days` (90) días **hasta el corte de cada ventana**; las series sin unidades en esa ventana nunca son `top` | `::test_volume_tier_and_series_age_use_only_what_was_known_at_each_cutoff` (una explosión de ventas posterior al corte no cambia el tramo de esa ventana) |
| `series_age` | `new`, `mature` | `new` si al corte de la ventana la serie tenía menos de `segment_new_series_days` (90) días de historia | igual que la anterior |

El tramo de volumen y la vida se calculan con el panel **tal como se conocía** en cada corte (`AsOfWindows.at(corte)`); «top 20 % de unidades» se
interpreta como el 20 % superior **de las series** por volumen (no como el conjunto de series que acumula el 20 % de las unidades). Las dimensiones
`category` y `branch` se limitan a `segment_max_values` (50) valores, los de mayor volumen del modelo servido; lo omitido se declara en
`extra.omitted_values`. Las dimensiones **particionan** las observaciones evaluables del modelo servido (cada observación cae en exactamente un valor de cada dimensión) **salvo los valores de categoría o sucursal omitidos por el tope** `segment_max_values`, que se declaran en `extra.omitted_values`: `test_forecast_validation_scenarios.py::test_every_dimension_partitions_the_evaluable_observations_of_the_served_model` (sin tope alcanzado).

### 3.3 Segmentos con pocas observaciones (`insufficient_data`)

Un segmento con menos de `segment_min_series` series distintas **con al menos un día evaluable** (una serie 100 % censurada no cuenta; `sample.n_series` es ese conteo) o menos de `segment_min_obs` series-día evaluables (en ese `H`) es
`status = insufficient_data` con `insufficient_reasons` (`few_series`, `few_observations`). La fila **sí** se persiste con sus tamaños de muestra y el
denominador, y **sin cifras de error**: las columnas `wape/mae/rmse/bias/coverage_*` y `mase`, `rmsse`, `sum_abs_error`, `horizon_total`, la estabilidad por
ventana y las cifras de la comparación quedan nulas (`metrics.withheld = true`). La UI y la API no deben mostrar cifras de estas filas. Pruebas:
`::test_segments_with_few_series_or_observations_are_insufficient_data_without_figures`, `::test_min_series_counts_only_series_with_evaluable_days`.

### 3.4 Comparación baseline vs ML, estabilidad y resumen

- **Comparación** (`metrics.comparison`, solo en la fila del servido): `selected_vs_baseline`, `best_statistical_vs_baseline` y `ml_vs_baseline`, cada
  una con la métrica usada (`wape`; si algún WAPE no es confiable, `aggregate_absolute_error` sobre las **mismas** observaciones), la diferencia, la mejora
  relativa y el veredicto `better | similar | worse | not_comparable` (similar = diferencia relativa ≤ `segment_similar_tolerance`, 3 %). Un modelo con soporte
  distinto (p. ej. LightGBM sin filas en una ventana) no se compara por error absoluto. Pruebas: `::test_comparison_verdicts_use_the_configurable_tolerance_and_declare_the_metric`, `::test_a_model_with_different_support_is_not_compared_on_absolute_error`.
- **Estabilidad entre ventanas** (`metrics.stability`): WAPE por ventana (con su corte, denominador y `used`), media, desvío (`ddof = 1`) y coeficiente de variación; **solo cuentan las ventanas cuyo denominador es ≥ `wape_min_denominator`** (las demás se listan con `used = false` y se cuentan en `n_windows_small_denominator`; si ninguna alcanza, `status = insufficient_denominator`); `unstable_across_windows`
  si el coeficiente supera `segment_stability_cv_warn` (0,5). El período final es **una sola ventana**: el desvío **no es estimable** (`single_window`, nunca 0) y se adjunta la
  estabilidad de las ventanas de selección como referencia (`stability_selection_windows`). Pruebas: `::test_stability_reports_the_wape_of_each_window_and_the_std_of_the_independent_period_is_not_estimable`, `::test_window_stability_is_not_estimable_with_one_window_and_flags_a_dispersed_series`, `::test_stability_leaves_out_the_windows_whose_denominator_is_below_the_wape_minimum`.
- **Resumen en la ejecución** (`forecast_runs.summary.segment_metrics`): **compacto y de tamaño acotado** (el backend devuelve el resumen entero en cada listado de ejecuciones; una versión anterior con una celda por segmento y horizonte pasaba de
  17 a 92 KB con 300 series y 30 categorías). Trae `counts` (segmentos, celdas, por estado, por motivo y por dimensión con sus estados y veredictos), el alcance principal, los horizontes evaluados y no evaluados y, como máximo,
  `segment_summary_max_cells` (20) **celdas que merecen atención**, en este orden: peor que el baseline, WAPE indefinido, WAPE inestable y `insufficient_data` (con segmento, horizonte, WAPE, denominador, días evaluables y WAPE del baseline);
  `attention_total` y `attention_truncated` dicen cuántas hubo. Remite a `model_evaluations` para el detalle (`detail`) y al manifiesto para las definiciones (`definitions_ref`). Tamaño medido: 2,5 KB con 177 series; < 8 KB con 50 categorías y 50 sucursales
  (la prueba lo exige). `summary.metrics` y los demás campos no cambian. Pruebas: `::test_the_run_summary_stays_small_with_fifty_categories_and_fifty_branches_and_points_to_the_detail`, `::test_attention_cells_list_the_worse_than_baseline_first_then_undefined_unstable_and_insufficient`,
  `test_segment_metrics_parity.py::test_the_summary_block_is_compact_and_mirrors_the_persisted_rows_of_the_served_model`.
- **Textos estáticos una sola vez por ejecución**: las filas de segmento llevan cifras y códigos (`horizon_basis`, `best_statistical_chosen_with = selection_windows | same_windows_optimistic`, `interval.basis`) y `metrics.definitions_ref`; las definiciones (base del horizonte, base del total de H días, base de los intervalos,
  regla de comparación, estabilidad, umbrales con su justificación «a aprobar en el piloto») viven en `forecast_runs.evaluation_manifest.segment_metrics`. Cada fila conserva solo lo que necesita para interpretarse (`denominator.min_required`, `comparison.similar_tolerance`, `stability.cv_warn`). Pruebas:
  `::test_static_texts_live_once_in_the_manifest_not_in_every_row`, `test_segment_metrics_parity.py::test_segment_rows_carry_numbers_and_codes_not_repeated_texts`.
- **Aislamiento de fallos**: el cálculo de segmentos es aditivo y está aislado; un error al preparar el contexto o al calcular las filas **no aborta la ejecución**: se registra la limitación `segment_metrics_unavailable` (en `summary.limitations`) y `summary.segment_metrics.available = false` con el error; el pronóstico, la selección y las
  demás filas de evaluación no cambian. El contexto as-of de volumen y vida se construye **una vez** y lo comparten el gate de segmentos de la política V2.5 (W3-B) y las métricas por segmento. Pruebas: `test_forecast_validation_scenarios.py::test_a_failure_in_the_segment_metrics_is_recorded_and_changes_nothing_that_is_served`, `::test_the_segment_context_is_built_once_and_shared_by_the_promotion_gate_and_the_metrics`.
- **Proveedor para la política V2.5** (`demand.segments.provider_from_context` / `make_provider`): entrega a `selection.promotion` (W3-B) los mismos segmentos as-of con la firma de su `SegmentProvider`. **Ya está conectado** en `pipeline/core.py` (W3-B): el gate «sin regresión por segmento crítico» usa estos segmentos (queda registrado en la evidencia del gate, `demand.segments.make_provider`); si las métricas están apagadas o fallan, rige su `default_segments`. Prueba: `::test_the_provider_for_the_promotion_policy_returns_the_same_segments_as_the_metrics`.

### 3.5 Umbrales configurables (todos «a aprobar en el piloto»)

Se resuelven con la misma precedencia que el resto (core → perfil sectorial → `settings.cores.demand` de la empresa, validados en rango) y quedan en la
configuración efectiva de cada ejecución. Ninguno es universal ni cambia lo servido.

| Clave (`demand`) | Valor inicial | Justificación |
|---|---|---|
| `segment_metrics_enabled` | `true` | apagar no calcula ni persiste las filas `segment` (las demás métricas no cambian) |
| `wape_min_denominator` | 30 unidades | el mismo piso con que el selector agrupa las clases con poca evidencia (Σ ventas < 30); por debajo, el cociente depende de muy pocas unidades |
| `segment_min_series` | 3 | el mínimo con que el selector evalúa una clase por separado |
| `segment_min_obs` | 90 series-día | una serie durante un trimestre (≈ 13 semanas de la estacionalidad semanal) |
| `segment_volume_top_share` / `segment_volume_lookback_days` | 0,2 / 90 | regla de Pareto de V2.5 §6; un trimestre de volumen as-of |
| `segment_new_series_days` | 90 | V2.5 §6 («nueva < 90 días») |
| `segment_max_values` | 50 | acota las filas en categorías y sucursales |
| `segment_similar_tolerance` | 0,03 | igual a `min_improvement` de la política heredada |
| `segment_stability_cv_warn` | 0,5 | referencia de dispersión alta; sin calibrar con datos reales |
| `segment_summary_max_cells` | 20 | tope de celdas de atención en el resumen de la ejecución (≈ 4 KB); el resto está en `model_evaluations` |

`::test_config_defaults_ranges_and_tenant_overrides_flow_into_the_thresholds`, `::test_the_top_share_and_the_new_series_threshold_are_configurable`, `::test_a_small_denominator_is_wape_unstable_...` (el mismo dato es estable o no según el umbral).

## 4. Escenarios de forma de serie (T03)

Todos con ventas **ficticias** generadas en `engine/tests/unit/_scenario_builders.py` (420 días; 6-8 series) y ejecutados por el pipeline completo
(`test_forecast_validation_scenarios.py`). Cada uno afirma algo verificable; ninguno afirma precisión real.

| Escenario | Qué se afirma | Prueba | Estado |
|---|---|---|---|
| Constante | el servido no empeora al baseline en ninguno de los horizontes y alcances; WAPE exacto 0 con denominador explícito > 0; MASE/RMSSE **indefinidos** (escala 0), no cero, y contados en `scale_undefined`; a H = 7 y 14 el período final es `insufficient_data` (6 series × H < 90) | `::test_constant_series_the_served_model_does_not_worsen_the_baseline_and_the_scaled_metrics_are_undefined` | IMPLEMENTADO / VERIFICADO |
| Irregular | WAPE entre 0 y 1,5 con denominador igual a Σ real del backtest, MASE/RMSSE definidos, intervalos con cobertura y anchura, las cinco dimensiones presentes y particionando las observaciones, estabilidad estimable en las ventanas y `single_window` en el período final; pinball y anchura de un segmento que cubre toda la población = los de la fila `overall` | `::test_irregular_series_metrics_are_defined_with_explicit_denominators_and_cover_every_dimension`, `::test_every_dimension_partitions_...`, `::test_segment_pinball_and_interval_width_equal_the_overall_ones_when_the_segment_is_the_whole_population` | IMPLEMENTADO / VERIFICADO |
| Ceros / intermitente | un segmento sin ventas en el período final: Σ real = 0 ⇒ WAPE y sesgo **nulos**, `wape_status = undefined`, métrica alternativa explícita, MAE finito, ninguna cifra no finita en ninguna fila; con pocas ventas (0 < Σ real < 30) en las ventanas de selección ⇒ `unstable` con valor crudo aparte; con 21 series-día ⇒ `insufficient_data` | `::test_zero_demand_segment_has_no_wape_but_an_explicit_alternative_and_no_invented_number`, `::test_few_sales_make_the_wape_unstable_in_the_selection_windows_and_few_days_make_the_segment_insufficient` | IMPLEMENTADO / VERIFICADO |
| Varios picos | las series con picos se clasifican `erratic`/`lumpy`; los picos **no se descartan** (están en el denominador y en el error; 0 días excluidos); la anchura del intervalo 95 % es mayor que la de la serie lisa (que es 0) | `::test_series_with_several_spikes_are_erratic_keep_their_spikes_in_the_error_and_widen_the_intervals` | IMPLEMENTADO / VERIFICADO |
| Ruptura estacional | la ventana cuyo horizonte contiene la ruptura (nivel ×2 y fase semanal +3 días el día 250) empeora > 1,4 × frente a las anteriores; el desvío entre ventanas es > 3 × el del control sin ruptura; con `segment_stability_cv_warn = 0,2` la ruptura se marca `unstable_across_windows` y el control no; con el umbral por defecto (0,5) el mismo dato no se marca (el umbral decide) | `::test_a_seasonal_break_shows_up_as_instability_between_windows_but_a_stable_series_does_not`, `::test_the_stability_threshold_is_configurable_and_recorded` | IMPLEMENTADO / VERIFICADO como **reporte de inestabilidad**; la detección automática de cambio de régimen NO está implementada (§6) |
| Promoción conocida ex ante | Seasonal Naive no usa promociones (error idéntico con o sin conocimiento previo); LightGBM baja su WAPE en días de promoción de 0,67 a 0,54 cuando la promoción se conocía al origen; una promoción registrada **después** del origen no cambia ninguna predicción del backtest (idéntico a quitarla de la tabla, `rtol 1e-9`) | `::test_a_promotion_known_at_the_origin_helps_ml_but_one_registered_later_does_not_and_the_baseline_ignores_both`, `::test_a_promotion_registered_after_the_origin_changes_no_backtest_forecast_at_all`, `::test_the_segment_metrics_separate_baseline_and_ml_in_the_promotion_scenario` | IMPLEMENTADO / VERIFICADO |
| Quiebre de 10 días | 10 días con existencias 0 + el día siguiente (que empieza sin stock) = 11 días `stockout_censored`; `exclusions.stockout_days = 11` solo en la categoría de la serie; 529 de 540 días evaluables; el WAPE equivale al calculado sin esos días y sería mayor si contaran como ceros; el peso de entrenamiento de LightGBM es 0 en esos 11 días; el pronóstico de la serie queda a < 5 % de su nivel real, mientras que sin la marca (ceros observados) baja > 5 % | `::test_a_ten_day_stockout_is_censored_counted_and_kept_out_of_the_wape`, `::test_the_stockout_does_not_contaminate_the_training_of_the_global_model_or_the_forecast_level` | IMPLEMENTADO / VERIFICADO |

**Hallazgo del escenario de promoción (para el piloto, no una conclusión de producto)**: con promociones de 7 días cada 28 (unidades ×2,5) y 15 repeticiones
por serie, LightGBM con la promoción conocida tiene mejor WAPE total en el período final que el baseline (0,37 frente a 0,48), pero **en los días de promoción no
supera al baseline** (0,54 frente a 0,47). Medir si el efecto de una promoción se captura exige promociones reales del cliente; una ablación de `promo_discount`
y una dimensión «día con/sin promoción» en las filas de segmento quedan **DISEÑADAS / NO IMPLEMENTADAS** (V25-DEM-033 sigue parcial).

**Sanidad de las pruebas** (verificación manual de este paquete, NO automatizada): sobre una copia del motor se alteraron a propósito ocho reglas y la
prueba correspondiente falló cada vez; se descartó la copia. Reglas alteradas: los días censurados contaban como evaluables (pruebas de fórmulas y de quiebre),
el WAPE inestable no se marcaba, el mejor estadístico se elegía con el período final, el tramo de volumen usaba ventas posteriores al corte, no había umbral de
insuficiencia, `known_at` de las promociones se ignoraba, el quiebre no se ponderaba 0 en LightGBM y la inestabilidad entre ventanas nunca se marcaba.

## 5. Persistencia, compatibilidad y costo

- **Persistencia**: `pipeline/forecast_run.py::persist_evaluations` (misma sentencia de siempre, ahora una función). Un valor escalar que no cabe en su columna (`wape`/`bias`
  numeric(12,6), etc.) o es NaN/Inf se guarda **nulo** y la columna queda en `extra.column_overflow`, en lugar de abortar la transacción de toda la ejecución.
  `level_key` ≤ 80 y `model_family` ≤ 40 caracteres (verificado sobre todas las filas). Pruebas: `test_segment_metrics_parity.py::test_every_segment_row_fits_the_table_columns_and_serializes_without_non_finite_numbers`, `::test_a_value_that_does_not_fit_its_column_is_stored_null_...`,
  `tests/integration/test_segment_metrics_integration.py` (5 pruebas en PostgreSQL: persistencia con el contrato de la tabla, resumen en `forecast_runs.summary`, aislamiento por empresa, desbordamiento).
- **Consumidores existentes sin cambios**: el backend lee `model_evaluations` con niveles explícitos (`overall`, `horizon`, `horizon_total`, `demand_class`, `branch`, `product`, `series`); las filas `segment` no
  aparecen en esas consultas (`test_the_existing_consumer_queries_return_exactly_the_same_rows_as_before`). Los listados sin filtro de nivel (`/accuracy/evaluations`, exportación CSV de precisión)
  sí las incluirán: su exposición, filtros y paginación son de W3-D.
- **Lo servido no cambia con la configuración por defecto** (IMPLEMENTADO / VERIFICADO): `test_segment_metrics_parity.py::test_the_default_run_serves_exactly_what_the_run_without_segment_metrics_serves` compara pronósticos, selección,
  recomendaciones, `summary` y manifiesto con y sin las métricas por segmento, y `::test_the_pre_existing_evaluation_rows_are_unchanged_and_come_first` las filas de evaluación previas. Evidencia adicional (medición, no prueba):
  sobre la empresa sintética `ferreteria_andina` (12 productos, semilla 42) el pipeline de este árbol y el del commit 4eb34f4 producen `forecast_values` idénticos (5.952 filas), misma selección, mismo `summary.metrics`,
  mismas recomendaciones y las mismas 283 filas de evaluación (columnas escalares idénticas; en su jsonb solo se agregan `denominator`, `wape_status` y `wape_unstable`); este árbol agrega 528 filas de segmento.
- **Costo**: las métricas por segmento son agregados vectorizados sobre las filas del backtest; en una empresa sintética de 177 series (510.000 filas de backtest) añaden ≈ 2,8 s a una evaluación de ≈ 3,3 s
  (≈ 7 % de la ejecución completa de ≈ 37 s) y crecen linealmente con las filas del backtest. `segment_metrics_enabled = false` lo evita. Almacenamiento: ≈ 2,9 KB de jsonb por fila de segmento tras sacar los textos estáticos de cada fila (antes ≈ 3,4 KB; 528 filas ≈ 1,5 MB en la empresa de 12 productos; `segment_max_values` acota categorías y sucursales: con 50 + 50 valores son ≈ 5.000 filas ≈ 15 MB por ejecución). **Retención recomendada (DISEÑADA / NO IMPLEMENTADA: hoy `model_evaluations` no tiene retención por ejecución)**: conservar las filas `level = 'segment'` solo de la ejecución vigente y de las últimas ~12 ejecuciones de producción por empresa (≈ un año de pronósticos mensuales o tres meses de semanales) y poder reconstruir el resto desde el backtest; las filas `level = 'segment'` con `evaluation_type = 'selection_windows'` (optimistas) son las primeras candidatas a purgarse. Mediciones puntuales, no compromisos de rendimiento.

## 6. Estado honesto

| Tema | Estado |
|---|---|
| Métricas por horizonte (7/14/15/30/60/90 que el backtest alcanza) y segmento (clase, categoría, sucursal, tramo de volumen, vida), con denominador, cobertura, MAE, RMSE, MASE, RMSSE, sesgo, pinball, cobertura y anchura de intervalos (servido y baseline) | **IMPLEMENTADO / VERIFICADO** (§2, §3) |
| WAPE inestable/indefinido con métrica alternativa explícita y sin divisiones por cero | **IMPLEMENTADO / VERIFICADO** |
| Comparación baseline / mejor estadístico / LightGBM / seleccionado por segmento y horizonte, en ventanas de selección y en el período final, con `n_windows` y estabilidad | **IMPLEMENTADO / VERIFICADO** |
| `insufficient_data` por segmento con umbrales configurables | **IMPLEMENTADO / VERIFICADO**; los umbrales son iniciales, **a aprobar en el piloto** |
| Escenarios constante, irregular, ceros, varios picos, ruptura, promoción y quiebre de 10 días como pruebas del pipeline | **IMPLEMENTADO / VERIFICADO** con datos sintéticos |
| Métricas del modelo servido en `forecast_runs.summary.segment_metrics` | **IMPLEMENTADO / VERIFICADO** |
| Exposición en API y UI (`/models/evaluations`, tablas con denominador y marca de WAPE inestable) | **DISEÑADO / NO IMPLEMENTADO aquí**: es W3-D; esta entrega solo persiste |
| Conexión del proveedor de segmentos a la política V2.5 de W3-B en `pipeline/core.py` | **IMPLEMENTADO / VERIFICADO** (integrado por W3-B; el contexto se construye una vez: `::test_the_segment_context_is_built_once_and_shared_by_the_promotion_gate_and_the_metrics`) |
| Aislamiento de fallos del cálculo de segmentos (`segment_metrics_unavailable`) | **IMPLEMENTADO / VERIFICADO** |
| Retención de `model_evaluations` por ejecución | **DISEÑADO / NO IMPLEMENTADO** (recomendación en §5) |
| Detección automática de cambio de régimen o ruptura estacional (V25-DEM-032) | **DISEÑADO / NO IMPLEMENTADO**: hoy se **reporta** como inestabilidad entre ventanas; no hay detector ni degradación automática del `quality_status` |
| Intervalos de confianza (bootstrap por bloques) de las métricas (V25-SEL-016) | **DISEÑADO / NO IMPLEMENTADO**: hay desvío entre ventanas, no intervalo de confianza; las ventanas de selección se solapan y no son independientes |
| Comparación contra el baseline **por SKU/serie** en las filas de segmento (V25-SEL-020) | **DISEÑADO / NO IMPLEMENTADO**: las filas `series`/`product` previas siguen sin baseline; los segmentos cubren clase, categoría, sucursal, volumen y vida |
| Dimensión «día con/sin promoción» y ablación de `promo_discount` (V25-DEM-033) | **DISEÑADO / NO IMPLEMENTADO** (ver §4) |
| Métricas económicas downstream por segmento (quiebres, capital, margen) y costo de inferencia por segmento | **NO IMPLEMENTADO** en estas filas (el dinero sigue en `overall`/`horizon_total`; los tiempos, en `summary.timings_seconds`) |
| Intervalos y pinball para LightGBM y el mejor estadístico por segmento | **NO IMPLEMENTADO** (solo servido y baseline tienen intervalos calibrados) |
| Precisión, calibración, estabilidad y veredictos por segmento **con datos reales de un cliente** | **BLOQUEADO**: requiere historia real del piloto; todo lo anterior son fórmulas y propiedades demostradas con datos ficticios |
| Aprobar los umbrales de §3.5 | **BLOQUEADO** hasta el piloto («a aprobar en el piloto»; ninguno es universal) |

## 7. Cómo reproducir

```
cd engine
ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<bd_de_pruebas> \
  .venv/bin/python -m pytest -q tests/unit/test_segment_metrics.py tests/unit/test_segment_metrics_parity.py \
  tests/unit/test_forecast_validation_scenarios.py tests/integration/test_segment_metrics_integration.py
```

Archivos: `engine/predictily_engine/demand/segments.py` (segmentos, métricas, comparación, resumen), `backtest/metrics.py` (WAPE con denominador, métrica alternativa, estabilidad),
`demand/evaluation.py` (integra las filas en cada alcance), `pipeline/forecast_run.py` (persistencia), `platform/config.py` (umbrales), `tests/unit/_scenario_builders.py` (datos ficticios).
