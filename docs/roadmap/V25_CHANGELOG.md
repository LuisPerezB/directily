# Predictily V2.5 · Registro de cambios: lo hecho y lo pendiente

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` (creada desde `main` @ `6c679d8`) · Fuente: V2.5 §23 paso 7 («entrega un changelog de lo
**hecho** y de lo **pendiente**, no de lo deseado») y §28. Commits: `git log --oneline main..HEAD` (56 commits al redactar, de `d13e44f` a `7eea3bf`).
Evidencia de cada integración: `docs/roadmap/evidence/<fecha>-w<n>-integration/SUMMARY.txt`. Auditoría por oleada: `docs/roadmap/audit/progress/*.json`.

**Qué es y qué no es.** Este registro dice qué se confirmó en la rama, con qué commit y con qué evidencia, y qué queda pendiente. **No decide ningún gate**: el
estado GO / GO CONDICIONAL / NO-GO / BLOQUEADO lo fija el arquitecto en [`V25_RELEASE_GATES.md`](V25_RELEASE_GATES.md). A la fecha de este registro **ningún gate está en
GO** y **ninguna cifra procede de datos reales de un cliente**: todas las métricas citadas son de la demo ficticia o de datos sintéticos.

**Leyenda.** **IMPLEMENTADO / VERIFICADO** (código + pruebas en una suite en verde, con evidencia de integración) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** (depende del
propietario o de un tercero) · **NO APLICA**.

## Contenido

- [1. Resumen por oleada](#1-resumen-por-oleada)
- [2. W0 · inventario y contratos (en `main`)](#2-w0--inventario-y-contratos-en-main)
- [3. W1 · correcciones P0 de temporalidad y seguridad](#3-w1--correcciones-p0-de-temporalidad-y-seguridad)
- [4. W2 · Data Core P0](#4-w2--data-core-p0)
- [5. W3 · Calidad ML P0](#5-w3--calidad-ml-p0)
- [6. W4 · MLOps y AWS P0](#6-w4--mlops-y-aws-p0)
- [7. W5 · flujo empresarial P1](#7-w5--flujo-empresarial-p1)
- [8. W6 · plataforma, integración y cierre](#8-w6--plataforma-integración-y-cierre)
- [9. Cambios transversales](#9-cambios-transversales)
- [10. Pendiente](#10-pendiente)
- [11. Tabla final por módulo](#11-tabla-final-por-módulo)

## 1. Resumen por oleada

| Oleada | Alcance (plan §3) | Commits de paquete | Integración (suites en el stack de la oleada) | Estado |
|---|---|---|---|---|
| W1 | 13 defectos HIGH de la auditoría (temporalidad de ventas, lectura as-of, SSRF, ZIP bomb, firma de artefactos) | `11c7086`, `c44076e`, `eea5321` | backend 739 (736 aprobadas, 3 omitidas) · motor 550 · frontend 313 · E2E 32/32 y 256/256 | IMPLEMENTADO / VERIFICADO (7 HIGH corregidos, 6 corregidos parcialmente) |
| W2 | Data Core P0 (S01–S03): calidad, cuarentena, Bronze, reconciliación, identidades, disponibilidad temporal, horizontes, planes | `e484c43`, `43a0873`, `8ed0faa`, `8a69363`, `df6531e`, `d7d468c` | backend 1090 (1082 aprobadas, 8 omitidas) · motor 780 · frontend 329 · E2E 32/32 y 256/256 | IMPLEMENTADO / VERIFICADO |
| W3 | Calidad ML P0 (S04–S05): métricas por horizonte y segmento, políticas de promoción, shadow, Optuna acotado, Customer S05 | `a656521`, `562f2b0`, `552f6c0`, `7586c57`, `ef5295f` | backend 1217 (1209 aprobadas, 8 omitidas) · motor 1071 · frontend 389 · E2E 32/32 y 256/256 | IMPLEMENTADO / VERIFICADO |
| W4 | MLOps y AWS P0 (S06, S13): registro con aprobación separada, CLI privada, almacén de objetos, IaC, costos, recuperación | `c77deab`, `bc54d9c`, `53400d4` | backend 1219 (1210 aprobadas, 9 omitidas) · motor 1327 · frontend 389 · E2E 32/32 y 256/256 | IMPLEMENTADO / VERIFICADO; despliegue AWS **BLOQUEADO** |
| W5 | Flujo empresarial P1 (S07–S10, S14): Radar y Agenda, Decision Engine, Outcomes/ROI, Supply S10, vistas Enterprise | `f18541b`, `6fb4819`, `cb31a03`, `2d1a0b1` | backend 1640 (1631 aprobadas, 9 omitidas) · motor 1413 (1 omitida) · frontend 737 · E2E 32/32 y 256/256 | IMPLEMENTADO / VERIFICADO; outcomes y ROI reales **BLOQUEADOS** |
| W6 | Plataforma y cierre (S11, S12, S15, S16, S18, S17) | `778622d`, `6b1bd89`, `e7fbc37`, `5c51090`, `aa82db1` | backend 2583 (2574 aprobadas, 9 omitidas) · motor 1496 (10 omitidas) · frontend 749 · agente Bridge 344 · E2E 32/32, 256/256 y **V2.5 160/160** (3 avisos) | IMPLEMENTADO / VERIFICADO; AWS, Windows firmado, ERP reales y piloto **BLOQUEADOS** |

Los números de la columna «Integración» son los de cada `SUMMARY.txt` (las omitidas son pruebas que requieren servidores MySQL/SQL Server de prueba o un emulador S3 que no
había en el equipo). Cada paquete pasó por implementación → revisión adversarial → corrección antes de confirmarse.

## 2. W0 · inventario y contratos (en `main`)

Hecho antes de esta rama y fusionado en `main` (PR #3 y #4, `c7e6a03` y `6c679d8`; línea base técnica `78c5029`):

- Fuentes versionadas en `docs/roadmap/sources/` (V2.5 literal, V1 recuperado literal, V2 literal parcial). V2.1, V2.2, V2.3, V2.4 y V2.4 Enterprise **no se hallaron íntegras**
  en el repositorio (`RECONSTRUIDO_PARCIALMENTE`); no se declara conciliación literal del 100 % (§28).
- Auditoría de 8 áreas con verificación adversarial (`docs/roadmap/audit/2026-10-31/*.json`): 841 requisitos y 179 defectos (13 HIGH, 73 MEDIUM, 93 LOW).
- Documentos generados (trazabilidad, brechas, diferencias de versión, riesgos) con `python3 infra/scripts/v25_roadmap_docs.py`; plan, gates, ADR-V25-01 a 23 y la matriz T01–T17.
- Línea base (`docs/roadmap/evidence/2026-10-31-baseline/`): motor 418, backend 449 (2 omitidas), frontend 313, E2E 32/32 y 256/256.
- Runbook del piloto y carpetas privadas ignoradas por git (`reports/private/`, `data/private/`).

## 3. W1 · correcciones P0 de temporalidad y seguridad

| Commit | Qué se hizo | Evidencia |
|---|---|---|
| `d13e44f` | Sesión PostgreSQL en UTC (en un servidor con otra zona las marcas `timestamptz` quedaban desplazadas) y prueba de conexión fallida independiente de la autenticación local | `evidence/2026-11-01-w1-integration/` |
| `6150f00` | Progreso por oleada en el generador de la hoja de ruta | `audit/progress/2026-11-01-w1.json` |
| `11c7086` | **W1-B seguridad**: SSRF (sintaxis canónica, A + AAAA, des-mapeo IPv4-en-IPv6, IP fijada), ZIP bomb (bytes reales, no los declarados), hermeticidad de las suites frente a los `.env`, firma Ed25519 de artefactos ligada a `run_id` | `docs/security/ENTERPRISE_SECURITY.md` §2–§4 |
| `c44076e` | **W1-A2 motor as-of**: lectura desde `sales_line_versions`, ventanas de backtest con lo conocido a su corte, Customer as-of, clasificación de días `zero_confirmed / missing_source / stockout_censored / closed`, huella `data_snapshot` | `docs/data/TEMPORAL_AVAILABILITY.md` |
| `eea5321` | **W1-A1 escritura temporal**: modo `backfill`/`incremental`, versiones archivadas, `stale_skipped`, tipos/estados desconocidos → `REVIEW_REQUIRED`, marca de agua acotada; invariante de 0 o 1 versión visible en 336 secuencias | `docs/data/LINEAGE_AND_RECONCILIATION.md` |
| `9809a52` | Cierre: evidencia, auditoría por oleada, gates y corrección del intervalo de cantidad del Customer Core (defecto de la etapa 2 que detectó el E2E) | `evidence/2026-11-01-w1-integration/SUMMARY.txt` |

Requisitos que pasaron a IMPLEMENTADO: V25-AVL-010, V25-AVL-013, V25-BRG-057, V25-SEC-033, V25-REG-045, V25-CUS-038. Demo: sin regresión (paridad exacta con la regla de huecos
inferidos apagada; con la regla encendida se excluyen 18 sucursal-días en Ferretería y 7 en Distribuidora, datos ficticios).

## 4. W2 · Data Core P0

| Commit | Paquete | Qué se hizo |
|---|---|---|
| `d900ebb` | activación | contrato Data Core P0 (migraciones `2026_03_03_000100`, `2026_03_04_000100`, `BronzeStore`, contratos canónicos) |
| `0004dc8`, `62fa080` | contratos | contratos del arquitecto para W3–W6 (migraciones probadas, guiones con paquetes y checklists) y estado |
| `263a268` | fuentes | SQL Server: `encrypt` y `trust_server_certificate` configurables desde la app |
| `e484c43` | W2-F | Factor Intelligence P0: 17 familias catalogadas y desactivadas, taxonomía básica, `SharedForecastGrant` detrás de bandera apagada (`docs/intelligence/FACTOR_INTELLIGENCE.md`) |
| `43a0873` | W2-B | Bronze inmutable con SHA-256 (local y emulador S3), contratos canónicos versionados, alias e identidades (cambio de código), reconciliación por ventana (`docs/data/BRONZE_SILVER_GOLD.md`, `CANONICAL_CONTRACTS.md`) |
| `8ed0faa` | W2-A | calidad de ingestión con clases `AUTO_FIX_SAFE / REVIEW_REQUIRED / QUARANTINED / WARNING / BLOCKER`, cuarentena reprocesable, mapeos de valores, captura Bronze, deriva de esquema, reconciliación por carga |
| `8a69363` | W2-D | `FeatureAvailabilityValidator` con estados `AVAILABLE / NOT_YET_AVAILABLE / STALE / INVALID / SCENARIO_ONLY`, contratos de features y pruebas T11 (`docs/data/FEATURE_CONTRACTS.md`, `docs/ml/POINT_IN_TIME_VALIDATION.md`) |
| `df6531e` | W2-C | métricas de dataset y gates PASS/WARN/DEGRADE/BLOCK por módulo, consultados antes de entrenar e inferir (`docs/data/DATA_QUALITY_ENGINE.md`) |
| `d7d468c` | W2-E | catálogo único de horizontes 7/14/15/30/60/90 (ADR-V25-02) y planes Starter/Growth/Enterprise configurables (`docs/product/RETAIL_STARTER_GROWTH.md`) |
| `fbb1739`, `8e1679c`, `7d25790` | integración y cierre | archivos compartidos, corrección del costo unitario, E2E con el catálogo de horizontes del contrato compartido, evidencia y estado |

Auditoría: 25 requisitos a IMPLEMENTADO y 18 de PENDIENTE a PARCIAL; 13 defectos (`audit/progress/2026-11-01-w2.json`). Revisión adversarial: 2 HIGH y 15 MEDIUM corregidos.
Demo (ficticia): gate PASS en ambas empresas.

## 5. W3 · Calidad ML P0

| Commit | Paquete | Qué se hizo |
|---|---|---|
| `4eb34f4` | activación | contrato de Calidad ML (`customer_predictions` ampliado, `model_promotion_decisions`, `shadow_forecast_values`) |
| `a656521` | W3-A | métricas por horizonte y segmento (clase de demanda, categoría, sucursal, tramo de volumen, vida de la serie as-of) con denominador, días evaluables y estabilidad (`docs/ml/FORECAST_VALIDATION.md`) |
| `562f2b0`, `ef5295f` | W3-B | política heredada parametrizada (≥ 3 % de WAPE y ⌈2n/3⌉ ventanas) con paridad exacta, política de promoción V2.5 en paralelo, champion/challenger, *shadow mode* con evaluación prospectiva, Optuna acotado (`docs/ml/FORECAST_SELECTION.md`) |
| `552f6c0` | W3-C | Customer S05: nº de pedidos (hurdle), cantidad por pedido, unidades, ventana P10/P50/P90 solo si se valida por cobertura, PR-AUC, lift@K, calibración por segmento (`docs/ml/CUSTOMER_REPURCHASE.md`, `CALIBRATION_AND_RANKING.md`) |
| `5dc55a1`, `d719cd9`, `6989b9a` | estado y auditoría | overlay de progreso y prueba de vigencia de alias en la zona horaria de la empresa |
| `7586c57` | W3-D | calidad ML en API y UI (evaluaciones por horizonte y segmento, decisiones de promoción, shadow) y exportación LOPDP de lo nuevo |
| `ae3e231` | integración | suites, E2E, demo y reproducibilidad (5 corridas con distinto `PYTHONHASHSEED`, idénticas fila a fila) |

Auditoría: IMPLEMENTADO 149 → 171, PENDIENTE 217 → 201; 6 defectos (`audit/progress/2026-11-01-w3.json`). Revisión: 1 HIGH y 8 MEDIUM corregidos. Demo: lo servido no cambia
respecto de W2 (la política heredada sigue gobernando por defecto); en el Customer Core la ventana queda `not_validated` y no publica cifras, como exige el contrato.

## 6. W4 · MLOps y AWS P0

| Commit | Paquete | Qué se hizo |
|---|---|---|
| `7170174` | activación | contrato de MLOps P0 |
| `c77deab` | W4-A | estados `candidate / validated / approved / production / retired / rejected` con aprobación separada (quien entrenó o publicó no aprueba), serving solo de lo aprobado o del pipeline propio, medición de uso por empresa (`docs/ml/MODEL_REGISTRY.md`) |
| `bc54d9c` | W4-C | IaC con AWS CDK v2 (síntesis y `cdk-nag`, sin desplegar), modelo de costos paramétrico por empresa, recuperación local probada (`docs/architecture/AWS_ARCHITECTURE.md`, `AWS_COST_MODEL.md`, `docs/operations/RECOVERY.md`) |
| `53400d4` | W4-B | CLI de entrenamiento privado por empresa (`validate-data`, `build-features`, `train`, `backtest`, `tune`, `sign`, `package`, `verify`, `publish`, `compare`, `rollback`, `list-models`, `export-local`, `diagnose`), almacén de objetos (local; S3 solo contra emulador), publicación siempre como candidato (`docs/ml/PRIVATE_TENANT_TRAINING.md`) |
| `00b4270` | integración | stack aislado con `vendor` propio, E2E, demo, auditoría, gate C y estado |

Auditoría: IMPLEMENTADO 171 → 188 (`audit/progress/2026-11-02-w4.json`). AWS real: **BLOQUEADO** (solo `cdk synth`, `cdk-nag` y emulador S3).

## 7. W5 · flujo empresarial P1

| Commit | Paquete | Qué se hizo |
|---|---|---|
| `d54076e` | activación | contrato del flujo empresarial P1 (modelos y `RecommendationSink`) |
| `f18541b` | W5-D | Supply S10: compromisos sin doble conteo, perecibilidad y FEFO, merma proyectada, simulación as-of, importación de lotes (`docs/supply/SUPPLY_CORE.md`) |
| `6fb4819` | W5-A + W5-B | Revenue Radar y Agenda Comercial como fachadas sobre Customer Core (contratos V2.2, seis estados heredados mapeados, feedback auditable); Decision Engine con fórmula versionada, workflow con segregación de funciones e idempotencia (`docs/decision/DECISION_ENGINE.md`, `docs/product/LEGACY_API_COMPATIBILITY.md`) |
| `cb31a03` | W5-C | outcomes y ROI honestos: medición as-of de toda recomendación (también rechazadas, caducadas y no contactadas), ITT con grupo de control (Newcombe y bootstrap con semilla), ROI `potential_unproven` sin control y nunca «probado» (`docs/decision/OUTCOME_MEASUREMENT.md`) |
| `2d1a0b1` | W5-E | vistas Enterprise: Resumen ejecutivo, Revenue Radar, Centro de decisiones, ROI y resultados, Administración (`docs/product/ENTERPRISE_MVP_ACCEPTANCE.md`) |
| `83b3b02`, `dfa2d88` | integración | stack aislado, E2E, demo, auditoría, gate D; números de documento únicos en los datos de prueba de decisiones y resultados |

Auditoría: IMPLEMENTADO 188 → 250 (`audit/progress/2026-11-03-w5.json`). Revisión: 8 HIGH y 29 MEDIUM corregidos. Demo idéntica a W4 (no hay compromisos confirmados cargados en la
demo). Outcomes, uplift y ROI reales: **BLOQUEADO** hasta un piloto.

## 8. W6 · plataforma, integración y cierre

| Commit | Paquete | Qué se hizo | Estado |
|---|---|---|---|
| `d46f839` | activación | contrato de plataforma (migración `2026_03_14_000100`: seguridad, Bridge y gateway) | IMPLEMENTADO / VERIFICADO |
| `778622d` | W6-E | pipeline de factores conectado a la demanda, entrenado como se sirve, con control placebo, selección hacia delante y herramienta de ablation de solo lectura (`docs/intelligence/FACTOR_PIPELINE.md`); 0/330 promociones de ruido en la calibración con datos ficticios | IMPLEMENTADO / VERIFICADO |
| `6b1bd89` | W6-A | MFA TOTP para privilegiados (con política por empresa), tokens con alcance y caducidad, revocación inmediata, roles comerciales gerente/vendedor/servicio, alcance por cartera, matriz rutas × roles × empresa, RLS **experimental y apagada** (`docs/security/ENTERPRISE_SECURITY.md`, `docs/architecture/TENANT_ISOLATION.md`) | IMPLEMENTADO / VERIFICADO |
| `e7fbc37` | W6-B | agente Bridge de solo lectura (PostgreSQL, SQLite, CSV, Excel probados; MySQL y SQL Server sin servidor), firma por dispositivo, checkpoints, búfer cifrado, URL de subida ligada a empresa y TTL (`docs/integrations/BRIDGE_WINDOWS.md`) | IMPLEMENTADO / VERIFICADO; instalador Windows **BLOQUEADO** |
| `5c51090` | W6-C | Auto Mapper: descubrimiento de esquema de solo lectura, propuesta determinista con puntaje heurístico, plantillas versionadas con huella, deriva de esquema (`docs/integrations/AUTO_MAPPING.md`, `ENTERPRISE_ONBOARDING.md`) | IMPLEMENTADO / VERIFICADO |
| `aa82db1` | W6-D | Intelligence Gateway: herramientas tipadas, autorización por herramienta, auditoría de solo inserción, defensa estructural ante inyección y canal WhatsApp **solo contrato** (`docs/architecture/INTELLIGENCE_GATEWAY.md`) | IMPLEMENTADO / VERIFICADO |
| `7eea3bf` | estado | W6-A a W6-E confirmados; integración y W6-F en curso al confirmarse | — |

**Integración de W6** (`docs/roadmap/evidence/2026-11-03-w6-integration/`): stack aislado en `aa82db1` con `vendor` propio y la base de desarrollo migrada (`2026_03_14_000200` … `000500`).

| Verificación | Resultado registrado |
|---|---|
| Backend (`php artisan test`) | `{"tests":2583,"passed":2574,"skipped":9}` (61 402 aserciones) |
| Motor (`pytest`) | 1496 aprobadas, 10 omitidas |
| Frontend | type-check 0 · lint 0 · vitest 749 aprobadas (56 archivos) · build correcto |
| Agente Bridge (`bridge/`, `pytest`) | 344 aprobadas |
| Cada commit de W6 por separado (copia con `vendor` y base de pruebas propios) | W6-A 1798 (1789 aprobadas, 9 omitidas) · W6-B 2051 (2042, 9) · W6-C 2214 (2205, 9) · W6-D = el stack integrado |
| `e2e/mvp_acceptance.py` | 32/32 |
| `e2e/stage2_acceptance.py` | 256/256 · 1 aviso de diseño (el mismo de W2–W5) |
| `e2e/v25_acceptance.py` (nuevo, W6-F) | **160/160** · 0 fallas · 3 avisos: outcome con la ventana de la decisión abierta (no se inventa resultado), asistente desactivado sin clave de proveedor LLM, AWS **BLOQUEADO**. Bloques: preparación 16, seguridad Enterprise (MFA, step-up, tokens) 20, datos CSV y SQL con calidad y cuarentena 17, modelos (gates, aprobación separada, firma, inferencia, bloqueo y reversión) 26, Radar, decisión y outcome 22, vendedor por cartera 8, Bridge firmado 15, Gateway 3, cambio de empresa sin filtraciones 33; 607 s |
| Demo (datos ficticios) | demanda y Customer Core idénticos a W5 en ambas empresas (`demo_compare_vs_w5.json`); los factores siguen apagados por defecto |

Revisiones adversariales corregidas: W6-A 1 HIGH, 5 MEDIUM y 10 LOW; W6-B 13 hallazgos; W6-C 7; W6-D 4 MEDIUM y 6 LOW; W6-E 2 HIGH y 5 MEDIUM. Siguen **BLOQUEADOS**: AWS, el
instalador Windows firmado, SQL Server/MySQL reales, ERP reales y los datos del piloto.

**W6-F:** E2E V2.5 (`e2e/v25_acceptance.py`), auditoría de W6 y evaluación final de gates por el arquitecto. Documentos de §21/§28 entregados en este paso:
`docs/architecture/ENTERPRISE_ARCHITECTURE.md`, `docs/ml/FEATURE_SELECTION.md`, `docs/operations/OWNER_RESPONSIBILITIES.md`, `docs/product/RETAIL_MVP_ACCEPTANCE.md`,
`reports/V25_PILOT_BASELINE.md`, `reports/V25_PROSPECTIVE_VALIDATION.md`, la actualización de `docs/testing/V25_TEST_MATRIX.md` (✅ 38 → 109 de 193 casos frente a la línea base;
ninguna fila validada con datos reales) y este registro.

## 9. Cambios transversales

| Commit | Cambio |
|---|---|
| `a936fd5` | `memory_limit` de PHPUnit igual al del CLI de Docker y cota de tiempo robusta de la invariante de ventanas |
| `e0378db`, `003fd69` | las FKs compuestas `ON DELETE SET NULL` anulan solo la referencia y **nunca** `tenant_id` (en `model_registry`, borrar el modelo anterior convertía el de la empresa en uno de plataforma) |
| `438d182` | phpunit fija `SANCTUM_STATEFUL_DOMAINS` (pruebas herméticas) |
| `ab8816d` | las empresas demo declaran `settings.data_origin = demo` |
| `f7513e6`, `d6c40f9`, `495abeb`, `7eea3bf` | estado de la sesión en `V25-ESTADO-Y-PROXIMOS-PASOS.md` |

## 10. Pendiente

### 10.1 BLOQUEADO (depende del propietario o de un tercero)

| Bloqueo | Afecta | Detalle |
|---|---|---|
| Fuentes SQL Server del piloto sin registrar en la app local | Gates A, B y E; T01.06, T10; `reports/V25_PILOT_BASELINE.md` | el acceso existe; falta que el propietario registre las fuentes. Los resultados reales irán solo a `reports/private/` (ignorado por git) |
| Cuenta AWS, presupuesto, dominios y certificados | T09, Gate C (publicación e inferencia en AWS), costos reales | `docs/operations/AWS_DEPLOYMENT.md` |
| Host Windows y certificado de firma de código | instalador y servicio del Bridge | `docs/integrations/BRIDGE_WINDOWS.md` §5 |
| Servidores SQL Server / MySQL de prueba | T01.05 (las pruebas de servidor se omiten en cada integración) | instancia de prueba o acceso de solo lectura |
| Cuenta de WhatsApp Business Platform y plantillas aprobadas | canal de mensajería (P2) | `INTELLIGENCE_GATEWAY.md` §10 |
| Clave de proveedor LLM con contrato de tratamiento | asistente con un modelo real | consentimiento por empresa: DISEÑADO (V25-SEC-040) |
| Revisión legal (LOPDP, contratos de encargo, EIPD) | Gates C y D | `docs/07-seguridad-multitenant.md` §8 |
| Conectores externos, licencias e historial de emisiones | Factor Intelligence P2 (T11.10, T12 con datos reales, T15) | `docs/intelligence/EXTERNAL_SOURCES.md` |
| Ventana prospectiva acordada con un cliente | Gate E, T07.08, `reports/V25_PROSPECTIVE_VALIDATION.md` | — |

Lista completa de decisiones del propietario, con dónde se configuran: [`docs/operations/OWNER_RESPONSIBILITIES.md`](../operations/OWNER_RESPONSIBILITIES.md).

### 10.2 DISEÑADO / NO IMPLEMENTADO (selección; el detalle está en cada documento)

- **Datos:** totales de control ejecutados en el origen para la reconciliación; datasets Gold persistidos y versionados; inventario, catálogo y costos con versiones as-of;
  la carrera de «primera carga» entre dos importaciones simultáneas (documentada).
- **ML:** escalón de backtest de 15 días (con 84–149 días de historia H15 queda `insufficient_data`); control de comparaciones múltiples sobre muchas familias y repetición
  antes de promover un factor; la huella de contratos de features en el manifiesto; detección de cambio de régimen; formato de artefactos sin pickle.
- **MLOps:** reentrenamiento automático (`docs/ml/AUTOMATED_TRAINING.md`), disco S3 de modelos del backend, despliegue canario y CI que bloquee la fusión.
- **Seguridad:** interfaz completa del segundo factor, WebAuthn/SSO, listado y cierre de sesiones individuales, RLS en más tablas y con el motor consciente de RLS, cuenta de
  base de mínimo privilegio para el motor, consentimiento por empresa para enviar datos a un LLM de terceros (V25-SEC-040), lista blanca SQL obligatoria en producción.
- **Integraciones:** descarga de mapeos aprobados desde el servidor al Bridge, inferencia de cobertura desde el Bridge, cuota por dispositivo, webhook entrante de WhatsApp.
- **Producto:** ABC/XYZ, sustitutos y complementarios (Retail); módulos Export, Industry y Agro (`P3_PENDIENTE`); conexión de los límites declarados de Enterprise
  (módulos, frecuencias, retención, conectores, cómputo, LLM) a los módulos.

### 10.3 Decisiones que corresponden al propietario

Los umbrales de todas las oleadas son **valores iniciales de diseño, a aprobar en el piloto** (ninguno es universal); el cambio de política que gobierna lo servido (`legacy` → `v25`),
la activación de RLS, la política de MFA, el consentimiento para LLM, la región de AWS y el resto se listan con su estado en `OWNER_RESPONSIBILITIES.md`.

## 11. Tabla final por módulo

| Módulo | Evidencia | Estado de pruebas | Costo | Riesgo | Aprobación |
|---|---|---|---|---|---|
| Data Core (W1, W2) | commits §3–§4; `evidence/2026-11-01-w2-integration/` | suites en verde en la integración de W2 y siguientes; sin datos reales | cómputo local; sin APIs de pago | conciliación con un ERP real no ejecutada (piloto BLOQUEADO) | gates A: arquitecto (`V25_RELEASE_GATES.md`); umbrales: propietario en el piloto |
| Demand / selección (W3) | §5; `evidence/2026-11-01-w3-integration/` | en verde; métricas solo con la demo ficticia | cómputo local en CPU | sin evidencia con datos reales; la política V2.5 no gobierna por defecto | cambio `legacy` → `v25`: propietario |
| Customer Core S05 (W3) | §5 | en verde; ventana `not_validated` en la demo | cómputo local | probabilidades y ventana sin validar con datos reales | umbrales: propietario en el piloto |
| MLOps / registro (W4) | §6; `evidence/2026-11-02-w4-integration/` | en verde; AWS solo por síntesis | local; costo AWS paramétrico, sin factura real | publicación e inferencia en AWS BLOQUEADAS | aprobación de modelos: persona distinta de quien entrena |
| Flujo empresarial (W5) | §7; `evidence/2026-11-03-w5-integration/` | en verde; datos sintéticos | local | outcomes y ROI reales BLOQUEADOS; ROI nunca «probado» | fracción de control y reglas: propietario con el cliente |
| Plataforma (W6) | §8; `evidence/2026-11-03-w6-integration/` | en verde en la integración de W6; E2E V2.5 160/160 con datos ficticios | local; Bridge sin costo de nube propio | Windows, WhatsApp, LLM y AWS BLOQUEADOS; RLS apagada | activar RLS, MFA obligatorio, consentimiento LLM: propietario |
| Gates y release | `V25_RELEASE_GATES.md` | — | — | ningún gate en GO | solo el arquitecto edita los gates |
