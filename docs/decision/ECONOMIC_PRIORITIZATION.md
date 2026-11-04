# Priorización económica de las recomendaciones

Fecha: 1-nov-2026 · Rama `v25/w1-cierre-y-w2` · V2.5 · W5-B · Fuentes: ADR-V25-13 y ADR-V25-14, §11 (valor esperado, contribución, uplift) y §12 de la especificación V2.5, `V25-DEC-014/015/016/020/021/022/023/035/039`. El workflow y la API están en
[`DECISION_ENGINE.md`](DECISION_ENGINE.md); la sección de Revenue Radar (W5-A) está al final de este documento.

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** · **NO APLICA**.

## 1. Principios (todos IMPLEMENTADOS / VERIFICADOS)

1. **Ningún número sin fuente.** Cada cifra de una recomendación resuelve a una fila persistida o a un parámetro registrado de la empresa; los insumos del cálculo quedan en el factor `value_calculation` de `explanation` para poder
   verificar el score a mano (`test_the_stored_explanation_is_enough_to_verify_the_score`).
2. **Contribución solo con costo confiable.** Si el costo unitario no es confiable, `expected_contribution` es **NULL** y `uncertainty.cost.reasons` y `uncertainty.expected_contribution_null_because` dicen por qué. Nunca un cero, nunca un
   costo supuesto, nunca `products.current_cost` (es el valor de HOY: fuga al pasado).
3. **Sin evidencia de uplift, etiqueta honesta.** `attribution_label = potential_unproven` («oportunidad potencial, atribución no demostrada»), `incremental_estimate = NULL`. El valor esperado de pedido **no implica ventas incrementales
   causadas por el contacto** (§11 ¶3) y no se vende como revenue recuperado. Una contribución o un incremental que traiga quien propone se ignora.
4. **El score es un ranking, no una cifra para el balance.** No se suman scores de tipos distintos (la misma venta puede aparecer como oportunidad de cliente y como reposición).

## 2. La fórmula (`expected-net-value`, revisión 1)

```
score = valor − gasto de la acción
valor = contribución esperada                  si el costo unitario es confiable
      = importe bruto esperado × peso          si NO lo es   (marca cost_unreliable)
      = (sin valor → score NULL)               si no hay ni contribución ni importe
gasto = costo de la acción                     solo en contacto, recuperación y riesgo de cliente (un gasto);
                                               en reposición el desembolso es capital comprometido: se informa en action_cost y NO se resta
```

**Valor esperado de un contacto** (§11 ¶3: `valor esperado de pedido = probabilidad de compra × contribución condicionada`, bajo supuestos explícitos):

```
unidades esperadas   = E(nº de pedidos) × cantidad esperada por pedido        (independencia entre ambos: supuesto documentado)
                       si la corrida no trae el modelo de conteo: P(≥1 compra) × cantidad condicionada a ≥1 compra
                       (la probabilidad es entonces una cota inferior de E(N): `orders_basis = probability_x_conditional_quantity`)
unidades vendibles   = min(unidades esperadas, disponible al corte)           solo si el disponible es conocido; desconocido NO es cero
importe bruto        = unidades vendibles × último precio neto de la relación (supuesto del Customer Core, sin impuestos)
contribución         = unidades vendibles × (precio − costo unitario)         solo con costo confiable; si no, NULL
```

La disponibilidad es existencias − reservado por sucursal (nunca negativa), sumada sobre las sucursales con foto vigente; el tránsito se informa aparte y **no** cuenta como disponible. Una foto más vieja que `inventory_max_age_days` no se usa.

**Ejemplo con los datos de la prueba** (cliente c1 × producto p1; `test_contribution_is_derived_from_a_reliable_cost_and_follows_orders_times_per_order_contribution`): E(N) = 1,35, cantidad por pedido = 3,2 → 4,32 unidades; precio 8,00; costo
confiable 6,00 (4 líneas de venta con costo) → importe 34,56; contribución 4,32 × 2,00 = 8,64; sin costo de contacto configurado `score = 8,64`. El mismo cliente × p2 (sin costo en sus ventas) → contribución NULL, importe 34,56 y
`score = 34,56 × 0,2 = 6,912`: lo de costo desconocido queda por debajo de lo comparable de margen conocido.

### 2.1 Versión de la fórmula (`score_formula_version`)

SHA-256 del JSON canónico (claves ordenadas recursivamente, sin espacios) de los parámetros **efectivos** (los de la empresa incluidos):

```json
{"decimals":6,"expense_kinds":["contact","customer_risk","recovery"],"id":"expected-net-value","priority_bands":[0.1,0.3,0.6,0.85],"revision":1,"unreliable_cost_weight":0.2}
```

