# Predictily V2.5 · Criterios de salida (release gates)

Fecha de esta evaluación: 1-nov-2026 · Commit evaluado: cierre de W1 en `v25/w1-cierre-y-w2` (ver Historial). Evaluación inicial: 31-oct-2026 sobre `78c5029`.
Regla (V2.5 §19 y §26): **no se admite GO** con fuga temporal, diferencia de ventas netas no explicada,
mezcla de tenants, modelo no aprobado, métricas fabricadas o retorno atribuible no sustentado.
Estados: **GO**, **GO CONDICIONAL**, **NO-GO**, **BLOQUEADO** (depende de un tercero o del propietario).

## Resumen

| Gate | Estado | Motivo principal |
|---|---|---|
| A · Integridad de datos (bloqueante) | **NO-GO** | W1 cerró las fugas temporales de ventas, los huecos como ceros y los tipos/estados desconocidos. W2 agregó el Data Quality Engine (clases, cuarentena revisable, gates PASS/WARN/DEGRADE/BLOCK antes de entrenar e inferir), Bronze inmutable con linaje, deriva de esquema y reconciliación por carga y por ventana. Falta: conciliación mes a mes contra una fuente SQL real (piloto: acceso disponible, fuente sin registrar), reconciliación con totales de control ejecutados en el origen, cuarentena/linaje fuera de ventas, inventario y catálogo con versiones |
| B · Calidad ML (bloqueante para piloto predictivo) | **NO-GO** | W2 agregó el horizonte 15 de punta a punta (catálogo 7/14/15/30/60/90 compartido) y el validador de disponibilidad de features (T11). Falta: métricas por segmento y política de promoción (W3-A/B, en curso), Customer Core con ventana de recompra, número de pedidos, lift@K y calibración por segmento (W3-C, en curso); en la demo el ranking servido de 7 y 30 días es la referencia de popularidad; sin datos reales |
| C · Seguridad / ML Ops (bloqueante) | **NO-GO** | W1 cerró la deserialización sin firma (Ed25519 verificada antes de joblib, ligada a empresa, core, corrida y SHA-256), la evasión SSRF (IP fijada, sin rebinding) y la ZIP bomb (bytes reales contados, ventana interactiva acotada). Falta: formato sin pickle o subproceso sin credenciales, lista blanca SQL obligatoria en producción, CLI completa, almacén de objetos y publicación aprobada (W4); AWS **BLOQUEADO** |
| D · Flujo empresarial (bloqueante para Enterprise P1) | **NO-GO** | no existen Revenue Radar, Decision Engine con aprobaciones ni medición de outcomes/ROI |
| E · Piloto prospectivo | **BLOQUEADO** | sin cliente piloto con datos autorizados ni ventana de observación posterior |

| Nivel | Estado | Condición |
|---|---|---|
| P0 · piloto predictivo controlado | **NO-GO** | requiere T01–T05, T08, T10 (hasta inferencia/radar), T11 interno, T13 básico y T16 |
| P1 · piloto Enterprise | **NO-GO** | P0 + T06, T07, T09, T10 completos, agenda/decisión/outcome/ROI auditables |
| P2 · Factor Intelligence ampliado | **NO-GO** | T11–T15 |
| P3 · verticales Industry/Agro/Export | **NO-GO** | T17 e integración real del sector |

## Gate A · Integridad de datos

| Criterio | Estado | Evidencia / brecha |
|---|---|---|
| Fuente SQL real + CSV/Excel probados | 🟡 | CSV/XLSX probados de punta a punta; conector PostgreSQL probado contra una base local, no contra el ERP de un cliente (**BLOQUEADO**: acceso a Soyoda/Resgasa) |
| Sincronización e idempotencia | 🟡 | ✅ W1-A1: versión vieja → `stale_skipped` sin escritura (archivo, lote y SQL); marca de agua acotada al inicio de la sincronización; invariante de ventanas disjuntas en 336 secuencias (`SalesVersionWindowsInvariantTest`). Pendiente: alerta tras N extracciones vacías, carrera de «primera carga» entre importaciones simultáneas (documentada) |
| Conciliación y linaje fuente → Bronze → Silver → Gold | 🟡 | ✅ W2-A/B: Bronze inmutable con SHA-256 (escritura verificada, retención y purga), linaje bronze_object_id/source_row/schema_version en ventas, revisiones e inventario, reconciliación por carga y por ventana que nunca da balanced sin comparar. Pendiente: totales de control ejecutados en el origen, Gold persistido, linaje fuera de ventas e inventario, S3 real (BLOQUEADO) |
| Notas de crédito, anulaciones y cortes as-of | 🟡 | ✅ W1: documento tardío invisible antes de su ingestión y anulación posterior contada antes y excluida después, en lectura, backtest por ventana, holdout y Customer (`test_asof_visibility.py`, `test_asof_integration.py`, `test_asof_customer_integration.py`; V25-AVL-010 y V25-AVL-013 IMPLEMENTADOS). Pendiente: inventario, catálogo y costos sin versiones; Supply, Causal, Escenarios y Costos leen el estado vigente |
| Esquemas versionados, pruebas de fuga y trazabilidad | 🟡 | ✅ W1-A2: pruebas T02 de documentos tardíos y anulaciones; huella `data_snapshot` as-of que cubre huecos, cierres y clasificación de días. Pendiente: contratos canónicos versionados (W2) |
| Falta de datos → degradación o bloqueo, sin inventar | 🟡 | ✅ W1-A2: días `missing_source` / `closed` excluidos de métricas, perfilado, evaluación realizada y deriva (nunca ceros observados); regla inferida configurable, a aprobar en el piloto. Pendiente: gates de calidad PASS/WARN/DEGRADE/BLOCK por módulo (W2) |

