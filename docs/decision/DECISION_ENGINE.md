# Decision Engine y workflow de decisiones

Fecha: 1-nov-2026 · Rama `v25/w1-cierre-y-w2` · V2.5 · W5-B · Fuentes: ADR-V25-13 (Decision Engine y workflow con segregación de funciones), ADR-V25-14 (outcomes y ROI honestos; la asignación al experimento), §11 de la especificación V2.5
y los requisitos `V25-DEC-001..039` de `docs/roadmap/audit/2026-10-31/supply_decision.json`. La fórmula del score, el costo confiable y los umbrales están en [`ECONOMIC_PRIORITIZATION.md`](ECONOMIC_PRIORITIZATION.md); lo que Predictily
**no** hace sobre el ERP y las fases futuras de ejecución controlada, en [`CONTROLLED_ACTIONS.md`](CONTROLLED_ACTIONS.md); la medición posterior (outcomes y ROI) es de W5-C.

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** (no se puede construir o verificar en este entorno) · **NO APLICA**.

## 1. Qué es y qué no es

`PredictilyDecisionEngine` (clase `App\Services\Decision\DecisionEngine`) **combina salidas ya persistidas** de Demand (recomendaciones de reposición y pronóstico), Customer (predicciones y actividad), Supply (plan, faltantes, presupuesto)
y el inventario con las reglas de la empresa, y las convierte en recomendaciones accionables (`decision_recommendations`) con su workflow. **No entrena modelos, no recalcula pronósticos ni planes y no genera números sin fuente**: cada cifra de
una recomendación resuelve a una fila persistida (corrida, recomendación de reposición, línea de plan, foto de inventario, línea de venta as-of) o a un parámetro registrado de la empresa. **No escribe en el ERP ni envía mensajes**
([`CONTROLLED_ACTIONS.md`](CONTROLLED_ACTIONS.md)).

La tabla `decision_recommendations` es distinta de `recommendations` (etapa 1: reposición por serie), que se conserva intacta y es una de las fuentes. Contrato de tablas: migración `2026_03_13_000100` del arquitecto (no modificada).

## 2. Piezas

| Pieza | Archivo | Responsabilidad |
|---|---|---|
| `DecisionEngine` | `backend/app/Services/Decision/DecisionEngine.php` | Implementa `App\Contracts\Decision\RecommendationSink`. Valida, enriquece, puntúa y persiste (idempotente). Registrado en `AppServiceProvider` |
| `ScoreFormula` | `.../ScoreFormula.php` | Fórmula versionada (SHA-256 de sus parámetros), prioridades por posición |
| `UnitEconomicsLoader` | `.../UnitEconomicsLoader.php` | Precio neto y costo unitario as-of y la confiabilidad del costo |
| `InventoryAvailabilityLoader` | `.../InventoryAvailabilityLoader.php` | Disponibilidad al corte (existencias − reservado; el tránsito aparte; desconocido ≠ cero) |
| `ExperimentAssigner` | `.../ExperimentAssigner.php` | Asignación tratamiento/control reproducible por cliente |
| `ExperimentRedaction` | `.../ExperimentRedaction.php` | Quita `experiment`, `experiment_group`, `experiment_key`, `unit_key` y `unit_type` de lo que se muestra a quien no tiene `decisions.approve` (lo guardado no se toca) |
| `AggregateDemandLoader` | `.../AggregateDemandLoader.php` | Demanda agregada pronosticada por producto (Σ de `forecast_values.yhat` del nivel producto); la usan el desajuste inventario-demanda y la preparación de abastecimiento del Radar |
| `Workflow` + `TransitionRules` | `.../Workflow.php`, `.../TransitionRules.php` | Tabla de transiciones, habilidades, segregación, aprobación sensible, idempotencia, bloqueo optimista, auditoría |
| `DecisionExpirer` | `.../DecisionExpirer.php` | Vencimiento programado |
| `DecisionGenerator` + `Generators/*` | `.../DecisionGenerator.php` | Genera por empresa desde lo persistido (cliente, reposición, inventario-demanda, presupuesto) |
| `DecisionSettings` | `.../DecisionSettings.php` | Parámetros por empresa en `tenants.settings.decision` (todos provisionales) |
| API | `backend/routes/api/decision.php` | `/api/v1/decisions…` |
| Comandos | `backend/app/Console/Commands/Decision/*` | `predictily:decisions-generate`, `predictily:decisions-expire` |

## 3. De dónde sale cada recomendación (sin recalcular)

| Tipo (`kind`) | Entidad | Fuente persistida | Cuándo se genera | Valor |
|---|---|---|---|---|
| `contact` | cliente × producto, por horizonte configurado | `customer_predictions` de la última corrida de predicción de producción (`CustomerRunService::latestPrediction`) | Probabilidad ≥ mínimo de la empresa (0,20 provisional); las abstenciones (probabilidad NULL), los compradores genéricos y los eliminados no generan | E(nº de pedidos) × contribución por pedido (§ de la fórmula) |
| `recovery` | cliente | `customer_activity` de esa corrida | `inactive_by_pattern`: ausencia respecto al patrón propio del cliente, **no abandono** | Caída observada del gasto neto entre las dos ventanas del Customer Core (importe, no contribución) |
| `customer_risk` | cliente | `customer_activity` | `at_risk`, o descenso de comportamiento significativo de quien aún compra; con `insufficient_history` no se genera nada | Igual que recuperación; informativa, no se contacta |
| `replenishment` | producto × sucursal | `recommendations` (etapa 1) de la corrida de pronóstico vigente: tipos `reorder` y `stockout_risk` abiertas; enriquecida con las líneas de `supply_plan_lines` **solo si el plan se construyó sobre esa misma corrida de pronóstico** | `suggested_qty > 0` y disponible conocido | Unidades en riesgo = max(demanda media en plazo + revisión − disponible, 0) × precio as-of; contribución con costo confiable |
| `inventory_mismatch` | producto | `customer_predictions` (demanda esperada de compradores) + `inventory_snapshots`; el exceso, además `recommendations` tipo `overstock` y el pronóstico agregado (`forecast_values`) | Faltante: demanda esperada de los compradores > disponible. Exceso: `overstock` y demanda < fracción configurada de lo disponible, donde la demanda es la **mayor** entre los compradores identificados y el pronóstico agregado; si no se conoce **ninguna** de las dos, no se recomienda nada (`demand_unknown`: sin datos no hay «demanda cero») | Faltante valorado al precio medio ponderado; el exceso **no** tiene valor estimado (score vacío) |
| `budget_constraint` | producto × sucursal | `supply_plan_shortfalls` con `reason = budget` del plan vigente (+ `purchase_budgets` en el contexto) | Faltante por presupuesto > 0 | Unidades faltantes × precio; costo de cubrirlas con el costo unitario de las líneas del plan (NULL si no hay) |
| `scenario` | libre | solo entra por `RecommendationSink::propose()` | — | Lo que aporte quien propone, sujeto a las mismas reglas |

