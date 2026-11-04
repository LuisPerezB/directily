# Compatibilidad con la API V2.2 (Agenda Comercial y pronósticos)

Fecha: 1-nov-2026 · Rama `v25/w1-cierre-y-w2` · V2.5 · W5-A · Fuentes: §25.3 de la especificación V2.5, ADR-V25-12 y los requisitos `V25-AGD-001..021` de `docs/roadmap/audit/2026-10-31/cliente.json`.

**Regla de la especificación (§25.3):** si ya existe un endpoint equivalente **no se crea un duplicado**: se versiona y se mantienen adaptadores compatibles y OpenAPI. Las rutas V2.2 son fachadas finas sobre los servicios vigentes; las que tienen equivalente interno delegan en su controlador (misma validación, mismo cuerpo, cabecera `X-Equivalent-Of`).

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + la prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** · **NO APLICA**.

## 1. Ruta V2.2 → ruta vigente

| Ruta V2.2 | Ruta vigente / implementación | Estado | Prueba |
|---|---|---|---|
| `GET /api/v1/radar/summary` | `RadarSummaryController` → `PredictiveSalesAgenda::summary` sobre `decision_recommendations` (kind contact, recovery, customer_risk) | IMPLEMENTADO / VERIFICADO | `RadarAgendaAndSummaryApiTest` |
| `GET /api/v1/radar/opportunities` | `RadarOpportunityController::index` → `PredictiveSalesAgenda::opportunities` (filtros cliente, SKU, horizonte, vendedor, segmento, zona, categoría, estado; paginado; orden determinista) | IMPLEMENTADO / VERIFICADO | `RadarOpportunitiesApiTest` |
| `GET /api/v1/radar/agenda` | `RadarAgendaController` → `PredictiveSalesAgenda::agenda` (por cliente, por fecha, pospuestas) | IMPLEMENTADO / VERIFICADO | `RadarAgendaAndSummaryApiTest` |
| `POST /api/v1/radar/opportunities/{id}/feedback` | `AgendaFeedbackService` (auditable en `decision_events`, idempotente) | IMPLEMENTADO / VERIFICADO | `RadarFeedbackApiTest` |
| `GET /api/v1/customers/{id}/predictions` | **alias** de `GET /customer/predictions?customer_id={id}` (delega en `CustomerPredictionController`) | IMPLEMENTADO / VERIFICADO (mismo cuerpo salvo URL de paginación) | `CustomerFichaApiTest::test_predictions_answer_exactly_like_customer_predictions_filtered_by_the_customer` |
| `GET /api/v1/customers/{id}/demand` | `CustomerCommercialProfile::demand`: Σ de `total_view_quantity` de `/customer/predictions` con el desglose esperado / comprometido / incremental | IMPLEMENTADO / VERIFICADO | `CustomerFichaApiTest::test_demand_is_the_sum_over_the_customer_pairs_of_the_total_view_with_its_breakdown` |
| `GET /api/v1/customers/{id}/products` | `CustomerCommercialProfile::products` (habituales por compras históricas; probables por el ranking del Customer Core) | IMPLEMENTADO / VERIFICADO | `CustomerFichaApiTest` |
| `GET /api/v1/demand/forecasts` | **alias** de `GET /forecasts/products` (por defecto), `/forecasts/categories` o `/forecasts/series` según `level` | IMPLEMENTADO / VERIFICADO (mismo cuerpo) | `DemandForecastAliasTest` |
| `GET /api/v1/supply/readiness` | `SupplyReadiness` sobre `inventory_snapshots`, `customer_predictions` y `forecast_values` (disponibilidad con la definición del Decision Engine; la necesidad se compara con la **mayor** entre la demanda de los compradores identificados y la agregada pronosticada, y `required_basis` lo dice) | IMPLEMENTADO / VERIFICADO | `SupplyReadinessApiTest` |
| `GET /api/v1/models/evaluations` | ya existe (W3-D, `ModelQualityController::evaluations`): se reutiliza tal cual, sin copia | IMPLEMENTADO / VERIFICADO (W3-D) | `ModelQualityApiTest` |
| *(nueva, soporte)* `GET /api/v1/radar/opportunities/{id}` | detalle con explicación, supuestos, restricciones e historial inmutable | IMPLEMENTADO / VERIFICADO | `RadarOpportunitiesApiTest::test_the_detail_includes_the_explanation_assumptions_and_full_history` |
| *(nueva, soporte)* `POST /api/v1/radar/opportunities/materialize` y `predictily:radar-materialize` | propone al Decision Engine las oportunidades de la predicción vigente (`RecommendationSink`) | IMPLEMENTADO / VERIFICADO | `RadarMaterializeTest`, `RadarDecisionEngineIntegrationTest` |
| *(nueva, soporte)* `GET /api/v1/customers/{id}/overview` | ficha comercial del comprador (compras, frecuencia, SKU habituales, evolución, ventana, seguimiento) | IMPLEMENTADO / VERIFICADO | `CustomerFichaApiTest` |