Versión con los parámetros por defecto: `c1aa6d23c576cc4b7533d7b46a74504dd46e89f354ad200d573b5d9755b38bd3` (fijada por una prueba: si cambia, el cambio es deliberado). Cambiar un parámetro de la empresa (`unreliable_cost_weight`) cambia su versión;
`revision` cambia si cambia la **estructura** de la fórmula. Cada recomendación conserva la versión con la que se calculó y no se recalcula. El costo de contacto es un **insumo** (`action_cost`), no un parámetro de la fórmula, y no cambia la versión.
Verificado: `test_the_score_formula_version_is_the_sha256_of_its_parameters_and_changes_with_them`, `test_the_default_formula_version_is_pinned_so_a_change_of_parameters_is_always_deliberate`.

## 3. Costo unitario confiable (lectura as-of)

El costo se lee de las **versiones de líneas de venta vigentes al corte** (`sales_line_versions`, la misma regla as-of de W1: un documento registrado después del corte no existía para ese corte —
`test_a_cost_line_known_after_the_cutoff_is_not_used`). Fuente 1: costo histórico en las líneas de venta de la ventana. Es confiable solo si se cumplen **las tres** reglas:

| Regla | Código si falla | Por defecto (provisional) |
|---|---|---|
| Al menos N líneas con costo conocido y positivo | `insufficient_cost_observations` (`no_cost_in_sales_lines` si ninguna) | N = 3 en 90 días |
| Cobertura (líneas con costo / líneas) ≥ umbral | `cost_coverage_low` | 80 % |
| Coeficiente de variación del costo ≤ umbral | `cost_dispersion_high` | 0,25 |

Fuente 2, **solo si la 1 no es confiable**: `purchase_cost_history` del último año con `known_at` conocido y anterior al corte (un `known_at` NULL = «desconocido» no entra), mismas reglas de observaciones y dispersión
(`purchase_history_insufficient`, `purchase_history_dispersion_high`, `no_purchase_cost_history`). Sin ninguna fuente confiable el costo es NULL con los motivos de ambas. Un producto sin ventas en la ventana: `no_sales_history`; una recomendación sin
producto (recuperación o riesgo de cliente): `no_product_scope`. Verificado: `test_each_cost_reliability_rule_withholds_the_contribution`, `test_purchase_cost_history_is_a_fallback_only_when_known_before_the_cutoff`,
`test_without_a_reliable_cost_the_contribution_is_null_and_the_uncertainty_says_why`.

## 4. Valor por tipo de recomendación

| Tipo | `expected_gross_value` | `expected_contribution` | `action_cost` | Nota |
|---|---|---|---|---|
| `contact` | unidades vendibles × precio | unidades vendibles × (precio − costo) con costo confiable | `contact_cost` de la empresa o NULL | El valor se limita a lo disponible; sin stock el valor es 0 y la restricción `out_of_stock` lo declara |
| `recovery`, `customer_risk` | Caída **observada** del gasto neto entre las dos ventanas del Customer Core (importe, no pronóstico) | NULL (`no_product_scope`: el margen por cliente no se estima) | `contact_cost` o NULL | No implica abandono; el score usa el importe × peso |
| `replenishment` | unidades en riesgo × precio neto as-of | con costo confiable | inversión del plan (líneas) o `estimated_investment` de la política; NULL si no hay costo | Unidades en riesgo = max(demanda media en plazo + revisión − disponible, 0); el desembolso es capital, no se resta |
| `inventory_mismatch` (faltante) | faltante × precio medio ponderado de las predicciones | con costo confiable | NULL | Faltante = demanda esperada de compradores − disponible |
| `inventory_mismatch` (exceso) | NULL | NULL | NULL | **Sin valor estimado**: requiere una tasa de mantener inventario por empresa (DISEÑADO); score vacío y prioridad 5 |
| `budget_constraint` | unidades faltantes por presupuesto × precio | con costo confiable | unidades × costo unitario de las líneas del plan (NULL si no hay) | Siempre exige aprobación |

## 5. Prioridad (1 = la más alta)

**Determinista por la posición del score dentro de su grupo**, sea cual sea la vía por la que llegó la recomendación (`DecisionEngine::rerank`): según la fracción del grupo con mejor score que la recomendación, **menos del 10 % → 1, menos del 30 % → 2, menos del 60 % → 3, menos del 85 % → 4,
el resto → 5**. Un score nulo, cero o negativo es 5. Las bandas forman parte de los parámetros versionados; el empate se resuelve por la huella. **Grupo** = (tipo, entidad, fecha de corte) y, en los tipos de cliente (contacto, recuperación, riesgo), también el horizonte; en reposición,
inventario y presupuesto el horizonte es incidental (plazo + revisión) y el grupo es todo el corte. **Solo cuenta lo accionable del grupo**: `suggested`, sin seguimiento en curso y fuera del grupo de control; lo que ya se trabaja, se cerró o venció conserva la prioridad que tenía y su `updated_at` no se toca (reordenar no es una acción de nadie). El desempate por huella es en orden binario (`COLLATE "C"`), igual en cualquier servidor. El reordenamiento es **una** sentencia SQL con las filas del grupo bloqueadas (`DecisionEngine::rerank`); acotado por el tope de candidatos. Verificado: `DecisionReviewFixesTest::test_reordering_priorities_never_touches_in_flight_control_or_closed_rows`.

