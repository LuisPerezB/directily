# 01 · Perfil semanal del negocio para las series de historia corta y el respaldo

**Área:** motor (`engine/predictily_engine`) · **Prioridad:** alta · **Esfuerzo:** bajo · **Interruptor:** `weekday_profile_models`
(ya existe; apagado por defecto).

**Estado: implementado el 10/11/2026** (rama `feat/cambio-01-perfil-semanal-series-cortas`). `common_weekday_profile` en
`models/statistical.py`; `short_forecast(..., weekday_factors=)` en `demand/lifecycle.py`; `fill_missing_with_fallback` y el supuesto
en `pipeline/core.py`; `window_average_short` eliminado. Pruebas en `tests/unit/test_weekday_profile.py` y
`tests/unit/test_special_cases.py`. Diferencias con lo escrito abajo: el perfil común se estima con todas las series activas (no
solo las que compiten en el backtest) y la prueba del respaldo se hace sobre la función de relleno, no sobre el pipeline completo.
Pendiente que este cambio no cubre: los días con quiebre de stock se imputan y entran al perfil (cambio `02`), y una empresa sin
backtest posible (< 70 días) sigue sirviendo el promedio móvil plano a todas sus series.

## Problema

Las series con historia corta (clase `insufficient`: productos nuevos y esporádicos) se sirven con un promedio **plano** de su
historia o de su categoría: el mismo valor los 7 días de la semana. En un negocio que no vende sábados ni domingos, esas series
son casi la única fuente de ventas pronosticadas en fin de semana (en el piloto B, el 99 % del domingo y el 82 % del sábado
pronosticados salían de ellas). El perfil semanal `*_dow` solo se aplica a las familias que compiten en el backtest, y estas
series no entran en él (`backtest/rolling.py:170-172`, `eligible_ids`).

## Evidencia en el código

- `demand/lifecycle.py:195` (`short_forecast`): repite el nivel (`own_mean` ~150, o la mediana de la categoría en `_benchmarks` 72-85)
  en los h días, sin día de la semana. Constantes 35-40 y 52-53 (`CATEGORY_FAMILY = "category_benchmark"`).
- `pipeline/core.py:375`: llamada a `short_forecast`; `core.py:383-396`: relleno con el respaldo `window_average` de las series
  servidas sin pronóstico propio.
- `models/statistical.py:23-55` (`weekday_profile`): ya calcula el perfil **común** del negocio (líneas 38-39: unidades por día de la
  semana entre su media, últimos 56 días) antes de mezclarlo con el de cada serie.
- `models/statistical.py:101-115` (`window_average_short`): código muerto, nadie lo llama (confirmado con grep).

## Qué cambiar (pasos)

1. **Exponer el perfil común.** En `models/statistical.py` extraer de `weekday_profile` una función
   `common_weekday_profile(train, days=WEEKDAY_PROFILE_DAYS) -> np.ndarray` (7 factores, media 1, índice 0 = lunes) que reutilice
   las líneas 35-39. `weekday_profile` debe seguir devolviendo exactamente lo mismo (hay pruebas de paridad en
   `tests/unit/test_weekday_profile.py`).
2. **Aplicarlo a las series cortas.** `demand/lifecycle.py::short_forecast` recibe un parámetro opcional
   `weekday_factors: np.ndarray | None`; cuando no es `None`, multiplica el nivel de cada día por el factor de su día de la semana
   (`ds.dayofweek`). Total de una semana completa inalterado (los factores tienen media 1).
3. **Aplicarlo al respaldo plano.** En `pipeline/core.py:383-396`, las filas servidas con el respaldo `window_average` (familia plana)
   se multiplican por los mismos factores. No tocar las filas de familias `*_dow`, LightGBM, ETS ni `seasonal_naive` (ya tienen día
   de la semana).
4. **Mismo interruptor.** Solo cuando `core_config["weekday_profile_models"]` es verdadero (`platform/config.py:56`; el filtrado de
   familias está en `core.py:272-275`). Con el interruptor apagado el comportamiento es idéntico al actual.
5. **Registrar el supuesto.** Añadir a `result.assumptions` (o `limitations`) una entrada «las series nuevas y esporádicas usan el
   ritmo semanal del negocio» cuando se aplique, para que la ficha de la ejecución lo muestre.
6. **Borrar `window_average_short`** (código muerto) o dejar una nota de que la ruta real es `lifecycle.short_forecast`.

## Pruebas

- `tests/unit/test_weekday_profile.py`: `common_weekday_profile` de un negocio sin domingos devuelve 0 el domingo y 7/6 los demás días;
  paridad de `weekday_profile` antes y después.
- Nueva prueba en `tests/unit/test_special_cases.py` (junto a `test_sparse_history_is_labeled_and_uses_the_series_own_average`, :57 y
  `test_new_pr…`, :194): con el interruptor activo, una serie nueva en un negocio sin domingos pronostica 0 el domingo y conserva el
  total semanal; con el interruptor apagado, salida idéntica a la actual.
- Prueba del respaldo (`tests/unit/test_served_model.py` o `test_pipeline.py:41`): las filas servidas con `window_average` por respaldo
  llevan el perfil solo con el interruptor activo.

## Criterio de aceptación

- Piloto B (arnés, interruptor activo): sábado y domingo del nivel total caen a ≈ lo real (en el experimento E1: sábado 180 → 34
  unidades/día con 40 reales; domingo 149 → 1 con 0 reales); el total semanal no cambia (±0,1 %); los ganadores por clase no cambian.
- Piloto A: cambio < 1 unidad/día en cualquier día de la semana (tiene 5 series cortas).
- Suite del motor en verde.

## Riesgos y compatibilidad

- No corrige el **nivel** de las series cortas (en B pronosticaban ~3 veces lo que venden); eso es el cambio `05`/`06` y una
  evaluación sombra de esas series. Este cambio solo arregla el reparto por día.
- Un negocio con series cortas en una sucursal con un ritmo distinto al del total usará el perfil común (no hay perfil por
  sucursal); aceptable como primer paso; documentarlo.