OpenAPI: todas están en `docs/openapi/v2.yaml` (etiqueta «Revenue Radar»); los alias declaran `x-equivalent-of` y devuelven la cabecera `X-Equivalent-Of`. `OpenApiRoutesTest` exige que no falte ni sobre ninguna operación.

Verificación (ejemplo con la base de pruebas propia): `cd backend && DB_DATABASE=<bd_pruebas> php artisan test --filter='Radar|CustomerFichaApiTest|DemandForecastAliasTest|SupplyReadinessApiTest|AgendaStatusMapTest|RadarPolicyTest'`.

## 2. Estados heredados de la agenda → workflow Enterprise

Tabla `radar-agenda-map-v1` (`App\Services\Radar\AgendaStatusMap`; la publica `GET /radar/opportunities/{id}` en `status_map`). El estado heredado vive en `decision_recommendations.agenda_status` (sin gestión = `pendiente`) y **cada cambio es un evento `feedback` inmutable** en `decision_events`: no se pierde ningún estado previo.

| Estado heredado (V2.2) | Estado de workflow que implica | Regla |
|---|---|---|
| Pendiente | `suggested` (`assigned` si ya tiene vendedor asignado) | Sin gestión |
| Contactado | `in_progress` | Se contactó al cliente |
| Interesado | `in_progress` | Contactado con interés; `payload.interest = true` en el evento |
| Pedido recibido | `executed` | Se recibió el pedido; `measured` lo pone el servicio de outcomes, no la agenda. **Estado final** |
| Pospuesto | *(sin cambio)* | `payload.snoozed_until` en el evento; sale de la agenda hasta esa fecha |
| Descartado | `rejected` | Equivale a rechazada. **Estado final** |

Transiciones permitidas: pendiente → contactado, pedido recibido, pospuesto, descartado · contactado → interesado, pedido recibido, pospuesto, descartado · interesado → contactado, pedido recibido, pospuesto, descartado · pospuesto → pendiente, contactado, pedido recibido, descartado · pedido recibido y descartado: ninguna. Verificado: `AgendaStatusMapTest` (matriz completa 6 × 6), `RadarFeedbackApiTest::test_a_legacy_history_is_kept_complete_and_maps_to_the_workflow`.

**No existe historial V2.2 previo que migrar** en este repositorio (la agenda V2.2 nunca se implementó): la ausencia de pérdida se prueba sobre historiales sembrados por la propia API (cada evento enlaza con el anterior; el último estado es el vigente). **Seguimiento → workflow (W5-B, IMPLEMENTADO / VERIFICADO):** el seguimiento registra `agenda_status` y el evento con `implied_workflow_status`, y `AgendaFeedbackService::syncWorkflow()` aplica la transición implicada con `Workflow::applyImplied()` (mismas habilidades, segregación, aprobación y bloqueo optimista que cualquier transición); si el workflow no la admite desde su estado actual, no se mueve y el motivo queda en un segundo evento `comment` (`payload.kind = workflow_sync`; el evento de seguimiento no se modifica) y se devuelve en `data.event.workflow_sync*`; si el estado cierra la oportunidad (`pedido_recibido`, `descartado`) y el rol no podría hacer esa transición, el seguimiento se rechaza con 403 (ver `docs/decision/DECISION_ENGINE.md` § 6.1).

## 3. Pendiente de otros paquetes o fases

| Elemento | Estado |
|---|---|
| Rol vendedor con alcance por cartera (`customer_assignments`) y rol gerente (V25-AGD-009, -021) | **DISEÑADO / NO IMPLEMENTADO** — llega en W6. Hoy `seller_id` filtra por el usuario asignado a la recomendación y los roles owner/admin/analyst/viewer ven toda la empresa (`TODO(W6)` en `PredictiveSalesAgenda` y `routes/api/radar.php`) |
| Habilidades `radar.view`, `radar.feedback`, `radar.assign` (V25-AGD-021) | **NO APLICA por decisión del arquitecto:** se usan las existentes (`forecast.view`, `decisions.manage`, `forecast.run`) |
| Interfaz `MessageDraft` con confirmación humana (V25-AGD-019) | **DISEÑADO / NO IMPLEMENTADO.** Lo implementado y probado es que **ningún camino del Radar envía mensajes** (`RadarFeedbackApiTest::test_no_message_is_ever_sent_by_the_radar`) |
| Inmutabilidad del historial a nivel de base de datos (V25-AGD-020) | **DISEÑADO.** La API solo inserta eventos y no expone ninguna ruta que edite o borre (`test_the_api_exposes_no_way_to_edit_or_delete_history_events`); un trigger que lo imponga en PostgreSQL requeriría una migración (fuera del alcance de W5-A) |
