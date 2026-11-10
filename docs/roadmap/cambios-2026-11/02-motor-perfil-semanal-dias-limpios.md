# 02 · Perfil semanal estimado solo con días limpios

**Área:** motor · **Prioridad:** media · **Esfuerzo:** medio · **Depende de:** `04` (si los cierres se declaran, este cambio pierde
parte de su urgencia pero sigue siendo correcto).

## Problema

`weekday_profile` se estima sobre la columna `y` del entrenamiento, que ya viene **imputada**: los días excluidos (`missing_source`,
`closed`, `stockout_censored`) llevan la media móvil de 28 días de los días limpios (`profiling/series.py::build_panel`). Si un
cierre no declarado (un feriado) cae en lunes y martes, el motor lo infiere como caída de la fuente y lo rellena con un día normal:
el perfil del lunes y del martes sube y el pronóstico de esos días queda inflado (en el piloto B, lunes +166 y martes +139
unidades/día por dos días de carnaval). Además la imputación no conoce feriados: el día festivo del horizonte se pronostica como un
día normal de su día de la semana.

## Evidencia en el código

- `models/statistical.py:23-55` (`weekday_profile`): usa `train[["unique_id","ds","y"]]` tal cual (línea 32, 36); no recibe `day_status`.
- Llamadores: `backtest/rolling.py:271` y `pipeline/core.py:336-340` pasan `train[["unique_id","ds","y"]]`; `selection/shadow.py:121`.
- `profiling/days.py:248-304` (`classify_days`): el panel sí tiene `day_status` por celda; `excluded_days()` y `censored_days()`
  (días excluidos y censurados) están en 75-92.
- Feriados: `calendar/ecuador.py` (feriados nacionales), usados por `features/builder.py` para LightGBM y por `readiness.py:36-42`,
  pero no por los modelos estadísticos ni por el perfil.

## Qué cambiar (pasos)

1. **Pasar el estado de los días al perfil.** `forecast_statistical(train, h, families, day_status: pd.Series | None = None)`: cuando
   `day_status` viene informado, `weekday_profile` se calcula solo sobre filas `sale` o `zero_confirmed` (excluir
   `missing_source`, `closed` y `stockout_censored`). StatsForecast sigue recibiendo la `y` imputada (los modelos necesitan series
   contiguas).
2. **Cablear los llamadores.** En `rolling.py:271` y `core.py:336-340` pasar `train["day_status"]` (el panel la trae); en
   `shadow.py:121` igual.
3. **Peso por semanas observadas.** Al excluir días, un día de la semana puede quedarse con pocas observaciones en los 56 días:
   calcular el perfil común con la media por día de la semana sobre los días observados (no la suma) y mantener el encogimiento
   `n / (n + WEEKDAY_PROFILE_SHRINK)` por serie contando solo días limpios con venta.
4. **Feriados en el perfil (opcional, misma rama o aparte).** Excluir los feriados nacionales de `calendar/ecuador.py` de la
   estimación del perfil (se tratan como no representativos de su día de la semana). Los feriados del **horizonte** siguen
   pronosticándose como días normales mientras no exista el cambio `04` (cierres declarados): dejarlo como limitación registrada.

## Pruebas

- `tests/unit/test_weekday_profile.py`: un lunes `missing_source` imputado con el nivel laborable no altera el factor del lunes;
  un `closed` tampoco; con `day_status=None` el resultado es idéntico al actual (paridad).
- `tests/unit/test_day_status.py`: el backtest con días excluidos entrena el perfil sin ellos (reutilizar `hand_data` y
  `build_panel(data, DayRules())`).
- Regresión en los dos pilotos con el arnés: A (periodo final) no debe empeorar WAPE ni sesgo; B: lunes y martes del total bajan
  hacia lo observado.

## Criterio de aceptación

- Paridad exacta cuando no hay días excluidos ni censurados.
- Piloto B: lunes y martes del nivel total bajan ≥ 100 unidades/día con el resto de días estable (orden de magnitud del
  contrafactual medido: −166 y −139).
- Piloto A: WAPE del periodo final ±0,005 y sesgo ±1 punto respecto a la rama actual.

## Riesgos

- Toca el backtest y la selección de todas las empresas con el interruptor `weekday_profile_models` activo (hoy solo los pilotos).
- Si una serie pierde todos sus días limpios de un día de la semana, cae al perfil común (comportamiento ya previsto en
  `weekday_profile`, línea 43).