**Acotado y estable.** Cada generador trae solo los candidatos más urgentes (`max_contact_per_horizon` para clientes; `max_supply_candidates`, 500 por defecto, para reposición, inventario-demanda y presupuesto): el orden previo al score es por importe esperado, quiebre probable, faltante o demanda no cubierta, y el score final decide dentro del tope. La recuperación y el riesgo a nivel de cliente llevan un horizonte propio (el predeterminado de la empresa si la corrida lo trae; si no, el mayor de la corrida), **no** uno de `horizons`: cambiar o reordenar los horizontes de contacto nunca cambia su huella ni duplica filas. Verificado: `DecisionReviewBoundsTest` (`test_customer_level_opportunities_keep_their_identity_when_the_contact_horizons_change`, `test_the_customer_level_candidates_are_capped_by_the_per_horizon_limit`, `test_the_supply_candidates_are_capped_and_keep_the_most_urgent_ones`, y los tres de demanda: `test_an_overstocked_product_with_aggregate_forecast_demand_is_not_an_excess_without_demand`, `test_an_overstocked_product_with_little_aggregate_demand_is_an_excess_that_says_where_the_demand_came_from`, `test_when_neither_buyers_nor_a_forecast_are_known_the_demand_is_unknown_and_nothing_is_recommended`).

Una fuente vencida (su `expires_at` ya pasó) no genera recomendaciones ya vencidas: se informa `source_expired`. Sin corrida de predicción el informe dice `no_customer_prediction_run`; sin pronóstico, `no_completed_forecast_run`; con un plan de
otra corrida de pronóstico, `plan_not_built_on_current_forecast_run` (no se mezcla).

**Un productor por tipo de oportunidad (decisión del arquitecto; ADR-V25-12: el Radar es la fachada sobre Customer Core).** Los **contactos y recuperaciones cliente × producto** los produce **solo el Radar** (`predictily:radar-materialize`, 04:50) por `RecommendationSink::propose()`.
`predictily:decisions-generate` genera la **recuperación y el riesgo a nivel de cliente** y todo lo derivado de Supply (reposición, inventario-demanda, presupuesto). Lo decide el parámetro por empresa `contact_source`: **`radar` por defecto**; con `radar`, `decisions-generate`
no genera contactos cliente × producto (informa `produced_by_radar`). El valor `engine` se conserva como alternativa documentada y probada (una empresa sin Radar: el motor los genera con su propia selección). Con cualquiera de los dos, o con los dos, **nunca se duplica una
oportunidad**: ambos construyen la misma huella de idempotencia (tipo, corte, horizonte, entidad, corrida de origen). Verificado: `DecisionProducersAndPriorityTest::test_running_the_radar_and_the_engine_in_either_order_never_duplicates_an_opportunity`,
`DecisionGenerationTest::test_by_default_the_radar_is_the_only_producer_of_customer_product_contacts`.

## 4. `RecommendationSink::propose()` (contrato estable)

`propose(array $attributes): DecisionRecommendation` es idempotente y es la **única** vía de escritura de `decision_recommendations`.

- **Obligatorios:** `kind`, `as_of` (AAAA-MM-DD), `horizon_days` (1–730), `entity_type` y los ids que ese tipo de entidad exige (`customer_product`: cliente y producto; `customer`: cliente; `product_branch`: producto y sucursal; `product`: producto).
- **Opcionales de §11:** `source_core_run_id`, `source_forecast_run_id`, `data_snapshot_id`, `model_versions`, `probability` (0–1), `interval`, `assumptions`, `constraints`, `availability`, `action_cost`, `expected_gross_value`, `explanation`, `policy`, `next_step`.
- **Extras que el motor acepta** (además del docblock de la interfaz): `expected_units` y `unit_price` (con ellos el motor deriva el importe y la contribución), `uncertainty`, `expires_at`, `owner_user_id`,
  `proposed_by_user_id` (la propuso una persona: queda sujeta a la segregación de funciones) y `agenda_status`. Ninguno es obligatorio.
- **Lo que el motor decide y no se puede imponer:** `expected_contribution` (se **ignora** lo que traiga quien propone: siempre se deriva, y solo con costo confiable), `incremental_estimate`/`incremental_evidence` (siempre NULL: solo existen con
  evidencia de uplift), `attribution_label` (`potential_unproven`), `score` y `score_formula_version`, `status` (`suggested`), `experiment_group`, `idempotency_key`, `tenant_id`.
- **Validación:** los ids (cliente, producto, sucursal, corridas, personas) deben ser de la empresa activa; los de otra empresa se rechazan con `ValidationException` (422 por la API). Sin empresa en contexto la consulta falla (fail-closed).
- **Resultado:** el modelo persistido. `wasRecentlyCreated` es `true` solo cuando creó la fila; con una huella existente devuelve la existente (`false`) sin escribir nada.
- **Qué escribe:** la recomendación, la asignación al experimento (si aplica), el evento inicial en `decision_events` (con `payload.experiment = {experiment_key, group}`: la medición de outcomes lo necesita; **se guarda siempre y solo se oculta al presentarlo**, § 9) y el vencimiento de las anteriores sin tocar del mismo alcance, todo en una transacción. Si el alcance ya tiene una recomendación **en curso** no crea sucesora: la refresca (§ 5).
- **Por lotes:** `DecisionEngine::proposeMany(array $batch)` (extensión del contrato; el Radar la usa si el sumidero la tiene) prepara un lote con **una** consulta de apoyo por tabla (existencia de ids, economía unitaria, disponibilidad), persiste cada elemento sin que uno que falla detenga a los demás y reordena cada grupo de prioridad **una vez**. `priority` ya no es un dato que se pueda imponer: lo calcula el motor (§ 5 de `ECONOMIC_PRIORITIZATION.md`). `constraints` se normaliza siempre a objetos con `code` (un texto suelto se guarda como `{code: note, note: …}`).

