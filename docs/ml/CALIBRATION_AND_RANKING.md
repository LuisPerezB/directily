# Customer Core · calibración y ranking (V2.5 §7, §25.2; ADR-V25-11)

> **Alcance y estado.** Define cómo se **evalúa** el Customer Core en el holdout temporal: las métricas con sus fórmulas y denominadores, los segmentos de calibración,
> lift@K frente a la referencia de popularidad/recencia, la utilidad económica del ranking, la calidad de la cantidad por pedido, del número de pedidos y de la ventana, el cold start, la estabilidad
> entre cortes y el drift, y las limitaciones. Qué estima el Customer Core y cómo se construye la ventana está en [`CUSTOMER_REPURCHASE.md`](CUSTOMER_REPURCHASE.md).
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO** o **NO APLICA**.
> **Los números de este documento salen de datos FICTICIOS** (mundos sintéticos de verdad conocida y la demo Ferretería Andina, donde los compradores se asignan al azar a cada
> factura): comprueban el procedimiento, **no validan la precisión comercial de ninguna empresa**. Todo umbral es un valor inicial «a aprobar en el piloto», nunca universal.
>
> **Protocolo (sin cambios):** los cortes maduros se reparten en bloques cronológicos `entrenamiento | H | calibración | H | selección | H | holdout` separados por H días (nivel
> «full»; «lean» sin selección; «reference_only» solo referencia calibrada). El **holdout no elige ni ajusta** modelo, hiperparámetros, variables, calibrador, escala, dispersión, cortes de
> segmento ni niveles de la ventana: solo evalúa el procedimiento completo y aplica reglas versionadas de promoción. Los cortes consecutivos con paso < H comparten parte de su ventana de resultado: **las
> filas no son independientes** y no se publican intervalos de confianza por fila.

## 1. Dónde queda cada métrica

| Qué | Dónde se persiste |
|---|---|
| Probabilidad servida y de la referencia (Brier, log loss, ECE, curva de calibración con n, PR-AUC, ROC-AUC) | `model_evaluations` `h{H}:probability` (vigente) |
| Calibración **por segmento** y horizonte | `model_evaluations` con `level = 'segment'`, `level_key = '<dimensión>:<valor>'`, `horizon_days = H` |
| Nº de pedidos, cantidad por pedido, unidades | `h{H}:expected_orders`, `h{H}:quantity_per_order`, `h{H}:expected_units` |
| lift@K, PR-AUC y utilidad del ranking por fuente | `h{H}:ranking_s05` (y `h{H}:ranking`, vigente, con precision/recall@k) |
| Estabilidad entre cortes y drift | `h{H}:stability` |
| Cortes de segmento, recalibración por segmento (candidato), cold start | `h{H}:segmenter` |
| Cobertura y error temporal de la ventana | `window` (`evaluation_type = 'out_of_sample'`) |
| Resumen de cada corrida | `core_runs.summary.horizons.<H>.s05`, `summary.window`, `summary.s05` (predicción) |
| Quién decidió qué y con qué | `core_runs.evaluation_manifest.s05` |

## 2. Métricas de probabilidad (IMPLEMENTADO / VERIFICADO desde la etapa 2; fórmulas)

Sea `y ∈ {0,1}` la etiqueta de la fila (≥ 1 pedido en (c, c+H]), `p` la probabilidad servida, `n` el número de filas, `ȳ` la tasa base.
- **Brier** = mean[(p − y)²]. **Brier de climatología** = mean[(ȳ − y)²]; **habilidad** = 1 − Brier / Brier_climatología.
- **Log loss** = −mean[y·ln p̃ + (1−y)·ln(1−p̃)] con p̃ = p acotada a [10⁻⁶, 1 − 10⁻⁶].
- **Curva de confiabilidad (reliability bins):** tramos de igual frecuencia ordenados por `p` (hasta `n_bins` tramos, cada uno con ≥ 30 filas; los menores se fusionan), con `n`, probabilidad media
  predicha, tasa observada e intervalo de Wilson al 95 %. **ECE** = Σ n_b·|tasa_b − media_b| / n.
