# Predictily V2.5 · Validación prospectiva del piloto

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · Fuentes: V2.5 §18 T10 (pasos 7 y 8), §19 Gate E («el resultado se informa, no se finge»), §21 y §23 paso 6.

> ## Estado: **BLOQUEADO**
>
> **No hay ninguna evaluación prospectiva con datos reales.** Hace falta, por este orden: (1) que el propietario registre las fuentes SQL Server del piloto en la app local (hoy no lo
> están: `docs/operations/OWNER_RESPONSIBILITIES.md`, OWN-02); (2) una línea base aceptada ([`V25_PILOT_BASELINE.md`](V25_PILOT_BASELINE.md)); (3) acordar con el cliente la duración,
> los objetivos, la unidad de evaluación y el comparador (OWN-09, OWN-10); y (4) **dejar pasar el tiempo**: una ventana de observación posterior suficiente para el ciclo de recompra.
>
> **Este archivo versionado nunca contendrá datos del cliente.** Los pronósticos congelados, las ventas posteriores, las métricas y la decisión del piloto se registran **solo** en
> `reports/private/` (ignorado por git). Aquí se describe el protocolo y las herramientas que existen.

**Leyenda.** **IMPLEMENTADO / VERIFICADO** (con datos ficticios o sintéticos) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** · **NO APLICA**.

## Contenido