- **Lo que propone el Radar de a una** (`propose()`) también queda ordenado: cada recomendación nueva reordena su grupo en la misma transacción, así que el resultado depende del conjunto final de scores y **no del orden de llegada**; sola en su grupo es la primera (prioridad 1), y un score mejor que llega después
  empuja a las anteriores (p. ej. 1 → 3 con dos). El generador por lotes persiste sin reordenar fila por fila y ordena cada grupo tocado una vez, con la misma función. La prioridad no es una decisión humana: no genera evento ni transición. `priority` ya no se acepta como dato de entrada.
- Las listas ordenan por score (`sort=-score` por defecto) y la prioridad es un resumen legible de esa posición.
- Verificado: `DecisionProducersAndPriorityTest` (`test_the_priority_does_not_depend_on_the_order_in_which_items_are_proposed_one_by_one`, `test_a_better_score_arriving_later_pushes_the_earlier_ones_down_and_groups_are_independent`, `test_the_radar_priorities_equal_the_rank_of_the_score_within_their_group`,
  `test_a_score_without_value_is_the_lowest_priority_and_the_batch_generator_agrees_with_propose`), `ScoreFormulaTest::test_priorities_follow_the_rank_inside_the_batch_and_are_deterministic`.

## 6. Umbrales y justificación (todos «a aprobar en el piloto»)

| Parámetro | Valor por defecto | Por qué ese punto de partida | Cómo se aprueba |
|---|---|---|---|
| `unreliable_cost_weight` | 0,2 | Con costo desconocido no se sabe qué fracción del importe es margen. Un peso menor que un margen comercial típico evita que lo de costo desconocido supere a lo de margen conocido solo por no tener costo. **No es un margen supuesto ni una cifra económica**: solo ordena | Fijarlo en el margen mediano observado de la empresa (productos con costo confiable) |
| `cost_reliability` | 90 días, 3 observaciones, 80 %, CV 0,25 | 3 observaciones es el mínimo para hablar de dispersión; 80 % de cobertura evita que un costo presente en pocas líneas represente al producto; un CV de 0,25 tolera variaciones moderadas de costo | Contrastar con la tasa de productos con costo confiable y con costos conocidos de compra |
| `contact_min_probability` / `max_contact_per_horizon` | 0,20 / 100 | Los de `radar-v1` (`config/radar.php`): deja fuera lo improbable y acota el trabajo del equipo | Precisión@K observada del ranking en el piloto |
| `inventory_max_age_days` | 7 | Una foto más vieja describe otra semana de ventas; desconocido es mejor que viejo | Cadencia real de carga de inventario |
| `excess_demand_ratio` | 0,10 | «Exceso sin demanda»: la demanda esperada de compradores es menos de la décima parte de lo disponible | Rotación y cobertura por categoría |
| Vigencias (`expiry_days`) | contacto = horizonte; reposición = período de revisión; 30 / 30 / 14 / 14 / 14 | Una oportunidad deja de describir la realidad cuando termina el horizonte que la sustenta | Ritmo real de gestión |
| `contact_cost` | NULL | No existe un costo de contacto «universal»; sin dato no se resta nada y se declara | Costo de tiempo del equipo, medido en el piloto |

## 7. Uplift y atribución

- **IMPLEMENTADO / VERIFICADO:** la etiqueta y los campos vacíos sin evidencia; la asignación reproducible a tratamiento/control al generar (`DECISION_ENGINE.md` § 9), que es lo que habilita medir el uplift.
- **DISEÑADO / NO IMPLEMENTADO:** estimar `P(compra|contacto) − P(compra|sin contacto)` y su intervalo para llenar `incremental_estimate` e `incremental_evidence` y subir la etiqueta a `incremental_estimated` o `causally_attributable`: es el análisis por
  intención de tratamiento de W5-C sobre los outcomes medidos, y solo con grupo de control registrado o un cuasiexperimento documentado.
- **BLOQUEADO:** cualquier cifra real de uplift o de ROI: requiere un piloto con datos reales (§12, Gate E). Hasta entonces los valores esperados son **oportunidad potencial, no beneficio atribuible**.

## 8. Supuestos y límites

