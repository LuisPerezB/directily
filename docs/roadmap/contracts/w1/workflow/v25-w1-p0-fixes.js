export const meta = {
  name: 'v25-w1-p0-fixes',
  description: 'V2.5 W1: correcciones P0 de correctitud temporal y seguridad (ventas versionadas, lectura as-of, días sin datos, SSRF, ZIP bomb, firma de artefactos)',
  phases: [
    { title: 'Implementación', detail: 'A1 backend temporal, A2 motor as-of, B seguridad' },
    { title: 'Revisión', detail: 'revisión adversarial por paquete' },
    { title: 'Corrección', detail: 'corregir hallazgos y re-ejecutar pruebas' },
  ],
}

const COMMON = `
Proyecto: Predictily, SaaS B2B multi-tenant (repositorio /home/user/directily, rama v25/enterprise-core). Eres ingeniero senior y trabajas bajo un arquitecto que fija los contratos y revisa e integra tu trabajo.
Stack: backend/ Laravel 13 (PHP 8.3, Sanctum, PostgreSQL, colas Redis; modelos con BelongsToTenant = global scope fail-closed; FKs compuestas (tenant_id, x_id)); engine/ Python 3.12 FastAPI (venv engine/.venv); frontend/ Vue 3 TS.
Especificación vigente: docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md (V2.5). Decisiones: docs/architecture/adr/V25-ADRS.md (lee ADR-V25-03, -20 y -23). Auditoría con evidencia: docs/roadmap/audit/2026-10-31/*.json (campo "defects").
Reglas de trabajo:
- NO hagas commit ni push: el arquitecto integra.
- NO modifiques migraciones existentes. La migración backend/database/migrations/2026_03_01_000100_add_sales_knowledge_time_and_revisions.php es el contrato fijado por el arquitecto: léela completa y respétala. Si necesitas otra migración, créala nueva con el prefijo que se te asigne y justifícala en tu informe.
- NO toques la base de desarrollo "predictily" (la usa el stack y el E2E). Usa SOLO tus bases de prueba asignadas.
- Si PostgreSQL o Redis no responden: service postgresql start; service redis-server start.
- Crear una base de prueba: cd backend && php artisan tinker --execute="try { DB::statement('CREATE DATABASE <nombre>'); } catch (\\Throwable \\$e) { echo 'ya existe'; }"
- Pruebas backend: cd backend && DB_DATABASE=<tu_bd_backend> php artisan test [--filter=...] (RefreshDatabase migra solo). Estilo: vendor/bin/pint sobre los archivos PHP que toques.
- Pruebas del motor: crea tu BD del motor, mígrala con: cd backend && DB_DATABASE=<tu_bd_motor> php artisan migrate:fresh --force ; y ejecuta: cd engine && ENGINE_TEST_DATABASE_URL=postgresql+psycopg://predictily:secret@127.0.0.1:5432/<tu_bd_motor> .venv/bin/python -m pytest -q [ruta]
- Nunca ejecutes pkill -f / pgrep -f con un patrón dentro de un comando en línea (se mata a sí mismo); si necesitas detener procesos, hazlo con un script en archivo.
- Textos, comentarios y documentación en español, con el estilo existente. Nada de credenciales en código, documentos ni logs.
- Documentación: cada documento que crees o actualices distingue IMPLEMENTADO/VERIFICADO (con la prueba que lo demuestra), DISEÑADO/NO IMPLEMENTADO, BLOQUEADO y NO APLICA.
- No debilites pruebas existentes para que pasen. Si una prueba existente choca con el contrato, explícalo en tu informe.
`

const REPORT_SCHEMA = {
  type: 'object',
  properties: {
    package: { type: 'string' },
    files_changed: { type: 'array', items: { type: 'string' } },
    behaviors_implemented: { type: 'array', items: { type: 'string' } },
    tests_added: { type: 'array', items: { type: 'string' } },
    test_runs: { type: 'array', items: { type: 'object', properties: {
      command: { type: 'string' }, result: { type: 'string' } }, required: ['command', 'result'] } },
    deviations_from_contract: { type: 'array', items: { type: 'string' } },
    pending_or_blocked: { type: 'array', items: { type: 'string' } },
    notes_for_architect: { type: 'string' },
  },
  required: ['package', 'files_changed', 'behaviors_implemented', 'tests_added', 'test_runs', 'deviations_from_contract', 'pending_or_blocked', 'notes_for_architect'],
}