- [1. Qué es (y qué no es) la validación prospectiva](#1-qué-es-y-qué-no-es-la-validación-prospectiva)
- [2. Protocolo](#2-protocolo)
- [3. Herramientas que existen](#3-herramientas-que-existen)
- [4. Métricas que se producirán](#4-métricas-que-se-producirán)
- [5. Decisión del piloto](#5-decisión-del-piloto)
- [6. Diseñado y bloqueado](#6-diseñado-y-bloqueado)
- [7. Tabla final por módulo](#7-tabla-final-por-módulo)

## 1. Qué es (y qué no es) la validación prospectiva

- **Es**: emitir pronósticos, probabilidades y recomendaciones en una fecha `C`, **congelarlos** con su versión, su huella de datos y su configuración, y compararlos **después** con
  lo que realmente ocurrió en un período que **no se usó para entrenar ni para elegir** modelos, variables o umbrales.
- **No es** la línea base (que mide el pasado reconstruyendo lo conocido en cada corte), ni un backtest con el período final reservado, ni una medición de ROI: una compra posterior a
  una recomendación es una **asociación temporal**, no un beneficio atribuible; solo un grupo de control permite estimar un efecto incremental (V2.5 §12 y §18).
- **Regla central: sin reentrenar sobre el período de evaluación.** Durante la ventana prospectiva no se reentrena el modelo evaluado con los datos de esa ventana, no se cambian sus
  umbrales ni su política de selección; si el negocio exige reentrenar, la versión nueva **empieza su propia** evaluación y la anterior se cierra con lo observado hasta ese día.

## 2. Protocolo

| Paso | Qué | Dónde queda | Estado |
|---|---|---|---|
| 1 | Acordar con el cliente duración, objetivos, unidad de evaluación (SKU × sucursal, cliente × producto) y comparador (baseline y, si se acepta, grupo de control) **antes** de empezar; sin cifras mágicas | `reports/private/` | **PENDIENTE (propietario y cliente)** |
| 2 | Fijar la fecha de inicio `C` y la versión a evaluar: corrida de demanda (`forecast_runs.version`, `config_version`, `data_snapshot_hash`), corrida del Customer Core, modelos aprobados y la política que gobierna (`legacy` por defecto) | base local + copia de identificadores en `reports/private/` | herramienta IMPLEMENTADO / VERIFICADO |
| 3 | **Congelar**: los valores de cada corrida se guardan con su versión (`forecast_values`, `customer_predictions`, `recommendations`) y no se reescriben; una corrida nueva crea otra versión | base local | IMPLEMENTADO / VERIFICADO (`ForecastRunApiTest::test_versions_increment_per_tenant_and_cutoff_creates_historical_run`) |
| 4 | Opcional: activar el grupo de control **antes** de generar recomendaciones (`tenants.settings.decision.control_fraction`, máximo 0,5; asignación uniforme y reproducible por comprador) | `PUT /api/v1/decisions/settings` | IMPLEMENTADO / VERIFICADO (datos sintéticos) |
| 5 | Operar el piloto: las personas aprueban, rechazan o ejecutan; toda transición queda auditada; nada se envía ni se ordena por sí solo | workflow del Decision Engine | IMPLEMENTADO / VERIFICADO (datos sintéticos) |
| 6 | Seguir cargando ventas en modo incremental (tiempo de conocimiento por versión); declarar huecos y cierres | sincronización de la fuente; `POST /api/v1/data-coverage/gaps` | IMPLEMENTADO / VERIFICADO |
| 7 | Al cerrar cada ventana, comparar lo congelado con lo realizado (§3) con la **misma** censura de días que el entrenamiento (quiebres, fuente ausente y cierres no son ceros) | `model_evaluations` (`realized`, `shadow_prospective`), `decision_outcomes` | IMPLEMENTADO / VERIFICADO (datos sintéticos y corridas históricas sintéticas) |
| 8 | Informe privado con las métricas de §4, los errores, los fallos y las pérdidas, la latencia y los costos | `reports/private/V25_PROSPECTIVE_VALIDATION.md` | DISEÑADO / NO EJECUTADO |
| 9 | Decisión GO / GO CONDICIONAL / NO-GO **del piloto** con responsables (§5) | `reports/private/` | **BLOQUEADO** |

## 3. Herramientas que existen

| Herramienta | Comando o ruta | Qué hace | Estado |
|---|---|---|---|
| Evaluación de pronósticos pasados contra ventas reales | `cd backend && php artisan predictily:evaluate-actuals U --lookback=10` (programada a diario a las 02:00 en `routes/console.php`); en el motor: `engine/.venv/bin/python -m predictily_engine.cli evaluate --tenant-id T --lookback-runs 10` | toma las fechas objetivo ya observadas de cada corrida completada, calcula la venta real por nodo con las reglas del entrenamiento (sin anuladas ni devoluciones), guarda `forecast_actuals` (idempotente) y métricas `evaluation_type = 'realized'` por corrida; los días censurados se cuentan por clase y no entran | IMPLEMENTADO / VERIFICADO (`engine/tests/integration/test_api_integration.py::test_historical_run_and_evaluate_actuals`; caída no declarada frente a declarada: `engine/tests/integration/test_asof_evaluation_integration.py::test_a_declared_outage_is_missing_source_not_zero_demand_and_does_not_produce_drift`) |
| Alerta de deterioro | dentro de la evaluación anterior | `forecast_drift` si el WAPE realizado de un tramo de horizonte (1-7, 8-14, 15-30, 31-60, 61-90) supera max(1,3 × backtest; backtest + 0,10) con al menos 14 días observados | IMPLEMENTADO / VERIFICADO; el umbral es de diseño (no aprobado con datos reales) |
| *Shadow mode* prospectivo | `GET /api/v1/models/shadow-evaluations` | el challenger que no se sirve (`shadow_forecast_values`) se evalúa tras el corte con la misma censura que lo servido y se compara con el servido sobre las mismas series y días (`evaluation_type = 'shadow_prospective'`) | IMPLEMENTADO / VERIFICADO (`engine/tests/integration/test_promotion_shadow_integration.py::test_prospective_shadow_evaluation_of_a_historical_run`, `::test_shadow_evaluation_applies_the_same_censorship_as_the_served_evaluation`) |
| Medición de outcomes de las recomendaciones | `cd backend && php artisan predictily:outcomes-measure --tenant=<id>` (`--dry-run` para contar); `GET /api/v1/decisions/roi` | medición as-of de **toda** recomendación (también rechazadas, caducadas y no contactadas), margen de espera para documentos tardíos (`settling_lag_days`, 3), devoluciones y notas de crédito descontadas, contribución (no ingresos brutos), ITT con control y ROI `potential_unproven` sin control | IMPLEMENTADO / VERIFICADO con datos sintéticos (`OutcomeMeasurementTest`, `IttOutcomeTest`, `OutcomeApiTest`) |
| Comparación de dos versiones de modelo | `engine/.venv/bin/python -m predictily_engine.cli compare …` | por horizonte y segmento, entre versiones del registro o paquetes | IMPLEMENTADO / VERIFICADO |
| Uso de cómputo por empresa (costo del piloto) | `cd backend && php artisan predictily:usage-snapshot --tenant=U` | segundos de entrenamiento e inferencia y almacenamiento medidos; **sin precios** | IMPLEMENTADO / VERIFICADO (`TenantUsageMeterTest`) |

## 4. Métricas que se producirán

| Ámbito | Métricas | Nivel | Comparador |
|---|---|---|---|
| Demanda (prospectiva) | WAPE con su denominador y estado, MAE, RMSE, sesgo, cobertura del 80 % realizada; exclusiones por clase de día | corrida × tramo de horizonte; total y serie | el WAPE del backtest de la misma corrida (deterioro); el challenger en *shadow*; la referencia ingenua |
| Customer Core (prospectiva) | tasa de compra observada frente a la probabilidad emitida por horizonte, calibración, precision@K y lift@K realizados, error de cantidad, cobertura de la ventana | horizonte × segmento | referencia de popularidad y recencia |
| Recomendaciones y outcomes | embudo (sugeridas, aprobadas, contactadas, ejecutadas, caducadas), observado y asociado (separados), contribución con costo confiable; con control: diferencia de conversión con intervalo de Newcombe y diferencia de contribución con bootstrap de semilla registrada | tipo × horizonte | grupo de control (si existe); sin control **no hay beneficio atribuible** |
| Operación | latencia de las corridas, fallos, reintentos, costo de cómputo por empresa, participación de los vendedores, principales fallos | piloto | — |

**Ningún objetivo universal** (V2.5 §19): la fecha del piloto y los umbrales económicos se fijan con el cliente. **El ROI nunca se declara probado**: con control se informa una estimación
con su intervalo (y si el intervalo incluye 0, «no significativo», no una victoria); sin control, `potential_unproven`.

## 5. Decisión del piloto

La decisión GO / GO CONDICIONAL / NO-GO **del piloto** la toman el propietario y el cliente con responsables y evidencia, y se registra en `reports/private/`. Si la precisión es
insuficiente, se dice; no se oculta tras un tablero. Esa decisión alimenta la evaluación de release que hace el arquitecto en `docs/roadmap/V25_RELEASE_GATES.md` (Gate E,
hoy **BLOQUEADO**); este documento no la sustituye.

## 6. Diseñado y bloqueado

| Tema | Estado |
|---|---|
| Datos reales del piloto y ventana posterior al corte (V25-APQ-013, V25-CUS-035, V25-OUT-028) | **BLOQUEADO** |
| Evaluador automático de la **calibración prospectiva del Customer Core** sobre `customer_predictions` congeladas (V25-SEL-042: hoy existe la cobertura del 80 % realizada de demanda, sin 95 % ni desglose por segmento) | **DISEÑADO / NO IMPLEMENTADO**; mientras tanto, cálculo manual reproducible a partir de las predicciones congeladas y las ventas posteriores, documentado en el informe privado |
| Bloqueo técnico del reentrenamiento durante la ventana de evaluación (hoy es una regla operativa: no lanzar `customer-train` ni cambiar la política o los umbrales de la empresa piloto) | **DISEÑADO / NO IMPLEMENTADO** |
| Política de reentrenamiento tras el piloto (`AUTOMATED_TRAINING.md`) | **DISEÑADO / NO IMPLEMENTADO** |
| Latencia y SLO con datos del piloto en AWS (T09.12) | **BLOQUEADO** (AWS) |
| Atribución causal sin grupo de control | **NO APLICA**: no se calcula |

## 7. Tabla final por módulo

| Módulo | Evidencia | Estado de pruebas | Costo | Riesgo | Aprobación |
|---|---|---|---|---|---|
| Pronósticos congelados y versionados | `ForecastRunApiTest.php` | en verde (integración de W6) | almacenamiento local | reentrenar dentro de la ventana invalidaría la evaluación | regla del protocolo: propietario |
| Evaluación realizada y deriva | `evaluation/actuals.py`; `test_api_integration.py`, `test_asof_evaluation_integration.py` | en verde con datos sintéticos | CPU local diaria | umbral de deriva de diseño | propietario en el piloto |
| *Shadow* prospectivo | `test_promotion_shadow_integration.py`; `FORECAST_SELECTION.md` | en verde con corridas históricas sintéticas | un modelo más por corrida | — | cambio a la política V2.5: propietario (OWN-16) |
| Customer Core prospectivo | `customer_predictions` | sin evaluador automático | — | calibración real desconocida | propietario en el piloto (OWN-17) |
| Outcomes y ROI | `OUTCOME_MEASUREMENT.md`; `OutcomeMeasurementTest`, `IttOutcomeTest`, `OutcomeApiTest` | en verde con datos sintéticos | CPU local | sin control no hay beneficio atribuible | fracción de control: propietario y cliente (OWN-10) |
| Informe y decisión | `reports/private/` | — | tiempo del propietario y del cliente | **BLOQUEADO** | OWN-11, OWN-12 |