- El precio es el último precio neto de la relación (contacto) o el neto medio ponderado de la ventana as-of (reposición, faltante, presupuesto): sin impuestos y sin descuentos futuros.
- E(N) y la cantidad por pedido se combinan bajo independencia; el intervalo de la cantidad y la ventana de recompra se muestran pero no entran al score.
- La demanda de reposición usa la media persistida; la incertidumbre de la demanda está en `interval` y la probabilidad de quiebre en `probability`, no en el valor.
- Los scores de distinto tipo no son comparables entre sí como dinero.

<!-- >>> W5-A radar: la sección de Revenue Radar y Agenda de W5-A va debajo de esta línea -->

## 9. Revenue Radar y Agenda Comercial (W5-A)

Fecha: 1-nov-2026 · V2.5 · W5-A · Fuentes: ADR-V25-12 (Radar y Agenda como fachadas sobre Customer Core), §10, §11 y §25.3 de la especificación V2.5, `V25-AGD-001..021` (`docs/roadmap/audit/2026-10-31/cliente.json`). Contrato de la API: `docs/06-contratos-integracion.md` § 3.17; equivalencias V2.2: [`../product/LEGACY_API_COMPATIBILITY.md`](../product/LEGACY_API_COMPATIBILITY.md).

### 9.1 Qué es y qué no es

El Revenue Radar es una **capa de producto** sobre las salidas del Customer Core, el inventario al corte y el Decision Engine. **No** entrena modelos, **no** recalcula probabilidades, cantidades ni costos y **no** puntúa: las oportunidades son filas de `decision_recommendations`
(kind `contact`, `recovery`, `customer_risk`) que **solo el Decision Engine escribe**. El Radar (1) PROPONE las suyas por `RecommendationSink::propose()` —nunca escribe esa tabla (`RadarSinkOnlyTest` lo vigila con un escaneo de código y comprueba qué campos viajan)—, (2) las LISTA, RESUME y AGRUPA en una agenda,
(3) registra el SEGUIMIENTO humano con los estados heredados de la Agenda V2.2 y (4) expone las fachadas V2.2 de la ficha del comprador. **No envía mensajes** (WhatsApp/correo) **ni escribe en el ERP**: todo contacto lo hace una persona y el Radar solo lo registra (`RadarFeedbackApiTest::test_no_message_is_ever_sent_by_the_radar`).

| Pieza | Archivo (`backend/app/…`) | Responsabilidad |
|---|---|---|
| `CommercialOpportunityService` | `Services/Radar/` | **Productor**: predicciones vigentes → oportunidades por cliente × producto → `RecommendationSink` (idempotente). `build()` es lectura pura |
| `PredictiveSalesAgenda` | `Services/Radar/` | **Consumidor**: lista de trabajo, resumen y agenda por cliente sobre las recomendaciones (modelo con aislamiento por empresa) |
| `OpportunityPresenter` | `Services/Radar/` | Forma pública de una oportunidad (cargas en bloque, sin N+1; la ventana pasa por `CustomerS05Presenter`) |
| `AgendaFeedbackService`, `AgendaStatusMap` | `Services/Radar/` | Seguimiento auditable e idempotente; tabla de mapeo y matriz de transiciones de los estados heredados |
| `CustomerCommercialProfile` | `Services/Radar/` | Demanda, productos y ficha del comprador (sin PII) |
| `SupplyReadiness` | `Services/Radar/` | Preparación de abastecimiento (usa la disponibilidad del Decision Engine) |
| `RadarPolicy` | `Services/Radar/`, `config/radar.php` | Parámetros de la política `radar-v1` y su huella SHA-256 |
| API y comando | `Http/Controllers/Api/Radar/`, `routes/api/radar.php`, `Console/Commands/Radar/` | Rutas `/radar/*`, `/customers/{id}/*`, `/demand/forecasts`, `/supply/readiness`; `predictily:radar-materialize` (04:50) |

### 9.2 Qué propone el Radar y con qué parámetros (política `radar-v1`)

Por cada horizonte de la última corrida de predicción de producción que el plan permite, sobre las filas de `customer_predictions` con probabilidad (la abstención —NULL— no genera nada) de compradores **no genéricos y no eliminados** y de productos activos:

| Tipo | Entidad | Regla | Estado |
|---|---|---|---|
| `recovery` | cliente × producto | ventana de recompra **atrasada** (`window_status = overdue`: atrasado respecto a su patrón, **no abandono**) y relación con ≥ `recovery_min_purchases` compras previas | IMPLEMENTADO / VERIFICADO (`CommercialOpportunityServiceTest`) |
| `contact` | cliente × producto | el resto de relaciones con P(≥ 1 compra) ≥ `min_probability`; cada relación aparece en un solo tipo por horizonte | IMPLEMENTADO / VERIFICADO |
| `recovery` y `customer_risk` **a nivel de cliente** | cliente | ausencia respecto al patrón propio (`customer_activity`): **los genera el Decision Engine** (`predictily:decisions-generate`, `DECISION_ENGINE.md` § 3); el Radar los lista pero no los duplica | IMPLEMENTADO / VERIFICADO (la lista, el resumen y la agenda los muestran: `RadarOpportunitiesApiTest`) |