- **PR-AUC** = precisión promedio (average precision) sobre todas las filas; **ROC-AUC** solo informativo (no sustituye la calibración). Con un solo valor de `y` no se calculan (nulo).
- **Estado de calibración:** `uncalibrated` (sin datos para calibrar), `calibrated` (ECE ≤ `ece_alert_threshold` = 0,05) o `degraded`. **No se fabrica un porcentaje con apariencia científica:** sin calibración
  suficiente la probabilidad se marca.
- **Regla de promoción** (`cust-promotion-v1`, vigente): el modelo complejo se sirve si baja el Brier de la referencia ≥ 2 % relativo, su log loss no es peor y su ECE ≤ máx(0,03; ECE_ref + 0,02); si no, se
  sirve la referencia calibrada con rol `fallback`.

## 3. Calibración por segmento y horizonte — `customer/segments.py` (V25-CUS-025)

**Dimensiones** (todas con lo conocido al corte): `frequency` (compras de la relación en 365 días, terciles low/medium/high), `value` (unidades de 180 días × último precio neto, terciles; `unknown` sin precio),
`tenure` (antigüedad de la relación: `new` < 90 d, `established` 90–364, `veteran` ≥ 365) e `history` (`cold_start` si hay menos de `cold_start_min_purchases` = 3 compras; `warm`). Los cortes de
frecuencia y valor son los **terciles del bloque de calibración** (nunca del holdout) y viajan en el artefacto firmado para que la predicción diaria asigne el mismo segmento; un corte solo existe si hay filas por
debajo y por encima. `customer_predictions.segment` = `frequency:<etiqueta>`.

**Por segmento y horizonte, en el holdout:** `n`, positivos, tasa observada, media predicha, razón media/observada, Brier y log loss del servido y de la referencia, ECE, curva con n por tramo (5 tramos) y estado:
- `insufficient_data` si el segmento tiene menos de `segment_min_rows` (200) filas o `segment_min_positives` (20) positivos, o no tiene negativos: **sin cifras de calibración** (solo `n`);
- `calibrated` si |media/observada − 1| ≤ `segment_calibration_tolerance` (0,25) y ECE ≤ `ece_alert_threshold`; si no, `miscalibrated`.
Además, por segmento: error del nº de pedidos servido frente a la referencia de tasa.

**Resultados (datos ficticios).** Demo Ferretería Andina: h30 → 7 segmentos calibrados, 1 mal calibrado, 1 `insufficient_data`; h7 y h90 → 8 calibrados y 1 `insufficient_data`; el cold start de la demo
sale calibrado (razón media/observada 0,97). En un mundo sintético de prueba con 10 % de abandono el cold start sale **mal calibrado** (razón ≈ 2): el modelo sobreestima la recompra de relaciones nuevas, y eso es
justo lo que el segmento hace visible. Pruebas: `test_customer_s05_ranking_segments_drift.py::test_segmenter_*`, `::test_segment_calibration_flags_miscalibration_and_refuses_figures_for_thin_segments`;
integración: `test_training_persists_segment_rows_the_window_and_keeps_the_existing_keys`.

**Recalibración por segmento — DISEÑADO / NO IMPLEMENTADO como servicio; evaluado como candidato.** Se ajusta un desplazamiento logit por segmento que iguala la media calibrada a la tasa observada del bloque de calibración, encogido por
n_pos/(n_pos + 50); se **decide** por el Brier del bloque de selección (≥ 1 % relativo) y el Brier del holdout solo se informa (`informative_only`). La probabilidad servida **no** cambia (`served = false`).

## 4. Ranking frente a popularidad y recencia — `customer/ranking_eval.py` (V25-CUS-027)

