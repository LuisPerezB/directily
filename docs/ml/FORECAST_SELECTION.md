# Selección, promoción y shadow del pronóstico de Demanda

Paquete W3-B · V2.5 §8 · ADR-V25-21 · requisitos V25-SEL-025..042 (y 045 en lo construible).
Código: `engine/predictily_engine/selection/` (`selector.py`, `promotion.py`, `shadow.py`, `tuning.py`), el punto de selección de
`pipeline/core.py`, la persistencia en `pipeline/forecast_run.py`, la evaluación prospectiva en `evaluation/actuals.py` y el bloque `promotion`
de `platform/config.py`. Contrato de datos (arquitecto): migración `2026_03_11_000100` (`model_promotion_decisions`, `shadow_forecast_values`).

Los estados de este documento son honestos: **IMPLEMENTADO/VERIFICADO** nombra la prueba que lo demuestra; **DISEÑADO/NO IMPLEMENTADO**,
**BLOQUEADO** y **NO APLICA** dicen por qué. Ningún umbral es universal: todos son valores iniciales **a aprobar en el piloto** con datos reales.

## 1. Estado

| Capacidad | Estado | Evidencia |
|---|---|---|
| Política heredada (`legacy`) parametrizada: mejora mínima y fracción de ventanas, rango validado, versión | IMPLEMENTADO/VERIFICADO | `test_selection_legacy_parity.py`: paridad exacta contra una copia congelada del selector del commit 4eb34f4 |
| Paridad exacta con los valores por defecto sobre los backtests de la demo | IMPLEMENTADO/VERIFICADO | `test_parity_on_the_demo_backtests`; además verificación manual de extremo a extremo (§8) |
| Política V2.5 calculada en paralelo, por alcance, versionada (SHA-256 de sus parámetros) | IMPLEMENTADO/VERIFICADO | `test_promotion_v25.py` (gates, baseline, champion, registro) |
| Gates V2.5: sin fuga, datos válidos, artefactos verificables, estabilidad, segmentos críticos, métricas e intervalos documentados, mejora frente al champion | IMPLEMENTADO/VERIFICADO | un caso que pasa y otro que falla por gate (§3) |
| Si nada supera al baseline se publica el baseline | IMPLEMENTADO/VERIFICADO | `test_if_no_model_beats_the_baseline_the_baseline_is_published` |
| Segmentos críticos del gate = los de las métricas por segmento de W3-A (as-of por corte), con respaldo propio | IMPLEMENTADO/VERIFICADO | `test_the_segment_gate_uses_the_w3a_provider_by_default` |
| Comparación alineada (candidato, champion y baseline sobre las mismas observaciones) y cobertura de TODAS las ventanas de selección | IMPLEMENTADO/VERIFICADO | `test_a_candidate_missing_from_a_bad_window_is_not_promoted`, `test_alignment_keeps_only_observations_both_families_have`, `test_contenders_are_ranked_on_rows_aligned_with_the_baseline_and_full_coverage_wins` |
| Un champion peor que el baseline no se sigue sirviendo; pronósticos no finitos no envenenan el orden | IMPLEMENTADO/VERIFICADO | `test_a_champion_worse_than_the_baseline_is_not_kept_when_the_challenger_fails_a_gate`, `test_non_finite_forecasts_do_not_poison_the_ranking` |
| Cada alcance no informativo tiene exactamente una fila efectiva (invariante verificado) | IMPLEMENTADO/VERIFICADO | `test_a_class_that_only_exists_in_the_final_period_still_has_exactly_one_effective_row_per_scope` |
| Las decisiones registran lo realmente servido tras un fallback del entrenamiento final | IMPLEMENTADO/VERIFICADO | `test_decisions_record_the_family_really_served_after_a_final_training_fallback` |
| El shadow (persistencia y evaluación prospectiva) no puede tumbar lo servido | IMPLEMENTADO/VERIFICADO | `test_a_failing_shadow_persistence_is_isolated_and_the_served_run_still_completes` y 2 más |
| Trazabilidad: huella de la ejecución con la política de promoción, criterio generado, corrida histórica sin champion | IMPLEMENTADO/VERIFICADO | `test_the_promotion_policy_is_part_of_the_run_fingerprint_and_the_criterion_is_generated`, `test_a_historical_run_does_not_take_the_current_run_as_champion` |
| Registro de ambas políticas con `is_effective`, gates con evidencia, tenant y FKs compuestas | IMPLEMENTADO/VERIFICADO | `test_promotion_shadow_integration.py` (PostgreSQL) |
| La heredada gobierna por defecto; un parámetro por empresa deja gobernar a la V2.5 | IMPLEMENTADO/VERIFICADO en el motor | `governing_policy`; `test_the_v25_policy_governs_when_the_company_asks_for_it` |
| Gobierno por empresa configurable desde la API/UI del backend | IMPLEMENTADO / VERIFICADO (W3-D) | `promotion` está en `EffectiveConfigController::CORES` (validación del motor como los demás cores): `backend/tests/Feature/DataCore/EffectiveConfigPromotionTest.php` |
| Aprobación humana separada | DISEÑADO/NO IMPLEMENTADO (W4 · S06) | el gate `human_approval` queda `pending`; `approved_by`/`approved_at` siguen nulos |
| Champion/challenger y shadow mode (challenger por clase, tope de series, sin servirse) | IMPLEMENTADO/VERIFICADO | `test_shadow_and_tuning.py`, `test_shadow_forecasts_are_persisted_for_the_challenger_and_are_not_served` |
| Evaluación prospectiva del shadow con la misma censura de días que la servida | IMPLEMENTADO/VERIFICADO con datos fabricados y con una corrida histórica sintética | `test_shadow_evaluation_applies_the_same_censorship_as_the_served_evaluation` y 2 más |
| Evaluación prospectiva con ventas reales posteriores al corte del cliente piloto | BLOQUEADO | requiere datos reales y autorizados (V25-APQ-013) |
| Optuna opcional y acotado, apagado por defecto, sin tocar el período final | IMPLEMENTADO/VERIFICADO | `test_shadow_and_tuning.py` (cotas, guarda del período final, registro en el manifiesto, corrida real) |
| Optuna como dependencia obligatoria | NO APLICA | se importa de forma perezosa; sin él el modo queda no disponible con mensaje explícito |
| Costo y latencia del shadow (V25-SEL-045) | PARCIAL | `summary.promotion.shadow.elapsed_seconds` (tiempo de la etapa); el pronóstico es por lotes: no hay latencia por petición |
| Modelo de LightGBM del shadow guardado como artefacto firmado | DISEÑADO/NO IMPLEMENTADO | cada ejecución reentrena y firma el servido; promover un challenger lo reentrena |
| Ajuste de hiperparámetros de otras familias (TSB, AutoETS) | DISEÑADO/NO IMPLEMENTADO | solo LightGBM |
| Revisión de la variación temporal de hiperparámetros (V25-SEL-024) | DISEÑADO/NO IMPLEMENTADO | solo hay parada temprana del estudio, no del `n_estimators` del modelo |
| Umbrales con datos reales | BLOQUEADO | todos «a aprobar en el piloto» |

