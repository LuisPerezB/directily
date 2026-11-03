# Medición de outcomes y ROI honestos

Fecha: 2-nov-2026 · Rama `v25/w1-cierre-y-w2` · V2.5 · W5-C (con las correcciones de la revisión adversarial) · Fuentes: ADR-V25-14 (outcomes y ROI honestos), §12 de la especificación V2.5 y los requisitos `V25-OUT-001..029` de
`docs/roadmap/audit/2026-10-31/supply_decision.json`. Las recomendaciones que se miden, su workflow y la asignación al experimento son de W5-B ([`DECISION_ENGINE.md`](DECISION_ENGINE.md));
la fórmula del score y el costo confiable, de [`ECONOMIC_PRIORITIZATION.md`](ECONOMIC_PRIORITIZATION.md); lo que Predictily no hace sobre el ERP, de [`CONTROLLED_ACTIONS.md`](CONTROLLED_ACTIONS.md).

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** (no se puede construir o verificar en este entorno) · **NO APLICA**.

> **Regla de oro.** Una compra posterior a una recomendación **no es** un beneficio atribuible. Sin un grupo de control analizado, el sistema informa **observación** y **oportunidad potencial («atribución no demostrada»)**,
> nunca «ventas recuperadas». Este paquete **no contiene resultados de clientes reales**: todas las cifras de este documento y de las pruebas son sintéticas (BLOQUEADO: requiere un piloto, § 14).

## 1. Qué es y qué no es

`OutcomeMeasurementService` (`App\Services\Outcomes`) une cada recomendación del Decision Engine con la **acción registrada** y con el **evento observable posterior** en una **ventana fija**, lo clasifica con honestidad y lo
guarda en `decision_outcomes`. Después, `RoiReport` resume lo medido separando siempre tres cosas que **nunca se suman entre sí**:

| Qué | Dónde | Qué significa |
|---|---|---|
| **Oportunidad potencial** | `summary.potential` | Contribución esperada de las recomendaciones accionables, **por tipo y horizonte**. «Oportunidad potencial, atribución no demostrada». No es ingreso recuperado |
| **Observación** | `summary.observed`, `summary.associated` | Lo que pasó después, con contribución conocida (no ingresos brutos) y **sin doble conteo**. Solo lo **posterior a la acción** es **asociado temporalmente**, y no es atribuible |
| **Incremento estimado** | `experiments`, `roi` | Solo con un experimento con grupo de control analizado por **intención de tratamiento (ITT)**, con tamaños de muestra e intervalo. Sin él: `roi.status = potential_unproven` y **no hay cifra de ROI** |

No entrena modelos, **no escribe en el ERP, no envía mensajes y no modifica recomendaciones** salvo la transición `executed → measured` por `Workflow::systemTransition` (§ 4). La API es de **solo lectura** (§ 11).

## 2. Piezas

| Pieza | Archivo (`backend/app/Services/Outcomes/`) | Responsabilidad |
|---|---|---|
| `OutcomeMeasurementService` | `OutcomeMeasurementService.php` | Orquesta la medición por empresa: grupos (corte, horizonte), candado y bloqueo de filas, lectura as-of, clasificación, persistencia idempotente, `executed → measured` |
| `OutcomeWindow` | `OutcomeWindow.php` | Ventana fija y corte de conocimiento (§ 3) |
| `SalesObservationReader` | `SalesObservationReader.php` | Lectura **as-of** de `sales_line_versions` (W1) e inventario conocido al corte. Sin PII |
| `ObservationCalculator` | `ObservationCalculator.php` | Resumen puro de las compras de un cliente: devoluciones restadas sin ventas negativas, «posterior a la acción» estricto, líneas para deduplicar |
| `ActionResolver`, `ActionRecord` | `ActionResolver.php` | Deduce de `decision_events` qué pasó (`action_kind`) y cuándo quedó registrada la **primera** acción |
| `ExperimentResolver` | `ExperimentResolver.php` | Reconstruye experimento y grupo **desde lo registrado al generar**, los verifica contra `experiment_assignments` y lee las unidades ya contadas (por lotes) |
| `IttAnalysis`, `IttEstimator`, `BootstrapInterval` | `IttAnalysis.php`, `IttEstimator.php`, `BootstrapInterval.php` | Análisis por intención de tratamiento (§ 7) |
| `RoiReport` | `RoiReport.php` | Informe de ROI y desglose por tipo, horizonte, segmento y responsable (§ 10) |
| `OutcomeSettings`, `OutcomeVocabulary` | `OutcomeSettings.php`, `OutcomeVocabulary.php` | Parámetros por empresa (§ 13); clasificaciones, acciones y eventos con su explicación |
| Comando | `backend/app/Console/Commands/Outcomes/MeasureOutcomesCommand.php` | `predictily:outcomes-measure` (§ 12) |
| API | `backend/routes/api/decision.php` (solo las rutas de outcomes) | `GET /api/v1/decisions/outcomes`, `GET /api/v1/decisions/roi` |

Contrato de tablas: migración `2026_03_13_000100` del arquitecto (`decision_outcomes`, `experiment_assignments`), **no modificada**. La migración propia `2026_03_13_000300` agrega el índice único `(tenant_id, recommendation_id)` sobre `decision_outcomes`
(§ 6; de haber duplicados, conserva el más antiguo: el resultado es inmutable).

## 3. Ventana, margen de espera y lectura as-of

Para cada recomendación la ventana es **fija desde su horizonte**, no se elige después:

```
desde  = as_of + 1 día                  hasta = as_of + horizon_days
corte de conocimiento = hasta + settling_lag_days        (fecha local de la empresa)
```

- **Cuándo se mide.** Solo cuando `hoy > corte de conocimiento`, con «hoy» en la **zona horaria de la empresa** (no UTC). Antes **no se escribe nada**: la recomendación sigue **prevista (`forecast`)**, que es un estado virtual del informe
  (`funnel.forecast_pending`: ventana abierta, en espera de documentos tardíos, o medible y aún sin medir), nunca una fila. Verificado: `test_only_recommendations_whose_window_and_settling_lag_have_passed_are_measured` (el último día del margen aún no mide),
  `test_today_is_the_local_date_of_the_company_not_utc`, `OutcomeWindowAndSettingsTest`.
- **Margen de espera** (`settling_lag_days`, por defecto **3**): es el margen para que lleguen los documentos de venta que se cargan tarde. Es **provisional, «a aprobar en el piloto»** con el retraso real de ingestión de cada fuente. Se cambia por empresa
  (`tenants.settings.outcomes.settling_lag_days`) o para una ejecución con `--lag`. Verificado: `test_the_settling_lag_is_configurable_per_company_and_by_option`.
- **Lectura AS-OF.** Se lee la vista `sales_line_versions` (W1, ADR-V25-23) **tal como se conocía al cierre del día de corte**: una versión es visible si `max(sale_date, día_local(known_from)) ≤ corte` y (`known_until` es nula o `día_local(known_until) > corte`), con el día
  local en la zona de la empresa. Consecuencias: un documento que llegó **después** del corte no se ve en esa medición (se informa aparte en `evidence.source.late_lines_not_counted` y `late_units_not_counted`); uno que llegó **antes** del corte sí cuenta aunque su fecha de venta sea más vieja;
  una anulación posterior al corte **no reescribe** el resultado pero se informa (`lines_revised_or_removed_after_cutoff`). El resultado es **reproducible** (misma base ⇒ misma lectura) y su huella SHA-256 queda en `evidence.source.fingerprint`. Verificado:
  `test_a_late_document_is_not_visible_before_it_was_ingested_but_one_ingested_within_the_lag_is`, `test_a_document_voided_and_a_return_ingested_after_the_cutoff_do_not_rewrite_the_outcome_but_are_reported`, `test_a_voided_document_is_not_a_purchase`,
  `test_the_day_a_document_became_known_is_the_local_day_of_the_company`.