**Unidad** = (corte, comprador); candidatos = sus relaciones con compras previas. Fuentes comparadas: **servida** (probabilidad del modelo servido), **referencia** (frecuencia/recencia calibrada), **popularidad**
(compradores del producto en 90 días) y **recencia** (menos días desde la última compra); desempate determinista (producto ascendente).
- **precision@k** (unidades con ≥ k candidatos) = hits en el top-k / k; **recall@k** (unidades con ≥ 1 positivo) = hits / positivos (vigentes, `h{H}:ranking`).
- **lift@k** = precision@k / precisión aleatoria@k, con precisión aleatoria@k = media sobre las mismas unidades de (positivos de la unidad / candidatos de la unidad). Lift 1 = no mejora al azar.
- **PR-AUC del puntaje** sobre todas las filas del holdout y **lift global** del 1 % / 5 % / 10 % superior de las filas (precisión, lift y recall).
- **Comparación:** razón de lift@k, diferencia de PR-AUC y razón de utilidad capturada del servido frente a popularidad, recencia y referencia (`comparison.served_vs_*`).
- **Fuente del ranking servido** (`cust-ranking-v1`, vigente): popularidad por defecto; otra fuente solo si supera a popularidad en precision@5 por ≥ 5 % relativo con ≥ 200 unidades.

**Resultados (demo ficticia, lift@5):**

| H | servido | referencia | popularidad | recencia | PR-AUC servido / popularidad |
|---|---|---|---|---|---|
| 7 | 0,92 | 0,92 | 1,56 | 1,07 | 0,015 / 0,023 |
| 30 | 1,03 | 1,03 | 1,52 | 1,04 | 0,062 / 0,092 |
| 90 | 1,44 | 1,03 | 1,52 | 1,04 | 0,236 / 0,244 |

En la demo el modelo **no supera a popularidad** (por eso `ranking_source = popularity`, como antes), y supera a la referencia y a la recencia solo en h90. Es un resultado honesto de datos ficticios sin recompra real.
Pruebas: `test_lift_at_k_is_precision_over_random_precision_per_unit`, `test_global_lift_and_pr_auc_against_a_random_score`, `test_ranking_extension_compares_the_served_source_with_popularity_and_recency`.

## 5. Utilidad económica del ranking (V25-CUS-028)

Para cada unidad con **más de k** candidatos (si tiene k o menos, el top-k los contiene a todos y el orden no aporta nada; incluirlas ocultaría la diferencia entre fuentes), se toman los k mejor
puntuados (k = `ranking_primary_k` = 5) y se suma lo **realizado** en (c, c+H]:
- **unidades** = Σ cantidad realizada; **importe a precio al corte** = Σ cantidad realizada × último precio neto conocido AL CORTE de la relación (nunca el precio de después);
- **margen** = Σ cantidad × (precio − costo unitario) **solo con costo unitario conocido al corte**; sin él es `null` con la limitación `margin_limitation` y no se aproxima con el costo vigente.
- **Razón de captura** = captado / óptimo, donde el óptimo es lo captado por los k candidatos de mayor valor realizado de cada unidad; se informa también la razón entre fuentes.
**Resultados (demo ficticia, unidades capturadas / óptimo, top-5 de unidades con > 5 candidatos):** h7 servido 0,21 frente a 0,28 de popularidad; h30 0,22 frente a 0,29; **h90 0,33 frente a 0,30**.
**Estado:** unidades e importe — IMPLEMENTADO / VERIFICADO (`test_economic_utility_counts_realized_units_amount_and_margin_only_with_known_cost`); **margen — BLOQUEADO**: la lectura de compras del Customer Core no incluye costo unitario conocido al corte
(requiere ampliar `db/customer_repository.py`, fuera de este paquete); la función lo soporta y se prueba con costo sintético. Las ventanas solapadas hacen que las unidades de cortes consecutivos no sean independientes.