| Parámetro (`config/radar.php`) | Por defecto | Justificación | A aprobar en el piloto con |
|---|---|---|---|
| `min_probability` | 0,20 | Deja fuera lo que el modelo considera improbable sin exigir certeza; alineado con `contact_min_probability` del motor | precisión@K y lift observados por segmento |
| `max_per_kind_horizon` | 100 | Acota el trabajo del vendedor y el costo de proponer; el resto sigue en `/customer/predictions`. Orden determinista: valor bruto esperado ↓, probabilidad ↓, cliente, producto | capacidad real de contacto del equipo |
| `recovery_min_purchases` | 3 | Con menos compras un atraso no es información sobre el patrón | distribución de intervalos por empresa |

Ninguno es un umbral universal. La huella de los parámetros viaja en cada recomendación (`policy = radar-v1:<12 hex>`; `RadarPolicyTest`): cambiar un parámetro es visible.

**Qué entrega el Radar al `propose()`** (`CommercialOpportunityService::SINK_FIELDS`; sin `tenant_id` ni `idempotency_key`): `kind`, `as_of` (= corte de la corrida), `horizon_days`, `entity_type = customer_product`, `customer_id`, `product_id`, `source_core_run_id`, `data_snapshot_id`, `model_versions` (corrida, versión, huella de configuración, modelos del horizonte, política),
`probability`, `interval` (ventana P10/P50/P90 —solo con estado `estimated` u `overdue`— y cantidad condicionada con su rango del 80 %), `assumptions`, `constraints` (p. ej. «ventana sin cifras»), `expected_units` (E(N) × cantidad por pedido de S05; sin S05, la esperanza de **un solo evento** y se declara), `unit_price` (último precio neto de la relación, supuesto del Customer Core),
`expected_gross_value` (respaldo: unidades × precio), `uncertainty` (calibración, banderas de calidad, estado de la ventana), `explanation` (factores verificables sin PII: probabilidad, E(N), cantidad por pedido, unidades, ventana, patrón de compra, base de las unidades) y `policy`.
**Lo que el Radar NO entrega y decide el Decision Engine** (probado: `RadarSinkOnlyTest`): `expected_contribution`, costo unitario y su confiabilidad, disponibilidad de inventario al corte (y el límite del valor de un contacto a lo disponible), `action_cost`, score y su versión de fórmula, prioridad, vencimiento, asignación al experimento, etiqueta de atribución, estado e `idempotency_key`.
`E(N)` (`expected_orders`) es el servido por el modelo de conteo del Customer Core; el Radar no lo deriva de la probabilidad.

**Coordinación con W5-B (productor de contactos).** Decisión del arquitecto: **un productor por tipo de oportunidad**. Los contactos y recuperaciones cliente × producto los produce solo el Radar (`contact_source = radar`, el valor por defecto); el motor genera los de nivel de cliente y los de Supply. Aun con `contact_source = engine` ambos usan la **misma huella de idempotencia** (tipo, corte, horizonte, entidad, corrida): lo mismo no se duplica en ningún orden. La prioridad la calcula el motor por la posición del score en su grupo (§ 5), también para lo que el Radar propone de a una o por lote (`proposeMany`). **Quién propone:** la materialización manual (`POST /radar/opportunities/materialize`) registra a la persona como proponente (`proposed_by_kind = user`) y por tanto **no podrá aprobar** lo que propuso (segregación de funciones); el comando programado propone como `system`. Si el alcance ya tiene trabajo **en curso**, una predicción nueva no crea sucesora: el motor refresca la existente con un comentario de linaje (`DECISION_ENGINE.md` § 5). Verificado: `DecisionProducersAndPriorityTest`, `RadarReviewFixesTest`.

### 9.3 Valor económico: lo que se muestra y lo que no