## 2. Política heredada (`legacy`, regla `legacy-selection-v1`)

La regla que ya gobernaba el motor, ahora parametrizada y verificable (`LegacyPolicy` en `selector.py`):

1. Se evalúan todos los candidatos de la clase (siempre el baseline Seasonal Naive). Entre los estadísticos gana el de menor WAPE.
2. LightGBM solo reemplaza al mejor estadístico si su WAPE ≤ WAPE_estadístico × (1 − `min_improvement`) **y** gana en al menos
   ⌈n × `windows_won_fraction`⌉ ventanas.
3. Una clase con menos de 3 series o Σ ventas < 30 usa la decisión agregada. Respaldo simple = el mejor entre Seasonal Naive y promedio móvil.

| Parámetro | Valor por defecto | Rango validado | Dónde |
|---|---|---|---|
| `min_improvement` | 3 % | [0, 0,5] | `demand.min_improvement` (config) y `LegacyPolicy` |
| `windows_won_fraction` | 2/3 | [0,5; 1] | `promotion.legacy_windows_won_fraction` |

Con 3 ventanas de selección y el período final hay 4 ventanas: se exigen **3 de 4** (75 %), no «≥ 2/3»; el manifiesto lo registra exacto en
`evaluation_manifest.promotion.legacy_windows_rule` (R-065). La fracción de ventanas redondea a 9 decimales antes del techo para que el ruido
de coma flotante no suba una ventana (`⌈n·2/3⌉` coincide para todo n < 2000: `test_needed_windows_equals_ceil_two_thirds_for_every_window_count`).

