# 05 · Plan de ventanas y horizonte: tope de 3 ventanas, periodo final «ligero», horizontes no sostenidos y readiness

**Área:** motor (`backtest/rolling.py`, `demand/horizons.py`, `profiling/readiness.py`) + SPA · **Prioridad:** media · **Esfuerzo:** medio.

## Problema

1. **Nunca hay más de 3 ventanas**, aunque la empresa tenga años de historia: `plan_backtest` usa `min(windows, max_windows)` y el
   tier más largo trae `windows = 3`. Con 3 ventanas de 90 días con paso 28 (solapadas 62 días) la selección es frágil ante cambios
   de régimen, y la política V2.5 (que exige estabilidad) tiene poca base.
2. **Con historia corta no hay periodo final independiente**: el tier mínimo exige `70 + 14 = 84` días; con 81 el plan queda sin
   periodo final y las mismas 2 ventanas eligen y evalúan (métricas optimistas). Un periodo final de 7 días sería posible.
3. **Los horizontes no sostenidos se siguen publicando** como cifra: el dashboard muestra el total a 30 y 90 días aunque
   `horizon_support` los marque `insufficient_data`.
4. **Readiness penaliza sábados** en negocios que no abren los sábados (cuenta «días hábiles sin venta» excluyendo solo domingos y
   feriados).

## Evidencia en el código

- `backtest/rolling.py:48-50` (`TIERS = [(300,90,28,3),(200,60,21,3),(120,30,14,3),(70,14,7,2)]`), `plan_backtest` 89-103 (94 `n_days`,
  96-99 periodo final, 100-102 sin periodo final, `min(windows, max_windows)`), `_window_cutoffs` 83-86.
- `platform/config.py:40` (`max_backtest_windows: 3`); cableado en `pipeline/forecast_run.py:187-191` y `mlops/training.py:163,170`.
- `demand/horizons.py:78-116` (`horizon_support`: `history_lt_2h`, `no_backtest`, `backtest_horizon_lt_h`, `no_evaluated_observations`;
  sin periodo final ⇒ `degraded`), `run_quality` 119-129; `pipeline/core.py:421-433`, 453-454.
- `profiling/readiness.py:35-43` (días esperados: excluye domingos y feriados, no sábados), 55-61 (puntaje), 63-113 (issues).
- Prueba existente que fija el comportamiento: `tests/unit/test_leakage.py:61-73` (80 días ⇒ horizonte 14 sin periodo final).

## Qué cambiar (pasos)

1. **Dejar que `max_backtest_windows` mande.** En `plan_backtest`, `windows = max_windows` si la historia alcanza
   (`n_days ≥ min_days + h + step × (max_windows − 1)`), si no, el máximo que quepa (≥ 2). Mantener 3 como valor por defecto en
   `CORE_DEFAULTS` para no cambiar nada hoy; permitir hasta 8 en `CONFIG_CONSTRAINTS`.
2. **Paso configurable** (`backtest_step_days`, por defecto el del tier) para poder usar ventanas menos solapadas (p. ej. 6 ventanas
   con paso 14 en horizonte 90) cuando la historia lo permite.
3. **Periodo final ligero.** Nuevo tier o regla: si `n_days ≥ min_days + 7` pero `< min_days + h`, reservar un periodo final de
   `min(h, n_days − min_days)` ≥ 7 días y evaluar en él solo los pasos cubiertos; `horizon_support` lo marca `degraded`
   (periodo final parcial) en vez de «sin periodo final». Registrarlo en el manifiesto (`demand/manifest.py`).
4. **Presentación de horizontes no sostenidos.** Backend/SPA: cuando `horizon_support[H].status = insufficient_data`, la tarjeta del
   horizonte muestra «no sostenido con la historia disponible» y no la cifra como principal (sí en la tabla de detalle, marcada).
   Hoy la SPA muestra 30 y 90 días como cifras normales.
5. **Readiness con calendario operativo.** `readiness.py:35-43`: usar `operating_weekdays` (cambio `04`) si existe; si no,
   detectar días de la semana con 0 ventas en toda la historia (≥ 8 semanas) y no contarlos como «hábiles sin venta».

## Pruebas

- `tests/unit/test_leakage.py:61`: añadir casos: 77 días ⇒ periodo final de 7 (horizonte 14 parcial); 600 días con
  `max_backtest_windows = 6` y paso 14 ⇒ 6 ventanas disjuntas de 14 días de paso; las ventanas nunca pisan el periodo final.
- `tests/unit/test_horizons.py:36-75`: estado `degraded` con periodo final parcial; `insufficient_data` sigue igual.
- Prueba de readiness: negocio sin sábados no pierde puntos por «días hábiles sin venta».
- SPA: prueba de componente del dashboard con `horizon_support` insuficiente.

## Criterio de aceptación

- Con los valores por defecto (3 ventanas, tiers actuales) el plan es idéntico al actual en los dos pilotos (paridad).
- Piloto B: con 81 días aparece un periodo final de 7 días; el dashboard deja de mostrar 30 y 90 días como cifras principales.
- Piloto A con `max_backtest_windows = 6` y paso 14: 6 ventanas, selección documentada por ventana en `model_promotion_decisions`.

## Riesgos

- Más ventanas alargan el backtest (LightGBM se reentrena por ventana): medir tiempo en el piloto A (hoy ~1 minuto con 3).
- Un periodo final de 7 días es ruidoso: se publica como `degraded`, nunca como `ok`.
