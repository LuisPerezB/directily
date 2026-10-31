# P2 · Comportamiento de compra (Customer Core)

Etapa 2 · paquete P2. Especificación: `instrucctions.md` §6.E. Código: `engine/predictily_engine/customer/**`,
`db/customer_repository.py`, `api/routers/customer.py`; backend `app/Services/Customer`, `app/Jobs/Customer`,
`app/Http/**/Customer`, `routes/api/customer.php`; migración `2026_02_03_000100_create_customer_core_tables.php`.

> **Estado de validación.** Todo lo medido aquí procede de **datos ficticios**: (a) un generador con procesos de compra de
> verdad conocida (`customer/synthetic.py`, semilla fija) y (b) la demo Ferretería Andina (`predictily_engine.synthetic`),
> donde el comprador de cada factura se asigna al azar (no hay recompra real por comprador). Comprueban el procedimiento;
> **no validan precisión comercial**. La validación con datos reales (Soyoda/Resgasa) sigue pendiente de acceso autorizado.

## 1. Qué responde

Para cada comprador identificado y cada producto que ya compró, a 7, 30 y 90 días: ¿comprará?, ¿cuánto (total de la
ventana) si compra?, ¿cuánto se espera?, ¿con qué monto supuesto?, ¿con qué frecuencia compra?, ¿qué productos son más
probables?, ¿se está alejando de su patrón? Entrenar (`train`) y predecir (`predict`) son operaciones distintas (ADR-V2-08).

## 2. Dataset de cortes y candidatos

- Unidad: **empresa × comprador × producto × corte**. Los cortes forman una rejilla cronológica (`cutoff_step_days`, 14 por
  defecto) anclada en el último día completo: `L − 7`, `L − 7 − paso`, …, hasta `primera venta + min_history_days` (90) o
  el inicio de cobertura declarada. Se conservan como máximo `max_cutoffs` (60) y, si la rejilla excede `max_dataset_rows`,
  se ensancha el paso (queda en el manifiesto como `cutoff_step_days_effective`).
- **Candidato** al corte `c`: relación comprador×producto con ≥ `min_relation_purchases` (1) compras válidas con día ≤ `c` y
  última compra dentro de `candidate_max_recency_days` (365). Tope `max_candidates_per_customer` (300, se conservan las más
  recientes). Se calcula solo con información ≤ `c`.
- **Compras válidas**: facturas/notas de venta/otros documentos `completed`; sin anuladas (`voided`), sin notas de crédito
  ni líneas de devolución. Las devoluciones se leen aparte (`returned_qty`) y **no restan** de la cantidad comprada.
- **Exclusiones (E2-09)**: ventas sin comprador (`customer_id` nulo), compradores `customers.is_generic` o con código en
  `generic_buyer_codes` (consumidor final), compradores eliminados. Se contabilizan (`summary.excluded_from_individual_prediction`,
  con `modeled_share_units_180d`) pero **nunca** se convierten en una persona recurrente.
- **Productos nunca comprados por el comprador** y **compradores nuevos sin historial**: no son candidatos, no reciben
  predicción individual (se abstiene; no se inventa una relación). Su demanda queda en el **residual** de la reconciliación
  (§9). Una relación con una sola compra sí es candidata y sale marcada `thin_history`.
- **Atribución a sucursal**: la predicción es por comprador×producto **sumando todas las sucursales** (una compra cuenta una
  vez). La *sucursal habitual* (la de la última compra de la relación al corte) solo se usa para disponibilidad y calendario.
- Productos inactivos o eliminados no se predicen (se cuentan en `excluded_inactive_products`).

## 3. Objetivos y etiquetas

Para cada H ∈ {7, 30, 90} (modelos y evaluación propios):

| Salida | Definición |
|---|---|
| `purchase_probability` | P(≥ 1 compra válida del producto por el comprador en `(corte, corte+H]`) |
| `quantity_if_purchase` | **suma de todas las compras válidas** de la ventana (no el siguiente ticket), condicionada a ≥ 1 compra |
| `expected_quantity` | `purchase_probability × quantity_if_purchase`; incluye el cero de «no compra». Identidad verificada en pruebas |
| `expected_amount` | `expected_quantity × último precio neto de la relación` (**supuesto explícito**, sin impuestos, por moneda). Nunca una venta confirmada |

**Madurez.** Una etiqueta existe solo si `corte + H ≤` último día completo de ventas: la cobertura declarada
(`data_coverage`, entidad `sales`; el `complete_through` más conservador) o, sin ella, la última fecha de ventas. Las filas
inmaduras no se entrenan ni se evalúan (se cuentan como `immature`); nunca son negativos.

