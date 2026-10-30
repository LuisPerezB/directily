# 04 · Estrategia de entrenamiento, validación y selección de modelos

Implementación: `engine/predictily_engine/`. Los pronósticos los generan modelos
estadísticos y de machine learning entrenados y evaluados con los datos de cada empresa.
No se usa ningún LLM para calcular cifras.

## 1. Unidad de pronóstico y objetivo

- **Serie** = sucursal × producto, frecuencia diaria, desde su primera venta hasta el corte.
- **Objetivo**: unidades vendidas válidas por día — facturas/notas de venta en estado
  `completed`, sin anuladas ni devoluciones. Las devoluciones (notas de crédito) se reportan
  aparte y no se restan del día en que ocurren (corresponden a ventas pasadas).
- **Ventas ≠ demanda**: un día con cierre de existencias ≤ 0 (o que empezó sin stock) se marca
  como **quiebre**; la venta de ese día está *censurada*. Para lags y modelos estadísticos se
  imputa de forma conservadora `max(venta, media móvil de 28 días sin quiebre)`; en LightGBM
  esos días tienen **peso 0**; y se **excluyen de todas las métricas**. Si no hay inventario,
  no se puede detectar la censura y el motor lo informa como limitación (readiness y warnings).
- Series sin ventas en 180 días o de productos inactivos no se pronostican (se informa).

## 2. Perfilado y clases de demanda

Clasificación Syntetos–Boylan sobre los últimos 365 días sin quiebre: ADI (intervalo medio
entre ventas) y CV² (variabilidad de los tamaños no nulos) con umbrales 1,32 y 0,49:
`smooth`, `erratic`, `intermittent`, `lumpy`; `insufficient` si hay < 28 días o < 4 días
con venta. La selección de modelos se hace **por clase**.

**El perfil siempre es "a un corte" (F4).** `profile_at(panel, c)` calcula historia, días con
venta, actividad (venta en los 180 días previos), clase y elegibilidad usando solo filas con
fecha ≤ `c`. Producción usa el corte final; cada ventana de backtest usa su propio corte, de
modo que ventas posteriores no cambian qué series se entrenan y evalúan en esa ventana ni su
clase (antes se reutilizaba el perfil del corte final: una serie con 2 días de venta al 1-abr
era elegible ese día si después vendía en mayo–julio). El estado del catálogo
(`products.is_active`) solo se aplica en el corte final, porque su historia no está versionada.
Cada fila del backtest guarda la clase de su serie en el corte de su ventana (`demand_class`).

## 3. Niveles estratégicos

| Nivel | Estado en el MVP | Modelos |
|---|---|---|
| **A** Estadístico | ✅ Activo | Naive, Seasonal Naive (7), Promedio móvil 28 días, AutoETS estacional semanal (modelo `ZNZ`, sin tendencia: 7× más rápido que la búsqueda completa con el mismo error en los datos demo), Croston SBA, ADIDA, TSB (intermitentes) |
| **B** Global reutilizable | ✅ Activo por empresa | LightGBM global (MLForecast) entrenado con TODAS las series de la empresa: lags 1/7/14/28, medias y desviaciones móviles, media del mismo día de semana, calendario de Ecuador, promoción, precio relativo, sucursal, categoría, región y nivel de la serie; objetivo Tweedie (conteos con ceros); recursivo |
| **B** entre empresas | ⏳ Diseñado, no activo | Requiere `tenants.allow_shared_training = true`, base jurídica (LOPDP) y evaluación; anonimizar IDs no vuelve anónimos los datos |
| **C** Sectorial | ⏳ Diseñado | `model_registry.scope = 'sector'`, `tenant_id NULL`; se activará con datos suficientes y autorizados del sector |
| **D** Personalizado | ✅ Implícito | Cada empresa obtiene su propio modelo (Nivel B por empresa) solo si supera a los estadísticos en su backtesting |
| **E** Fundacional | 🔌 Interfaz desactivada | `TimesFMAdapter`: pendiente verificar licencia comercial de código y pesos de TimesFM 2.5 y su costo en CPU. No se instala. Si se habilita, compite con la misma regla de selección |

## 4. Validación temporal (rolling-origin)

Nunca se dividen los datos al azar. Para cada corte `c` se entrena solo con datos ≤ `c` y se
pronostican los `h` días siguientes; las variables futuras se construyen igual que en
producción (el calendario es conocido; las promociones siguen la regla as-of de abajo; el
**precio futuro se congela** en el último precio neto observado al corte).

