# Pipeline de factores en la demanda: cableado, activación, competencia y ablation (V2.5 §24 · W6-E)

> **Alcance y estado.** Cómo entran los factores externos al modelo de demanda del motor, qué los activa, cómo compite cada factor con el modelo sin ellos y con sus placebos, qué garantiza la
> disponibilidad temporal (as-of) y cómo se mide con la herramienta de ablation. Implementa `docs/architecture/adr/V25-ADRS.md` (ADR-V25-16 y ADR-V25-25) sobre el validador de W2-D
> (`engine/predictily_engine/availability/**`) y la activación por empresa de W2-F (`docs/intelligence/FACTOR_INTELLIGENCE.md` §4). Incorpora la revisión adversarial de W6-E (hallazgos H1, H2, M1–M5 y L1–L4).
>
> **Es P2 en lo funcional y P0 en lo temporal.** El cableado, la activación y la ablation están **IMPLEMENTADOS / VERIFICADOS** con datos **ficticios** y con una base de pruebas. **No hay ninguna
> fuente externa conectada** (`EXTERNAL_SOURCES.md`): solo entra un factor si la empresa cargó indicadores o campañas. **No hay mejora medida de ningún factor sobre datos reales**; los números de
> este documento salen de una empresa ficticia con un efecto sembrado (o de ruido puro) y son solo un ejemplo de formato y de calibración, jamás una promesa de rendimiento.
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO** o **NO APLICA**.

## 1. Cómo entran los factores (de la base al modelo)

```
tenants.settings ──► load_tenant_data ──► load_factor_inputs ──► TenantData.factor_inputs        (pipeline/data.py; lee la base UNA vez, en SAVEPOINTs)
                                                                       │
                          run_pipeline ──► prepare_factor_arm ─────────┘                          (backtest/factor_arm.py; puro, sin base de datos)
                                 │             · FactorPipeline.resolve(..., retroactive=True, served_block_days=horizonte servido)
                                 │               (availability/pipeline.py + served.py + validator.py)
                                 ▼
                      backtest rolling-origin ──► FactorOutcome.compete ──► cada factor vs. LightGBM sin factores y vs. sus placebos
                         (ventanas de selección)        (solo selección)     ──► combinación de los que pasaron ──► selección heredada / V2.5 ──► lo servido
```

| Pieza | Archivo | Qué hace |
|---|---|---|
| Lectura | `engine/predictily_engine/pipeline/data.py` (`load_factor_inputs`, `FactorInputs`) | Con el pipeline **apagado** (o sin factores activos) **no consulta ninguna tabla de factores** y deja `TenantData.factor_inputs = None`. Encendido, lee la definición y las observaciones de cada factor activo (con la precedencia público/privado declarada) dentro de un SAVEPOINT por lectura: un fallo (tabla ausente, definición ambigua, almacenamiento nativo sin lectura de factores) se guarda como excepción y el factor sale con su motivo, no aborta la demanda |
| Valores servidos | `engine/predictily_engine/availability/served.py` | Arma las columnas de factor **tal como se sirven** (§3): bloques de entrenamiento con el origen de cada bloque, antigüedad con tope, estadísticas de vencimiento. Camino vectorizado: una evaluación por origen, no por fecha |
| Resolución | `engine/predictily_engine/backtest/factor_arm.py` (`prepare_factor_arm`) | Resuelve los factores con el horizonte REAL de la ejecución y la regla **retroactiva**; deja listo el armado de constructores de features con factores (heredan la política del constructor sin factores: features deshabilitadas por el gate de calidad, zona horaria). Una definición malformada saca solo a su factor (`invalid_definition`); un fallo inesperado de la resolución nunca detiene la demanda |
| Ventanas | `engine/predictily_engine/backtest/rolling.py` (`asof_windows`) y `factor_arm.py` (`FactorWindows`) | Cada ventana del backtest —incluidas las reconstruidas con lo conocido en su corte (ADR-V25-23)— recibe el constructor con la política de la ejecución (`FeatureBuilder.options()`); `FactorWindows` entrega el constructor CON factores de cada origen |
| Competencia | `FactorOutcome.compete` | Ver §4 |
| Registro | `engine/predictily_engine/pipeline/core.py`, `demand/snapshot.py`, `pipeline/forecast_run.py` | `summary.factors`, `evaluation_manifest.factors`, `effective_config.factors`, la huella de datos y el registro de modelos (§6) |

*IMPLEMENTADO / VERIFICADO:* `engine/tests/unit/test_factor_wiring.py`, `test_factor_served.py`, `test_factor_arm_rule.py` y `engine/tests/integration/test_factors_wiring_integration.py`.

## 2. Activación: dos condiciones, no una

Un factor cuenta en la ejecución de una empresa **solo si** se cumplen las dos:

1. el interruptor del pipeline de la empresa: `tenants.settings.cores.factors.enabled = true` (bloque `factors` de `platform/config.py`; **apagado por defecto**), y
2. la elección de la empresa para ese factor: `tenants.settings.factor_activation.<clave>.active = true` (la escribe el backend con `PUT /api/v1/intelligence/factors/{clave}/activation`; ver `FACTOR_INTELLIGENCE.md` §4).

`factor_definitions.is_active` del catálogo **no interviene** (es el valor «ninguna familia activa» del catálogo público).