**Censura (no es un negativo).** Si el producto estuvo sin stock (`existencia − reservas ≤ 0`) en la sucursal habitual en
más de `1 − min_availability_share` (20 %) de la ventana, la **fila completa se excluye** (`censored`), tanto si hubo compra como
si no. Se excluye la fila entera (y no solo el negativo) para no sesgar: conservar solo los positivos de ventanas sin stock
enseñaría «sin stock ⇒ compra». Con menos de `min_availability_known_share` (50 %) de días con inventario conocido, la
disponibilidad es **desconocida**: no se excluye y se contabiliza (`availability_unknown`). Consecuencia declarada: las
probabilidades están **condicionadas a que el producto esté disponible** en la sucursal habitual. No se detecta un cierre del
local sin registro explícito.

## 4. Variables (solo pasado al corte)

Esquema `cust-fs-2026.10-v1` (`features.py`): recencia y tenencia de la relación; conteos en 30/90/180/365 días; tasa 180 d;
distribución de los últimos 8 intervalos (mediana, media, desviación, mín., máx.) y cociente recencia/intervalo; cantidades
recientes (última, media/mediana de las últimas 5, CV, suma 90/180 d); tendencia (cociente 90 d vs 90 d previos, conteo y
cantidad); precio neto observado, variación respecto de la compra previa, CV y relación con el precio medio del producto;
tiempo desde el último cambio de precio de lista (derivado de las ventas, umbral 2 %); descuentos (última compra, 180 d,
producto 30 d); promoción **conocida al corte** (`promotions_as_of`: `known_at`/revisión ≤ corte; vigente hoy y máxima conocida
dentro del horizonte); devoluciones 180 d (informativas); comprador (recencia, días de compra 30–365, mediana de
intervalos, productos distintos); producto (compradores 30/90 d, participación); **calendario** como conteos dentro de la ventana
del horizonte (feriados, quincenas, fin de mes, temporadas — feriados de Ecuador por cantón/región de la sucursal habitual);
**disponibilidad** (estado hoy, días sin stock en 30 d, proporción disponible 90 d).

Decisiones que evitan fuga y extrapolación (probadas): (1) toda consulta usa `EventIndex.pos(grupo, corte)`, que ignora por
construcción lo posterior; el test de no-fuga perturba compras, devoluciones, inventario y promociones posteriores y exige
variables idénticas; (2) no entran el día de la semana/mes del corte como número (la rejilla los mantiene constantes y un
valor numérico extrapola mal; este defecto apareció y se corrigió durante la validación), (3) las variables que crecen con
el calendario (antigüedad, conteo histórico) se acotan a 365/50 y la regresión logística recorta cada variable a
[P0,5; P99,5] del entrenamiento. Las **referencias** (`ref_p`, `ref_q`) no son variables del modelo.

## 5. Protocolo temporal (E2-06 parte customer)

```
entrenamiento | H días | calibración | H días | selección | H días | holdout
```

Fracciones por defecto 55/15/12/18 % del tramo disponible (`block_fractions`). La separación de **H días** entre bloques
garantiza que la ventana de resultado de una fila no se solape con la del bloque siguiente: no se comparten desenlaces.
Los cortes que caen en las separaciones se descartan y se cuentan. **Sin división aleatoria de filas.**

- El **holdout** no se usa para elegir modelo, hiperparámetros, variables, calibrador ni umbrales. Solo evalúa el
  procedimiento completo y aplica la regla de promoción (un único umbral previo y versionado).
- **Niveles** (por horizonte), registrados en `core_runs.evaluation_manifest.horizons[H].tier`:
  `full` (4 bloques; candidatos: referencia, logística, LGBM pequeño y grande), `lean` (sin selección: entrenamiento |
  calibración | holdout, un único candidato complejo preconfigurado, fracciones 60/20/20 %), `reference_only` (solo la referencia
  calibrada con calibración | holdout; resultado `degraded`) y `abstained` (datos insuficientes: sin modelo ni probabilidad,
  motivo explícito — E2-21). Umbrales: `min_block_cutoffs` 2, `min_block_rows` 300, positivos mínimos de entrenamiento 100,
  calibración 30, holdout 30.
- Con 18–24 meses de historia, H = 90 suele quedar en `lean` o `reference_only`: las tres separaciones de 90 días consumen
  el tramo. Es una limitación de los datos, no del código, y se declara.
- **Orígenes solapados.** Con paso < H las ventanas de cortes consecutivos se solapan: sus filas no son independientes
  (`overlapping_origins: true`). No se publican intervalos por fila; la diferencia de Brier entre modelo y referencia se
  acompaña de un bootstrap **por bloques de corte** (informativo, no gobierna la promoción).
- **Submuestreo de negativos** (si el bloque de entrenamiento excede `max_train_rows`): se conserva la fracción `w` de
  negativos y se corrige el prior (`p = w·p_s / (w·p_s + 1 − p_s)`) antes de calibrar con datos sin submuestrear; la tasa queda
  en el manifiesto.