- **Devoluciones y notas de crédito.** Las COMPRAS son las de la ventana (`desde … hasta`); las devoluciones (`is_return`) y las notas de crédito se leen hasta el corte de conocimiento —**aunque su fecha sea posterior al cierre de la ventana**— y se restan de esas compras
  (se informan en `evidence.observation.returns_after_window`). Las posteriores al corte no se conocían: se informan aparte (`late_returned_units_not_counted`) y no se restan. Verificado: `test_returns_and_credit_notes_dated_after_the_window_but_known_before_the_cutoff_are_netted_and_reported`.
- **Inventario** (quiebres de los tipos por producto). Se lee `inventory_snapshots` con el **conocimiento real** de la fila: `updated_at` (el importador hace upsert y lo actualiza; `created_at` no cambia). Una foto cargada **o corregida** después del corte se trata como **desconocida**
  (el valor original ya no existe: ni se lee el corregido ni se supone el anterior); sin ninguna foto conocida la cobertura es 0 (**desconocido, no «sin stock»**). La existencia disponible lleva el piso en cero **por sucursal** antes de sumar (como `InventoryAvailabilityLoader`):
  un saldo negativo en una sucursal no compensa el stock de otra. Una foto idéntica que se vuelve a cargar tras el corte también queda como desconocida (conservador). Verificado: `test_a_snapshot_corrected_after_the_cutoff_is_unknown_not_read_with_the_corrected_value`,
  `test_a_negative_balance_in_one_branch_does_not_cancel_the_stock_of_another`.

## 4. Qué se mide: todo, no solo los éxitos

Se mide **cada recomendación** cuya ventana ya se puede medir, también las que nadie atendió. Sin los no tratados la comparación se sesga. `action_kind` dice qué pasó **dentro de la ventana**:

| `action_kind` | Cuándo | `contact_cost` |
|---|---|---|
| `contacted` | Tipos con cliente (contacto, recuperación, riesgo) con acción **registrada dentro de la ventana** | `action_cost` de la recomendación; **NULL** si la empresa no lo configuró (desconocido, no cero) |
| `executed` | Tipos por producto (reposición, inventario-demanda, presupuesto, escenarios) con ejecución registrada dentro de la ventana | NULL (la compra al proveedor no es un costo de contacto) |
| `not_contacted` / `not_executed` | Sin acción registrada dentro de la ventana (sugerida, asignada, revisada, aprobada o en curso al medir; o con la acción registrada **después** de cerrar la ventana) | 0 |
| `rejected` | Rechazada o descartada **antes** de cualquier acción (con la fecha en `evidence.action.rejected_at`; el motivo queda en `decision_events`) | 0 |
| `expired` | Venció sin acción (incluye las **reemplazadas** por una más nueva: `evidence.recommendation.superseded`) | 0 |
| `control` | Grupo de control: retenida a propósito (nunca se asigna ni se contacta) | 0 |

**Cuándo hay «acción registrada».** `in_progress` por sí solo es «empezada», no «hecha». Cuenta la transición a `executed` o —en tipos con cliente— un seguimiento de agenda «contactado», «interesado» o «pedido_recibido» (W5-A, `radar-agenda-map-v1`); «pospuesto», «descartado» y «pendiente» no.
`action_at` es la **PRIMERA** acción registrada (no la última): es una cota **conservadora** (la acción real pudo ser anterior; nunca se supone anterior al registro). Una acción registrada **después** de cerrar la ventana **no cuenta** como atendida ni genera costo de contacto
(no pudo influir en lo observado en ella; queda en `evidence.action.action_registered_after_window_at`). Una acción registrada gana sobre el estado final: una recomendación contactada que luego se descartó o venció sigue siendo `contacted`.
Verificado: `test_only_contacted_interested_and_order_received_count_as_an_action_and_the_first_registration_wins`, `test_an_action_registered_after_the_window_closed_is_not_attended_nor_does_it_cost_contact`, `test_agenda_follow_up_contacted_registers_the_action_time_for_the_association`,
`test_a_contacted_recommendation_that_later_expired_or_was_discarded_is_still_contacted`, `test_rejected_expired_not_contacted_and_superseded_recommendations_are_measured_too`, `test_a_superseded_recommendation_is_measured_and_marked_as_superseded`.

**Evento observable (`observed_event`).** Tipos con cliente: `purchase` / `no_purchase` según haya **unidades netas > 0** del cliente (en el producto de la recomendación; en cualquiera si es una recomendación de cliente) en la ventana. Las **devoluciones y notas de crédito se restan por producto y nunca
bajan de cero**. `observed_ref` es `sale:<id>` (sin PII). Tipos por producto: `stockout` (algún día de la ventana con existencias disponibles ≤ 0 en las fotos conocidas), `no_sell_through` (solo inventario-demanda: al cierre de la ventana la disponibilidad no bajó respecto de la de la
recomendación; es una observación de existencias, **no un diagnóstico de exceso** —el nombre anterior `overstock` se retiró—) o `product_sales`. Verificado: `test_returns_are_netted_and_never_create_negative_sales`, `ObservationCalculatorTest`, `test_availability_that_did_not_fall_is_reported_as_no_sell_through_not_as_overstock`.

**`executed → measured`.** Si la recomendación estaba `executed`, tras escribir su resultado pasa a `measured` **solo por `Workflow::systemTransition`** (evento del sistema, idempotente, con `outcome_id` en su payload); una persona sigue pudiendo `close`. Las demás no cambian de estado.
Si la ejecución se registra **después** de haber medido, el resultado **no se reescribe** (es inmutable) y la recomendación se cierra como medida con la marca `late_execution` en el evento (en `--dry-run` solo se informa). Verificado: `test_executed_moves_to_measured_through_the_system_transition_with_its_audit_event`,
`test_an_execution_registered_after_the_measurement_is_closed_as_measured_with_the_late_mark`, `test_dry_run_reports_the_late_execution_closure_without_moving_the_recommendation`.

## 5. Clasificación honesta

| `classification` | Cuándo | Qué **sí** afirma | Qué **no** afirma | Prueba |
|---|---|---|---|---|
| `forecast` | La ventana no cerró o está en espera | Hay una oportunidad prevista | Nada observado. **No se persiste**: es el estado del informe | `test_the_forecast_state_is_virtual_recommendations_not_yet_measurable_are_reported_as_forecast` |
| `observed` | Lo que pasó, sin acción previa; el grupo de control; una compra **solo anterior** a la acción; y **todo lo de nivel producto** | Lo ocurrido en la ventana | Que alguna acción lo causara | `test_a_purchase_after_a_registered_action_is_temporally_associated_and_never_incremental`, `test_product_level_results_never_claim_association_with_a_supplier_purchase` |
| `temporally_associated` | Hubo una acción registrada **y** compras netas **estrictamente posteriores** a ella (`issued_at` > `action_at`) | Esas compras siguieron a la acción | **Causalidad**: sin contrafactual no se sabe qué habría pasado sin la acción | misma prueba; `ObservationCalculatorTest::test_only_a_purchase_strictly_after_the_registered_action_counts_as_after` |
| `incremental_estimated` | **Solo como resultado AGREGADO** de un experimento con control y un ITT estimado (§ 7): `roi.status`, `experiments`. **No se estampa en ninguna fila** | Existe una estimación del incremento del experimento, con tamaños e intervalo | Causalidad demostrada; que una compra o un cliente concreto sea incremental | `IttOutcomeTest::test_a_known_effect_is_recovered_with_its_estimate_sample_sizes_and_interval` |
| `causally_attributable` | **Nunca en esta versión** | — | — | `test_the_vocabulary_matches_the_database_contract_and_keeps_causal_attribution_unwritten` |