- `value.expected_gross_value` es un **supuesto** (unidades vendibles × último precio neto de la relación, sin impuestos). Para un contacto el motor lo limita a lo disponible al corte. **No es una venta confirmada ni causada por el contacto.**
- `value.expected_contribution` es `null` salvo que el motor tenga **costo unitario confiable** al corte (§ 3 de este documento); `value.cost_status` (`reliable` | `unreliable` | `unknown`) y `value.cost_reasons` repiten lo que el motor declaró en `uncertainty.cost`. Nunca se estima un margen sin costo confiable.
- `value.attribution` es siempre «**Oportunidad potencial, atribución no demostrada**» (`potential_unproven`) mientras el motor no registre evidencia de uplift (grupo de control analizado, W5-C).
- `GET /radar/summary` informa **por horizonte** (`by_horizon[h]`) y **nunca suma entre horizontes** (las ventanas se solapan: una misma compra se contaría dos veces). Cada horizonte trae `opportunities`, `customers`, `by_kind`, `by_state`, `inventory`, y **dos cifras de naturaleza distinta que no se mezclan**:
  `value` = valor **pronosticado** de las relaciones cliente × producto (`scope = customer_product`, `basis_code = forecast_units_x_last_net_price`: unidades × último precio neto; `contribution` solo suma las valoradas con costo confiable y `contribution_unavailable` dice cuántas no la tienen) y
  `customer_level` = lo de **nivel de cliente** (recuperación y riesgo): su importe es la **caída observada del gasto neto** (`basis_code = observed_spend_decline`, con `observed_spend_decline` y el número de oportunidades que la tienen), que **no es pronóstico, ni contribución, ni venta causada** y **no se suma a `value`**. Cada fila de la lista trae el mismo `value.basis_code` y su `value.basis` en texto. Los totales de nivel superior cuentan relaciones **distintas** (tipo, cliente, producto), no filas por horizonte, y llevan su unidad en texto; no hay valor, estado ni inventario totales entre horizontes. Verificado: `RadarReviewFixesTest::test_the_observed_spend_decline_is_reported_apart_with_its_own_basis_and_never_added_to_the_forecast`, `RadarAgendaAndSummaryApiTest::test_the_summary_counts_by_horizon_and_kind_and_labels_the_value_as_potential`, `test_the_value_is_never_summed_across_horizons`, y `RadarDecisionEngineIntegrationTest` (contribución 4,32 × (8 − 5) = 12,96 derivada por el motor; `NULL` con 2 líneas de costo).
- `inventory`: disponible = existencias − reservado (el tránsito va aparte); sin fotografía vigente es `unknown` —**no es cero**—; `not_applicable` en el riesgo de cliente. Con el grupo de control **no se muestran** las oportunidades (ver 9.5).
- La ventana de recompra publica cuantiles solo con estado `estimated` u `overdue` (regla de W3-C/W3-D reutilizada de `CustomerS05Presenter`); con `insufficient_data`, `censored` o sin estado, `null`. Nunca un día exacto.

### 9.4 Estados heredados de la agenda y su mapeo al workflow (`radar-agenda-map-v1`)

| Estado heredado | Workflow que implica | Regla |
|---|---|---|
| Pendiente | `suggested` (`assigned` con vendedor asignado) | Sin gestión (`agenda_status` NULL se lee como pendiente) |
| Contactado | `in_progress` | Se contactó al cliente |
| Interesado | `in_progress` | `payload.interest = true` en el evento |
| Pedido recibido | `executed` | **Final.** `measured` lo pone el servicio de outcomes, no la agenda |
| Pospuesto | *(sin cambio)* | `payload.snoozed_until` (posterior a hoy, ≤ 1 año y **no posterior al vencimiento de la oportunidad**: 422 `snooze_beyond_expiry`); la oportunidad reaparece en la agenda en esa fecha |
| Descartado | `rejected` | **Final.** Equivale a rechazada |

