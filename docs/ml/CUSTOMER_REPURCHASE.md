# Customer Core · recompra por comprador × producto (V2.5 §7, §25.2; ADR-V25-11)

> **Alcance y estado.** Este documento describe qué estima el Customer Core para cada comprador × producto y horizonte, con qué modelos, cómo se define el
> evento, cómo se calcula la **ventana probable de recompra**, qué ocurre con la calidad de datos degradada, los **fixtures funcionales** (las cubetas de huevo
> de V2.5 §7 y un cliente con cambios de precio) y el estado de cada requisito. La evaluación (fórmulas de las métricas, segmentos, lift@K, utilidad económica,
> drift y limitaciones) está en [`CALIBRATION_AND_RANKING.md`](CALIBRATION_AND_RANKING.md). El diseño base de la etapa 2 sigue en
> `docs/architecture/v2/P2-comportamiento.md`; las decisiones, en ADR-V25-11 (cantidades de Customer) y ADR-V25-05 (gates de calidad).
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO** (no se puede
> construir o verificar sin datos reales o sin un cambio fuera de este paquete) o **NO APLICA**. **Todos los resultados numéricos de este documento salen de datos
> FICTICIOS (mundos sintéticos de verdad conocida y la demo Ferretería Andina): comprueban el procedimiento, no validan la precisión comercial de ninguna empresa.**
> Todo umbral es un valor inicial de diseño con su justificación y queda **a aprobar en el piloto** con datos reales; ninguno es universal.
>
> Pruebas (desde un directorio **sin `.env`**, o desde `engine/`, cuyo `conftest` anula `env_file`):
> `ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<bd_de_pruebas> <venv>/bin/python -m pytest -q engine/tests/unit/test_customer_s05_*.py engine/tests/integration/test_customer_s05_integration.py`
> (la BD se migra con `cd backend && DB_DATABASE=<bd_de_pruebas> php artisan migrate:fresh --force`; las pruebas unitarias no necesitan la base).

## 1. Qué sale por comprador × producto y horizonte

Horizontes del Customer Core: **7, 14, 15, 30 y 90 días** (subconjunto del catálogo único de `platform/config.py`; 60 queda fuera). Cada fila de
`customer_predictions` trae, además de lo que ya se servía, las cantidades que exigen V2.5 §7 y §25.2:

| Columna | Qué es | Modelo | Estado |
|---|---|---|---|
| `purchase_probability` | P(≥ 1 pedido en (corte, corte+H]) | referencia frecuencia/recencia · logística · LightGBM, calibrada en el bloque de calibración; regla de promoción versionada | vigente (sin cambios) |
| `expected_orders` | **E(nº de pedidos en H)** = P × E(N \| N ≥ 1) (hurdle) | P servida × **conteo condicionado propio** (1 + Poisson/LightGBM sobre los pedidos adicionales, ajustado solo con los positivos; o la referencia de tasa) | IMPLEMENTADO / VERIFICADO (§3) |
| `quantity_if_purchase`, `quantity_lo/hi` | suma de TODAS las compras de la ventana, condicionada a ≥ 1 compra, con intervalo conformal 80 % | referencia histórica · LightGBM (objetivos comparados) | vigente (sin cambios) |
| `quantity_per_order` | **E(cantidad por pedido \| ocurre)** | referencia (últimas 5 compras) · LightGBM ponderado por nº de pedidos | IMPLEMENTADO / VERIFICADO (§4) |
| `expected_quantity` | P × `quantity_if_purchase` (el cero de «no compra» incluido) | — | vigente, se conserva por compatibilidad |
| `expected_units` | **E(nº pedidos) × E(cantidad por pedido)** bajo independencia documentada | — | IMPLEMENTADO / VERIFICADO (§5) |
| `window_p10_days`, `window_p50_days`, `window_p90_days`, `window_status` | **ventana**: cuantiles del tiempo hasta la siguiente compra contado desde `as_of`; nunca un día exacto. **`window_status` refleja la validación**: sin evidencia de cobertura no hay cifras | empírica por intervalos propios con suavizado jerárquico y niveles recalibrados | IMPLEMENTADO / VERIFICADO (§6) |
| `segment` | segmento de calibración de la fila (`frequency:low\|medium\|high`) | cortes de terciles del bloque de calibración | IMPLEMENTADO / VERIFICADO |
| `quality_flags` | banderas por fila; las nuevas están en §14 (`orders_nested_adjusted`, `cold_start`, `window_beyond_validation`, `s05_unavailable`, `s05_failed`) | — | IMPLEMENTADO / VERIFICADO |

**Compatibilidad (contrato de W3).** Las salidas vigentes no cambian: con S05 apagado (`s05_enabled = false`) y encendido, la probabilidad, la cantidad condicionada, sus
intervalos, `expected_quantity`, `expected_amount` y el ranking son **idénticos bit a bit**
(`test_customer_s05_pipeline.py::test_s05_does_not_change_the_served_probability_quantity_intervals_or_ranking`), y contra el código de la base de W3 (`4eb34f4`) sobre el
mismo mundo sintético: 1365 filas × 13 columnas y los rankings idénticos (comparación manual de este paquete, no automatizada). En la demo Ferretería Andina los modelos
promovidos son los mismos que antes: **h7 y h30 sirven la referencia; h90 sirve la logística** (Brier 0,138295 frente a 0,142273 de la referencia), con el mismo Brier de
holdout, la misma cantidad condicionada y la misma fuente de ranking (popularidad). Las métricas y columnas nuevas **se agregan**; no se modificó ninguna migración.

## 2. Definiciones

- **Unidad de observación.** La relación comprador × producto sumando todas las sucursales (sin doble conteo), en un corte `c` de una rejilla cronológica. Un comprador
  frecuente con varios productos no se trata como varios compradores: cada fila referencia el par; las recomendaciones pueden agruparse por comprador para el contacto.
