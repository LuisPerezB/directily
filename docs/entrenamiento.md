# Entrenamiento y ejecución del motor predictivo

## Cómo se dispara un pronóstico

1. El usuario (o la programación de Laravel) solicita `POST /api/v1/forecast-runs`.
2. Laravel crea `forecast_runs` + `training_jobs` en `queued` y encola `RunForecastPipeline`
   (cola `forecasting`). La petición HTTP del usuario responde 202 de inmediato.
3. El worker llama al motor: `POST {ENGINE_URL}/v1/forecast-runs/{run_id}/execute` con
   `Authorization: Bearer {ENGINE_API_TOKEN}` y `{"tenant_id", "training_job_id", "horizons":[7,14,30,60,90]}` (solo los que autoriza el plan).
4. El motor actualiza `training_jobs.stage/progress` (loading_data → profiling →
   building_features → backtesting → selecting → training → predicting → reconciling →
   recommending → persisting) y escribe todos los resultados en **una sola transacción**.
5. Idempotencia: un run `completed` devuelve su resumen; uno `running` reciente responde 409;
   uno abandonado (> `ENGINE_STALE_RUN_SECONDS`) puede retomarse. Errores → run/job `failed`
   con mensaje (422 `insufficient_data` si no hay datos suficientes).

El entrenamiento **no** se ejecuta por cada factura: solo por acción del usuario, por la
programación (`settings.forecast_schedule`) o al completar una importación inicial.

Runs **históricos** (`run_type = 'historical'`, con `options.cutoff_date`): entrenan solo con
datos hasta el corte; nunca pasan a vigentes ni cambian modelos de producción. Sirven para
mostrar "pronosticado vs real" y medir precisión realizada.

## Ejecución manual (CLI)

```bash
cd engine
.venv/bin/python -m predictily_engine.cli run --run-id 12 --tenant-id 3 --training-job-id 41
.venv/bin/python -m predictily_engine.cli readiness --tenant-id 3     # perfil y calidad de datos
.venv/bin/python -m predictily_engine.cli evaluate --tenant-id 3      # comparar con ventas reales
```
(el `run` debe existir en `forecast_runs`; normalmente lo crea Laravel con
`php artisan predictily:forecast {tenant}`).

## Variables de entorno (`engine/.env`)

| Variable | Defecto | Uso |
|---|---|---|
| `ENGINE_DATABASE_URL` | postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily | BD compartida con Laravel |
| `ENGINE_API_TOKEN` | dev-engine-token-change-me | Igual al del backend; **cambiar en producción** |
| `ENGINE_ARTIFACTS_DIR` | ./storage/artifacts | Artefactos `{tenant_id}/run-{id}/lightgbm_global.joblib` |
| `ENGINE_RANDOM_SEED` | 42 | Semilla (reproducibilidad) |
| `ENGINE_N_JOBS` | 4 | Hilos de LightGBM |
| `ENGINE_MAX_SERIES_LGBM` | 5000 | Límite de series para el modelo global |
| `ENGINE_MIN_IMPROVEMENT` | (vacío) | Sobrescritura del operador de la mejora mínima exigida a modelos complejos. Vacío = configuración `demand.min_improvement` (0,03 por defecto; por empresa en `settings.cores.demand`). Si se define, queda registrada en `effective_config.run` |
| `ENGINE_MAX_BACKTEST_WINDOWS` | (vacío) | Ídem para las ventanas de backtest (`demand.max_backtest_windows`, 3 por defecto) |
| `ENGINE_STALE_RUN_SECONDS` | 1800 | Tiempo para considerar abandonado un run |

## Reproducibilidad y trazabilidad

Cada ejecución registra en `forecast_runs` su **configuración efectiva** (`config_version` = hash de
`effective_config`: core → perfil sectorial → empresa → opciones de la ejecución), su **huella de datos**
(`data_snapshot_id` → `data_snapshots`: ventas, devoluciones, inventario, precios, costos, catálogo,
promociones tal como se conocían al corte, calendario y eventos, insumos de abastecimiento, configuración,
reglas de elegibilidad y cobertura) y el **manifiesto del protocolo de evaluación** (`evaluation_manifest`).
Mismo snapshot + misma configuración + misma semilla ⇒ mismos valores
(`test_pipeline.py::test_reproducible_with_same_seed`, `test_demand_v2_integration.py`).

Cada ejecución registra en `model_registry`: familia, nivel (A–E), estado (`candidate | validated | production |
retired | failed`) y rol (`primary | fallback`), hiperparámetros, versión
del feature set (`fs-2026.10-v2`, definiciones en `forecast_features`), rango y **hash SHA-256
de los datos de entrenamiento**, número de series, semilla, versiones de librerías y, para
LightGBM, el artefacto `joblib` con su SHA-256. Los modelos estadísticos son deterministas y se
reentrenan con la configuración registrada. Con los mismos datos y la misma semilla el
resultado es idéntico (prueba `test_reproducible_with_same_seed`). Cada valor de
`forecast_values` queda ligado a `forecast_run_id` (versión, fecha de generación, corte),
horizonte (`horizon_step`), `model_registry_id` y feature set.