- **Sin perder historial (IMPLEMENTADO / VERIFICADO):** cada cambio inserta un evento `feedback` en `decision_events` con estado previo y nuevo, autor, nota, `implied_workflow_status` y la versión de la tabla; el estado vigente está en `agenda_status`. No existe historial V2.2 previo que migrar en este repositorio; la ausencia de pérdida se prueba sobre historiales sembrados (`RadarFeedbackApiTest::test_a_legacy_history_is_kept_complete_and_maps_to_the_workflow`, `AgendaStatusMapTest`: matriz completa 6 × 6).
- **Idempotente (IMPLEMENTADO / VERIFICADO):** `idempotency_key` obligatoria; clave del evento = SHA-256(«radar.feedback», recomendación, clave del cliente); la repetición exacta devuelve el mismo evento (200); otra carga con la misma clave, 409; la fila se bloquea durante el cambio.
- **Auditable e inmutable:** solo inserción por diseño de la API (sin rutas de edición o borrado: `test_the_api_exposes_no_way_to_edit_or_delete_history_events`) y una fila `radar.feedback` en la auditoría general. **DISEÑADO / NO IMPLEMENTADO:** un trigger de PostgreSQL que lo imponga en la base (requiere una migración).
- **Notas con PII:** texto libre acotado a 500 caracteres; se enmascaran correos y números de 10/13 dígitos (`PiiMasker`); el texto de la nota no va a la auditoría general.
- **Seguimiento → workflow (IMPLEMENTADO / VERIFICADO, W5-B):** `AgendaFeedbackService::syncWorkflow()` pide a `Workflow::applyImplied()` la transición que implica el estado heredado (`radar-agenda-map-v1`: contactado e interesado → `in_progress`, pedido_recibido → `executed`, descartado → `rejected`, pospuesto y pendiente → sin cambio) y se aplica COMO CUALQUIER OTRA: habilidades del rol, segregación de funciones, aprobación de acciones sensibles, grupo de control, idempotencia (clave `agenda-feedback:<id del evento>`) y bloqueo optimista (`expected_version` = el evento de seguimiento recién registrado). El seguimiento nunca se pierde ni falla por esto: si el workflow no admite la transición desde su estado actual (p. ej. `suggested`, `assigned` o `reviewed` → `in_progress` sin pasar por la revisión, o una acción sensible aún sin aprobar), el workflow NO se mueve y el motivo queda en un **segundo evento `comment`** (`payload.kind = workflow_sync`: `workflow_sync` = `applied` | `unchanged` | `not_applicable` | `skipped`, `workflow_sync_reason`, `workflow_status_before/after`, `workflow_event_id`), en la misma transacción; el evento de seguimiento queda inmutable. **Excepción (403, sin registrar nada):** si el estado **cierra** la oportunidad (`pedido_recibido`, `descartado`) y el rol no podría hacer la transición implicada (falta de habilidad o segregación de funciones), el seguimiento se rechaza: un rol sin permiso no puede dejar la oportunidad cerrada y fuera de la agenda sin que el workflow la cierre. Tabla de resultados y pruebas: [`DECISION_ENGINE.md`](DECISION_ENGINE.md) § 6.1. Solo se admite seguimiento mientras el workflow no esté rechazado, vencido, medido ni cerrado (`opportunity_closed`).

### 9.5 Listas, agenda y grupo de control

- **Lista de trabajo** (sin `state` ni `status`): vigentes —ni vencidas, medidas, cerradas ni rechazadas— y sin pedido recibido ni descartadas, y **a lo sumo una accionable por alcance** (tipo, entidad, horizonte, cliente, producto, sucursal; defensa ante datos heredados o duplicados: gana la que está en curso y, a igualdad, la más reciente). Con un `state` o `status` explícito se ve exactamente lo pedido. Verificado: `RadarReviewFixesTest::test_duplicates_of_the_same_scope_are_shown_once_preferring_the_one_in_flight`. Con `state` se ve exactamente ese estado; con `status`, exactamente ese estado de workflow. Orden determinista: `score` ↓ (nulos al final), valor bruto ↓, `id`. Filtros: cliente, SKU, horizonte, tipo, vendedor (usuario asignado), segmento comercial y de calibración, zona, categoría, estado, corte.
- **Agenda** (`/radar/agenda?date=`): oportunidades vigentes a la fecha agrupadas por **cliente** (no se duplica por SKU), paginadas por cliente; las pospuestas reaparecen en su fecha de reactivación; las vencidas salen. El día se corta en la **zona horaria de la empresa** (no en UTC): una oportunidad que vence a las 23:59:59 locales sigue en la agenda de ese día y no en la del siguiente (`RadarReviewFixesTest::test_the_agenda_boundaries_use_the_company_time_zone_not_utc`).
- **Grupo de control:** las oportunidades que el motor asignó a control (`experiment_group = control`) **no se listan, no se agrupan en la agenda, no se pueden abrir ni recibir seguimiento (404)**: se reservan para medición. El grupo de control **no se delata**: `experiment_group` solo se incluye con `decisions.approve`; el resumen informa cuántas se retienen (`control_withheld`) solo a quien aprueba y solo sin filtro de cliente o producto (con uno revelaría a qué cliente pertenece); lo guardado en el evento inicial (`payload.experiment`) no se muestra a los demás roles (`DECISION_ENGINE.md` § 9). Verificado con el motor real: `RadarDecisionEngineIntegrationTest::test_a_control_unit_assigned_by_the_engine_is_never_shown_nor_contacted`.

### 9.6 Ficha del comprador (`/customers/{id}/…`)

`predictions` ≡ `/customer/predictions?customer_id=` (mismo cuerpo); `demand` = Σ de `total_view_quantity` con desglose esperado / comprometido / incremental (coincide con la suma de `/customer/predictions`); `products` = habituales (compras históricas) y probables (ranking del Customer Core); `overview` = compras recientes con importes, frecuencia, SKU habituales,
evolución mensual de 12 meses (los meses sin compras salen con cero porque ahí el cero es un hecho de las ventas), ventana futura y seguimiento. **Sin PII innecesaria:** solo código, tipo, zona y segmento; el nombre sigue cifrado y no sale (`CustomerFichaApiTest::test_the_overview_carries_no_unnecessary_pii_and_never_decrypts_the_name`). Los estados `no_individual_prediction`, `no_candidate_relations` y `no_prediction_run` se dicen, no se rellenan con ceros.