## 6. Modelos, calibración y selección

- **Probabilidad**: referencia frecuencia/recencia (renovación empírica con encogimiento exponencial sobre los intervalos propios
  de la relación: P = [F(r+H) − F(r)] / [1 − F(r)]), regresión logística (recorte por cuantiles, log con signo, imputación) y
  `LGBMClassifier` (`lgbm_small`, `lgbm_large`). Modelos **compartidos dentro de la empresa**; no hay un modelo por comprador.
- **Cantidad condicionada**: referencia histórica (cantidad media de las últimas 5 compras × días de compra esperados dado ≥ 1,
  Poisson truncado en cero) y `LGBMRegressor` con **objetivos comparados**: L2, L2 sobre `log1p` con retransformación de Duan,
  Tweedie (p = 1,5), Gamma y **Poisson solo si todas las cantidades son enteras**. Elección en el bloque de selección: menor MAE entre
  los de |sesgo| ≤ 15 %. Factor de escala Σreal/Σpredicho del bloque de calibración (acotado 0,5–2).
- **Calibración**: ajustada solo con el bloque de calibración (posterior al entrenamiento y separado por H días). Isotónica
  **por grupos** (≥ ~100 filas por grupo, suavizada con la tasa global, extremos constantes; la isotónica sobre filas sueltas
  producía escalones degenerados) con ≥ 150 positivos; Platt con menos; «none» (no calibrado, declarado) sin datos mínimos.
  La referencia también se calibra (comparación justa). Estado por horizonte: `calibrated` si el ECE del holdout ≤ 0,05;
  `degraded`; `uncalibrated`.
- **Regla de promoción `cust-promotion-v1`** (el modelo complejo solo se sirve si la cumple en el holdout):
  probabilidad: mejora relativa del Brier ≥ 2 % **y** log loss no peor **y** ECE ≤ máx(0,03; ECE referencia + 0,02) **y** ≥ 20 positivos en
  selección; cantidad: mejora relativa del MAE ≥ 3 % **y** |sesgo| ≤ máx(10 %; |sesgo| de la referencia). Si no la cumple se sirve la referencia con
  `role = fallback` (documentado en el registro y en cada fila). El candidato no promovido queda `validated`.

## 7. Coherencia entre horizontes (P7 ≤ P30 ≤ P90)

Los eventos están anidados. **No** se multiplica P7 para obtener P30/P90: cada H tiene modelo y volumen propios. Como los
modelos son independientes puede haber violaciones; se aplica el **máximo acumulado** sobre los horizontes crecientes y se
registra la tasa (`summary.coherence.corrected_rate_hH`, `mean_abs_correction_hH`; `probability_coherence_adjusted` por fila).
La corrección se **evalúa** en el entrenamiento por par de horizontes consecutivos (`evaluate_coherence`): tasa de violación
y Brier/log loss de H largo antes y después, separando filas «limpias» (en el holdout de ambos modelos) de «todas» (pueden haber sido
vistas por el modelo corto: optimista). Además `E[Q_7] ≤ E[Q_30] ≤ E[Q_90]` se mantiene subiendo `quantity_if_purchase`
(sin tocar la probabilidad) para conservar la identidad `esperada = P × cantidad`.

## 8. Otras salidas (E2-22)

- **Incertidumbre de la cantidad condicionada**: intervalo conformal multiplicativo del 80 % con cuantiles de `log(real/predicho)`
  del bloque de calibración; la cobertura se mide en el holdout. No se multiplican intervalos de P y cantidad (no hay intervalo de la esperanza).
  La incertidumbre de la probabilidad se expresa por su curva de calibración medida (no por un intervalo).
- **Ventana de próxima compra**: no se ofrece (no se deduce una fecha de una probabilidad binaria); requeriría supervivencia.
- **Monto**: ver §3. Evaluado con MAE/sesgo/WAPE frente a la referencia (importe neto real de la ventana, filas con precio).
- **Frecuencia** (`customer_activity.purchase_frequency_per_30d`): días de compra (cualquier producto) por 30 días en
  `frequency_lookback_days` (180). Evaluada contra los días de compra reales de los 30 días siguientes; se comparan las
  ventanas 90/180/365/histórica (en datos estacionarios las más largas ganan; se informa). A nivel relación: `median_interval_days`.
- **Ranking top-k** (k = 10 almacenado): productos de las relaciones candidatas. Su **fuente** se decide por horizonte en el holdout (ADR-P2-01,
  abajo): probabilidad del modelo servido, popularidad (compradores distintos en 90 días) o recencia (compra más reciente). Evaluado con precision@k y
  recall@k por unidad (corte × comprador) frente a popularidad, recencia y la referencia; también se informa el candidato no promovido.