| `factors.enabled` | `factor_activation.<clave>.active` | Resultado | Prueba |
|---|---|---|---|
| falso (defecto) | cualquiera | no se consulta nada; ningún factor entra; la ejecución es idéntica a la de un motor sin factores | `test_with_the_pipeline_off_the_run_is_exactly_the_one_of_an_engine_without_factors`; `test_a_switched_off_pipeline_runs_no_query_on_the_factor_tables_even_with_activations_and_data` |
| verdadero | ninguno / falso / malformado | `reason = no_active_factors`; el resumen lo dice en una frase (`message`); ningún factor entra; los valores servidos no cambian | `test_a_factor_needs_both_the_company_switch_and_its_own_activation` |
| verdadero | verdadero, **sin** datos cargados | limitación `factors_inputs_not_loaded`; ningún factor entra | `test_enabled_and_active_but_without_loaded_data_nothing_enters_and_the_run_says_so` |
| verdadero | verdadero, con datos | el factor se resuelve (§3) y **compite** (§4) | `test_the_campaign_wins_on_its_own_and_the_noise_is_left_out_by_forward_selection` |

**Activar no cambia lo servido por sí solo.** Con el pipeline encendido y los factores activados, lo servido cambia únicamente si algún factor **gana la competencia** de §4; si ninguno gana, la
ejecución sirve exactamente lo que serviría sin factores (prueba de paridad con base de datos: `test_activating_factors_that_do_not_win_serves_exactly_what_the_same_company_served_before`).

## 3. Garantía as-of y entrenamiento COMO SE SIRVE (H1 a, M1)

Un valor de factor entra a una feature en el corte `t` **solo si era conocido en `t`**. La regla es la única del motor (`availability/visibility.py`: `disponibilidad < medianoche local de t+1`, W1 / ADR-V25-23)
y el cableado la aplica así:

- **Siempre retroactiva** (`retroactive=True`), también en el corte final de una ejecución vigente: una observación sin fecha de disponibilidad (`unknown_availability`) no demuestra que se conociera y **no entra**. *Verificado:* `test_the_run_resolves_factors_with_the_retroactive_rule_even_when_the_final_cutoff_is_live`.
- **Entrenado exactamente como se sirve.** El diseño original (W2-D) daba a la fila de entrenamiento de la fecha `d` el valor visible el MISMO día `d`: cada fecha tenía un valor propio (una «huella de fecha») con que el modelo
  podía memorizar choques de fecha, mientras que al predecir todas las fechas futuras comparten UN valor arrastrado desde el origen. La revisión adversarial mostró que el ruido puro podía así «ganar» el duelo (2,2 % de las
  pruebas con un choque de fecha oculto; 10 % en la combinación de 4 factores de la ablation). Ahora (`availability/served.py`, modo `served_block_days` de `FactorResolution`):
  - las fechas **futuras** leen el valor visible en el corte (el origen real de la predicción), con su antigüedad `d − referencia`;
  - las fechas del **pasado** se agrupan en bloques de `block_days` días hacia atrás desde el corte (`block_days` = el horizonte que se sirve en el backtest, `plan.horizon`); todas las filas del bloque `k` comparten el
    MISMO origen `corte − (k+1)·block_days` y reciben el valor visible en ese origen, arrastrado: edades 1 … `block_days`, como en un horizonte servido. Dentro de un bloque el valor es constante, igual que en la predicción;
    el número de valores distintos del entrenamiento baja de uno por día a uno por bloque;
  - **nunca** el valor del propio día. *Verificado:* `test_training_rows_get_the_value_served_from_their_origin_never_the_one_of_their_own_day` (incluye el contraste con el modo anterior: más de 100 valores distintos), y la equivalencia del camino
    vectorizado con evaluar cada fecha desde su origen: `test_factor_served.py::test_the_vectorized_path_equals_evaluating_every_date_from_its_origin`.
- **Antigüedad con tope (M1).** Un valor arrastrado cuya antigüedad `d − referencia` supera `max_staleness_days` es **faltante (NaN)**, no un valor viejo presentado como vigente. Por factor se registra la fracción de días vencidos, la
  fracción de faltantes y la antigüedad máxima (en el manifiesto y en `summary.factors.factor_stats`) y se emite la limitación `factors_stale`. *Verificado:* `test_a_value_older_than_the_staleness_limit_is_missing_and_reported_never_presented_as_current`.
  Estrategia de ausencia: un factor sale entero solo si su fuente está vencida **en el origen** (la fuente se cayó) con `disable_feature`/`unavailable`; con `last_valid_with_age` se queda con sus días vencidos faltantes. El arrastre sobre el
  horizonte ya NO descarta una serie diaria fresca (antes la descartaba entera: e15). *Verificado:* `test_a_fresh_daily_series_is_kept_with_its_stale_days_missing_and_a_dead_source_is_dropped_or_kept_by_strategy`.
  No se agrega una feature de antigüedad: no hay forma de servirla idéntica en entrenamiento y predicción sin cambiar el contrato de features (DISEÑADO / NO IMPLEMENTADO).
- **Cada ventana recalcula las columnas con lo visible en SU corte** (`FactorResolution.columns_at(corte)`): una observación publicada desde el corte —o una revisión posterior— no puede verse en esa ventana; un valor futuro
  (campaña, feriado) solo cuenta si se anunció antes del corte. *Verificado:* `test_a_known_in_advance_factor_is_missing_not_zero_when_it_was_announced_after_the_cutoff`, `test_each_window_reads_the_factor_as_known_at_its_own_origin_through_the_as_of_windows` y,
  sobre filas reales, `test_loaded_inputs_never_expose_what_was_published_after_each_cutoff`.