## 6. Cantidad por pedido, nº de pedidos y unidades

- **Cantidad (por pedido y condicionada):** n, MAE = mean|ŷ − y|, **sesgo** = Σ(ŷ − y)/Σy, **WAPE** = Σ|ŷ − y|/Σy (nulo si Σy = 0), RMSE y mediana del error absoluto, más la **cobertura del intervalo conformal** del 80 % y su
  anchura relativa. La cantidad por pedido se mide solo sobre ventanas con compra.
- **Nº de pedidos:** sobre TODAS las filas del holdout (incluye ceros): sesgo = Σμ/Σn − 1, MAE, WAPE, RMSE y **desvianza de Poisson media** = 2·mean[n·ln(n/μ) − (n − μ)] (0·ln 0 = 0), que gobierna la
  promoción (§3 de `CUSTOMER_REPURCHASE.md`). **Se evalúan sobre el E(N) servido FINAL** (hurdle P × E(N | N ≥ 1) con el anidamiento entre horizontes sobre las mismas filas del holdout), y se informan además sin
  anidar (`holdout_pre_nesting`), el factor condicionado sobre los positivos (`conditional_holdout`), la descomposición del sesgo en el de P y el del factor condicionado (`bias_decomposition`), la fracción de filas con E(N) = P
  exacto (`equal_to_probability_share`, 0 por construcción) y el efecto del anidamiento (`nesting`: filas y masa corregidas).
- **Unidades:** E(pedidos) × E(cantidad por pedido) frente a P × E(cantidad | compra) y frente a la referencia, mismas métricas.
**Resultados (demo ficticia, medidos con el hurdle):** nº de pedidos — se sirve la referencia de tasa condicionada en h7/h30/h90 (el retador mejora la desvianza 0,05 % / 0,13 % / 0,82 %, bajo el 2 % exigido); desvianza 0,128 / 0,358 / 0,659, sesgo del E(N) servido −5,8 % / −6,9 % / +11,4 % (dentro del tope de 15 %); anidamiento: 0 % de las filas del holdout en h7 y h30 y 5,9 % (0,54 % de la masa) en h90;
cantidad por pedido — se sirve la referencia en h7 y h90 y Gamma en h30 (MAE 3,05 frente a 3,14); cobertura del intervalo 76–88 %.

## 7. Cold start y estabilidad entre cortes

- **Cold start (V25-CUS-016):** el segmento `history:cold_start` (< 3 compras) tiene sus propias métricas de probabilidad y de nº de pedidos, y su estado. La ventana de esas relaciones es `insufficient_data` si tienen menos de 2 intervalos propios.
- **Estabilidad entre cortes:** por corte del holdout (con ≥ `stability_min_rows` = 50 filas) se informa el Brier del servido y de la referencia; resumen = desvío del Brier servido entre cortes y fracción de cortes en que el servido no es peor
  que la referencia (demo: 100 % en los tres horizontes). Para la ventana se informa la cobertura por corte (desvío, mínimo y máximo).

## 8. Drift (V25-CUS-031) — `customer/drift.py`

- **PSI** = Σ (a_i − e_i)·ln(a_i / e_i) con tramos por deciles de la distribución de referencia de la probabilidad servida (proporciones acotadas a ≥ 10⁻⁴). Convención: < 0,10 estable, 0,10–0,25 cambio moderado (`warn`), ≥ 0,25 cambio
  mayor (`alert`). Con pocas filas el PSI crudo se infla por ruido; el estado usa el **PSI ajustado** = máx(0, PSI − (tramos − 1)·(1/n_ref + 1/n_act)). Se publican ambos.