- **Inactividad (no es «abandono»)**: con ≥ 4 intervalos propios, `at_risk` si la brecha actual supera el P75 de sus
  intervalos e `inactive_by_pattern` si supera el P90 (ambos con holgura del 20 % de su mediana); `inactivity_risk` = percentil empírico de la brecha
  en sus propios intervalos; con menos intervalos: `insufficient_history` (riesgo nulo). Evaluada contra un resultado observable —ninguna compra de
  ningún producto en los 90 días siguientes— y contra la referencia ingenua «sin compras en 60 días» (precision/recall y AUC).
- **Cambio de comportamiento**: días de compra de los últimos 90 días vs. los 90 previos (binomial exacta, p < 0,05 y razón fuera de
  [0,67; 1,5]); requiere ≥ 180 días de historia y ≥ 4 días de compra en total.

### ADR-P2-01 · Fuente del ranking servido de productos probables (`cust-ranking-v1`)

**Contexto.** La regla de promoción de la probabilidad (§6) se decide por Brier/log loss/ECE, que no miden la calidad de *ordenar* productos. En la demo
ficticia el ranking del modelo servido (la referencia) quedaba por debajo de la popularidad, mientras el candidato no promovido la igualaba.

**Decisión.** Por horizonte, la fuente del ranking servido se elige en el holdout entre `model` (probabilidad calibrada del modelo servido), `popularity` y
`recency` por **precision@k** (k primario `ranking_primary_k`, 5 por defecto, sobre unidades corte×comprador con ≥ k candidatos). **Popularidad es la fuente por
defecto**: otra solo se sirve si la supera con un margen relativo mínimo (`ranking_min_relative_margin`, 5 %) y hay ≥ `ranking_min_units` (200) unidades; si ninguna
lo logra se sirve popularidad. Si varias lo logran gana la de mayor precision@k (empate: el modelo). Todo versionado como `cust-ranking-v1`; los parámetros
están en `customer` de `CORE_DEFAULTS` y son sobrescribibles por empresa (`settings.cores.customer`). Como la regla de promoción, es **una sola decisión previa y
versionada por horizonte**: el holdout no ajusta ningún modelo ni umbral.

**Registro.** `ranking.selection` (valores de precision@k de cada fuente, margen, motivo) en `model_evaluations` (`h{H}:ranking`, `extra.selection`); `ranking_source` en
`core_runs.model_versions[H]` y `summary` (entrenamiento y predicción), en `model_registry.hyperparameters` y en el metadato del artefacto; y en **cada fila** de
`customer_product_rankings` (`ranking_source`, `ranking_score`: probabilidad, compradores distintos o −días desde la última compra). Un artefacto no servido
(respaldo validado, candidato) o sin decisión registrada usa popularidad (su ranking no se evaluó). La API lo muestra en `/customer/predictions` (`ranking_source` del horizonte) y en
`/customer/customers/{id}/profile` (`ranking_sources`, y por ítem). Desempate determinista (modelo: probabilidad sin calibrar; popularidad/recencia: producto ascendente).
**Prueba**: `test_the_served_ranking_uses_the_decided_source`, `test_popularity_is_the_default_ranking_source_unless_another_beats_it_by_the_margin` y
`test_when_popularity_wins_the_served_ranking_is_popularity` (un margen inalcanzable por empresa ⇒ popularidad en todos los horizontes, filas ordenadas por popularidad).

## 9. Compromisos y reconciliación (E2-11, E2-23)

**Demanda comprometida** (leída de `commercial_commitment_revisions`/`commercial_commitments`, escritas por P3). El **estado a un corte** es la regla de P3
(`CommitmentAsOf`, docs/architecture/v2/P3-datos.md §2.3), reproducida sin cambios en `customer/commitments.py::state_as_of`: (1) versiones visibles = revisiones con
`known_at` **no nulo** y ≤ corte (fin del día local de `as_of`; sin ninguna, el compromiso no existe a ese corte, ni siquiera para hoy); (2) se usa la última versión visible
(cabecera, líneas y cumplido tal como se conocían); (3) cancelado si `cancelled_at ≤ corte` (una cancelación posterior no aplica); estado cancelado sin fecha ⇒ `cancellation_date_unknown`,
pendiente 0; (4) pendiente = `max(0, cantidad − cumplida)`, 0 si está cancelado. Sobre esa vista, el Customer Core cuenta como comprometido solo `order`/`contract` en estado
`confirmed`/`partially_fulfilled` con entrega en `(corte, corte+H]` (fecha de la línea o de la cabecera). Quedan fuera, con motivo contado: no visibles al corte, cotizaciones, abiertos sin confirmar,
vencidos/cumplidos, cancelaciones (con fecha o de fecha desconocida), entrega sin fecha, vencida o posterior al horizonte y **otra unidad distinta de la del producto** (P3 convierte con factores declarados al
importar; aquí no se convierte en silencio).