- **Revisión que acorta un evento (M3).** Una revisión visible al corte que acorta o cancela un evento conocido de antemano reemplaza a la anterior en TODAS sus fechas (antes la revisión anterior seguía vigente en las fechas que la nueva ya no cubría).
  *Verificado:* `test_a_later_revision_that_shortens_a_known_in_advance_event_supersedes_the_previous_one_on_every_date`.
- **Anuncio posterior al corte (L3).** En una reconstrucción histórica, un evento anunciado DESPUÉS del corte se ignora (esos días quedan faltantes) sin descartar el factor entero. *Verificado:* `test_an_announcement_after_the_cutoff_is_ignored_in_a_historical_run_without_dropping_the_factor`.
- **Desconocido = NaN, nunca cero.**
- **Quedan fuera, con motivo registrado** (`FactorResolution.dropped` → limitación `factors_dropped`): pronóstico de tercero sin historial de emisiones (`no_issuance_history`), disponibilidad desconocida (`unknown_availability`), serie que solo se conoció después del corte (`after_cutoff`), fuente vencida
  en el origen con `disable_feature` (`stale`), indicadores o calendarios ambiguos entre el público y el privado (`ambiguous_indicator`, `ambiguous_calendar`), almacenamiento ilegible (`storage_unreadable`), definición u observaciones
  malformadas (`invalid_definition`, `invalid_observations`: solo ese factor, nunca la demanda). Un escenario del usuario (`USER_SCENARIO`) **nunca** entra. *Verificado:* `test_unusable_factors_are_dropped_with_their_reason_and_never_reach_the_model`,
  `test_public_and_private_indicators_with_the_same_code_need_a_declared_precedence_and_are_never_mixed`.
- **Eventos de calendario solo de su país y su rubro (L2).** Un evento de alcance `country` aplica a la empresa de ESE país y uno de alcance `sector` a la de ESE rubro; los demás se descartan con `scope_not_applicable`. *Verificado:* `test_calendar_events_of_another_country_or_sector_do_not_apply_to_the_company`.
- **Aislamiento:** el lector filtra por empresa; los factores de otra empresa no existen para ésta. *Verificado:* `test_a_company_never_loads_the_factor_rows_of_another_company`.

## 4. Cómo compite cada factor: contra el modelo sin factores y contra sus placebos (H1 b/c, M2)

El selector del motor tiene **una sola** plaza de modelo complejo (`lightgbm_global`) frente a los estadísticos. Para reducir el riesgo de que activar un factor empeore lo servido, un factor no ocupa esa plaza por decreto: **compite primero
con el LightGBM sin factores** y solo después, ya como `lightgbm_global`, con la misma política de selección de siempre.

1. El backtest corre como siempre (estadísticos y LightGBM **sin** factores).
2. **Cada factor por separado.** Con las **mismas ventanas de selección** se entrena el LightGBM con ese factor (`selection.tuning.lightgbm_backtest_rows`, que reproduce la rama de LightGBM de `run_backtest`). Regla (`factor_arm.duel`; la del LightGBM
   heredado frente al mejor estadístico): su WAPE agregado es **estrictamente** mejor y al menos `arm_min_improvement` mejor, **y** gana en al menos ⌈n × `arm_windows_won_fraction`⌉ ventanas, con `arm_min_windows` ventanas comparables como
   mínimo y un denominador (Σ reales) que supere el umbral de estabilidad del WAPE de W3-A (`demand.wape_min_denominator`). Los días no evaluables (quiebre de stock, fuente ausente, cierre) quedan fuera de ambos brazos.
3. **Control placebo.** Si pasó la regla, se entrenan además `arm_placebo_arms` (K = 5 por defecto) **placebos con semilla**: el mismo factor con TODAS sus fechas desplazadas en círculo dentro de su rango (período, publicación, disponibilidad,
   vigencia y emisión se mueven juntos: se conservan la distribución, el rezago de publicación y la proporción de faltantes; se rompe solo la alineación con el objetivo), repartidos entre el 15 % y el 85 % del rango. El factor real debe superar,
   con el mismo margen (`arm_min_improvement`), al **mejor** de los placebos y ganarle en la misma fracción de ventanas. Sin al menos la mitad de los placebos evaluables, no se promueve (`placebo_unavailable`). La evidencia de cada placebo
   (semilla, desplazamiento, WAPE) queda en `summary.factors.evidence.placebo`. *Verificado:* `test_the_real_arm_must_beat_the_best_placebo_by_the_margin_and_in_enough_windows`, `test_a_factor_as_good_as_its_placebo_is_noise_and_is_not_promoted`.
4. **Selección hacia delante (comparaciones múltiples).** Solo los factores que pasaron individualmente entran. Si pasó más de uno, se evalúa la **combinación** (con su regla y sus placebos); solo se promueve si TAMBIÉN pasa. Si no, se queda el mejor factor
   individual que sí pasó. Un factor que ni siquiera cumple la regla no gasta placebos. *Verificado:* `test_forward_selection_keeps_only_the_factors_that_pass_individually`, `test_a_combination_is_promoted_only_if_every_member_passed_and_the_combination_passes_too`.
5. **El período final reservado (holdout) nunca decide el duelo.** El brazo ganador se entrena en él **solo después** de haber ganado con las ventanas de selección (para que la evaluación final mida lo que se sirve). Su WAPE frente al del brazo sin factores en ese período
   se registra como **evidencia solo de reporte** (`summary.factors.holdout_comparison`) y, si el brazo con factores es peor, se emite la limitación `factor_model_worse_on_holdout`. *Verificado:* `test_a_factor_that_beats_the_plain_arm_and_its_placebos_wins_and_the_holdout_is_trained_only_afterwards`,
   `test_changing_only_the_holdout_never_changes_the_decision`.