Verificado: `DecisionEngineProposalTest` (contrato completo, idempotencia, `wasRecentlyCreated`, validación y ids de otra empresa, contribución ignorada).

## 5. Idempotencia y reemplazo por insumos nuevos

`idempotency_key` = SHA-256 del JSON canónico de `(kind, as_of, horizon_days, [entity_type, customer_id, product_id, branch_id], [source_core_run_id, source_forecast_run_id])`. La huella de datos (`data_snapshot_id`) se guarda pero **no** entra en la
clave: se deriva de la corrida, y dejarla fuera hace que dos proponentes de la misma corrida coincidan. `UNIQUE (tenant_id, idempotency_key)` garantiza una sola fila aun con dos procesos simultáneos (el perdedor recibe la existente).

- **Mismos insumos** → misma fila, mismo evento, ninguna escritura (IMPLEMENTADO / VERIFICADO: `test_the_same_inputs_return_the_same_recommendation_without_duplicating_anything`, `test_generation_is_idempotent_the_second_run_creates_nothing`).
- **Insumos nuevos** (otra corrida, otro corte) → depende de si el alcance (tipo, entidad, horizonte, cliente, producto, sucursal) tiene trabajo **en curso**: `agenda_status ∈ {contactado, interesado, pospuesto}` o `status ∈ {assigned, reviewed, approved, in_progress}`.
  - **Sin trabajo en curso**: recomendación nueva; las anteriores del alcance con corte igual o anterior que **nadie ha tocado** (`suggested`) pasan a `expired` con un evento del sistema (`reason_code = superseded`, `superseded_by`).
  - **Con trabajo en curso**: **no se crea sucesora** (la lista de trabajo no puede mostrar dos accionables del mismo alcance ni perder el seguimiento): la recomendación en curso queda intacta (cifras, estado, seguimiento) y recibe un **evento `comment` de linaje** (`payload.kind = refresh`, la huella propuesta, la fuente nueva —corridas e instantánea de datos— y los valores nuevos: probabilidad, valor, contribución, costo, score y versión de fórmula), idempotente por (en curso, huella propuesta). Ese comentario **no cuenta para la versión** del bloqueo optimista (§ 7). Quien la trabaja decide; al terminar o vencer, la corrida siguiente crea la nueva. El generador y el Radar cuentan esto como «ya existente».
  Verificado: `test_new_inputs_create_a_new_recommendation_and_expire_the_previous_one_that_was_not_decided`, `test_while_a_recommendation_is_in_flight_newer_inputs_refresh_it_instead_of_replacing_it` (7 estados en curso), `test_once_the_in_flight_one_is_terminal_or_expired_the_next_run_creates_a_new_one`, `test_new_prediction_inputs_replace_the_untouched_ones_and_refresh_the_in_flight_one`, y de punta a punta con el motor real y el Radar `RadarReviewFixesTest::test_newer_predictions_refresh_the_opportunities_in_flight_instead_of_replacing_them`.
- Cambiar parámetros de la empresa (p. ej. el peso del costo no confiable) **no** reemite recomendaciones ya generadas del mismo corte; cada una conserva la `score_formula_version` con la que nació.
- **Mismo input, misma recomendación, mismo ranking, misma explicación:** `test_the_same_inputs_produce_the_same_recommendations_in_two_companies`.

## 6. Workflow

`suggested → assigned → reviewed → approved | rejected → in_progress → executed → measured → closed`, más `expired`. La tabla es `TransitionRules::RULES` (fuente única de la API, del servicio y de esta tabla); lo que no está aquí no existe
(409 `decision_transition_not_allowed`). Habilidades nuevas del sistema existente (`TenantRole`): `decisions.view` (todos los roles), `decisions.manage` (analista, administrador, propietario), `decisions.approve` (administrador, propietario).
Los roles comerciales gerente/vendedor llegan en W6; no se inventan aquí. **`expected_version` es obligatorio en TODA transición humana** (`TransitionRules::RULES[].version = true`; las del sistema —`measure`, `expire`— no lo llevan): 422 si falta, 409 `decision_version_conflict` si está desfasado. Verificado: `DecisionReviewFixesTest::test_every_human_transition_requires_the_expected_version` (una por acción) y `test_a_second_assignment_cannot_silently_overwrite_what_another_person_did`.

| Acción | Desde → Hacia | Habilidad | Reglas |
|---|---|---|---|
| `assign` | `suggested` → `assigned` | `decisions.manage` | `expected_version` obligatorio; `assignee_user_id`: miembro de la empresa con `decisions.manage`; las de control no se asignan |
| `reassign` | `assigned` → `assigned` | `decisions.manage` | Igual (una segunda asignación no pisa en silencio lo que otra persona hizo); deja un evento `assignment` |
| `review` | `assigned` → `reviewed` | `decisions.manage` | `expected_version` obligatorio |
| `approve` | `reviewed` → `approved` | `decisions.approve` | `expected_version`; **segregación**: no aprueba quien la propuso ni quien debe ejecutarla |
| `reject` | `reviewed` → `rejected` | `decisions.approve` | `expected_version` y `reason` (≥ 5 caracteres); misma segregación |
| `dismiss` | `suggested`, `assigned` → `rejected` | `decisions.manage` | `expected_version` y `reason` obligatorios (descartar no compromete nada) |
| `start` | `approved` → `in_progress` | `decisions.manage` | `expected_version`; quien aprobó no ejecuta; ejecuta la persona asignada o un supervisor con `decisions.approve` |
| `start_without_approval` | `reviewed` → `in_progress` | `decisions.manage` | `expected_version`; **solo si la acción no es sensible** (409 `decision_approval_required` si lo es) |
| `execute` | `in_progress` → `executed` | `decisions.manage` | `expected_version`; mismas reglas de ejecución. Es un **registro**: Predictily no ejecuta nada |
| `measure` | `executed` → `measured` | — (sistema) | La pone la medición de outcomes (W5-C) con `Workflow::systemTransition`; una persona no puede declararla |
| `close` | `measured` → `closed` | `decisions.manage` | `expected_version` |
| `expire` | `suggested`, `assigned`, `reviewed`, `approved` → `expired` | — (sistema) | Por vigencia o por reemplazo; ver § 8 |