- **Pedido.** Un **día de compra de la relación**: todas las líneas del mismo comprador y producto en el mismo día son UN pedido. Dos documentos el mismo día no se
  cuentan por separado (limitación declarada en `limitations`). `N_H` = nº de pedidos en (c, c+H].
- **Evento de P.** ≥ 1 compra válida (sin anuladas ni notas de crédito; devoluciones aparte) en (c, c+H]. Etiqueta **madura** solo si c+H ≤ último día completo de ventas
  (cobertura declarada o, sin ella, última venta). **Censura:** si el producto estuvo sin stock en la sucursal habitual más del `1 − min_availability_share` de la
  ventana, la fila se excluye (ni negativo ni positivo).
- **Población.** Relaciones con ≥ `min_relation_purchases` compras previas y última compra dentro de `candidate_max_recency_days`. Compradores genéricos, anónimos y
  clientes sin historial del producto **no** reciben predicción individual (su demanda queda en el residual de la reconciliación).
- **Tiempo hasta la siguiente compra `T`.** Días desde `c` hasta el primer pedido posterior de la relación (entero ≥ 1). La ventana son sus cuantiles P10/P50/P90.
- **Todo es conocido al corte.** Las variables y la ventana usan eventos tal como se conocían ese día (lectura as-of de W1, ADR-V25-23); las etiquetas, la verdad disponible.

## 3. Número esperado de pedidos E(N_H) — `customer/orders.py`

**Interpretación del arquitecto de ADR-V25-11** (decisión de la revisión de W3-C): el número esperado de pedidos es un **hurdle coherente por construcción**,
**E(N_H) = P(N_H ≥ 1) × E(N_H | N_H ≥ 1)**. El conteo condicionado es un **modelo propio** (no se deriva de P); **P solo compone el hurdle**. Sustituye a la versión
anterior (conteo sobre todas las filas con la cota fila a fila E(N) = máx(μ, P)), que convertía E(N) en P en 22–67 % de las filas, sesgaba hacia arriba (+7,7 % frente a −3,3 % sin cota) y
decidía el gate de sesgo sin la cota.

- **Factor condicionado E(N | N ≥ 1) ≥ 1, ajustado SOLO con los casos positivos** (relaciones con ≥ 1 pedido en la ventana; los ceros no entran al ajuste):
  1. **modelo:** `1 + Poisson` (LightGBM, log-enlace) sobre los pedidos adicionales `N − 1`; equivalente a un Poisson truncado en cero con media desplazada; E(N | N ≥ 1) = 1 + μ, con μ ≥ 10⁻⁴;
  2. **referencia de tasa** (alternativa simple): media de la Poisson truncada en cero con λ = tasa × H, `λ / (1 − e^{−λ})`, donde la tasa son los pedidos de los últimos 365 días / exposición
     (acotada a [30, 365] días), con piso de 0,5/365 por día.
  La **escala** (Σ(N − 1) reales / Σ exceso predicho, acotada a [0,5; 2]) y la **dispersión** de la binomial negativa de los pedidos adicionales (Var = μ + α·μ², método de momentos, acotada a
  [0, `orders_dispersion_max`]) salen de los **positivos del bloque de calibración**. El exceso servido nunca baja de 10⁻⁴: E(N | N ≥ 1) > 1 estrictamente.
- **E(N) > P en TODAS las filas, sin cotas ni subidas a P.** La fracción de filas con E(N) = P exacto es 0 (prueba de pipeline: < 5 %; medido: 0,0 en los tres horizontes de la demo y del mundo de prueba).
  Ya no existe la bandera `orders_raised_to_probability`.
- **Única corrección de coherencia que queda: el anidamiento entre horizontes.** Como (c, c+7] ⊂ (c, c+30] ⊂ (c, c+90], E(N_H) no puede decrecer con H: se aplica el máximo acumulado sobre el
  E(N) FINAL (con P ya anidada por el máximo acumulado vigente) y se **MIDE**:
  en entrenamiento, `finalize_s05` calcula el E(N) final de las mismas filas del holdout encadenando los modelos de cada horizonte menor, y publica por horizonte `nesting` = fracción de filas y masa
  (Σ aumento / Σ E antes de corregir) corregidas, separadas en las debidas al anidamiento de P y al de E, y el sesgo antes y después; en la predicción, `summary.s05.orders_nested` publica
  `corrected_rate_h{H}` y `corrected_mass_share_h{H}` y la fila corregida lleva `orders_nested_adjusted`.
  **Medido en la demo ficticia (holdout):** h7 y h30 sin corrección (0 % de filas); h90 5,9 % de las filas y 0,54 % de la masa. En la predicción diaria de la demo, 0 % de filas en los tres horizontes.
- **Promoción y gate de sesgo sobre el E(N) SERVIDO final.** El conteo condicionado complejo se sirve solo si, con el E(N) final (hurdle + anidamiento sobre las mismas filas del holdout, todas, incluidos los
  ceros): (a) baja la desvianza de Poisson de la referencia ≥ `orders_min_rel_deviance_improvement` (2 %) **y** (b) su sesgo agregado Σ E(N)/Σ N − 1 cumple `|sesgo| ≤ orders_max_abs_bias` (15 %). Si no, se sirve
  la referencia con rol `fallback`. `finalize_s05` repite la decisión con el E(N) final (el gate por horizonte de `train_horizon` es un primer filtro) y, si la cadena real de modelos servidos deja a un horizonte
  complejo fuera del tope, lo vuelve a la referencia. El holdout solo aplica esta regla versionada; no ajusta nada. `served.bias_within_bound`, `served.equal_to_probability_share`, el gate final y su
  evidencia quedan en `h{H}:expected_orders` y en el manifiesto.
- **El sesgo de E(N) hereda el de P.** (1 + sesgo E) = (1 + sesgo de P: Σ P / Σ y − 1) × (1 + sesgo del factor condicionado). Si P está sesgada en el holdout (deriva temporal entre calibración y holdout), ningún conteo
  condicionado lo corrige: en un mundo sintético de prueba P quedó 23 % por debajo de lo observado a h7 mientras el factor condicionado tenía +2,6 % de sesgo sobre los positivos, y el E(N) servido (referencia) tuvo −21 %, fuera
  del tope; la corrida lo declara en `limitations` por horizonte, publica la descomposición (`bias_decomposition`) y no presenta el valor como calibrado.
