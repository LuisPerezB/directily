# Predictily V2 · Análisis de brechas y plan de la etapa 2

Fecha: 2026-10-29. Base: `main` @ `6425692` (incluye el PR #1, correcciones F1–F7).
Instrucción vigente: `instrucctions.md` §6 (el prompt V2 histórico es antecedente; la separación
**core común primero, personalización por rubro/empresa aparte** gobierna el alcance).

Leyenda de estado: ✅ implementado y probado · 🟡 parcial · ⛔ ausente · 🔌 requiere acceso/credencial externa.

---

## 1. Arquitectura encontrada (se conserva)

| Capa | Tecnología | Responsabilidad actual |
|---|---|---|
| API pública y esquema | Laravel 13 / PHP 8.3, PostgreSQL 16 (48 → 49 tablas) | Auth Sanctum, multi-tenant (scope fail-closed + FKs compuestas), importación CSV/XLSX/REST, catálogos, analítica, simulador, exportaciones, auditoría, planes |
| Orquestación | Redis + Jobs Laravel (`imports`, `forecasting`) | Importación asíncrona, ejecución del motor, evaluación diaria |
| Cómputo | Python 3.12 / FastAPI (`engine/`) | Perfilado, características, backtesting, selección, intervalos, reconciliación, inventario, evaluación vs reales |
| UI | Vue 3 + TS | 13 pantallas, i18n es, roles |

Contratos vigentes: `docs/06-contratos-integracion.md`, `docs/openapi/*.yaml`. Pruebas al inicio de la
etapa: motor 88, backend 169, frontend 69, E2E 32/32 (ver `docs/validation/2026-10-29/CORRECCIONES.md`).

## 2. Estado frente a la etapa 2

### Bloque 1 · Estabilización

| Req. | Estado | Evidencia |
|---|---|---|
| F1–F7 + regresiones | ✅ | PR #1; `probe_after_fixes.py` 6/6; `PlanHorizonSchedulingTest` |
| Política horizonte interno vs comercial | ✅ | Interno hasta 180 días para abastecimiento; persistido solo el horizonte autorizado |

### Bloque 2 · Demanda y evaluación (core existente)

| Requisito | Estado | Brecha |
|---|---|---|
| Estadísticos + LightGBM/MLForecast, selección por clase con mejora mínima 3 % | ✅ | — |
| Holdout final, selección/calibración separadas (F6) | ✅ | Falta **manifiesto** explícito de fechas (entrenamiento, selección, calibración, prueba, maduración) por ejecución (E2-06) |
| Horizontes 7/14/30/60/90 | 🟡 | Solo 7/30/90 en motor, config y planes |
| Métricas MASE/RMSSE, pinball (P10/P50/P90), amplitud de intervalos, desglose dinero vs unidades | ⛔ | WAPE/MAE/RMSE/sesgo/cobertura sí |
| P10/P50/P90 | ⛔ | Hay intervalos 80/95 conformales; no hay P50 evaluada (yhat no es mediana) |
| Modelo servido = métricas/intervalos servidos (E2-19) | 🟡 | El respaldo se registra; la calibración usa filas del ganador aunque se sirva el respaldo |
| Productos nuevos/descontinuados, aperturas/cierres, eventos extraordinarios | 🟡 | Series cortas usan promedio; no hay política de abstención/descontinuado ni fallback por categoría |
| Cobertura de fuente: ceros solo dentro de período completo | ⛔ | Se rellenan ceros hasta el corte sin conocer completitud de la fuente |
| Huella de datos completa (precios, promociones, externos, config, elegibilidad) | 🟡 | `training_data_hash` cubre solo ventas/quiebres |

### Bloque 3 · Comportamiento de compra (Customer Core)

| Requisito | Estado |
|---|---|
| Dataset de cortes comprador×producto, etiquetas maduras por H | ⛔ |
| `purchase_probability`, `quantity_if_purchase`, `expected_quantity` a 7/30/90 | ⛔ |
| Baselines (frecuencia/recencia), logística, LGBMClassifier/Regressor, calibración temporal | ⛔ |
| Brier, log loss, curvas de calibración, PR-AUC, precision@k/recall@k, MAE/sesgo cantidad e importe | ⛔ |
| Monto esperado, frecuencia, ranking de productos, inactividad | ⛔ |
| Compradores anónimos/nuevos; consumidor final genérico | 🟡 | El importador marca `customer_type`; el motor agrega y pierde la dimensión |
| Pedidos confirmados / contratos sin doble conteo; reconciliación con demanda | ⛔ |

Datos demo disponibles: 640 compradores identificados por empresa con ~25–31 documentos cada uno
(sintéticos). Suficiente para construir y verificar; no para validar comercialmente.

### Bloque 4 · Decisiones comunes

| Requisito | Estado | Brecha |
|---|---|---|
| Balance fechado (stock, reservas, recepciones, órdenes sin fecha) | ✅ | F1–F3; falta recepciones parciales con fecha, cancelaciones explícitas, mínimos/múltiplos |
| Planificador con restricciones (presupuesto/período, capacidad proveedor, lotes, almacenamiento) | ⛔ | Solo comparación presupuesto vs compra sugerida |
| Comparación de proveedores normalizada (unidades, moneda, costos de adquisición) | ⛔ |
| Transformación genérica insumo→producto (rendimiento, tiempo, capacidad) | ⛔ |
| Pronóstico de costos con referencia simple | ⛔ | Existe `purchase_cost_history` |
| Evaluador de escenarios (entradas observadas/pronosticadas/supuestas) | 🟡 | Simulador determinista de supuestos |
| Contrato causal con `insufficient_evidence` | ⛔ | El simulador ya separa supuesto vs predicción |
| Simulación histórica de operación (faltantes, sobrestock, capital) | ⛔ |

### Bloque 5 · Plataforma, integración y trazabilidad

| Requisito | Estado | Brecha |
|---|---|---|
| Conectores SQL solo lectura (PostgreSQL, SQL Server, MySQL/MariaDB) con mapeos autorizados | ⛔ | ODBC/Contífico declarados `planned`. Entorno: PostgreSQL disponible; sin servidores MySQL/SQL Server |
| Tres fechas: evento, disponibilidad en fuente, ingestión | 🟡 | Promociones (F5) e indicadores externos sí; ventas/costos/inventario solo evento + ingestión |
| Completitud por fuente (`complete_through`) | ⛔ |
| Envoltura común de resultados (§C) | ⛔ | Campos dispersos en `forecast_runs`/`summary` |
| Estados de modelo candidate/validated/production/retired/failed + reversión | 🟡 | Hoy `candidate/production/fallback/archived/rejected`, sin reversión |
| Separación entrenamiento / predicción | 🟡 | Demanda entrena y predice en un paso (aceptable: reentrenar es barato); nuevo core requiere separación |
| Configuración con precedencia core → perfil sectorial → empresa, registrada por ejecución | ⛔ | `tenants.settings` sin versión ni perfiles |
| Puntos de extensión (conectores, calendarios, variables, restricciones, transformaciones) | 🟡 | Registro de conectores en backend; sin contrato en el motor |
| Calendarios con alcance y estado de conocimiento en BD | 🟡 | Feriados/eventos EC en código del motor; sin eventos por empresa/sucursal/producto |
| Capa conversacional opcional desacoplada | ⛔ |
| Matriz requisito→código→prueba→evidencia | ⛔ |

Problemas de seguridad/integridad detectados en la auditoría: ninguno nuevo de severidad alta. Se
mantiene como endurecimiento pendiente RLS de PostgreSQL. Las nuevas tablas deben seguir el patrón de
FKs compuestas `(tenant_id, x_id)` y el scope fail-closed.

## 3. Decisiones de arquitectura de la etapa 2

**ADR-V2-01 · Cores como módulos de los servicios existentes.** No se crean servicios nuevos. Motor:
paquetes `customer/`, `supply/` (inventario existente + `planning/`), `costs/`, `scenarios/`,
`causal/`, `platform/` (envoltura, huellas, configuración, extensiones). Backend: servicios bajo
`app/Services/{Customer,Supply,DataCore,Platform}` con rutas propias.

**ADR-V2-02 · Ejecuciones.** La demanda conserva `forecast_runs` (compatibilidad con etapa 1). Los
nuevos cores usan una tabla común `core_runs` (`core` ∈ customer | supply | cost | scenario) con los
campos de la envoltura. `training_jobs.core_run_id` enlaza el trabajo. Ambas tablas registran
`config_version`, `effective_config` y `data_snapshot_id`.

**ADR-V2-03 · Envoltura común de resultados.** Toda respuesta de resultados (API pública y motor)
incluye: `tenant_id`, `run_id`, `core`, `as_of`, `horizon_start`, `horizon_end`, `target_definition`,
`entity_keys`, `unit`, `currency`, `result_type` (forecast | conditional_simulation | causal_estimate |
optimized_plan | evaluation), `model_version`, `data_snapshot_id`, `config_version`, `assumptions[]`,
`limitations[]`, `quality_status` (ok | degraded | insufficient_data | not_configured |
insufficient_evidence) y `uncertainty_source` (empirical_holdout | conformal | model | unknown | none).
El estado técnico del job (`status`) es distinto de `quality_status`.

**ADR-V2-04 · Huellas de datos.** `data_snapshots` guarda, por ejecución, el hash global y por
componente (ventas, devoluciones, inventario, precios, costos, promociones as-of, externos, calendario,
configuración efectiva, reglas de elegibilidad) más la cobertura de cada fuente. Reproducibilidad =
mismo snapshot + misma configuración + misma semilla.

**ADR-V2-05 · Completitud de fuentes.** `data_coverage` (empresa, entidad, fuente, `complete_from`,
`complete_through`, actualización). Los ceros de demanda y los negativos de compra solo se generan
dentro de la cobertura; fuera de ella el dato es **ausente**, no cero.

**ADR-V2-06 · Configuración por precedencia.** `defaults del core → perfil sectorial (extensión,
opcional) → tenants.settings`, validada contra un esquema; la configuración efectiva y su hash
(`config_version`) se guardan en cada ejecución. Prohibidas las condiciones `si empresa == X`.

**ADR-V2-07 · Registro de modelos.** Estados `candidate`, `validated`, `production`, `retired`,
`failed`; el papel `primary`/`fallback` pasa a la columna `role`. Migración compatible: `fallback` →
`production`+`role=fallback`, `archived` → `retired`, `rejected` → `validated` (evaluado, no elegido).
Reversión: `POST /api/v1/models/{core}/rollback` re-sirve una versión `validated`/`retired` con
artefacto íntegro (demanda: re-apunta la ejecución vigente a una ejecución anterior completada).

**ADR-V2-08 · Entrenamiento ≠ predicción.** Customer Core: `train` (dataset de cortes, protocolo,
registro, promoción condicionada) y `predict` (carga el modelo en producción y recalcula variables al
día; la recencia cambia sin ventas nuevas). Demanda mantiene su ejecución conjunta (documentado).

**ADR-V2-09 · Solver.** `scipy.optimize.milp` (HiGHS, ya instalado con scikit-learn): estados
óptimo/factible con brecha/infactible/límite de tiempo. OR-Tools queda como adaptador opcional.
Respaldo factible heurístico explícito cuando el MILP excede el límite.

**ADR-V2-10 · Horizontes.** Dominio: demanda 7/14/30/60/90; compra 7/30/90. Planes: trial y
profesional/empresarial todos; básico 7/14/30. Política interna de abastecimiento sin cambios.

**ADR-V2-11 · Causalidad.** Contrato `causal/` con verificación de requisitos (variación y soporte de
precio, confusores, disponibilidad, supuestos de identificación). Por defecto `insufficient_evidence`;
la simulación condicionada sigue disponible. Ningún estimador habilitado en esta etapa.

**ADR-V2-12 · Conversación opcional.** `app/Services/Conversation` con proveedor configurable (Ollama
Cloud por defecto, desactivado sin clave). Solo consume endpoints de resultados mediante herramientas
de lista blanca; nunca calcula cifras. Su ausencia no afecta a los cores.

**ADR-V2-13 · Conectores SQL.** Adaptadores de solo lectura PDO para PostgreSQL (probado contra una BD
de origen de pruebas), MySQL/MariaDB y SQL Server (implementados; pruebas de contrato marcadas como
omitidas si falta servidor/driver; estado `implemented_untested`). Solo consultas/vistas autorizadas en
el mapeo; la extracción produce filas canónicas que entran por el **mismo** pipeline de importación.

## 4. Migraciones nuevas (aditivas, reversibles)

| Prefijo | Paquete | Contenido |
|---|---|---|
| `2026_02_01_` | Plataforma (arquitecto) | `core_runs`, `data_snapshots`, `data_coverage`; columnas `config_version`, `effective_config`, `data_snapshot_id` en `forecast_runs`; `training_jobs.core_run_id`; `model_registry.role` + `core` + mapeo de estados; `model_evaluations.metrics` (jsonb) y `core` |
| `2026_02_02_` | Datos | `warehouses`, `unit_conversions`, `commercial_commitments` + `_items` (cotización/pedido/contrato con estado y fechas), `calendar_events` (alcance + `known_at`), `source_mappings` (consultas autorizadas), `known_at`/`available_at` en costos |
| `2026_02_03_` | Comportamiento | `customer_predictions`, `customer_product_rankings`, `customer_activity` |
| `2026_02_04_` | Abastecimiento | `supplier_offers`, `location_capacities`, `purchase_budgets`, `production_resources`, `production_recipes` + `_lines`, `supply_plans` + `_lines`, `cost_forecasts`, `scenario_evaluations` |

## 5. Plan de ejecución (paquetes de trabajo)

Cada paquete lo implementa un subagente con propiedad exclusiva de archivos; el arquitecto fija
contratos, revisa e integra. Dependencias: **P0 → (P1 ∥ P2 ∥ P3 ∥ P4) → P5 → integración**.

| Paquete | Alcance | Pruebas E2-xx |
|---|---|---|
| **P0 Plataforma** (arquitecto) | Migración `2026_02_01`, modelos, envoltura, scaffolding de rutas/routers, ADRs | — |
| **P1 Demanda** | Horizontes 7/14/30/60/90, manifiesto del protocolo, MASE/RMSSE, pinball y P10/P50/P90 evaluados, amplitud, modelo servido coherente, nuevos/descontinuados/fallback por categoría, cobertura de fuentes, huella completa, estados del registro y reversión de demanda | 01, 05, 06, 19, 21, 24 (demanda) |
| **P2 Comportamiento** | Customer Core completo (dataset, etiquetas, modelos, calibración, métricas, salidas, entrenamiento/predicción, reconciliación, compromisos), API backend | 08–11, 18, 21–23 |
| **P3 Datos/Integración** | Conectores SQL, mapeos autorizados, entidades canónicas nuevas, calendarios con alcance, cobertura de fuentes, tres fechas, configuración por precedencia y extensiones | 05, 12, 17, 23, 25 |
| **P4 Decisiones** | Planificador MILP, proveedores, presupuesto, capacidades, lotes, producción genérica, pronóstico de costos, escenarios, contrato causal, simulación histórica de operación | 12–16, 20, 26 |
| **P5 UI + conversación + matriz** | Vistas mínimas de los cores nuevos, estados de abstención; servicio conversacional opcional; matriz de aceptación | 01, 24 |

Después: integración, E2E extendido, actualización de `docs/00`, `03`, `04`, `06`, OpenAPI y matriz.

## 6. Riesgos de compatibilidad

- Consultas de analítica de demanda asumen `forecast_runs`: no se mezclan otros cores en esa tabla.
- Cambio de estados del registro: el backend y la UI deben aceptar los valores nuevos y los legados.
- `available` ya cambió en F1; nuevas recomendaciones del planificador no reemplazan a
  `recommendations` (se publican en `supply_plans` y se referencian).
- Horizontes nuevos: validaciones de requests, planes y UI deben aceptar 14 y 60.
- Datos demo: no se modifican los CSV de ventas existentes (E2E estable); los datos sintéticos
  adicionales de cada core se generan con semilla propia y se identifican como ficticios.

## 7. Fuera de alcance de esta etapa

Módulos sectoriales (Retail/Export/Industry/Agro) como entregables; entrenamiento compartido entre
empresas; estimadores causales activos; modelos fundacionales (Chronos/AutoGluon/TimeGPT) salvo como
candidatos opcionales que demuestren valor; validación con datos reales (🔌, pendiente de acceso
autorizado a Soyoda/Resgasa desde la red del cliente).

## 8. Cierre de la etapa 2 (31-oct-2026)

Paquetes ejecutados: P0/P0b (arquitecto), P1–P4 en paralelo, P5a (interfaz), P5b (plataforma),
P6 (correcciones de integración), P7 (E2E de la etapa 2), P8 (defectos del E2E), P9 (paginación y
corte del plan de abastecimiento en la interfaz). Las decisiones ADR-V2-01..13 se mantuvieron; se
agregaron dos reglas de arquitecto durante la revisión:

- **`cust-ranking-v1`:** la fuente del ranking de productos por cliente se elige en el holdout por
  precision@5 con margen; si el modelo no supera a la popularidad, se sirve popularidad.
- **Visibilidad de compromisos:** `effective_known_at = COALESCE(known_at, ingestión)`; un compromiso es
  visible en el corte C si `effective_known_at` ≤ fin del día local C (misma regla en PHP y Python).

Resultado: todos los casos E2-01..E2-26 tienen código y prueba automatizada; ninguno está validado con
datos reales. E2-20 (operación real) queda 🔌 hasta tener acceso autorizado al histórico de
Resgasa/Soyoda. Estado por caso y evidencia: `PREDICTILY_V2_ACCEPTANCE_MATRIX.md`.