**Segregación de funciones** (independiente de la habilidad: un propietario tampoco aprueba lo suyo): quien **propone** (`proposed_by_user_id`) o debe **ejecutar** (`assigned_user_id`) no aprueba ni rechaza formalmente (403
`decision_segregation_of_duties`, `context.roles` = `proposer` y/o `executor`); quien **aprobó** no ejecuta (`context.roles = ['approver']`). (No existe una regla «el aprobador no recibe la asignación»: la asignación solo se hace desde `suggested`, cuando aún no hay aprobador; no se afirma lo que no puede ocurrir.) Quien **materializa a mano** las oportunidades del Radar (`POST /radar/opportunities/materialize`) queda como **proponente** (`proposed_by_kind = user`, `proposed_by_user_id`): no podrá aprobarlas; el comando programado propone como `system`. Verificado con dos y tres usuarios:
`test_the_proposer_cannot_approve_their_own_action_but_another_approver_can`, `test_whoever_must_execute_the_action_cannot_approve_it_and_whoever_approved_cannot_execute_it`,
`test_the_assignee_must_be_a_member_with_manage_ability`, `RadarReviewFixesTest::test_a_manual_materialization_records_the_user_as_the_proposer_and_the_proposer_cannot_approve_it`, `RadarReviewFixesTest::test_the_scheduled_command_proposes_as_the_system`.

**Acciones sensibles exigen aprobación**: `replenishment`, `inventory_mismatch`, `budget_constraint` y `scenario` por defecto; cualquier recomendación con una restricción `over_budget` (reposición cuyo plan no cubrió todo con el presupuesto); y lo
que la empresa configure (`approval_required_kinds`, `approval_cost_threshold`). Los contactos no la exigen por defecto (Predictily no envía el mensaje: la persona lo hace fuera). Verificado:
`test_a_sensitive_action_cannot_start_without_approval_but_a_contact_can`, `test_the_company_can_require_approval_for_contacts_or_above_a_cost_threshold`, `test_a_recommendation_over_budget_requires_approval_even_if_its_kind_does_not`.

**Habilidades por rol:** `test_abilities_by_role_are_enforced_on_every_transition` (consulta ve pero no actúa; analista gestiona pero no aprueba; sin sesión 401).

**Auditoría:** TODA transición deja una fila en `decision_events` (de, a, quién —usuario o sistema—, motivo, evidencia: acción, versión, habilidad, rol) en la misma transacción, y una entrada en `audit_logs` (`decision.<acción>`).
`decision_events` solo se inserta. Verificado: `test_a_sensitive_recommendation_walks_the_whole_workflow_and_every_transition_is_audited`.

### 6.1 Seguimiento de la agenda comercial → workflow (`radar-agenda-map-v1`)

El seguimiento del Radar (`POST /radar/opportunities/{id}/feedback`) registra el estado heredado (`agenda_status`) y un evento `feedback`; **además** pide al workflow la transición que ese estado implica, con `Workflow::applyImplied()`, conectado en
`AgendaFeedbackService::syncWorkflow()`. Se aplica **como cualquier otra transición**: `transition()` con las habilidades del rol del usuario, la segregación de funciones, la aprobación de acciones sensibles, el grupo de control, la idempotencia (clave `agenda-feedback:<id del evento>`:
un reintento exacto del seguimiento no repite la transición) y el bloqueo optimista (`expected_version` = el evento de seguimiento recién registrado). La transición deja su propio evento (`transition`, con su acción) y su auditoría.

**El seguimiento nunca se pierde por una limitación del workflow.** Si el workflow no admite la transición desde su estado actual, **se registra el seguimiento, no se mueve el workflow y se anota el motivo**. El resultado de la sincronización se guarda en un **segundo evento, de tipo `comment` (`payload.kind = workflow_sync`)**, insertado en la misma transacción y enlazado con el evento de seguimiento (`feedback_event_id`, `map_version`, `implied_workflow_status`, `workflow_sync`, `workflow_sync_reason`, `workflow_status_before/after`, `workflow_event_id`, `workflow_required_ability`); el evento de seguimiento queda **inmutable** (nunca se actualiza después de insertarlo). `POST …/feedback` devuelve ese resultado en `data.event.workflow_sync*`, y el historial del Radar lo muestra junto a su evento de seguimiento (`workflow_sync*`).

**Excepción deliberada: no se registra lo que el rol no podría hacer al cerrar.** Para los estados que **cierran** la oportunidad (`pedido_recibido` → `executed`, `descartado` → `rejected`) el Radar consulta antes `Workflow::impliedBlockedByAuthorization()`: si la transición implicada estaría prohibida por la habilidad del rol (`forbidden`) o por la segregación de funciones, el seguimiento **se rechaza con 403 sin cambiar nada** (ni `agenda_status`, ni evento, ni workflow): de lo contrario un rol sin permiso dejaría la oportunidad cerrada y fuera de la agenda sin que el workflow la haya cerrado. `contactado`, `interesado` y `pospuesto` no cierran nada y siguen registrándose siempre. Además, **pospuesta** más allá del `expires_at` de la recomendación se rechaza con 422 `snooze_beyond_expiry`: no se promete reactivar lo que ya habrá vencido.

| Estado heredado → implicado | Estado actual del workflow | Resultado (`workflow_sync`) | Motivo (`workflow_sync_reason`) |
|---|---|---|---|
| `contactado`, `interesado` → `in_progress` | `reviewed` y no sensible | `applied` (`start_without_approval`) | — |
| | `approved` | `applied` (`start`) si el usuario es el asignado (o supervisor con `decisions.approve`) y no aprobó | — |
| | `reviewed` y sensible | `skipped` | `decision_approval_required` |
| | `approved`, usuario = quien aprobó | `skipped` | `decision_segregation_of_duties` (roles: `approver`) |
| | `approved`, usuario ≠ asignado y sin `decisions.approve` | `skipped` | `forbidden` (`required_ability = decisions.approve`) |
| | `suggested`, `assigned` | `skipped` | `transition_not_defined` (falta pasar por la revisión) |
| | `in_progress` | `unchanged` | `already_in_state` |
| `pedido_recibido` → `executed` | `in_progress` | `applied` (`execute`) con las mismas reglas de ejecución | — |
| | `suggested`, `assigned`, `reviewed`, `approved` | `skipped` | `transition_not_defined` (no se ejecuta lo que no se inició) |
| `descartado` → `rejected` | `suggested`, `assigned` | `applied` (`dismiss`; motivo = la nota o «Descartada desde la agenda comercial.») con `decisions.manage` | — |
| | `reviewed` | `applied` (`reject`, decisión formal) solo con `decisions.approve` y sin ser quien propuso ni quien debe ejecutar | si no: **403** `forbidden` / `decision_segregation_of_duties` y el seguimiento no se registra (arriba) |
| `pospuesto` | cualquiera | `not_applicable` | `agenda_state_does_not_change_workflow` |
| `pendiente` (reabrir) | cualquiera | `not_applicable` | `agenda_pending_never_moves_workflow_back` |
| cualquiera | cualquiera con control, versión desfasada | `skipped` | `control_group_not_actionable` / `decision_version_conflict` |