**Promociones as-of (F5).** Para las fechas ≤ `c` la promoción es un hecho observado y se usa
tal como ocurrió (última versión registrada; una dada de baja no ocurrió). Para las fechas > `c`
solo cuentan las promociones que el negocio ya conocía al cierre del día `c` (`promotions.known_at`
≤ `c`, en hora local de la empresa) y con los valores de la versión vigente ese día: la última de
`promotion_revisions` con `recorded_at` ≤ `c` (si ninguna es anterior, la primera versión, que se
conocía desde `known_at`; sin revisiones, la fila actual salvo que ya estuviera dada de baja). Una
promoción registrada, modificada o cancelada después de `c` no altera el pronóstico de `c`. Cada
ventana del backtest, el período reservado y las ejecuciones históricas aplican su propio corte;
la ejecución vigente usa lo conocido el día en que se ejecuta. Un histórico importado sin
`known_at` queda como conocido el día de la carga, de modo que el backtest lo trata como
promoción no anunciada (`tests/unit/test_promotions_as_of.py`).

**Período final reservado (holdout, F6).** Si la historia alcanza, el plan reserva una ventana
final con origen `H = corte − h` y fechas objetivo `(H, corte]`. Las ventanas de **selección**
terminan sus fechas objetivo en o antes de `H`: no comparten fechas con el holdout y sus residuos
ya eran observables en `H`. Entre sí sí se solapan (con h = 90 y paso 28 comparten 62 fechas):
sirven para comparar modelos desde varios orígenes, no como prueba independiente.

| Historia disponible | Horizonte | Paso | Ventanas de selección | Holdout |
|---|---|---|---|---|
| ≥ 390 días | 90 | 28 | 3 | 90 días |
| ≥ 260 días | 60 | 21 | 3 | 60 días |
| ≥ 150 días | 30 | 14 | 3 | 30 días |
| ≥ 84 días | 14 | 7 | 2 | 14 días |
| 70–83 días | 14 | 7 | 2 | **no**: métricas de las ventanas de selección, con `warning` explícito |
| < 70 días | sin backtest: promedio de la historia, intervalos no calibrados (warning) | | | |

Se prefiere el mayor horizonte que admita un holdout independiente. Con 300–389 días ahora se
usa h = 60 con holdout en vez de h = 90 sin holdout. Los pasos de producción que exceden el
horizonte evaluado **ya no se presentan como completos** (ver «Horizontes y abstención» abajo).

**Horizontes 7/14/30/60/90 y abstención explícita (etapa 2 · P1, E2-21).** El motor pronostica
los cinco horizontes del dominio (Laravel pide solo los que el plan autoriza: básico 7/14/30; resto
todos). Un horizonte H está **respaldado** únicamente si (1) la historia cubre ≥ 2×H días y (2) el
backtesting tiene ventanas de ≥ H días y observaciones evaluadas. Si no, queda `insufficient_data` con
sus motivos (`history_lt_2h`, `backtest_horizon_lt_h`, `no_backtest`, `no_evaluated_observations`): el
número se calcula —la jerarquía y las recomendaciones lo necesitan— pero se marca en cada fila
(`forecast_values.quality_status`), en el resumen (`horizon_support`), en la API y en la envoltura, y
las filas `granularity='horizon'` de ese H salen **sin intervalo** (no se extrapola). Sin período final
independiente, los horizontes respaldados quedan `degraded` (métricas optimistas). Por serie, una serie con
menos de 2×H días de historia queda `insufficient_data` en los pasos de ese horizonte. Ejemplo
(`tests/unit/test_horizons.py`): 118 días de historia ⇒ backtest de 14 días con holdout ⇒ 7 y 14 `ok`; 30, 60 y
90 `insufficient_data`. Con 574 días (datos de prueba) o 730 (demo) los cinco horizontes son `ok`.

**Manifiesto del protocolo (E2-06).** Cada ejecución guarda en `forecast_runs.evaluation_manifest` los
períodos que usó cada fase, construidos de las filas reales del backtest: orígenes y rangos objetivo de
las ventanas de selección, la fuente de los residuos de calibración y que eran observables en el origen del
holdout (`calibration.observable_at`), el rango del período final y que **no** participó en selección,
hiperparámetros, umbrales ni calibración (`used_for_* = false`), la maduración de las etiquetas, los
orígenes superpuestos (`overlap`: fechas objetivo compartidas entre ventanas de selección y con el holdout)
y qué ventanas decidieron qué (`decisions`). `demand.manifest.verify()` devuelve las violaciones del
protocolo. Se expone en `GET /accuracy/summary` y `GET /forecast-runs/{id}`
(`tests/unit/test_manifest.py`; docs/architecture/v2/P1-demanda.md §2.2).

