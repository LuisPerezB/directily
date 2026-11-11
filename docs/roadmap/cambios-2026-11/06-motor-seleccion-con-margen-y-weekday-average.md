# 06 · Selección entre estadísticos con margen y control de sesgo; familia «mismo día de la semana» como candidata

**Área:** motor (`selection/selector.py`, `models/registry.py`, `models/statistical.py`) · **Prioridad:** media · **Esfuerzo:** medio ·
**Depende de:** `05` (más ventanas hacen fiable cualquier regla de selección).

**Estado (11/11/2026, rama `claude/optimistic-ritchie-snsv8i`): parte A implementada con otro diseño; parte B pendiente.**
- **Parte A.** En lugar del margen entre estadísticos frente a la referencia, la regla v2 (`promotion.legacy_selection_metric = total_day`,
  apagada por defecto) ordena a TODOS los candidatos por el WAPE del total diario del alcance, con salvaguarda de sesgo
  (`legacy_bias_tolerance`, 0,15) y de error por serie (`legacy_series_tolerance`, 0,05); LightGBM conserva su margen y su regla de ventanas,
  medidos con esa métrica. Detalle y medición en `docs/ml/FORECAST_SELECTION.md` §2.1; pruebas en `tests/unit/test_selection_total_day.py`.
  Sigue pendiente de lo escrito abajo: el margen mínimo de un estadístico frente a la referencia (paso 1) y validar en los dos pilotos.

## Problema

1. Entre los modelos estadísticos **gana el de menor WAPE serie-día sin margen ni estabilidad**: en el piloto A el modelo servido ganó a
   la referencia por 0,003 de WAPE; la política V2.5 (que sí exige margen y estabilidad) habría publicado la referencia.
2. El **WAPE serie-día premia pronosticar de menos** en series intermitentes: en el experimento E2, un promedio plano con el fin de
   semana a cero «ganó» el WAPE serie-día con un sesgo de −27 % y empeoró el total diario; una regla que solo mira ese WAPE puede
   elegir un modelo que subestima.
3. La familia **«media de los últimos k mismos días de la semana»** (`weekday_average`, k = 4) no existe. En el experimento E3 fue la
   mejor familia en conjunto en el periodo final del piloto A (WAPE 0,28 frente a 0,31 del servido; total 0,18 frente a 0,21) y tuvo
   el mejor WAPE total en el piloto B, pero el selector no la eligió en las ventanas de selección: señal post hoc, no concluyente.

## Evidencia en el código

- `selection/selector.py:148-189` (`_decide_full`): 155 y 161-163 eligen el estadístico de menor WAPE agregado; 173-177 regla de
  LightGBM (`min_improvement`, `needed_windows`); constantes 49-58.
- `selection/promotion.py:94-120` (`V25Policy`), `149-172` (`PromotionConfig.from_config`), gobierno en `platform/config.py:330`
  (`governing_policy = legacy` por defecto); gates en 443-466.
- `backtest/metrics.py::summarize` (WAPE y sesgo por grupo; ya calcula `bias`).
- Variantes semanales: `models/registry.py:34-49`; `models/statistical.py:71-99` (`forecast_statistical`: base en 84, concatenación en 97).

## Qué cambiar (pasos)

### A · Regla entre estadísticos (legacy) con margen y sesgo

1. En `_decide_full`, tras ordenar los estadísticos por WAPE: el candidato solo reemplaza a la **referencia** (`seasonal_naive`) si
   `wape ≤ wape_ref × (1 − min_improvement_statistical)` (nueva clave, por defecto 0,02) **y** `|bias| ≤ bias_tolerance` (nueva clave,
   por defecto 0,15) **y** su WAPE del total agregado (`summarize` por `window` del total diario) no es peor que el de la referencia
   en más de `total_tolerance` (0,02). Si ningún candidato cumple, se sirve la referencia y se registra el motivo en `evidence`.
2. Desempate: entre candidatos dentro del margen, elegir el de menor WAPE del total diario (no el serie-día).
3. Mantener la paridad con la prueba de referencia congelada (`tests/unit/legacy_selector_reference.py`) con las claves nuevas en
   sus valores «neutros» (0, 1, 1): la política `legacy-selection-v1` no cambia; la nueva es `legacy-selection-v2` con su propia
   huella (`LegacyPolicy.version`).

### B · Familia `weekday_average`

4. `models/registry.py`: `FamilyInfo("weekday_average", "A", "statistical", "Promedio de los últimos 4 mismos días de la semana",
   {"k": 4})`; añadirla a `CANDIDATES_BY_CLASS` de las cuatro clases y a la lista que filtra `weekday_profile_models`
   (`pipeline/core.py:272-275`), para que entre solo donde el perfil semanal está activo.
5. `models/statistical.py`: implementarla fuera de StatsForecast (es una media por día de la semana): para cada serie y cada día del
   horizonte, media de las últimas k observaciones del mismo día de la semana en `train` (días limpios si llega `day_status`,
   cambio `02`); con menos de k observaciones, la media disponible; sin ninguna, 0. Separar de `base` en la línea 84 y concatenar
   después de la 97; cuidar el caso `base` vacío.
6. Dejar que el backtest decida; no forzarla. Reevaluar cuando haya ≥ 3 ventanas (cambio `05`).

## Pruebas

- `tests/unit/test_selection_and_intervals.py:29-37`: casos nuevos: candidato con WAPE menor pero sesgo −30 % no reemplaza a la
  referencia; candidato con WAPE 1 % mejor no reemplaza (margen 2 %); desempate por total.
- `tests/unit/test_selection_legacy_parity.py`: paridad de `legacy-selection-v1`.
- `tests/unit/test_weekday_profile.py` o nuevo `test_weekday_average.py`: con 10 semanas de 10 unidades lunes–sábado y 0 el domingo,
  `weekday_average` devuelve 10 y 0; con 2 observaciones devuelve su media; sin datos, 0.

## Criterio de aceptación

- Piloto A: con la regla v2 el servido iguala o mejora el WAPE del total diario y el sesgo del periodo final respecto a la rama actual;
  si la referencia resulta servida, el informe lo explica.
- Piloto B: ningún candidato con sesgo < −15 % es servido.
- Suite del motor en verde, paridad de la política v1.

## Riesgos

- Exigir margen puede dejar la referencia servida en más empresas: es lo honesto, pero hay que medir cuántas (consulta sobre
  `model_promotion_decisions`) antes de cambiar el valor por defecto de `governing_policy`.
- `weekday_average` con k = 4 reacciona rápido a cambios de nivel pero es ruidosa; por eso compite y no se impone.
