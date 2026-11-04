export const meta = {
  name: 'v25-w4-mlops-aws',
  description: 'V2.5 W4: MLOps P0 (S06 registro con aprobación separada, CLI de entrenamiento privado, publicación al almacén de objetos, serving solo de lo aprobado, aislamiento en registro/caché/jobs) y S13 AWS (IaC con CDK verificada por síntesis, costo por empresa medido, despliegue y recuperación documentados; despliegue real BLOQUEADO)',
  phases: [
    { title: 'Implementación', detail: 'A registro y aprobación (backend), B CLI y serving (motor), C IaC, costos y recuperación' },
    { title: 'Revisión', detail: 'revisión adversarial por paquete' },
    { title: 'Corrección', detail: 'corregir hallazgos y re-ejecutar pruebas' },
  ],
}

const BASE = (args && args.base) || 'HEAD'

// Contexto común: el de W2 (constante COMMON de docs/roadmap/contracts/w2/workflow/v25-w2.js), más lo siguiente.
const COMMON_W4 = `
Contrato de W4 fijado por el arquitecto (léelo completo; NO lo modifiques): backend/database/migrations/2026_03_12_000100_add_model_lifecycle_publication_and_usage_metering.php (estados con CHECK, aprobación separada approved_by ≠ published_by/entrenador, publicación al almacén, model_registry_events, tenant_usage_records). ADR: V25-07 (registro y serving solo de lo aprobado), V25-08 (almacén de objetos: claves del servidor con prefijo de la empresa), V25-09 (CDK en TypeScript), V25-19 (CLI) y V25-20 (firma: todo artefacto se firma al guardar y se verifica antes de deserializar). Contratos previos: decisiones de promoción y shadow de W3 (model_promotion_decisions; aprobación pending que W4 completa), BronzeStore/ObjectStore de W2-B.
Reglas adicionales: ningún secreto ni credencial en código, documentos o logs; nada se despliega en AWS (no hay cuenta del propietario: BLOQUEADO); ningún precio inventado (el modelo de costos es paramétrico con fuentes citadas o marcadas «a confirmar»).
`

