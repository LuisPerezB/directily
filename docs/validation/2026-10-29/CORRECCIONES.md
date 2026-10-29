# Correcciones de la validación del 28 de octubre de 2026

Respuesta a [`VALIDACION-PREDICTILY-2026-10-28.md`](../../../VALIDACION-PREDICTILY-2026-10-28.md).
Rama: `fix/validacion-2026-10-28`. La evidencia histórica de `docs/validation/2026-10-28/` se conserva
sin cambios; como allí se advierte, su `probe_findings.py` afirma los defectos y ahora se detiene en la
primera aserción (F2). [`probe_after_fixes.py`](probe_after_fixes.py) ejecuta **los mismos casos con las
mismas entradas** y afirma el comportamiento correcto; su salida está en
[`probe-results-after-fixes.json`](probe-results-after-fixes.json).

## 1. Hallazgos

| # | Prioridad | Estado | Corrección | Pruebas de regresión |
|---|---|---|---|---|
| F1 | P1 | ✅ | `available` = existencias físicas (`on_hand − reserved`); cada orden abierta se recibe en su `expected_at` y el saldo se simula día a día. Una orden sin fecha o vencida se asume al final del plazo (supuesto registrado). Un quiebre anterior a la llegada se conserva (`stockout_before_receipt`). En cortes retroactivos solo cuentan las órdenes emitidas y no recibidas al corte. | `engine/tests/unit/test_inventory_validation.py`, `engine/tests/integration/test_inventory_repository.py` |
| F2 | P1 | ✅ | El pronóstico interno para abastecimiento cubre `max(horizonte, plazo + revisión)` (tope 180 días); `forecast_values` guarda solo el horizonte pedido. Si aun así no alcanza, la recomendación se publica **sin cantidad** (`coverage_insufficient`), con la cota inferior y la limitación visibles; no se extrapola. | `test_inventory_validation.py` (F2), `backend/tests/Feature/Analytics/RecommendationLimitationsTest.php` |
| F3 | P1 | ✅ | Incertidumbre desconocida ≠ variabilidad nula: `stockout_probability` y `demand_lead_time_std` quedan `null`; stock de seguridad con política conservadora explícita (50 % de la demanda en cobertura, configurable) y riesgo por regla determinista del saldo proyectado. La regla de Laplace de la rama empírica se mantiene. Además, la desviación del plazo del proveedor (`lead_time_std_days`) ahora entra en el stock de seguridad: σ² = σ²_demanda + (d̄·σ_L)². | `test_inventory_validation.py` (F3), `test_inventory.py` |
| F4 | P2 | ✅ | Elegibilidad, actividad y clase de demanda se recalculan **en cada corte** con datos ≤ corte (`profile_at`); cada fila del backtest lleva la clase de su ventana y la selección y la calibración agrupan por ella. | `test_profiling.py`, `test_leakage.py` (perturbar el futuro reconstruyendo el perfil), `test_pipeline.py` |
| F5 | P2 | ✅ | `promotions.known_at` (fecha en que el negocio la conocía) y tabla `promotion_revisions` (versiones). Para fechas ≤ corte se usa la promoción tal como ocurrió; para fechas > corte solo las conocidas al corte, con los valores vigentes entonces. Importador y API aceptan `known_at`. `FEATURE_SET_VERSION` sube a `fs-2026.10-v2`. | `test_promotions_as_of.py`, `test_promotions_integration.py`, `backend/tests/Feature/Core/PromotionRevisionsTest.php` |
| F6 | P2 | ✅ | Se reserva un **período final** (holdout de h días antes del corte) que no interviene en la selección ni en la calibración usada para evaluarlo; las ventanas de selección terminan antes de su origen (0 fechas compartidas). Las métricas principales (`evaluation_type='backtest'`, `extra.evaluation_scope='holdout'`) son las del holdout; las de selección quedan como `selection_windows` (secundarias, optimistas por construcción). Producción sí usa toda la evidencia. Con historia insuficiente se degrada con advertencia explícita. | `test_selection_and_intervals.py`, `test_leakage.py`, `test_pipeline.py`, `backend/tests/Unit/Analytics/AccuracyExplainerTest.php` |
| F7 | P2 | ✅ | Sin horizonte explícito (programador, botón de la interfaz, API, demo) se usa el **mayor horizonte que permite el plan**; empresas cuyo plan no incluye horizontes se omiten con motivo. | `backend/tests/Feature/Analytics/PlanHorizonSchedulingTest.php` |

## 2. Comportamiento antes y después (casos de la auditoría)