6. **Qué dice exactamente `holdout_used_for_decision`.** `holdout_used_for_duel` es siempre falso. Pero la selección POSTERIOR frente a los estadísticos usa la política que gobierna la empresa: la **heredada usa TODAS las ventanas (selección + período
   final)** y la V2.5 solo las de selección; por eso, si un factor ganó y gobierna la heredada, `holdout_used_for_decision` es verdadero y `governing_policy` lo dice. *Verificado:* `test_the_holdout_flag_reflects_the_policy_that_governs_the_final_selection`.
7. Si gana, sus filas ocupan el lugar de las de `lightgbm_global` en el backtest y el resto del pipeline (selección heredada o V2.5, ajuste de hiperparámetros, shadow mode, entrenamiento final) trabaja con el constructor ganador (solo los factores
   seleccionados). **Si no gana ninguno**, el backtest, la selección y todo lo servido son exactamente los de un motor sin factores. `summary.factors.factor_model_served` dice si finalmente se sirve (ganó **y** se sirve LightGBM **y** su entrenamiento final no falló).

**La garantía exacta** (no «activar no puede empeorar lo servido»): un factor llega al modelo servido solo si, en las ventanas de selección, mejora al LightGBM sin factores con el margen mínimo y en suficientes ventanas **y** supera al mejor de sus placebos. Eso
reduce mucho la probabilidad de promover ruido (§4.1 más abajo) pero **no garantiza nada sobre el período final ni sobre el futuro**: por eso se mide y se avisa (punto 5).

Parámetros (bloque `factors` de `platform/config.py`, resueltos core → perfil → empresa, con rangos validados). **Valores iniciales de diseño, a aprobar en el piloto con datos reales; ninguno es universal.**

| Clave | Defecto | Rango | Significado |
|---|---|---|---|
| `arm_min_improvement` | 0,03 | 0 – 0,5 | mejora relativa mínima del WAPE sobre el modelo sin factores y sobre el mejor placebo (misma escala que `demand.min_improvement`) |
| `arm_windows_won_fraction` | 2/3 | 0,5 – 1 | fracción de ventanas de selección que debe ganar (una mayoría como mínimo) |
| `arm_min_windows` | 2 | 1 – 20 | ventanas de selección comparables mínimas (con menos, la evidencia es insuficiente) |
| `arm_placebo_arms` | 5 | 0 – 20 | placebos con semilla por factor y por combinación; **0 desactiva el control (NO recomendado: el ruido puede ganar)** |
| `arm_placebo_seed` | 20261103 | 0 – 2³¹−1 | semilla base de los desplazamientos (queda en la evidencia) |

Razones registradas cuando se mantiene el modelo sin factores: `no_sufficient_improvement`, `not_stable_across_windows`, `insufficient_windows`, `denominator_below_minimum`, `placebo_not_beaten`, `placebo_unavailable`, `factor_arm_incomplete`,
`factor_arm_failed`, `factor_arm_failed_in_holdout_window`, `holdout_window_incomplete`; y `not_evaluated` si no hubo competencia posible (`no_backtest`, `lightgbm_not_used`, `no_lightgbm_rows`, `no_active_factors`, `inputs_not_loaded`, `no_usable_factors`, `resolution_failed`).
*Verificado:* `test_factor_arm_rule.py` (la regla, los placebos y la selección hacia delante, caso por caso).

### 4.1 Calibración con ruido (¿qué tan seguido gana el ruido?)

Se repitieron los experimentos de la revisión (`e5_noise.py` V1–V4 y `e7_ablation_noise.py`) sobre empresas ficticias donde NO existe ningún efecto de los factores evaluados. V4 es el caso difícil: las ventas llevan un choque de fecha oculto (campañas que el modelo no puede ver) y solo
está activo el factor de ruido.

| Experimento (empresas ficticias sin efecto del factor evaluado; «—» = sin dato comparable) | Diseño anterior (revisión) | Con entrenamiento como se sirve + placebos + selección hacia delante |
|---|---|---|
| V1 · empresa y ruido nuevos en cada prueba | — | **0/30** promovidos |
| V2 · mismas ventas, solo cambia el ruido | — | **0/30** |
| V3 · campañas aleatorias sin relación con las ventas | — | **0/30** |
| V4 · choque de fecha **oculto** en las ventas, solo el ruido activo (el caso difícil) | **2/90 = 2,2 %** | **0/330** (cota superior del 95 % ≈ 0,9 %) |
| Ablation, 4 factores de ruido con choque oculto (20 empresas × 5 conjuntos) | **2/20 = 10 %** de las empresas con alguna promoción | **0/20** (80/80 factores individuales rechazados; las 20 combinaciones, `member_not_promoted`) |
| Ablation, 4 factores de ruido sin choque (nula, 20 × 5) | — | **0/20** (1 factor individual llegó al control placebo y no lo superó: `placebo_not_beaten`) |

- **Objetivo de la revisión: ≤ 1 % con choques ocultos. Alcanzado** (0 de 330; la cota superior del 95 % por la «regla de tres» es ≈ 0,9 %), **sin subir `arm_min_improvement`** (se mantiene en 0,03). Con 330 pruebas la tasa verdadera no se conoce con precisión por debajo de ≈ 1 %; con las 30 pruebas de cada V1–V3 la cota superior es mucho más holgada (≈ 10 % cada una), por eso V4 es el experimento que sostiene la afirmación.
- **Qué logra cada pieza.** El entrenamiento como se sirve quita casi todo el ruido que ganaba (de 2/90 a una única prueba entre 330 que supera la regla base, con mejora relativa de 4,7 %, que es azar porque el factor es ruido puro): la regla base sola dejaría pasar 1/330 = 0,3 %. Los placebos
  bloquean esa prueba (`placebo_not_beaten`) y la del conjunto nulo de la ablation. La mayor mejora relativa de ruido que NO alcanzó el margen fue de 2,9 % en V4: el margen de 3 % deja poco espacio libre, otra razón para tratar los umbrales como valores iniciales a aprobar en el piloto.
