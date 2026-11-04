# Predictily V2.5 · Arquitectura Enterprise tal como está construida

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · Fuentes: V2.5 §0 («Decisiones funcionales reconciliadas»), §3–§17 y §21; ADR-V25-01 a ADR-V25-25
([`adr/V25-ADRS.md`](adr/V25-ADRS.md)). Visión previa a V2.5: [`docs/02-arquitectura.md`](../02-arquitectura.md). Despliegue objetivo en la nube:
[`AWS_ARCHITECTURE.md`](AWS_ARCHITECTURE.md) (**escrito y verificado por síntesis, NO desplegado**).

**Qué es este documento.** El mapa de lo que **existe hoy en el repositorio**: los cuatro cores, los servicios transversales, el flujo de datos Bronze → Silver → Gold, el
aislamiento entre empresas, dónde vive cada pieza y qué documento la detalla. No describe lo deseado como si existiera y **no certifica ningún gate**: el estado de salida lo
decide el arquitecto en [`docs/roadmap/V25_RELEASE_GATES.md`](../roadmap/V25_RELEASE_GATES.md) (a la fecha, ningún gate en GO).

**Leyenda de estado.** **IMPLEMENTADO / VERIFICADO** (código + pruebas en una suite en verde con evidencia de integración, la última la de W6 en
`docs/roadmap/evidence/2026-11-03-w6-integration/`; el documento enlazado cita las pruebas) · **DISEÑADO / NO IMPLEMENTADO** ·
**BLOQUEADO** (falta un recurso del propietario o de un tercero) · **NO APLICA**. Ninguna afirmación de este documento se apoya en datos reales de un cliente: todo lo verificado
lo está con datos ficticios o sintéticos.

## Contenido