- **Tasa observada:** cambio relativo (b − a)/a entre dos grupos de cortes; `alert` si |cambio| ≥ `drift_rate_rel_alert` (0,5) y ambos grupos tienen ≥ `drift_min_positives` (30) positivos; con menos, `insufficient_data`.
- **Dónde:** entrenamiento (calibración → holdout; cortes consecutivos del holdout) y predicción diaria (distribución actual contra la guardada en el artefacto, por horizonte). Una alerta agrega una limitación a la corrida; **no cambia el estado de calidad**.
- **Demo ficticia:** PSI ajustado calibración → holdout de 0,13 (h7, `warn`), 0,08 (h30) y 0,09 (h90); en la predicción, PSI crudo < 0,02 en los tres horizontes (estable).
Pruebas: `test_psi_is_zero_for_the_same_distribution_and_grows_with_the_shift`, `test_psi_adjusted_discounts_sampling_noise_in_small_samples`, `test_drift_between_flags_probability_and_observed_rate_changes_with_enough_positives`, `test_drift_across_cutoffs_reports_the_worst_consecutive_shift`.

## 9. Ventana: cobertura y error temporal (V25-CUS-029)

Definición y método en `CUSTOMER_REPURCHASE.md` §6. Métricas: **cobertura de [P10, P90]** = fracción de relaciones evaluables (P90 ≤ seguimiento) cuyo `T` cae dentro (nominal ≥ 80 %), masa por debajo del P10 y por encima del P90 (nominal ≤ 10 % cada una),
anchura media en días, fracción con `T ≤ P50` (nominal ≈ 50 %) y **error absoluto del P50 en días** (MAE, mediana y sesgo) sobre las que compraron dentro del seguimiento, comparados con la referencia ingenua y por estado, por cantidad de intervalos propios y por corte.
El error del P50 es **condicionado** (excluye a las que tardan más que el seguimiento) y por eso es optimista.
**La evidencia se cuenta en relaciones y compradores DISTINTOS**, no en filas (las filas de una relación en cortes consecutivos no son independientes): mínimos globales de 100 relaciones, 30 compradores y 4 cortes de
evaluación; por grupo (1–3, 4–7 y 8+ intervalos propios, y estado `overdue`) 50 relaciones y su propia cobertura ≥ 0,70. Un grupo o la ventana global sin esa evidencia o bajo la tolerancia **no publican cifras**
(`window_status = insufficient_data`, cuantiles nulos). Se publica también `not_assessable_share` (fracción de las filas con cifras evaluadas cuyo P90 excede el seguimiento validado; la bandera `window_beyond_validation` marca esas
filas al servir). Los niveles recalibrados se ajustan con cortes cuyo seguimiento termina antes del primer corte holdout de todo horizonte; la evaluación puede coincidir en el tiempo con un holdout pero solo se evalúa
(`CUSTOMER_REPURCHASE.md` §6).

## 10. Limitaciones

1. Ventanas de resultado solapadas entre cortes consecutivos: filas no independientes; sin intervalos de confianza por fila; el bootstrap por bloques de corte (vigente) es informativo.
2. Los umbrales (segmentos, tolerancias, PSI, cobertura) son de diseño y quedan **a aprobar en el piloto**; con pocas filas el segmento calla en vez de mostrar una cifra inestable.
3. Un abandono baja la cobertura de la ventana y el lift observado; no se separa la probabilidad de volver de la de cuándo. La evaluación del P50 es condicionada.
4. Los precios son los últimos netos de la relación conocidos al corte (supuesto explícito); la sensibilidad al precio es **asociativa**, nunca causal.
5. Sin costo unitario al corte no hay margen (BLOQUEADO). Sin datos reales de compradores, ninguna cifra es una validación comercial (BLOQUEADO).
6. La recalibración por segmento está evaluada pero no servida; `segment` etiqueta el segmento de calibración de la fila.
7. El sesgo de E(N) = P × E(N | N ≥ 1) hereda el de P en el holdout (deriva temporal): se publica la descomposición; un conteo condicionado complejo no se promueve si deja el E(N) final fuera de `orders_max_abs_bias`.