- **Costo del control:** los placebos solo se entrenan para los factores que ya pasaron la regla base (en V4: 1 de 330 pruebas), así que casi no cuestan cuando no hay efecto; cuando un factor sí pasa pagan K entrenamientos por ventana.
- **Qué NO demuestra:** estas son empresas ficticias con un generador conocido; no miden la tasa de falsas promociones en datos reales, otros tipos de choque (estacionalidades de factor, regímenes), ni la sensibilidad a efectos pequeños reales (el control placebo también puede rechazar un efecto pequeño verdadero: el trade-off es deliberado).
  El escenario de cambio de régimen de la revisión (e11: la campaña gana en la selección y deja de funcionar en el período final) **sigue ocurriendo** —el factor gana el duelo con 6,8 % de mejora y en el período final es peor, WAPE 0,613 frente a 0,537—, y ahora queda **dicho**: se emite `factor_model_worse_on_holdout` y el período final se registra como evidencia (§4 punto 5). Ninguna garantía ex ante cubre un cambio de régimen posterior a la selección.
- **Reproducción:** los guiones de la revisión (`e5_noise.py V1..V4`, `e7_ablation_noise.py`, `e10`, `e11`, `e14`, `e15`) se re-ejecutaron contra el código nuevo sin modificarlos; usan el arnés de la revisión sobre `run_backtest` + `FactorOutcome.compete`. Resultados cualitativos de los demás escenarios de la revisión:
  `e14` (fuga de lo publicado tarde o de lo conocido tras el corte): publicado con 7 días de retraso → no se promueve; publicado al cierre del día `d` → mejora de 3,1 % pero `placebo_not_beaten`; conocido solo tras el corte → `after_cutoff`, el factor sale; control (anunciado 30 días antes) → se promueve con 21 % de mejora. `e15`: una serie diaria fresca ya no se descarta con `disable_feature`. `e10`: la serie vieja (el clima) deja 89 de 90 días del horizonte como faltantes con la limitación `factors_stale`.

## 5. Apagado por defecto: paridad exacta con el motor anterior

Con la configuración por defecto (`factors.enabled` falso) —y también con el interruptor encendido sin ningún factor activado, o con activaciones sin interruptor— la demo ficticia y los E2E sirven **exactamente** lo mismo que antes. Se demuestra de dos maneras:

- **Pruebas** (`test_with_the_pipeline_off_the_run_is_exactly_the_one_of_an_engine_without_factors`): `forecast_values`, evaluaciones, recomendaciones y filas del backtest idénticos (`check_exact=True`), huella de la ejecución (`config_version`), huella de datos
  (`snapshot.hash`) y `training_data_hash` idénticos y sin ninguna clave `factors` en el resumen, el manifiesto ni la configuración efectiva; con el pipeline apagado el brazo con factores ni se entrena.
- **Antes/después contra el commit base** sobre la demo ficticia, con un `git archive` del commit base y el árbol modificado, volcando a archivos y comparando columna por columna:

```bash
mkdir -p /tmp/base && git archive d46f839 engine | tar -x -C /tmp/base
export ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<base de pruebas migrada>
PYTHONPATH=/tmp/base/engine engine/.venv/bin/python engine/tests/tools/factor_off_parity.py /tmp/base/engine /tmp/factor_base.pkl
PYTHONPATH=$PWD/engine      engine/.venv/bin/python engine/tests/tools/factor_off_parity.py $PWD/engine      /tmp/factor_cur.pkl
engine/.venv/bin/python engine/tests/tools/compare_demo_parity.py /tmp/factor_base.pkl /tmp/factor_cur.pkl     # «TODO IDÉNTICO»
# y, para el Customer Core y el resto de la demo: engine/tests/tools/demo_parity.py (mismo procedimiento)
```

`factor_off_parity.py` vuelca `forecast_values`, `model_evaluations`, `recommendations`, el registro de modelos y —en la variante por defecto— la selección, el resumen y el manifiesto (sin tiempos de reloj) de tres empresas
ficticias con los mismos datos: configuración por defecto, interruptor encendido sin activación y activación sin interruptor. Es DESTRUCTIVO (vacía las empresas de la base de pruebas): se niega a correr si la URL no es inequívocamente de pruebas
(analizada con `make_url`, «test» como palabra, sin `dbname`/`host`/`options` en la consulta) y comprueba `current_database()` tras conectar. **Ejecute los dos volcados el MISMO día:** una ejecución vigente depende de «hoy» (`as_of` del Customer Core,
conocimiento de las promociones), de modo que un volcado base de ayer difiere de uno actual de hoy aunque el código sea el mismo.

**Resultado verificado (2026-11-03, commit base `d46f839`, ambos volcados del mismo día, base de pruebas `predictily_engine_test_w6e`):**

