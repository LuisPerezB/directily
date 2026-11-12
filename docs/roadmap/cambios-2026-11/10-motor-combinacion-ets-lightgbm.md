# 10 · Combinación AutoETS + LightGBM como familia candidata

**Área:** motor (`models/ensemble.py`, `models/registry.py`, `selection/selector.py`, `selection/promotion.py`, `pipeline/core.py`) ·
**Prioridad:** media · **Esfuerzo:** bajo · **Interruptor:** `demand.ensemble_models` (apagado por defecto).

**Estado: implementado el 11/11/2026** (rama `claude/optimistic-ritchie-snsv8i`). Pendiente: medirlo en los dos pilotos.

## Problema

El selector se queda con UN ganador por clase. Cuando AutoETS y LightGBM están casi empatados (lo habitual: 0,492 frente a 0,489 por
serie-día en la demo), elegir uno es elegir con ruido, y se desperdicia que sus errores no coinciden: AutoETS sigue el nivel y el ritmo
semanal de cada serie; LightGBM aporta calendario, promociones y precio.

## Qué se hizo

- Familia `ets_lightgbm_mean` (nivel B, tipo `ml`): promedio simple de `auto_ets` y `lightgbm_global`, fila a fila. Solo existe donde
  los dos miembros tienen pronóstico (nunca medio promedio).
- Backtest: `with_ensemble_rows` agrega sus filas en la etapa de selección, DESPUÉS del ajuste de hiperparámetros y de la competencia de
  factores (que reemplazan las filas de LightGBM), así la combinación corresponde a los miembros vigentes.
- Selección: es un segundo modelo complejo. Se sirve si mejora al mejor estadístico ≥ `min_improvement`, gana ⌈n × fracción⌉ ventanas
  (y cumple las salvaguardas en la regla v2) y, si LightGBM también lo cumple, solo si es estrictamente mejor que él. Evidencia en
  `evidence.ensemble`; compuerta `ensemble` en `model_promotion_decisions`.
- Servido: no se entrena nada nuevo. Si gana, el entrenamiento final produce AutoETS y LightGBM y se promedian. Si LightGBM falla en el
  entrenamiento final, la combinación queda `failed` con él y sus clases pasan al respaldo.
- Registro: fila propia sin artefacto (`artifact_kind = ensemble_of_members`); el artefacto firmado de LightGBM sigue en su fila.
- Shadow: como challenger se produce promediando sus miembros.
- Paquete de entrenamiento privado (`mlops/training.py::demand_entries`): si lo servido es la combinación, el artefacto de LightGBM se
  marca como servido (es el mismo artefacto, servido como miembro).
- Etiquetas en backend (`ModelCatalog`) y frontend (`es.json`).

## Medido (demo ficticia, período final reservado, horizonte 90)

| Empresa | Solo `ensemble_models` (regla v1) | Con las tres mejoras (regla v2 + feriados + combinación) |
|---|---|---|
| Ferretería Andina | sin cambio: no supera el margen del 3 % por serie-día | total diario 0,094 → 0,078 (gana LightGBM) |
| Distribuidora Costa | total diario 0,096 → 0,079; serie-día 0,449 → 0,440 | total diario 0,096 → 0,075; total semanal 0,046 → 0,034 |

Datos sintéticos: prueban el mecanismo, no la precisión de un cliente.

## Pruebas

`tests/unit/test_ensemble.py`: filas = media de los miembros y solo donde ambos existen; se rehacen si cambian las de LightGBM; gana, pierde
frente a LightGBM o frente al estadístico según corresponde, en las dos reglas; sin el interruptor no hay filas ni evidencia; en el pipeline,
lo servido es exactamente el promedio de AutoETS y del LightGBM de la ejecución, y un fallo final de LightGBM sirve el respaldo.
`tests/integration/test_precision_switches_integration.py` (PostgreSQL): una ejecución con la regla v2, el perfil semanal, el ajuste de
feriados y la combinación activos se persiste completa: fila propia en `model_registry` sin artefacto, evaluaciones, compuerta `ensemble`
en `model_promotion_decisions` y valores servidos solo con familias conocidas.

## Limitaciones y riesgos

- Pesos iguales y solo dos miembros; no se estiman pesos (con 3 ventanas sería sobreajuste).
- La política V2.5 (que se calcula en paralelo) la trata como un candidato más con artefacto firmado: depende del de LightGBM.
- No verificado: la reversión de modelos (`POST /models/demand/rollback`) y la aprobación de modelos del backend con esta familia servida;
  tampoco las pantallas de la SPA (solo se añadió su etiqueta).
