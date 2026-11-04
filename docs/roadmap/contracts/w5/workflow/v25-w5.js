export const meta = {
  name: 'v25-w5-enterprise-flow',
  description: 'V2.5 W5: flujo empresarial P1 (S07 Revenue Radar y Agenda sobre Customer Core con contratos V2.2, S08 Decision Engine y workflow con segregación e idempotencia, S09 Outcomes y ROI honestos con grupo de control, S10 Supply con perecibilidad/FEFO y simulación as-of, S14 vistas Enterprise)',
  phases: [
    { title: 'Implementación', detail: 'A Radar/Agenda, B Decision Engine, D Supply en paralelo; C Outcomes tras B; E UI al final' },
    { title: 'Revisión', detail: 'revisión adversarial por paquete' },
    { title: 'Corrección', detail: 'corregir hallazgos y re-ejecutar pruebas' },
  ],
}

const BASE = (args && args.base) || 'HEAD'

// Contexto común: el de W2 (constante COMMON de docs/roadmap/contracts/w2/workflow/v25-w2.js), más lo siguiente.
const COMMON_W5 = `
Contrato de W5 fijado por el arquitecto (léelo completo; NO lo modifiques): backend/database/migrations/2026_03_13_000100_create_decision_radar_outcome_and_supply_contracts.php (decision_recommendations con el contrato de §11 y clave de idempotencia; decision_events; experiment_assignments; decision_outcomes; products.is_perishable/shelf_life_days; inventory_lots). ADR: V25-12 (Radar y Agenda como fachadas sobre Customer Core), V25-13 (Decision Engine y workflow con segregación), V25-14 (outcomes y ROI honestos), V25-22 (UI Enterprise). Especificación: §10, §11, §12, §16 y §25.
Reglas adicionales: el Decision Engine NO entrena modelos ni genera números sin fuente: combina salidas persistidas de Demand, Customer y Supply y reglas del tenant; todo valor económico usa contribución cuando el costo es confiable y lo marca si no; sin evidencia de uplift la etiqueta es potential_unproven («oportunidad potencial, atribución no demostrada»); Predictily NO escribe en el ERP ni envía mensajes automáticos (WhatsApp/correo solo como contrato con confirmación humana); ninguna cifra económica ficticia se presenta como real.
`

