# P1 · Demanda — horizontes, protocolo, métricas, casos especiales, huella y reversión

Etapa 2, bloque D de `instrucctions.md` §6 (partes de §6.C e §I). Responsable: paquete P1. Todo lo medido
aquí proviene de **datos sintéticos (ficticios)**; ninguna cifra certifica exactitud comercial y la validación
con datos reales sigue pendiente (E2-20, 🔌).

Código: `engine/predictily_engine/demand/` (nuevo), `pipeline/core.py`, `pipeline/forecast_run.py`,
`backtest/{rolling,metrics}.py`, `intervals/conformal.py`, `selection/selector.py`, `db/{repository,writer}.py`;
backend `app/Services/Analytics/{HorizonSupport,DemandEnvelope}.php`,
`app/Services/Forecasting/DemandRollbackService.php`. Migraciones `2026_02_05_000100/000200`.

## 1. Qué cambia, en una página

| # | Requisito | Resultado |
|---|---|---|
| 1 | Horizontes 7/14/30/60/90 | Dominio, esquemas, filas `granularity='horizon'`, planes (básico 7/14/30; resto todos), validaciones, `ForecastRunService`, programador. **Abstención**: un horizonte H solo es «respaldado» si la historia ≥ 2×H **y** el backtest tiene ventanas ≥ H; si no, `insufficient_data` con motivos y sin intervalo |
| 2 | Manifiesto del protocolo | `forecast_runs.evaluation_manifest` construido de las **filas reales** del backtest; verificable; expuesto en `/accuracy/summary` y `/forecast-runs/{id}` |
| 3 | Métricas | MASE/RMSSE (escala = naive estacional 7 con datos previos a cada origen), pinball P10/P50/P90, amplitud 80/95, tamaños de muestra y exclusiones, **dinero separado de unidades**, error del total de H días; todo en `model_evaluations.metrics` |
| 3b | P50 | Conformal de mediana evaluado con pinball en el holdout; **se publica solo si mejora a «la media como mediana»**. En la demo no mejora ⇒ `null` (honesto) |
| 4 | Modelo servido coherente (E2-19) | Si falla el ganador se sirve el respaldo y **métricas, intervalos, etiquetas y registro** corresponden al servido (`Selection.served`) |
| 5 | Casos especiales | Producto nuevo (mediana de categoría / promedio propio), historia escasa, descontinuado (cero explícito o abstención), apertura/cierre de sucursal (solo cierres declarados), eventos extraordinarios (bandera) — siempre con etiqueta |
| 6 | Cobertura de fuentes | `data_coverage` (ventas): el corte no supera `complete_through`; antes de `complete_from` y después de `complete_through` el dato es ausente; sin registro ⇒ `coverage_undeclared` |
| 7 | Huella y configuración | `data_snapshots` con 13 componentes + `config_version`/`effective_config`; mismo snapshot + config + semilla ⇒ mismos valores |
| 8 | Registro de modelos | `candidate/validated/production/retired/failed` + `role` + `core='demand'`; `POST /models/demand/rollback` con auditoría |
| 9 | Envoltura | `envelope` en `/forecasts/series` y `/forecast-runs/{id}` (aditivo) |
| 10 | Demo recalculada | §7 |

## 2. Contratos

### 2.1 Horizontes y abstención (E2-21)

- Dominio: `SUPPORTED_HORIZONS = (7, 14, 30, 60, 90)` (`demand/horizons.py`). `ExecuteRequest.horizons` rechaza otros valores. Laravel
  autoriza por plan (`config/predictily.php`); **el dominio puede soportar un horizonte que el plan no autoriza** y Laravel solo pide los autorizados.
- `summary.horizon_support[H] = {status, reasons[], history_days, required_history_days, backtest_horizon_days, independent_holdout, evaluated_obs}`:

| status | cuándo |
|---|---|
| `ok` | historia ≥ 2×H, backtest con ventanas ≥ H, observaciones evaluadas > 0 y período final independiente |
| `degraded` | respaldado, pero sin período final independiente (métricas optimistas) |
| `insufficient_data` | `history_lt_2h`, `backtest_horizon_lt_h`, `no_backtest` o `no_evaluated_observations` |