- **Medido en la demo ficticia:** en los tres horizontes se sirve la referencia de tasa condicionada (el retador mejora la desvianza solo 0,05 % / 0,13 % / 0,82 %, bajo el 2 % exigido), con sesgo del E(N) servido
  −5,8 % / −6,9 % / +11,4 % (dentro del tope), desvianza 0,128 / 0,358 / 0,659 y fracción de filas con E(N) = P de 0.
- **Interruptor y compatibilidad de artefactos:** el artefacto de pedidos lleva `form = "hurdle-v1"`; uno con otra forma (p. ej. el conteo con cota de la primera versión de W3-C) **no se sirve** (columnas de pedidos nulas).
- Pruebas: `test_customer_s05_orders.py` (Poisson truncado en cero con su media simulada; hurdle > P en todas las filas con escala 0,5; exceso mínimo; anidamiento con su masa; ajuste solo con positivos —cambiar las variables de las
  filas sin compra no altera el modelo—; promoción con señal, sin señal y con sesgo fuera de tope aunque mejore la desvianza; nivel `reference_only`),
  `test_customer_s05_pipeline.py::test_expected_orders_are_a_coherent_hurdle_not_a_function_of_p_and_rarely_equal_to_it`, `::test_served_orders_bias_is_within_the_bound_or_the_complex_model_is_not_promoted`,
  `::test_nesting_correction_is_measured_per_horizon_in_training_and_prediction`.

## 4. Cantidad por pedido E(cantidad | ocurre) — `customer/per_order.py`

- **Definición:** en ventanas con ≥ 1 pedido, Σ cantidades / nº de pedidos; cada ventana pesa por su nº de pedidos (el promedio ponderado es la cantidad de UN pedido). Es
  distinta de `quantity_if_purchase` (la suma de toda la ventana), que se conserva.
- **Candidatos:** referencia histórica (media de las últimas 5 compras de la relación, con respaldos) y LightGBM con los mismos objetivos que la cantidad condicionada (L2, L2
  sobre log1p con corrección de Duan, Tweedie, Gamma y Poisson solo con cantidades enteras), con pesos.
- **Protocolo:** escala (Σreal/Σpredicho acotado) e **intervalo conformal multiplicativo del 80 %** con el bloque de calibración; objetivo elegido en el bloque de selección (en
  «lean», en calibración sin escalar) entre los de |sesgo| ≤ 15 %; el holdout aplica la regla versionada (mejora de MAE ≥ `promotion_qty_min_rel_mae_improvement` y sesgo
  aceptable) y mide la cobertura real del intervalo.
- **El intervalo no tiene columna** en `customer_predictions` (el contrato de W3 no la define): viaja como factores relativos (`per_order_interval_ratio` en `summary.served` y
  `exp(interval_log_q)` en el artefacto) y la predicción los materializa como `quantity_per_order_lo/hi` solo en memoria. Pendiente para el arquitecto/W3-D (§13).
- Pruebas: `test_customer_s05_orders.py::test_per_order_quantity_weights_by_orders_and_serves_the_reference_when_it_does_not_improve` y los fixtures (§9).

## 5. Unidades esperadas y la diferencia con `expected_quantity`

`expected_units = E(nº de pedidos) × E(cantidad por pedido | ocurre)` **bajo independencia** entre el nº de pedidos y su cantidad dentro de la relación (supuesto que no se
estima ni se contrasta con covarianza). `expected_quantity = P × E(cantidad total de la ventana | compra)` se conserva. Difieren porque:
(1) la segunda supone **un** evento en el horizonte (V2.5 §7 B lo restringe a eso) y multiplica una cantidad que ya suma varios pedidos por la probabilidad de al menos uno;
(2) la primera modela cuántos pedidos caben y cuánto trae cada uno, sin subcontar compras repetidas (V2.5 §25.2).
Ambas se evalúan en el holdout contra lo realizado (`h{H}:expected_units` y `h{H}:expected_quantity`). **Medido en la demo ficticia** (WAPE / sesgo; con E(N) hurdle y anidamiento): pedidos × cantidad por pedido
h7 1,75 / −22,7 %, h30 1,67 / −22,6 %, h90 1,64 / +5,2 %; P × cantidad condicionada h7 1,75 / −22,8 %, h30 1,63 / −26,8 %, h90 1,59 / −3,5 %: magnitudes del mismo orden, con menor sesgo de la primera en h30 y WAPE
algo mejor de la segunda en h30 y h90; no se afirma que una sustituya a la otra. El `expected_amount` vigente sigue calculándose desde `expected_quantity`.

## 6. Ventana probable de recompra — `customer/window.py`

La ventana es la **distribución del tiempo hasta la siguiente compra**, contada desde `as_of`, resumida en cuantiles P10/P50/P90 (días enteros ≥ 1). **Nunca un día exacto.**

**Método (empírico con suavizado jerárquico):**
1. Los `window_gap_k` (12) últimos intervalos propios de la relación forman la distribución empírica; cada intervalo se reparte en ±½ día (se miden en días enteros, así que una
   serie perfectamente regular da un rango de 1–2 días, no un punto).
2. Se suaviza con una exponencial a priori de `window_prior_weight` (2) pseudo-observaciones cuya media es **jerárquica**: otras relaciones del mismo comprador en la misma
   categoría → otros compradores de la categoría → promedio de la empresa (cada nivel pesa como máximo `window_prior_cap` (6) observaciones y excluye lo ya contado en el anterior).