**El seguimiento del vendedor asignado nunca cuenta como aprobación**: el aprobador de una recomendación es solo quien hizo la transición `reviewed → approved` (`approverOf`); un evento `feedback` lleva el mismo `to_status = approved` mientras la recomendación está aprobada, pero no es una
aprobación. Verificado: `DecisionAgendaSyncTest` (todos sus métodos, incluido `test_dropping_an_opportunity_follows_the_roles_dismiss_before_review_and_formal_rejection_after`), `DecisionWorkflowApiTest::test_an_agenda_feedback_event_is_not_mistaken_for_the_approval`, `RadarFeedbackApiTest` (W5-A, actualizada), `RadarReviewFixesTest::test_postponing_beyond_the_expiry_of_the_opportunity_is_refused_without_changing_anything`.

## 7. Idempotencia de transiciones y concurrencia

- **Idempotencia:** `POST /decisions/{id}/transition` exige `idempotency_key`. La clave de evento es SHA-256(recomendación, clave del cliente), única por empresa. Repetir la **misma petición** responde lo mismo (`meta.idempotent_replay = true`, el mismo
  `event_id`) **sin otro evento ni otro efecto**, incluso si el estado ya avanzó. Pero la repetición **se autoriza antes de devolver nada**: quien no tiene hoy la habilidad con la que se hizo la transición recibe 403, no el resultado. Y debe ser la **misma** petición: cada transición guarda una huella
  (`payload.request` = SHA-256 de destino, usuario, motivo, asignado y `expected_version`); la misma clave con otro destino, otro cuerpo u otro usuario → 409 `idempotency_key_reused`. La misma clave sobre otra recomendación u otra empresa es otra operación. Verificado:
  `test_repeating_a_transition_with_the_same_key_has_no_new_effects`, `test_a_key_already_used_for_another_target_is_refused_and_keys_are_independent_per_recommendation`, `test_the_idempotency_key_is_required_and_validated`, `DecisionReviewFixesTest::test_a_replay_is_authorized_first_and_must_carry_the_same_body_and_user`.
- **Bloqueo optimista:** `version` = id del último evento de la recomendación **que cambia su estado o seguimiento**: todo cambio de estado, asignación o seguimiento inserta uno; los **comentarios del sistema** (`comment`: refrescos de linaje y resultado de la sincronización con la agenda) **no cuentan**, para que un refresco automático no invalide la acción de una persona. `expected_version` es obligatorio en toda transición humana (§ 6); si cambió desde que se leyó → 409
  `decision_version_conflict` con `context.current_version`, sin pisar nada ni dejar evento. Además la fila se bloquea (`SELECT … FOR UPDATE`) dentro de la transacción antes de decidir, así que dos decisiones simultáneas se serializan y la segunda ve el
  resultado de la primera. Verificado: `test_two_concurrent_reviews_do_not_overwrite_each_other_the_stale_one_is_refused_with_409`, `test_review_approve_and_reject_require_the_expected_version`, `DecisionReviewFixesTest::test_the_version_ignores_system_comments`,
  `test_every_transition_locks_the_row_inside_a_transaction_before_deciding`, `test_concurrent_assignments_are_serialized_by_the_row_lock_and_the_state_check`. **Limitación honesta:** la suite corre cada prueba dentro de una transacción de una sola
  conexión, por lo que la carrera real entre dos procesos no se ejecuta en paralelo; se prueba el efecto (versión desfasada rechazada, estado comparado bajo bloqueo) y el orden de las sentencias (bloqueo antes de escribir).

## 8. Expiración

`expires_at` se fija al proponer, al final del día (zona de la empresa): contacto = fin de su horizonte; reposición = período de revisión de la empresa; el resto, `expiry_days` configurable (30 / 30 / 14 / 14 / 14 días provisionales). `predictily:decisions-expire` (cada hora,
:25) pasa a `expired` lo que está en `suggested`, `assigned`, `reviewed` o `approved` y ya venció, con evento del sistema; **no vence** lo que ya tiene una acción en curso (`in_progress`) ni lo terminado. Es idempotente, por empresa y admite `--tenant` y
`--dry-run`. Las vencidas siguen existiendo y se miden (W5-C): se registran también las caducadas, no solo los éxitos. Verificado: `DecisionExpiryTest` (todos sus métodos) y `test_the_expiry_dates_follow_the_kind_and_the_company_settings`.

## 9. Experimento de contacto (control)

Fracción de control por empresa en `tenants.settings.decision.control_fraction`, **0 por defecto = sin experimento** (no se asigna ni se escribe nada). Con fracción > 0 (máximo 0,5), al generar un `contact` o `recovery` se asigna el **cliente** a
tratamiento o control en `experiment_assignments`: `u = SHA-256(semilla | clave del experimento | customer:<id>)` → [0, 1); control si `u < fracción`. La semilla (la de la empresa, o una derivada y estable si no se fijó), la fracción, `u`
y el algoritmo (`sha256-uniform-v1`) quedan en `policy`: cualquiera puede recalcular el grupo (`ExperimentAssigner::groupFor`). Un cliente cae siempre en el mismo grupo dentro de un experimento y una asignación ya registrada no cambia aunque la fracción
cambie (análisis por intención de tratamiento). **Las de control no se asignan a vendedores**: no se listan por defecto y el scope `DecisionRecommendation::actionable()` las excluye (es el que debe usar cualquier agenda). **El grupo de control no se delata por ningún endpoint**: para quien **no** tiene `decisions.approve`, una recomendación de control es un **404 idéntico al de un id inexistente** en el detalle **y en la transición** — la comprobación ocurre antes que cualquier otra (versión, destino, permisos), así que ni el estado, ni la versión, ni el mensaje distinguen una de otra —; quien sí puede aprobar recibe 409 `control_group_not_actionable` con el motivo (también sin versión o con una desfasada). El Radar tampoco la delata: sus listas, resumen, agenda y ficha excluyen a las de control, `experiment_group` solo se incluye con `decisions.approve` y `control_withheld` (cuántas se retienen) solo lo ve quien aprueba y solo en un resumen sin filtro de cliente o producto (con un filtro de entidad revelaría a qué cliente pertenece). 