const REVIEW_SCHEMA = {
  type: 'object',
  properties: {
    package: { type: 'string' },
    verdict: { type: 'string', enum: ['APPROVE', 'CHANGES_REQUIRED'] },
    findings: { type: 'array', items: { type: 'object', properties: {
      severity: { type: 'string', enum: ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] },
      title: { type: 'string' }, evidence: { type: 'string' }, required_fix: { type: 'string' } },
      required: ['severity', 'title', 'evidence', 'required_fix'] } },
    tests_run: { type: 'array', items: { type: 'string' } },
    contract_checklist: { type: 'array', items: { type: 'object', properties: {
      item: { type: 'string' }, ok: { type: 'boolean' }, note: { type: 'string' } }, required: ['item', 'ok', 'note'] } },
  },
  required: ['package', 'verdict', 'findings', 'tests_run', 'contract_checklist'],
}

const PACKAGES = [
  {
    key: 'A1-backend-temporal',
    dbs: 'BD backend: predictily_test_w1a',
    prompt: `Paquete W1-A1 · Escritura temporal de ventas en el backend (ADR-V25-23).
Archivos que te pertenecen: backend/app/Services/Sales/**, backend/app/Services/Imports/** (EXCEPTO backend/app/Services/Imports/Reading/XlsxReader.php y cualquier clase nueva de inspección ZIP, que son de otro paquete), backend/app/Services/DataCore/Sync/**, requests/controladores de importación, de POST /sales/batch y de cobertura, backend/app/Console/Commands del demo, backend/app/Models (nuevos SalesDocumentRevision, SaleItemRevision, DataCoverageGap), backend/routes/api/datacore.php, backend/tests/Feature/** nuevos, docs/03-modelo-datos.md, docs/06-contratos-integracion.md, docs/openapi/core.yaml y docs/openapi/v2.yaml (regenera docs/openapi/predictily-api.yaml con infra/scripts/merge_openapi.py; revisa su --help; OpenApiRoutesTest debe pasar), docs/data/LINEAGE_AND_RECONCILIATION.md (nuevo).
Prohibido: engine/, frontend/, migraciones existentes, SqlHostPolicy, XlsxReader, ModelArtifactVerifier.
Comportamiento exigido:
1. Modo de ingestión (sales.ingestion_mode): valores backfill|incremental.
   - Importación de archivo: opción import_jobs.options.ingestion_mode (validada). Si no se declara: backfill si la empresa no tiene ninguna fila en sales al iniciar la importación (primera carga histórica); si no, incremental. Guarda el valor resuelto en import_jobs.options y en las estadísticas/respuesta.
   - POST /sales/batch: campo opcional ingestion_mode; por defecto incremental.
   - Sincronización SQL (SourceSyncService): la primera sincronización exitosa de una fuente es backfill salvo que se declare otra cosa; las siguientes, incremental; permite declararlo en la solicitud o en source_mappings.options.
   - Comando del demo: backfill explícito.
2. SalesDocumentWriter:
   - Documento nuevo: ingestion_mode resuelto; first_seen_at = ahora; current_revision = 0; version_known_from = NULL si backfill (inicio del día local de sale_date) o = ahora si incremental.
   - Documento existente con source_hash distinto:
     a) Orden: si la entrante y la almacenada tienen source_recorded_at y la entrante es ANTERIOR, se descarta como 'stale_skipped' (no se escribe nada). Si alguna no tiene marca de tiempo, gana la última ingestión y se cuenta 'updated_without_source_order'.
     b) Antes de sobrescribir, archiva la versión vigente: fila en sales_document_revisions con la cabecera ACTUAL (revision = current_revision; known_from = version_known_from actual, puede ser NULL; known_until = known_from de la nueva versión: ahora si la nueva es incremental; si la nueva es backfill, el inicio del día local de la sale_date ANTERIOR (ventana vacía: una re-carga histórica colapsa versiones); usa la zona horaria de la empresa, tenants.timezone); change_kind = status_changed | lines_changed | header_changed | mixed; superseded_by_import_job_id; y copia sus líneas vigentes a sale_item_revisions con el mismo revision.
     c) Luego reemplaza cabecera y líneas como hoy, con current_revision + 1, version_known_from según el modo de ESTA escritura e ingestion_mode de esta escritura. first_seen_at NUNCA se actualiza.
     d) Todo dentro de la transacción y del advisory lock existentes; por lotes (sin consultas por documento si puedes evitarlo).
   - Estadísticas nuevas (aditivas): stale_skipped, revisions_archived, updated_without_source_order; propágalas a import_jobs y a la respuesta de /sales/batch.
3. Tipos y estados desconocidos (defecto HIGH de datos.json): un tipo de documento o estado NO vacío que no se reconozca (ni por los sinónimos existentes) ya no se cuenta como venta: es un error de fila con código unknown_document_type / unknown_document_status y un campo dq_class = 'REVIEW_REQUIRED' en el detalle del error; el documento no se escribe (se aplica la lógica actual de skip_invalid_rows). Estado vacío = completed (convención documentada, se mantiene). Antes de cambiarlo revisa e2e/mvp_acceptance.py y los archivos data/demo/*_sales_with_errors.csv: si la expectativa del E2E cambiaría, NO edites el E2E: explica el impacto en tu informe.
4. Marca de agua (defecto HIGH de conectores.json, SourceSyncService.php:127-128): watermark = min(máx. source_recorded_at extraído, inicio de la sincronización); valores futuros o ilegibles se cuentan como anomalía en las estadísticas ('future_recorded_at') y no adelantan la marca.
5. Huecos de cobertura: endpoints GET/POST /api/v1/data-coverage/gaps y DELETE /api/v1/data-coverage/gaps/{id} sobre data_coverage_gaps (entity en lista blanca, gap_to >= gap_from, data_source_id y branch_id de la MISMA empresa, basis 'declared' para los creados por usuarios); habilidad de lectura para consultar y de gestión de datos para crear/borrar (usa las habilidades existentes del enum TenantRole que correspondan); aislamiento por empresa; OpenAPI.
6. Pruebas (BD predictily_test_w1a), como mínimo: nuevo backfill (known_from NULL, first_seen_at); primera importación backfill y segunda incremental por defecto; declaración explícita respetada; /sales/batch incremental por defecto; actualización incremental archiva cabecera y líneas con ventanas correctas y la vista sales_line_versions devuelve ambas versiones con [known_from, known_until) correctos; cambio de estado completed→voided produce status_changed; re-carga backfill colapsa (known_until = known_from); versión entrante más vieja → stale_skipped sin cambios; tipo/estado desconocido → REVIEW_REQUIRED y no escrito; estado vacío → completed; watermark acotada con source_recorded_at futuro; huecos: CRUD, validación y aislamiento; aislamiento de revisiones entre empresas. Al final, suite completa del backend en verde (hoy: 449 aprobadas, 2 omitidas) y Pint.
7. Documentación: docs/03-modelo-datos.md (tablas, columnas y vista nuevas), docs/06-contratos-integracion.md (ingestion_mode, stale, estadísticas nuevas, endpoints de huecos), docs/data/LINEAGE_AND_RECONCILIATION.md (nuevo: versiones de documentos, orden de escritura, modos de ingestión, qué está verificado y con qué prueba, y qué queda DISEÑADO: reporte fuente→Bronze→Silver→Gold, que es otro paquete).`,
    review: `Checklist del revisor para A1: modo de ingestión en los 4 caminos (archivo, batch, sincronización, demo) con los valores por defecto exactos; archivo de versión ANTES de sobrescribir; ventanas known_from/known_until correctas (incremental: ahora; backfill: colapso con la zona horaria de la empresa); first_seen_at inmutable; stale_skipped sin escritura; cuentas nuevas propagadas; tipos/estados desconocidos → REVIEW_REQUIRED sin escribir; estado vacío = completed; watermark acotada; endpoints de huecos con aislamiento y validación de pertenencia de data_source_id/branch_id; consultas por lotes (sin N+1 por documento); pruebas que AFIRMAN cada comportamiento (lee sus cuerpos); suite completa del backend en verde con DB_DATABASE=predictily_test_w1a; OpenAPI regenerada y OpenApiRoutesTest en verde; documentación con estados honestos.`,
  },
  {
    key: 'A2-engine-asof',
    dbs: 'BD del motor: predictily_engine_test_w1b',
    prompt: `Paquete W1-A2 · Lectura as-of de ventas en el motor y clasificación de días (ADR-V25-03 y ADR-V25-23).
Archivos que te pertenecen: engine/predictily_engine/db/repository.py, engine/predictily_engine/db/customer_repository.py, engine/predictily_engine/profiling/**, engine/predictily_engine/pipeline/core.py, engine/predictily_engine/features/** (si hace falta), engine/predictily_engine/customer/dataset.py (si hace falta), engine/predictily_engine/platform/snapshot.py y platform/config.py (claves nuevas), un módulo nuevo engine/predictily_engine/data/asof.py (o equivalente), engine/tests/** nuevos, docs/data/TEMPORAL_AVAILABILITY.md (nuevo), docs/04-estrategia-modelado.md.
En engine/predictily_engine/pipeline/forecast_run.py y engine/predictily_engine/customer/service.py SOLO puedes tocar la carga de datos, nunca el guardado/carga de artefactos (son de otro paquete que trabaja en paralelo).
Prohibido: backend/** (salvo leer), migraciones, frontend/.
Comportamiento exigido:
1. Fuente única: la vista sales_line_versions (léela en la migración del contrato). Lee las versiones una vez hasta el corte final y deriva cada vista as-of en pandas: una versión es visible en el corte C si known_from < medianoche local de (C+1) en la zona de la empresa (tenants.timezone) y (known_until es NULL o known_until >= esa medianoche). Mantén las firmas públicas (daily_sales(cutoff), daily_returns, last_sale_date) con semántica as-of al cutoff, para que los llamadores existentes la obtengan sin cambios.
2. Backtest: cada ventana del rolling-origin (y el holdout final) entrena con los datos tal como se conocían en SU corte (panel, perfilado de demanda/profile_at y features). Los reales con que se evalúa cada ventana usan el conocimiento del corte final (la verdad disponible), documentado. Una sola lectura de BD; filtros vectorizados.
3. Customer Core: features as-of al corte de cada observación; etiquetas (compras en (corte, corte+H]) con el conocimiento más reciente (verdad), documentado. Corrige customer_repository.purchases()/returns() (defecto HIGH de cliente.json).
4. Clasificación de días (ADR-V25-03) en el panel diario: columna day_status ∈ {sale, zero_confirmed, missing_source, stockout_censored, closed}.
   - missing_source: fuera de data_coverage (entity 'sales') declarada para la fuente/sucursal (antes de complete_from o después de complete_through), o dentro de data_coverage_gaps (entity 'sales', declared o inferred).
   - closed: calendar_events con category 'closure' (documenta este valor nuevo de categoría) de alcance tenant (scope 'tenant') o sucursal (scope 'branch', scope_ref 'branch:<id>').
   - Regla inferida configurable (platform/config.py, por defecto activa): un día en que una sucursal no tiene NINGUNA línea pese a que su mediana de líneas diarias en los 28 días previos es >= 5 se marca missing_source con basis inferred (umbral configurable; documenta la justificación y que debe aprobarse en el piloto).
   - stockout_censored: la lógica actual de quiebres.
   - Sin cobertura declarada se conserva el comportamiento actual y la limitación coverage_undeclared.
   - missing_source y closed: se EXCLUYEN de las métricas (WAPE/MASE/sesgo/cobertura) y de la proporción de ceros del perfilado; para ajustar modelos que exigen series contiguas se imputan con la misma imputación que hoy usan los quiebres (rolling 28 días), marcados en el manifiesto/limitaciones con su conteo. Nunca cuentan como cero observado.
5. Huella: platform/snapshot.py debe calcular el hash del componente de ventas sobre los datos as-of efectivamente usados (incluye ventanas de conocimiento), para que dos cortes con distinto conocimiento no compartan huella.
6. Pruebas adversariales T02 (BD predictily_engine_test_w1b; inserta filas directamente en sales, sale_items, sales_document_revisions y sale_item_revisions según la migración): documento incremental cargado tarde invisible antes de su ingestión y visible después; anulación posterior: contado antes y excluido después; documento backfill visible desde su fecha; ventana de backtest no cambia al perturbar datos conocidos después de su corte (generaliza las pruebas de fuga existentes, p. ej. test_leakage); features de Customer as-of en esos mismos casos; huecos declarados y cierres excluidos de métricas y no contados como ceros; regla inferida; fixtures existentes (inserciones directas con los valores por defecto) se comportan como antes. Al final, suite completa del motor en verde (hoy: 418 aprobadas).
7. Documentación: docs/data/TEMPORAL_AVAILABILITY.md (nuevo: tres fechas, ventanas de conocimiento, modos de ingestión, regla de visibilidad, backtest as-of por ventana, features vs etiquetas, clasificación de días, limitaciones y lista de pruebas con su estado) y la sección correspondiente de docs/04-estrategia-modelado.md.
No uses la base de desarrollo para medir métricas: el arquitecto comparará la demo antes/después al integrar. Informa el costo de tiempo de la lectura as-of en tus pruebas.`,
    review: `Checklist del revisor para A2: lectura única de sales_line_versions con regla de visibilidad exacta (medianoche local de C+1, zona de la empresa, known_until inclusive en el límite como dice el contrato); cada ventana del backtest y el holdout usan datos as-of de su corte (busca cualquier camino que aún lea por fecha de evento o use el estado actual, incluidos last_sale_date, devoluciones, precios derivados de ventas y perfilado); etiquetas vs features en Customer; day_status con las cinco clases y exclusión de métricas; regla inferida configurable; huella as-of; pruebas que AFIRMAN cada caso (lee sus cuerpos; intenta romperlas mentalmente); suite completa del motor en verde con ENGINE_TEST_DATABASE_URL de predictily_engine_test_w1b; sin tocar el guardado/carga de artefactos; documentación honesta.`,
  },
  {
    key: 'B-security',
    dbs: 'BD backend: predictily_test_w1c; BD del motor: predictily_engine_test_w1c',
    prompt: `Paquete W1-B · Endurecimiento de seguridad P0 (defectos HIGH de conectores.json, seguridad_ui.json y modelos_aws.json; ADR-V25-15 y ADR-V25-20).
Archivos que te pertenecen: backend/app/Services/Connectors/Sql/** y conectores que usan SqlHostPolicy, backend/config/connectors.php, backend/app/Services/Imports/Reading/XlsxReader.php y una clase nueva de inspección ZIP, backend/app/Http/Requests/Core/StoreImportRequest.php, el verificador de artefactos del backend (ModelArtifactVerifier y su configuración), backend/.env.example (variables nuevas, sin valores secretos), engine/predictily_engine/platform/artifacts.py, un módulo nuevo engine/predictily_engine/platform/signing.py, en engine/predictily_engine/pipeline/forecast_run.py y engine/predictily_engine/customer/service.py SOLO las funciones de guardado/carga de artefactos (otro paquete toca la carga de datos de esos archivos en paralelo: usa ediciones acotadas), engine/predictily_engine/cli.py (comando de claves), el archivo de dependencias del motor (requirements/pyproject), engine/.env.example, pruebas nuevas en backend/tests y engine/tests, docs/07-seguridad-multitenant.md, docs/security/ENTERPRISE_SECURITY.md (nuevo, secciones de este paquete), docs/ml/MODEL_REGISTRY.md (nuevo, sección de firma de artefactos).
Prohibido: migraciones existentes (si necesitas columnas nuevas usa primero los jsonb existentes de model_registry como artifact_compat; si es imprescindible, crea una migración nueva con prefijo 2026_03_02_000100), SalesDocumentWriter, ImportService (salvo una llamada mínima si es imprescindible), repository.py, profiling/.
1. SSRF (SqlHostPolicy): solo nombres RFC 1123 o literales IP canónicos (rechaza formas numéricas decimales/hex/octales como 2130706433, 0x7f.0.0.1, 0177.0.0.1); resuelve A y AAAA; des-mapea IPv4 mapeadas/compatibles en IPv6 (::ffff:a.b.c.d, ::ffff:a9fe:a9fe); bloquea SIEMPRE loopback, 0.0.0.0/8, link-local (169.254.0.0/16, fe80::/10), metadatos (169.254.169.254, fd00:ec2::254), multicast y broadcast; redes privadas (RFC 1918, ULA fc00::/7, 100.64.0.0/10) DENEGADAS por defecto salvo lista blanca explícita (config connectors: hosts y CIDR permitidos); conecta a la IP resuelta para evitar DNS rebinding (en PostgreSQL usa hostaddr manteniendo host para TLS; en MySQL/SQL Server conecta al literal IP). Las pruebas existentes que conectan al PostgreSQL local deben declarar la lista blanca explícitamente. Pruebas para cada vector de evasión.
2. ZIP bomb (XLSX): antes de abrir, inspecciona el directorio central con ZipArchive: nº de entradas, suma de tamaños descomprimidos, razón por entrada, archivos anidados; límites configurables con valores por defecto razonables para el histórico real (hoy se importan 99 334 líneas en 19 MB); límites de filas/columnas y de tiempo en la lectura de encabezados/vista previa (que hoy ocurre dentro de la petición); error en español con código estable (413 o 422). Prueba con una bomba generada en la prueba: rechazada en menos de 2 s.
3. Firma de artefactos Ed25519 (ADR-V25-20; defecto HIGH: joblib.load con solo SHA-256 permite ejecución de código):
   - Motor: platform/signing.py con la librería cryptography (instálala en engine/.venv con pip y fíjala en el archivo de dependencias). Claves por entorno: ENGINE_SIGNING_KEY_ID, ENGINE_SIGNING_PRIVATE_KEY (lado de entrenamiento), ENGINE_TRUSTED_PUBLIC_KEYS (lista kid:clave). Manifiesto JSON canónico (claves ordenadas) con sha256 y tamaño del artefacto, tenant_id, core, run, familia/nombre, created_at, versiones de librerías, versión del esquema de features y huella de datos; firma sobre los bytes del manifiesto; archivo <artefacto>.manifest.json junto al artefacto.
   - Al guardar: firma si hay clave privada; si no, el artefacto queda marcado sin firma.
   - Al cargar: verifica firma con claves de confianza, sha256 y tamaño ANTES de joblib.load; si falta o no coincide, NO se deserializa: se usa el respaldo existente (reajuste estadístico/baseline) con una marca explícita y un log sin PII. ENGINE_ALLOW_UNSIGNED_ARTIFACTS (por defecto false) permite artefactos legados SIN firma solo si se activa explícitamente; nunca acepta firmas inválidas. El manifiesto liga el tenant: un artefacto firmado de otra empresa se rechaza.
   - CLI: comando para generar un par de claves (la privada a un archivo con permisos 0600; la pública impresa); documenta el procedimiento.
   - Pruebas del motor (BD predictily_engine_test_w1c para lo que toque BD; conftest genera claves efímeras): artefacto válido carga; bytes alterados → rechazado; pickle malicioso con sha256 coherente y sin firma o con firma inválida → rechazado SIN efecto lateral (verifica que el payload no se ejecutó); clave desconocida → rechazado; artefacto de otra empresa → rechazado; sin firma con el flag desactivado → rechazado, con el flag activado → cargado.
   - Backend: el verificador de reversión comprueba la firma Ed25519 (extensión sodium de PHP, sodium_crypto_sign_verify_detached) con las claves públicas de confianza de la configuración, además del SHA-256; la reversión a un artefacto sin firma o con firma inválida se rechaza (salvo el mismo flag explícito). Pruebas (BD predictily_test_w1c).
4. Las suites completas deben quedar en verde (backend con DB_DATABASE=predictily_test_w1c: hoy 449 aprobadas y 2 omitidas; motor con predictily_engine_test_w1c: hoy 418). Si la firma obligatoria rompe pruebas existentes del motor que guardan y cargan artefactos, configura claves efímeras en conftest; NO actives ENGINE_ALLOW_UNSIGNED_ARTIFACTS globalmente en las pruebas.
5. Documentación: docs/07-seguridad-multitenant.md (SSRF, cargas, firma), docs/security/ENTERPRISE_SECURITY.md (nuevo, secciones de este paquete con estado honesto), docs/ml/MODEL_REGISTRY.md (nuevo, sección de firma y verificación; el resto de la gestión del registro queda DISEÑADO), variables nuevas en los .env.example.`,
    review: `Checklist del revisor para B: intenta EVADIR la política SSRF (decimal, hex, octal, IPv6 mapeada y compatible, AAAA, nombres que resuelven a privadas, rebinding entre validación y conexión, puertos, nombres con punto final o mayúsculas); valores por defecto seguros; lista blanca explícita en pruebas; ZIP: entradas anidadas, razón alta en un solo archivo, muchas entradas pequeñas, sharedStrings enorme, tiempo de rechazo; firma: intercambio de manifiestos entre artefactos, reutilizar la firma de otra empresa, alterar el manifiesto, borrar el manifiesto, clave desconocida, flag de no firmados, verificación ANTES de deserializar (busca cualquier joblib.load restante sin verificación en todo el motor), backend con sodium; suites completas en verde con las BD asignadas; documentación honesta.`,
  },
]