Con período reservado, el procedimiento evaluado es el mismo que se usa en producción, pero
ejecutado al origen `H`: (1) elegir modelos por clase solo con las ventanas de selección; (2)
calibrar intervalos solo con los residuos de esas ventanas; (3) pronosticar `(H, corte]` y medir.
El modelo de **producción** sí se elige y calibra con todas las ventanas (selección + holdout),
porque para el pronóstico vigente conviene usar toda la evidencia. Las métricas que se anuncian
salen del paso (3), no del ajuste de producción.

Pruebas automáticas: perturbar datos posteriores al corte no cambia características ni pronósticos
(`tests/unit/test_leakage.py`); el perfil al 1-abr del caso de la auditoría no cambia al perturbar
el futuro (`test_profiling.py`); en el pipeline completo, alterar ventas posteriores al corte de
la primera ventana (y añadir una serie que al corte tenía 2 días de venta) no cambia sus series,
pronósticos ni clases; alterar el período reservado cambia sus métricas, pero no la selección
usada para evaluarlo, ni sus pronósticos, ni las métricas de las ventanas de selección
(`test_pipeline.py`). Los indicadores externos solo se usan desde su fecha real de publicación.

**Pendiente documentado: evaluación anidada.** El holdout mide el procedimiento en un único
origen (90 días, una temporada). Para estimar su variabilidad se puede repetir todo el
procedimiento en varios orígenes externos `H₁ < H₂ < …` (p. ej. cada 28 días hacia atrás): en
cada `Hᵢ`, perfilar, planificar las ventanas internas que terminan en o antes de `Hᵢ`, elegir y
calibrar solo con ellas y medir en `(Hᵢ, Hᵢ + h]`; reportar la media y la dispersión entre
orígenes. Cuesta unas 4 ventanas de backtest por origen externo (≈ 20 s cada uno en la demo de
ferretería), por lo que conviene como tarea programada y no en cada ejecución.

## 5. Métricas

- **WAPE** = Σ|real − pred| / Σ real (principal; robusta con ceros). **MAE**, **RMSE**
  (complementaria), **sesgo** = Σ(pred − real)/Σ real (positivo = sobreestima), **cobertura**
  de intervalos 80 % y 95 %.
- Desgloses guardados en `model_evaluations`: total, clase de demanda, tramo de horizonte
  (etapa 2: `h1-7`, `h8-14`, `h15-30`, `h31-60`, `h61-90`; antes `h1-7`, `h8-30`, `h31-90`), sucursal,
  categoría, producto (`p{id}`) y serie (`b{id}-p{id}`). **Nuevo** `level = 'horizon_total'`
  (`level_key = 'H30'`): error del **total de H días** (7/14/30/60/90) por serie y ventana, para el
  seleccionado y el seasonal naive; es el error que importa al planear compras (en la demo el WAPE del
  total de 30 días es 0,105 frente a 0,49 del paso diario).
- **Métricas de la etapa 2** (jsonb `model_evaluations.metrics`, `demand-metrics-v1`):
  - **MASE** = media de |e| / MAE del naive estacional de 7 días y **RMSSE** = √(media de e² / MSE del
    mismo naive). La escala se calcula **por serie con datos de entrenamiento ≤ origen** (pares t, t−7
    sin días censurados, ≥ 14 pares); ventas posteriores al origen no la cambian
    (`test_scaled_metrics.py`). Escala indefinida (serie constante o < 14 pares) ⇒ la fila se excluye de
    MASE/RMSSE y se cuenta en `exclusions.scale_undefined`; nunca se convierte en cero. MASE < 1 = mejor
    que el naive estacional medido en entrenamiento.
  - **Pinball** de P10, P50 y P90 y **amplitud media** de los intervalos 80/95 (unidades por serie-día y
    relativa a lo vendido): solo con período final independiente, con intervalos calibrados fuera de
    muestra; `null` en las ventanas de selección.
  - **Dinero (ingresos) separado de unidades**: pronóstico = unidades × último precio neto conocido en
    el origen; real = unidades × precio neto observado ese día; `money.{currency, wape, mae, bias,
    actual_total, forecast_total, n_obs}`.
  - **Muestra y exclusiones**: `sample.{n_obs, n_series, n_windows, n_scored_intervals,
    exclusions.{stockout_days, scale_undefined, price_missing}}`. Los denominadores cero dejan WAPE/sesgo
    en `null`, no en cero.