3. Se condiciona a que la compra aún no ocurrió: con recencia `r`, P(T ≤ t) = [F(r+t) − F(r)] / [1 − F(r)]. El cuantil de nivel `q` es el menor `t` entero con esa probabilidad ≥ q.
4. **Recalibración de niveles** (por cantidad de intervalos propios: 1–3, 4–7, 8+). Con pocos intervalos el cuantil empírico es ruidoso y la cobertura real queda por debajo de la
   nominal. Con **cortes de ajuste** se elige, por grupo, el nivel de la distribución suavizada que alcanza el 10 / 50 / 90 % observado (rejillas 0,01–0,10 / 0,30–0,80 / 0,90–0,99). Un corte de
   ajuste cumple DOS condiciones: su seguimiento termina antes del primer corte de evaluación **y antes del primer corte del bloque holdout de TODO horizonte entrenado** (ni el corte ni su
   seguimiento tocan un holdout; los inicios de holdout se leen del manifiesto de cada horizonte y se registran en `manifest.s05.window.holdout_starts`). Los cortes de EVALUACIÓN solo se evalúan:
   pueden coincidir en el tiempo con un holdout, pero no ajustan nada. Sin relaciones de ajuste suficientes (`window_group_min_relations` DISTINTAS por grupo), los niveles quedan nominales y se declara.

**Estados (`window_status`) — la columna que consumen la API y la UI refleja la validación:**

| Estado | Cuándo | Cifras |
|---|---|---|
| `estimated` | ≥ `window_min_own_gaps` (2) intervalos propios, producto disponible **y la validación respalda a su grupo** | P10/P50/P90 |
| `insufficient_data` | menos de `window_min_own_gaps` intervalos propios (cold start de la relación) **o** la validación global es `insufficient_data`/`not_validated` **o** el grupo de la fila (1–3, 4–7, 8+ intervalos propios; y, si está atrasada, el grupo `overdue`) no está `validated` | **ninguna** (nulos) |
| `overdue` | la brecha actual supera el cuantil `window_overdue_quantile` (0,9) de sus intervalos más una holgura (la de la inactividad): la ventana es una cola extrapolada, **y el grupo `overdue` está validado** | P10/P50/P90, anchas. **No es abandono** |
| `censored` | el producto está sin stock en la sucursal habitual al corte: la ausencia de compra no es información | **ninguna** |

Una fila a la que el cálculo en bruto le dio cuantiles pero la validación no respalda **sale `insufficient_data` con los cuantiles nulos** (`apply_validation`); `summary.s05.window.withheld` cuenta cuántas por motivo
(`global_not_validated`, `gaps_1_3_insufficient_data`, `overdue_not_validated`…) y la corrida lo declara en `limitations`.

La ventana no depende del horizonte (la misma en las cinco filas de la relación) y usa solo lo conocido al corte (`test_customer_s05_window.py::test_window_uses_only_what_was_known_at_the_cutoff`).

**Validación (cobertura y error temporal).** En cortes de **evaluación** con seguimiento maduro de `window_eval_days` (90; se duplica hasta 365 si no hay evidencia suficiente), se mide:
cobertura de [P10, P90] (nominal 80 %) sobre las filas cuyo P90 cabe en el seguimiento, masa por debajo del P10 y por encima del P90, fracción con T ≤ P50 y error absoluto del P50 en
días sobre las que compraron dentro del seguimiento. Se compara con la referencia ingenua (cuantiles de los intervalos propios menos la recencia, sin suavizar ni condicionar), por
estado, por grupo de intervalos propios y por corte (estabilidad). **La evidencia se cuenta en relaciones y compradores DISTINTOS, no en filas** (las filas de una misma relación en cortes consecutivos
no son independientes): la validación global exige ≥ `window_eval_min_rows` (100) relaciones distintas, ≥ `window_eval_min_customers` (30) compradores distintos y ≥ `window_eval_min_cutoffs` (4) cortes de
evaluación; cada grupo (1–3, 4–7, 8+ intervalos propios y el estado `overdue`) exige ≥ `window_group_min_relations` (50) relaciones distintas **y su propia cobertura** ≥ nominal − `window_coverage_tolerance` (0,10).
**Validada** = cobertura global ≥ 0,70 con esa evidencia; **no validada** si la cobertura no llega; **no concluyente** (`insufficient_data`) si falta evidencia. Cada grupo recibe su propio veredicto
(`summary.window.groups`, `model_evaluations` `window`, artefacto `s05.window.validation.groups`).

**Parte no evaluable.** Una fila solo es evaluable si su P90 cabe en el seguimiento con que se validó (se sabe si la compra cayó dentro o fuera). `not_assessable_share` = fracción de las filas con cifras
evaluadas cuyo P90 excede el seguimiento (o no existe dentro de `window_max_days`); se publica en `summary.window`, en el artefacto, en el manifiesto y en `limitations`, y **toda fila servida con
P90 mayor que el seguimiento validado lleva la bandera `window_beyond_validation`** (su cola no se pudo evaluar). El resultado viaja en el artefacto firmado (`s05.window`), en `summary.window`, en el manifiesto
(`s05.window`: cortes de ajuste y de evaluación, inicios de holdout, grupos) y en `model_evaluations` (`level_key = 'window'`, `evaluation_type = 'out_of_sample'`, `coverage_80` = cobertura de [P10, P90],
`mae`/`bias` = error del P50 en días).

**Qué mide y qué no.** Un abandono (la relación no vuelve) cuenta como compra fuera de la ventana y baja la cobertura: la ventana responde «cuándo, si vuelve», y la
probabilidad a H responde «si». La evaluación es **condicionada** al seguimiento y sobrestima el acierto del P50 (excluye las que tardan más). La ventana **no es un modelo de supervivencia**
con censura: V25-CUS-005 (discrete-time/hazard) queda **DISEÑADO / NO IMPLEMENTADO** (§11).

**Resultados sobre datos ficticios (no validan ninguna empresa).** Con las reglas de evidencia actuales (medido):