- Cada fila de `forecast_values` lleva `quality_status` (estado del horizonte que contiene a su paso; para filas de **serie**, además `insufficient_data`
  si la historia de *esa* serie < 2×H). Las filas `granularity='horizon'` de un H sin respaldo se escriben **sin intervalo** (no se extrapola).
- `summary.quality_status` (ok | degraded | insufficient_data), `summary.max_supported_horizon`, `summary.horizon_totals[H].status`.
- La API añade `meta.horizon_support` / `horizon_support` (series, productos, categorías, dashboard) con estados extra: `not_computed` (H mayor que el horizonte de la
  ejecución) y `unknown` (ejecución anterior a la etapa 2).
- Tramos de evaluación diaria: `h1-7, h8-14, h15-30, h31-60, h61-90` (antes `h1-7, h8-30, h31-90`). Además `level='horizon_total'` (`level_key='H30'`, `horizon_days=30`):
  error del **total de H días** por serie y ventana, para el seleccionado y el seasonal naive, con dinero.

### 2.2 Manifiesto (E2-06)

`forecast_runs.evaluation_manifest` (`demand-protocol-v1`): `history`, `protocol`, `training.backtest_fits[]` (origen, entrenamiento hasta, rango objetivo, rol
selección/holdout), `training.final_fit`, `selection` (orígenes, rango, fechas objetivo compartidas), `calibration` (fuente de residuos, **observable_at**, si eran observables),
`holdout` (origen y rango, `used_for_*` = false), `label_maturity` (regla, fin de evidencia, cobertura), `overlap` (orígenes superpuestos, fechas compartidas entre ventanas y con el
holdout), `decisions` (qué ventanas decidieron qué), `checks`, `horizon_support`, `p50`, `served`. `demand.manifest.verify()` devuelve las violaciones del protocolo. Sin holdout independiente:
`holdout.present=false` y `decisions.evaluation_windows_that_also_took_decisions` lista las ventanas que evalúan y deciden a la vez.

### 2.3 Métricas (`model_evaluations.metrics`, jsonb; `metrics_version = demand-metrics-v1`)

```json
{"mase": 0.84, "rmsse": 0.76, "scale": "naive_estacional_7_con_datos_de_entrenamiento_previos_a_cada_origen",
 "pinball_p10": 0.37, "pinball_p50": null, "pinball_p50_candidate": 1.04, "pinball_p50_yhat_as_median": 1.03, "pinball_p90": 0.64,
 "interval_width_80": 8.42, "interval_width_95": 14.06, "interval_width_80_rel": 2.01, "share_actual_le_p10": 0.36, "share_actual_le_p90": 0.92,
 "money": {"currency": "USD", "wape": 0.66, "mae": 10.0, "bias": -0.06, "actual_total": 233944.59, "forecast_total": 219938.16, "n_obs": 15458},
 "sample": {"n_obs": 15458, "n_series": 183, "n_windows": 1, "n_scored_intervals": 15458,
            "exclusions": {"scale_undefined": 0, "price_missing": 0, "stockout_days": 1012}}}
```

- **MASE** = media de |e|/MAE_naive7; **RMSSE** = √(media de e²/MSE_naive7). La escala se calcula por serie **solo con datos ≤ origen** (pares t, t−7 sin días censurados; ≥ 14 pares;
  serie constante ⇒ indefinida, la fila se excluye de MASE/RMSSE y se cuenta en `exclusions.scale_undefined`; nunca se vuelve cero).
- **Dinero**: pronóstico = unidades × último precio neto conocido **al origen**; real = unidades × precio neto observado ese día. Se informa aparte de unidades, en la moneda de la empresa.
- **Pinball y amplitud** solo con período final independiente (intervalos calibrados con residuos previos al holdout); en las ventanas de selección quedan `null`.
- **P10/P90 = `lo_80`/`hi_80`**: los límites del intervalo 80 %. A nivel serie-día son los cuantiles 10/90 del error (conformal asimétrico); en niveles agregados el intervalo es simétrico
  sobre |error| y solo se afirma que contiene el 80 %, no 10 %/90 % por cola. Con demanda que tiene ≥ 10 % de ceros, `lo_80 = 0` y `share_actual_le_p10` > 0,10 por construcción.