| Volcado | Tablas comparadas (filas, columna por columna, `check_exact`) | Resultado |
|---|---|---|
| `factor_off_parity.py` · empresa con la configuración por defecto | `forecast_values` (5 952), `model_evaluations` (811), `recommendations` (36), `forecast_run` (selección, resumen y manifiesto sin tiempos), registro de modelos (8) | idéntico |
| ídem · interruptor encendido **sin** factores activados | las mismas tablas, salvo `forecast_run` | idéntico |
| ídem · factores activados **sin** interruptor | las mismas tablas, salvo `forecast_run` | idéntico |
| `demo_parity.py` · demanda y Customer Core | `forecast_values` (5 952), `customer_predictions` (1 690), `customer_rankings` (1 690), `customer_activity` (139), `customer_reconciliations` (40), registro de modelos (26), `recommendations` (36), versiones de modelo | idéntico |

`compare_demo_parity.py` termina con «TODO IDÉNTICO» en los dos pares. El árbol de `engine/` del commit base `d46f839` es idéntico al del `HEAD` actual (`git diff d46f839 HEAD -- engine` vacío), de modo que el `git archive` del base es también el del `HEAD`.
La paridad prueba el apagado por defecto; **no** prueba (ni pretende probar) que un factor activado y ganador mejore nada: eso lo muestran la ablation (§7) y la calibración con ruido (§4.1) con datos ficticios.

## 6. Qué queda registrado y qué linaje tiene

| Dónde | Contenido |
|---|---|
| `forecast_runs.summary.factors` (y `PipelineResult.factors`) | `decision` (`factor_arm_selected` · `plain_arm_kept` · `not_evaluated`), `reason`, `message` (una frase de qué pasó, también con el pipeline encendido y sin factores activados), `active_factors`, `used_factors` (resueltos), `selected_factors` (los que ganaron), `dropped_factors`, `scenario_only`, `per_factor` y `combination` (veredicto y placebos de cada uno), `factor_model_served`, la regla (`rule`, `rule_version`), la evidencia compacta (WAPE de cada brazo, denominador, ventanas ganadas/necesarias, mejora relativa, placebos), `holdout_used_for_duel`, `holdout_used_for_decision`, `governing_policy`, `holdout_comparison` (solo reporte), `served_block_days`, `factor_stats` (fracción de días vencidos, faltantes y antigüedad máxima) y `data_fingerprint` |
| `forecast_runs.evaluation_manifest.factors` | lo anterior más la evidencia por ventana, el estado de cada factor en el corte final (`resolution.factors`) y el contrato de cada columna `factor__<clave>` |
| `forecast_runs.effective_config.factors` | solo con el pipeline encendido: la configuración de factores, su procedencia y la activación; así `config_version` cambia cuando cambia la configuración (apagado, la huella es la de siempre) |
| **Huella de datos (H2)** `data_snapshots` / `summary.data_snapshot_hash` | con el pipeline encendido el componente `external_indicators` deja de ser `{"enabled": false}` y lleva, por cada factor resuelto, su clave, la **versión de su definición**, la **huella de sus valores visibles** y su estado (activo / seleccionado); cambia con los datos de los factores aunque el factor no se sirva. Apagado, es `{"enabled": false}` y la huella es la de siempre |
| **Modelo servido (H2)** `training_data_hash`, `model_registry.feature_set_version`, `artifact_compat.factors` | solo cuando se SIRVE el modelo con factores: el hash de entrenamiento incluye la huella de los factores seleccionados, `feature_set_version` lleva la marca `+factors:<huella corta>` (≤ 40 caracteres) y `artifact_compat.factors` guarda claves, columnas, versiones de definición, huella y bloque de entrenamiento. Sin modelo con factores servido, todo queda exactamente como siempre |
| `forecast_runs.summary.limitations` | `factors_dropped`, `factors_stale` (días vencidos por factor), `factors_inputs_not_loaded`, `factors_resolution_failed`, `factors_not_evaluated`, `factor_model_not_selected` (con los WAPE de ambos brazos y del mejor placebo), `factor_model_worse_on_holdout`, `factors_config_invalid` |

*Verificado:* `test_the_data_lineage_changes_with_the_factor_data_and_the_served_model_carries_it`, `test_the_lineage_fingerprint_changes_with_the_factor_values_and_only_the_served_model_folds_into_the_hashes`.

## 7. Herramienta de ablation (`engine/scripts/factor_ablation.py`) · P2

Backtest **con y sin cada factor** (y con su combinación) sobre las mismas ventanas de selección, con las diferencias de métricas **por horizonte y segmento** y los denominadores de W3-A, **entrenado como se sirve** y con el **mismo control placebo y la misma
selección hacia delante** que el pipeline.

```bash
# Datos FICTICIOS (sin base de datos): empresa sintética con campañas anunciadas por anticipado (efecto sembrado) y un «clima» de ruido
engine/.venv/bin/python engine/scripts/factor_ablation.py --fictional --out /tmp/ablation.json --markdown /tmp/ablation.md
# Una BASE DE PRUEBAS en solo lectura (el nombre debe contener «test»; la URL se lee de la variable de entorno)
ENGINE_TEST_DATABASE_URL=... engine/.venv/bin/python engine/scripts/factor_ablation.py --tenant-id 12 --factors weather.observed,calendar_events.campana_ficticia
```

- **Solo lectura (M4).** No escribe ninguna tabla: ni `factor_evaluations` ni las de producción. En modo base de datos (`db/safety.py`): URL analizada con `make_url` (se exige `url.database`, el nombre con «test» como palabra, la consulta sin `dbname`/`host`/`service`/`options`),
  `SELECT current_database()` comprobado tras conectar y `SET TRANSACTION READ ONLY` al abrir **cada** transacción (además de `default_transaction_read_only = on`; cambiar el valor por defecto de la sesión no la hace escribible). Las herramientas destructivas
  `factor_off_parity.py` y `demo_parity.py` llevan la misma guarda en línea. Para ensayar con datos de un piloto, restaure una **copia** en una base cuyo nombre contenga «test». *Verificado:* `test_db_safety.py` (todas las evasiones encontradas en la revisión y la paridad de las tres copias),
  `test_the_database_mode_of_the_ablation_is_read_only_per_transaction_and_never_a_production_database`, `test_the_ablation_reads_a_test_database_without_writing_anything`.