const RESUME = {
  'A2-engine-asof': `\n\nREANUDACIÓN: este paquete ya se implementó casi por completo en un intento anterior que se interrumpió por límite de uso. Su trabajo está confirmado en commits WIP de la rama (base previa a W1: b975897). Antes de tocar nada, revisa lo hecho con: git diff b975897 HEAD -- engine/ docs/data/TEMPORAL_AVAILABILITY.md docs/04-estrategia-modelado.md (y git status). Según su lista de tareas estaban HECHOS: asof.py y lectura de versiones, clasificación de días, backtest as-of por ventana, Customer as-of, huella y configuración, pruebas adversariales T02 y documentación; y aplicada en código, pruebas y docs una extensión: el corte final de una ejecución vigente conoce hasta la hora de ejecución (los documentos ingeridos hoy sobre días pasados son conocidos por el pronóstico de producción), mientras las ventanas del backtest y las corridas históricas conocen hasta el fin del día de su corte. FALTABA: la suite completa del motor en verde y el informe. No rehagas lo hecho salvo que esté mal: verifica, completa lo pendiente, ejecuta la suite completa con tu BD y devuelve el informe estructurado de TODO el paquete.`,
  'B-security': `\n\nREANUDACIÓN: este paquete se implementó en buena parte en un intento anterior interrumpido por límite de uso. Su trabajo está confirmado en commits WIP de la rama (base previa a W1: b975897). Antes de tocar nada, revisa lo hecho con: git diff b975897 HEAD -- backend/app/Services/Connectors backend/config backend/app/Services/Imports/Reading backend/app/Http/Requests/Core/StoreImportRequest.php backend/app/Services/Forecasting backend/tests engine/predictily_engine/platform engine/predictily_engine/pipeline/forecast_run.py engine/predictily_engine/customer/service.py engine/predictily_engine/cli.py engine/pyproject.toml engine/tests docs/07-seguridad-multitenant.md docs/security docs/ml backend/.env.example engine/.env.example (y git status). Según su lista de tareas estaban HECHOS: entorno, SSRF con IP fija y pruebas de evasión, inspector ZIP con límites y prueba de bomba, verificador del backend con sodium y pruebas, documentación; EN CURSO: firma Ed25519 en el motor (signing.py, artifacts.py, forecast_run, customer/service, CLI, pruebas); FALTABA: suites completas en verde, Pint e informe. No rehagas lo hecho salvo que esté mal: verifica, completa lo pendiente, ejecuta las suites completas con tus BD y devuelve el informe estructurado de TODO el paquete.`,
}