| Datos | Resultado de la validación | Qué se publica |
|---|---|---|
| Mundo sintético de prueba (130 compradores, 800 días, 15 % de abandono; seguimiento 90 d, 12 cortes de evaluación) | `validated`: 243 relaciones evaluables, cobertura 0,77 en el grupo 8+ intervalos (211 relaciones); los grupos 1–3, 4–7 y `overdue` quedan `insufficient_data` (18, 20 y 34 relaciones < 50) | solo filas del grupo 8+ y no atrasadas; el resto `insufficient_data` sin cifras. `not_assessable_share` = 58 % |
| Mundo de 450 días (130 compradores, 9 productos, 15 % de abandono; 8 cortes de evaluación) | `validated`: 237 relaciones y 97 compradores evaluables, cobertura 0,72; grupos 4–7 (137 relaciones) y 8+ (107) validados; 1–3 (41) y `overdue` (28) `insufficient_data` | filas de los grupos 4–7 y 8+ no atrasadas; el resto sin cifras |
| Proceso de renovación con intervalos uniformes 8–16 días (prueba) | `validated`, cobertura ≥ 0,70, referencia ingenua ≤ servida | cifras en los grupos validados |
| **200 días de historia** (prueba de BD de extremo a extremo) | `insufficient_data`: ningún corte tiene seguimiento maduro | **ninguna cifra**: más de cien filas con probabilidad, pedidos y cantidad, todas con `window_status = insufficient_data` |
| **Demo Ferretería Andina** (compradores asignados al azar a cada factura; no hay recompra real por comprador) | `not_validated`: seguimiento ampliado a 180 d, 127 relaciones evaluables, cobertura **0,40** frente a 0,09 de la referencia ingenua; `not_assessable_share` 97 % | **ninguna cifra**: 4 649 filas con cuantiles en bruto se retienen (`global_not_validated`) |

La cobertura mejora con la cantidad de intervalos propios (grupos 8+ > 4–7 > 1–3), y la recalibración la acerca a la nominal en los mundos de prueba (de 0,59–0,66 a 0,72–0,76 en la medición anterior de este paquete). En la demo la
ventana **no se valida y la corrida lo dice** (la demo no contiene un proceso de recompra por comprador; es el comportamiento esperado, no un hallazgo comercial).
- Pruebas: `test_customer_s05_window.py` (cuantiles y estados, condicionamiento a la recencia, suavizado jerárquico hacia categorías rápida/lenta, niveles por grupo, validación por cobertura en un proceso de renovación, separación ajuste/evaluación, **ajuste anterior a todo holdout con la restricción activa (no tautológica)**, ampliación del seguimiento en ciclos lentos, **mínimo de relaciones y compradores DISTINTOS**, **`window_status` y cuantiles nulos sin validación o con un grupo sin validar (`apply_validation`)**, **200 días de historia → sin cifras**, mejora de la cobertura con la recalibración, `fit_levels`, una sola lectura de eventos por corte) y `test_customer_s05_pipeline.py::test_window_figures_are_published_only_where_the_validation_backs_them`, `::test_rows_whose_p90_exceeds_the_validated_follow_up_are_flagged`; de extremo a extremo: `test_customer_s05_integration.py::test_a_validated_window_publishes_figures_only_for_validated_groups_and_declares_what_it_cannot_assess`, `::test_two_hundred_days_of_history_publish_no_window_figures_end_to_end`.

## 7. Historial insuficiente, reactivados, abandonados, sustitutos e identidad

| Caso | Tratamiento | Estado |
|---|---|---|
| Relación con pocos intervalos propios (cold start) | `window_status = insufficient_data` sin cifras; bandera `cold_start` (menos de `cold_start_min_purchases` = 3 compras); calibración y errores de pedidos reportados aparte (`history:cold_start`); la probabilidad y el conteo siguen saliendo con su estado de calibración | IMPLEMENTADO / VERIFICADO (`test_cold_relation_has_no_window_and_says_so`; segmento `history:cold_start` en el pipeline) |
| Comprador/relación nueva sin historial del producto | no es candidato: sin predicción individual; queda en el residual de la reconciliación | vigente |
| Reactivado (vuelve tras un largo silencio) | su recencia grande lo marca `overdue`; el modelo ve `rel_recency_days` y la frecuencia previa; no hay un estado «reactivado» propio | PARCIAL: señal indirecta |
| Abandonado | **no se asigna abandono por un único retraso**; la inactividad (`customer_activity`) usa el patrón propio y es independiente de la ventana | vigente (V2.5 §25.2) |
| Productos sustitutos | la relación se mide por producto; no se modela el reemplazo entre SKU | DISEÑADO / NO IMPLEMENTADO |
| Cambio de identidad (IDs inestables, fusiones, cambios de código) | el gate de W2-C degrada a agregado (§8) | IMPLEMENTADO / VERIFICADO |

## 8. Degradación a agregado — `customer/degrade.py`

Si el gate de calidad de W2-C (`quality/`) para `customer` da **DEGRADE** (IDs de comprador o de producto inestables, fracción de ventas de compradores identificados baja, códigos
nuevos de producto) o **BLOCK**, el Customer Core **no entrena ni predice a nivel comprador** y **no escribe ninguna fila individual** (`customer_predictions`,
`customer_product_rankings`, `customer_activity`); cada horizonte queda `insufficient_data` con el código `dq_individual_recommendations_disabled` (o `dq_gate_block`) y el motivo.
Lo que W3-C añade (solo con `s05_enabled` y solo cuando el gate deshabilita lo individual; en cualquier otro caso no se calcula nada de esto):
- el bloque **`summary.individual_recommendations`** (y el del manifiesto) con `status = disabled`, el código, el resultado del gate, los motivos (`reason_codes`), las funciones
  deshabilitadas (`customer.individual_predictions`, `customer.individual_rankings`, `customer.activity_risk`) e `individual_rows_written = 0`;
- un **ranking agregado de productos** (solo en DEGRADE; `null` en BLOCK): top N por **demanda agregada vigente** (Demand Core, suma pronosticada en el horizonte) y, sin
  demanda vigente, por **unidades vendidas a compradores identificados** en los últimos `aggregate_ranking_days` (marcado `partial` porque excluye genéricos y anónimos). Nunca nombra ni inventa compradores.