- Fila compuesta `model_family = 'selection'` (`is_selected = true`) = combinación de
  ganadores **servidos** por clase; `seasonal_naive` con `is_baseline = true` es la referencia obligatoria.
- **Qué métrica es la principal.** `evaluation_type = 'backtest'` (lo que leen Laravel, el
  resumen `summary.metrics` y la alerta de deriva) contiene las métricas del **período final
  reservado** cuando existe (`extra.evaluation_scope = 'holdout'`, `n_windows = 1`, `cutoffs` =
  origen del holdout). Las ventanas que eligieron el modelo se guardan como
  `evaluation_type = 'selection_windows'` (global, clase y horizonte): son **optimistas** por
  construcción y la pantalla de precisión las muestra solo como referencia. Sin holdout posible,
  `backtest` usa las ventanas de selección con `extra.evaluation_scope = 'selection_windows'` y el
  run lleva el `warning` correspondiente. `summary.metrics` agrega `evaluation_scope`,
  `independent_holdout`, `holdout` (fechas, selección por clase usada para evaluarlo, ventanas de
  calibración) y `selection_windows` (métricas secundarias).
- **No se anuncian "% de precisión"**: el WAPE diario por producto-sucursal es alto por
  naturaleza (demanda intermitente) y no se transforma en un porcentaje de confianza
  comercial; se muestra siempre junto al baseline y al error del total, que es mucho menor.

## 6. Selección automática y respaldo

1. Por clase se evalúan los candidatos y el baseline. Cada fila del backtest cuenta en la clase
   que su serie tenía **en el corte de su ventana**, no en el corte final (F4).
2. Gana el estadístico de menor WAPE.
3. LightGBM solo lo reemplaza si mejora su WAPE ≥ 3 % (relativo; configuración `demand.min_improvement`,
   sobrescribible por empresa en `settings.cores.demand` o por el operador con `ENGINE_MIN_IMPROVEMENT`) **y**
   gana en ≥ 2/3 de las ventanas (estabilidad). Clases con poca evidencia usan la decisión agregada.
   La regla se aplica dos veces: con todas las ventanas para el modelo de producción y solo con
   las ventanas de selección para la evaluación en el período reservado (F6).
4. Se registra un **modelo de respaldo** simple (mejor entre Seasonal Naive y Promedio móvil)
   que se usa si el ganador falla al entrenar o predecir.
   **Modelo servido coherente (E2-19).** Si el entrenamiento final del ganador (LightGBM) falla, se sirve el
   respaldo en las clases afectadas y TODO lo que acompaña al resultado corresponde al modelo servido:
   las métricas del período final se calculan con las filas del backtest de la familia servida, los
   intervalos se calibran con los residuos de esa familia (grupo clase|familia), cada fila se etiqueta con
   la familia que la produjo, la selección persistida marca `planned_family` y `fallback_applied`, y el
   registro deja al ganador en `failed` (con el error) y al respaldo en `production` con `role = fallback`.
   `summary.served` y la limitación `fallback_served` lo declaran (`tests/unit/test_served_model.py`).
5. Un modelo de producción **nunca se reemplaza sin evaluación**: cada ejecución reevalúa a
   todos los candidatos (incluida la familia vigente) antes de promover. **Estados de la etapa 2**
   (ADR-V2-07): `candidate` (ejecuciones históricas) → `validated` (evaluado con el protocolo y no elegido)
   → `production` (servido; `role = primary`, o `fallback` para el respaldo en espera o que reemplazó a un
   ganador fallido) → `retired` (los de producción anteriores, al completarse una ejecución de producción) o
   `failed`; siempre con `core = 'demand'`, `validated_at` y `artifact_compat`. Los nombres de la etapa 1 se
   derivan en la API (`fallback` = production + role fallback, `archived` = retired, `rejected` = validated).
   La **reversión** (`POST /models/demand/rollback`) re-apunta la ejecución vigente a una de producción
   completada **anterior**, con auditoría y verificación del SHA-256 de los artefactos visibles; no reentrena.

## 7. Intervalos de predicción