- **Sin tocar el período final.** Solo entrena y mide en las ventanas de selección; `holdout_untouched` se **calcula** de los orígenes realmente entrenados (no es una constante). *Verificado:* `test_the_final_holdout_is_never_trained_or_measured_to_decide`,
  `test_the_holdout_flag_is_computed_from_the_trained_origins_not_a_constant`.
- **Métricas con las definiciones de W3-A** (`demand/segments.py`): WAPE = Σ|error| / Σ reales con su denominador y su estado (`ok` · `unstable` · `undefined`); segmentos con pocas observaciones **sin cifras**; dimensiones: clase de demanda, categoría, sucursal, tramo
  de volumen y vida de la serie; horizontes del catálogo que el backtest alcanza (7/14/15/30/60/90). Diferencia = candidato − baseline (negativa = mejora). *Verificado:* `test_metrics_per_horizon_and_segment_carry_the_w3a_denominators_and_states`.
- **Decisión = la misma regla de §4** (`promote` la pasa con sus placebos; `reject` evidencia suficiente de que no mejora, o una combinación con un miembro que no pasó solo —`member_not_promoted`—; `inconclusive` evidencia insuficiente, placebos insuficientes, brazo incompleto, factor no
  evaluable o mejora global con regresión mayor al 10 % en un segmento crítico: `demand_class`, `volume_tier`). Sin evidencia no se promueve (T12). La decisión es **del conjunto de factores** (`decision_scope = factor_set` en cada fila de `evaluation_rows`); cada
  celda trae además su propio veredicto de métrica (`cell_verdict`). *Verificado:* `test_the_ablation_tool_and_the_pipeline_reach_the_same_evidence_for_the_same_factor`.
- **Costo:** solo cómputo (segundos y entrenamientos, incluidos los de los placebos); sin API externas.

**Ejemplo de salida (datos FICTICIOS; el efecto de la campaña lo siembra el generador, por eso se conoce la respuesta).**

Empresa ficticia (12 campañas anunciadas con 30 días de anticipación, un «clima» de ruido), corte 2026-10-26, 3 ventanas de selección, horizonte 90 días, K = 5 placebos, período final reservado intacto. Salida resumida de
`factor_ablation.py --fictional --out … --markdown …` (la corrida completa tardó unos 130 s de cómputo, 27 entrenamientos de LightGBM, 0 llamadas a APIs):

| Conjunto de factores | Decisión | Motivo | WAPE sin factores | WAPE con factores | Mejora relativa | Ventanas ganadas |
|---|---|---|---|---|---|---|
| `calendar_events.campana_ficticia` | `promote` | `improvement_and_windows` | 0,488 | 0,454 | 0,068 | 3/3 |
| `weather.observed` (ruido) | `reject` | `no_sufficient_improvement` | 0,488 | 0,490 | −0,005 | 1/3 |
| ambos | `reject` | `member_not_promoted` (el ruido no pasó solo) | 0,488 | 0,457 | 0,063 | 3/3 |

Control placebo de la campaña (5 desplazamientos de 137 a 492 días del mismo factor): WAPE de los placebos entre 0,476 y 0,491; el mejor, 0,476; el factor real, 0,454 (4,5 % mejor que el mejor placebo, ganando en 3/3 ventanas) → pasa. El «clima» ficticio
se publica a diario pero con `max_staleness_days = 3`: el 99 % de los días del horizonte quedan faltantes (`factors_stale`) y no aporta información.

Diferencia de WAPE por horizonte de la campaña (candidato − baseline, global, con los denominadores de W3-A):

| H (días) | WAPE baseline | WAPE candidato | Δ | Denominador (Σ reales) | Estado |
|---|---|---|---|---|---|
| 7 | 0,501 | 0,453 | −0,049 | 5 094 | ok |
| 14 | 0,542 | 0,487 | −0,055 | 10 669 | ok |
| 30 | 0,499 | 0,463 | −0,036 | 22 313 | ok |
| 60 | 0,489 | 0,455 | −0,034 | 49 497 | ok |
| 90 | 0,488 | 0,454 | −0,033 | 79 197 | ok |

(El JSON trae además los horizontes 15 y los segmentos por clase de demanda, categoría, sucursal, tramo de volumen y vida de la serie, con segmentos de pocas observaciones sin cifras.)

**Estas cifras no son un resultado de negocio:** muestran que la herramienta detecta un efecto sembrado, descarta el ruido y no promueve una combinación cuyo miembro no pasó solo.

## 8. Límites honestos

- **Factores de la empresa, no de la serie.** Una columna de factor tiene un valor por fecha: no distingue productos ni sucursales (un factor regional o por producto es DISEÑADO / NO IMPLEMENTADO; el alcance de un evento de
  calendario por sucursal, producto, categoría o región se descarta con `scope_unsupported`).
- **Un factor observado (clima, tendencias, precios) con una antigüedad máxima corta aporta pocos días de información al horizonte** (el resto es faltante a propósito, §3): solo ayuda en los primeros días o si su límite es largo. Un factor conocido de antemano (campañas,
  feriados) sí cubre el horizonte hasta donde se anunció.