**Por qué las filas no llevan `incremental_estimated`.** Marcar «incremental estimado» en cada unidad de tratamiento —compre o no, la hayan contactado o no— afirmaría de cada unidad lo que solo sostiene la comparación tratamiento-control. Cada fila conserva lo que le pasó (`observed` o
`temporally_associated`), `method = none`, y enlaza con el análisis por `evidence.experiment` (`in_itt`, `itt_counted`, `itt_status`) y, solo para quien aprueba, `evidence.itt`. La etiqueta `decision_recommendations.attribution_label` **no cambia** al medir (es la de la generación: `potential_unproven`).
`OutcomeVocabulary::WRITTEN` = `observed` y `temporally_associated`; `AGGREGATE_ONLY` = `incremental_estimated`.

**Lo asociado es SOLO lo posterior a la acción.** `evidence.after_action` (y `after_action` en la API) resume únicamente las compras emitidas después de la acción registrada, **netas de las devoluciones emitidas también después**, con su contribución. La ventana completa (`observed_*`, `contribution`) es
observación y puede incluir compras anteriores a la acción; `summary.associated` del informe suma solo `after_action`. Verificado: `test_the_associated_money_is_only_what_was_bought_after_the_action_not_the_whole_window` (800 antes, 8 después: asociado 8 y contribución 2, observado 808 y 202),
`test_a_purchase_only_before_the_action_is_observed_and_adds_nothing_to_the_association`, `test_only_returns_issued_after_the_action_reduce_the_after_action_figures`.

## 6. Idempotencia y concurrencia

- **Una vez por recomendación.** Una recomendación con resultado no se vuelve a medir: el resultado es **inmutable** y su huella de ventas queda en la evidencia. Re-ejecutar no duplica, no modifica filas ni agrega eventos. Verificado: `test_re_measuring_is_idempotent_and_dry_run_writes_nothing`.
- **Unicidad en la base.** El índice único `decision_outcomes_recommendation_unique (tenant_id, recommendation_id)` impide un segundo resultado aunque falle el candado. El servicio escribe con `INSERT … ON CONFLICT DO NOTHING`: si otro proceso ya midió esa recomendación no se escribe nada, se cuenta como «**ya medida**»
  (`already_measured_concurrently`) y el grupo sigue, sin abortar su transacción. **No se usa un punto de guardado por fila**: cada subtransacción que escribe toma un candado sobre su propio XID y unos miles en una sola transacción agotan la tabla de candados de PostgreSQL (`out of shared memory`, `max_locks_per_transaction`);
  lo encontró la prueba E1 de la revisión con 66 000 filas en un grupo. Verificado: `test_the_unique_index_exists_and_a_race_lost_is_treated_as_already_measured_without_aborting_the_group` (la violación real la provoca una escritura concurrente simulada) y `test_a_large_group_is_measured_in_one_transaction_without_exhausting_the_database_lock_table`
  (7 000 recomendaciones en un grupo). Queda un límite de escala: la transición `executed → measured` sí usa un punto de guardado por recomendación ejecutada (es de `Workflow::systemTransition`, W5-B); un grupo con más de ≈ 5 000 recomendaciones **ejecutadas** de la misma vez agotaría la misma tabla (DISEÑADO: cerrarlas por lotes fuera de la transacción del grupo).
- **Concurrencia.** Cada grupo (corte, horizonte) se mide en **una transacción** que toma un **candado consultivo por empresa** (`pg_advisory_xact_lock(5301, empresa)`: una medición a la vez por empresa entre procesos, porque el análisis acumulado de un experimento depende de lo que midieron los grupos anteriores),
  bloquea las filas de sus recomendaciones (`FOR UPDATE`, en orden de id) y **después**, en otra consulta, descarta las que otro proceso ya midió (con READ COMMITTED una fila solo bloqueada no se reevalúa). Si algo falla, el grupo entero se revierte (ni los resultados, ni las transiciones, ni los
  contadores del informe) y se informa con un código estable; la siguiente ejecución lo mide. **Verificado con procesos y conexiones reales** (`OutcomeConcurrencyTest`, deterministas: esperan a que `pg_locks` muestre al hijo bloqueado antes de soltar): con otra conexión reteniendo el candado la medición no avanza y,
  al soltarlo, mide todo; con otra conexión reteniendo una fila, el hijo ya tiene el candado y espera esa fila; con tres procesos a la vez no hay resultados repetidos ni un cliente contado dos veces. También `test_a_group_that_fails_midway_is_rolled_back_whole_reported_and_measured_on_the_next_run`.
- **`--dry-run`** calcula y cuenta lo que mediría sin escribir nada ni mover estados.

## 7. Análisis por intención de tratamiento (ITT)

**Qué compara.** A cada **cliente** (la unidad de la asignación de W5-B, `customer:<id>`) según el grupo que se le **asignó al generar** (tratamiento o control), **se haya contactado o no**. Descartar a los no contactados rompería la aleatorización (se contacta a los casos más fáciles); por eso un
tratamiento no contactado igual cuenta en el denominador. Cuando hay incumplimiento (asignados que no se contactaron) el efecto del contacto efectivo es **mayor** que el estimado por ITT.

**Verificación de la asignación.** La clave del experimento sale del evento de propuesta (`payload.experiment.experiment_key`) cuando el Decision Engine la dejó allí; si no está, se **infiere** de las asignaciones del cliente: la **única** clave con el mismo grupo que trae la recomendación (`evidence.experiment.key_source`:
`proposal_event` o `single_assignment_of_customer`). Con varias claves posibles o ninguna, **no se adivina**: la recomendación no entra al análisis (`experiment_key_ambiguous`, `experiment_key_not_recorded`). Después el grupo se **verifica** contra `experiment_assignments`: debe coincidir con el de la
recomendación y haberse registrado a más tardar al generarla (5 min de margen por reloj); si no, no entra (`unverified_assignment:assignment_not_found | assignment_group_mismatch | assignment_registered_after_generation | experiment_key_ambiguous | experiment_key_not_recorded`). La semilla del experimento
**no** se copia a la evidencia. Las consultas de verificación van por lotes (`ExperimentResolver`, 5 000 por lote: una empresa grande supera el límite de parámetros de PDO con un solo `whereIn`). Verificado: `test_a_group_not_backed_by_a_registered_assignment_is_excluded_from_the_analysis`,
`test_an_assignment_registered_after_the_recommendation_was_generated_is_not_trusted`, `test_the_assignment_made_by_the_decision_engine_is_the_one_analysed`, `test_without_the_key_in_the_proposal_event_it_is_inferred_from_the_single_assignment_of_the_customer`, `test_when_several_experiments_fit_a_customer_the_key_is_ambiguous_and_nothing_is_claimed`,
`test_the_key_recorded_in_the_proposal_event_wins_over_inference`, `test_a_group_without_any_assignment_or_recorded_key_does_not_enter_the_analysis`, `test_the_resolver_loads_events_and_assignments_in_chunks`.

**Unidad y población.** La unidad es el cliente; su **alcance de productos** es la unión de los productos de sus recomendaciones de contacto y recuperación en la cohorte (cualquier producto si alguna no trae producto). *Convertido* = unidades netas > 0 en ese alcance dentro de la ventana.
**Un cliente entra una sola vez por experimento y horizonte**, con su primera cohorte medida (las siguientes no suman: `repeat_customer`), de modo que las unidades son **distintas e independientes** y se acumulan entre cohortes. Verificado: `test_each_customer_enters_the_analysis_once_and_cohorts_of_different_customers_accumulate`.
**Unidades descartadas por grupo** (`dropped_units`: repetidos, sin asignación verificable, con grupos en conflicto) y **cociente de muestras (SRM)**: la proporción observada de control entre las unidades contadas se compara con la fracción registrada al asignar (χ² de 1 g.l.; `sample_ratio.status = mismatch` si p < 0,001, y el ROI lo
anota como `sample_ratio_mismatch`). Verificado: `test_the_units_dropped_per_arm_and_the_sample_ratio_are_reported`, `test_a_sample_ratio_mismatch_is_flagged_in_the_experiment_and_in_the_roi_reasons`, `IttAnalysisTest::test_the_sample_ratio_check_flags_a_split_incompatible_with_the_registered_fraction`.