**Política de no duplicación.** El histórico de ventas ya contiene los pedidos entregados, así que la esperanza probabilística ya
«contempla» el comprometido típico. La demanda atribuida a una relación es `max(esperanza, comprometido)` =
comprometido + incremento probabilístico (`incremental_quantity = max(esperanza − comprometido, 0)`); **no se suman**. Las
probabilidades individuales no se modifican.

**Reconciliación** (`customer_reconciliations`, por producto y horizonte, en la unidad del producto) contra la demanda agregada
vigente (`forecast_values` nivel `product`, diario, Σ sobre `(fecha, fecha+H]`, todas las sucursales):

```
agregado A = compradores modelados B + comprometido fuera del modelo C_out + residual R
B = Σ max(esperanza, comprometido);  R = A − B − C_out ≥ 0  (anónimos, compradores nuevos, no modelados)
```

Si `B + C_out > A`: `status = inconsistent` (`severity` minor ≤ 10 % / major), `R = 0`, exceso informado; no se altera ninguna
probabilidad. Sin agregado o con cobertura < H días: `aggregate_unavailable` / `aggregate_incomplete` (sin residual inventado). Nunca se suman
las vistas agregada y por comprador. Los intervalos del agregado no se suman día a día: solo se usa la media.

## 10. Entrenamiento ≠ predicción, registro e idempotencia (E2-18, E2-24)

- **`train`**: dataset → protocolo → modelos → evaluación → registro. `core_runs` (`operation = train`, `result_type = evaluation`) guarda
  `evaluation_manifest`, `effective_config` + `config_version` (precedencia core → perfil → empresa), `data_snapshot_id` (huella de compras,
  devoluciones, disponibilidad, promociones, configuración y reglas de elegibilidad) y `model_versions`. Cada modelo en `model_registry`
  (`core = 'customer'`, `scope = tenant`) con `hyperparameters.slot = "h{H}:probability|quantity"`, estado `production` (servido; `role`
  `primary` si es complejo, `fallback` si es la referencia), `validated` (evaluado y no promovido, incluida la referencia como respaldo) o
  `failed`; producción anterior del mismo slot → `retired`. Artefacto joblib en `ENGINE_ARTIFACTS_DIR/{tenant}/customer/run-{id}/` con
  SHA-256 y `artifact_compat` (esquema de variables, hash de nombres, versiones de scikit-learn/LightGBM). Las corridas `historical`
  (corte retroactivo) nunca se promueven.
- **`predict`**: carga los modelos en `production` (verifica SHA-256 y compatibilidad), recalcula las variables **a la fecha** (la recencia
  cambia cada día sin ventas nuevas), predice, aplica coherencia, calcula comprometido, ranking, actividad y reconciliación. No entrena (probado: el
  registro no cambia). Si el artefacto del ganador falla, sirve la **referencia validada** del mismo slot y lo declara (`limitations`, `model_role = fallback`).
  La **reversión** (`POST /api/v1/models/customer/rollback {model_registry_id}`, ability `forecast.run`, auditoría `models.customer_rolled_back`) re-sirve una versión `validated`/`retired` íntegra: el modelo debe
  ser de la empresa y del core `customer` (404 si no), con slot válido, horizonte dentro del plan (402), esquema de variables compatible y SHA-256 verificado cuando el archivo es visible desde el backend (409
  `rollback_artifact_mismatch` si no coincide; si no es visible, lo verifica el motor al cargar y cae a la referencia validada). Marca `retired` la versión en producción **del mismo slot** (`hyperparameters.slot`) y la elegida
  pasa a `production`; la siguiente `predict` la usa (verificado de extremo a extremo contra la BD del motor).
  Sin modelo en producción: la corrida termina `completed` con `quality_status = insufficient_data` y cero filas (abstención).
- **Cobertura y madurez**: la cobertura de ventas es la **efectiva de P3** (`effective_coverage`: declaración del usuario sin fuente > mínimo `complete_through`/máximo `complete_from` entre fuentes > ninguna); sin cobertura, la última venta. Ninguna ventana de resultado pasa de ella (probado).
- **Fecha efectiva**: si la cobertura declarada termina antes de la fecha pedida, las variables se calculan a esa fecha (los días siguientes son
  ausentes, no cero) y el resultado es `degraded`; sin cobertura declarada y ventas con > `stale_data_days` (7) de atraso también.
- **Idempotencia**: una corrida completada devuelve su resumen; una en ejecución reciente → 409; una vencida (`stale_run_seconds`) se retoma
  reemplazando por completo sus filas (clave única por corrida), sin duplicar. Retención: se conservan las filas de las últimas 30 predicciones
  (`retain_prediction_runs`); `core_runs` y evaluaciones se conservan.