function implPrompt(p) {
  return `${COMMON}\nTus bases de prueba: ${p.dbs}.\n\n${p.prompt}\n\nAl terminar, devuelve el informe estructurado (archivos, comportamientos, pruebas agregadas, ejecuciones con su resultado literal resumido, desviaciones del contrato, pendientes y notas para el arquitecto).${RESUME[p.key] || ''}`
}

function reviewPrompt(p, report) {
  return `${COMMON}\nEres REVISOR ADVERSARIAL del paquete ${p.key}. Otro ingeniero lo implementó; su informe es:\n${JSON.stringify(report).slice(0, 12000)}\n\nEspecificación del paquete:\n${p.prompt}\n\n${p.review}\n\nRevisa el diff real del paquete contra la base previa a W1: git diff b975897 -- <archivos del paquete> (parte del trabajo ya está en commits WIP de la rama) más los cambios sin confirmar (git status y git diff; los archivos nuevos no rastreados también cuentan). Otros dos paquetes trabajan en paralelo: limita tu revisión a los archivos de ESTE paquete. Ejecuta las pruebas del paquete y la suite completa correspondiente con las MISMAS bases asignadas (${p.dbs}). No corrijas tú el código: reporta hallazgos accionables con evidencia (archivo:línea) y la corrección exigida. Veredicto APPROVE solo si no hay hallazgos CRITICAL/HIGH/MEDIUM.`
}

