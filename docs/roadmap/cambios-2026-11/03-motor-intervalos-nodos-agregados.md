# 03 · Intervalos de los nodos agregados con pocas ventanas

**Área:** motor (`intervals/conformal.py`, `pipeline/core.py`) · **Prioridad:** media · **Esfuerzo:** medio.

## Problema

Los intervalos del nivel total, sucursal y categoría se calibran con los residuos del backtest agregado. Con 2 o 3 ventanas, un
nodo agregado tiene pocos residuos por tramo del horizonte (2 ventanas × 7 días = 14 < `MIN_POINTS = 20`) y **hereda los cuantiles
del pool `__all__`**, dominado por series de producto (en el piloto B: 7.336 de 7.434 puntos), cuyo error normalizado es 5 veces
mayor. Resultado: el total a 7 días se publica como «0 – 48.000» con 22.000 pronosticado; a 30 días no hay intervalo. El dashboard
muestra un «rango probable» inútil para decidir.

## Evidencia en el código

- `intervals/conformal.py:32` (`MIN_POINTS = 20`); `ConformalCalibrator.__init__` 48-68: cuantil por `(group, bucket)` solo con ≥ 20
  residuos (63-65) y pool `("__all__", bucket)` con todos los grupos mezclados (66-68); `_q` 80-86 busca el bucket pedido hacia
  abajo y en cada bucket primero el grupo y luego `__all__`.
- `pipeline/core.py:487-531`: agregados (`agg_bt` 498-505, grupo por `level` en 506, semana/mes 507-517, escala 518-519, `apply`
  520-524); series en 436-450 (grupo `demand_class|family`, escala = media de 28 días limpios).
- Observado en el piloto B: residuo normalizado del total q80 = 0,55 y q95 = 0,95 (n = 28) frente a 2,60 y 7,46 del pool.

## Qué cambiar (pasos)

1. **No mezclar niveles en el pool.** En `core.py:506`, calibrar los agregados con un calibrador propio por nivel (`total`, `branch`,
   `category`, `branch_category`, `product`) o pasar `group = level` y crear el pool `__all__` **solo con los nodos del mismo
   nivel** (nuevo parámetro `pool_within: str | None` en `ConformalCalibrator.__init__`).
2. **Respaldo jerárquico antes del pool.** En `_q`: si `(total, bucket)` no alcanza `MIN_POINTS`, probar en orden `total` → `branch` →
   `category` (nodos de mayor agregación comparten escala de error relativa) y, solo al final, el pool del mismo nivel.
3. **Umbral mínimo con corrección por muestra.** Permitir `MIN_POINTS_AGGREGATE = 10` para nodos agregados y ensanchar el cuantil
   empírico por tamaño de muestra (cuantil del orden `ceil((n + 1) · q)`, como en conformal partido estándar), de modo que con 14
   puntos el intervalo sea honesto pero no herede el ruido de los productos.
4. **Registrar la procedencia.** Guardar en `summary.intervals` de qué nivel/pool salió cada cuantil (`source: own | parent:<nivel> |
   pool:<nivel>`), y publicar en la ficha de la ejecución cuando el total use un respaldo.
5. **Medir cobertura del total.** Hoy se mide la cobertura de serie-día; añadir cobertura 80/95 del nivel total y de los horizontes
   acumulados sobre las ventanas (y el periodo final cuando exista) a `summary.metrics`.

## Pruebas

- `tests/unit/test_selection_and_intervals.py:44` (cobertura calibrada) y `:58`: añadir un caso con 2 ventanas en el que el total
  tenga 14 residuos: el cuantil no debe salir del pool de productos; cobertura del total medida en ventanas ≈ nominal (±10 puntos).
- `tests/unit/test_pipeline.py:12-21` (intervalos presentes en todos los horizontes) y `:26` (jerarquía coherente): siguen pasando.
- Prueba de no regresión: una empresa con ≥ 20 residuos por nodo (piloto A: 21 puntos, tabla propia) produce exactamente los mismos
  intervalos que antes.

## Criterio de aceptación

- Piloto B (arnés): intervalo del total a 7 días del orden de ±20–30 % del pronóstico (estimación con sus propios residuos:
  lunes ≈ [1.700, 5.060] frente a [0, 8.762] hoy); cobertura medida del total en las ventanas entre 0,70 y 0,90 para el 80 %.
- Piloto A: intervalos idénticos (±0,1 %).

## Riesgos

- Con muy pocos residuos el intervalo del total puede quedar estrecho de más; por eso la corrección por muestra del paso 3 y la
  medición del paso 5 son obligatorias, no opcionales.
- El respaldo jerárquico supone que el error relativo del total se parece al de la sucursal o categoría; registrar la procedencia
  permite auditarlo.