- **Aislamiento (E2-17)**: toda consulta del repositorio exige `:tenant_id` (`TenantScopeError` si falta), FKs compuestas en las tablas nuevas, artefactos por
  empresa, y no hay entrenamiento compartido (una empresa sin modelos no hereda los de otra).

## 11. Contratos

Backend `/api/v1/customer/*` y `POST /api/v1/models/customer/rollback` (abilities `forecast.view` / `forecast.run`) y rutas del motor `/v1/customer/*`: `docs/06-contratos-integracion.md` §3.15 y §4.6.
Tablas (migraciones `2026_02_03_000100` y `_000200`): `customer_predictions`, `customer_product_rankings` (con `ranking_source` y `ranking_score`), `customer_activity`, `customer_reconciliations` (+ `core_runs`, `model_registry`,
`model_evaluations`, `data_snapshots` de la plataforma). Envoltura ADR-V2-03 en toda respuesta: `result_type` `forecast` (predicciones, reconciliación) o
`evaluation` (entrenamiento); `uncertainty_source = empirical_holdout`; `quality_status` `ok | degraded | insufficient_data`.
Horizontes: plan ∩ {7, 30, 90}; un horizonte fuera del plan responde 402 y la corrida que se abstuvo de un horizonte responde 422 `horizon_not_available`.

## 12. Pruebas (E2-xx)

| ID | Evidencia (archivo · prueba) |
|---|---|
| E2-08 | `test_customer_dataset.py`: suma de compras en la ventana (2+3+1 = 6, devolución aparte); etiquetas inmaduras sin `y`; censura excluida y nunca 0; disponibilidad desconocida no censura |
| E2-09 | `test_customer_dataset.py` (genéricos/anónimos fuera del dataset, anuladas y notas de crédito no son compras), `test_customer_integration.py::test_generic_and_anonymous_buyers_get_no_individual_prediction`, `CustomerResultsApiTest::test_generic_buyers_have_no_individual_profile_and_the_fallback_is_identified` |
| E2-10 | `test_customer_models.py`: calibración separada y posterior (fechas y n), curva con `n ≥ 30` por grupo, calibradores vs. datos no vistos, **probabilidad servida vs. verdad conocida** del generador, corrección de prior |
| E2-11 | `test_customer_commitments.py` (componentes suman el agregado; esperanza no se suma al comprometido; inconsistencia sin alterar probabilidades; unidades), `test_customer_integration.py::test_commitments_and_aggregate_demand_are_reconciled_without_double_counting` |
| E2-17 | `test_customer_integration.py::test_company_isolation_*`, `test_repository_refuses_queries_without_a_tenant_filter`; `CustomerResultsApiTest` (aislamiento de predicciones, perfil, reconciliación) y `CustomerRunApiTest::test_listing_filters_and_company_isolation` |
| E2-18 | `test_customer_integration.py`: reintentos idempotentes, retomar una corrida vencida sin duplicar, 409 si hay otra en curso, recencia +3 días sin ventas nuevas; `test_customer_models.py::test_the_recency_changes_every_day_without_new_sales` |
| E2-21 | `test_customer_models.py::test_insufficient_data_abstains_*`, `test_customer_integration.py::test_insufficient_history_abstains_explicitly`; Laravel: `horizon_not_available`, plan Básico 7/30 |
| E2-22 | `test_customer_models.py`: monto = esperanza × precio supuesto; frecuencia, inactividad (por patrón, historial insuficiente), cambio de comportamiento, ranking (a mano), evaluación frente a la referencia ingenua |
| E2-23 | `test_customer_commitments.py`: `known_at` posterior, cancelación antes/después del corte, entrega fuera de H, cotizaciones, unidades, reconstrucción por revisiones |
| E2-24 (customer) | registro con SHA-256/`artifact_compat`, un solo `production` por slot; **reversión**: `CustomerModelRollbackTest` (restaura retirada/validada, solo su slot, auditoría, rechazo por SHA-256 alterado, esquema incompatible, plan, 404 otra empresa/core, 403 visor) + `test_rollback_to_a_validated_version_is_served_by_the_next_prediction`; artefacto roto ⇒ referencia validada; funciona sin LLM |
| Ranking (ADR-P2-01) | `test_customer_models.py::test_popularity_is_the_default_ranking_source_unless_another_beats_it_by_the_margin`, `test_the_served_ranking_uses_the_decided_source`; `test_customer_integration.py::test_when_popularity_wins_the_served_ranking_is_popularity`, `test_ranking_source_is_recorded_in_models_summary_and_every_ranking_row`; `CustomerResultsApiTest` (API muestra `ranking_source`) |
| Cobertura P3 | `test_customer_integration.py::test_label_maturity_and_prediction_date_follow_the_effective_coverage_of_p3` |
| E2-05/06 (customer) | `test_features_ignore_everything_after_the_cutoff`, `test_blocks_are_chronological_and_separated_by_h_days`, manifiesto con fechas y política del holdout |
| E2-07 (customer) | `CustomerJobsAndSchedulingTest`: predicción diaria y entrenamiento semanal usan los horizontes del plan (Básico 7/30) |

