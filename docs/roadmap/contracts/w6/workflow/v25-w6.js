export const meta = {
  name: 'v25-w6-platform-closure',
  description: 'V2.5 W6: plataforma y cierre (S11 seguridad Enterprise, S12 Bridge y Auto Mapper, S15 factores y taxonomía ampliados, S18 Intelligence Gateway, S16 contratos sectoriales, S17 documentación completa, E2E V2.5 y evaluación final de gates)',
  phases: [
    { title: 'Implementación', detail: 'A seguridad, B Bridge, C Auto Mapper, D gateway, E factores/sectorial en paralelo; F cierre al final' },
    { title: 'Revisión', detail: 'revisión adversarial por paquete' },
    { title: 'Corrección', detail: 'corregir hallazgos y re-ejecutar pruebas' },
  ],
}

const BASE = (args && args.base) || 'HEAD'

// Contexto común: el de W2 (constante COMMON de docs/roadmap/contracts/w2/workflow/v25-w2.js), más lo siguiente.
const COMMON_W6 = `
Contrato de W6 fijado por el arquitecto (léelo completo; NO lo modifiques): backend/database/migrations/2026_03_14_000100_create_enterprise_security_bridge_and_gateway_contracts.php (MFA en users, rol comercial en tenant_users, bridge_devices, bridge_checkpoints, mapping_templates, gateway_tool_calls). ADR: V25-10 (Bridge Python de solo lectura), V25-15 (seguridad como bloqueo de release), V25-16 (taxonomía y factores), V25-17 (gateway sobre el asistente existente). Especificación: §4, §13, §15, §21, §24, §26, §28.
Reglas adicionales: ningún secreto en código, documentos o logs; el Bridge es de SOLO LECTURA en el origen; el instalador Windows y su firma de código quedan BLOQUEADOS (sin host Windows ni certificado del propietario); WhatsApp solo como contrato con confirmación humana; ninguna llamada a un proveedor LLM sin clave configurada (sin clave el asistente queda desactivado como hoy).
`