**Paridad.** `tests/unit/legacy_selector_reference.py` es una copia literal del selector del commit 4eb34f4. Con los valores por defecto,
ganadores, respaldo, razones, clases agregadas y las dos tablas de WAPE coinciden con ella en 28 combinaciones de ventanas y de peso de LightGBM (con 3 márgenes cada una), con y sin
columna de clase por ventana, con quiebres, sin LightGBM, en el límite del 3 % (incluido el ruido de punto flotante) y sobre los backtests
reales de la demo (producción con todas las ventanas y evaluación con las de selección). El límite inclusivo `≤` **se conserva bit a bit**:
el riesgo R-063 (un WAPE de 0,194 calculado por suma no promueve a 0,2 × 0,97) sigue presente en la política heredada por la paridad exigida;
la V2.5 compara con tolerancia de 1e-9.

`policy_version` = SHA-256 de `{política, parámetros, constantes de la regla, versión}`. Cambiar cualquier parámetro cambia la huella.

### 2.1 Regla v2: selección con el WAPE del total diario (`legacy-selection-v2`, apagada por defecto)

**Por qué.** El WAPE serie-día está cerca del piso de ruido de los conteos: dos modelos buenos difieren ahí en 1–2 % (por debajo del margen
exigido) aunque en el total del negocio difieran 15–30 %, un modelo plano que no reparte la semana puede ganar por décimas a uno que sí la
reparte, y en series intermitentes premia pronosticar de menos. En la demo Ferretería Andina, en las ventanas de selección, LightGBM era
1,4 % mejor por serie-día (0,495 frente a 0,502: rechazado por el margen del 3 %) y 29 % mejor en el total diario (0,084 frente a 0,118).

**Regla** (`promotion.legacy_selection_metric = total_day`; `selector._decide_by_total`). La estructura es la de la v1; cambia la métrica
que ordena a los candidatos y se agregan dos salvaguardas:

1. Métrica: WAPE del **total diario del alcance** (`selector.total_day_wape`): las series del alcance (todas, o las de una clase) se suman
   por ventana y día y se mide Σ|total real − total pronosticado| / Σ total real.
2. Estadísticos elegibles: WAPE serie-día ≤ el mejor estadístico × (1 + `series_tolerance`) **y** |sesgo| ≤ `bias_tolerance`. Entre ellos
   gana el de menor WAPE del total diario. Si ninguno cumple el sesgo se elige sin esa salvaguarda y queda registrado
   (`evidence.bias_guard_relaxed`); los excluidos se listan en `evidence.statistical_outside_guards`.
3. LightGBM reemplaza al ganador si mejora su WAPE del total diario ≥ `min_improvement`, gana en ⌈n × `windows_won_fraction`⌉ ventanas con
   esa misma métrica **y** cumple las dos salvaguardas (compuerta `guards` de `model_promotion_decisions`).
4. El respaldo simple (Seasonal Naive o promedio móvil) también se ordena con el total diario.

| Parámetro | Valor por defecto | Rango validado | Dónde |
|---|---|---|---|
| `selection_metric` | `series_day` (v1) | `series_day` / `total_day` | `promotion.legacy_selection_metric` |
| `bias_tolerance` | 0,15 | [0; 5] | `promotion.legacy_bias_tolerance` (solo v2) |
| `series_tolerance` | 0,05 | [0; 1] | `promotion.legacy_series_tolerance` (solo v2) |

**Compatibilidad.** Con `series_day` las salvaguardas no intervienen ni forman parte de los parámetros: la huella (`policy_version`) y la
selección son exactamente las de la v1 (`test_default_policy_keeps_the_v1_parameters_and_fingerprint` y la paridad de
`test_selection_legacy_parity.py`). Con `total_day` la política tiene su propia `rule_version` y su propia huella, y las tablas de la
selección llevan además la columna `wape_total`. Las métricas publicadas de la ejecución no cambian de definición.

**Medido en la demo ficticia** (período final reservado, horizonte 90; mismo backtest, solo cambia la regla): Ferretería Andina, total diario
0,094 → 0,079 y serie-día 0,491 → 0,489; Distribuidora Costa, total diario 0,096 → 0,077 y serie-día 0,449 → 0,443. Son datos sintéticos:
prueban el mecanismo, no la precisión de un cliente. Valores iniciales de diseño, a aprobar en el piloto.

