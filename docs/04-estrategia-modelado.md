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

| Historia disponible | Horizonte de backtest | Paso | Ventanas |
|---|---|---|---|
| ≥ 300 días | 90 | 28 | 3 |
| ≥ 200 días | 60 | 21 | 3 |
| ≥ 120 días | 30 | 14 | 3 |
| ≥ 70 días | 14 | 7 | 2 |
| < 70 días | sin backtest: promedio de la historia, intervalos no calibrados (warning) | | |

Hay pruebas automáticas que perturban los datos posteriores al corte y verifican que las
características y los pronósticos del backtest no cambian (`tests/unit/test_leakage.py`), y
que los indicadores externos solo se usan desde su fecha real de publicación.

## 5. Métricas

- **WAPE** = Σ|real − pred| / Σ real (principal; robusta con ceros). **MAE**, **RMSE**
  (complementaria), **sesgo** = Σ(pred − real)/Σ real (positivo = sobreestima), **cobertura**
  de intervalos 80 % y 95 %.
- Desgloses guardados en `model_evaluations`: total, clase de demanda, tramo de horizonte
  (h1-7, h8-30, h31-90), sucursal, categoría, producto (`p{id}`) y serie (`b{id}-p{id}`).
- Fila compuesta `model_family = 'selection'` (`is_selected = true`) = combinación de
  ganadores por clase; `seasonal_naive` con `is_baseline = true` es la referencia obligatoria.
- **No se anuncian "% de precisión"**: el WAPE diario por producto-sucursal es alto por
  naturaleza (demanda intermitente) y no se transforma en un porcentaje de confianza
  comercial; se muestra siempre junto al baseline y al error del total, que es mucho menor.

## 6. Selección automática y respaldo

1. Por clase se evalúan los candidatos y el baseline.
2. Gana el estadístico de menor WAPE.
3. LightGBM solo lo reemplaza si mejora su WAPE ≥ 3 % (relativo, `ENGINE_MIN_IMPROVEMENT`) **y**
   gana en ≥ 2/3 de las ventanas (estabilidad). Clases con poca evidencia usan la decisión agregada.
4. Se registra un **modelo de respaldo** simple (mejor entre Seasonal Naive y Promedio móvil)
   que se usa si el ganador falla al entrenar o predecir.
5. Un modelo de producción **nunca se reemplaza sin evaluación**: cada ejecución reevalúa a
   todos los candidatos (incluida la familia vigente) antes de promover. Los modelos
   anteriores pasan a `archived`; los perdedores quedan `rejected` con sus métricas.

## 7. Intervalos de predicción

Conformales a partir de los residuos del backtest del modelo elegido, normalizados por la
escala de cada serie (media de 28 días). Nivel serie: cuantiles asimétricos por clase y tramo
de horizonte (conteos sesgados). Niveles agregados: simétricos (|error|), más robustos cuando
las ventanas comparten un sesgo estacional. Más allá del horizonte evaluado se ensanchan con
√(paso/paso máximo). La cobertura se mide **dejando una ventana fuera** (honesta).
Semanal/mensual y totales del horizonte (7/30/90) se calibran con sumas de errores del
backtest (`granularity = 'week' | 'month' | 'horizon'`).

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

Backtest rolling-origin (3 ventanas × 90 días, cortes 2026-06-02, 06-30 y 07-28):

| Empresa demo | Series | WAPE diario serie: seleccionado vs Seasonal Naive | WAPE del total diario | Cobertura 80 % / 95 % |
|---|---|---|---|---|
| Ferretería Andina | 183 | **0,487** vs 0,645 | 0,107 vs 0,113 | 0,798 / 0,949 |
| Distribuidora Costa | 80 | **0,457** vs 0,627 | 0,095 vs 0,135 | 0,796 / 0,941 |

En Ferretería Andina ganó AutoETS en series suaves/erráticas (LightGBM no superó el margen
del 3 %) y LightGBM en las intermitentes (0,866 vs 0,900 de Croston SBA, 3/3 ventanas). Un
pronóstico con corte retroactivo de 30 días, comparado luego con las ventas reales, obtuvo
WAPE realizado 0,511, sesgo +0,5 % y cobertura 80 % de 0,807.

## 12. Limitaciones conocidas (honestas)

- Con < 1 año de historia la estacionalidad anual se estima con poca evidencia; las 3
  ventanas de backtest pueden caer en la misma temporada (sesgo −7 % observado en la demo de
  ferretería, cuyas ventanas coinciden con la temporada seca de construcción). Un lag anual
  (364 días) se probó y apenas mejoró (0,4879 → 0,4869): se descartó por complejidad.
- Los indicadores macro (INEC/BCE) tienen interfaz as-of probada pero no están conectados.
- Elasticidad precio-demanda y efectos causales no se estiman (el simulador usa supuestos).
- Sin inventario no se detecta la demanda no atendida ni se generan recomendaciones.
- Los "puentes" por decreto ejecutivo no son predecibles; pueden añadirse como eventos.