## 13. Resultados medidos (datos ficticios)

Comandos y semillas: ver §16. Holdout = último bloque cronológico; «ref» = referencia frecuencia/recencia calibrada; Brier de la climatología = tasa base.

**(a) Generador de verdad conocida** (`generate_world`, intervalos medios 20–200 días; 130–150 compradores, 9 productos, ~450 días; paso 14 días). La probabilidad servida en H = 30 se
contrasta con la **probabilidad verdadera** del proceso (`test_served_probabilities_match_the_known_truth`: media servida ≈ media verdadera, por quintiles |Δ| < 0,10,
Brier a ≥ 40 % del camino entre climatología y oráculo, ECE < 0,07).

| Mundo · H | Nivel | Servido | Brier ref → servido | ECE ref → servido | Mejora relativa del Brier del candidato |
|---|---|---|---|---|---|
| sin churn · 7 | full | logística (promovida) | 0,0959 → 0,0885 | 0,024 → 0,020 | 7,7 % |
| sin churn · 30 | full | referencia (fallback) | 0,1961 → 0,1961 | 0,038 → 0,038 | candidato 5,2 % pero su ECE (≈ 0,058) no cumple «≤ ECE de la referencia + 0,02» ⇒ no promovido |
| sin churn · 90 | reference_only | referencia (degraded) | 0,1733 | 0,050 | — (datos insuficientes para entrenar/validar) |
| con churn 15 % · 7 | full | LGBM pequeño | 0,0926 → 0,0876 | 0,022 → 0,029 | 5,4 % |
| con churn 15 % · 30 | full | logística | 0,1958 → 0,1641 | 0,074 → 0,044 | 16,2 % |
| con churn 15 % · 90 | reference_only | referencia (degraded) | 0,1985 | 0,101 | — |

Inactividad (mundo con churn, 2.572 observaciones comprador×corte): tasa base de «sin compras en 90 días» 11,2 %; `inactive_by_pattern` marca 245 con precisión 41,6 % (lift 3,7) y
recall 35,3 %; la regla ingenua «sin compras en 60 días» marca 304 con precisión 45,1 % y recall 47,4 % y su AUC (0,87) supera al del riesgo por patrón propio (0,79). **La definición por patrón
propio no supera a la regla simple en este generador**; se informa tal cual.

**(b) Demo Ferretería Andina** (`tests/customer_demo_report.py`, semilla 42, 99.334 líneas, 62 productos, 2024-10-27 → 2026-10-26; entrenamiento ≈ 91–125 s y predicción ≈ 7 s con 4 hilos;
JSON completo en `P2-demo-ferreteria-andina.json`). El comprador de cada factura es aleatorio: **no hay señal de recompra**, por eso modelo ≈ referencia ≈ climatología.

| H | Nivel | Servido (prob.) | Brier modelo / ref / climat. | log loss ref → mejor | PR-AUC ref → mejor | ECE servido | n holdout (positivos) |
|---|---|---|---|---|---|---|---|
| 7 | full | referencia (el candidato logística mejoró 0,5 % < 2 %) | 0,01488 / 0,01488 / 0,01488 | 0,0784 → 0,0769 | 0,0145 → 0,0225 | 0,0018 | 117.402 (1.773) |
| 30 | full | referencia (candidato 1,2 % < 2 %) | 0,05827 / 0,05827 / 0,05824 | 0,2331 → 0,2270 | 0,0622 → 0,0905 | 0,0064 | 100.118 (6.215) |
| 90 | full | **logística promovida** | 0,13830 / 0,14227 / 0,14212 | 0,4589 → 0,4432 | 0,1724 → 0,2364 | 0,0065 | 59.221 (10.161) |

Cantidad condicionada (MAE ref → servido; todas promovidas por mejorar ≥ 3 %): H7 3,146 → 2,936 (Poisson); H30 3,589 → 3,208 (Gamma); H90 4,553 → 3,742 (Gamma); sesgo −14 % → −12 % / −18 % → −12 % / −23 % → −6 %.
Esperanza incondicional (incluye no compradores; sesgo = Σ(pred−real)/Σreal): H7 −23,4 % → −22,8 %; H30 −31,2 % → −26,9 %; H90 −30,0 % → −3,5 % (referencia → servido). Monto esperado (sesgo): H7 −10,0 % → +1,7 %; H30 −21,2 % → −11,8 %; H90 −15,8 % → −9,0 %.

