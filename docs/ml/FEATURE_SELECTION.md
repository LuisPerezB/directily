# Selección de features y factores (V2.5 §24.7 y §26 T12) tal como está construida

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · Paquetes: W2-C (habilitación por calidad), W2-D (contratos de features y disponibilidad), W6-E (pipeline de factores, control
placebo y ablation). Decisiones: ADR-V25-05, ADR-V25-06, ADR-V25-16 y ADR-V25-25 ([`docs/architecture/adr/V25-ADRS.md`](../architecture/adr/V25-ADRS.md)).

**Qué es este documento.** El resumen de **cómo decide Predictily qué variables entran al modelo de demanda** y con qué evidencia. No repite el detalle: el cableado, la regla de
competencia, los placebos y la herramienta de ablation están en [`docs/intelligence/FACTOR_PIPELINE.md`](../intelligence/FACTOR_PIPELINE.md); el método point-in-time y los casos
T02/T11 en [`POINT_IN_TIME_VALIDATION.md`](POINT_IN_TIME_VALIDATION.md); el contrato de cada feature en [`docs/data/FEATURE_CONTRACTS.md`](../data/FEATURE_CONTRACTS.md); la selección
de **modelos** (estadísticos frente a LightGBM, política heredada y V2.5) en [`FORECAST_SELECTION.md`](FORECAST_SELECTION.md).

**Leyenda de estado.** **IMPLEMENTADO / VERIFICADO** (código + la prueba que lo demuestra, en una suite en verde con evidencia de integración; las pruebas de W6-E pasaron en la
integración de W6: motor 1496 aprobadas y 10 omitidas, `docs/roadmap/evidence/2026-11-03-w6-integration/`) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** · **NO APLICA**.

> **Aviso.** Ninguna feature ni ningún factor tiene **mejora medida sobre datos reales**. Las cifras de §8 salen de empresas **ficticias** con un efecto sembrado o con ruido puro:
> sirven para comprobar el procedimiento, no son un resultado de negocio. No hay fuentes externas conectadas ([`EXTERNAL_SOURCES.md`](../intelligence/EXTERNAL_SOURCES.md)). Todos los
> umbrales son valores iniciales de diseño **a aprobar en el piloto**; ninguno es universal.

## Contenido