- **Los placebos no son una prueba de causalidad.** Reducen mucho la probabilidad de promover ruido (§4.1), pero con pocas ventanas de selección (el mínimo es 2) la evidencia es débil; el período final se reporta, no decide el duelo.
- **Almacenamientos soportados:** `external_indicator_observations` y `calendar_events`. Los almacenamientos nativos (ventas, precios, promociones, compromisos, inventario) tienen su propia lectura as-of y no pasan por
  este pipeline (un factor con ese almacenamiento sale como `storage_unreadable`). `external_events` no entra como feature: las alertas oficiales verificadas se muestran aparte aunque no mejoren el error.
- **Sin fuentes externas** (`EXTERNAL_SOURCES.md`): clima, tendencias, competencia, estadísticas de exportación, etc. **BLOQUEADO / P2** hasta que existan conectores, licencias e historial de emisiones.
- **Ablation:** el control de comparaciones múltiples sobre muchas familias a la vez (más allá de la selección hacia delante), el reporte por familia completa y la confirmación única en el período final son DISEÑADO / NO IMPLEMENTADO. Los umbrales de §4 son valores iniciales a aprobar en el piloto.
- **Pronósticos de terceros** (p. ej. `weather.forecast`): solo con historial archivado de emisiones; sin él, `no_issuance_history` y backtest prohibido.
- **Carga del backend:** la activación se registra en `tenants.settings`; el backend no consulta ni modifica el interruptor del motor (`FACTOR_INTELLIGENCE.md` §1.3).
- **Dónde NO queda registrado el factor:** las columnas `factor__<clave>` no se agregan a `forecast_features` (catálogo estático de features del builder) ni a los metadatos del paquete del modelo privado (`mlops/bundle.py`); su
  contrato vive en `evaluation_manifest.factors.feature_contracts` y, para el modelo servido, en `model_registry.artifact_compat.factors`. Listar las columnas de factor en el paquete firmado de `mlops` es DISEÑADO / NO IMPLEMENTADO.
- **Lecturas extra:** `load_tenant_data` se usa también en el informe de preparación (`readiness`) y en el entrenamiento privado (`mlops`); con el pipeline encendido también cargan los insumos de los factores activos (unas pocas consultas); apagado no consultan nada.
- **Costo de cómputo:** con el pipeline encendido cada factor es un LightGBM más por ventana de selección, y los que pasan la regla pagan además `arm_placebo_arms` entrenamientos por ventana; el pipeline apagado (por defecto) no paga nada.

## 9. Pruebas y comandos

Desde `engine/`, con el venv y desde un directorio sin `.env` (ver el contexto de W6), con `ENGINE_TEST_DATABASE_URL` apuntando a una base de pruebas migrada:

```bash
PYTHONPATH=$PWD pytest -q tests/unit/test_factor_wiring.py tests/unit/test_factor_served.py tests/unit/test_factor_arm_rule.py tests/unit/test_factor_ablation.py tests/unit/test_db_safety.py tests/unit/test_sector_contracts.py
PYTHONPATH=$PWD pytest -q tests/integration/test_factors_wiring_integration.py tests/integration/test_factors_t11_integration.py
```

| Prueba | Qué demuestra |
|---|---|
| `tests/unit/test_factor_served.py` | bloques de entrenamiento, camino vectorizado ≡ evaluar cada fecha, antigüedad con tope, serie fresca, revisión que acorta (M3), anuncio posterior al corte (L3), placebo desplazado, y los mutantes M10/M17 (la política del constructor viaja a las ventanas as-of y al brazo con factores) |
| `tests/unit/test_factor_wiring.py` | entrenamiento como se sirve, faltante ≠ cero, factores descartados con motivo (incluida la definición malformada), activación (dos condiciones), paridad exacta apagado, selección hacia delante, período final sin decidir, huella de linaje, ventanas as-of reconstruidas, ablation ≡ pipeline |
| `tests/unit/test_factor_arm_rule.py` | la regla y el control placebo caso por caso, `compete` con el período final y los placebos, selección hacia delante, banderas del período final, huella (M11), carga de insumos sin base de datos |
| `tests/unit/test_factor_ablation.py` | efecto sembrado promovido, ruido rechazado, combinación bloqueada por un miembro, holdout intacto y calculado, solo lectura, denominadores W3-A, forma de `factor_evaluations` |
| `tests/unit/test_db_safety.py` | las evasiones de la guarda de base de pruebas y la paridad de las tres copias |
| `tests/integration/test_factors_wiring_integration.py` | lectura real (apagado no consulta, activación, as-of, público/privado, aislamiento, país y rubro de los eventos), ablation de solo lectura por transacción, ejecución de demanda de punta a punta con paridad antes/después |
| `tests/unit/test_sector_contracts.py` | contratos sectoriales preservados y casos T15/T17 `P3_PENDIENTE` (ver `SECTOR_PROFILES.md`) |

**Mutación.** Se aplicaron 12 mutantes (cada uno con UN cambio) y TODOS fueron detectados por las pruebas de arriba: M10 (sin `**features.options()` en `asof_windows`), M11 (huella constante), M17 (el brazo pierde las features deshabilitadas y la zona), M20 (se ignora el modo servido),
M21 (desconocido = 0), M22 (el duelo usa el período final), M23 (el placebo siempre pasa), M24 (`holdout_untouched` constante), M26 (la revisión que acorta no reemplaza a la anterior), M27 (sin el filtro de país y rubro), M28 (sin tope de antigüedad), M29 (sin selección hacia delante).