Ranking (precision@5 en el holdout; regla `cust-ranking-v1`): H7 modelo servido (referencia) 0,0139, recencia 0,0161, **popularidad 0,0234**; H30 0,0638 / 0,0645 / **0,0944**; H90 0,2477 / 0,1788 / **0,2598**.
Ninguna fuente supera a popularidad por ≥ 5 %, así que **se sirve popularidad en los tres horizontes** (`ranking_source = popularity`). El candidato no promovido la iguala en H7/H30 (0,0233 y 0,0948): en estos datos,
sin señal de recompra por comprador, la popularidad es el mejor ordenamiento disponible y ahora es el que se sirve.

Coherencia: tasa de violación P30 > P90 6,4 % (Brier 0,13920 → 0,13926 con la corrección, efecto neto despreciable), P7 > P30 0 %; en la predicción diaria se corrige el 5,2 % de las filas de H = 90 (corrección media 0,0009).
Inactividad (demo): las tres categorías tienen la misma tasa de «sin compras en 90 días» (2,3–2,6 % frente a la base 2,4 %; AUC 0,50): coherente con compradores aleatorios.
Frecuencia (MAE de días de compra por 30 días): 90 d 1,003; 180 d 0,946; 365 d 0,908; histórica 0,897 (se usa 180 por configuración; en datos estacionarios las ventanas largas ganan).
Compradores genéricos: 14.202 líneas (45 % de las unidades de los últimos 180 días quedan fuera del modelo; `modeled_share_units_180d` = 0,454); sin predicción individual.

## 14. Limitaciones y pendientes

- Validación solo con datos ficticios; en la demo los compradores se asignan al azar a cada factura, así que las métricas del modelo
  son ≈ climatología (Brier skill ≈ 0), como corresponde. Con el generador de verdad conocida el procedimiento sí recupera la señal.
- H = 90 depende de ≥ ~24 meses para el protocolo completo; con menos queda `lean`/`reference_only`/abstención.
- El modelo servido no se reentrena con los bloques de calibración/selección/holdout (el siguiente `train` usa ventanas más recientes);
  refit final con la misma receta está pendiente.
- Supervivencia/BG-NBD (ventana de próxima compra) no implementados. El monto no modela precios futuros.
- La inactividad «por patrón» no supera a la regla ingenua de 60 días en el generador con churn (se informa); no hay umbral calibrado por empresa.
- Retención de predicciones diarias por número de corridas (30), no por antigüedad; no hay evaluación vs. realizados de las predicciones
  pasadas (operación `evaluate` pendiente: el motor ya expone las etiquetas maduras para hacerla).
- La reversión no revalida el ranking de la versión restaurada: usa la fuente registrada en su artefacto (popularidad si fue un respaldo o candidato).
- `docs/openapi/predictily-api.yaml` no incluye aún estas rutas (pendiente de la integración).

## 15. Qué necesita la UI (P5)

Selector de horizonte (7/30/90 según el plan) y de comprador/producto; tabla de `GET /customer/predictions` ordenable por probabilidad
con `expected_amount` etiquetado «supuesto», `quality_flags` y el estado de calibración; estados vacíos para `insufficient_data` (sin corrida,
horizonte sin modelo → 422 `horizon_not_available`, comprador genérico o sin historial → `fallback`); ficha del comprador
(`/customers/{id}/profile`): frecuencia, ranking, inactividad con su definición «no es abandono», cambio de comportamiento;
pantalla de evaluación (curva de calibración con `n` por grupo, Brier/log loss/PR-AUC frente a la referencia, ranking vs. popularidad, manifiesto de fechas);
conciliación por producto con componentes y `inconsistent` destacado; mostrar `ranking_source` del ranking (popularidad/modelo/recencia) y botón de reversión por versión (`POST /models/customer/rollback`); botones Entrenar/Predecir (`POST /customer/runs`, 202 + sondeo de `/customer/runs/{id}`).

## 16. Reproducir

```bash
# pruebas (motor; BD de pruebas con las migraciones aplicadas: cd backend && DB_DATABASE=predictily_engine_test_p2 php artisan migrate --force)
cd engine && ENGINE_TEST_DATABASE_URL=postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily_engine_test_p2 \
  OMP_WAIT_POLICY=PASSIVE ENGINE_N_JOBS=1 .venv/bin/python -m pytest -q tests -k customer
# backend
cd backend && DB_DATABASE=predictily_test_p2 php artisan test tests/Feature/Customer
# demo ficticia (vacía `tenants` de la BD indicada: usar una BD de pruebas)
cd engine && ENGINE_DATABASE_URL=postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily_engine_test_p2 ENGINE_N_JOBS=4 \
  .venv/bin/python tests/customer_demo_report.py --out ../docs/architecture/v2/P2-demo-ferreteria-andina.json
```