**Qué se guarda y qué se muestra.** El evento inicial de la recomendación guarda siempre `payload.experiment = {experiment_key, group}` (la medición de outcomes lo necesita, y es lo que audita el experimento); `ExperimentRedaction` lo quita —junto con `experiment_group`, `unit_key` y `unit_type` en cualquier nivel— de **toda respuesta** a quien no tiene `decisions.approve` (`DecisionEventResource`, `OpportunityPresenter`). Verificado: `DecisionReviewFixesTest::test_a_control_unit_is_a_404_on_every_endpoint_for_non_approvers_and_leaks_nothing_about_its_status_or_version`, `test_the_proposal_event_stores_the_experiment_key_and_group` (lo guardado), `test_an_analyst_never_receives_the_experiment_key_group_or_unit_in_any_response_but_an_approver_does` (lo mostrado, por 8 endpoints de Decision y Radar) y `test_the_redaction_removes_the_experiment_keys_at_any_depth`.
Reposición, inventario y presupuesto no participan. Estratificación: DISEÑADA, no implementada. Verificado: `DecisionExperimentTest` (todos sus métodos) y
`ScoreFormulaTest::test_the_experiment_split_is_uniform_deterministic_and_recomputable`. El análisis (ITT, contaminación, sesgo de selección) es de W5-C.

## 10. API

Prefijo `/api/v1`, `auth:sanctum` + empresa activa (`X-Tenant-ID`). Una recomendación de otra empresa responde 404 en todas. Detalle de campos y ejemplos en `docs/openapi/v2.yaml` (regenerado en `predictily-api.yaml`).

| Método y ruta | Habilidad | Descripción |
|---|---|---|
| `GET /decisions` | `decisions.view` | Lista con filtros (`status`, `kind`, `priority`, `horizon_days`, `customer_id`, `product_id`, `branch_id`, `assigned_user_id`, `mine`, `requires_approval`, `attribution_label`, `as_of`, `expiring_before`, `include_control`), orden (`sort`) y paginado. Trae `version`, `requires_approval` y `allowed_transitions` por fila |
| `GET /decisions/summary` | `decisions.view` | Conteos por estado, tipo y etiqueta; pendientes de asignar y de aprobar; por vencer en 24 h. No suma importes (sumar tipos distintos contaría dos veces la misma venta) |
| `GET /decisions/{id}` | `decisions.view` | Contrato completo, línea de tiempo y transiciones disponibles con `blocked_by` |
| `POST /decisions/{id}/transition` | `decisions.manage` (+ la de la transición) | `{to_status, idempotency_key, expected_version, reason?, assignee_user_id?}` (`expected_version` obligatorio en toda transición humana) |
| `GET /decisions/settings` | `decisions.view` | Parámetros efectivos, por defecto y versión de la fórmula |
| `PUT /decisions/settings` | `settings.manage` | Actualiza las claves enviadas; con fracción de control > 0 registra una semilla (no se devuelve en claro). La auditoría (`decision.settings_updated`) guarda **valor anterior y nuevo** de cada clave que cambió; de la semilla solo que cambió o se generó, nunca su valor (`DecisionReviewFixesTest::test_the_settings_audit_records_old_and_new_values_and_never_the_seed`) |

Códigos: 403 `forbidden` (con `required_ability`) · 403 `decision_segregation_of_duties` · 404 (también una de control para quien no aprueba) · 409 `decision_transition_not_allowed` / `decision_version_conflict` / `decision_approval_required` / `control_group_not_actionable` /
`idempotency_key_reused` · 422 validación (incluida la falta de `expected_version`). Cada respuesta trae `attribution_label` y `attribution_note` («oportunidad potencial, atribución no demostrada»: el valor esperado **no** implica ventas incrementales causadas por la acción).

## 11. Comandos y programación

| Comando | Programación (America/Guayaquil) | Qué hace |
|---|---|---|
| `predictily:decisions-generate [--tenant=ID] [--dry-run]` | diario 05:30 (después de la predicción de comportamiento 04:20 y del radar 04:50) | Genera, por empresa activa, las recomendaciones propias del motor (cliente a nivel de cliente y Supply) desde lo persistido; idempotente; una empresa o una fuente con error no detiene a las demás |
| `predictily:decisions-expire [--tenant=ID] [--dry-run]` | cada hora, minuto 25 | Vence lo caducado sin acción en curso |

Ambos con `withoutOverlapping()->onOneServer()`. Verificado: `test_the_commands_are_scheduled_per_company_and_idempotent`, `test_the_command_expires_per_company_and_supports_dry_run_and_a_single_company`,
`test_generation_for_one_company_never_touches_another`, `test_dry_run_writes_nothing`.

## 12. Parámetros por empresa (`tenants.settings.decision`, todos provisionales)

Ningún valor es un umbral universal: son hipótesis de partida **«a aprobar en el piloto»** con datos reales de cada empresa (justificación en [`ECONOMIC_PRIORITIZATION.md`](ECONOMIC_PRIORITIZATION.md) § 6).