## 3. Política de promoción V2.5 (`promotion-v25-1`)

Se calcula siempre, **solo con las ventanas de selección**: el período final reservado se filtra antes de decidir. El gate `no_leakage` lo
verifica sobre las filas realmente recibidas y sobre el manifiesto del protocolo (el mismo `build_manifest`/`verify` que publicará la ejecución).
Con historia corta (sin período final independiente) los gates no pueden certificar nada: un candidato nuevo no se promueve.

Referencia que debe superar el candidato: el **champion** (familia servida por la misma clase en la ejecución de producción vigente anterior,
`forecast_runs.selection`) si se evaluó en este backtest; si no hay ejecución previa, si la corrida es histórica (su corte es anterior a la vigente:
usarla sería mirar el futuro; `summary.promotion.champions_source = none_historical_run`) o si no se evaluó, el **baseline**.

**Comparación alineada.** Candidato, champion y baseline se comparan SIEMPRE sobre las observaciones (serie × ventana × paso) que tienen en común
(`align_pair`); `backtest/rolling.py` omite a LightGBM en una ventana donde no se puede entrenar o falla, y medir a cada familia sobre sus propias
filas haría «ganar» a quien falta en la ventana mala. Mejora, estabilidad, segmentos y cobertura usan solo esas observaciones comunes (la evidencia
trae `aligned`: observaciones comunes, fracción de la referencia cubierta, ventanas faltantes). Los contendientes se ordenan por su WAPE sobre las
filas que comparten con el baseline, y se prefiere al mejor que cubre **todas** las ventanas de selección; si ninguno las cubre, el mejor a secas
queda en `shadow` porque `temporal_stability` falla con `windows_missing` (la ausencia no se cuenta como ventaja). Los pronósticos no finitos
(NaN/inf) se descartan antes, se cuentan en `metrics.invalid_rows_dropped` y nunca envenenan el orden.

**Gates estructurales y gates de medición.** `no_leakage` (sobre las filas y el manifiesto realmente recibidos) y `valid_data` (resultado del gate de
W2-C) son estructurales: no miden al modelo, certifican el proceso y los datos, y son idénticos para todos los candidatos de la ejecución.
`verifiable_artifacts` depende de la familia. `temporal_stability`, `segment_regression`, `metrics_documented` e `improvement` miden al candidato.
`metrics_documented` exige WAPE y cobertura de intervalos medibles y dentro de tolerancia; **MASE/RMSSE se reportan pero NO son condición**: si son
nulos (p. ej. series sin escala in-sample definida) el gate pasa igual y la evidencia lleva `mase: null`.

**Un champion que no supera al baseline no se sigue sirviendo.** Si el candidato no se promueve (`reject` o `shadow`), antes de seguir sirviendo al
champion se lo evalúa frente al baseline con los mismos gates alineados (`metrics.champion_check`); si un gate blando MIDE que no cumple (o falla
uno blando sin que un gate duro impida certificarlo) se publica el baseline, con la decisión `publish_baseline` y el motivo explícito. Solo un fallo
de gate duro (nada que medir en su contra) conserva al champion.