- **P50** (`forecast_values.p50`, solo nivel serie-día): mediana condicional = pronóstico + mediana del error normalizado × escala. Se **publica solo si**: se evaluó con pinball fuera de muestra,
  hay ≥ 200 observaciones evaluadas y **mejora** la pinball de usar el pronóstico (media) como mediana. Decisión y motivo en `summary.p50`. Si no se publica, `forecast_values.p50`, `model_evaluations.metrics.pinball_p50`
  y la API devuelven `null`. La suma de medianas no es la mediana de la suma: los niveles agregados nunca llevan P50.

### 2.4 Modelo servido (E2-19)

`Selection.served(failed)` reemplaza, por clase, al ganador cuyo entrenamiento final falló por el respaldo. A partir de ahí: la predicción, la calibración de intervalos
(grupo = clase|familia, con los residuos de la familia servida), las evaluaciones (`evaluate(bt, served, eval_served, …)`), la selección persistida (`planned_family`, `fallback_applied`),
las etiquetas `model_family` de cada fila y el registro (`failed` con el error; el respaldo `production` con su rol) describen **lo servido**. `summary.served` y la limitación `fallback_served` lo declaran.
Sin fallo el resultado es idéntico al anterior (los números de la demo no cambian).

### 2.5 Casos especiales (§6.D) — `forecast_values.case_label`, `forecasting_series.lifecycle`, `summary.special_cases`

| Caso | Regla | Salida |
|---|---|---|
| Producto nuevo | < 28 días de historia. Poca venta propia (< 14 días o < 4 con venta) y categoría comparable (≥ 3 series maduras en sucursal+categoría o en la empresa) ⇒ **mediana de la categoría** (promedio diario de 28 días); si no, promedio propio | `new_product_category_median`, `new_product_own_average`, `new_product_own_average_low_evidence`; `model_family` = `category_benchmark` / `window_average`; calidad `insufficient_data` |
| Historia escasa | ≥ 28 días con < 4 días con venta | `sparse_history` (promedio de su historia) |
| Descontinuado | `products.is_active=false` y vendió en el último año ⇒ **cero explícito**; más antiguo ⇒ abstención | `discontinued_zero`; `model_family='declared_zero'`; sin recomendaciones |
| Sin ventas recientes | catálogo activo, > 180 días sin venta ⇒ **abstención** (no se inventa un cero) | sin filas; `summary.special_cases.no_recent_sales` |
| Cierre de sucursal | solo **declarado** (`branches.is_active=false`) ⇒ cero explícito | `branch_closed_declared` |
| Apertura de sucursal | primera venta ≥ 60 días después de la de la empresa y ≤ 90 días atrás; series con < 90 días | `branch_opening` (la serie empieza en su primera venta) |
| Evento extraordinario | `calendar_events.category='exceptional'`, leído con `extensions.data_core.load_calendar_events(as_of=corte)` (P3: última revisión **conocida al corte**; `known_at` nulo ⇒ no se devuelve; se cuenta y se informa en `special_cases.exceptional_events.stats`), alcance país/región/empresa/sucursal/producto/categoría | bandera `exceptional_event` + calidad `degraded`; **no cambia el valor**; desactivable con `exceptional_events_flag` |

Precedencia de etiqueta: cierre/descontinuado > apertura > nuevo > escasa > evento. Los ceros declarados son filas de nivel serie (no alteran la jerarquía).

### 2.6 Cobertura de fuentes (ADR-V2-05)