const PACKAGES = [
  {
    key: 'W6-A-enterprise-security',
    dbs: 'BD backend: predictily_test_w6a',
    owns: 'backend/app/Services/Auth/** (MFA), backend/app/Enums/TenantRole.php y la resolución de habilidades (solo el rol comercial y su mapeo), middleware nuevos de MFA y de alcance comercial, controladores y requests nuevos de MFA, tokens y dispositivos, pruebas nuevas de seguridad (backend/tests/Feature/Security/**), docs/security/ENTERPRISE_SECURITY.md (secciones nuevas), docs/architecture/TENANT_ISOLATION.md (nuevo)',
    prompt: `Paquete W6-A · Seguridad Enterprise (§15, ADR-V25-15).
1. Roles comerciales gerente/vendedor/servicio (tenant_users.commercial_role y commercial_scope) que se suman al rol de acceso: el vendedor ve solo sus clientes/zonas en Radar y decisiones (W5), el gerente ve su equipo, servicio solo lectura operativa; mapeo de habilidades documentado y probado.
2. MFA TOTP (RFC 6238) obligatorio para owner/admin y superadministrador cuando la empresa lo active (y siempre para el superadministrador en producción): alta con verificación del primer código, códigos de recuperación de un solo uso con hash, desactivación con reautenticación, ventana de tolerancia ±1 paso, sin secretos en logs; pruebas con reloj fijo.
3. Tokens con alcance y caducidad (Sanctum abilities/expires_at): emisión con alcance mínimo, caducidad por defecto configurable, revocación inmediata; revocación de dispositivos Bridge (bridge_devices.revoked_at) con efecto inmediato.
4. Pruebas negativas de aislamiento entre empresas en colas (un job con tenant A nunca lee B), caché (claves con prefijo de empresa), almacén de objetos (Bronze/artefactos), exportaciones LOPDP y endpoints nuevos de W2–W5 (matriz de rutas × roles × empresa).
5. RLS de PostgreSQL detrás de una bandera (desactivada por defecto) para las tablas principales de ventas, con prueba de que una consulta sin el contexto de empresa no devuelve filas cuando está activa; documenta límites y plan.
6. Suite completa del backend en verde; TENANT_ISOLATION.md y ENTERPRISE_SECURITY.md con estados honestos.`,
    review: 'Checklist: MFA correcto (RFC 6238, tolerancia, recuperación con hash, reautenticación) sin secretos en logs; alcance comercial aplicado en el servidor; tokens con alcance mínimo y revocación inmediata; matriz negativa de aislamiento real en colas, caché, almacén y exportaciones; RLS detrás de bandera con prueba; suite completa en verde.',
  },
  {
    key: 'W6-B-bridge-agent',
    dbs: 'BD backend: predictily_test_w6b',
    owns: 'bridge/** (nuevo: agente Python con su propio pyproject y pruebas), endpoints del backend para registro de dispositivos, heartbeat, URL prefirmada de subida y checkpoints (controladores, requests y rutas nuevas), docs/integrations/BRIDGE_WINDOWS.md, docs/integrations/CONNECTOR_FRAMEWORK.md y docs/integrations/ERP_COMPATIBILITY.md (nuevos)',
    prompt: `Paquete W6-B · Predictily Bridge (ADR-V25-10, §4). Instalador Windows y firma de código: BLOQUEADO.
1. Agente Python 3.12 de solo lectura (bridge/): registro del dispositivo (par de claves local; el backend guarda la huella y el hash del token), configuración local cifrada, adaptadores CSV/Excel, SQLite y PostgreSQL probados; MySQL y SQL Server implementados contra el mismo contrato (implemented_untested salvo que haya servidor de prueba); consultas SOLO SELECT validadas con las mismas reglas que SqlQueryGuard (reutiliza o porta sus reglas con pruebas de paridad).
2. Extracción incremental con marca de agua acotada y checkpoints (bridge_checkpoints), reintentos con backoff exponencial y jitter, búfer local cifrado ante caída de red (se reenvía en orden, idempotente por SHA-256), subida por URL prefirmada limitada a empresa/objeto/tamaño/tipo/TTL hacia Bronze (W2-B) y validación de checksum en el servidor; heartbeat y revocación (un dispositivo revocado no sube nada).
3. Pruebas: unidad del agente (pytest en bridge/), integración agente ↔ backend local (registro, subida, checkpoint, reintento, revocación), interrupción y reanudación sin duplicados.
4. Documentos: BRIDGE_WINDOWS.md (empaquetado y servicio de Windows: DISEÑADO/BLOQUEADO con pasos exactos para el propietario), CONNECTOR_FRAMEWORK.md, ERP_COMPATIBILITY.md (matriz por motor/ERP con estado honesto: probado, implemented_untested, bloqueado).`,
    review: 'Checklist: solo lectura real en el origen; reglas SELECT con paridad con SqlQueryGuard; registro con huella y token con hash; URL prefirmada acotada y checksum validado; búfer cifrado e idempotente; backoff con jitter; revocación efectiva; reanudación sin duplicados; estados honestos de compatibilidad; suites en verde.',
  },
  {
    key: 'W6-C-auto-mapper',
    dbs: 'BD backend: predictily_test_w6c',
    owns: 'backend/app/Services/DataCore/Mapping/** (nuevo), controladores y rutas nuevas de descubrimiento y plantillas, modelo MappingTemplate, pruebas nuevas, docs/integrations/AUTO_MAPPING.md y docs/integrations/ENTERPRISE_ONBOARDING.md (nuevos)',
    prompt: `Paquete W6-C · Schema discovery y Auto Mapper (§4, §17).
1. Descubrimiento de esquema de solo lectura para fuentes SQL (tablas, columnas, tipos, filas estimadas, claves) con consultas fijas de catálogo por dialecto (PostgreSQL, MySQL, SQL Server; parte de infra/scripts/pilot/sql_source_discovery.php), aisladas de SqlQueryGuard (consultas fijas, nunca texto del usuario) y con READ UNCOMMITTED/ApplicationIntent=ReadOnly en SQL Server.
2. Propuesta determinista de mapeo por entidad (ventas, productos, clientes, inventario, compras) con confianza heurística por campo (nombre, tipo, cardinalidad, muestra estadística sin PII) — nunca presentada como probabilidad — y value_maps propuestos para tipo y estado de documento a partir de los valores distintos observados (W2-A los aplica); aprobación explícita (mapping_templates.status approved) antes de cualquier sincronización.
3. Plantillas versionadas por sistema/proveedor/entidad (públicas de la plataforma o de la empresa) con la huella del esquema; deriva de esquema (W2-A) que invalida la plantilla y pide revisión.
4. Pruebas con un ERP ficticio PostgreSQL (TEST_ERP_DATABASE) y fixtures de catálogo de SQL Server/MySQL; suite completa del backend en verde.
5. Documentos: AUTO_MAPPING.md y ENTERPRISE_ONBOARDING.md (onboarding repetible paso a paso, con el checklist de §22).`,
    review: 'Checklist: consultas de catálogo fijas y de solo lectura; ninguna PII en muestras; confianza heurística declarada como tal; aprobación obligatoria antes de sincronizar; versionado y deriva; pruebas con ERP ficticio; suite completa en verde.',
  },
  {
    key: 'W6-D-intelligence-gateway',
    dbs: 'BD backend: predictily_test_w6d',
    owns: 'backend/app/Services/Conversation/** (evolución a gateway: registro de herramientas tipadas, autorización por herramienta, auditoría en gateway_tool_calls, defensa ante inyección), contrato de WhatsApp (solo interfaz y pruebas, sin proveedor), pruebas nuevas, docs/architecture/INTELLIGENCE_GATEWAY.md (nuevo)',
    prompt: `Paquete W6-D · Intelligence Gateway (ADR-V25-17, §13; P2 no bloqueante).
1. Herramientas tipadas con esquema de argumentos y autorización por herramienta (habilidad y alcance comercial de W6-A); el modelo nunca calcula cifras: solo explica resultados persistidos (pronósticos, oportunidades, decisiones, outcomes con su clasificación).
2. Defensa ante inyección de instrucciones: el contenido de datos (nombres, notas, eventos externos) se trata como datos, nunca como instrucciones; detección y bloqueo registrados (decision blocked_injection); pruebas con cargas adversariales.
3. Auditoría de cada llamada en gateway_tool_calls (argumentos minimizados sin PII, latencia, decisión).
4. Contrato de WhatsApp: interfaz de canal con confirmación humana obligatoria antes de cualquier envío; sin proveedor configurado el canal no existe (404); pruebas del contrato.
5. Sin clave de proveedor LLM el asistente sigue desactivado (503 assistant_disabled) y los cores funcionan igual; suite completa del backend en verde; INTELLIGENCE_GATEWAY.md con estados honestos.`,
    review: 'Checklist: herramientas tipadas y autorizadas por herramienta; el LLM no calcula cifras; inyección tratada como datos y bloqueada con registro; auditoría sin PII; WhatsApp solo contrato con confirmación; desactivado sin clave; suite completa en verde.',
  },
  {
    key: 'W6-E-factors-taxonomy-sector',
    dbs: 'BD del motor: predictily_engine_test_w6e; BD backend: predictily_test_w6e',
    owns: 'el cableado del pipeline de factores en engine/predictily_engine/pipeline/data.py y backtest/rolling.py (W2-D lo dejó DISEÑADO), un script de ablation engine/scripts/factor_ablation.py, la lectura de tenants.settings.factor_activation en el bloque factors del motor, contratos sectoriales (docs/intelligence/SECTOR_PROFILES.md y docs/product/*_ACCEPTANCE.md sectoriales), pruebas nuevas',
    prompt: `Paquete W6-E · Factores, taxonomía y contratos sectoriales ampliados (§24, ADR-V25-16; P2/P3 no bloqueantes).
1. Cablear el pipeline de factores (apagado por defecto) en load_tenant_data/run_pipeline y en cada ventana del backtest (FeatureBuilder.options() por ventana), leyendo la activación de la empresa en tenants.settings.factor_activation (W2-F) junto con factors.enabled; con el pipeline apagado, la demo y los E2E idénticos (prueba de paridad).
2. Script de ablation reproducible (con y sin cada familia activa sobre el holdout as-of, sin tocar el holdout final para decidir) que escribe factor_evaluations; documentado como herramienta P2.
3. Contratos sectoriales Retail/Export/Industry/Agro preservados: pruebas T15/T17 marcadas P3_PENDIENTE sin declarar cobertura; SECTOR_PROFILES.md actualizado.
4. Suites completas en verde.`,
    review: 'Checklist: pipeline de factores apagado por defecto con paridad exacta; activación leída de la empresa; ablation sin usar el holdout final para decidir; contratos sectoriales sin declarar cobertura falsa; suites en verde.',
  },
  {
    key: 'W6-F-closure-e2e-gates',
    dbs: 'BD de desarrollo del stack y BD backend predictily_test_w6f',
    after: ['W6-A-enterprise-security', 'W6-B-bridge-agent', 'W6-C-auto-mapper', 'W6-D-intelligence-gateway', 'W6-E-factors-taxonomy-sector'],
    owns: 'e2e/v25_acceptance.py (nuevo), docs restantes de §21 y §28 (docs/architecture/ENTERPRISE_ARCHITECTURE.md, docs/operations/OWNER_RESPONSIBILITIES.md, docs/product/RETAIL_MVP_ACCEPTANCE.md, docs/ml/FEATURE_SELECTION.md y los que falten), docs/roadmap/V25_RELEASE_GATES.md, docs/roadmap/audit/progress/*.json, docs/testing/V25_TEST_MATRIX.md, V25-ESTADO-Y-PROXIMOS-PASOS.md',
    prompt: `Paquete W6-F · Cierre (S17; §21, §23.5–7, §26, §28). Lo ejecuta el arquitecto o un agente bajo su revisión DESPUÉS de W6-A..E.
1. E2E V2.5 (e2e/v25_acceptance.py, sin editar los E2E heredados): 2 empresas; fuente SQL (ERP ficticio PostgreSQL) y CSV; calidad y cuarentena (W2); entrenamiento validado con gates; registro con aprobación separada y firma (W4); inferencia; Radar, decisión con workflow y outcome con clasificación (W5); un fallo de calidad que bloquea; reversión; cambio de empresa sin filtraciones. AWS: BLOQUEADO (el E2E corre local y lo declara).
2. Documentos exigidos por §21/§28 que falten, cada uno con IMPLEMENTADO/VERIFICADO, DISEÑADO/NO IMPLEMENTADO, BLOQUEADO y NO APLICA, comandos reales y la tabla final por módulo (evidencia, estado de pruebas, costo, riesgo, aprobación).
3. Progreso por oleada de W2–W6 en docs/roadmap/audit/progress/ y regeneración de la hoja de ruta; matriz T01–T17 actualizada; V25_RELEASE_GATES.md final con GO / GO CONDICIONAL / NO-GO y, por cada NO-GO, prueba fallida, error, riesgo y plan de remediación; changelog de lo hecho y lo pendiente.`,
    review: 'Checklist: E2E V2.5 real con todos los pasos o bloqueo explícito; documentos completos con estados honestos y comandos reales; gates decididos con evidencia, sin GO con gates obligatorios fallidos; changelog de lo hecho y lo pendiente.',
  },
]

// Orquestación: implementación → revisión adversarial → corrección; A–E en paralelo; F al final.