| Clave | Por defecto | Significado |
|---|---|---|
| `control_fraction` | 0 | Fracción de clientes en control (máx. 0,5); 0 = sin experimento |
| `experiment_key`, `experiment_seed` | `contact-default`, derivada | Identifican el experimento; la semilla se registra al activarlo |
| `horizons` | `[30]` | Horizontes del Customer Core para los que se generan contactos (no afecta a la recuperación ni al riesgo a nivel de cliente) |
| `max_supply_candidates` | 500 | Tope de candidatos por grupo de reposición, inventario-demanda y presupuesto (1–5000); entran los más urgentes |
| `contact_source` | `radar` | Quién produce los contactos cliente × producto: `radar` (por defecto) o `engine` (alternativa; ver § 3) |
| `contact_cost` | NULL | Costo de un contacto; sin él el score no resta nada y la incertidumbre lo declara |
| `contact_min_probability`, `max_contact_per_horizon` | 0,20; 100 | Solo para `contact_source = engine`; alineados con la política `radar-v1` |
| `expiry_days` | 30 / 30 / 14 / 14 / 14 | recuperación, riesgo, inventario, presupuesto, escenario (contacto = su horizonte; reposición = período de revisión) |
| `approval_required_kinds`, `approval_cost_threshold` | sensibles; NULL | Qué exige aprobación |
| `unreliable_cost_weight` | 0,2 | Peso de ranking cuando el costo no es confiable |
| `cost_reliability` | 90 días, 3 observaciones, 80 % de cobertura, CV ≤ 0,25 | Reglas del costo confiable |
| `inventory_max_age_days`, `excess_demand_ratio` | 7; 0,10 | Foto de inventario utilizable; «exceso sin demanda» |

## 13. Estado frente a los requisitos

| Requisito | Estado | Evidencia |
|---|---|---|
| V25-DEC-001/002 Servicio que combina Demand, Customer y Supply sin entrenar ni inventar números | IMPLEMENTADO / VERIFICADO | `DecisionGenerationTest` (todo sale de filas persistidas; las `recommendations` de etapa 1 quedan intactas) |
| V25-DEC-003 contacto · -004 recuperación · -005 riesgo de cliente | IMPLEMENTADO / VERIFICADO | `test_customer_predictions_become_contacts_and_inactive_buyers_become_recovery`, `test_customer_risk_covers_at_risk_or_declining_buyers_but_never_insufficient_history_or_generic_buyers` |
| V25-DEC-006 reposición · -007 inventario-demanda · -008 presupuesto | IMPLEMENTADO / VERIFICADO | `test_replenishment_comes_from_the_existing_recommendations_without_recalculating_anything`, `test_the_supply_plan_built_on_the_same_forecast_sets_quantity_date_supplier_and_over_budget_constraints`, `test_a_shortage_against_customer_demand_is_a_mismatch_valued_at_the_missing_units`, `test_overstock_without_customer_demand_is_a_mismatch_without_an_invented_value`, `test_unmet_demand_because_of_the_budget_becomes_a_budget_constraint_recommendation` |
| V25-DEC-009 escenarios | PARCIAL: `kind = scenario` se acepta por `propose()`; no hay generador de escenarios (DISEÑADO) | `test_a_proposal_gets_every_field_of_the_section_11_contract` |
| V25-DEC-010..019 campos del contrato de §11 | IMPLEMENTADO / VERIFICADO | `test_a_proposal_gets_every_field_of_the_section_11_contract`, `test_the_detail_exposes_the_complete_contract_without_pii_and_the_summary_counts`, `test_the_stored_explanation_is_enough_to_verify_the_score` |
| V25-DEC-014/020/021 valor esperado = E(nº pedidos) × contribución por pedido; no implica ventas causadas | IMPLEMENTADO / VERIFICADO | `test_contribution_is_derived_from_a_reliable_cost_and_follows_orders_times_per_order_contribution`, `attribution_note` en toda respuesta |
| V25-DEC-015/022/023 incremental solo con evidencia; etiqueta «atribución no demostrada» | IMPLEMENTADO / VERIFICADO (la etiqueta y el campo NULL); el **uplift** medido es de W5-C | `test_a_contribution_supplied_by_the_proposer_is_ignored`, `ScoreFormulaTest` |
| V25-DEC-016/035 score con versión de fórmula; mismo input → mismo resultado | IMPLEMENTADO / VERIFICADO | `test_the_score_formula_version_is_the_sha256_of_its_parameters_and_changes_with_them`, `test_the_default_formula_version_is_pinned_so_a_change_of_parameters_is_always_deliberate`, `test_the_same_inputs_produce_the_same_recommendations_in_two_companies` |
| V25-DEC-019 inputs persistidos sin PII | IMPLEMENTADO / VERIFICADO | factor `value_calculation` y fuentes en `explanation`; el cliente se identifica por id y código externo, sin nombre |
| Seguimiento de la agenda → workflow (V25-AGD-006/007) | IMPLEMENTADO / VERIFICADO (§ 6.1) | `DecisionAgendaSyncTest` |
| V25-DEC-024/025 workflow y roles | IMPLEMENTADO / VERIFICADO con los roles actuales (owner/admin/analyst/viewer); **gerente/vendedor: W6** | `DecisionWorkflowApiTest` |
| V25-DEC-026 expiración | IMPLEMENTADO / VERIFICADO | `DecisionExpiryTest` |
| V25-DEC-027 revisiones concurrentes | IMPLEMENTADO / VERIFICADO (ver la limitación honesta de § 7) | `test_two_concurrent_reviews_do_not_overwrite_each_other_the_stale_one_is_refused_with_409` |
| V25-DEC-028 idempotencia de transiciones | IMPLEMENTADO / VERIFICADO | `test_repeating_a_transition_with_the_same_key_has_no_new_effects` |
| V25-DEC-029 aprobación para acciones sensibles | IMPLEMENTADO / VERIFICADO para compra/reposición, presupuesto y lo configurado; «cambio de precio» y «mensaje al cliente» **NO APLICAN** hoy (Predictily no los ejecuta) | § 6 |
| V25-DEC-030 trazabilidad | IMPLEMENTADO / VERIFICADO | `decision_events` + `audit_logs` |
| V25-DEC-031 reversión o compensación | DISEÑADO / NO IMPLEMENTADO: hoy no hay reversión de un estado ya alcanzado (el pasado no se edita); la compensación sería una recomendación nueva ([`CONTROLLED_ACTIONS.md`](CONTROLLED_ACTIONS.md)) | — |
| V25-DEC-032 segregación de funciones | IMPLEMENTADO / VERIFICADO | § 6 |
| V25-DEC-033 no escribir en el ERP | IMPLEMENTADO / VERIFICADO | `test_generating_and_walking_the_workflow_never_sends_messages_calls_the_erp_or_queues_work` |
| V25-DEC-034 controles de escrituras futuras | DISEÑADO / NO IMPLEMENTADO | [`CONTROLLED_ACTIONS.md`](CONTROLLED_ACTIONS.md) |
| V25-DEC-036 Decision Center (UI) | NO es de este paquete (W5-E); la API está lista | — |
| V25-DEC-038 E2E con dos empresas | PARCIAL: aislamiento verificado por API y servicios; el E2E de extremo a extremo con rollback de modelo es de la integración | `test_a_recommendation_of_another_company_is_invisible_and_untouchable`, `test_assignments_are_isolated_between_companies` |
| V25-DEC-039 reglas del tenant que cambian el resultado | PARCIAL: contacto (mínimo, tope, costo, horizontes), peso del costo no confiable, confiabilidad del costo, vigencias, aprobación, exceso. **Prioridades por producto y lista de aprobadores: DISEÑADO** | `DecisionSettingsApiTest::test_settings_change_what_the_engine_does_and_do_not_leak_between_companies` |
| Revisión conjunta W5-A/W5-B: control que no se delata, trabajo en curso que no se pisa, versión obligatoria, repetición autorizada, rerank acotado, demanda sin «cero» inventado, índices | IMPLEMENTADO / VERIFICADO (§ 5, 6, 7, 9, 15) | `DecisionReviewFixesTest`, `DecisionReviewBoundsTest`, `RadarReviewFixesTest` |
| Pruebas con datos reales del piloto (precisión del ranking, utilidad económica) | BLOQUEADO: requiere un piloto con datos reales | — |