- [1. Principios que rigen la arquitectura](#1-principios-que-rigen-la-arquitectura)
- [2. Vista general (local, hoy)](#2-vista-general-local-hoy)
- [3. Los cuatro cores](#3-los-cuatro-cores)
- [4. Servicios transversales](#4-servicios-transversales)
- [5. Flujo de datos Bronze → Silver → Gold](#5-flujo-de-datos-bronze--silver--gold)
- [6. Tiempo: la regla as-of única](#6-tiempo-la-regla-as-of-única)
- [7. Tenancy y aislamiento](#7-tenancy-y-aislamiento)
- [8. Local hoy frente a AWS](#8-local-hoy-frente-a-aws)
- [9. Contratos y APIs](#9-contratos-y-apis)
- [10. Lo que la arquitectura NO hace](#10-lo-que-la-arquitectura-no-hace)
- [11. Cómo verificarlo](#11-cómo-verificarlo)
- [12. Tabla final por módulo](#12-tabla-final-por-módulo)

## 1. Principios que rigen la arquitectura

| Principio | Fuente | Estado |
|---|---|---|
| **Cuatro cores** (Data, Demand, Customer, Supply) y **servicios transversales** (Decision Engine, workflow de aprobación, outcomes, entrenamiento y registro, gateway, Bridge); el Radar es una **vista**, no un quinto core | V2.5 §0; ADR-V25-01 | IMPLEMENTADO / VERIFICADO (estructura del código, §3–§4) |
| **Evolución no destructiva**: migraciones aditivas, contratos compatibles, módulos heredados conservados (también los P3) | V2.5 §0.4; ADR-V25-01 | IMPLEMENTADO / VERIFICADO (`test_sector_contracts.py::test_the_sector_extension_contract_is_preserved`, OpenAPI sin rutas fuera de contrato) |
| **El ERP/POS/CRM sigue siendo la fuente de verdad**; Predictily lee en solo lectura y recomienda; no factura, no compra, no escribe en el ERP | V2.5 §0; ADR-V25-10 | IMPLEMENTADO / VERIFICADO (guardia SELECT `SqlQueryGuard` y su paridad en el Bridge; acciones solo registradas, nunca ejecutadas) |
| **Temporalidad y aislamiento antes que tableros o LLM** | V2.5 §23.3 | IMPLEMENTADO / VERIFICADO (W1 cerró las fugas temporales de ventas; W6-A amplía el aislamiento) |
| **El camino predictivo base no depende de un LLM ni de APIs externas de pago** | V2.5 §1 «No objetivos»; §24 | IMPLEMENTADO / VERIFICADO (`CoresWorkWithoutAssistantTest::test_the_four_cores_work_when_the_assistant_has_no_key`) |
| **Entrenamiento local y privado por empresa; publicación firmada; serving solo de lo aprobado**; sin GPU permanente | V2.5 §0, §9; ADR-V25-07, -19, -20 | IMPLEMENTADO / VERIFICADO en local; publicación e inferencia en AWS **BLOQUEADO** |
| **Ningún número inventado**: lo desconocido es nulo o `insufficient_data`, nunca cero | V2.5 §1, §25.1; ADR-V25-02, -03 | IMPLEMENTADO / VERIFICADO (ver `docs/data/TEMPORAL_AVAILABILITY.md`, `docs/ml/FORECAST_VALIDATION.md`) |

## 2. Vista general (local, hoy)

```
                 navegador (SPA Vue 3 · frontend/)
                          │ HTTPS/JSON  (/api/v1, Sanctum; X-Tenant-ID)
                          ▼
   ┌───────────────── backend/ (Laravel 13 · PHP 8.3) ─────────────────────────────┐
   │ API REST · auth (MFA, tokens con alcance) · RBAC · tenancy (fail-closed)       │
   │ Data Core: importación, calidad, cuarentena, Bronze, reconciliación, mapeo     │
   │ Radar/Agenda · Decision Engine · Outcomes/ROI · Registro de modelos           │
   │ Gateway (asistente) · endpoints del Bridge · exportación/borrado LOPDP         │
   │ colas Redis: imports · forecasting · default      programador (cron)          │
   └──────┬──────────────────────────┬────────────────────────────┬─────────────────┘
          │ SQL (PostgreSQL)         │ HTTP interno (token)       │ almacén de objetos
          ▼                          ▼                            ▼
   PostgreSQL (Silver, registro,   engine/ (FastAPI · Python 3.12)   disco local
   auditoría, resultados)          Demand · Customer · Supply ·       (S3: solo emulador;
          ▲                        factores · MLOps (CLI privada)      AWS real BLOQUEADO)
          │ solo lectura                           ▲
   ERP/BD del cliente  ◄──── bridge/ (agente Python, en la red del cliente) ──► POST /bridge/* (firma por dispositivo)
   (fuente de verdad)        o conector SQL del backend (PostgreSQL probado; MySQL/SQL Server sin servidor de prueba)
```

| Proceso | Dónde | Puerto local (desarrollo) | Notas |
|---|---|---|---|
| SPA | `frontend/` (Vue 3, Vite, Pinia) | 5173 | vistas operativas y Enterprise (W5-E) |
| API | `backend/` (Laravel) | 8000 | toda ruta de `/api/v1` exige autenticación salvo una lista revisada (`TenantIsolationMatrixTest`) |
| Workers | `php artisan queue:work redis --queue=imports,forecasting,default` | — | cada trabajo lleva su empresa y restablece el contexto |
| Programador | `backend/routes/console.php` | — | evaluación contra reales, pronósticos, sincronización SQL, Customer, Radar, decisiones, purgas |
| Motor | `engine/` (FastAPI + CLI `python -m predictily_engine.cli`) | 8100 | solo accesible desde la API y los workers |
| Base de datos | PostgreSQL 15/16 | 5432 | sesión en UTC (`d13e44f`) |
| Agente Bridge | `bridge/` (Python 3.12, dependencia única `cryptography`) | — | se ejecuta en la red del cliente; instalador Windows **BLOQUEADO** |

`docker-compose.yml` declara el mismo conjunto (postgres, redis, backend, queue, queue-forecasting, scheduler, engine, web). El entorno de trabajo vigente es macOS con
Homebrew (ver `V25-ESTADO-Y-PROXIMOS-PASOS.md` §6).

## 3. Los cuatro cores

| Core | Responsabilidad | Backend (`backend/app/…`) | Motor (`engine/predictily_engine/…`) | Documentos | Estado |
|---|---|---|---|---|---|
| **Data Core** | fuentes, ingestión, calidad, cuarentena, Bronze, Silver con linaje, reconciliación, identidades y alias, unidades, cobertura, disponibilidad temporal, contratos de features, taxonomía y factores (Factor Intelligence vive **dentro** de Data Core) | `Services/{Imports,Sales,Catalog,DataCore,Connectors,Intelligence,Promotions}`, `Support/Canonical` | `data/` (lectura as-of), `availability/`, `quality/`, `profiling/`, `features/`, `calendar/` | [`BRONZE_SILVER_GOLD.md`](../data/BRONZE_SILVER_GOLD.md), [`CANONICAL_CONTRACTS.md`](../data/CANONICAL_CONTRACTS.md), [`DATA_QUALITY_ENGINE.md`](../data/DATA_QUALITY_ENGINE.md), [`TEMPORAL_AVAILABILITY.md`](../data/TEMPORAL_AVAILABILITY.md), [`LINEAGE_AND_RECONCILIATION.md`](../data/LINEAGE_AND_RECONCILIATION.md), [`FEATURE_CONTRACTS.md`](../data/FEATURE_CONTRACTS.md), [`FACTOR_INTELLIGENCE.md`](../intelligence/FACTOR_INTELLIGENCE.md), [`PRODUCT_TAXONOMY.md`](../intelligence/PRODUCT_TAXONOMY.md) | IMPLEMENTADO / VERIFICADO con datos ficticios; conciliación con un ERP real **BLOQUEADO** (fuentes del piloto sin registrar) |
| **Demand Core** | pronóstico agregado por sucursal × producto con jerarquía, baselines, backtest rolling-origin as-of, período final reservado, selección (política heredada y V2.5), shadow, intervalos, métricas por horizonte y segmento, evaluación contra reales | `Services/{Forecasting,Analytics}` | `pipeline/`, `demand/`, `backtest/`, `selection/`, `models/`, `intervals/`, `reconciliation/`, `evaluation/` | [`FORECAST_VALIDATION.md`](../ml/FORECAST_VALIDATION.md), [`FORECAST_SELECTION.md`](../ml/FORECAST_SELECTION.md), [`POINT_IN_TIME_VALIDATION.md`](../ml/POINT_IN_TIME_VALIDATION.md), [`FEATURE_SELECTION.md`](../ml/FEATURE_SELECTION.md) | IMPLEMENTADO / VERIFICADO con la demo ficticia; precisión con datos reales **BLOQUEADO** |
| **Customer Core** | por comprador × producto × horizonte: `P(≥1 pedido)`, nº de pedidos (hurdle), cantidad por pedido, unidades, ventana P10/P50/P90 solo si se valida, ranking, inactividad, calibración por segmento; degradación a agregado si los IDs no son confiables | `Services/Customer` | `customer/` | [`CUSTOMER_REPURCHASE.md`](../ml/CUSTOMER_REPURCHASE.md), [`CALIBRATION_AND_RANKING.md`](../ml/CALIBRATION_AND_RANKING.md) | IMPLEMENTADO / VERIFICADO con datos sintéticos; en la demo la ventana queda `not_validated` (no publica cifras) |
| **Supply Core** | posición de inventario, reservas, tránsito, compromisos sin doble conteo, lead time, MOQ y múltiplos, presupuesto, perecibilidad y FEFO, plan con MILP y respaldo heurístico verificado, simulación as-of, escenarios y costos | `Services/{Supply,Scenarios}` | `supply/`, `inventory/`, `costs/`, `scenarios/`, `causal/` | [`SUPPLY_CORE.md`](../supply/SUPPLY_CORE.md) | IMPLEMENTADO / VERIFICADO con datos sintéticos; el motor **sugiere**, no emite compras |

Los cores son independientes: un core no escribe en las tablas de otro; se comunican por contratos (lectura de Silver as-of, corridas registradas con su huella, la
`RecommendationSink` del Decision Engine). La predicción vive solo en Demand y Customer; los servicios transversales **no** vuelven a predecir.

## 4. Servicios transversales

| Servicio | Qué hace | Dónde | Documento | Estado |
|---|---|---|---|---|
| **Revenue Radar y Agenda Comercial** (vista) | fachadas sobre Customer Core y el Decision Engine con los contratos V2.2 (`/radar/summary`, `/opportunities`, `/agenda`, feedback auditable, seis estados heredados mapeados al workflow) | `backend/app/Services/Radar/` | [`LEGACY_API_COMPATIBILITY.md`](../product/LEGACY_API_COMPATIBILITY.md), [`ECONOMIC_PRIORITIZATION.md`](../decision/ECONOMIC_PRIORITIZATION.md) | IMPLEMENTADO / VERIFICADO (W5-A) |
| **Decision Engine** (`PredictilyDecisionEngine`) y workflow | recomendación con fórmula versionada y explicación verificable; máquina de estados cerrada con segregación de funciones, `expected_version` e idempotencia; grupo de control 0 por defecto | `backend/app/Services/Decision/` | [`DECISION_ENGINE.md`](../decision/DECISION_ENGINE.md), [`CONTROLLED_ACTIONS.md`](../decision/CONTROLLED_ACTIONS.md) | IMPLEMENTADO / VERIFICADO (W5-B) |
| **Outcomes y ROI** (`PredictilyOutcomeMeasurementService`) | medición as-of de toda recomendación; ITT con control; contribución (no ingresos brutos); ROI `potential_unproven` sin control y **nunca** «probado» | `backend/app/Services/Outcomes/` | [`OUTCOME_MEASUREMENT.md`](../decision/OUTCOME_MEASUREMENT.md) | IMPLEMENTADO / VERIFICADO con datos sintéticos (W5-C); outcomes reales **BLOQUEADO** |
| **MLOps** (entrenamiento privado, registro, serving) | CLI privada por empresa, artefactos firmados Ed25519, estados con aprobación separada, serving solo de lo aprobado o del pipeline propio, reversión auditada, medición de uso | `engine/predictily_engine/mlops/`, `backend/app/Services/Models/` | [`PRIVATE_TENANT_TRAINING.md`](../ml/PRIVATE_TENANT_TRAINING.md), [`MODEL_REGISTRY.md`](../ml/MODEL_REGISTRY.md), [`MODEL_DEPLOYMENT.md`](../ml/MODEL_DEPLOYMENT.md), [`AUTOMATED_TRAINING.md`](../ml/AUTOMATED_TRAINING.md), [`TENANT_TRAINING_RUNBOOK.md`](../operations/TENANT_TRAINING_RUNBOOK.md) | IMPLEMENTADO / VERIFICADO en local (W4); reentrenamiento automático **DISEÑADO**; AWS **BLOQUEADO** |
| **Pipeline de factores** | activación por empresa (dos condiciones), resolución as-of entrenada como se sirve, competencia contra el modelo sin factores y contra placebos, selección hacia delante, ablation de solo lectura | `engine/predictily_engine/{availability,backtest/factor_arm.py,backtest/factor_ablation.py}`, `backend/app/Services/Intelligence/` | [`FACTOR_PIPELINE.md`](../intelligence/FACTOR_PIPELINE.md), [`EXTERNAL_SOURCES.md`](../intelligence/EXTERNAL_SOURCES.md), [`SECTOR_PROFILES.md`](../intelligence/SECTOR_PROFILES.md) | IMPLEMENTADO / VERIFICADO (W6-E); apagado por defecto; sin fuentes externas conectadas (**BLOQUEADO / P2**) |
| **Seguridad Enterprise** | MFA TOTP con política por empresa, segundo factor fresco para habilidades sensibles, tokens con alcance y caducidad, revocación inmediata, roles comerciales con alcance por cartera, RLS experimental | `backend/app/Services/{Auth,Tenancy}/`, `backend/config/security.php` | [`ENTERPRISE_SECURITY.md`](../security/ENTERPRISE_SECURITY.md), [`TENANT_ISOLATION.md`](TENANT_ISOLATION.md) | IMPLEMENTADO / VERIFICADO (W6-A; también de punta a punta en el E2E V2.5); RLS **apagada y no apta para producción** |
| **Predictily Bridge** | agente de solo lectura en la red del cliente: registro de dispositivo con firma, extracción incremental con marca de agua acotada, checkpoints confirmados por el servidor, búfer cifrado, subida a Bronze por URL ligada a empresa y TTL | `bridge/`, `backend/app/Services/Bridge/` | [`BRIDGE_WINDOWS.md`](../integrations/BRIDGE_WINDOWS.md), [`CONNECTOR_FRAMEWORK.md`](../integrations/CONNECTOR_FRAMEWORK.md), [`ERP_COMPATIBILITY.md`](../integrations/ERP_COMPATIBILITY.md) | IMPLEMENTADO / VERIFICADO (W6-B; agente: 344 pruebas; registro y latido firmado en el E2E V2.5); MySQL y SQL Server sin servidor de prueba; instalador y firma de código Windows **BLOQUEADO** |
| **Auto Mapper** | descubrimiento de esquema de solo lectura, propuesta determinista con puntaje heurístico (no una probabilidad), aprobación como habilidad separada, plantillas versionadas con huella, deriva de esquema | `backend/app/Services/DataCore/Mapping/` | [`AUTO_MAPPING.md`](../integrations/AUTO_MAPPING.md), [`ENTERPRISE_ONBOARDING.md`](../integrations/ENTERPRISE_ONBOARDING.md) | IMPLEMENTADO / VERIFICADO (W6-C) con esquemas ficticios; no probado contra ERPs reales |
| **Intelligence Gateway** (`PredictilyIntelligenceGateway`) | asistente con herramientas tipadas y autorización por herramienta, cifras solo de servicios de negocio, auditoría de solo inserción, saneado estructural ante inyección; canal WhatsApp **solo contrato** | `backend/app/Services/Conversation/` | [`INTELLIGENCE_GATEWAY.md`](INTELLIGENCE_GATEWAY.md) | IMPLEMENTADO / VERIFICADO (W6-D) sin modelo real; proveedor LLM y WhatsApp **BLOQUEADO**; consentimiento por empresa (V25-SEC-040) **DISEÑADO** |
| **Planes comerciales** | Básico (intacto), Starter, Growth y Enterprise configurables por empresa; un precio nunca es un límite | `backend/app/Services/Plans/`, `backend/config/predictily.php` | [`RETAIL_STARTER_GROWTH.md`](../product/RETAIL_STARTER_GROWTH.md), [`RETAIL_MVP_ACCEPTANCE.md`](../product/RETAIL_MVP_ACCEPTANCE.md) | IMPLEMENTADO / VERIFICADO (W2-E) |
| **Privacidad y LOPDP** | exportación completa por empresa (ZIP firmado), eliminación diferida, retención, consentimiento de entrenamiento compartido (apagado por defecto) | `backend/app/Services/{Privacy,Exports}/` | [`docs/07-seguridad-multitenant.md`](../07-seguridad-multitenant.md) §8 | IMPLEMENTADO / VERIFICADO; revisión legal **BLOQUEADO** (propietario) |

## 5. Flujo de datos Bronze → Silver → Gold

No hay *lakehouse*: las capas se asignan a lo que ya existe (ADR-V25-04, ADR-V25-24).

```
 origen autorizado ──► Bronze ───────────────────► Silver ─────────────────────────► Gold (efímero) ─────► resultados
 (archivo, SQL,        bronze_objects + objeto       tablas canónicas con linaje      dataset que el motor    forecast_runs, model_evaluations,
  lote REST, Bridge)   inmutable con SHA-256,        (bronze_object_id, source_row,   arma en cada corrida    customer_predictions, supply_plans,
                       procedencia, retención         schema_version); ventas con     (series, cliente-       recommendations, decision_*,
                       (local · S3 emulador)          versiones y ventana de          producto, features      outcomes; data_snapshots guarda
                                                      conocimiento; cuarentena        as-of)                   la huella y los conteos
                     └──────── reconciliación por carga y por ventana (fuente · Bronze · Silver · Gold) ────────┘
```

| Capa | Qué es | Estado |
|---|---|---|
| Bronze | el original autorizado e inmutable antes de normalizar; clave generada por el servidor `tenants/<uuid>/bronze/<entidad>/<aaaa>/<mm>/<sha256>.<ext>`; verificación al leer; purga auditada | IMPLEMENTADO / VERIFICADO (disco local y emulador S3); S3 real **BLOQUEADO** |
| Silver | las tablas del backend **son** el modelo canónico (31 entidades de §3 con versión y huella de columnas); `sales_line_versions` es la fuente única de lectura as-of de ventas | IMPLEMENTADO / VERIFICADO; inventario, catálogo y costos **sin versiones** as-of (DISEÑADO) |
| Gold | no es un almacén persistido: se guarda su identidad (`data_snapshots`: hash, componentes, conteos, cobertura) enlazada desde cada corrida | huella IMPLEMENTADO / VERIFICADO; datasets Gold persistidos y versionados **DISEÑADO / NO IMPLEMENTADO** |
| Reconciliación | `php artisan predictily:reconcile <tenant_uuid> --entity=sales --from=… --to=…` y `GET /api/v1/lineage/reconciliation`: nunca `balanced` sin comparar todas las capas | IMPLEMENTADO / VERIFICADO; los totales de «origen» son los calculados sobre lo leído, **no** totales de control ejecutados en el ERP (DISEÑADO); con un ERP real **BLOQUEADO** |
| Calidad antes de entrenar | gates PASS/WARN/DEGRADE/BLOCK por módulo y empresa consultados por demanda, Customer y abastecimiento antes de entrenar o inferir | IMPLEMENTADO / VERIFICADO (W2-C); umbrales **a aprobar en el piloto** |

## 6. Tiempo: la regla as-of única

Un hecho entra en el corte `C` solo si su disponibilidad es anterior a la medianoche local de `C+1` en la zona de la empresa (ADR-V25-23, ADR-V25-06). La misma regla
gobierna las ventas versionadas (W1), las ventanas del backtest, el Customer Core, los factores (`availability/visibility.py`), la simulación de abastecimiento (W5-D) y la
medición de outcomes (W5-C). Días sin transacción: `zero_confirmed`, `missing_source`, `stockout_censored` o `closed`; solo el primero es un cero (ADR-V25-03). Detalle y
pruebas: [`TEMPORAL_AVAILABILITY.md`](../data/TEMPORAL_AVAILABILITY.md) y [`POINT_IN_TIME_VALIDATION.md`](../ml/POINT_IN_TIME_VALIDATION.md). Excepciones heredadas declaradas:
`load_calendar_events` corta en fin del día UTC y las promociones interpretan su `known_at` sin zona como hora local (V25-AVL-014, DISEÑADO unificarlas).

## 7. Tenancy y aislamiento

Modelo: **base compartida con `tenant_id` en cada tabla de empresa**, defensa en profundidad y fallo cerrado. Detalle con la prueba de cada capa en
[`TENANT_ISOLATION.md`](TENANT_ISOLATION.md).

| Capa | Mecanismo | Estado |
|---|---|---|
| Resolución de empresa | `ResolveTenant` (membresía exigida; un token de integración está ligado a SU empresa e ignora el encabezado) | IMPLEMENTADO / VERIFICADO |
| Consultas | global scope `BelongsToTenant` que **lanza** sin contexto de empresa | IMPLEMENTADO / VERIFICADO |
| Base de datos | FKs compuestas `(tenant_id, x_id)`; `ON DELETE SET NULL` nunca anula `tenant_id` | IMPLEMENTADO / VERIFICADO |
| Autorización | `tenant.ability:<habilidad>` en cada ruta; matriz rutas × roles × empresa generada del router | IMPLEMENTADO / VERIFICADO (matriz de W6-A) |
| Colas, caché, almacén | trabajo con empresa y middleware que restaura el contexto; claves de caché con empresa; claves de objeto con prefijo de empresa generadas por el servidor | IMPLEMENTADO / VERIFICADO (W4) y ampliado en W6-A (inventario de todos los trabajos y usos de caché) |
| Motor | toda lectura y escritura filtra por el `tenant_id` recibido; artefactos confinados a `<ENGINE_ARTIFACTS_DIR>/<tenant_id>/`; firma ligada a la empresa | IMPLEMENTADO / VERIFICADO |
| Modelos | global dentro de un mismo tenant; nunca se combinan datos privados entre empresas (V2.5 §27); entrenamiento compartido solo con consentimiento expreso, apagado por defecto | IMPLEMENTADO / VERIFICADO |
| RLS de PostgreSQL | defensa adicional en 4 tablas de ventas detrás de `PREDICTILY_RLS_ENABLED` | **EXPERIMENTAL, APAGADA**; tres bloqueos antes de producción (motor sin RLS, cuenta de mínimo privilegio, *pooler*) |
| Concurrencia entre procesos | trabajos de A y B en paralelo en procesos distintos | **DISEÑADO / NO VERIFICADO** |
| Contratos entre empresas (`SharedForecastGrant`) | contrato, pruebas negativas y bitácora de accesos detrás de `PREDICTILY_SHARED_FORECAST_GRANTS_ENABLED=false` | IMPLEMENTADO / VERIFICADO (W2-F); red colaborativa **no habilitada** (P3) |

## 8. Local hoy frente a AWS

| Pieza | Local (hoy) | AWS (objetivo, [`AWS_ARCHITECTURE.md`](AWS_ARCHITECTURE.md)) | Estado AWS |
|---|---|---|---|
| SPA | Vite (`npm run dev`) | S3 privado + CloudFront (WAF, TLS 1.2+) | escrito en CDK, verificado por síntesis; **BLOQUEADO** |
| API y workers | `php artisan serve`, `queue:work` sobre Redis | ECS Fargate detrás de ALB; workers sobre SQS con DLQ cifrada | **BLOQUEADO** |
| Motor | uvicorn en `127.0.0.1:8100` | tarea Fargate interna (Cloud Map), sin GPU | **BLOQUEADO** |
| Programación | `routes/console.php` + cron | EventBridge Scheduler con tareas efímeras | **BLOQUEADO** |
| Base de datos | PostgreSQL local | RDS PostgreSQL 16 Single-AZ cifrado, no público (criterio para Multi-AZ en ADR-V25-09) | **BLOQUEADO** |
| Almacén de objetos | disco local; S3 contra emulador (moto) | S3 versionado y cifrado con prefijo por empresa | **BLOQUEADO** (sin IAM por prefijo ni KMS reales) |
| Secretos y firma | `.env` no versionado; clave Ed25519 local 0600 | Secrets Manager + KMS | **BLOQUEADO** |
| Observabilidad | logs locales; alertas de negocio en `alerts` | CloudWatch (logs cifrados, alarmas, DLQ), AWS Budgets | alarmas y presupuesto en plantillas CDK probadas; **BLOQUEADO** |
| Respaldo y restauración | `infra/scripts/backup.sh`, `restore.sh`, `recovery-check.sh cycle` (probado en local) | snapshots de RDS, versionado S3 | local IMPLEMENTADO / VERIFICADO; AWS **BLOQUEADO** |
| Costo | `tenant_usage_records` (uso medido por empresa, sin precios) | modelo paramétrico de [`AWS_COST_MODEL.md`](AWS_COST_MODEL.md) | **sin factura real**: ningún costo de AWS medido |

La IaC (`infra/aws`, AWS CDK v2 en TypeScript) se verifica con `npm run verify` (compilación, pruebas de plantilla y `cdk synth` con `cdk-nag`) usando datos **ficticios**;
una plantilla así **no es desplegable**. La región (hoy `us-east-1`), la cuenta, el presupuesto y los dominios son decisiones del propietario
([`docs/operations/OWNER_RESPONSIBILITIES.md`](../operations/OWNER_RESPONSIBILITIES.md)).

## 9. Contratos y APIs

| Contrato | Dónde | Garantía |
|---|---|---|
| API pública | `docs/openapi/{core,analytics,v2,predictily-api}.yaml` (combinados con `infra/scripts/merge_openapi.py`) | `OpenApiRoutesTest::test_every_real_api_route_is_documented_and_nothing_extra_is_documented` |
| API interna del motor | `docs/openapi/engine-api.json` | token del motor; `tenant_id` autorizado por el backend |
| Catálogo de horizontes | `docs/product/horizons.contract.json` (7/14/15/30/60/90; Customer 7/14/15/30/90) | backend y motor se comparan contra el archivo sin depender del otro entorno |
| Lote del Bridge | `docs/integrations/CONNECTOR_FRAMEWORK.md` §4.1 | vectores de firma compartidos PHP ↔ Python (`BridgeSignatureVectorsTest`, `test_signature_vectors.py`) |
| Guardia SQL | `backend/app/Services/Connectors/Sql/SqlQueryGuard.php` y `bridge/src/predictily_bridge/sqlguard.py` | corpus de paridad generado con el guardia PHP (`SqlGuardParityFixtureTest`, `test_sqlguard_parity.py`) |
| Recomendación | contrato de §11 en `DECISION_ENGINE.md` | `DecisionEngineProposalTest::test_a_proposal_gets_every_field_of_the_section_11_contract` |

## 10. Lo que la arquitectura NO hace

- No escribe en el ERP, no emite órdenes de compra, no cambia precios ni envía mensajes por su cuenta (V2.5 §1 «No objetivos»; acciones solo registradas tras aprobación).
- No tiene un segundo almacén de features ni un segundo gateway de LLM (ADR-V25-25, ADR-V25-17).
- No depende de Chronos, TimesFM, TimeGPT ni de APIs de pago en el camino base.
- No combina datos privados entre empresas; los modelos «globales» lo son dentro de un tenant.
- No mantiene GPU ni EC2 permanentes en el diseño AWS.
- No declara precisión ni ROI: la capacidad técnica de completar el flujo no es evidencia de precisión ni de beneficio (V2.5 §18).

## 11. Cómo verificarlo

```bash
# Suites (desde la raíz; herméticas frente a los .env de desarrollo)
cd backend && DB_DATABASE=predictily_test TEST_ERP_DATABASE=<bd_erp_de_pruebas> php artisan test
cd engine && ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<bd_de_pruebas> .venv/bin/python -m pytest -q
cd frontend && npm run type-check && npm run lint && npm run test -- --run && npm run build
cd bridge && .venv/bin/python -m pytest -q
cd infra/aws && npm run verify                      # síntesis y cdk-nag con datos ficticios; no despliega
# E2E (pila levantada)
engine/.venv/bin/python e2e/mvp_acceptance.py --api http://127.0.0.1:8000/api/v1
engine/.venv/bin/python e2e/stage2_acceptance.py --api http://127.0.0.1:8000/api/v1
engine/.venv/bin/python e2e/v25_acceptance.py --api http://127.0.0.1:8000/api/v1 --engine-dir engine   # E2E V2.5 (W6-F)
# Documentos generados de la hoja de ruta
python3 infra/scripts/v25_roadmap_docs.py --check
```

Última evidencia de integración: `docs/roadmap/evidence/2026-11-03-w6-integration/` (stack en `aa82db1`: backend 2583 pruebas, 2574 aprobadas y 9 omitidas; motor 1496
aprobadas y 10 omitidas; frontend 749; agente Bridge 344; E2E 32/32, 256/256 con 1 aviso de diseño y **E2E V2.5 160/160** con 3 avisos: outcome con la ventana abierta, asistente sin
clave de proveedor y AWS BLOQUEADO). El E2E V2.5 se ejecuta con `engine/.venv/bin/python e2e/v25_acceptance.py --api http://127.0.0.1:8000/api/v1 --engine-dir engine` (backend, cola y motor activos; crea una base «ERP de origen» ficticia local; datos ficticios).

## 12. Tabla final por módulo

| Módulo | Evidencia | Estado de pruebas | Costo | Riesgo | Aprobación |
|---|---|---|---|---|---|
| Data Core | `docs/data/*.md`; `evidence/2026-11-01-w2-integration/` | suites en verde; datos ficticios | cómputo local; almacenamiento Bronze medido por empresa (`predictily:usage-snapshot`) | conciliación con un ERP real no ejecutada; inventario y catálogo sin versiones | umbrales de calidad: propietario en el piloto |
| Demand Core | `FORECAST_VALIDATION.md`, `FORECAST_SELECTION.md`; demo W6 (idéntica a W5) | en verde; métricas solo de la demo ficticia | CPU local; sin APIs de pago | sin evidencia con datos reales | política que gobierna lo servido: propietario |
| Customer Core | `CUSTOMER_REPURCHASE.md` | en verde; sintético | CPU local | ventana sin validar con datos reales | umbrales: propietario en el piloto |
| Supply Core | `SUPPLY_CORE.md` | en verde; sintético | CPU local (MILP con tope de tiempo) | costos y precios no confiables dejan el margen desconocido | parámetros de perecibilidad: propietario con el cliente |
| Radar, Decision Engine, Outcomes | `docs/decision/*.md`; `evidence/2026-11-03-w5-integration/` y `-w6-integration/` | en verde; sintético | local | ROI real BLOQUEADO; nunca «probado» sin control | aprobaciones con segregación de funciones; fracción de control: propietario y cliente |
| MLOps | `MODEL_REGISTRY.md`, `PRIVATE_TENANT_TRAINING.md`; `evidence/2026-11-02-w4-integration/` | en verde en local | local; AWS paramétrico sin factura | publicación e inferencia en AWS BLOQUEADAS; formato con pickle (firmado) | modelos: persona distinta de quien entrena o publica |
| Seguridad y aislamiento | `ENTERPRISE_SECURITY.md`, `TENANT_ISOLATION.md` | W1-B y W6-A en verde; E2E V2.5 (MFA, tokens, vendedor por cartera, cambio de empresa) | — | RLS apagada; concurrencia entre procesos no verificada | MFA obligatorio y RLS: propietario |
| Bridge y Auto Mapper | `BRIDGE_WINDOWS.md`, `AUTO_MAPPING.md` | en verde en la integración de W6 (agente: 344 pruebas) con datos ficticios | se ejecuta en el equipo del cliente | Windows BLOQUEADO; MySQL/SQL Server sin servidor | mapeos: propietario (aprobación separada) |
| Gateway | `INTELLIGENCE_GATEWAY.md` | en verde en la integración de W6; sin modelo real (asistente desactivado sin clave) | LLM de terceros solo con clave del operador (sin costo hoy: desactivado) | envío de resultados a un tercero sin consentimiento por empresa (V25-SEC-040) | consentimiento LLM y WhatsApp: propietario |
| Infraestructura AWS | `AWS_ARCHITECTURE.md`, `AWS_DEPLOYMENT.md`, `AWS_COST_MODEL.md` | síntesis y pruebas de plantilla en verde; nada desplegado | **sin medir** | todo **BLOQUEADO** hasta la cuenta del propietario | cuenta, región, presupuesto: propietario |