**Desviación respecto de «filas marcadas».** El contrato de W2-C (prueba existente `test_customer_degrades_to_aggregate_no_models_are_trained_and_no_individual_predictions_are_written`)
exige **cero filas individuales**, así que las «filas marcadas» son las de `horizon_support` (estado `insufficient_data` con su código por horizonte) y el bloque anterior; no se
escriben filas de `customer_predictions` con banderas. Pruebas: `test_customer_s05_integration.py::test_unstable_ids_disable_individual_recommendations_and_publish_an_aggregate_ranking`
(alias de fusión de >⅓ de los compradores), `::test_generic_buyers_degradation_marks_the_run_and_keeps_the_aggregate_ranking`, `::test_block_disables_individual_output_without_inventing_an_aggregate_ranking`
y los unitarios de `degrade.py`.

## 9. Fixtures funcionales (NO son pronósticos reales) — `engine/tests/customer_s05_fixtures.py`

Cada caso es un comprador más dentro de un mundo sintético de verdad conocida (110 compradores, 3 años); los modelos se entrenan **solo con datos hasta el corte** y el histórico
posterior se guarda aparte (`later_purchase_days`) para las aserciones. Las pruebas afirman **propiedades**, nunca números mágicos.

**Cubetas de huevo (V2.5 §7).** 3 años; normalmente 5–7 cubetas cada 9–15 días; un episodio de ~2 meses (precio 3,20 → 4,40) con 3–5 cada 6–10 días; vuelta al patrón. Corte del texto:
la última compra fue 13 días antes y la siguiente cae 1–2 días después. Pruebas (`test_customer_s05_fixtures.py`):
- la construcción respeta el caso (`test_egg_fixture_matches_the_functional_case_of_the_spec`);
- **la ventana contiene el intervalo típico** (con 13 días transcurridos solo quedan los intervalos de 14–15: P10 ≤ 1 y P90 ≥ 2) **y la compra real posterior**, que solo entra en la aserción, y es un rango, no un día; las cifras solo se publican porque la validación (global y la del grupo 8+ intervalos de la relación) las respalda (se afirma en la prueba);
- la **probabilidad a 7 días** sale con estado de calibración explícito (`calibrated`/`degraded`/`uncalibrated`), está entre 0 y 1, es mayor con 13 días de recencia que con 3 (`test_probability_depends_on_recency…`),
  y en el holdout poblacional el servido nunca es peor que la referencia; se compara contra la referencia (`ref_p`) y contra el histórico posterior;
- la **cantidad por pedido** dentro del episodio es la del episodio (≈ 3–5) y con el patrón restablecido **vuelve a 5–7** (`test_conditional_quantity_returns_to_the_normal_pattern_after_the_episode`), con su intervalo;
- la **ventana se adapta** al episodio: con los mismos 3 días de recencia, la cola superior es menor con intervalos de 6–10 que con 9–15;
- el **nº de pedidos** es un hurdle: E(N) > P en cada fila (el factor condicionado es > 1), ≤ 1,5 a 7 días con intervalos ≥ 9 días, creciente con H, y `expected_units = pedidos × cantidad por pedido`;
- los **factores** (`customer/explain.py::describe_factors`): recencia frente al intervalo habitual, dispersión, cambio de precio (**asociativo**, la lectura dice que no es causal), tendencia de cantidad, disponibilidad y promoción. No se persisten (no hay columna);
- **sin mirada al futuro**: con todo el histórico cargado, las variables y la ventana del corte salen idénticas a las de los datos hasta el corte (`test_posterior_history_is_separated…`).

**Cliente con cambios temporales de precio.** Quincenal (14–16 días) de 9–11 unidades a 1,00; dos meses a 1,60 con 6–7 unidades; vuelve al patrón. Se afirma que el último precio subió ≈ 60 % (asociación),
que la cantidad media de las últimas compras cae durante el episodio y se recupera al normalizarse el precio, que la cantidad por pedido servida vuelve al entorno de 10 unidades y que el siguiente pedido real
cae dentro de la ventana. **Sensibilidad a precio asociativa:** ninguna salida afirma elasticidad causal.

**Escenarios de V2.5 §7 que NO se simulan:** escasez de maíz, fenómeno de El Niño, costo de alimento y oferta externa dependen de variables con fecha de conocimiento reales (Factor Intelligence está apagado): **BLOQUEADO**.

## 10. Drift del Customer Core

PSI entre distribuciones de probabilidad servida (deciles de la referencia; convención 0,10 aviso / 0,25 alerta, con el PSI **ajustado por ruido muestral** para decidir) y cambio relativo de la tasa observada
(con ≥ `drift_min_positives` positivos en cada grupo). En entrenamiento: calibración → holdout y cortes consecutivos del holdout (`h{H}:stability`, `summary.horizons.<H>.s05.drift`). En la predicción diaria: la distribución
actual de P contra la guardada en el artefacto (`summary.s05.drift`). Una **alerta** agrega una limitación a la corrida; **no cambia el estado de calidad** (es una señal de revisión: las ventanas de cortes consecutivos se solapan y no
son independientes). Detalle y fórmulas en `CALIBRATION_AND_RANKING.md` §8. IMPLEMENTADO / VERIFICADO (`test_customer_s05_ranking_segments_drift.py`).

## 11. Estado por requisito