`data_coverage` (`entity='sales'`), resuelta con la cobertura **efectiva** del core de Datos (`extensions.data_core.effective_coverage`: declaración del usuario > mínimo entre fuentes > ninguna): el corte = mín(corte pedido, `complete_through`
efectivo de la empresa y de las sucursales **activas** con cobertura propia; una sucursal cerrada no lo fija); las ventas anteriores a `complete_from` se descartan (la serie empieza en su primera venta completa). Coherencia con el core de Datos: dentro de la cobertura efectiva, todo día del panel es `observed` o `zero_observed` según `classify_day`; ninguno es `absent` (`test_coverage.py::test_every_zero_day_in_the_panel…`). Sin ninguna fecha declarada: comportamiento de la etapa 1 + limitación `coverage_undeclared`. `summary.coverage.sales` y las limitaciones
`cutoff_limited_by_coverage`, `sales_before_complete_from_ignored` lo registran. Una ejecución con corte limitado por cobertura usa lo conocido al corte para promociones y órdenes (conservador).

### 2.7 Huella y configuración (ADR-V2-04/06)

- `forecast_runs.data_snapshot_id` → `data_snapshots` (core `demand`): `sales, returns, inventory, prices, costs, catalog, promotions` (observadas + as-of con `known_at`), `calendar`
  (feature set, librería de feriados, eventos extraordinarios conocidos), `external_indicators`, `supply_inputs`, `config`, `eligibility`, `coverage`. Misma huella ⇒ mismo resultado con la misma semilla.
- `config_version` = hash de `effective_config = {core: demand, config: <core→perfil→empresa>, provenance: {clave: core|profile:…|tenant}, profile: {code, version}|null, core_config_version, run: {seed, horizon_days, horizons, min_improvement, max_backtest_windows, max_series_lgbm, force_families, feature_set_version}}`;
  la parte `config/provenance/profile/core_config_version` es el registro `platform.config.effective_config_record` de P3, que la ejecución conserva (cambiar después la configuración de la empresa no reescribe ejecuciones ya registradas).
  Claves de empresa en `tenants.settings.cores.demand.*` (`min_improvement`, `max_backtest_windows`, `exceptional_events_flag`, …); una clave desconocida **rechaza** la ejecución (422 `invalid_configuration`, el run queda `failed` con el motivo).
  `ENGINE_MIN_IMPROVEMENT` y `ENGINE_MAX_BACKTEST_WINDOWS` pasan a ser sobrescrituras del operador **solo si se definen** y quedan registradas en `run`.

### 2.8 Registro de modelos y reversión (ADR-V2-07, E2-24)

- Estados: un modelo evaluado y servido ⇒ `production` (`role=primary`); el respaldo en espera o que reemplazó a un ganador fallido ⇒ `production` + `role=fallback`; evaluado y no elegido ⇒ `validated`;
  falló el entrenamiento final ⇒ `failed`; ejecuciones históricas ⇒ `candidate`. Al completar una ejecución de producción los modelos `production` anteriores pasan a `retired`.
  `core='demand'`, `validated_at`, `artifact_compat` (`input_schema`, `feature_set_version`, librerías, tipo de artefacto).
- `GET /models` acepta `status` nuevo o legado (`fallback` = production+fallback, `archived` = retired, `rejected` = validated), `role`, `core`, `run_id`; devuelve `legacy_status`.
- `POST /models/demand/rollback {run_id}` (habilidad `forecast.run`): re-apunta la ejecución **vigente** a una de **producción, completada y anterior**; restaura sus modelos `retired`→`production`, retira los actuales, verifica el SHA-256
  de los artefactos visibles (discrepancia ⇒ 409 `rollback_artifact_mismatch`), todo en una transacción con auditoría `models.demand_rolled_back`. Errores 409: `no_current_run`, `rollback_already_current`,
  `rollback_target_not_production`, `rollback_target_not_previous`, `rollback_target_without_models`, `rollback_target_without_forecasts`. Una ejecución de otra empresa responde 404. No reentrena: los pronósticos conservan su corte.

### 2.9 Envoltura (ADR-V2-03)

