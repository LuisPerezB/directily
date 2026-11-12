# 11 · Modo de precisión automático

**Área:** motor (`pipeline/precision.py`, `pipeline/core.py`, `platform/config.py`) ·
**Prioridad:** alta · **Esfuerzo:** medio · **Interruptor:** `demand.precision_mode` (`manual` por defecto; `auto`).

**Estado: implementado el 12/11/2026** (rama `claude/optimistic-ritchie-snsv8i`). Las empresas nuevas nacen en `auto`
(`TenantService::create`, `Tenant::NEW_TENANT_DEMAND_CORE`); las existentes siguen en `manual`. Pendiente: medirlo en los pilotos.

## Problema

Las mejoras de precisión 06, 09 y 10 se encienden con cuatro interruptores por empresa. Integrar una empresa nueva obliga a decidir cada
uno a mano, y no hay forma de saber de antemano cuál conviene a sus datos.

## Qué se hizo

Con `demand.precision_mode = "auto"` el motor ajusta los interruptores en cada ejecución con el backtest de la empresa:

- **Enciende los que solo agregan candidatos**: familias del mismo día de la semana (`weekday_profile_models`) y combinación AutoETS +
  LightGBM (`ensemble_models`). Un candidato solo se sirve si gana en el backtest.
- **Elige con la regla del total diario** (`promotion.legacy_selection_metric = total_day`), con sus guardas de sesgo y de error por serie.
- **Decide el ajuste de feriados** (`holiday_adjustment_models`): repite el backtest de las familias estadísticas ajustables con el
  ajuste y lo adopta solo si, en las ventanas de selección, el error del total diario de la mejor familia baja al menos
  `demand.precision_auto_margin` (1 % relativo por defecto) y el error por serie y día no empeora más de 0,5 %. Sin feriados en esas
  ventanas no hay evidencia y no se adopta. Si la empresa ya lo encendió a mano, se respeta sin comparar.
- **Registra la decisión** en `summary["precision"]` (modo, candidatos encendidos, métrica y evidencia del ajuste de feriados); la
  configuración efectiva de la ejecución muestra los interruptores tal como quedaron.

El período final reservado nunca decide. En `manual` la ejecución es exactamente la de siempre (sin el bloque `precision`).

## Costo

En `auto` el backtest de las familias estadísticas ajustables se calcula dos veces (LightGBM y las referencias no se repiten).

## Pruebas

`engine/tests/unit/test_precision_mode.py` (15): valores por defecto y validación; decisión del ajuste (adoptado, sin ganancia, peor, sin
evidencia, el período final no decide, rechazo si empeora el error por serie); en el pipeline, `manual` no cambia nada, `auto` enciende
candidatos y la regla v2, la nota de feriados aparece solo si se adoptó, una ejecución `auto` con el ajuste adoptado es idéntica a la de
una empresa con los cuatro interruptores encendidos a mano, y un interruptor de feriados ya encendido se respeta.
`backend/tests/Feature/Core/TenantManagementTest.php`: una empresa nueva nace en `auto` y una existente no cambia.

## Limitaciones y riesgos

- No busca el mínimo error posible: elige entre las variantes conocidas. La regla de selección no se compara (se fija la del total
  diario, validada en la demo y en datos públicos; ver `engine/scripts/lectura_por_periodo/README.md`).
- Cambia lo que se sirve en toda empresa que lo active: en los pilotos actuales se enciende solo tras comparar con sus datos.