| Gate | Exige | Duro | Umbral por defecto y justificación (a aprobar en el piloto) |
|---|---|---|---|
| `no_leakage` | Manifiesto del protocolo sin violaciones; ni las filas ni el ajuste de hiperparámetros usaron el período final; período final independiente presente | sí | sin umbral. Sin período final: `not_evaluable` ⇒ no se promueve |
| `valid_data` | Gate de calidad de W2-C ≠ BLOCK | sí | PASS/WARN/DEGRADE pasan; BLOCK ya detiene la ejecución, aquí queda la evidencia |
| `verifiable_artifacts` | Candidato estadístico: reajuste determinista (sin artefacto, `not_applicable`). LightGBM: clave de firma Ed25519 configurada (ADR-V25-20) | sí | sin umbral; sin clave el artefacto quedaría sin firmar y el serving lo rechaza |
| `temporal_stability` | El candidato cubre TODAS las ventanas de selección (si falta alguna: `fail` con `windows_missing`) y no pierde frente a la referencia en más de `v25_max_consecutive_losing_windows` ventanas consecutivas, con ≥ `v25_min_selection_windows` ventanas comparables | no | 1 y 2: perder dos ventanas seguidas indica cambio de régimen y no ruido puntual; con una ventana no hay estabilidad medible |
| `segment_regression` | Ningún segmento crítico empeora su WAPE más de `v25_segment_max_regression` frente a la referencia; segmentos con menos de `v25_segment_min_actual` unidades son `insufficient_data` (ni aprueban ni rechazan) | no | 10 % y 30 unidades: tolerancia al ruido de muestra de segmentos pequeños; 30 es el mínimo de ventas de la clase en la regla heredada |
| `metrics_documented` | WAPE (con denominador), MASE/RMSSE y cobertura de intervalos 80/95 medidos; cobertura dentro de `v25_coverage_tolerance` del nominal (dejando una ventana fuera) | no | ±0,10: con pocas ventanas la cobertura es ruidosa; lo medido queda en la evidencia aunque pase |
| `improvement` | Mejora el WAPE de la referencia en ≥ `v25_min_relative_improvement`, **o** beneficio operacional: el WAPE en dinero (unidades × precio) mejora ≥ `v25_operational_min_money_improvement` sin empeorar las unidades | no | 3 % (igual que la heredada, comparable) y 5 % (el WAPE en dinero lo dominan pocos productos caros: más ruidoso) |
| `human_approval` | Aprobación humana separada (W4 · S06) | solo con `v25_approval_blocks_serving` | siempre `pending`: no hay flujo de aprobación todavía |

**Decisiones** (una fila por política y alcance; lo servido por la política va siempre en `metrics.served_family`):

| Decisión | Significa |
|---|---|
| `promote` | el candidato cumple los gates y reemplaza al champion (o al baseline) |
| `keep_champion` | el champion sigue siendo el mejor y vuelve a cumplir los gates (o un gate duro impide certificar otro cambio) |
| `publish_baseline` | ningún modelo supera al baseline, o el champion ya no cumple los gates frente al baseline (aunque el candidato tampoco se promueva: el motivo lo dice) |
| `shadow` | el candidato no se promueve (gate blando fallido o aprobación pendiente que bloquea) pero queda como challenger observado; sigue sirviéndose el champion si éste supera al baseline, y si no el baseline |
| `reject` | un gate duro impide considerar al candidato; sigue sirviéndose el champion (si supera al baseline) o el baseline |

**Segmentos críticos.** El pipeline conecta el gate al proveedor de W3-A, `demand.segments.make_provider(bt, panel, windows, cat, config)`
(categoría, sucursal, clase de demanda, tramo de volumen y vida de la serie, calculados **as-of** en el corte de cada ventana: los mismos segmentos
que se miden en `model_evaluations`); la evidencia del gate dice qué proveedor se usó (`evidence.provider`). Si las métricas por segmento están
apagadas (`demand.segment_metrics_enabled = false`) o no se puede construir, rige `default_segments` (clase de demanda, tramo de volumen
top 20 % / resto con las unidades del propio período evaluado, y sucursal; solo columnas del backtest). `run_pipeline(segment_provider=...)`
acepta un proveedor explícito. Interfaz (`promotion.SegmentProvider`): `Callable[[DataFrame de filas del backtest], DataFrame con el mismo índice
y una columna por dimensión]`. Por defecto las dimensiones críticas son `demand_class` y `volume_tier`; `promotion.v25_critical_segments` admite
también `category`, `branch`, `series_age`/`series_life` con el proveedor de W3-A. Una dimensión pedida y no disponible se declara en
`evidence.dimensions_unavailable`; nunca se ignora en silencio. Probado en `test_the_segment_gate_uses_the_w3a_provider_by_default` (el gate
recalculado con el proveedor de W3-A da la misma evidencia y difiere de `default_segments`) y
`test_segment_provider_is_the_integration_point_for_w3a_segments`.

**Clases con pocas series (R-071).** Heredan la decisión agregada solo si su familia es candidata de la clase; si no, la V2.5 publica el baseline
(la heredada conserva su comportamiento por paridad). Una clase que solo existe en el período final reservado (la heredada, con todas las ventanas, la
ve; la V2.5 no) recibe igualmente su fila V2.5 «sin evidencia en las ventanas de selección» y rige la decisión agregada: cada alcance no informativo tiene
exactamente una fila por política y exactamente una efectiva. Es un invariante verificado sobre la lista final (`assert_one_effective_per_scope`,
`PromotionInvariantError`), no una consecuencia de la construcción.