Conformales a partir de los residuos del backtest del modelo elegido, normalizados por la
escala de cada serie (media de 28 días). Nivel serie: cuantiles asimétricos por clase y tramo
de horizonte (conteos sesgados). Niveles agregados: simétricos (|error|), más robustos cuando
las ventanas comparten un sesgo estacional. Más allá del horizonte evaluado los pasos diarios se
ensanchan con √(paso/paso máximo), **pero quedan marcados `insufficient_data`** (§4) y los totales de
horizonte de ese H salen sin intervalo. Semanal/mensual y totales del horizonte (7/14/30/60/90) se
calibran con sumas de errores del backtest (`granularity = 'week' | 'month' | 'horizon'`).

- **Producción:** residuos del modelo elegido y **servido** en todas las ventanas (incluido el holdout),
  agrupados por la clase de la serie en el corte de cada ventana y por familia.
- **P10 / P50 / P90.** `lo_80` = P10 y `hi_80` = P90 son los límites del intervalo 80 %: a nivel serie-día son
  los cuantiles 10/90 del error normalizado (conformal asimétrico); en niveles agregados el intervalo es
  simétrico sobre |error| y solo se afirma que contiene el 80 %. Con ≥ 10 % de días en cero, `lo_80 = 0`.
  **P50** (`forecast_values.p50`, solo serie-día) = pronóstico + mediana del error normalizado × escala; **se
  publica únicamente si se evaluó con pinball en el período final independiente** (≥ 200 observaciones) **y
  mejora** a «usar el pronóstico (media) como mediana»; si no, queda `null` en la base y en la API, y
  `summary.p50` explica por qué. La suma de medianas no es la mediana de la suma: los niveles agregados nunca
  llevan P50. En la demo ficticia no se publica (pinball 1,042 frente a 1,032; tampoco por clase de demanda).
- **Cobertura anunciada (holdout):** el intervalo evaluado se calibra **solo** con los residuos de
  las ventanas de selección, ya observables en el origen del holdout, y se mide en el holdout
  (`holdout_coverage`). Es una prueba prospectiva.
- **Cobertura secundaria (ventanas de selección):** "dejando una ventana fuera"; calibra con
  ventanas posteriores y solapadas, por eso no es una prueba independiente (antes era la
  cobertura anunciada).

## 8. Jerarquía

Reconciliación **bottom-up**: series → producto, categoría, sucursal, sucursal×categoría y
total; la suma de hijos es exactamente el padre (probado). Productos sin categoría entran en
sucursal y total, no en nodos de categoría. MinT queda como mejora futura.

## 9. Ingresos y precios

Ingresos = unidades pronosticadas × **supuesto de precio** (último precio neto promedio de 28
días por serie; respaldo: precio actual del producto). El supuesto se guarda en
`forecast_values.unit_price_assumed` y en el resumen. No se reconstruyen ventas pasadas con
precios actuales: el precio y el costo históricos vienen de cada línea de venta.

## 10. Inventario y abastecimiento

Revisión periódica (order-up-to) con saldo diario proyectado
(`engine/predictily_engine/inventory/recommendations.py`; corrige F1–F3 de la validación
2026-10-28). **No se emiten pedidos ni se comprometen fondos.**

**Cobertura.** `L` = plazo de proveedor (producto+sucursal > producto > proveedor > valor de
la empresa) + período de revisión. La necesidad no depende del horizonte pedido: el pipeline
pronostica internamente `max(horizonte, máx. L de las series con inventario)` días (tope 180)
y persiste en `forecast_values` solo el horizonte solicitado; los primeros días no cambian. Si
aun así el pronóstico es más corto que `L` (plazo + revisión > 180) **no se extrapola**: la
recomendación se publica sin cantidades (`suggested_qty`, `demand_lead_time`, `safety_stock`,
`reorder_point` y probabilidad en `null`), con `assumptions.coverage_insufficient = true`,
días cubiertos frente a necesarios y una cota inferior (`suggested_qty_lower_bound`); el
riesgo sale de los días cubiertos y es al menos «medio».