**Estimadores** (`IttEstimator`, `BootstrapInterval`; confianza por defecto 95 %):

1. *Conversión.* Diferencia de tasas `p_T − p_C` con el **intervalo de Newcombe** (híbrido de Wilson, método 10 sin corrección de continuidad). Válido con proporciones cerca de 0 o 1 y con muestras pequeñas. Reproduce los valores **publicados** (Newcombe, 1998, *Statistics in Medicine* 17:873–890: 56/70 frente a 48/80 → 0,2000
   [0,0524; 0,3339]; Wilson 81/263 → [0,2553; 0,3662]); verificado en `IttEstimatorTest`.
2. *Contribución por unidad* (incluye los ceros de quien no compró). Diferencia de medias con **intervalo percentil bootstrap**: se remuestrean con reemplazo las unidades **dentro de cada grupo** (`B` = 2 000 por defecto), se calcula la diferencia de medias de cada remuestreo y se toman los percentiles α/2 y 1−α/2. Es **determinista**: motor
   Mersenne Twister con una **semilla registrada** en la evidencia (`contribution.interval.seed`, derivada de SHA-256 de empresa, experimento y horizonte): mismos datos y semilla ⇒ mismo intervalo. El error estándar de **Welch** y su intervalo normal se conservan **solo como diagnóstico** (`diagnostic_welch`).
   Solo sobre la **población con costo confiable en todo su alcance de productos** (decidida por el costo del producto, no por quién compró); las unidades con costo no confiable salen del análisis de contribución pero **no** del de conversión (`excluded_unreliable_cost`).
3. *Incremento total* = `diferencia por unidad × unidades tratadas de la población` (con su intervalo). Es el incremento de **contribución** estimado del experimento, antes de costos.

**Cuándo se publica cada parte.** *Conversión* (`itt.status = estimated`): hay control **y** tratamiento, ambos grupos ≥ `min_group_size` y **una sola fracción de control registrada**; si no, no hay diferencia ni intervalo y queda el motivo (`no_control_group`, `no_treatment_group`, `insufficient_sample`,
`heterogeneous_control_fraction`). *Contribución* (dinero): además la población con costo confiable ≥ `min_group_size` por grupo (`insufficient_sample`), **al menos `min_buyers_per_arm` compradores en CADA grupo** (10 por defecto, provisional «a aprobar en el piloto»; `buyers_below_min_per_arm`) y **varianza distinta de cero**
(`zero_variance`: con todos los valores iguales el error estándar de Welch es cero y el intervalo sería de ancho cero) o cómputo dentro del tope (`bootstrap_budget_exceeded`). Si no, `contribution.status = not_estimable`, **no hay intervalo, ni incremento monetario**, y `roi.status = incremental_conversion_only`. Un grupo
sin compradores o con uno solo nunca produce un intervalo estrecho ni una «pérdida neta significativa». Los **tamaños de muestra y de compradores se informan siempre**. Pisos que ninguna empresa puede bajar: `min_group_size` ≥ 20, `min_buyers_per_arm` ≥ 5, `bootstrap_resamples` ≥ 500.
Verificado: `test_with_fewer_buyers_than_the_minimum_per_arm_no_money_interval_is_published`, `IttAnalysisTest::test_nobody_buying_gives_no_zero_width_interval_and_no_significant_loss`, `IttAnalysisTest::test_identical_values_give_zero_variance_and_no_interval`, `IttAnalysisTest::test_the_floors_stop_a_company_from_configuring_a_meaningless_minimum`.

**Ejemplo con datos sintéticos de prueba** (no son resultados reales): 60 clientes de tratamiento (30 compran) y 60 de control (12 compran); cada compra aporta una contribución de 4.

| | Tratamiento | Control | Diferencia | Intervalo 95 % |
|---|---|---|---|---|
| Tasa de conversión | 0,50 (30/60) | 0,20 (12/60) | **0,30** | [0,1299; 0,4474] (Newcombe) |
| Contribución por unidad | 2,00 | 0,80 | **1,20** | [0,53; 1,87] (percentil bootstrap; el percentil exacto de la distribución bootstrap es [0,5333; 1,8667], retícula de 0,0667) |
| Incremento total (× 60) | | | **72,0** | [32,0; 112,0] |

(Welch, solo diagnóstico: [0,5465; 1,8535] por unidad y [32,79; 111,21] en total.) Con 40 por grupo y 30 % frente a 25 % de compradores la conversión (diferencia 0,05) y la contribución tienen intervalos que **incluyen 0** y se informa `significant = false`, no como una victoria. Con 12 compradores frente a 6 en el control la conversión
se estima y la contribución **no** (menos de 10 compradores por grupo). Verificado: `IttOutcomeTest::test_a_known_effect_is_recovered_with_its_estimate_sample_sizes_and_interval`, `test_an_interval_that_includes_zero_is_reported_as_not_significant_not_as_a_win`, `BootstrapIntervalTest::test_it_reproduces_the_exact_bootstrap_distribution_of_a_closed_form_case`
(contra la convolución binomial exacta, calculada aparte), `BootstrapIntervalTest::test_the_same_seed_gives_the_same_interval_and_another_seed_a_different_simulation`.

**Cobertura medida y límite honesto del intervalo monetario.** Simulación (`scratchpad` de la revisión; 800 réplicas por celda, B = 1 000, condicionada a la compuerta de ≥ 10 compradores por grupo) con contribución por unidad cero-inflada y lognormal (valor medio de quien compra e^(3+σ²/2)); cobertura empírica de un intervalo al 95 %:

| n por grupo | compra T / C | σ | réplicas que pasan la compuerta | Welch (diagnóstico) | **Percentil bootstrap (publicado)** |
|---|---|---|---|---|---|
| 60 | 30 % / 15 % | 1,5 | 317 de 800 | 89,0 % | **84,2 %** |
| 60 | 30 % / 15 % | 0,5 | 327 de 800 | 94,5 % | **93,6 %** |
| 200 | 15 % / 5 % | 1,5 | 407 de 800 | 86,0 % | **83,1 %** |
| 200 | 30 % / 15 % | 1,5 | 800 de 800 | 94,0 % | **90,9 %** |
| 200 | 15 % / 5 % | 2,0 | 443 de 800 | 78,3 % | **74,0 %** |
| 200 | 30 % / 15 % | 0,5 | 799 de 800 | 95,7 % | **95,1 %** |
| 600 | 15 % / 5 % | 1,5 | 800 de 800 | 91,1 % | **89,6 %** |
| 600 | 30 % / 15 % | 1,5 | 800 de 800 | 95,8 % | **93,9 %** |
| 600 | 15 % / 5 % | 2,0 | 800 de 800 | 85,8 % | **83,5 %** |
| 600 | 30 % / 15 % | 0,5 | 800 de 800 | 95,8 % | **95,4 %** |

**Lectura honesta: el bootstrap percentil NO repara el problema de cobertura con colas pesadas; en esta simulación cubre lo mismo o algo menos que Welch** (la compuerta de compradores evita lo peor con muestras pequeñas, pero no la cola). Con colas moderadas (σ ≤ 0,5) y 200–600 unidades por grupo cubre cerca del 95 %; con σ ≥ 1,5 baja a 74–94 %.
Probadas también las variantes BCa y bootstrap-t (400 réplicas; mismas celdas de n = 60–600 con σ = 1,5–2): ninguna llega al nivel nominal (BCa 71–90 %, bootstrap-t 76–92 %). Es una limitación de la estadística (la media de una variable muy asimétrica converge lentamente), no de la implementación. Por eso: (a) se exige un mínimo de compradores por grupo, (b) cada estimación trae
`tail_diagnostic` (coeficiente de variación de los valores de quien compra > 1,5, o un solo comprador con más del 25 % del total de su grupo ⇒ `heavy_tailed`, y el ROI añade el motivo `heavy_tailed_contribution`) y (c) el intervalo monetario debe leerse como **indicativo**, con la conversión (Newcombe, robusta) como evidencia principal. Alternativas DISEÑADAS: analizar contribución recortada o en escala
logarítmica (cambia el estimando), o un diseño con muestra calculada por potencia antes del piloto (§ 9).