- [1. Qué se selecciona y qué no](#1-qué-se-selecciona-y-qué-no)
- [2. Contratos de features: sin contrato no hay feature](#2-contratos-de-features-sin-contrato-no-hay-feature)
- [3. Habilitación por calidad de datos](#3-habilitación-por-calidad-de-datos)
- [4. Brazo con factores frente a LightGBM sin factores](#4-brazo-con-factores-frente-a-lightgbm-sin-factores)
- [5. Control placebo](#5-control-placebo)
- [6. Selección hacia delante](#6-selección-hacia-delante)
- [7. Regla del período final reservado (holdout)](#7-regla-del-período-final-reservado-holdout)
- [8. Herramienta de ablation y reportes existentes](#8-herramienta-de-ablation-y-reportes-existentes)
- [9. Diseñado, bloqueado y no aplica](#9-diseñado-bloqueado-y-no-aplica)
- [10. Pruebas y comandos](#10-pruebas-y-comandos)
- [11. Tabla final por módulo](#11-tabla-final-por-módulo)

## 1. Qué se selecciona y qué no

| Conjunto | Cómo se decide | Estado |
|---|---|---|
| **Features internas del constructor** (`engine/predictily_engine/features/builder.py` y las que deriva MLForecast: rezagos, medias móviles, calendario, precio relativo, promociones…) | catálogo **fijo** con contrato por feature (§2); no hay búsqueda automática de features internas; una feature solo se **apaga** si el gate de calidad lo exige (§3) | IMPLEMENTADO / VERIFICADO |
| **Factores** (indicadores externos o de la empresa en `external_indicator_observations`, campañas en `calendar_events`) | se **activan** por empresa con dos condiciones y luego **compiten**: cada factor contra el LightGBM sin factores y contra sus placebos, con selección hacia delante (§4–§6) | IMPLEMENTADO / VERIFICADO (W6-E); apagado por defecto |
| **Alertas oficiales** (sanitarias, regulatorias, epidemiológicas, de inocuidad) | **no son features**: se listan aparte y se muestran aunque no mejoren el error (regla crítica V2.1, §24.7) | IMPLEMENTADO / VERIFICADO (`backend/tests/Feature/Intelligence/ExternalEventsTest.php::test_informative_alerts_are_listed_apart_and_are_never_predictive_features`) |
| **Escenarios del usuario** (`USER_SCENARIO`) | nunca entran como feature ni a la medición de precisión | IMPLEMENTADO / VERIFICADO (`POINT_IN_TIME_VALIDATION.md` §3, caso 8) |
| **Modelos** (estadísticos, LightGBM global, respaldos) | política heredada (≥ 3 % de WAPE y ⌈2n/3⌉ ventanas) o V2.5 | ver [`FORECAST_SELECTION.md`](FORECAST_SELECTION.md) |
| **Features del Customer Core y de abastecimiento** | conjunto propio del core, as-of por corte (W1); **no** están en el registro de contratos | DISEÑADO / NO IMPLEMENTADO incorporarlas al registro |
| Selección de features por un LLM | prohibido: un LLM puede **proponer** factores con procedencia, nunca elegir el ganador (V2.5 §0, §24.6) | NO APLICA (no existe y no debe existir) |

## 2. Contratos de features: sin contrato no hay feature

Cada feature que produce el motor declara su clase de disponibilidad (`OBSERVED_HISTORICAL`, `STATIC`, `KNOWN_IN_ADVANCE`, `THIRD_PARTY_FORECAST`, `USER_SCENARIO`,
`UNAVAILABLE_FOR_HORIZON`), su fuente, su regla temporal y los **datos requeridos** (vocabulario cerrado). La construcción **falla** si una columna no tiene contrato; una columna
de factor solo entra con su contrato (`factor__<clave>`). Detalle en `FEATURE_CONTRACTS.md` §3.

| Garantía | Prueba (`engine/tests/unit/test_feature_contracts.py`) | Estado |
|---|---|---|
| toda columna que el constructor y MLForecast producen tiene contrato | `test_every_column_the_builder_and_mlforecast_actually_produce_has_a_contract` | IMPLEMENTADO / VERIFICADO |
| una feature nueva sin contrato hace fallar la construcción | `test_building_the_frames_fails_when_a_new_feature_is_added_without_a_contract` | IMPLEMENTADO / VERIFICADO |
| el registro está fijado por versión y huella | `test_the_contract_registry_is_pinned_by_version_and_digest` | IMPLEMENTADO / VERIFICADO; la huella **no** se registra aún en el manifiesto de la corrida (DISEÑADO) |
| las columnas de factor resueltas entran solo con contrato | `test_resolved_factor_columns_enter_the_frames_only_with_a_contract` | IMPLEMENTADO / VERIFICADO |
| un precio futuro confirmado solo reemplaza al precio congelado si se conocía al corte | `test_a_confirmed_future_price_replaces_frozen_price_only_when_it_was_known_at_the_cutoff` | IMPLEMENTADO / VERIFICADO |

## 3. Habilitación por calidad de datos

El gate de calidad de la demanda (W2-C, [`DATA_QUALITY_ENGINE.md`](../data/DATA_QUALITY_ENGINE.md) §6–§7) puede **deshabilitar** una feature opcional cuando faltan los datos que la
sostienen. Hoy el caso es `price_ratio`: con menos del 80 % de las unidades con precio neto positivo (`demand_min_price_coverage = 0,8`, **a aprobar en el piloto**) el gate da
DEGRADE y el constructor **omite** la columna en el entrenamiento, en las ventanas del backtest (también las reconstruidas as-of) y en la predicción.

*IMPLEMENTADO / VERIFICADO:* `engine/tests/unit/test_quality_gates.py::test_a_degrade_gate_omits_the_price_feature_in_training_backtest_windows_and_prediction` y
`::test_omitting_the_column_through_the_builder_is_equivalent_to_neutralizing_it` (predicciones idénticas a neutralizarla); `test_feature_contracts.py::test_degrade_plan_separates_optional_neutral_and_indispensable_features`.

## 4. Brazo con factores frente a LightGBM sin factores

Resumen de [`FACTOR_PIPELINE.md`](../intelligence/FACTOR_PIPELINE.md) §2 y §4.

1. **Activación (dos condiciones).** Un factor cuenta solo si `tenants.settings.cores.factors.enabled = true` (**apagado por defecto**) **y**
   `tenants.settings.factor_activation.<clave>.active = true` (`PUT /api/v1/intelligence/factors/{clave}/activation`). Ninguna basta sola.
   *Verificado:* `test_factor_wiring.py::test_a_factor_needs_both_the_company_switch_and_its_own_activation`.
2. **Entrenado como se sirve.** Las filas del pasado reciben el valor visible en el origen de su bloque (de la longitud del horizonte servido), arrastrado como en la predicción;
   nunca el valor del propio día. Lo desconocido es faltante, nunca cero. *Verificado:* `test_factor_wiring.py::test_training_rows_get_the_value_served_from_their_origin_never_the_one_of_their_own_day`.
3. **Duelo en las ventanas de selección.** Con las mismas ventanas, el LightGBM con el factor debe tener un WAPE agregado estrictamente mejor y al menos `arm_min_improvement` mejor,
   ganar en ⌈n × `arm_windows_won_fraction`⌉ ventanas, con `arm_min_windows` ventanas comparables y un denominador mayor que `demand.wape_min_denominator`.
4. **Activar no cambia lo servido por sí solo**: si ningún factor gana, la ejecución sirve exactamente lo que serviría sin factores.
   *Verificado:* `engine/tests/integration/test_factors_wiring_integration.py::test_activating_factors_that_do_not_win_serves_exactly_what_the_same_company_served_before`;
   apagado = paridad exacta: `test_factor_wiring.py::test_with_the_pipeline_off_the_run_is_exactly_the_one_of_an_engine_without_factors`.

| Parámetro (`factors`, `engine/predictily_engine/platform/config.py`) | Defecto | Rango | Estado de la aprobación |
|---|---|---|---|
| `arm_min_improvement` | 0,03 | 0 – 0,5 | a aprobar en el piloto |
| `arm_windows_won_fraction` | 2/3 | 0,5 – 1 | a aprobar en el piloto |
| `arm_min_windows` | 2 | 1 – 20 | a aprobar en el piloto |
| `arm_placebo_arms` | 5 | 0 – 20 (0 desactiva el control: no recomendado) | a aprobar en el piloto |
| `arm_placebo_seed` | 20261103 | 0 – 2³¹−1 | queda registrada en la evidencia |

Se resuelven núcleo → perfil → empresa con rangos validados; la procedencia queda en `effective_config.factors`.

## 5. Control placebo

Si un factor pasa la regla del duelo, se entrenan K placebos con semilla: **el mismo factor con todas sus fechas desplazadas en círculo** dentro de su rango (período, publicación,
disponibilidad, vigencia y emisión se mueven juntos: se conserva la distribución, el rezago y la proporción de faltantes; se rompe solo la alineación con el objetivo). El factor
real debe superar **al mejor placebo** con el mismo margen y en la misma fracción de ventanas; sin al menos la mitad de los placebos evaluables, no se promueve
(`placebo_unavailable`).

*IMPLEMENTADO / VERIFICADO:* `test_factor_arm_rule.py::test_the_real_arm_must_beat_the_best_placebo_by_the_margin_and_in_enough_windows`,
`::test_a_factor_as_good_as_its_placebo_is_noise_and_is_not_promoted`; `test_factor_served.py::test_the_placebo_shifts_every_date_together_and_keeps_the_lag_the_distribution_and_the_missing_pattern`.

**Qué no es:** los placebos reducen la probabilidad de promover ruido, **no prueban causalidad** y también pueden rechazar un efecto pequeño verdadero (compromiso deliberado).

## 6. Selección hacia delante

Solo los factores que pasaron individualmente (duelo + placebos) entran a la combinación; la combinación se promueve solo si **también** pasa su regla y sus placebos; si no, se
queda el mejor factor individual que sí pasó. Un factor que no pasa la regla base no gasta placebos.

*IMPLEMENTADO / VERIFICADO:* `test_factor_arm_rule.py::test_forward_selection_keeps_only_the_factors_that_pass_individually`,
`::test_a_combination_is_promoted_only_if_every_member_passed_and_the_combination_passes_too`; de punta a punta,
`test_factor_wiring.py::test_the_campaign_wins_on_its_own_and_the_noise_is_left_out_by_forward_selection`.

Esto es la única forma de **control de comparaciones múltiples** implementada hoy: limita las combinaciones a miembros que pasaron solos. El control formal sobre muchas
familias a la vez está **DISEÑADO / NO IMPLEMENTADO** (§9).

## 7. Regla del período final reservado (holdout)

| Regla | Estado | Prueba |
|---|---|---|
| El período final **nunca decide el duelo** (`holdout_used_for_duel` siempre falso): cambiar solo el holdout no cambia la decisión | IMPLEMENTADO / VERIFICADO | `test_factor_arm_rule.py::test_changing_only_the_holdout_never_changes_the_decision` |
| El brazo ganador se entrena en el período final **solo después** de ganar, para medir lo que se sirve; su comparación con el brazo sin factores es **evidencia de reporte** (`summary.factors.holdout_comparison`) y, si es peor, se emite `factor_model_worse_on_holdout` | IMPLEMENTADO / VERIFICADO | `test_factor_arm_rule.py::test_a_factor_that_beats_the_plain_arm_and_its_placebos_wins_and_the_holdout_is_trained_only_afterwards` |
| `holdout_used_for_decision` dice la verdad sobre la selección posterior: la política **heredada** usa todas las ventanas (incluido el período final) y la V2.5 solo las de selección; `governing_policy` lo declara | IMPLEMENTADO / VERIFICADO | `test_factor_arm_rule.py::test_the_holdout_flag_reflects_the_policy_that_governs_the_final_selection` |
| La ablation no entrena ni mide el período final; `holdout_untouched` se **calcula** de los orígenes realmente entrenados | IMPLEMENTADO / VERIFICADO | `test_factor_ablation.py::test_the_final_holdout_is_never_trained_or_measured_to_decide`, `::test_the_holdout_flag_is_computed_from_the_trained_origins_not_a_constant` |
| Confirmación **única** en el período final antes de promover un factor en producción | DISEÑADO / NO IMPLEMENTADO | — |

Consecuencia honesta: con la política heredada gobernando (el valor por defecto), si un factor gana el duelo, la selección posterior frente a los estadísticos sí usa el período
final. Que la política V2.5 gobierne lo servido es una decisión del propietario (`promotion.governing_policy`, [`OWNER_RESPONSIBILITIES.md`](../operations/OWNER_RESPONSIBILITIES.md)).

## 8. Herramienta de ablation y reportes existentes

`engine/scripts/factor_ablation.py` corre el backtest as-of **sin factores, con cada factor y con su combinación** sobre las mismas ventanas de selección, entrenado como se sirve y
con el mismo control placebo y la misma selección hacia delante que el pipeline; entrega diferencias de métricas por horizonte y segmento con los denominadores de W3-A, una
decisión `promote | reject | inconclusive` y filas con la forma de `factor_evaluations`. Es de **solo lectura** (no escribe ninguna tabla) y solo acepta datos ficticios o una base
cuyo nombre contenga «test» (`SET TRANSACTION READ ONLY` en cada transacción).

```bash
# Datos FICTICIOS, sin base de datos
engine/.venv/bin/python engine/scripts/factor_ablation.py --fictional --out <dir>/ablation.json --markdown <dir>/ablation.md
# Copia de pruebas en solo lectura (la URL se lee de la variable de entorno; el nombre de la base debe contener «test»)
ENGINE_TEST_DATABASE_URL=... engine/.venv/bin/python engine/scripts/factor_ablation.py --tenant-id <id> --factors <clave1>,<clave2>
```

**Reportes que existen (todos con datos FICTICIOS; registrados en `FACTOR_PIPELINE.md` §4.1 y §7):**

| Reporte | Resultado registrado | Qué demuestra y qué no |
|---|---|---|
| Ablation de una empresa ficticia (12 campañas anunciadas con 30 días de antelación y un «clima» de ruido; corte 2026-10-26, 3 ventanas, horizonte 90, K = 5) | campaña: `promote` (WAPE 0,488 → 0,454, mejora relativa 0,068, 3/3 ventanas; 4,5 % mejor que el mejor placebo); ruido: `reject` (`no_sufficient_improvement`); ambos: `reject` (`member_not_promoted`) | la herramienta detecta un efecto **sembrado** y descarta el ruido; no es una mejora de negocio |
| Calibración con ruido, V4 (choque de fecha oculto, solo el factor de ruido activo) | **0/330** promociones (cota superior del 95 % ≈ 0,9 %); el diseño anterior daba 2/90 | la tasa de falsas promociones en ese generador; no la tasa en datos reales |
| Calibración V1, V2, V3 (30 pruebas cada una) | 0/30 en cada una (cota superior ≈ 10 % cada una) | evidencia débil por el tamaño de muestra |
| Ablation de 4 factores de ruido con choque oculto (20 empresas × 5 conjuntos) | 0/20 empresas con alguna promoción (80/80 factores individuales rechazados) | la selección hacia delante bloquea combinaciones de ruido |
| Cambio de régimen (escenario e11: la campaña gana en selección y deja de funcionar en el período final) | el factor gana el duelo con 6,8 % de mejora y en el período final es peor (WAPE 0,613 frente a 0,537): se emite `factor_model_worse_on_holdout` | **ninguna garantía ex ante cubre un cambio de régimen posterior**; queda dicho, no se oculta |
| Paridad con el pipeline apagado (commit base `d46f839`, mismo día) | idéntico en `forecast_values` (5 952 filas), `model_evaluations` (811), `recommendations` (36), registro de modelos y la demo completa | apagar o activar sin ganador no cambia lo servido; no prueba que un factor ganador mejore |

**Holdout con datos reales:** no existe. **Ablation con datos reales:** no existe. Ningún porcentaje de mejora de ningún factor en datos reales.

## 9. Diseñado, bloqueado y no aplica

| Tema | Estado | Nota |
|---|---|---|
| Control de comparaciones múltiples sobre muchas familias a la vez (más allá de la selección hacia delante) | **DISEÑADO / NO IMPLEMENTADO** | V2.5 §24.7 lo exige antes de promover factores de forma general |
| Repetición antes de promover (varios cortes o ventanas prospectivas con el mismo resultado) | **DISEÑADO / NO IMPLEMENTADO** | hoy la decisión usa una corrida |
| Reporte por familia completa y confirmación única en el período final | **DISEÑADO / NO IMPLEMENTADO** | — |
| Escritura de `factor_evaluations` por un proceso autorizado | **DISEÑADO / NO IMPLEMENTADO** | la herramienta es de solo lectura a propósito |
| Factores por serie (por producto, sucursal o región) | **DISEÑADO / NO IMPLEMENTADO** | una columna de factor tiene un valor por fecha; eventos con otros alcances se descartan con `scope_unsupported` |
| Feature de antigüedad del factor | **DISEÑADO / NO IMPLEMENTADO** | no se puede servir idéntica en entrenamiento y predicción sin cambiar el contrato |
| Columnas de factor en el paquete firmado de `mlops` | **DISEÑADO / NO IMPLEMENTADO** | su contrato vive en `evaluation_manifest.factors` y `model_registry.artifact_compat.factors` |
| Subir `FEATURE_SET_VERSION` | **PENDIENTE** | alteraría `config_version` de toda ejecución (ver `FEATURE_CONTRACTS.md` §9) |
| Fuentes externas (clima, tendencias, competencia, estadísticas…) y pronósticos de terceros con historial de emisiones | **BLOQUEADO / P2** | sin conectores, licencias ni historial; un pronóstico sin emisiones archivadas sale con `no_issuance_history` y su backtest está prohibido |
| Ablation y holdout con datos de un piloto | **BLOQUEADO** | fuentes del piloto sin registrar; se ejecutaría sobre una **copia** cuyo nombre contenga «test» y los resultados irían solo a `reports/private/` |
| Elección del ganador por un LLM | **NO APLICA** | prohibido por V2.5 §0 |

## 10. Pruebas y comandos

Desde `engine/`, con el entorno virtual, desde un directorio sin `.env` y con `ENGINE_TEST_DATABASE_URL` apuntando a una base de pruebas migrada
(`cd backend && DB_DATABASE=<bd_de_pruebas> php artisan migrate:fresh --force`):

```bash
PYTHONPATH=$PWD .venv/bin/python -m pytest -q tests/unit/test_feature_contracts.py tests/unit/test_quality_gates.py
PYTHONPATH=$PWD .venv/bin/python -m pytest -q tests/unit/test_factor_wiring.py tests/unit/test_factor_served.py tests/unit/test_factor_arm_rule.py tests/unit/test_factor_ablation.py tests/unit/test_db_safety.py
PYTHONPATH=$PWD .venv/bin/python -m pytest -q tests/integration/test_factors_wiring_integration.py tests/integration/test_factors_t11_integration.py
```

Del backend (activación y alertas): `cd backend && DB_DATABASE=<bd_de_pruebas> php artisan test --filter='FactorCatalogTest|FactorActivationTest|ExternalEventsTest'`.

## 11. Tabla final por módulo

| Módulo | Evidencia | Estado de pruebas | Costo | Riesgo | Aprobación |
|---|---|---|---|---|---|
| Contratos de features | `test_feature_contracts.py`; `FEATURE_CONTRACTS.md` | en verde desde la integración de W2 | ninguno | huella de contratos fuera del manifiesto | — |
| Habilitación por calidad | `test_quality_gates.py`; `DATA_QUALITY_ENGINE.md` | en verde desde W2 | ninguno | umbral de cobertura de precio sin validar con datos reales | `demand_min_price_coverage`: propietario en el piloto |
| Brazo con factores, placebos y selección hacia delante | `test_factor_arm_rule.py`, `test_factor_wiring.py`, `test_factor_served.py` | en verde en la integración de W6 | un LightGBM más por factor y ventana; K entrenamientos más por placebo solo si el factor pasa la regla; nada con el pipeline apagado | cambio de régimen tras la selección; pocas ventanas = evidencia débil | parámetros `factors.*` y activación por empresa: propietario |
| Ablation | `test_factor_ablation.py`, `test_factors_wiring_integration.py`; `FACTOR_PIPELINE.md` §7 | en verde en la integración de W6; datos ficticios | solo cómputo (≈ 130 s y 27 entrenamientos en el ejemplo ficticio); sin APIs | sin datos reales; sin control formal de comparaciones múltiples | decisión de activar un factor: propietario con el cliente |
| Alertas informativas | `ExternalEventsTest.php` | en verde desde W2 | ninguno | alertas manuales (sin fuente oficial conectada) | verificación humana de cada evento sensible |
| Fuentes externas | `EXTERNAL_SOURCES.md` | — | costos y licencias por definir | **BLOQUEADO / P2** | licencias y proveedores: propietario |