## 4. Qué gobierna lo servido

`promotion.governing_policy` (`legacy` por defecto): precedencia core → perfil sectorial → `tenants.settings.cores.promotion` (validado con rango;
un valor inválido rechaza la ejecución con 422 `invalid_configuration`, no se aplica). La política que no gobierna se registra igual
(«sombra de decisión»).

- Con `legacy`: `selection` y `eval_selection` son exactamente las del selector anterior (producción con todas las ventanas; evaluación del
  período final con las de selección). La demo y los E2E no cambian lo servido.
- Con `v25`: la selección de producción y la de evaluación del período final coinciden y salen de las decisiones V2.5 (solo ventanas de
  selección): nada contamina la evaluación. El manifiesto lo registra en `decisions.model_selection_production`.

**Registro** (`model_promotion_decisions`, una fila por política y alcance): `scope_key` = `global`, `class:<clase>` o `horizon:<H>`;
`policy_version`; `candidate_family`/`champion_family` y sus `*_model_registry_id` (el candidato es el registro de esta ejecución; el champion, el de
la ejecución vigente anterior; FKs compuestas por empresa); `decision`; `is_effective` (la política que gobernó; **los alcances `horizon:<H>`
son informativos: la familia servida no varía por horizonte y nunca son efectivos**); `gates` con la evidencia de cada gate; `metrics` (WAPE por
familia, ventanas usadas, `served_family`); `reason` en español. `forecast_runs.summary.promotion` y `evaluation_manifest.promotion` citan ambas
políticas (huellas, parámetros, ventanas, desacuerdos, ajuste Optuna, shadow). `approved_by`/`approved_at` los completa W4.

- `champion_model_registry_id` solo referencia un champion REAL (lo servido por la vigente anterior, `metrics.champion_source = previous_run`); con el
  baseline como referencia queda nulo (en una corrida histórica el registro de la vigente sería posterior al corte).
- **Lo realmente servido.** Si el entrenamiento final de la familia elegida falla y se sirve el respaldo (`Selection.served`), las decisiones efectivas
  afectadas se reconcilian: `served_family` = el respaldo, `metrics.fallback_applied = true`, `metrics.planned_family`, `metrics.final_training_error`, y
  `summary.promotion.fallback_applied` las enumera.
- **Trazabilidad.** La huella de la ejecución (`effective_config.promotion`, y por tanto `config_version`) incluye la política que gobierna, su versión
  y la huella del bloque `promotion`. El criterio del manifiesto (`evaluation_manifest.selection.criterion`) se genera de la política: «LightGBM solo si
  mejora ≥ 3% y gana en al menos ⌈n × 0.6667⌉ ventanas (con 4 ventanas: 3)», no la frase fija «≥ 2/3». Efecto en la demo por defecto: **`config_version`
  cambia** (la huella gana la clave `promotion`); ninguna prueba ni E2E fija un hash literal (solo comparan que `config_version` coincida con la huella
  de `effective_config`); el texto del criterio también cambia. Lo servido no cambia.
- **Aislamiento.** El registro de decisiones y el de shadow se escriben cada uno dentro de su punto de guardado (`begin_nested`): si fallan, la ejecución
  se publica igual y queda la limitación `promotion_decisions_persist_failed` / `shadow_persist_failed`. La evaluación prospectiva (`evaluate-actuals`)
  también va aislada: su fallo deja `shadow_evaluation_failed` en `shadow_prospective.limitations` sin perder la evaluación de lo servido ni la deriva.

`approval_blocks_serving` (apagado): cuando W4 implemente la aprobación, un candidato sin aprobar quedará en `shadow` y no se servirá.

## 5. Champion/challenger y shadow mode

- **Challenger**: por clase de demanda, el mejor candidato **no servido** (menor WAPE en las ventanas de selección; el período final no lo elige:
  `test_the_final_holdout_does_not_pick_the_challenger`). Uno por alcance.
