# Predictily V2.5 · Criterios de salida (release gates)

Fecha de esta evaluación: 1-nov-2026 · Commit evaluado: cierre de W1 en `v25/w1-cierre-y-w2` (ver Historial). Evaluación inicial: 31-oct-2026 sobre `78c5029`.
Regla (V2.5 §19 y §26): **no se admite GO** con fuga temporal, diferencia de ventas netas no explicada,
mezcla de tenants, modelo no aprobado, métricas fabricadas o retorno atribuible no sustentado.
Estados: **GO**, **GO CONDICIONAL**, **NO-GO**, **BLOQUEADO** (depende de un tercero o del propietario).

## Resumen

| Gate | Estado | Motivo principal |
|---|---|---|
| A · Integridad de datos (bloqueante) | **NO-GO** | W1 cerró las fugas temporales de ventas, los huecos como ceros y los tipos/estados desconocidos. W2 agregó el Data Quality Engine (clases, cuarentena revisable, gates PASS/WARN/DEGRADE/BLOCK antes de entrenar e inferir), Bronze inmutable con linaje, deriva de esquema y reconciliación por carga y por ventana. Falta: conciliación mes a mes contra una fuente SQL real (piloto: acceso disponible, fuente sin registrar), reconciliación con totales de control ejecutados en el origen, cuarentena/linaje fuera de ventas, inventario y catálogo con versiones |
| B · Calidad ML (bloqueante para piloto predictivo) | **NO-GO** | W3 agregó métricas por horizonte y segmento con denominador y estabilidad, la política de promoción V2.5 en paralelo con la heredada (champion/challenger, shadow con evaluación prospectiva, Optuna acotado) y Customer S05 (nº de pedidos hurdle, cantidad por pedido, ventana validada por cobertura, lift@K, calibración por segmento), expuestos en API y UI. Falta: evidencia con datos reales (piloto), aprobación humana para que la política V2.5 gobierne, ventana validada en algún dataset (en la demo queda not_validated) |
| C · Seguridad / ML Ops (bloqueante) | **NO-GO** | W1 cerró la deserialización sin firma, la evasión SSRF y la ZIP bomb. W4 agregó el registro con aprobación separada y segregación de funciones, el serving solo de modelos aprobados o del pipeline propio con firma verificada (motor y backend), la CLI de entrenamiento privado por empresa con publicación como candidato, el almacén de objetos (probado solo contra emulador), la exportación autorizada cifrada, la medición de uso por empresa y la infraestructura como código (CDK con cdk-nag). Falta: despliegue real en AWS (**BLOQUEADO**), formato sin pickle, lista blanca SQL obligatoria en producción, MFA y roles Enterprise (W6) |
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
| Demand 7/15/30 con baselines, backtesting sin fuga, holdout separado y métricas por segmento/horizonte | 🟡 | ✅ W1/W2: as-of sin fuga, catálogo 7/14/15/30/60/90 con holdout y manifiesto. ✅ W3-A: métricas por horizonte y segmento (volumen y vida de la serie as-of) con denominador, días evaluables, WAPE inestable y métrica alternativa (`test_segment_metrics*.py`, `test_forecast_validation_scenarios.py`); ✅ W3-B: paridad exacta de la política heredada y gates V2.5 sobre filas alineadas. Pendiente: datos reales del piloto |
| Customer Core: probabilidad calibrada o «no estimable», ventana y cantidad condicionada; ranking evaluado vs baseline | 🟡 | ✅ W3-C: nº de pedidos por modelo hurdle con gate de sesgo, cantidad por pedido, unidades, ventana P10/P50/P90 solo con validación por cobertura (si no, insufficient_data sin cifras), PR-AUC y lift@K frente a popularidad y recencia, calibración por segmento (`test_customer_s05_*.py`); ✅ W3-D: expuesto en API y UI sin cifras inventadas. Pendiente: ventana validada con datos reales; margen del ranking BLOQUEADO sin costo al corte |
| Quiebres, intermitencia, cliente nuevo, total vs individual, reproducibilidad, drift | 🟡 | ✅ W1-A2: huecos y cierres fuera de métricas; ✅ W3-A: escenarios de forma de serie (quiebre, intermitencia, serie nueva); ✅ W3-C: drift por horizonte y degradación con IDs inestables; reproducibilidad entre procesos verificada en la demo (5 corridas con distinto PYTHONHASHSEED idénticas fila a fila, `evidence/2026-11-01-w3-integration/`). Pendiente: datos reales |

## Gate C · Seguridad / ML Ops

| Criterio | Estado | Evidencia / brecha |
|---|---|---|
| Aislamiento A/B en API, S3, modelos, caché, jobs y export | 🟡 | API, jobs y export probados; ✅ W4-B: registro, caché (empresa + versión + esquema + sha) y jobs con pruebas negativas entre empresas, claves del almacén con prefijo de la empresa (`test_mlops_serving.py`, `test_mlops_integration.py`). Pendiente: S3 real (BLOQUEADO; solo emulador), concurrencia real entre procesos → W6 S11 |
| RBAC, revocación, secretos/cifrado, backups restaurables | 🟡 | RBAC y respaldo/restauración local probados; sin MFA ni roles Enterprise → W6 S11 |
| CLI local + Registry + publicación aprobada a AWS + inferencia por tenant + rollback + artefactos verificables | 🟡 | ✅ W1-B firma Ed25519; ✅ W4-A aprobación separada y segregación (`backend/tests/Feature/Models/`); ✅ W4-B CLI completa (validate-data … publish, compare, rollback), publicación siempre como candidato con el contrato de §5.6, serving solo de lo aprobado o del pipeline propio (espejo de ModelServingPolicy, 5 376 combinaciones sin diferencias en la revisión); ✅ W4-C IaC con CDK. Pendiente: publicación a AWS real (**BLOQUEADO**), reentrenamiento automático (DISEÑADO) |

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
| 2026-11-01 | W3 integrado (`a656521`, `562f2b0`, `552f6c0`, `7586c57`) | W3 Calidad ML P0 en sus cuatro paquetes, cada uno con revisión adversarial y corrección (1 HIGH y 8 MEDIUM corregidos). Suites: backend 1217 (1209 aprobadas, 8 omitidas), motor 1071, frontend 389; E2E 32/32 y 256/256; demo sin cambio en lo servido (el motor de W2 sobre los mismos datos sirve lo mismo, fila a fila). Auditoría: `docs/roadmap/audit/progress/2026-11-01-w3.json` (22 requisitos a IMPLEMENTADO, 9 de PENDIENTE a PARCIAL, 6 defectos). Gate B sigue NO-GO con menos brechas: falta evidencia con datos reales (evidencia: `docs/roadmap/evidence/2026-11-01-w3-integration/`) |
| 2026-11-02 | W4 integrado (`7170174`, `c77deab`, `bc54d9c`, `53400d4`) | W4 MLOps y AWS en sus tres paquetes, cada uno con revisión adversarial y corrección (W4-B: 1 HIGH, 4 MEDIUM y 8 LOW). Stack aislado en `53400d4`: backend 1219 (1210 aprobadas, 9 omitidas), motor 1327, frontend 389; E2E 32/32 y 256/256; demo idéntica a W3. Auditoría: `docs/roadmap/audit/progress/2026-11-02-w4.json`. Gate C sigue NO-GO con menos brechas: AWS real BLOQUEADO (evidencia: `docs/roadmap/evidence/2026-11-02-w4-integration/`) |