`data.envelope` en `GET /forecast-runs/{id}` (y listado) y `GET /forecasts/series`: `ResultEnvelope::fromForecastRun` + `entity_keys`, `currency`, `target_definition` (con P10/P50/P90), `assumptions`, `limitations` (+`limitation_codes`),
`quality_status` (de la ejecución; en series, el del horizonte pedido), `uncertainty_source`, `horizon_support`, `data_snapshot_id`, `config_version`. `status` (job) ≠ `quality_status` (aptitud estadística).

## 3. Migraciones (prefijo `2026_02_05_`, aditivas y reversibles)

| Archivo | Contenido |
|---|---|
| `000100_add_demand_v2_columns` | `forecast_values.p50`, `.quality_status`, `.case_label`; `forecasting_series.lifecycle` |
| `000200_update_plan_horizons` | `plans.limits.horizons`: `[7,30,90]→[7,14,30,60,90]` y `[7,30]→[7,14,30]` solo si conservan el valor por defecto |

El motor omite (con aviso) las columnas de la etapa 2 si la BD aún no tiene la migración, para no romper la escritura de otros paquetes.

## 4. Decisiones

1. **Abstener = marcar, no ocultar.** El número de un horizonte sin respaldo se calcula (la jerarquía y el abastecimiento lo necesitan) pero se marca `insufficient_data`, sin intervalo y con motivo en todas las capas (fila, resumen, API, envoltura).
2. **Todo se mide en el procedimiento servido.** Las evaluaciones se calculan con la selección tal como se sirve; así un respaldo no hereda métricas del ganador.
3. **Escala de MASE/RMSSE solo de entrenamiento y por origen**, para que perturbar el futuro no cambie la métrica de un corte pasado (prueba en `test_scaled_metrics.py`).
4. **P50 es una hipótesis que se evalúa**, no un atributo del modelo. En la demo la mediana conformal no gana (pinball 1,042 vs 1,032 de la media; tampoco por clase de demanda) ⇒ no se publica.
5. **Cierre de sucursal solo declarado.** No existe fecha de cierre en el esquema: se usa `branches.is_active=false` (la API de sucursales la desactiva si tiene ventas). La inactividad por sí sola jamás cierra una sucursal.
6. **Reversión estricta a una ejecución anterior** (el contrato lo pide); avanzar de nuevo se hace generando un pronóstico.
7. **Cobertura**: se limita el *corte* (todas las series comparten fecha), en vez de excluir sucursales rezagadas; una sucursal desactivada no fija el corte.
8. **Deriva**: los tramos nuevos (`h8-14`, …) solo existen en ejecuciones nuevas; `evaluate-actuals` ignora los tramos de ejecuciones antiguas que no existan en el backtest vigente.

## 5. Evidencia (qué prueba qué)

| E2 | Prueba | Evidencia |
|---|---|---|
| E2-01 | suites completas, prueba E2E (`e2e/mvp_acceptance.py`) | §6 |
| E2-05 (demanda) | `test_leakage.py`, `test_pipeline.py::test_future_after_a_backtest_cutoff…`, `test_scaled_metrics.py::test_scale_is_*`/`test_backtest_scales_and_prices_ignore_data_after_each_origin`, `test_demand_v2_integration.py::test_sales_after_the_declared_coverage_do_not_change_the_result…` | perturbar ventas, perfiles, precios y escala posteriores no altera el corte anterior; ventas posteriores a `complete_through` (×7 en BD) producen valores idénticos |
| E2-06 | `test_manifest.py`, `test_pipeline.py::test_holdout_evaluation_is_independent_of_holdout_data` | el manifiesto sale de las filas reales; alterar el período final no cambia selección/calibración/protocolo |
| E2-19 | `test_served_model.py`, `test_demand_v2_integration.py::test_forced_final_failure…` | fallo forzado del LightGBM final: WAPE publicado = el del respaldo por clase (cálculo independiente), intervalos = residuos del respaldo, registro `failed`/`fallback` |
| E2-21 (demanda) | `test_horizons.py`, `test_special_cases.py`, `DemandV2ApiTest` | cinco horizontes, abstención con motivos, evaluación del total de H días, planes y validaciones |
| E2-24 (demanda) | `DemandV2ApiTest::test_rollback_*`, `test_snapshot_config.py`, `test_demand_v2_integration.py` | reversión con auditoría, aislamiento, integridad de artefactos; reproducibilidad (snapshot+config+semilla) |