- **Costo acotado**: tope de series por alcance (`shadow_max_series`, las de mayor volumen reciente, determinista); estadísticos baratos; si el
  servido ya entrenó LightGBM se reutiliza (exacto); si no, `shadow_train_ml_challenger` entrena un LightGBM SOLO con las series acotadas (costo
  proporcional al tope, no al tamaño de la empresa). Es una **aproximación** del modelo que se serviría (menos series de entrenamiento) y queda anotada
  (`shadow_ml_capped_training`); si esas series no bastan para entrenarlo, se omite con `shadow_ml_not_trainable`. Sin `shadow_train_ml_challenger` no
  hay shadow de LightGBM salvo que el servido lo use. Se ejecuta **al final** del pipeline: apagado el shadow, todo lo publicado es idéntico (`test_shadow_never_changes_what_is_served`).
- **Persistencia**: `shadow_forecast_values` (nivel `series`, pasos 1..horizonte, `lo_80`/`hi_80` calibrados con los residuos del propio
  challenger). No entra a `forecast_values`, recomendaciones, alertas ni intervalos servidos.
- **Evaluación prospectiva** (`evaluate-actuals`, `evaluate_shadow_prospective`): tras el corte compara el shadow con lo realizado usando la **misma**
  clasificación y censura de días que la servida (`excluded_series_days`: fuente ausente, cierre; más quiebres de stock): esos días no son ceros,
  no entran a WAPE, sesgo ni cobertura y se cuentan por clase. Escribe `model_evaluations` con `evaluation_type = 'shadow_prospective'`, nivel
  `overall` y por tramo de horizonte (`horizon`), con WAPE, MAE, RMSE, sesgo, cobertura 80 %, exclusiones y la comparación con el WAPE del servido
  sobre las mismas series y días no censurados. Idempotente por ejecución.
- **No cubierto**: calibración de probabilidades y ranking económico prospectivos (V25-SEL-042: W5), drift del servido (V25-SEL-043: sin cambios en
  este paquete), costo/latencia por inferencia (V25-SEL-045: el pronóstico es por lotes).

## 6. Optuna (opcional, acotado, apagado)

`promotion.optuna_enabled = false` por defecto. Optuna **no es una dependencia declarada** del motor (hoy llega de forma transitiva con
`mlforecast`; en el entorno de desarrollo es la 5.0.0). Si no está instalado el modo queda no disponible: limitación `optuna_unavailable` con el
mensaje explícito y la ejecución sigue con los hiperparámetros por defecto (`test_unavailable_mode_is_reported_and_leaves_the_run_unchanged`).

- Cotas: `optuna_n_trials`, `optuna_timeout_seconds`, semilla (`TPESampler`), parada temprana (`optuna_early_stopping_patience` pruebas seguidas
  sin mejorar) y tope de CPU (`optuna_max_cpu` hilos de LightGBM, también en el rebacktest posterior al ajuste; Optuna en serie). La **primera prueba es la configuración por defecto**.
- Sin fuga: el objetivo (WAPE ponderado por unidades, días no evaluables excluidos) se mide solo en las ventanas de selección, cuyo fin es ≤ el
  origen del período final; `assert_selection_windows_only` lo verifica al empezar y en cada prueba (`HoldoutLeakError` aborta el estudio).
- Solo se **aplica** si mejora ≥ 1 % (relativo) el WAPE de los hiperparámetros por defecto (menos es ruido). Entonces el backtest de LightGBM se
  rehace con ellos en todas las ventanas (el período final solo evalúa) y el entrenamiento final los usa, de modo que métricas, selección e
  intervalos corresponden al modelo servido. `lightgbm_backtest_rows` reproduce `run_backtest` (`test_lightgbm_backtest_rows_reproduce_the_pipeline_backtest`).
- Registro: `evaluation_manifest.promotion.tuning` (versión de Optuna, semilla, pruebas con parámetros y valores, orígenes usados, origen del
  período final, `holdout_used = false`, parada, CPU, tiempo). `holdout_used_for_hyperparameters` se **deriva** de ese registro, no es una constante.
- No medido: el costo del ajuste en empresas grandes (cada prueba = una pasada de LightGBM por ventana de selección).

## 7. Configuración (bloque `promotion`)