**Existencias y recepciones.** `available` = existencias − reservado: lo único que atiende
demanda hoy. Cada orden de compra abierta (una fila por orden, con su `expected_at`) llega en
su fecha. Supuestos conservadores: una orden sin fecha o vencida sin recibir llega al final del
plazo del proveedor (día = `lead_time_days`); el `in_transit` del inventario (sin fecha) se trata
igual y solo se usa si la serie no tiene órdenes abiertas (si las tiene, se registra como no
utilizado para no contarlo dos veces). Saldo del día `t` = disponible + recepciones hasta `t` −
demanda pronosticada acumulada; la fecha estimada de quiebre es el primer día con saldo
negativo **aunque después llegue una orden** (`assumptions.stockout_before_receipt`). Posición
de inventario = disponible + recepciones que llegan dentro de `L`; las posteriores no reducen
la compra de hoy. Compra sugerida = ⌈max(0, D_L + SS − posición)⌉ (con faltantes pendientes; en
ventas perdidas es levemente conservadora).

**Incertidumbre** (`assumptions.uncertainty`):

| Caso | Stock de seguridad | Probabilidad de quiebre | Riesgo |
|---|---|---|---|
| `empirical`: ≥ 15 errores acumulados del backtest | cuantil del nivel de servicio (√ si `L` > días evaluados) | regla de Laplace, nunca 0 ni 1 | probabilidad, con piso del saldo |
| `intervals`: σ diaria desde intervalos 80 % | z · σ_L | normal | probabilidad, con piso del saldo |
| `zero_variability`: intervalos finitos de ancho 0 | 0 | 0 o 1 (legítimo) | probabilidad, con piso del saldo |
| `unknown`: sin errores e intervalos NaN | `unknown_uncertainty_safety_factor` × D_L (0,5 por defecto, configurable) | `null` (también `demand_lead_time_std`) | reglas deterministas |

La probabilidad se evalúa en los puntos de control del saldo (víspera de cada recepción dentro
de `L` y el día `L`) y se toma el máximo (cota inferior de la unión; exacta si los errores son
comonótonos). Reglas deterministas (`projected_risk_level`), que además son piso del riesgo
probabilístico: **crítico** si el saldo proyectado se agota a más tardar el día en que llegaría
una compra hecha hoy (día ≤ plazo); **alto** si se agota dentro de `L`; **medio** si cae bajo el
stock de seguridad (solo sin probabilidad). Las limitaciones se listan en
`assumptions.limitations` y la pantalla de inventario las muestra.

Pendiente: las órdenes abiertas se leen en su estado actual también para cortes retroactivos;
la variabilidad del plazo (`lead_time_std_days`) se registra pero no entra en el cálculo.

## 11. Resultados medidos en los datos demo (ficticios)

Medición del 29-oct-2026 en memoria (semilla 42, datos **ficticios**, corte 2026-10-26).
Período final reservado: 2026-07-29 a 2026-10-26 (origen 2026-07-28). Ventanas de selección y
calibración de 90 días con cortes 2026-03-04, 04-01 y 04-29 (terminan el 2026-07-28).

| Empresa demo | Series | WAPE diario serie: seleccionado vs Seasonal Naive | WAPE del total diario | Sesgo | Cobertura 80 % / 95 % |
|---|---|---|---|---|---|
| Ferretería Andina (holdout) | 183 | **0,493** vs 0,663 | 0,098 vs 0,116 | −4,0 % | 0,854 / 0,979 |
| Distribuidora Costa (holdout) | 80 | **0,452** vs 0,602 | 0,100 vs 0,134 | +0,1 % | 0,797 / 0,953 |
| Ferretería Andina (ventanas de selección, referencia optimista) | 183 | 0,504 vs 0,664 | 0,119 vs 0,130 | −8,1 % | 0,798 / 0,947 (dejando una fuera) |

Ferretería, holdout por tramo: WAPE 0,480 / 0,493 / 0,494 y cobertura 80 % de 0,82 / 0,85 / 0,86
en 1–7, 8–30 y 31–90 días. La cobertura del holdout queda **por encima** de la nominal: los
intervalos se calibraron con residuos de marzo–julio, con más error que agosto–octubre, y
resultaron conservadores en ese período. Se informa tal cual; con un solo origen no se sabe si
es sistemático (ver evaluación anidada en §4).

Modelos: en Ferretería, la selección usada para evaluar el holdout (solo ventanas anteriores) y
la de producción (las 4 ventanas) coinciden: AutoETS en suaves/erráticas y LightGBM en
intermitentes (0,878 vs 0,929 del promedio móvil de 28 días, 4/4 ventanas). Producción: LightGBM
en 94 series y AutoETS en 89. En Costa la evaluación usó LightGBM en todas las clases salvo
las suaves; producción: AutoETS 57 y LightGBM 23.