## 14. Limitaciones y supuestos conocidos

- Las unidades en riesgo de la reposición usan la **media** de la demanda persistida (no su varianza): es un punto, no un intervalo (la probabilidad de quiebre sí se muestra).
- El valor de recuperación y de riesgo es un **importe observado** (caída de gasto), no una contribución: el margen por cliente no se estima.
- El exceso de inventario no tiene valor económico (requiere una tasa de mantener inventario por empresa: DISEÑADO); su score queda vacío y su prioridad es la más baja (5).
- Un costo unitario sin respaldo suficiente no se usa nunca (ver reglas): la contribución queda NULL aunque exista `products.current_cost` (es el valor de hoy, fuga al pasado).
- El tope por grupo (`max_contact_per_horizon`, `max_supply_candidates`) y el orden previo al score usan la ordenación por importe esperado, quiebre probable o faltante; el score final decide el orden y la prioridad dentro del tope.
- El reordenamiento de prioridades (`DecisionEngine::rerank`) toma **solo** lo accionable de su grupo (`suggested`, sin seguimiento en curso, no de control), bajo bloqueo de fila, en **una** sentencia SQL, y **no toca** `updated_at` ni genera eventos: la prioridad es una función del orden del score, no una acción de nadie. Lo que ya se trabaja o se cerró conserva su prioridad. Desempate por `idempotency_key` en orden binario (`COLLATE "C"`, estable entre servidores). El grupo es (tipo, entidad, corte) y, para contacto, recuperación y riesgo, también el horizonte. Verificado: `DecisionReviewFixesTest::test_reordering_priorities_never_touches_in_flight_control_or_closed_rows`, `DecisionProducersAndPriorityTest`.
- Un alcance con trabajo en curso no recibe sucesora (§ 5): si el equipo deja una oportunidad «contactada» sin cerrarla, la corrida siguiente solo anota el refresco hasta que se cierre o venza; es deliberado (no perder el seguimiento), pero obliga a cerrar lo que se trabajó.

## 15. Índices y plan de consultas (EXPLAIN)

El contrato original (`2026_03_13_000100`) trae solo (empresa, estado, prioridad), (empresa, cliente) y la clave de idempotencia. Las consultas reales del motor y del Radar necesitaban más; la migración **aditiva y reversible** `2026_03_13_000200_add_decision_recommendation_query_indexes` crea:

| Índice | Columnas | Consulta que sirve |
|---|---|---|
| `decision_recommendations_scope_idx` | (tenant, kind, customer, product, horizon) | alcance de una oportunidad: la que está en curso, el reemplazo de las no trabajadas y la defensa «una accionable por alcance» del Radar |
| `decision_recommendations_group_idx` | (tenant, kind, as_of, horizon) | grupo de prioridad: el reordenamiento al proponer |
| `decision_recommendations_work_idx` | (tenant, status, kind, score DESC NULLS LAST) | lista de trabajo por defecto (orden por score) |
| `decision_recommendations_expiry_idx` | (tenant, expires_at) **parcial** `status IN (suggested, assigned, reviewed, approved)` | barrido horario de vencimientos |

**Medición** (PostgreSQL 15, una empresa con **300 000** recomendaciones sintéticas = 600 clientes × 500 productos, 3 tipos × 3 horizontes × 5 cortes, `EXPLAIN (ANALYZE)` tras `ANALYZE`; sonda temporal, no queda en la suite; tiempos de ejecución en un equipo de desarrollo, orden de magnitud, no una garantía):

| Consulta | Sin los índices nuevos | Con ellos |
|---|---|---|
| Q1 alcance en curso (cliente, producto, horizonte) | 0,41 ms (índice (tenant, cliente)) | 0,04 ms (`scope_idx`) |
| Q2 reemplazo de las `suggested` del alcance | 0,15 ms | 0,01 ms (`scope_idx`) |
| Q3 pool del grupo de prioridad (tipo, entidad, corte, horizonte, `suggested`) | 26,8 ms (bitmap sobre (tenant, estado, prioridad) + `Gather Merge`) | 3,2 ms (`group_idx`) |
| Q4 lista de trabajo (`suggested`, tipo, `ORDER BY score DESC LIMIT 50`) | 35,5 ms (`Seq Scan` + orden) | 0,10 ms (`work_idx`, sin orden) |
| Q5 barrido de vencimientos | 40,4 ms (`Seq Scan` paralelo + `Gather Merge`) | 2,1 ms (`expiry_idx`, parcial) |

Costo: tamaño de los índices nuevos en esa tabla (scope 19 MB, work 17 MB, group 2,1 MB, expiry 1,5 MB frente a 39 MB de la clave de idempotencia): cada escritura de una recomendación mantiene cuatro índices más; es aceptable porque la escritura es de lotes diarios, no de transacciones en línea. La prueba `DecisionReviewBoundsTest::test_the_real_queries_use_the_new_indexes` siembra 10 000 filas, ejecuta `ANALYZE` y exige que el planificador elija cada índice para su consulta (con `enable_seqscan = off`); `test_the_query_indexes_exist` comprueba que existen y que el de vencimientos es parcial.

