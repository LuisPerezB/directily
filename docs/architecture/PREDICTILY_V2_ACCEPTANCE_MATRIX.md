# Predictily V2 · Matriz de aceptación (requisito → código → prueba → evidencia → estado)

Fuente de los casos: `instrucctions.md` §6.J. Estados: ✅ comprobado (prueba automatizada ejecutada) ·
🧪 comprobado solo con datos sintéticos · 🟡 parcial · ⏳ pendiente · 🔌 requiere acceso externo.
"Comprobado" nunca significa validado comercialmente: la validación con datos reales se registra aparte
(columna *Datos reales*).

| ID | Caso | Paquete | Código | Prueba | Evidencia | Estado | Datos reales |
|---|---|---|---|---|---|---|---|
| E2-01 | Compatibilidad etapa 1 | todos | migraciones aditivas `2026_02_0x`; contratos etapa 1 intactos (cambios aditivos) | suites completas + `e2e/mvp_acceptance.py` | P1: E2E 32/32 en BD propia; integración final §Ejecución | 🧪 | — |
| E2-02 | Recepción tardía | PR #1 | `inventory/recommendations.py` | `test_inventory_validation.py` | `docs/validation/2026-10-29/probe-results-after-fixes.json` | ✅ | — |
| E2-03 | Horizonte físico | PR #1 | `inventory/recommendations.py`, `pipeline/core.py` | `test_inventory_validation.py` | ídem | ✅ | — |
| E2-04 | Incertidumbre ausente | PR #1 | `inventory/recommendations.py` | `test_inventory_validation.py` | ídem | ✅ | — |
| E2-05 | Temporalidad (ventas, perfiles, promociones, costos, revisiones) | PR #1 + P1/P2/P3/P4 | `profiling/series.profile_at`, `features/builder` (promociones as-of), `customer/features`, `customer/commitments`, `costs/` (known_at), `platform/coverage` | `test_leakage.py`, `test_promotions_as_of.py`, `test_customer_dataset.py`, `test_costs_forecast.py`, `CanonicalDataTest`, `CommitmentsImportTest` | perturbar datos posteriores al corte no altera entradas/predicciones del corte | 🧪 | — |
| E2-06 | Protocolo independiente (manifiesto) | P1 + P2 | `pipeline/core.py` (manifiesto + `verify()`), `customer/protocol.py` | `test_manifest.py`, `test_customer_models.py`, `DemandV2ApiTest` | `forecast_runs.evaluation_manifest`, `core_runs.evaluation_manifest`; `/accuracy/summary` | 🧪 | — |
| E2-07 | Plan Básico en ejecución programada | PR #1 | `ScheduleForecastsCommand`, `ForecastRunService` | `PlanHorizonSchedulingTest` | — | ✅ | — |
| E2-08 | Recompra: suma de compras y etiquetas censuradas | P2 | `customer/dataset.py` | `test_customer_dataset.py` | varias compras en (corte, corte+H] se suman; inmaduras/censuradas excluidas, no negativas | 🧪 | — |
| E2-09 | Compradores anónimos y nuevos | P2 | `customer/dataset.py`, `customers.is_generic` | `test_customer_dataset.py`, `CustomerResultsApiTest` | genéricos excluidos; nuevos con respaldo identificado | 🧪 | — |
| E2-10 | Calibración | P2 | `customer/calibration.py`, `customer/protocol.py` | `test_customer_models.py` (generador de verdad conocida) | Brier/log loss/curva con n y fechas; calibración separada del ajuste; `P2-demo-ferreteria-andina.json` | 🧪 | — |
| E2-11 | Reconciliación demanda ↔ comprador | P2 | `customer/reconcile.py` | `test_customer_commitments.py`, `test_customer_integration.py` | residual explícito; nunca se suman ambas vistas; unidades coherentes | 🧪 | — |
| E2-12 | Inventario: tránsito, parciales, fechas desconocidas, reservas | P4 (+PR #1) | `supply/balance.py`, `inventory/recommendations.py` | `test_supply_balance.py`, `test_inventory_validation.py`, `test_inventory_repository.py` | sin doble conteo tránsito/órdenes; política explícita de fechas | 🧪 | — |
| E2-13 | Planificador: casos de solución conocida | P4 | `supply/planner.py` (MILP HiGHS) | `test_supply_planner.py`, `test_supply_inputs.py` | casos a mano, oráculo por enumeración (25), 12 aleatorios sin violaciones; infactibilidad con causa | 🧪 | — |
| E2-14 | Producción genérica | P4 | `supply/planner.py` (recetas, rendimiento, tiempo, recurso) | `test_supply_planner.py` | sin recetas ⇒ `not_configured` | 🧪 | — |
| E2-15 | Costos y escenarios | P4 | `costs/`, `scenarios/` | `test_costs_forecast.py`, `test_scenarios_evaluator.py` | referencia vs método en holdout; entradas observadas/pronosticadas/supuestas | 🧪 | — |
| E2-16 | Causalidad | P4 | `causal/` | `test_causal_contract.py` | sin soporte ⇒ `insufficient_evidence`; simulación disponible | 🧪 | — |
| E2-17 | Aislamiento (datos, features, modelos, cachés, resultados, jobs, extensiones) | todos | scope fail-closed, FKs compuestas, repositorios con `tenant_id` | `DataCoreIsolationTest`, `test_customer_integration.py`, `test_supply_integration.py`, `test_demand_v2_integration.py`, `TenantIsolationTest`, pruebas por endpoint | empresa B no lee ni contamina A | 🧪 | — |
| E2-18 | Reintentos y actualización sin venta nueva | P2 (+P1) | `customer/predict.py`, jobs idempotentes | `test_customer_integration.py`, `CustomerJobsAndSchedulingTest` | `predict` diario recalcula recencia; reintento no duplica | 🧪 | — |
| E2-19 | Fallo del ganador | P1 | `selection/selector.py` (`Selection.served`), calibración por familia servida | `test_pipeline.py`/`test_special_cases.py` (fallo forzado) | métricas, intervalos y registro del respaldo servido | 🧪 | — |
| E2-20 | Operación real | P4 (simulación) | `supply/simulation.py` | `test_supply_simulation.py` | simulación en demo ficticia (`docs/architecture/v2/evidence/p4-simulation-*.json`) | 🔌 | pendiente de acceso autorizado (Soyoda/Resgasa) |
| E2-21 | Horizontes del core | P1 + P2 | demanda 7/14/30/60/90 con abstención; compra 7/30/90 | `test_horizons.py`, `DemandV2ApiTest`, `PlanHorizonSchedulingTest`, `test_customer_models.py` | `insufficient_data` explícito si no hay evidencia | 🧪 | — |
| E2-22 | Salidas Customer (monto, frecuencia, ranking, inactividad) | P2 | `customer/outputs.py`, `cust-ranking-v1` | `test_customer_models.py`, `CustomerResultsApiTest` | definiciones y referencias; monto como supuesto; ranking por evidencia | 🧪 | — |
| E2-23 | Compromisos comerciales sin doble conteo | P2 + P3 | `CommitmentAsOf` (PHP) y `customer/commitments.py`: `COALESCE(known_at, ingestión)` | `CommitmentsImportTest`, `test_customer_commitments.py` | cancelaciones y fechas respetan el corte; comprometido no se re-suma | 🧪 | — |
| E2-24 | Registro, reversión y autonomía sin LLM | P1 + P2 + P5b | estados candidate/validated/production/retired/failed + role; `POST /models/{demand,customer}/rollback` | `DemandV2ApiTest`, `CustomerModelRollbackTest`, pruebas de P5b | reversión con SHA-256 verificado; cores sin proveedor LLM | 🟡 | — |
| E2-25 | Conector SQL solo lectura | P3 | `Connectors/Sql/*` (`SqlQueryGuard`, READ ONLY) | `SqlSourceSyncTest` | PostgreSQL de punta a punta; MySQL/SQL Server `implemented_untested` | 🧪 | ERP real pendiente |
| E2-26 | Proveedores y fechas | P4 | `supply/offers.py`, `planner.py` | `test_supply_offers.py` | oferta tardía no oculta faltantes; unidades/monedas explícitas | 🧪 | — |

## Capacidades por nivel de validación

Se completa al cierre: implementadas · comprobadas solo con sintéticos · validadas con datos reales ·
dependencias externas pendientes.