| Clave | Defecto | Rango | Nota |
|---|---|---|---|
| `governing_policy` | `legacy` | `legacy`/`v25` | por empresa |
| `legacy_windows_won_fraction` | 2/3 | [0,5; 1] | `demand.min_improvement` fija el margen de la heredada |
| `legacy_selection_metric` | `series_day` | `series_day`/`total_day` | `total_day` = regla v2 (§2.1), por empresa |
| `legacy_bias_tolerance` / `legacy_series_tolerance` | 0,15 / 0,05 | [0; 5] / [0; 1] | salvaguardas de la regla v2 |
| `v25_min_selection_windows` / `v25_max_consecutive_losing_windows` | 2 / 1 | [1; 20] / [0; 10] | estabilidad |
| `v25_min_relative_improvement` / `v25_operational_min_money_improvement` | 0,03 / 0,05 | [0; 0,5] | mejora |
| `v25_segment_max_regression` / `v25_segment_min_actual` | 0,10 / 30 | [0; 1] / ≥ 0 | segmentos |
| `v25_critical_segments` | `demand_class`, `volume_tier` | — | dimensiones del proveedor |
| `v25_coverage_tolerance` | 0,10 | [0; 0,5] | calibración |
| `v25_approval_blocks_serving` | falso | — | W4 · S06 |
| `shadow_enabled` / `shadow_max_series` / `shadow_train_ml_challenger` | verdadero / 100 / verdadero | [1; 5000] | costo del shadow |
| `optuna_enabled` / `n_trials` / `timeout_seconds` / `seed` / `early_stopping_patience` / `max_cpu` | falso / 20 / 120 / 42 / 8 / 2 | acotados | §6 |

## 8. Verificación y costo

- Pruebas nuevas: `tests/unit/test_selection_legacy_parity.py`, `test_promotion_v25.py`, `test_shadow_and_tuning.py` y
  `tests/integration/test_promotion_shadow_integration.py` (PostgreSQL, base `predictily_engine_test_w3b`).
- Verificación manual de extremo a extremo (no automatizada, repetida tras la revisión adversarial): la misma demo (ferretería, 12 productos, semilla
  42) ejecutada con el árbol del commit 4eb34f4 y con este árbol dio `forecast_values` (5952 filas), backtest completo (103680), recomendaciones,
  selección servida, razones y las 283 filas de evaluación **idénticas** con la configuración por defecto, salvo las claves aditivas que W3-A agrega a
  `metrics` (`wape_status`, `denominator`, `wape_unstable`). El manifiesto gana la clave `promotion` (y la de W3-A), el texto del criterio generado de la
  política y la huella `config_version` cambia (ver §4).
- En esa demo sintética la V2.5 coincide con la heredada en 2 de 4 alcances; donde difiere, promueve LightGBM (la heredada eligió auto_ets o el
  promedio móvil). Es una observación sobre datos sintéticos, no una evidencia de calidad.
- **Costo de la etapa de selección** (medido con un backtest SINTÉTICO, esta máquina, sin otras cargas controladas): la heredada (dos llamadas, como
  antes) tarda 2,8 s con 648 mil filas y 7,3 s con 2,16 millones (1000 series × 4 ventanas × 90 pasos × 6 familias); ambas políticas con sus alcances
  informativos por horizonte suman 8,7 s y 23,3 s. El extra de la V2.5 es ≈ 6–7 µs por fila (≈ 16 s con 2,16 millones; la mitad es el cálculo de los
  alcances `horizon:<H>`, informativos). Escala lineal con las filas: **NO medido con empresas grandes reales** (p. ej. 5000 series ⇒ del orden de un
  minuto extra, estimación por extrapolación). El shadow de la demo toma ≈ 2 s (`summary.promotion.shadow.elapsed_seconds`); el costo del ajuste Optuna
  en empresas grandes tampoco está medido.

## 9. Pendientes y notas

- **Backend (W3-D)**: `promotion` es configurable por empresa en la configuración efectiva (`EffectiveConfigController::CORES`; `factors` sigue
  fuera). Las decisiones y el shadow se exponen en `GET /api/v1/models/promotion-decisions` y `/models/shadow-evaluations`
  (`docs/06-contratos-integracion.md` §3.9.1); sus tablas están clasificadas en el exportador LOPDP (`TenantDataExporterW3Test`).
- **Aprobación humana** (W4 · S06): completar `approved_by`/`approved_at` y activar `v25_approval_blocks_serving`.
- Decisiones `horizon:<H>`: informativas; si en el futuro se sirviera un modelo distinto por horizonte habría que hacerlas efectivas.
- `demand.max_backtest_windows` sigue sin validar en la configuración (R-064, parte fuera de este paquete).
- Los umbrales de los gates y del ajuste deben aprobarse en el piloto con ventanas reales; hasta entonces la V2.5 es una sombra de decisión.