const PACKAGES = [
  {
    key: 'W5-A-radar-agenda',
    dbs: 'BD backend: predictily_test_w5a',
    owns: 'backend/app/Services/Radar/** (nuevo), controladores y requests nuevos de Radar y de la ficha de cliente, backend/routes/api/radar.php (nuevo; una línea en backend/routes/api.php), pruebas nuevas, docs/openapi/*.yaml (rutas nuevas), docs/decision/ECONOMIC_PRIORITIZATION.md (sección de Radar)',
    prompt: `Paquete W5-A · Revenue Radar y Agenda Comercial (ADR-V25-12; requisitos V25-AGD-001..021).
1. Servicio PredictiveSalesAgenda / CommercialOpportunityService reutilizable sobre customer_predictions vigentes (W3: probabilidad, nº de pedidos, cantidad, ventana), disponibilidad de inventario al corte y margen conocido; las oportunidades se materializan como decision_recommendations kind contact/recovery/customer_risk vía el Decision Engine de W5-B (coordina por la interfaz: si W5-B aún no existe, define la interfaz y un adaptador mínimo que W5-B reemplaza; no dupliques lógica).
2. Contratos V2.2 como fachadas: GET /api/v1/radar/summary, /radar/opportunities (filtros cliente, SKU, horizonte, vendedor, segmento, zona, categoría, estado; paginado), /radar/agenda, POST /radar/opportunities/{id}/feedback (auditable en decision_events, idempotente), GET /customers/{id}/predictions|demand|products, /demand/forecasts, /supply/readiness; /models/evaluations ya existe (W3-D): reutilízalo. Estados heredados pendiente/contactado/interesado/pedido_recibido/pospuesto/descartado en agenda_status mapeados al workflow sin perder historial (tabla del mapeo documentada y probada).
3. Ficha del cliente: compras, frecuencia, SKU habituales, evolución, ventana futura y seguimiento, sin PII innecesaria (nombres cifrados como hoy).
4. Autorizaciones y aislamiento (pruebas negativas entre empresas y por rol vendedor/gerente con las habilidades existentes); sin envío automático de mensajes.
5. Suite completa del backend en verde; OpenAPI; documentación con estados honestos.`,
    review: 'Checklist: fachadas sin duplicar lógica; filtros y paginado; feedback auditado e idempotente; mapeo de estados heredados probado y sin pérdida de historial; ficha sin PII innecesaria; aislamiento y roles; ningún envío automático; suite completa en verde.',
  },
  {
    key: 'W5-B-decision-engine-workflow',
    dbs: 'BD backend: predictily_test_w5b',
    owns: 'backend/app/Services/Decision/** (nuevo: DecisionEngine, Workflow, ScoreFormula, ExperimentAssigner), modelos DecisionRecommendation, DecisionEvent, ExperimentAssignment, controladores y requests nuevos de decisiones, backend/routes/api/decision.php (nuevo; una línea en backend/routes/api.php), un comando programado predictily:decisions-generate y otro predictily:decisions-expire, pruebas nuevas, docs/decision/DECISION_ENGINE.md, docs/decision/ECONOMIC_PRIORITIZATION.md (fórmula), docs/decision/CONTROLLED_ACTIONS.md (nuevos)',
    prompt: `Paquete W5-B · Decision Engine y workflow (ADR-V25-13; §11).
1. DecisionEngine: genera decision_recommendations de contacto, recuperación comercial, riesgo de cliente, reposición (a partir de recommendations/supply_plans existentes, sin recalcular), incompatibilidad inventario-demanda y restricción presupuestaria, con TODOS los campos del contrato (fuente y huella, versiones de modelos, probabilidad e intervalo, supuestos, restricciones, disponibilidad, costo de acción, valor bruto esperado, contribución, incremental solo con evidencia, etiqueta de atribución, score con versión de fórmula, incertidumbre, explicación verificable sin PII, prioridad, dueño, expiración, siguiente paso). Fórmula versionada (score_formula_version = SHA-256 de sus parámetros): valor esperado = E(nº pedidos) × contribución condicionada por pedido cuando haya costo confiable (si no, importe con la marca cost_unreliable), menos costo de acción; documentada con sus supuestos.
2. Idempotencia: idempotency_key = SHA-256(kind, as_of, horizonte, entidad, fuente); regenerar con los mismos insumos no duplica; con insumos nuevos crea una recomendación nueva y la anterior expira.
3. Experimentos: al generar, asignación reproducible tratamiento/control por cliente (fracción de control configurable por empresa, por defecto 0 = sin experimento; semilla registrada) en experiment_assignments; las de control no se asignan a vendedores (quedan para medición).
4. Workflow suggested → assigned → reviewed → approved | rejected → in_progress → executed → measured → closed, + expired; transiciones explícitas con habilidades por rol; quien propone o ejecuta no aprueba su propia acción (segregación probada); acciones sensibles (p. ej. reposición sobre presupuesto) exigen aprobación; revisiones concurrentes con bloqueo optimista; toda transición en decision_events con clave de idempotencia; expiración programada.
5. API: listado/detalle con filtros, transiciones (POST /api/v1/decisions/{id}/transition con idempotency_key), sin escribir en el ERP; aislamiento entre empresas.
6. Pruebas de cada regla (idempotencia, segregación, concurrencia, expiración, control sin asignación, campos completos, etiqueta sin evidencia); suite completa del backend en verde; documentación con estados honestos (DECISION_ENGINE.md, ECONOMIC_PRIORITIZATION.md, CONTROLLED_ACTIONS.md: allowlist, dry-run, confirmación y rollback como DISEÑADO para fases futuras).`,
    review: 'Checklist: ningún número sin fuente; contrato completo; fórmula versionada y documentada; idempotencia real; asignación de control reproducible y sin contacto; segregación de funciones probada; concurrencia; expiración; transiciones auditadas; sin escritura en ERP; aislamiento; suite completa en verde.',
  },
  {
    key: 'W5-C-outcomes-roi',
    dbs: 'BD backend: predictily_test_w5c',
    after: ['W5-B-decision-engine-workflow'],
    owns: 'backend/app/Services/Outcomes/** (nuevo), modelo DecisionOutcome, controladores y rutas nuevas de outcomes y ROI (backend/routes/api/decision.php: solo rutas de outcomes), un comando predictily:outcomes-measure, pruebas nuevas, docs/decision/OUTCOME_MEASUREMENT.md (nuevo)',
    prompt: `Paquete W5-C · Outcomes y ROI honestos (ADR-V25-14; §12).
1. OutcomeMeasurementService: para cada recomendación (incluidas rechazadas, no contactadas, de control y caducadas) mide en su ventana el evento observable posterior desde las versiones vigentes de ventas (sales_line_versions; sin PII en observed_ref) y escribe decision_outcomes con clasificación forecast / observed / temporally_associated / incremental_estimated / causally_attributable. Solo es causally_attributable con grupo de control registrado al generar (análisis por intención de tratamiento: diferencia de tasas y de contribución entre tratamiento y control con intervalo) o un cuasiexperimento documentado; si no, temporally_associated.
2. ROI con contribución (no ingresos brutos) y costos de contacto/uso (tenant_usage_records de W4 si existen); intervalos y limitaciones; métricas: oportunidades identificadas/atendidas, tasa de acción, tasa de respuesta, ventas y contribución posteriores, stockouts/exceso cuando aplique, por segmento, horizonte y responsable.
3. API: GET /api/v1/outcomes (filtros), GET /api/v1/outcomes/summary (ROI honesto con su método); aislamiento.
4. Pruebas: cada clasificación; ITT con datos sintéticos de tratamiento/control (efecto conocido recuperado con su intervalo); sin control ⇒ nunca causal; rechazadas y caducadas medidas; contribución NULL si el costo no es confiable; suite completa en verde.
5. OUTCOME_MEASUREMENT.md: método, clasificaciones, ITT, contaminación entre vendedores, sesgo de selección y lo BLOQUEADO (resultados reales: requiere piloto).`,
    review: 'Checklist: se miden también rechazadas/no contactadas/control/caducadas; causal solo con control registrado o cuasiexperimento documentado; ITT correcto con intervalo; ROI con contribución y costos; ninguna cifra económica ficticia presentada como real; aislamiento; suite completa en verde.',
  },
  {
    key: 'W5-D-supply-s10',
    dbs: 'BD del motor: predictily_engine_test_w5d; BD backend: predictily_test_w5d',
    owns: 'engine/predictily_engine/supply/**, engine/predictily_engine/db/supply_repository.py, engine/predictily_engine/inventory/**, el importador/validación de lotes de inventario e is_perishable/shelf_life_days en el backend (servicios de importación de inventario y productos: solo lo nuevo), pruebas nuevas, docs/supply/SUPPLY_CORE.md (nuevo)',
    prompt: `Paquete W5-D · Supply Core (§10; requisitos de supply_decision.json).
1. Disponibilidad = stock físico válido − reservado, separada de pedidos confirmados (compromisos), compras en tránsito y ajustes; compromisos comerciales en el balance SIN doble conteo con la demanda pronosticada (documenta y prueba la reconciliación).
2. Perecibilidad: productos perecibles con vida útil e inventory_lots con vencimiento; asignación FEFO y riesgo de vencimiento en el plan; durables sin cambios.
3. Stock de seguridad y punto de reorden explícitos (fórmula, nivel de servicio, lead time y su variabilidad), MOQ y múltiplos, presupuesto y capacidad como restricciones duras sobre un pronóstico optimista; escenario «sin compra»; nunca inventarios negativos silenciosos; ninguna compra automática.
4. Simulación cronológica as-of: supply/simulation_runner.py, db/supply_repository.py (recent_net_prices, sales_cost_history), scenarios y costs leen con la lectura as-of de W1 (data/asof.py: knowledge = día de cada paso o hoy en vigente), con métricas de servicio (fill rate, días de quiebre, exceso, capital inmovilizado) sobre lo ocurrido después de cada corte.
5. Pruebas (incluida una fuga: un documento tardío no altera una simulación pasada); suites completas del motor y del backend en verde; SUPPLY_CORE.md con estados honestos.`,
    review: 'Checklist: disponibilidad separada de comprometido/tránsito; sin doble conteo de compromisos; FEFO y vencimiento; fórmulas explícitas; restricciones duras; sin negativos silenciosos ni compras automáticas; simulación as-of sin fuga (prueba); suites completas en verde.',
  },
  {
    key: 'W5-E-enterprise-ui',
    dbs: 'BD backend: predictily_test_w5e',
    after: ['W5-A-radar-agenda', 'W5-B-decision-engine-workflow', 'W5-C-outcomes-roi', 'W5-D-supply-s10'],
    owns: 'frontend/src/views/** y frontend/src/components/** nuevos de Executive Overview, Revenue Radar, Decision Center, ROI & Outcomes y Administración; router, menú e i18n (ediciones mínimas); pruebas vitest nuevas; docs/product/ENTERPRISE_MVP_ACCEPTANCE.md (nuevo)',
    prompt: `Paquete W5-E · Vistas Enterprise (ADR-V25-22; §16). Se ejecuta DESPUÉS de W5-A..D: usa solo sus endpoints.
1. Executive Overview (pronóstico, oportunidades, decisiones pendientes, outcomes medidos con su clasificación), Revenue Radar (oportunidades con filtros y agenda con estados heredados), Decision Center (workflow con transiciones permitidas por rol y confirmación humana), ROI & Outcomes (contribución, método, «atribución no demostrada» visible cuando corresponde) y Administración (modelos y aprobaciones de W4, calidad de datos de W2, uso por empresa).
2. Nada de cifras cuando el backend devuelve insufficient_data o atribución no demostrada; textos en español; accesible (roles ARIA, foco, contraste).
3. vitest de cada vista nueva; type-check, lint, vitest y build en verde; E2E sin editar.
4. ENTERPRISE_MVP_ACCEPTANCE.md: criterios de aceptación por vista con su prueba y estado.`,
    review: 'Checklist: solo endpoints existentes; transiciones según rol y con confirmación; sin cifras sin datos; atribución honesta visible; accesibilidad básica; pruebas de cada vista; build en verde.',
  },
]

// Orquestación: implementación → revisión adversarial → corrección; A, B y D en paralelo; C tras B; E al final.