**Qué cambió respecto a la evidencia del 28-oct** (`docs/validation/2026-10-28/`, conservada sin
cambios): allí se anunciaron 0,4877 vs 0,6451 (total 0,1055 vs 0,1132), sesgo −7,2 % y
cobertura 0,798 / 0,949, medidos en las mismas 3 ventanas que eligieron el modelo (cortes
06-02, 06-30 y 07-28) y con el perfil del corte final. Ese procedimiento no medía una prueba
independiente (F4 y F6). El WAPE por serie independiente es similar (0,493); el sesgo es menor
porque el holdout cubre agosto–octubre; la cobertura 80 % sube a 0,854 porque ya no se calibra
con el mismo período que se evalúa. El pronóstico retroactivo de 30 días comparado con ventas
reales (WAPE realizado 0,511, sesgo +0,5 %, cobertura 80 % de 0,807) se midió antes de esta
corrección y no se repitió.

### Recalculado en la etapa 2 (29-oct-2026, Ferretería Andina, semilla 42, en memoria, datos ficticios)

`engine/scripts/benchmark_demand_v2.py` (pipeline en memoria, 183 series activas, corte 2026-10-26,
holdout 2026-07-29 → 2026-10-26, 15.458 observaciones serie-día sin quiebre). **Antes** = código de
`main` @ `6425692` + correcciones F1–F7 (`docs/validation/2026-10-29/CORRECCIONES.md`); **después** = etapa 2 · P1.

| Métrica (holdout independiente) | Antes | Después |
|---|---|---|
| WAPE diario por serie, seleccionado / Seasonal Naive | 0,4926 / 0,6631 | 0,4926 / 0,6631 (idéntico: el protocolo y los modelos no cambian) |
| WAPE del total diario | 0,0976 / 0,1164 | 0,0976 / 0,1164 |
| Sesgo · cobertura 80 % / 95 % | −4,03 % · 85,39 % / 97,86 % | −4,03 % · 85,39 % / 97,86 % |
| Modelos servidos | LightGBM 94 · AutoETS 89 | LightGBM 94 · AutoETS 89 |
| **MASE** seleccionado / Seasonal Naive | no medido | **0,840 / 1,092** |
| **RMSSE** seleccionado / Seasonal Naive | no medido | **0,764 / 1,079** |
| **Pinball** P10 · P90 (P50 no publicada) | no medido | **0,371 · 0,635** (P50 candidata 1,042 vs 1,032 de la media como mediana ⇒ `null`) |
| **Amplitud media** del intervalo 80 % · 95 % (unidades por serie-día) | no medido | **8,42 · 14,06** |
| **Dinero** (ingresos): WAPE · sesgo, seleccionado / Seasonal Naive | no medido | **0,662 · −6,0 % / 0,888 · +6,9 %** (real 233.944,59 vs pronosticado 219.938,16; moneda `null` en memoria, USD en BD) |
| WAPE del **total de H días** (seleccionado / Seasonal Naive) H = 7 · 14 · 30 · 60 · 90 | no medido | 0,218 / 0,284 · 0,156 / 0,246 · 0,105 / 0,213 · 0,090 / 0,208 · 0,076 / 0,213 |
| WAPE diario por tramo `h1-7 · h8-14 · h15-30 · h31-60 · h61-90` | 0,480 · 0,493 (8–30) · 0,494 (31–90) | 0,480 · 0,529 · 0,479 · 0,483 · 0,506 |
| Duración del pipeline (n_jobs = 2, CPU compartida) | 64,7 s | 66,7 s (+3 %) |

Lectura honesta: el seleccionado supera al naive estacional **de entrenamiento** (MASE 0,84 < 1) y al de
referencia en dinero; el total de 30 días se predice con 10,5 % de error (vs 49 % el paso diario); los
horizontes cortos (7 días) tienen sesgo negativo de −13 %. Con un solo período de 90 días (una temporada) estas
cifras no tienen intervalo de confianza y no certifican exactitud comercial.

## 12. Limitaciones conocidas (honestas)

- Con < 1 año de historia la estacionalidad anual se estima con poca evidencia; las ventanas
  de backtest pueden caer en la misma temporada (sesgo −7 % en las ventanas del 28-oct, −8 % en
  las ventanas de selección y −4 % en el holdout de la demo de ferretería). Un lag anual
  (364 días) se probó y apenas mejoró (0,4879 → 0,4869): se descartó por complejidad.