**Sin afirmación incremental** (verificado): sin experimento `test_without_a_control_group_nothing_is_ever_incremental`; solo tratamiento `test_a_treatment_only_experiment_without_controls_makes_no_incremental_claim`; muestra bajo el mínimo `test_a_sample_below_the_minimum_per_group_is_insufficient_and_makes_no_claim`;
fracciones mezcladas `test_a_single_control_fraction_is_required_to_pool_units`. La intención de tratamiento (no el contacto efectivo) se prueba en `test_the_analysis_follows_the_assigned_group_not_who_was_actually_contacted`.

## 8. Contaminación, sesgo de selección y otras amenazas

| Amenaza | Qué hace hoy | Estado |
|---|---|---|
| **Sesgo de selección** (se atiende a los casos más fáciles) | Atendidas frente a no atendidas **no se comparan**: tasa de respuesta y de acción se muestran como descriptivas y la respuesta se marca «asociación, no efecto». La única comparación válida es tratamiento-control por ITT | IMPLEMENTADO / VERIFICADO |
| **Contaminación del control** (un control con acción registrada) | Se cuenta en `itt.contamination.control_units_with_registered_action`, la fila lleva `action.control_contaminated` y **sigue en su grupo** (ITT) | IMPLEMENTADO / VERIFICADO (`test_the_analysis_follows_the_assigned_group_not_who_was_actually_contacted`) |
| **Contaminación entre vendedores** (un vendedor alcanza a un cliente de control sin registrarlo, o dos vendedores comparten un cliente) | No es detectable si no queda registrada. La cartera de clientes por vendedor llega con el rol vendedor (W6). Mitigación de diseño: las de control no se asignan a nadie y no se listan a quien contacta | PARCIAL; la asignación **por conglomerado (vendedor/zona)** es DISEÑADA |
| **Asignación rota o unidades perdidas de forma desigual** | Cociente de muestras (SRM) frente a la fracción registrada y unidades descartadas por grupo | IMPLEMENTADO / VERIFICADO (§ 7) |
| **Desbalance de covariables previas** | No se verifica | DISEÑADO (§ 9) |
| **Cambio de la fracción de control a mitad del experimento** | Se detecta (`heterogeneous_control_fraction`) y bloquea la afirmación | IMPLEMENTADO / VERIFICADO |
| **Mismo cliente en varias ventanas** | Una sola vez por experimento y horizonte; los importes por recomendación **no son aditivos** entre ventanas solapadas: los totales se deduplican por línea de venta (§ 10) | IMPLEMENTADO / VERIFICADO |
| **Reglas de negocio de la asignación** (por clientes con reglas de negocio) | La asignación de W5-B es aleatoria por cliente, sin estratos | Estratificación DISEÑADA |

## 9. Atribución causal y cuasiexperimentos: DISEÑADO, no implementado

`causally_attributable` **no se produce** en esta versión (ni siquiera con un ITT significativo). Se exigiría un **experimento pre-registrado** y una revisión humana:

1. **Pre-registro antes de generar**: hipótesis, unidad, resultado primario único, horizonte, duración, regla de parada y tamaño de muestra por cálculo de potencia (no por el mínimo práctico).
2. **Aleatorización estratificada** por segmento y volumen previo, con semilla registrada, y **verificación del balance** de covariables previas (compras del pre-período) entre grupos.
3. **Fracción de control fija** durante el experimento (el análisis ya lo exige) y **ningún cambio** de modelo o política mientras dure.
4. **Contaminación y cumplimiento** medidos y por debajo de umbrales pre-registrados (controles con acción = 0); asignación **por conglomerado** cuando varios vendedores comparten clientes.
5. **Ventana** suficiente para el ciclo de recompra del segmento; **un** resultado primario (o corrección por comparaciones múltiples); muestra suficiente para que un intervalo monetario cubra su nivel (§ 7).
6. **Revisión**: una persona distinta de quien configuró el experimento registra su validez (segregación de funciones del workflow de W5-B) antes de que un resultado pase a `causally_attributable`.

**Cuasiexperimento** (cuando la aleatorización no sea viable): diferencias en diferencias con pre-período, control sintético o emparejamiento, **documentando** diseño, supuestos (tendencias paralelas, sin derrames), diagnósticos y riesgos de sesgo. `method = quasi_experiment` existe en el contrato de la tabla pero **no se escribe**;
un resultado cuasiexperimental no sería nunca `causally_attributable` automático.

## 10. Contribución, costos y ROI

**Contribución, no ingresos brutos.** `contribution = importe neto − unidades netas × costo unitario`, con el costo unitario **confiable** de W5-B (`UnitEconomicsLoader`, lectura as-of al corte; reglas en [`ECONOMIC_PRIORITIZATION.md`](ECONOMIC_PRIORITIZATION.md)).
Sin costo confiable en algún producto comprado la contribución es **NULL** con su motivo (`contribution_status = unknown`, `contribution_reasons`): no se inventa un costo ni se presenta el ingreso como contribución. Sin compra la contribución es 0.
Verificado: `test_contribution_is_null_with_its_reason_when_the_cost_is_not_reliable_and_zero_when_nothing_was_bought`.

**Sin doble conteo.** Varias recomendaciones pueden observar la misma línea de venta (horizontes de 7 y 30 días del mismo cliente y producto, contacto y recuperación). Cada resultado guarda sus líneas (`evidence.lines`: venta, línea, producto, unidades, importe, devolución, posterior a la acción; hasta 100) y el
informe **deduplica por línea de venta distinta** (cliente × producto × venta × línea) antes de netear devoluciones y valorar con el costo confiable: `summary.observed` (ventana completa) y `summary.associated` (solo lo posterior a la acción), con clientes distintos y líneas distintas. La suma por recomendación queda en `per_recommendation` como **detalle no aditivo**.
Un resultado sin el detalle de líneas (anterior a este formato, o con más de 100) se suma por recomendación y se declara (`rows_without_line_detail`). Verificado: `test_overlapping_recommendations_do_not_double_count_the_same_sales_in_the_headline_totals`, `test_the_associated_totals_are_deduplicated_too`,
`test_a_unit_cost_that_is_not_reliable_leaves_the_deduplicated_contribution_unknown_not_zero`.

**Oportunidad potencial por tipo y horizonte.** `summary.potential.cells` trae una celda por (tipo, horizonte); **no hay total** entre tipos ni horizontes (sumar reposición con contacto, o los 7 con los 30 días, contaría dos veces la misma venta). Verificado: `test_the_potential_is_reported_per_kind_and_horizon_and_never_as_one_total_across_incompatible_kinds`.

**Costos.** *Contacto*: el `action_cost` de cada recomendación atendida dentro de la ventana (`contact_cost`); sin costo configurado es **desconocido (NULL), no cero**, y el neto no se calcula. El costo que entra al ROI sale de **las unidades de tratamiento del propio análisis**, **no** del filtro de tipo (con `kind=…` el neto no cambia).
Un solo contacto que cubre varias recomendaciones del mismo cliente se cuenta una vez **por recomendación** (cota conservadora). *Uso de plataforma*: `tenant_usage_records` (W4) son **cantidades**; solo se convierten en dinero con los precios por unidad que la empresa configure (`outcomes.usage_prices`); sin precios `total_cost` es `null` (`no_price_model`).
*Costos de implantación*: no se modelan (DISEÑADO). Verificado: `test_a_kind_filter_does_not_change_the_cost_or_the_net_of_the_experiment`.

**`roi.status`** (siempre `roi.proven = false` y `roi.causal_claims = 0`):

