# Entrenamiento y ejecución del motor predictivo

## Cómo se dispara un pronóstico

1. El usuario (o la programación de Laravel) solicita `POST /api/v1/forecast-runs`.
2. Laravel crea `forecast_runs` + `training_jobs` en `queued` y encola `RunForecastPipeline`
   (cola `forecasting`). La petición HTTP del usuario responde 202 de inmediato.
3. El worker llama al motor: `POST {ENGINE_URL}/v1/forecast-runs/{run_id}/execute` con
   `Authorization: Bearer {ENGINE_API_TOKEN}` y `{"tenant_id", "training_job_id", "horizons":[7,30,90]}`.
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
| `ENGINE_MIN_IMPROVEMENT` | 0.03 | Mejora mínima exigida a modelos complejos |
| `ENGINE_MAX_BACKTEST_WINDOWS` | 3 | Ventanas de backtest |
| `ENGINE_STALE_RUN_SECONDS` | 1800 | Tiempo para considerar abandonado un run |

## Reproducibilidad y trazabilidad

Cada ejecución registra en `model_registry`: familia, nivel (A–E), hiperparámetros, versión
del feature set (`fs-2026.10-v1`, definiciones en `forecast_features`), rango y **hash SHA-256
de los datos de entrenamiento**, número de series, semilla, versiones de librerías y, para
LightGBM, el artefacto `joblib` con su SHA-256. Los modelos estadísticos son deterministas y se
reentrenan con la configuración registrada. Con los mismos datos y la misma semilla el
resultado es idéntico (prueba `test_reproducible_with_same_seed`). Cada valor de
`forecast_values` queda ligado a `forecast_run_id` (versión, fecha de generación, corte),
horizonte (`horizon_step`), `model_registry_id` y feature set.

## Tiempos medidos (VPS de pruebas: 4 vCPU, 15 GB RAM, sin GPU)

Empresa demo completa **Ferretería Andina (ficticia)**: 3 sucursales, 62 productos, 183 series
activas, 99 334 líneas de venta (24 meses), inventario diario. Medición con
`engine/scripts/benchmark_demo.py` vía HTTP (`/execute`):

| Fase | Segundos |
|---|---|
| Carga de datos desde PostgreSQL | 1,7 |
| Perfilado | 0,6 |
| Backtesting (3 ventanas × 8 modelos, incl. LightGBM) | 15,5 |
| Entrenamiento final + predicción 90 días | 5,6 |
| Reconciliación + intervalos | 0,6 |
| Persistencia (27 140 filas de `forecast_values` vía COPY + registro, evaluaciones, 183 recomendaciones) | 0,8 |
| **Total de punta a punta (HTTP)** | **28,0** |

Run histórico (corte −30 días): 26,8 s. `evaluate-actuals`: 0,5 s. Distribuidora Costa (80
series, 15 meses): ~11 s en memoria. Resultados de precisión: ver `docs/04` §11.

Observación: en una primera medición sobre una BD con tablas recién cargadas el run tardó
386 s fuera del pipeline, compatible con estadísticas de PostgreSQL desactualizadas (plan con
*nested loop* sobre `sale_items × sales`). El motor ahora ejecuta sus lecturas analíticas con
`SET LOCAL enable_nestloop = off` y la medición repetida en BD vacía dio 1,7 s de carga.
Recomendación: ejecutar `ANALYZE sales, sale_items, inventory_snapshots` tras importaciones
masivas (o dejar que autovacuum lo haga) en producción.

## Pruebas

```bash
cd backend && DB_DATABASE=predictily_engine_test php artisan migrate:fresh --force && cd ../engine
.venv/bin/python -m pytest -q     # 43 pruebas: 34 unitarias + 9 de integración (~70 s)
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