### Verificación ejecutada (30-oct-2026, 4 vCPU compartidas con los otros paquetes; datos sintéticos)

| Suite / comprobación | Comando | Resultado |
|---|---|---|
| Motor completo (todos los paquetes; BD `predictily_engine_test_p1` migrada) | `cd engine && ENGINE_TEST_DATABASE_URL=postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily_engine_test_p1 OMP_WAIT_POLICY=PASSIVE ENGINE_N_JOBS=1 .venv/bin/python -m pytest -q` | **391 aprobadas**, 0 fallos, 427 s |
| de ellas, nuevas de P1 | `tests/unit/test_{horizons,scaled_metrics,manifest,served_model,special_cases,coverage,snapshot_config}.py` (11+14+5+6+12+8+6 = 62) y `tests/integration/test_demand_v2_integration.py` (13) | 75 nuevas; más ajustes en `test_pipeline.py` y `test_api_integration.py` |
| Backend completo | `cd backend && DB_DATABASE=predictily_test_p1 php artisan test` | **355 pruebas, 353 aprobadas, 2 omitidas** (contrato SQL de P3 sin servidor), 3 018 aserciones |
| de ellas, nuevas de P1 | `tests/Feature/Analytics/DemandV2ApiTest.php` | 17 pruebas |
| Estilo backend | `vendor/bin/pint --test` sobre los archivos de P1 | sin observaciones |
| **E2-01** prueba de aceptación de la etapa 1 sobre el stack real (API + cola `database` + motor + PostgreSQL, BD propia) | `python e2e/mvp_acceptance.py --api http://127.0.0.1:8711/api/v1` | **32 de 32** verificaciones |
| Comprobación de punta a punta de P1 sobre esas empresas (script de la sesión) | horizontes 14 y 60, envoltura, manifiesto, MASE/RMSSE/pinball/dinero, estados del registro, reversión real (SHA-256 verificado), aislamiento A/B | **16 de 16** |

Cifras reales de esa corrida E2E (empresa demo ficticia): MASE 0,850 · RMSSE 0,763 · WAPE 0,493 · WAPE del total de 30 días 0,106; los cinco horizontes `ok`; reversión de la versión 2 a la 1 con `artifacts_verified = true`; `POST /models/demand/rollback` hacia una ejecución de otra empresa responde 404 y hacia una más nueva 409.

## 6. Límites y pendientes (honestos)

- **Datos reales**: todo es sintético; el período final de la demo es un único origen de 90 días (sin intervalo de confianza de las métricas). La evaluación anidada con varios orígenes
  externos (docs/04 §4) sigue pendiente.
- **P50**: el mecanismo existe y se evalúa, pero en la demo no mejora a la media como mediana, ni en conjunto ni por clase, así que no se publica. Con demanda más sesgada podría publicarse; la decisión es automática y auditable (`summary.p50`).
- **Casos especiales sin fecha de cierre**: el esquema no tiene fecha de cierre de sucursal; se usa `branches.is_active=false`. Los eventos extraordinarios solo se marcan: el motor no aprende su efecto.
- **Cobertura** por empresa/sucursal (ventas); no hay cobertura por producto ni se verifica sola. Se reutiliza `extensions.data_core.effective_coverage` de P3 (declaración del usuario > mínimo entre fuentes).
- **Reversión** solo hacia ejecuciones de producción anteriores; el SHA-256 se verifica únicamente si el artefacto es visible desde el backend: el motor guarda `artifact_path` **relativo a `ENGINE_ARTIFACTS_DIR`** y el backend lo resuelve con `engine.artifacts_dir` (la misma variable, mismo volumen compartido en solo lectura); si no lo ve, `artifacts_verification` lo informa con `status` y `reason` (corrección P8; ver `docs/06` §1). El motor no recarga artefactos: los pronósticos de cada ejecución ya están persistidos y la reversión re-apunta la vigente.
- **`evaluate-actuals`** compara el WAPE realizado por tramo con el del backtest vigente; un backtest anterior a la etapa 2 usa otros tramos (`h8-30`, `h31-90`) y esos tramos se omiten en la alerta de deriva hasta la siguiente ejecución.
- **Frontend**: no se tocó; ver §8.
- Ningún cálculo de la demanda usa un LLM; su ausencia no bloquea nada de este paquete.