const PACKAGES = [
  {
    key: 'W4-A-registry-approval-backend',
    dbs: 'BD backend: predictily_test_w4a',
    owns: 'backend/app/Services/Models/** (nuevo: máquina de estados del registro), controladores y requests nuevos de modelos, backend/routes/api/*.php (solo rutas nuevas), el enum de habilidades TenantRole (solo la habilidad models.approve y su asignación), backend/app/Services/Forecasting/DemandRollbackService.php y el rollback de Customer (solo para exigir estado approved/production), modelos ModelRegistryEvent y TenantUsageRecord, backend/app/Services/Privacy/TenantDataExporter.php (solo lo nuevo de W4), docs/openapi/*.yaml, pruebas nuevas, docs/ml/MODEL_REGISTRY.md (secciones de ciclo de vida y aprobación) y docs/ml/MODEL_DEPLOYMENT.md (nuevo)',
    prompt: `Paquete W4-A · Ciclo de vida del registro con aprobación separada (ADR-V25-07; requisitos V25-REG-*).
1. Máquina de estados candidate → validated → approved → production → retired, más rejected y failed (legado): transiciones permitidas explícitas; toda transición escribe model_registry_events (de, a, actor, tipo, motivo, evidencia) en la misma transacción.
2. Aprobación separada: POST /api/v1/models/{id}/approve y /reject (motivo obligatorio) solo con la habilidad models.approve (nueva; por defecto propietario/administrador) y SOLO si el usuario no es quien publicó (published_by) ni quien disparó el entrenamiento (training_jobs.user_id / triggered_by): prueba de segregación de funciones. Aprobar exige firma verificada (ModelArtifactVerifier) y el gate de datos de W2-C ≠ BLOCK de su ejecución; la decisión de promoción de W3 asociada (promotion_decision_id) queda con approved_by/approved_at.
3. Serving solo de lo aprobado: la promoción a production (y el rollback de demanda y de Customer) acepta solo modelos approved (o production retirados que fueron aprobados antes); los modelos legados sin aprobación se tratan con una política explícita documentada (p. ej. «legado aprobado por migración» marcado en evidence) para no romper la demo ni los E2E; prueba de que un candidate no se sirve.
4. Listado y detalle: GET /api/v1/models (filtros core, estado, familia), GET /api/v1/models/{id} con eventos; aislamiento por empresa (pruebas negativas: otra empresa no ve, no aprueba, no revierte).
5. Medición de uso (tenant_usage_records): registra por ejecución de demanda y de Customer los segundos de entrenamiento/inferencia que ya reportan los resultados del motor y las ejecuciones; bytes Bronze y de artefactos por empresa en un comando diario predictily:usage-snapshot; sin precios.
6. Exportación LOPDP de model_registry_events y tenant_usage_records; OpenAPI; suite completa del backend en verde; E2E sin editar.
7. Documentación con estados honestos: MODEL_REGISTRY.md (ciclo de vida, segregación, evidencia) y MODEL_DEPLOYMENT.md (promoción, rollback, qué se publica dónde; AWS BLOQUEADO).`,
    review: 'Checklist: transiciones explícitas y auditadas en la misma transacción; segregación real (aprobador ≠ publicador/entrenador) con prueba negativa; aprobar exige firma verificada y gate de datos; serving y rollback solo de lo aprobado con política explícita para legados; aislamiento entre empresas; medición de uso sin precios; suite completa en verde.',
  },
  {
    key: 'W4-B-engine-cli-serving',
    dbs: 'BD del motor: predictily_engine_test_w4b',
    owns: 'engine/predictily_engine/cli.py y un paquete nuevo engine/predictily_engine/mlops/** (comandos, empaquetado, publicación, comparación, diagnóstico), un módulo nuevo engine/predictily_engine/platform/object_store.py (local y S3 con emulador, misma convención de claves que BronzeStore de W2-B), la carga de artefactos en serving (solo para exigir estado approved/production; no toques la verificación de firma de signing.py), engine/tests/** nuevos, docs/ml/PRIVATE_TENANT_TRAINING.md y docs/operations/TENANT_TRAINING_RUNBOOK.md (nuevos), docs/ml/AUTOMATED_TRAINING.md (nuevo, diseño)',
    prompt: `Paquete W4-B · CLI de entrenamiento privado por empresa y serving solo de lo aprobado (ADR-V25-19, -08, -07; §9).
1. CLI predictily_engine (argparse existente) con: validate-data (gates de W2-C sobre la BD o un export autorizado), build-features, train --tenant (demanda y customer; CPU por defecto), backtest, tune (Optuna acotado de W3-B si está instalado), evaluate, package (artefacto + manifiesto firmado + versiones de librerías + huella de datos), sign, verify, publish --env (sube al almacén de objetos con clave del servidor tenants/<uuid>/models/<core>/<version>/... y registra candidate con published_by/published_env; NUNCA production), compare (dos versiones: métricas por horizonte y segmento de W3-A), rollback (pide al backend o deja la instrucción; nunca escribe production sin aprobación), diagnose (entorno, claves de confianza, versiones, conectividad a la BD sin imprimir secretos). Cada comando con --help en español y código de salida estable; documenta los comandos exactos.
2. Exportaciones locales: solo con autorización explícita (--authorized-export con referencia), minimizadas (pseudónimos), cifradas en reposo si salen del servidor y con limpieza posterior (comando y prueba).
3. platform/object_store.py: interfaz con controladores local y s3 (boto3 contra emulador moto etiquetado EMULADOR en la prueba si se puede instalar en un venv propio del scratchpad; si no, la prueba se omite con mensaje explícito y el controlador queda implemented_untested).
4. Serving: el motor carga solo artefactos de modelos approved o production con firma verificada; la clave de caché incluye empresa + versión + esquema de features; pruebas negativas entre empresas en registro, caché y jobs (un job de la empresa A nunca carga artefactos ni cachés de B).
5. Pruebas de todo lo anterior; suite completa del motor en verde (desde un directorio sin .env).
6. Documentación: PRIVATE_TENANT_TRAINING.md (flujo local autorizado → firma → publicación → aprobación separada), TENANT_TRAINING_RUNBOOK.md (pasos exactos del propietario) y AUTOMATED_TRAINING.md (DISEÑADO: programación y reentrenamiento con aprobación).`,
    review: 'Checklist: publish nunca deja production; claves del almacén generadas por el servidor con prefijo de la empresa; firma en package/publish y verificación antes de cargar; serving solo de approved/production; caché con empresa+versión+esquema; pruebas negativas entre empresas en registro, caché y jobs; exportación autorizada, minimizada y limpiada; diagnose sin secretos; suite completa en verde.',
  },
  {
    key: 'W4-C-aws-iac-costs-recovery',
    dbs: 'BD backend: predictily_test_w4c (solo para la prueba de restauración)',
    owns: 'infra/aws/** (nuevo: app CDK en TypeScript con su package.json propio), infra/scripts/backup.sh y un script nuevo de restauración (solo para probar la restauración local), docs/architecture/AWS_ARCHITECTURE.md, docs/architecture/AWS_COST_MODEL.md, docs/operations/AWS_DEPLOYMENT.md, docs/operations/RECOVERY.md (nuevos)',
    prompt: `Paquete W4-C · Infraestructura como código, costo por empresa y recuperación (ADR-V25-09; §14). Despliegue real: BLOQUEADO (sin cuenta AWS del propietario).
1. App CDK en TypeScript en infra/aws (npm install en esa carpeta: aws-cdk-lib, constructs, aws-cdk y cdk-nag; si la instalación no es posible, escribe el diseño equivalente y deja BLOQUEADO con el comando exacto): VPC privada, S3+CloudFront para la SPA, ECS Fargate (API, workers, motor), RDS PostgreSQL privado (Single-AZ inicial con RTO/RPO documentados y criterio para Multi-AZ), S3 versionado y cifrado con KMS (Bronze/Silver/Gold/artefactos, bloqueo de acceso público), SQS + DLQ, EventBridge Scheduler, Secrets Manager, CloudWatch (logs y alarmas) y AWS Budgets con alertas; sin GPU permanente; parámetros por entorno (staging/production). Verificación local sin credenciales: cdk synth y cdk-nag (AwsSolutions) sin errores o con supresiones justificadas una por una; pruebas de la plantilla con assertions de aws-cdk-lib (cifrado, sin buckets públicos, RDS no público, DLQ presente).
2. AWS_COST_MODEL.md: costo por empresa PARAMÉTRICO a partir de tenant_usage_records (W4-A) y de los recursos de la plantilla (fijos compartidos vs variables por empresa); fórmulas y una tabla de parámetros con su fuente (página pública de precios citada con fecha) o «a confirmar por el propietario»; ningún precio inventado; ejemplo numérico solo con parámetros declarados como supuestos.
3. RECOVERY.md: respaldo y restauración PROBADOS localmente (infra/scripts/backup.sh existente + script de restauración nuevo contra predictily_test_w4c: respaldar, restaurar en una base vacía y comprobar conteos), RTO/RPO objetivo y el procedimiento en AWS (snapshots RDS, versiones S3) como DISEÑADO.
4. AWS_ARCHITECTURE.md y AWS_DEPLOYMENT.md: arquitectura, aislamiento por empresa (prefijos S3, roles de tarea, secretos), despliegue paso a paso para el propietario (cuenta, presupuesto, rol de mínimo privilegio, dominio y certificados) con estados honestos.`,
    review: 'Checklist: cdk synth y cdk-nag reales (o BLOQUEADO con comando exacto); cifrado, sin acceso público, RDS privado, DLQ, budgets; pruebas de plantilla que afirman lo anterior; costo paramétrico sin precios inventados; restauración probada localmente con conteos; nada desplegado; documentos con estados honestos.',
  },
]

// Orquestación (igual que W1 y W2): implementación → revisión adversarial → corrección; A, B y C en paralelo.