| Estado | Cuándo | Qué muestra |
|---|---|---|
| `potential_unproven` | Sin experimento con estimación (sin control, muestra insuficiente, …) | «Oportunidad potencial, atribución no demostrada». **`value = null`**: ninguna cifra de ROI |
| `incremental_estimated` | Un experimento con estimación y contribución estimable | Incremento de contribución, costos de contacto, **neto con intervalo** y razón ROI (neto / costo, si hay costo). `significant = false` si el intervalo incluye 0. Con un costo de contacto desconocido el neto queda `null` (`contact_cost_unknown`) |
| `incremental_conversion_only` | Hay estimación de conversión pero la contribución no es estimable (costo no confiable, pocos compradores, varianza nula, población insuficiente) | Solo la conversión; ninguna cifra monetaria |
| `multiple_experiments` | Más de un experimento u horizonte con estimación | Pide filtrar por `experiment_key` y `horizon_days`: los clientes se solapan entre horizontes y sumar contaría dos veces |

**Quien no puede aprobar no recibe información del control ni del experimento** (decisión del arquitecto; `ExperimentRedaction` de W5-B): en `GET /decisions/roi` se omiten `experiments` (muestras, fracciones de control, contaminación, SRM), `funnel.control_held_out`, `by_action_kind.control`, `control_baseline` y las filas del control dentro de
los desgloses (por responsable, segmento…); `roi` muestra solo su estado, etiqueta y método, sin datos por grupo; filtrar por `experiment_key` no devuelve nada. En `GET /decisions/outcomes` la evidencia no trae `experiment`, `itt`, `unit` ni ninguna clave de experimento. Quien aprueba lo conserva todo.
Verificado: `test_whoever_cannot_approve_gets_no_control_or_experiment_information_anywhere_in_the_roi`, `test_the_control_group_is_listed_only_to_those_who_approve`.

**Periodo acotado.** Filtra por el corte (`as_of`) de la recomendación. **Sin `from`/`to`, los últimos 180 días**; el rango no puede superar **730 días** (422). Las filas se leen por bloques de 5 000 con paginación por clave (memoria acotada). Verificado: `test_the_roi_defaults_to_the_last_180_days_of_the_cut_and_caps_the_explicit_range_at_730`.
Con 200 000 resultados (100 000 unidades de experimento, caso de estrés de la revisión): ver § 15 para los tiempos.

**Origen de los datos.** `data_origin` (de `tenants.settings.data_origin`: `real`, `demo` o `simulated`; **las empresas de demostración lo declaran `demo`**) acompaña al informe. Sin declarar **no se supone real**: el ROI lleva `data_origin_warning` («origen de los datos no verificado»); con `demo`/`simulated` el aviso dice que los importes
**NO son resultados reales**. Solo `real` no lleva aviso. Verificado: `test_the_origin_of_the_data_is_declared_and_demo_or_simulated_results_are_never_shown_as_real`.

**Métricas y desgloses.** Embudo: recomendaciones, reemplazadas (no cuentan como oportunidades distintas), identificadas, accionables, medidas, previstas pendientes, atendidas, **tasa de acción** (atendidas / medidas accionables) y **tasa de respuesta** (atendidas con compra posterior a la acción / atendidas; asociación, no efecto); ventas y contribución
observadas y asociadas; quiebres y «sin salida» observados; desglose por **tipo, horizonte, segmento del cliente o responsable** (`group_by`) y filtros por tipo, horizonte, experimento y periodo. Verificado: `test_the_roi_filters_and_breaks_down_by_kind_horizon_segment_and_owner`, `test_superseded_recommendations_are_not_counted_as_separate_opportunities`.

## 11. API

Prefijo `/api/v1`, `auth:sanctum` + empresa activa (`X-Tenant-ID`), habilidad **`decisions.view`** (todos los roles actuales). **Solo lectura**: cualquier otro método responde 405. Detalle de campos —incluidas las claves adicionales de la respuesta (`after_action`, `data_origin`, `sample_ratio`, `dropped_units`,
`tail_diagnostic`, `period_*`, `potential.cells`, `per_recommendation`…)— en `docs/openapi/v2.yaml` (regenerado en `predictily-api.yaml`).

| Método y ruta | Descripción |
|---|---|
| `GET /decisions/outcomes` | Resultados medidos, paginados (`page`, `per_page` ≤ 100) y con filtros: `kind`, `classification`, `action_kind`, `observed_event` (listas por coma), `horizon_days`, `recommendation_id`, `customer_id`, `product_id`, `assigned_user_id`, `contribution_known`, `from`/`to` (periodo = corte de la recomendación), `sort`; y, solo con `decisions.approve`, `experiment_key` y `experiment_group` |
| `GET /decisions/roi` | ROI honesto con su método: `method`, `filters`, `summary`, `experiments` (solo con `decisions.approve`), `costs`, `roi`, `breakdown` (`group_by` = `kind` · `horizon` · `segment` · `owner`) y `limitations`. Filtros: `kind`, `horizon_days`, `experiment_key` (solo con `decisions.approve`), `from`, `to` |

- **Nombres de ruta.** El prompt del paquete decía `GET /api/v1/outcomes` y `GET /api/v1/outcomes/summary`; por decisión del arquitecto (g) se publican como **`GET /api/v1/decisions/outcomes`** y **`GET /api/v1/decisions/roi`**, junto a las demás rutas de decisiones y con la misma habilidad `decisions.view`. Es una **desviación elegida y documentada**:
  `/outcomes/summary` es `/decisions/roi`. No existe `/api/v1/outcomes`.
- **Aislamiento.** Los modelos llevan el global scope de empresa y el informe filtra explícitamente por la empresa activa; un usuario de otra empresa recibe 404 al cambiar la cabecera. Verificado: `test_one_company_never_sees_the_outcomes_or_the_roi_of_another`, `test_one_company_never_reads_the_sales_or_recommendations_of_another`.
- **Grupo de control y experimento** (§ 10): para quien no puede aprobar no se listan los resultados del control, no aparece `experiment_group`, la evidencia no trae información del experimento y los filtros de experimento no devuelven nada.
- **Sin PII.** El comprador se identifica por id y código externo; `observed_ref` es `sale:<id>`; el motivo de un rechazo (texto libre) no se copia a la evidencia.
- Valores desconocidos en los filtros de lista **no se convierten en «todos»**: no devuelven nada. Fechas inválidas, rangos invertidos, periodos de más de 730 días y `per_page` > 100 responden 422.

## 12. Comando y programación

| Comando | Programación (America/Guayaquil) | Qué hace |
|---|---|---|
| `predictily:outcomes-measure [--tenant=ID] [--dry-run] [--lag=N]` | diario **06:10** (después de `decisions-generate` 05:30 y del vencimiento horario `decisions-expire` :25) | Mide, por empresa activa, las recomendaciones cuya ventana ya cerró más el margen; idempotente; una empresa o un grupo con error no detiene a las demás |

`withoutOverlapping()->onOneServer()`. `--lag` (0–90) sustituye el margen de la empresa en esa ejecución. **Códigos de salida**: 0 correcto; **1 si algún grupo o empresa falló** (la programación lo detecta; las recomendaciones de ese grupo no se midieron y se reintentan en la siguiente ejecución); **2 si `--tenant` no es un entero positivo**
(`abc`, `1x`, `-3`, `0`, `1.5`), con un mensaje; `--lag` inválido sale con 1 y un mensaje. La salida lleva un **código estable** (`error=outcomes_group_failed`, `error=outcomes_tenant_failed`) y **nunca el texto de la excepción** (puede traer SQL o datos): el detalle queda en el registro de la aplicación.
Verificado: `test_the_artisan_command_measures_per_tenant_supports_dry_run_and_validates_the_lag`, `test_a_tenant_option_that_is_not_a_positive_integer_exits_with_code_2_and_a_message`, `test_a_failing_group_makes_the_command_exit_non_zero_with_a_stable_code_and_no_raw_error`, `test_the_command_is_scheduled_after_the_decisions_are_generated`.