| Requisito | Estado | Evidencia |
|---|---|---|
| V25-CUS-006 horizonte 7 central y 14/15/30/90 | IMPLEMENTADO / VERIFICADO | S05 funciona para 7/14/15/30/90: `test_s05_works_for_the_catalog_horizons_14_and_15_and_keeps_orders_nested`; catálogo de W2-E |
| V25-CUS-009 baseline de E(cantidad \| compra) por cliente/SKU | IMPLEMENTADO / VERIFICADO | referencia de las últimas 5 compras para la cantidad por pedido; referencia histórica para la cantidad condicionada; ambas comparadas en el holdout |
| V25-CUS-013 compras repetidas sin subcontar | IMPLEMENTADO / VERIFICADO | E(N) × E(cantidad por pedido) (§5) frente a P × E(cantidad \| compra) |
| V25-CUS-014 ventana (distribución del tiempo hasta la siguiente compra) | IMPLEMENTADO / VERIFICADO (mecanismo, validación por grupo y retención de cifras sin evidencia); **sin cifras publicadas en la demo ficticia (no validada)** | §6 |
| V25-CUS-016 cliente sin historial / cold start | IMPLEMENTADO / VERIFICADO | §7, segmento `history:cold_start` |
| V25-CUS-020 tareas A/B/C frente a alternativas simples | IMPLEMENTADO / VERIFICADO | conteo vs tasa, cantidad por pedido vs últimas 5, ventana vs intervalos propios sin condicionar, ranking vs popularidad/recencia |
| V25-CUS-025 calibración por segmento y horizonte | IMPLEMENTADO / VERIFICADO | `CALIBRATION_AND_RANKING.md` §3 |
| V25-CUS-027 lift@K | IMPLEMENTADO / VERIFICADO | `CALIBRATION_AND_RANKING.md` §4 |
| V25-CUS-028 utilidad económica del ranking | PARCIAL: unidades e importe a precio al corte IMPLEMENTADO / VERIFICADO; **margen BLOQUEADO** | la lectura de compras del Customer Core no trae costo unitario conocido al corte (`db/customer_repository.py`, fuera de este paquete); la función soporta costo y se prueba con costo sintético |
| V25-CUS-029 validar la ventana (cobertura y error temporal) | IMPLEMENTADO / VERIFICADO | §6 |
| V25-CUS-030 cantidad condicionada (MAE/WAPE, dispersión) | IMPLEMENTADO / VERIFICADO | MAE/WAPE/sesgo/RMSE y cobertura del intervalo de la cantidad por pedido y de la condicionada; dispersión del conteo |
| V25-CUS-031 drift | IMPLEMENTADO / VERIFICADO | §10 |
| V25-CUS-033 fixture de las cubetas de huevo | IMPLEMENTADO / VERIFICADO (fixture funcional; no es validación real) | §9 |
| V25-CUS-036 cliente con cambios temporales de precio | IMPLEMENTADO / VERIFICADO | §9 |
| V25-CUS-037 IDs no confiables → degradar a agregado | IMPLEMENTADO / VERIFICADO (W2-C) + bloque y ranking agregado (W3-C) | §8 |
| V25-CUS-039 definición del evento P(≥ 1 pedido) | IMPLEMENTADO / VERIFICADO | §2 |
| V25-CUS-040 E(nº pedidos) con modelo de conteo | IMPLEMENTADO / VERIFICADO (hurdle P × E(N \| N ≥ 1), conteo condicionado propio; interpretación del arquitecto de ADR-V25-11) | §3 |
| V25-CUS-041 E(cantidad por pedido \| ocurre) distinta de la total | IMPLEMENTADO / VERIFICADO | §4 |
| V25-CUS-057 reproducibilidad | PARCIAL: semilla fija y salidas idénticas en una repetición (`test_idempotent_prediction_rerun_keeps_one_row_per_relation_and_horizon`); huella de datos y snapshot son de W1/W2 | — |
| V25-HOR-009 horizontes de Customer | IMPLEMENTADO / VERIFICADO | ver V25-CUS-006 |
| V25-CUS-005 modelos de supervivencia / discrete-time | DISEÑADO / NO IMPLEMENTADO | la ventana empírica es la vía S; el hazard discreto con censura derecha queda como evolución |
| Recomendación de contacto y valor esperado por cliente | NO APLICA a este paquete | Decision Engine (W4/W5) |

## 12. Configuración (`CORE_DEFAULTS["customer"]`, bloque W3-C)

Todos los valores son iniciales, **a aprobar en el piloto**, con rango validado (`CONFIG_CONSTRAINTS`; las listas `segment_tenure_edges` —estrictamente creciente, ≤ 2 elementos, > 0— y `lift_global_fractions` —estrictamente creciente, en (0, 1]— tienen reglas propias) y sobrescribibles por empresa en `settings.cores.customer`. `s05_enabled` apaga todo lo de S05: no calcula, no persiste y no declara (columnas, evaluaciones, `limitations`, `target_definition`, bloque `individual_recommendations` y ranking agregado).

| Clave | Valor | Justificación |
|---|---|---|
| `orders_min_rel_deviance_improvement` | 0,02 | misma magnitud que la mejora mínima de Brier de la probabilidad; se evalúa sobre el E(N) servido final |
| `orders_max_abs_bias` | 0,15 | como el sesgo admisible del objetivo de cantidad; tope del sesgo del E(N) servido final (si no se cumple, no se promueve el conteo complejo) |
| `orders_dispersion_max` | 10 | tope de α (método de momentos) |
| `window_gap_k` · `window_min_own_gaps` | 12 · 2 | el doble que los intervalos de las variables; con 1 intervalo no hay distribución |
| `window_prior_weight` · `window_prior_cap` | 2 · 6 | 2 = pseudo-observaciones de la referencia de probabilidad; cada nivel jerárquico pesa a lo sumo 6 |
| `window_max_days` | 365 | = `candidate_max_recency_days`; un cuantil mayor queda nulo |
| `window_overdue_quantile` | 0,9 | = cuantil de «inactivo por patrón» |
| `window_eval_days` · `window_eval_max_cutoffs` · `window_eval_max_rows` · `window_coverage_tolerance` | 90 · 12 · 10 000 · 0,10 | seguimiento de un trimestre; ventana de evaluación acotada; tope de filas por corte (muestra reproducible que acota el costo; el suavizado usa a todos los candidatos); tolerancia bajo el nominal de 80 % |
| `window_eval_min_rows` · `window_eval_min_customers` · `window_eval_min_cutoffs` · `window_group_min_relations` | 100 · 30 · 4 · 50 | evidencia mínima en relaciones y compradores DISTINTOS (no filas) y en cortes de evaluación (una sola realización del calendario no valida); por grupo de intervalos propios y para `overdue`: 50 relaciones distintas |
| `segment_min_rows` · `segment_min_positives` · `segment_tenure_edges` · `segment_calibration_tolerance` · `cold_start_min_purchases` | 200 · 20 · [90, 365] · 0,25 · 3 | evidencia mínima por segmento (como el bloque de selección); cortes de antigüedad en trimestre y año; desvío relativo máximo de la media predicha |
| `lift_global_fractions` · `stability_min_rows` | [0,01; 0,05; 0,10] · 50 | |
| `drift_bins` · `drift_psi_warn` · `drift_psi_alert` · `drift_rate_rel_alert` · `drift_min_positives` | 10 · 0,10 · 0,25 · 0,5 · 30 | convención del PSI; positivos mínimos como el holdout |
| `aggregate_ranking_top_n` · `aggregate_ranking_days` | 20 · 90 | |