- El holdout es **un solo período de 90 días**: es independiente de la selección, pero su
  resultado depende de esa temporada. No hay intervalos de confianza de las métricas; la
  evaluación anidada con varios orígenes (§4) queda pendiente.
- Las métricas son de datos sintéticos; con un histórico real deben recalcularse.
- Los indicadores macro (INEC/BCE) tienen interfaz as-of probada pero no están conectados.
- Elasticidad precio-demanda y efectos causales no se estiman (el simulador usa supuestos).
- Sin inventario no se detecta la demanda no atendida ni se generan recomendaciones.
- Los "puentes" por decreto ejecutivo no son predecibles; pueden añadirse como eventos.
- **P50 no publicada en la demo** (no mejora a la media como mediana). Para demanda con muchos ceros el
  intervalo 80 % tiene `lo_80 = 0`, por lo que «P10» es 0 y su cobertura de cola no es el 10 % nominal.
- Los horizontes se respaldan con la historia **de la empresa** (≥ 2×H) y las ventanas de backtest; no hay
  evaluación anidada de cada horizonte ni intervalos de confianza de las métricas (un solo período final).
- Las métricas en dinero valoran lo pronosticado al último precio conocido en el origen (supuesto de precio
  congelado, §9): incluyen el efecto de cambios de precio posteriores al origen.
- Las series nuevas o de historia escasa se pronostican con un nivel plano (mediana de categoría o promedio
  propio): no aprenden estacionalidad hasta tener ≥ 28 días; quedan marcadas `insufficient_data` en los
  horizontes que su historia no respalda.
- El cierre de sucursal solo se reconoce si está declarado (`branches.is_active = false`; no hay fecha de cierre):
  una sucursal que deja de vender sin declaración se trata como series sin ventas recientes (abstención).
- La cobertura de ventas (`data_coverage`) limita el corte de **toda** la empresa al menor `complete_through`
  declarado; no hay cobertura por producto ni se verifica automáticamente (se declara o no se declara).
- Los eventos extraordinarios solo se marcan; el motor no aprende su efecto (no hay histórico etiquetado).
- La reversión solo verifica el SHA-256 de artefactos visibles desde el backend (volumen compartido con el
  motor); en servidores distintos informa `artifacts_verified = null`.

## 13. Casos especiales, cobertura, huella y configuración (etapa 2 · P1)

Detalle y contratos en `docs/architecture/v2/P1-demanda.md`.

**Casos especiales (§6.D).** Cada serie lleva su etiqueta (`forecast_values.case_label`,
`forecasting_series.lifecycle`, `summary.special_cases`) y su respaldo o abstención:

| Caso | Tratamiento | Etiqueta |
|---|---|---|
| Producto nuevo (< 28 días) | mediana de la categoría (≥ 3 series maduras de la sucursal+categoría o de la empresa) si tiene poca venta propia; si no, su promedio | `new_product_category_median`, `new_product_own_average[_low_evidence]` |
| Historia escasa (≥ 28 días, < 4 con venta) | promedio de su historia | `sparse_history` |
| Descontinuado (catálogo inactivo) | cero explícito si vendió en el último año; si no, abstención | `discontinued_zero` |
| Sin ventas recientes (> 180 días, catálogo activo) | **abstención** (no se inventa un cero) | `no_recent_sales` |
| Sucursal cerrada (declarada) | cero explícito | `branch_closed_declared` |
| Sucursal abierta hace ≤ 90 días | la serie empieza en su primera venta | `branch_opening` |
| Evento extraordinario conocido al corte | bandera opcional; no cambia el valor; calidad `degraded` en sus fechas | `exceptional_event` |

**Cobertura de fuentes.** `data_coverage` (ventas): el corte no supera el menor `complete_through`
declarado; lo posterior (y lo anterior a `complete_from`) es dato **ausente**, no cero. Sin declaración:
comportamiento anterior + limitación `coverage_undeclared`.

**Huella y configuración.** Cada ejecución enlaza `data_snapshots` (ventas, devoluciones, inventario, precios,
costos, catálogo, promociones tal como se conocían al corte, calendario/feature set/eventos, indicadores
externos, insumos de abastecimiento, configuración efectiva, reglas de elegibilidad y cobertura) y guarda
`config_version` + `effective_config` (core → perfil sectorial → empresa → opciones de la ejecución). Mismo
snapshot + configuración + semilla ⇒ mismos valores (`test_pipeline.py::test_reproducible_with_same_seed`,
`test_snapshot_config.py`, `test_demand_v2_integration.py`).
