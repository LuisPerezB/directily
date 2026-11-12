# 09 · Ajuste de feriados de los modelos estadísticos

**Área:** motor (`models/holidays.py`, `models/statistical.py`, `features/builder.py`, `backtest/rolling.py`, `pipeline/core.py`) ·
**Prioridad:** alta · **Esfuerzo:** medio · **Interruptor:** `demand.holiday_adjustment_models` (apagado por defecto).

**Estado: implementado el 11/11/2026** (rama `claude/optimistic-ritchie-snsv8i`). Pendiente: medirlo en los dos pilotos.

## Problema

Solo LightGBM recibe el calendario (`is_holiday`, víspera, día siguiente). Los modelos estadísticos reciben fecha y unidades:

1. Pronostican un feriado del horizonte como un día normal de su día de la semana.
2. Aprenden la caída de un feriado pasado como demanda: el naive estacional la repite cada semana, ETS la mete en su nivel y el perfil
   semanal (`weekday_profile`) baja el factor de ese día.

Medido en las empresas demo (período final reservado, feriado del 10 de agosto de 2026): AutoETS sobrestimó el total del día en +55 % y
+63 %; LightGBM, +4 % y −4 %. AutoETS sirve a 89 de 183 y a 58 de 80 series.

## Qué se hizo

- `models/holidays.py`:
  - `clean_training`: la venta de cada feriado pasado se reemplaza por la esperable sin feriado (media de los mismos días de la semana de
    las 4 semanas anteriores y posteriores de la misma serie, sin contar otros feriados).
  - `holiday_factors`: un factor por nombre de feriado = venta real / esperable del negocio en los últimos 1.100 días, encogido hacia el
    factor común con peso n / (n + 1) (n = fechas vistas de ese feriado). Un feriado no visto usa el común; sin feriados, 1.
  - `apply_factors`: los feriados del horizonte se multiplican por su factor.
- `forecast_statistical(..., holidays=)`: las familias que no son referencia se pronostican con el entrenamiento limpio y se ajustan;
  `naive` y `seasonal_naive` quedan intactas (son la vara de medir). Sin `holidays`, salida idéntica a la de siempre.
- `FeatureBuilder.holiday_days(cutoff, ids)`: feriados por serie (calendario de la sucursal, con nombre). De los pasados se quitan los días
  que el panel excluye (cierre, fuente ausente, quiebre: su venta ya está imputada); el estado de los días posteriores al corte no se consulta.
- Backtest (`run_backtest(holiday_adjustment=)`), entrenamiento final y shadow usan el mismo ajuste, cada ventana con lo conocido en su corte.
- La ejecución registra el supuesto en `assumptions` (feriados del horizonte, factor común, feriados con factor propio).

## Medido (demo ficticia, período final reservado, horizonte 90; regla de selección v1)

| Empresa | Total diario | Total semanal | Serie-día | Error del total el 10 de agosto |
|---|---|---|---|---|
| Ferretería Andina | 0,094 → 0,083 | 0,054 → 0,051 | 0,491 → 0,486 | +50 % → −8 % |
| Distribuidora Costa | 0,096 → 0,087 | 0,046 → 0,034 | 0,449 → 0,446 | +61 % → −10 % |

Datos sintéticos: prueban el mecanismo, no la precisión de un cliente.

## Pruebas

`tests/unit/test_holiday_adjustment.py`: vecinos del mismo día de la semana, factores y encogimiento, limpieza sin leer más allá del
entrenamiento, paridad sin `holidays`, referencias intactas, días excluidos, y en el pipeline: el error del total en los feriados del
backtest baja a menos del 60 % y el supuesto queda registrado solo con el interruptor activo.

## Limitaciones y riesgos

- Un solo factor por feriado para todo el negocio: no distingue sucursales ni productos (una bebida puede subir en carnaval y la
  pintura bajar).
- No ajusta la víspera ni el día siguiente al feriado.
- Con menos de un año de historia casi todos los feriados usan el factor común.
- Un feriado en que la sucursal cierra debe declararse como cierre (cambio `04`): ese día se pronostica en cero y no entra al factor.
- Cambia qué modelo gana: por eso se activa por empresa (`PUT /tenant/core-config/demand`) al aprobarlo en el piloto.
