# Predictily V2.5 · Criterios de salida (release gates)

Fecha de esta evaluación: 31-oct-2026 · Commit evaluado: `78c5029` (línea base, antes de cambios V2.5).
Regla (V2.5 §19 y §26): **no se admite GO** con fuga temporal, diferencia de ventas netas no explicada,
mezcla de tenants, modelo no aprobado, métricas fabricadas o retorno atribuible no sustentado.
Estados: **GO**, **GO CONDICIONAL**, **NO-GO**, **BLOQUEADO** (depende de un tercero o del propietario).

## Resumen

| Gate | Estado | Motivo principal |
|---|---|---|
| A · Integridad de datos (bloqueante) | **NO-GO** | fugas temporales en ventas (documentos tardíos y anulaciones posteriores reescriben cortes pasados), huecos de la fuente tratados como ceros, tipos/estados desconocidos contados como venta; sin Data Quality Engine con clases y gates; sin fuente SQL real de un cliente |
| B · Calidad ML (bloqueante para piloto predictivo) | **NO-GO** | horizonte 15 ausente; métricas por segmento incompletas; Customer Core sin ventana de recompra, número de pedidos ni lift@K; en la demo el ranking servido es la referencia de popularidad; sin datos reales |
| C · Seguridad / ML Ops (bloqueante) | **NO-GO** | deserialización joblib verificada solo con SHA-256; SSRF evadible; sin protección ZIP bomb; CLI incompleta; sin almacén de objetos ni publicación aprobada; AWS **BLOQUEADO** |
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
| Sincronización e idempotencia | 🟡 | reimportación sin duplicados (etapa 1); defecto HIGH: una versión vieja sobrescribe la nueva; marca de agua puede saltar al futuro → W1-A1 |
| Conciliación y linaje fuente → Bronze → Silver → Gold | ❌ | sin Bronze inmutable ni reporte de reconciliación → W2 S01 |
| Notas de crédito, anulaciones y cortes as-of | ❌ | defectos HIGH de fuga temporal → W1-A1/A2 |
| Esquemas versionados, pruebas de fuga y trazabilidad | 🟡 | pruebas de fuga de la etapa 2 en features; faltan las de documentos tardíos y anulaciones → W1-A2 |
| Falta de datos → degradación o bloqueo, sin inventar | 🟡 | abstención `insufficient_data` existe; huecos tratados como ceros → W1-A2; gates de calidad → W2 |

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
| CLI local + Registry + publicación aprobada a AWS + inferencia por tenant + rollback + artefactos verificables | ❌ | reversión con SHA-256 (etapa 2); firma, aprobación, CLI completa y AWS ausentes → W1-B, W4; AWS **BLOQUEADO** |

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