| Caso | Antes (28-oct) | Después |
|---|---|---|
| F1 · 10 u., demanda 10/día, orden de 1 000 u. el día 30 | disponible 1 010, riesgo bajo, compra 0, sin fecha de agotamiento | disponible 10, en tránsito 1 000, agotamiento 2026-10-03 (día 2), riesgo crítico, compra 150 |
| F2 · plazo 30 + revisión 7, stock 0, pronóstico de 7 días | demanda 70, compra 84 presentada como válida | sin cantidad, `coverage_insufficient` (7 de 37 días), cota inferior 70, riesgo crítico; con 90 días: 370 y 402 |
| F3 · sin errores históricos ni intervalos | probabilidad 0 o 1, stock de seguridad 0 | probabilidad `null`, stock de seguridad 70 (política explícita), riesgo medio / alto |
| F4 · serie con 2 días de venta al 1-abr | elegible por ventas de mayo–julio | no elegible en ese corte |
| F5 · promoción registrada el 4-abr, corte 1-abr | descuento 0,5 en el pronóstico | descuento 0; si se registró antes del corte, 0,5 |
| F6 · ventanas 90 días / paso 28 | 62 fechas objetivo compartidas; métricas de las mismas ventanas que eligieron el modelo | holdout 2026-07-28 → 2026-10-26 sin fechas compartidas con la selección |
| F7 · empresa con plan Básico | la ejecución programada pedía 90 días y el plan la rechazaba | se programa con 30 días |

## 3. Métricas de referencia recalculadas (demo Ferretería Andina, semilla 42, datos ficticios)

| Métrica | Antes (ventanas que eligieron el modelo) | Después (holdout independiente) |
|---|---|---|
| WAPE diario por serie, seleccionado / Seasonal Naive | 0,4877 / 0,6451 | **0,4926 / 0,6631** |
| WAPE del total diario, seleccionado / Seasonal Naive | 0,1055 / 0,1132 | **0,0976 / 0,1164** |
| Sesgo diario por serie | −7,20 % | **−4,03 %** |
| Cobertura de intervalos 80 % / 95 % | 79,80 % / 94,90 % | **85,39 % / 97,86 %** (conservadores en este período) |
| Modelos elegidos | LightGBM 94 series, AutoETS 89 | igual |

Referencia secundaria (ventanas de selección, optimistas): WAPE 0,5045 vs 0,6644; cobertura 79,8 / 94,7 %.
Limitaciones: el holdout es un único período de 90 días (sin dispersión de la métrica); son datos
sintéticos y no certifican exactitud comercial. WAPE es un error: 0,49 no es «51 % de precisión».

## 4. Verificación ejecutada

| Suite | Resultado |
|---|---|
| Motor (`cd engine && OMP_WAIT_POLICY=PASSIVE .venv/bin/python -m pytest -q`) | 88 aprobadas (unitarias + integración PostgreSQL) |
| Backend (`cd backend && php artisan test`) | 169 aprobadas, 1 260 aserciones; Pint sin observaciones |
| Frontend (`type-check`, `lint`, `vitest`, `build`) | 69 aprobadas, build correcto |
| `docs/validation/2026-10-29/probe_after_fixes.py` | 6 casos (F1–F6) con el comportamiento correcto |
| Prueba de aceptación E2E (`e2e/mvp_acceptance.py`, API + cola + motor + PostgreSQL) | 32/32 verificaciones (criterios 1–13 del MVP, dos empresas aisladas) |
| Revisión visual (Playwright) de dashboard, inventario y precisión | sin errores de JavaScript ni de API; sin desborde horizontal |

## 5. Orden de trabajo recomendado: estado

1. **F1–F3 y F7** — hecho.
2. **F4–F6 con pruebas de regresión y métricas recalculadas** — hecho (§3).
3. **Histórico real autorizado (p. ej. Soyoda)** — pendiente: requiere acceso y credenciales del
   cliente; el conector ODBC sigue `planned`. La validación de unidades, devoluciones, anulaciones y
   cobertura del inventario, y la distinción cero real / cierre / ausencia de carga, debe hacerse sobre
   ese histórico.
4. **Simulación histórica de operación** (faltantes, sobrestock, capital) — pendiente; conviene hacerla
   sobre el histórico real del punto 3.
5. **Recompra por cliente, costos, suministro, producción y respuesta al precio** — pendiente: es una
   capacidad nueva (el motor agrega ventas antes de modelar y no conserva la dimensión cliente).

## 6. Efecto visible en la demo

Con el tránsito sin fecha tratado como recepción futura (F1), la Ferretería demo pasa de ~42 a 82
productos-sucursal con riesgo alto o crítico (62 críticos, 45 de ellos con quiebre antes de una
recepción). Es el comportamiento buscado: antes ese tránsito se contaba como disponible hoy. Cuando un
pedido nuevo no llegaría antes del faltante, la recomendación lo dice y sugiere acelerar la recepción
pendiente o reasignar existencias.

## 7. Cambios de contrato

- `recommendations`: `available` ya no incluye compras pendientes; `demand_lead_time`, `safety_stock`,
  `reorder_point` y `suggested_qty` admiten `null` (migración `2026_01_04_000100`); nuevas claves en
  `assumptions` (ver `docs/06-contratos-integracion.md` §3.10).
- `promotions.known_at`, baja lógica y `promotion_revisions` (migraciones `2026_01_03_000100/000200`);
  `GET /promotions/{id}/revisions`; campo de importación `known_at`.
- `model_evaluations`: `evaluation_type='backtest'` contiene el holdout; nuevo `selection_windows`.
- `ForecastRunService::request()` acepta horizonte `null` (= mayor del plan).
- Simulador de escenarios: nuevo riesgo `coverage_insufficient`.