## Gate B · Calidad ML

| Criterio | Estado | Evidencia / brecha |
|---|---|---|
| Demand 7/15/30 con baselines, backtesting sin fuga, holdout separado y métricas por segmento/horizonte | 🟡 | 7/14/30/60/90 con holdout y manifiesto (etapa 2); falta 15; fuga temporal de ventas abierta; métricas por segmento parciales → W1, W2 S03, W3 S04 |
| Customer Core: probabilidad calibrada o «no estimable», ventana y cantidad condicionada; ranking evaluado vs baseline | 🟡 | probabilidad 7/30/90 calibrada con Brier/log loss y regla de ranking `cust-ranking-v1`; faltan ventana, número de pedidos, lift@K y calibración por segmento → W3 S05 |
| Quiebres, intermitencia, cliente nuevo, total vs individual, reproducibilidad, drift | 🟡 | tratados en la etapa 2 con datos sintéticos; huecos como ceros abiertos → W1-A2 |

## Gate C · Seguridad / ML Ops

| Criterio | Estado | Evidencia / brecha |
|---|---|---|
| Aislamiento A/B en API, S3, modelos, caché, jobs y export | 🟡 | API, jobs y export probados; S3 inexistente; caché y concurrencia parciales → W4, W6 S11 |
| RBAC, revocación, secretos/cifrado, backups restaurables | 🟡 | RBAC y respaldo/restauración local probados; sin MFA ni roles Enterprise → W6 S11 |
| CLI local + Registry + publicación aprobada a AWS + inferencia por tenant + rollback + artefactos verificables | 🟡 | ✅ W1-B: artefactos firmados Ed25519 y verificados antes de deserializar en motor y backend (reversión), CLI `keygen` y `verify-artifact`. Pendiente: aprobación separada, CLI completa, publicación al almacén de objetos (W4); AWS **BLOQUEADO** |

## Gate D · Flujo empresarial

| Criterio | Estado | Evidencia / brecha |
|---|---|---|
| Opportunity Radar, restricciones de Supply, Decision Engine económico reproducible, aprobación, outcome trazado, ROI honesto | ❌ | planificador de abastecimiento con restricciones existe (etapa 2); Radar, Decision Engine, workflow y outcomes ausentes → W5 |
| Dashboard, onboarding repetible, costos por tenant, runbooks y observabilidad | ❌ | dashboard de la etapa 1; faltan vistas Enterprise, costo por tenant y runbooks → W4, W5, W6 |

## Gate E · Piloto prospectivo

**BLOQUEADO.** Requiere cliente piloto con contrato, datos autorizados, KPI y ventana de observación
acordados (V2.5 §19 Gate E, §22). La decisión GO / GO-CONDICIONAL / NO-GO se registrará con
responsables y evidencia; si la precisión es insuficiente se informa, no se oculta tras un tablero.

## Historial

| Fecha | Commit | Cambio |
|---|---|---|
| 2026-10-31 | `78c5029` | Evaluación inicial (línea base) |
| 2026-11-01 | cierre W1 (`11c7086`, `c44076e`, `eea5321` + integración) | W1 integrado tras revisión adversarial y corrección de A1, A2 y B: 7 de 13 defectos HIGH corregidos y 6 corregidos parcialmente (`docs/roadmap/audit/progress/2026-11-01-w1.json`). Suites: backend 739 (736 aprobadas, 3 omitidas por falta de servidores MySQL/SQL Server), motor 549, frontend 313; E2E 32/32 y 256/256; demo sin regresión (paridad exacta con la regla inferida apagada). Ningún gate cambia a GO: A y C siguen NO-GO con menos brechas (evidencia: `docs/roadmap/evidence/2026-11-01-w1-integration/`) |
| 2026-11-01 | W2 integrado (`e484c43` … `fbb1739`) | W2 Data Core P0 en sus seis paquetes, cada uno con revisión adversarial y corrección (2 HIGH y 15 MEDIUM corregidos). Suites: backend 1090 (1082 aprobadas, 8 omitidas), motor 780, frontend 329; E2E 32/32 y 256/256; demo con gate de calidad PASS y sin regresión. Auditoría: `docs/roadmap/audit/progress/2026-11-01-w2.json` (25 requisitos a IMPLEMENTADO, 18 de PENDIENTE a PARCIAL, 13 defectos). Gates A y B siguen NO-GO con menos brechas (evidencia: `docs/roadmap/evidence/2026-11-01-w2-integration/`) |