### 9.7 Autorizaciones y aislamiento

| Operación | Habilidad (existente) | owner | admin | analyst | viewer |
|---|---|---|---|---|---|
| Leer resumen, oportunidades, agenda, ficha, pronósticos agregados y preparación de abastecimiento | `forecast.view` | sí | sí | sí | sí |
| Registrar seguimiento (feedback) | `decisions.manage` | sí | sí | sí | **403** |
| Proponer oportunidades al Decision Engine | `forecast.run` | sí | sí | sí | **403** |

Aislamiento por empresa en modelo (global scope fail-closed): una oportunidad o un comprador de otra empresa responde 404; un id de otra empresa en un filtro, 422; la cabecera de una empresa donde no se es miembro, 404. Plan sin Customer: 402 `features.customer`. Verificado en cada clase de `backend/tests/Feature/Radar`.
**IMPLEMENTADO / VERIFICADO (W6-A):** los roles comerciales **gerente**, **vendedor** y **servicio** (`tenant_users.commercial_role` y `commercial_scope`; se suman al rol de acceso). El vendedor ve solo las oportunidades asignadas a él y las SIN asignar de los clientes de su cartera (clientes, zonas o segmentos), nunca las asignadas a otra persona, con lista vacía sin cartera ni asignaciones; el resumen, el valor potencial, los estados y la agenda se calculan sobre lo visible. `seller_id` sigue siendo un filtro y se compone con el alcance. Se aplica en `PredictiveSalesAgenda` (el antiguo punto `TODO(W6)`), en el Decision Engine y, por id, en `EnforceCommercialScope` (404 fuera del alcance). Detalle, límites y pruebas: `docs/security/ENTERPRISE_SECURITY.md` §7 (`CommercialRolesTest`).

### 9.8 Estado de los requisitos (`V25-AGD-*`)

| Requisito | Estado | Evidencia |
|---|---|---|
| 001 servicios reutilizables | IMPLEMENTADO / VERIFICADO | `CommercialOpportunityService` (productor), `PredictiveSalesAgenda` (consumidor); `CommercialOpportunityServiceTest` |
| 002–004 summary, opportunities, agenda | IMPLEMENTADO / VERIFICADO | `RadarAgendaAndSummaryApiTest`, `RadarOpportunitiesApiTest` |
| 005, 006, 007, 020 feedback, estados, mapeo, historial | IMPLEMENTADO / VERIFICADO (el hook al workflow lo conectó W5-B; el trigger de inmutabilidad en la base sigue DISEÑADO, ver 9.4) | `RadarFeedbackApiTest`, `AgendaStatusMapTest` |
| 008, 009 filtros | IMPLEMENTADO / VERIFICADO; vendedor = usuario asignado (el alcance por cartera del rol vendedor se compone con el filtro: W6-A, `CommercialRolesTest`) | `RadarOpportunitiesApiTest::test_filters_…` |
| 010–013 ficha, predictions, demand, products | IMPLEMENTADO / VERIFICADO | `CustomerFichaApiTest` |
| 014 `/demand/forecasts` | IMPLEMENTADO / VERIFICADO (alias) | `DemandForecastAliasTest` |
| 015 `/supply/readiness` | IMPLEMENTADO / VERIFICADO | `SupplyReadinessApiTest` |
| 016 `/models/evaluations` | IMPLEMENTADO / VERIFICADO (W3-D; sin copia) | `ModelQualityApiTest` |
| 017, 018 sin duplicados, compatibilidad | IMPLEMENTADO / VERIFICADO | `DemandForecastAliasTest`, `CustomerFichaApiTest`, [`LEGACY_API_COMPATIBILITY.md`](../product/LEGACY_API_COMPATIBILITY.md), `OpenApiRoutesTest` |
| 019 `MessageDraft` con confirmación humana | DISEÑADO / NO IMPLEMENTADO; **ningún envío automático** IMPLEMENTADO / VERIFICADO | `test_no_message_is_ever_sent_by_the_radar` |
| 021 autorizaciones y aislamiento | IMPLEMENTADO / VERIFICADO con las habilidades existentes; roles gerente/vendedor/servicio y cartera: IMPLEMENTADO / VERIFICADO (W6-A, `CommercialRolesTest`) | pruebas de roles y de aislamiento de cada clase |
| Piloto con datos reales (utilidad frente a lo que el vendedor ya haría) | **BLOQUEADO** | requiere clientes reales y el piloto prospectivo (Gate E) |