function fixPrompt(p, report, review) {
  return `${COMMON}\nTus bases de prueba: ${p.dbs}.\n\nEres el ingeniero del paquete ${p.key}. Especificación:\n${p.prompt}\n\nInforme de implementación previo:\n${JSON.stringify(report).slice(0, 8000)}\n\nLa revisión adversarial pidió cambios:\n${JSON.stringify(review).slice(0, 12000)}\n\nCorrige TODOS los hallazgos CRITICAL, HIGH y MEDIUM (y los LOW baratos), con pruebas que lo demuestren; vuelve a ejecutar las pruebas del paquete y la suite completa correspondiente. Devuelve el informe estructurado actualizado (incluye en notes_for_architect cómo resolviste cada hallazgo o por qué no).`
}

const results = await pipeline(
  PACKAGES,
  p => agent(implPrompt(p), { label: `implementar:${p.key}`, phase: 'Implementación', schema: REPORT_SCHEMA, model: 'sonnet' }),
  (report, p) => agent(reviewPrompt(p, report), { label: `revisar:${p.key}`, phase: 'Revisión', schema: REVIEW_SCHEMA, model: 'sonnet' })
    .then(review => ({ report, review })),
  (rr, p) => {
    const blocking = (rr.review && rr.review.findings || []).filter(f => ['CRITICAL', 'HIGH', 'MEDIUM'].includes(f.severity))
    if (rr.review && rr.review.verdict === 'APPROVE' && blocking.length === 0) {
      return { package: p.key, report: rr.report, review: rr.review, fix: null }
    }
    return agent(fixPrompt(p, rr.report, rr.review), { label: `corregir:${p.key}`, phase: 'Corrección', schema: REPORT_SCHEMA, model: 'sonnet' })
      .then(fix => ({ package: p.key, report: rr.report, review: rr.review, fix }))
  },
)
return results
