# 04 · Cierres declarables y días de la semana sin operación

**Área:** backend + motor · **Prioridad:** alta · **Esfuerzo:** bajo (cierres) + medio (días sin operación).

## Problema

1. **No se pueden declarar cierres desde el producto.** El motor ya trata un `calendar_events.category = 'closure'` como día
   `closed` (excluido del entrenamiento y de la evaluación, con prioridad sobre la regla inferida), pero el backend no admite esa
   categoría ni en el modelo ni en el importador: en la práctica ningún cliente puede declarar un feriado o un cierre, y el motor
   los infiere como «caída de la fuente» y los imputa con un día normal.
2. **Un negocio que nunca abre un día de la semana** (sábado y domingo en el piloto B) ve esos días como ceros confirmados en
   todas sus series: la intermitencia medida (ADI) sube, los productos que venden todos los días hábiles quedan clasificados como
   esporádicos (`lumpy`) y las familias para series regulares nunca compiten por ellos. Además los modelos planos pronostican
   ventas esos días (cambio `01` lo mitiga en series cortas; aquí se resuelve en el origen del problema).

## Evidencia en el código

- Motor lee cierres: `db/repository.py:364-377` (`closures` desde `calendar_events` con `category = 'closure'`), `profiling/days.py:12`
  y `_declared_matrices` 114-187 (precedencia `missing_source > closed > …`).
- Backend no los admite: `backend/app/Models/CalendarEvent.php:21` (`CATEGORIES` sin `closure`) y
  `backend/app/Services/Imports/Fields/ImportFieldRegistry.php:209` (campo `category` del importador de `calendar_events`).
- Clasificación: `profiling/series.py:38-41` y `:216` (umbral ADI 1,32 que separa regular de esporádico), `classify_demand` 62-77.
- Regla inferida: `profiling/days.py:190-232` (`infer_missing_days`) y `235-245` (`_same_weekday_regular`): ya evita marcar como caída
  un día de la semana que nunca se vende, pero esos días siguen siendo **ceros** para los modelos.
- No existe ninguna configuración de días operativos (grep `opening|operating_days|weekday` en `platform/config.py` sin resultados).

## Qué cambiar (pasos)

### Parte A · Cierres declarables (bajo, cambia el comportamiento por defecto)

1. Añadir `'closure'` a `CalendarEvent::CATEGORIES` (`CalendarEvent.php:21`) y a la validación del campo `category` del importador
   (`ImportFieldRegistry.php:209`) y de la API de eventos de calendario (buscar `Rule::in(CalendarEvent::CATEGORIES)` en
   `backend/app/Http`).
2. Permitir alcance por sucursal (`branch_id` nulo = toda la empresa) y rango de fechas (`starts_on`/`ends_on`), como ya espera
   `repository.py:364-377`.
3. En la SPA, en Configuración → Calendario, ofrecer «Cierre» como tipo de evento con la explicación «ese día no se entrena, no
   se evalúa ni se pronostica venta».
4. En el pronóstico, los días del horizonte declarados como cierre deben salir en 0 en todas las familias (post-proceso en
   `pipeline/core.py` sobre `series_fc`, antes de `calibrator.apply` en ~447; `lo/hi/p50` también a 0) y contarse en
   `summary.assumptions` (`closures_in_horizon: n`).

### Parte B · Días de la semana sin operación (medio, opcional por empresa)

5. Nueva clave del core de Demanda `operating_weekdays` (`platform/config.py` `CORE_DEFAULTS["demand"]`, valor por defecto
   `null` = los 7 días; validación en `CONFIG_CONSTRAINTS`: lista de enteros 0–6 sin repetidos, al menos 3 días).
6. En `profiling/days.py::_declared_matrices`, cuando la clave esté definida, marcar como `closed` (base `declared`) todos los días
   de la semana que no estén en la lista, para todas las sucursales (o por sucursal si más adelante se quiere). Así: no son ceros
   para la clasificación ni para los modelos, se excluyen de la evaluación y el horizonte los pronostica en 0 (paso 4).
7. **Sugerencia automática, no decisión automática:** en `readiness` (`profiling/readiness.py`) añadir un aviso
   `weekday_without_sales` cuando un día de la semana sume < 1 % de las unidades del día medio durante ≥ 8 semanas, con el texto
   «declare los días sin operación en Configuración». No activar la máscara sin que la empresa lo confirme: el experimento E2
   mostró que forzar ceros dentro del backtest vuelca la selección hacia el promedio plano y empeora el total (el WAPE serie-día
   premia pronosticar de menos).
8. `readiness.py:36-42` cuenta «días hábiles sin venta» excluyendo domingos y feriados pero no sábados: con `operating_weekdays`
   definido, usar esa lista; sin definir, mantener el comportamiento actual.

## Pruebas

- Backend: feature test que crea un `calendar_events` con `category = closure` por API y por importación; prueba de validación de
  `operating_weekdays` (`tests/Feature/...EffectiveConfig...`).
- Motor: `tests/unit/test_day_status.py:201` (cierres declarados tienen prioridad) ampliar con `operating_weekdays = [0..4]`: sábados y
  domingos quedan `closed/declared`, ADI de una serie que vende todos los días hábiles cae por debajo de 1,32 y pasa a `smooth`;
  pronóstico 0 en fin de semana en todas las familias; con la clave sin definir, salida idéntica (paridad en el piloto A).
- `tests/unit/test_horizons.py`: los días `closed` del horizonte no cuentan como observaciones evaluables.

## Criterio de aceptación

- Un cierre declarado (p. ej. dos días de carnaval) deja de imputarse: en el piloto B desaparecen las ~7.300 unidades imputadas y el
  perfil del lunes y martes baja hacia lo observado.
- Con `operating_weekdays = lunes–viernes` en el piloto B, al menos los productos de mayor volumen dejan `lumpy` por `smooth`
  y el fin de semana del total es 0; el total semanal cambia menos del 5 %.
- Piloto A sin la clave: ninguna fila cambia.

## Riesgos

- Parte A cambia el comportamiento por defecto solo para quien declare cierres: riesgo bajo.
- Parte B mal declarada (un día que sí abre) borra ventas reales del entrenamiento: por eso es una decisión explícita de la empresa
  con aviso previo, y el panel debe mostrar cuántas unidades históricas caen en días declarados cerrados (si > 1 %, bloquear).