## 7. Resultados de la demo recalculados

Ver `docs/04-estrategia-modelado.md` §11 («Recalculado en la etapa 2») y `docs/entrenamiento.md`: los números de precisión de la etapa 1 se conservan **exactamente** (WAPE 0,4926 vs 0,6631; sesgo −4,03 %; cobertura 85,39/97,86 %; LightGBM 94 · AutoETS 89) y se añaden MASE 0,840 (naive 1,092), RMSSE 0,764
(1,079), pinball P10 0,371 / P90 0,635, amplitud 80 % 8,42 y 95 % 14,06 u./serie-día, dinero (WAPE 0,662; sesgo −6,0 %) y el error del total de H días (0,218 · 0,156 · 0,105 · 0,090 · 0,076 para 7/14/30/60/90). Reproducir:
`cd engine && OMP_WAIT_POLICY=PASSIVE .venv/bin/python scripts/benchmark_demand_v2.py ferreteria_andina salida.json`.

## 8. Qué necesita la UI (otro paquete)

1. **Selector de horizonte** con 7/14/30/60/90, filtrado por `plan.limits.horizons` (`GET /tenant`); `settings.default_horizon_days` acepta 14 y 60.
2. **Respaldo por horizonte**: leer `meta.horizon_support` (series), `horizon_support` (productos, categorías), `data.horizon_support` (dashboard). `insufficient_data` ⇒ aviso con `reasons[].message` y **no** presentar la cifra como completa (p. ej. estilo punteado, sin banda de intervalo);
   `degraded` ⇒ «métricas optimistas»; `not_computed` ⇒ pedir una ejecución con ese horizonte; `unknown` ⇒ ejecución antigua.
3. **Series**: cada punto trae `quality_status`, `case_label` (insignias: producto nuevo, descontinuado, sucursal cerrada/abierta, evento extraordinario) y `p50` (dibujar la línea solo si no es null). Las series en **abstención** no tienen filas: listar `summary.special_cases.no_recent_sales` / `discontinued.abstained`.
   Listado de productos: columna `case_label`.
4. **Precisión**: mostrar MASE/RMSSE (con la nota «< 1 mejora al naive de entrenamiento»), pinball P10/P90 (P50 «no publicada» si es null), amplitud de intervalos, **dinero separado de unidades** (`money`), `sample.exclusions` y los **tramos nuevos** (`h1-7…h61-90`) más `by_horizon_total` (el error del total de H días es el que importa al comprar).
   Visor del **manifiesto** (`evaluation_manifest`): fechas de entrenamiento, selección, calibración y período final, maduración de etiquetas, nota de orígenes superpuestos.
5. **Modelos**: estados nuevos (`status`, `role`, `core`) con `legacy_status` para compatibilidad; botón «Volver a la versión anterior» (habilidad `forecast.run`) → `POST /models/demand/rollback {run_id}`, mostrando `note` y `artifacts_verified`, y manejando los 409 por `code`.
6. **Trazabilidad**: panel con `envelope` (limitaciones, supuestos, `quality_status`, `config_version`, `data_snapshot_id`); `GET /forecast-runs/{id}` incluye `effective_config` y `evaluation_manifest`.
7. Los valores de `limitations[].code` útiles para avisos: `coverage_undeclared`, `cutoff_limited_by_coverage`, `fallback_served`, `p50_not_published`, `no_independent_holdout`, `horizon_{H}_insufficient_data`.