## 13. Parámetros (todos provisionales, «a aprobar en el piloto»)

Valores por defecto en `backend/config/outcomes.php`; por empresa en `tenants.settings.outcomes`, **dentro de los pisos** (un valor fuera de rango se ignora y vale el de la configuración). No hay endpoint de escritura (la API de outcomes es de solo lectura).

| Clave | Por defecto | Piso | Justificación |
|---|---|---|---|
| `settling_lag_days` | 3 | 0 | Margen para documentos que se cargan tarde: cubre la carga diaria con un fin de semana. Aprobar con el retraso real de ingestión de cada fuente |
| `min_group_size` | 30 | **20** | Por debajo, las aproximaciones del intervalo no son fiables. Regla práctica habitual; aprobar con la potencia que necesite cada experimento (§ 9) |
| `min_buyers_per_arm` | 10 | **5** | Compradores mínimos por grupo para publicar CUALQUIER intervalo monetario: con pocos compradores el intervalo es una ilusión de precisión |
| `confidence_level` | 0,95 | (0,80 · 0,90 · 0,95 · 0,99) | Nivel de los intervalos. Convención, no una garantía |
| `bootstrap_resamples` | 2 000 | **500** | Estabiliza los percentiles 2,5 y 97,5 (error de simulación ≈ 0,02 desviaciones estándar) |
| `bootstrap_max_draws` (solo `config`) | 40 000 000 | — | Tope de números aleatorios por intervalo (≈ 4 s); si B remuestreos lo superan, B baja hasta 500; por debajo no se publica (`bootstrap_budget_exceeded`) |
| `usage_prices` | `{}` | — | Precio por unidad de cada métrica de uso. Vacío: el uso de plataforma no se convierte en dinero |
| `report_default_days` / `report_max_days` (solo `config`) | 180 / 730 | — | Periodo por omisión y máximo de `GET /decisions/roi` |

Los pisos viven en `config('outcomes.floors')`; solo las pruebas los bajan, con `config()`, para ejercitar la mecánica con muestras pequeñas (`IttAnalysisTest::test_the_floors_stop_a_company_from_configuring_a_meaningless_minimum` los verifica con sus valores de producción).

## 14. Estado frente a los requisitos

| Requisito | Estado | Evidencia |
|---|---|---|
| V25-OUT-001 Servicio de medición por empresa | IMPLEMENTADO / VERIFICADO | `OutcomeMeasurementTest` (todos sus métodos) |
| V25-OUT-002 Recomendación → tarea → decisión → acción → evento posterior con ids, fechas y fuente | IMPLEMENTADO / VERIFICADO (la «tarea» es la asignación; la «decisión», el estado y los eventos del workflow; ids, fechas y fuente en `evidence`) | `test_the_list_returns_the_measured_outcomes_with_their_honest_classification_and_evidence` |
| V25-OUT-003 Cinco clasificaciones | IMPLEMENTADO / VERIFICADO para `forecast` (virtual), `observed` y `temporally_associated` en filas e `incremental_estimated` como resultado AGREGADO; **`causally_attributable`: DISEÑADO**, nunca se produce (§ 9) | § 5 |
| V25-OUT-004 Rechazadas | IMPLEMENTADO / VERIFICADO (fecha en la evidencia; el motivo, en `decision_events`) | `test_rejected_expired_not_contacted_and_superseded_recommendations_are_measured_too` |
| V25-OUT-005 No contactadas · -006 caducadas | IMPLEMENTADO / VERIFICADO | misma prueba |
| V25-OUT-007 Identificadas/atendidas, tasa de acción y de respuesta, desglosables | IMPLEMENTADO / VERIFICADO | `test_without_a_control_group_the_roi_is_potential_unproven_and_never_proven`, `test_the_roi_filters_and_breaks_down_by_kind_horizon_segment_and_owner` |
| V25-OUT-008 Ventas posteriores como observación, no causa | IMPLEMENTADO / VERIFICADO (asociado = solo lo posterior a la acción; deduplicado) | § 5, § 10 |
| V25-OUT-009 Margen de contribución conocido; costo desconocido ⇒ desconocido | IMPLEMENTADO / VERIFICADO | `test_contribution_is_null_with_its_reason_when_the_cost_is_not_reliable_and_zero_when_nothing_was_bought` |
| V25-OUT-010 Costos de contacto | IMPLEMENTADO / VERIFICADO con el `action_cost` configurado por empresa; el tiempo y canal reales de cada contacto: **NO IMPLEMENTADO** | `test_the_contact_cost_comes_from_the_recommendation_only_when_an_action_was_registered`, `test_an_action_without_a_configured_contact_cost_leaves_the_cost_unknown_not_zero` |
| V25-OUT-011 Ahorro comprobado | DISEÑADO / NO IMPLEMENTADO: requiere órdenes de compra efectivas (V25-SUP-051) para comparar compra efectiva frente a alternativa | — |
| V25-OUT-012 Stockouts, exceso, rotación, capital inmovilizado | PARCIAL: quiebre y «sin salida» (`no_sell_through`) **observados** por producto con inventario leído as-of; exceso como diagnóstico, rotación y capital inmovilizado: DISEÑADO | `test_product_level_recommendations_are_measured_by_sales_and_stockouts_and_never_incremental`, § 3 |
| V25-OUT-013 Tiempo de trabajo | DISEÑADO / NO IMPLEMENTADO (derivable de `decision_events`: tiempo hasta contactar y de revisión) | — |
| V25-OUT-014 Coste de operación por empresa | PARCIAL: el informe trae las cantidades de uso de W4 y las monetiza solo con precios configurados; el modelo de costos de operación no es de este paquete | `test_a_known_cost_of_platform_usage_enters_the_net_only_when_prices_are_configured` |
| V25-OUT-015 Frecuencia de actualización y calidad junto a los resultados | PARCIAL: cada resultado trae sus líneas leídas, las tardías, el margen y los motivos de costo no confiable; el enlace con los reportes de calidad de W2: DISEÑADO | `test_a_late_document_is_not_visible_before_it_was_ingested_but_one_ingested_within_the_lag_is` |
| V25-OUT-016 Desglose por segmento, horizonte y responsable | IMPLEMENTADO / VERIFICADO | `test_the_roi_filters_and_breaks_down_by_kind_horizon_segment_and_owner` |
| V25-OUT-017 ROI sobre contribución con costos, intervalos y limitaciones | IMPLEMENTADO / VERIFICADO (costos de contacto; uso con precios configurados; implantación: no). La cobertura real del intervalo monetario con colas pesadas es inferior a la nominal (§ 7) | § 10 |
| V25-OUT-018 Grupo de control con asignación registrada al generar | IMPLEMENTADO / VERIFICADO (asignación: W5-B; análisis: W5-C); **estratificación: DISEÑADA** | `test_the_assignment_made_by_the_decision_engine_is_the_one_analysed` |
| V25-OUT-019 Intención de tratamiento | IMPLEMENTADO / VERIFICADO | `IttOutcomeTest`, `IttAnalysisTest`, `IttEstimatorTest`, `BootstrapIntervalTest` |
| V25-OUT-020 Cuasiexperimentos documentados | DISEÑADO / NO IMPLEMENTADO (§ 9) | — |
| V25-OUT-021 Contaminación y sesgo de selección | PARCIAL (§ 8): controles contaminados, fracciones mezcladas, SRM y unidades descartadas se detectan o informan; contaminación entre vendedores y balance de covariables: DISEÑADO | `test_the_analysis_follows_the_assigned_group_not_who_was_actually_contacted`, `test_a_sample_ratio_mismatch_is_flagged_in_the_experiment_and_in_the_roi_reasons` |
| V25-OUT-022 Sin causalidad demostrable ⇒ observación y oportunidad | IMPLEMENTADO / VERIFICADO | `test_without_a_control_group_the_roi_is_potential_unproven_and_never_proven` |
| V25-OUT-023 Datos de demostración/simulados nunca como reales | IMPLEMENTADO / VERIFICADO: el informe respeta `data_origin` (las empresas demo lo declaran `demo`) y avisa «origen no verificado» si no está declarado | `test_the_origin_of_the_data_is_declared_and_demo_or_simulated_results_are_never_shown_as_real` |
| V25-OUT-024 Compra posterior = asociación temporal | IMPLEMENTADO / VERIFICADO | `test_a_purchase_after_a_registered_action_is_temporally_associated_and_never_incremental` |
| V25-OUT-025 Resultados tardíos sin reescribir el pasado ni fugar | IMPLEMENTADO / VERIFICADO (lectura as-of; lo posterior se informa aparte). **Re-medición versionada de un resultado ya escrito: DISEÑADA** | `test_a_late_document_is_not_visible_before_it_was_ingested_but_one_ingested_within_the_lag_is` |
| V25-OUT-026 Devoluciones, notas de crédito y reversiones ajustan el outcome y se reportan | IMPLEMENTADO / VERIFICADO para lo **conocido al corte de conocimiento**, aunque su fecha sea posterior al cierre de la ventana (se restan de las compras de la ventana y se informan en `returns_after_window`). Lo posterior al corte **se reporta** (`late_returned_units_not_counted`, `lines_revised_or_removed_after_cutoff`) sin ajustar el resultado (re-medición: DISEÑADA) | `test_returns_and_credit_notes_dated_after_the_window_but_known_before_the_cutoff_are_netted_and_reported`, `test_returns_are_netted_and_never_create_negative_sales`, `test_a_document_voided_and_a_return_ingested_after_the_cutoff_do_not_rewrite_the_outcome_but_are_reported` |
| V25-OUT-027 Dashboard que separa oportunidad, observación e incremento | API IMPLEMENTADA / VERIFICADA (`GET /decisions/roi`); la **UI es de W5-E** | `test_the_forecast_state_is_virtual_recommendations_not_yet_measurable_are_reported_as_forecast` |
| V25-OUT-028 Piloto con el cliente: duración, objetivos y GO/NO-GO | **BLOQUEADO**: requiere un piloto con datos reales | — |
| V25-OUT-029 Ejecutadas y no ejecutadas por separado, misma ventana y covariables previas | PARCIAL: misma ventana por construcción y comparación solo por ITT; el balance de covariables previas: DISEÑADO | `test_rejected_expired_not_contacted_and_superseded_recommendations_are_measured_too` |
| Aislamiento entre empresas | IMPLEMENTADO / VERIFICADO | `test_one_company_never_sees_the_outcomes_or_the_roi_of_another`, `test_one_company_never_reads_the_sales_or_recommendations_of_another` |
| Sin información del experimento para quien no aprueba | IMPLEMENTADO / VERIFICADO | `test_whoever_cannot_approve_gets_no_control_or_experiment_information_anywhere_in_the_roi` |
| Sin escrituras en el ERP ni mensajes | IMPLEMENTADO / VERIFICADO | `test_measuring_never_calls_the_erp_sends_messages_or_creates_recommendations` |
| **Resultados reales** (conversión, incremento y ROI con clientes) | **BLOQUEADO**: requiere un piloto con datos reales y un periodo de observación suficiente para el ciclo de recompra; nada de este documento es un resultado real | — |