## Tiempos medidos (VPS de pruebas: 4 vCPU, 15 GB RAM, sin GPU)

Empresa demo completa **Ferretería Andina (ficticia)**: 3 sucursales, 62 productos, 183 series
activas, 99 334 líneas de venta (24 meses), inventario diario. Medición del 29-oct-2026 con
`engine/scripts/benchmark_demo.py` vía HTTP (`/execute`), con la máquina sin otras cargas:

| Fase | Segundos |
|---|---|
| Carga de datos desde PostgreSQL | 2,6 |
| Perfilado (corte final; cada ventana se reperfila dentro del backtesting) | 0,4 |
| Backtesting (3 ventanas de selección + período final reservado × 8 modelos, incl. LightGBM) | 20,6 |
| Entrenamiento final + predicción 90 días | 6,0 |
| Reconciliación + intervalos | 0,7 |
| Persistencia y respuesta (27 140 filas de `forecast_values` vía COPY, registro, 491 evaluaciones, recomendaciones) | ≈ 3,5 |
| **Total de punta a punta (HTTP)** | **38,4** |

Run histórico (corte −30 días): 34,8 s. `evaluate-actuals`: 0,7 s; sus 30 días observados dieron
WAPE realizado 0,511, sesgo +0,6 % y cobertura 80 % de 0,843.

En memoria (`docs/validation/2026-10-28/benchmark_in_memory.py` adaptado; sin BD), medidos uno
tras otro en la misma máquina: pipeline **32,6 s** (backtesting 19,3 s) y 40,4 s con la
generación de datos, frente a 26,9 s (backtesting 16,0 s) y 34,5 s del código evaluado el
28-oct. El aumento se debe a la ventana adicional del período final reservado (F6) y al
reperfilado por ventana (F4, < 0,1 s por ventana). Resultados de precisión: ver `docs/04` §11.

Con varios procesos del motor compitiendo por los 4 vCPU (p. ej. suites de pruebas en
paralelo), LightGBM con `n_jobs = 4` puede quedar minutos en espera activa de OpenMP; en ese
caso conviene `OMP_WAIT_POLICY=PASSIVE` o `ENGINE_N_JOBS=1` (un ajuste bajó de > 120 s a 1,1 s).

Observación: en una primera medición sobre una BD con tablas recién cargadas el run tardó
386 s fuera del pipeline, compatible con estadísticas de PostgreSQL desactualizadas (plan con
*nested loop* sobre `sale_items × sales`). El motor ahora ejecuta sus lecturas analíticas con
`SET LOCAL enable_nestloop = off` y la medición repetida en BD vacía dio 1,7 s de carga.
Recomendación: ejecutar `ANALYZE sales, sale_items, inventory_snapshots` tras importaciones
masivas (o dejar que autovacuum lo haga) en producción.

## Demo recalculada con la etapa 2 (antes → después)

Medición en memoria del 29-oct-2026 (`engine/scripts/benchmark_demand_v2.py`; Ferretería Andina **ficticia**, semilla 42,
183 series activas, 99 334 líneas, holdout 2026-07-29 → 2026-10-26, `n_jobs = 2` con CPU compartida):

| | Antes (F1–F7) | Después (P1) |
|---|---|---|
| WAPE diario por serie, seleccionado / Seasonal Naive | 0,4926 / 0,6631 | 0,4926 / 0,6631 (idéntico: protocolo y modelos intactos) |
| WAPE del total diario · sesgo · cobertura 80/95 % | 0,0976 · −4,03 % · 85,39/97,86 % | idénticos |
| MASE · RMSSE (seleccionado / naive estacional) | no medidos | 0,840 / 1,092 · 0,764 / 1,079 |
| Pinball P10 · P90 · amplitud 80/95 % (u. por serie-día) | no medidos | 0,371 · 0,635 · 8,42 / 14,06 |
| P50 | no existía | no publicada (pinball 1,042 vs 1,032 de la media): `null` |
| Dinero: WAPE · sesgo (seleccionado) | no medido | 0,662 · −6,0 % |
| WAPE del total de 7 · 14 · 30 · 60 · 90 días | no medido | 0,218 · 0,156 · 0,105 · 0,090 · 0,076 |
| Pipeline (s) | 64,7 | 66,7 (+3 %) |

Tablas completas y lectura en `docs/04-estrategia-modelado.md` §11. No certifican exactitud comercial.

## Pruebas

```bash
cd backend && DB_DATABASE=predictily_engine_test php artisan migrate:fresh --force && cd ../engine
.venv/bin/python -m pytest -q     # 88 pruebas: 75 unitarias + 13 de integración (~110 s, 29-oct-2026)
```
Las de integración se omiten si la BD de pruebas no está disponible. Cubren: escritura
versionada, aislamiento entre dos empresas, idempotencia, 409 por concurrencia, cambio de run
vigente y archivo de modelos, run histórico + evaluación contra reales, readiness, 422 por datos
insuficientes y autenticación.

## Datos de demostración

```bash
.venv/bin/python -m predictily_engine.cli generate-demo --out ../data/demo --seed 42
```
Genera los datasets **ficticios** de `data/demo/` (ver `{slug}_README.md`). Mismo resultado con
la misma semilla.