## 13. Pendientes, bloqueos y notas

- **BLOQUEADO — datos reales:** cualquier cifra de calidad (lift, utilidad, cobertura de la ventana, calibración por segmento) con compradores reales; la demo ficticia asigna compradores al azar a cada factura.
- **BLOQUEADO — costo unitario conocido al corte** en la lectura de compras: sin él no hay margen (solo unidades e importe a precio al corte). Requiere ampliar `db/customer_repository.py` (fuera de este paquete).
- **Sin columna para el intervalo de la cantidad por pedido** en `customer_predictions`: hoy son factores relativos en el resumen; si W3-D lo quiere por fila, hace falta `quantity_per_order_lo/hi` (migración nueva).
- **Recalibración por segmento:** se evalúa como candidato (decisión por Brier de selección; el holdout solo informa) y **no se sirve**. DISEÑADO / NO IMPLEMENTADO el servirla. La columna `segment` es el segmento de calibración de la fila (dimensión primaria: frecuencia), no un ajuste aplicado a su probabilidad.
- **Supervivencia / discrete-time**, **sustitutos** y **estado «reactivado»**: DISEÑADO / NO IMPLEMENTADO.
- El sesgo de E(N) hereda el de P (§3): con deriva temporal entre calibración y holdout, el E(N) servido puede quedar fuera del tope aunque el conteo condicionado sea insesgado; se declara por horizonte y no se presenta como calibrado.
- La validación de la ventana usa los cortes del dataset. Los cortes de **ajuste** de los niveles (y su seguimiento) quedan antes del primer corte holdout de todo horizonte (restricción activa y probada); los de **evaluación** pueden coincidir en el tiempo con un holdout pero solo se evalúan. El manifiesto lista los cortes de ajuste, los de evaluación y los inicios de holdout.
- Sin validación por cobertura, o en los grupos sin evidencia, **no hay cifras de ventana** (en la demo ficticia, ninguna). Quien necesite una ventana publicada sobre datos con ciclos más largos que el seguimiento debe aumentar `window_eval_days` (hasta 365) cuando el histórico lo permita.

## 14. Banderas nuevas por fila (`quality_flags`) y su significado

W3-D las muestra en la UI con su i18n; aquí queda su significado y cuáles son **diagnósticas** (no cambian ninguna cifra: informan una condición de la fila) y cuáles **modifican** una cifra servida.

| Bandera | Significado | Tipo |
|---|---|---|
| `orders_nested_adjusted` | E(N) de esta fila se subió al máximo acumulado de los horizontes menores (ventanas anidadas); la masa y la fracción corregidas se publican por horizonte | **modifica** `expected_orders` (y `expected_units`) |
| `cold_start` | la relación tiene menos de `cold_start_min_purchases` compras al corte; su calibración se reporta aparte | diagnóstica |
| `window_beyond_validation` | el P90 de la ventana excede el seguimiento con que se validó la cobertura (o no existe dentro de `window_max_days`): su cola no está validada | diagnóstica |
| `s05_unavailable` | el artefacto servido es anterior a S05 (o con otra forma de `orders`): las columnas S05 salen nulas | diagnóstica |
| `s05_failed` | S05 falló en esta predicción: columnas S05 nulas; la probabilidad y la cantidad condicionada no se afectan | diagnóstica |

Retiradas respecto de la primera versión: `orders_raised_to_probability` (ya no hay cota fila a fila) y `window_not_validated` (sin validación las cifras no se publican: el estado es `insufficient_data`).

## 15. Presupuesto de cómputo (medido)

Demo Ferretería Andina (ficticia; ≈ 15 600 relaciones candidatas por corte, ≈ 59–117 mil filas de holdout por horizonte; horizontes 7/30/90) en el mismo equipo, con la máquina cargada por otros procesos
(los tiempos son de orden de magnitud, no un benchmark): **entrenamiento 60,9 s con S05 apagado y 92,6 s encendido (+31,7 s, +52 %)**, de los cuales `train_s05` 17,1 s (Σ de los 3 horizontes: pedidos, cantidad por
pedido con hasta 5 objetivos, segmentos, ranking), `finalize_s05` 5,6 s (cadenas de modelos sobre el holdout de cada horizonte) y la evaluación de la ventana 9,4 s (ajuste de 24 cortes y evaluación de 12, con tope de
10 000 filas por corte y hasta 3 seguimientos). **Predicción diaria 6,3 s apagado y 6,5 s encendido**: la ventana de los 15 629 candidatos tarda 0,2 s y `apply_s05` 0,1 s. Controles de costo: bloques de 500 filas en el cálculo de
cuantiles (memoria acotada), tope `window_eval_max_rows`, una sola lectura de eventos por corte para ajuste, evaluación y comparación nominal (`test_window_inputs_are_computed_once_per_cutoff…`), y el seguimiento solo se amplía si el anterior no es concluyente.