## 15. Limitaciones y supuestos conocidos

- **Intervalo monetario con colas pesadas.** El intervalo percentil bootstrap cubre menos que su nivel nominal cuando pocos compradores concentran el importe (§ 7: 74–94 % medido); no es mejor que Welch en la simulación. Se exige un mínimo de compradores, se advierte la cola (`tail_diagnostic`) y el intervalo debe leerse como indicativo.
- La contribución observada usa **un** costo unitario por producto (el confiable al corte), no el costo de cada línea; las compras con costos muy distintos dentro de la ventana se promedian.
- `action_at` es la primera acción **registrada**: si la acción real fue anterior, una compra intermedia se clasifica `observed` y no `temporally_associated` (conservador). Una acción registrada tarde (tras cerrar la ventana) no cuenta.
- El resultado es **inmutable**: la corrección tardía de datos (anulaciones, devoluciones y documentos posteriores al corte) se informa pero no se reaplica (re-medición versionada: DISEÑADA). Si el volumen de `late_lines_not_counted` es alto, el margen de espera es demasiado corto para esa fuente.
- **Borrado definitivo y cascada.** `decision_outcomes.recommendation_id` y `decision_recommendations.customer_id/product_id` tienen `ON DELETE CASCADE` (contrato del arquitecto): un **borrado definitivo** de un cliente o producto (p. ej. una supresión por la LOPDP) borra en cascada sus recomendaciones y sus resultados, y con ello el historial de
  medición que los sustentaba (los agregados del informe cambian). La supresión de datos personales sin perder la medición exigiría anonimizar al cliente en vez de borrarlo (DISEÑADO).
- El análisis de conversión y de contribución se limita a **contacto y recuperación** (los tipos que participan en el experimento); los tipos por producto no tienen control y se informan como observación.
- Los importes por recomendación **no son aditivos** entre ventanas solapadas del mismo cliente y producto ni entre tipos distintos: los totales del informe se deduplican por línea de venta; el detalle por recomendación no es aditivo.
- Un cliente entra una vez por experimento y horizonte: un experimento largo con muchos ciclos de recompra del mismo cliente no usa los ciclos posteriores.
- **Rendimiento de `GET /decisions/roi`.** Medido con 200 000 resultados (100 000 unidades de experimento, el caso de estrés de la revisión) y la máquina de pruebas con carga media (≈ 6): memoria máxima ≈ **135 MB** (antes ≈ 217 MB; las filas se leen por bloques de 5 000 con paginación por clave y la evidencia se descomprime una vez por fila),
  primera llamada ≈ **7,5 s** y las siguientes ≈ **4,5 s** (antes ≈ 3,5 s sin ITT por unidad ni bootstrap). La primera incluye el bootstrap: con 100 000 unidades solo caben ≈ 670 de los 2 000 remuestreos dentro del tope de cómputo (`bootstrap_max_draws`); después queda en caché por contenido. Un experimento mucho mayor no
  se publicaría en dinero (`bootstrap_budget_exceeded`): se acota con el periodo. Un acumulado materializado: DISEÑADO.

## 16. Verificación adversarial

- **Mutación** (81 mutaciones del código del paquete: lectura as-of, ventana, asociación posterior a la acción, compuertas del ITT, bootstrap, redacción del experimento, doble conteo, devoluciones, inventario, candados, unicidad, comando…): **78 muertas, 3 sobrevivientes**. Los tres son **equivalentes por redundancia** y no indican una brecha: `M07` (el primer filtro de `eligible()` ya no excluye lo medido) y `M07b`
  (`eligibleGroups()` tampoco) quedan cubiertas por la comprobación POSTERIOR al bloqueo, que sí está probada (`M07c`, quitarla, muere con `test_a_recommendation_measured_by_another_process_while_this_one_waits_for_its_row_is_skipped_after_the_lock`; quitar todas las guardas a la vez, `M07d`, también muere); `M40` (el lector de ventas sin el filtro de empresa) es equivalente
  porque los ids de cliente y producto son claves primarias globales (el filtro es defensa en profundidad). Antes de las correcciones sobrevivían 12 de 53.
- **Sondas de la revisión** (caso H1: 800 antes de la acción y 8 después ⇒ asociado 8 y contribución 2; caso H2: nadie compra ⇒ conversión con su intervalo de Newcombe y contribución `not_estimable`; E1: 66 000 recomendaciones en un grupo ⇒ 66 000 medidas, sin errores; candado y fila retenidos por otra conexión ⇒ el proceso espera y luego mide).
- **Contrato OpenAPI**: las respuestas reales (administrador y solo consulta, con experimento estimado, bootstrap y desglose por responsable) se validaron contra los esquemas de `v2.yaml` sin una sola clave sin documentar ni tipo distinto.
