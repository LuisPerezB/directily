# Predictily V2.5 · Decisiones de arquitectura (ADR-V25-01 … ADR-V25-22)

Fecha: 31-oct-2026. Rama: `v25/enterprise-core` (base: `etapa-2/core` @ `9d593d5`).
Fuente: `docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md` (V2.5).
Estado de cada ADR: **Aceptada** (rige la implementación de la V2.5) salvo que se indique otra cosa.
Regla de precedencia aplicada (V2.5 §0.5): seguridad/correctitud y disponibilidad temporal > pruebas
reproducibles > visión comercial Enterprise > requisitos demostrados de versiones previas > fases opcionales.

Toda migración de la V2.5 es **aditiva y reversible** (prefijos `2026_03_*`); ningún contrato de la
etapa 1 o 2 se elimina: se versiona o se adapta.

---

## ADR-V25-01 · Evolución no destructiva: cuatro cores y servicios transversales

**Contexto.** La V2.5 exige continuar Predictily sin reemplazar componentes probados (§0, §28) y
fija cuatro cores (Data, Demand, Customer, Supply) más servicios transversales (§0 «Decisiones
funcionales»).

**Decisión.** Se conserva el stack (Laravel 13 + FastAPI + Vue 3 + PostgreSQL + Redis). Los servicios
transversales se implementan como módulos, no como un quinto core:

| Servicio V2.5 | Implementación |
|---|---|
| `PredictilyDecisionEngine` | `backend/app/Services/Decision/` (orquesta salidas de los cores; no entrena ni inventa números) |
| Execution/Approval Workflow | máquina de estados en `backend/app/Services/Decision/Workflow/` |
| `PredictilyOutcomeMeasurementService` | `backend/app/Services/Outcomes/` |
| `PredictilyTrainingOrchestrator` | motor `engine/predictily_engine/training/` + jobs Laravel existentes |
| Model Registry | tabla `model_registry` existente, ampliada (ADR-V25-07) |
| `PredictilyIntelligenceGateway` | evolución de `backend/app/Services/Conversation/` (ADR-V25-17) |
| Predictily Bridge | agente `bridge/` (ADR-V25-10) |

Los radares (Revenue Radar, Radar de Reposición, Radar Comercial) son **vistas/casos de uso** sobre
los cores, no cores paralelos.

**Consecuencias.** Sin duplicación de predicción; cada servicio consume la envoltura común de
resultados de la etapa 2 (`ResultEnvelope`).

## ADR-V25-02 · Catálogo de horizontes parametrizado 7/14/15/30/60/90

**Contexto.** V2 (7/14/30/60/90), V2.2 (recompra 7/14/30/90) y V2.3/Enterprise (7/15/30) difieren
(§25.1, §27).

**Decisión.** Un único catálogo `predictily.horizons.catalog = [7, 14, 15, 30, 60, 90]` validado en
backend y motor. Defaults por perfil comercial: Enterprise `[7, 15, 30]`; planes heredados conservan
los suyos (Básico `[7, 14, 30]`). Demanda y Customer aceptan cualquier subconjunto del catálogo que el
plan permita. Un horizonte sin evidencia suficiente devuelve `quality_status = insufficient_data`
(nunca un número fabricado). El horizonte, la fecha de corte y la unidad viajan en cada resultado.

**Consecuencias.** Validaciones, OpenAPI y UI aceptan 15; pruebas de abstención por horizonte.

## ADR-V25-03 · Semántica de días sin transacciones

**Contexto.** §3 prohíbe convertir automáticamente en cero los días sin transacciones.

**Decisión.** Cada celda diaria de una serie se clasifica como `zero_confirmed` (fuente completa,
tienda abierta, stock disponible y sin venta), `missing_source` (fuera de `data_coverage` o con
desfase de ingestión), `stockout_censored` (stock disponible ≤ 0 o quiebre registrado) o `closed`
(calendario de cierre de la sucursal/empresa). Solo `zero_confirmed` entra como 0 en entrenamiento y
métricas; `missing_source` y `closed` se excluyen; `stockout_censored` se trata como demanda censurada
(la venta observada es cota inferior de la demanda). La clasificación se persiste en el dataset Gold y
su resumen en el informe de calidad.

## ADR-V25-04 · Bronze/Silver/Gold sobre el esquema existente

**Decisión.** No se introduce un lakehouse. Se mapean las capas a lo que ya existe y se completa lo
que falta:

- **Bronze**: almacén de objetos inmutable (`ObjectStore`, ADR-V25-08) con el archivo/lote original
  autorizado, SHA-256, procedencia (fuente, dispositivo, usuario, hora), esquema detectado y
  retención por tenant. Tabla `bronze_objects`.
- **Silver**: tablas canónicas existentes (`sales`, `sale_items`, `inventory_snapshots`, …) con
  linaje (`bronze_object_id`, `source_system_id`, `schema_version`).
- **Gold**: datasets del motor (series diarias/semanales, cliente-producto, precios, inventario,
  features as-of) identificados por `data_snapshots` (huella) y `dataset_fingerprint`.

Reporte de reconciliación fuente → Bronze → Silver → Gold por ventana (`reconciliation_reports`).
Reprocesamiento determinista desde Bronze sin destruir datos.

## ADR-V25-05 · Data Quality Engine con gates por módulo

**Decisión.** `PredictilyDataQualityEngine` unifica las validaciones del importador y las amplía.
Cada hallazgo se clasifica como `AUTO_FIX_SAFE`, `REVIEW_REQUIRED`, `QUARANTINED`, `WARNING` o
`BLOCKER`, conservando el valor original y el motivo (`data_quality_issues`). Cada dataset produce un
`data_quality_reports` con cobertura temporal, % inválido, integridad de claves, historial por SKU y
cliente, proporción de ceros, estabilidad de IDs, presencia de costo/inventario/precio, % de cierre o
quiebre y frescura. Los gates son configurables por módulo y tenant con salida `BLOCK`, `WARN` o
`DEGRADE`; los umbrales iniciales se documentan con su justificación y quedan marcados «a aprobar en
el piloto» (no son universales). Entrenamiento e inferencia consultan el gate antes de ejecutar.

## ADR-V25-06 · Modelo de disponibilidad temporal único

**Decisión.** Módulo `engine/predictily_engine/availability/` con `FeatureAvailabilityValidator`.
Cada feature y cada factor declara su clase: `OBSERVED_HISTORICAL`, `STATIC`, `KNOWN_IN_ADVANCE`,
`THIRD_PARTY_FORECAST`, `USER_SCENARIO`, `UNAVAILABLE_FOR_HORIZON`. Para un `cutoff` el validador
devuelve el valor visible y su estado `AVAILABLE`, `NOT_YET_AVAILABLE`, `STALE`, `INVALID` o
`SCENARIO_ONLY` usando fecha de evento, de observación, de publicación, de disponibilidad real,
vigencia, emisión del pronóstico externo y revisión. Regla de visibilidad heredada de la etapa 2:
`effective_known_at = COALESCE(known_at, ingestión)` y visible si ≤ fin del día local del corte.
Los escenarios se guardan aparte y nunca entran en la medición de precisión. Pruebas adversariales
T02/T11 obligatorias.

## ADR-V25-07 · Registro de modelos: aprobación separada y serving solo de lo aprobado

**Decisión.** Estados `candidate → validated → approved → production → retired`, más `rejected` y el
legado `failed`; `role` primary|fallback se conserva. `approved` exige un usuario con la habilidad
`models.approve` distinto de quien entrenó/publicó (segregación de funciones). El serving solo carga
artefactos `production` aprobados con firma verificada. Se registran algoritmo, versión, propósito,
granularidad, esquema y versión de features, transformadores, dependencias, huella del dataset,
ventana de entrenamiento, métricas train/backtest/holdout, parámetros, checksum, firma, URI, dueño,
justificación, modelo anterior y política aplicada.

## ADR-V25-08 · Almacén de objetos con controladores local y S3

**Decisión.** Interfaz `ObjectStore` (PHP y Python) con controladores `local` (desarrollo y pruebas)
y `s3`. Las claves las genera el servidor con prefijo de tenant (`tenants/<uuid>/bronze/...`,
`tenants/<uuid>/models/...`); nunca se derivan de rutas enviadas por el cliente. Subidas del Bridge por
URL prefirmada PUT limitada a tenant/objeto/tamaño/tipo/TTL, seguida de validación de checksum y
contenido en el servidor. El controlador S3 se prueba contra un emulador S3 local etiquetado como
emulador; el despliegue real en AWS queda `BLOQUEADO` hasta tener cuenta y credenciales del
propietario.

## ADR-V25-09 · Infraestructura como código

**Decisión.** No existe IaC previa. Se elige **AWS CDK en TypeScript** (mismo lenguaje que el
frontend) porque `cdk synth` genera CloudFormation sin credenciales y permite verificar plantillas en
CI (lint y reglas de seguridad). Si las dependencias de CDK no pueden instalarse en el entorno, se
escribe el diseño equivalente y la verificación queda `BLOQUEADO` con instrucciones exactas. Diseño:
S3+CloudFront para el SPA, ECS Fargate (API Laravel, workers, motor FastAPI), RDS PostgreSQL privado,
S3 versionado y cifrado (Bronze/Silver/Gold/artefactos), SQS + DLQ, EventBridge Scheduler, Secrets
Manager, KMS, VPC, CloudWatch y AWS Budgets. Sin GPU permanente. Producción puede iniciar Single-AZ
documentando RTO/RPO y el criterio para pasar a Multi-AZ.

## ADR-V25-10 · Predictily Bridge como agente Python de solo lectura

**Decisión.** Agente `bridge/` en Python (multiplataforma; empaquetado Windows como servicio en un
paso posterior que requiere host Windows y certificado: `BLOQUEADO` hasta disponerlos). Funciones:
registro de dispositivo ligado a tenant/sucursal (credencial revocable emitida por el backend),
descarga de mapeos aprobados, extracción incremental con watermark, solapamiento y relectura de
ventana, checkpoints confirmados solo tras el acuse del servidor, reintentos con backoff exponencial
y jitter, búfer local cifrado y acotado, envío HTTPS saliente (sin puertos entrantes), salud,
pausa/revocación. Adaptadores: CSV/Excel, SQLite y PostgreSQL probados aquí; MySQL/MariaDB, SQL
Server, ODBC, Access y DBF implementados detrás de la misma interfaz y marcados `implemented_untested`
hasta probarlos con su driver real. Nunca escribe en el ERP; no activa CDC.

## ADR-V25-11 · Customer Core: probabilidad, número de pedidos, cantidad y ventana

**Decisión.** Se separan las cantidades exigidas por §7 y §25.2: `P(≥1 pedido en H)`,
`E(nº pedidos en H)` (modelo de conteo; nunca se infiere de `P`), `E(cantidad por pedido | ocurre)`,
`E(unidades totales) = E(nº pedidos) × E(cantidad | pedido)` bajo independencia documentada,
`E(importe | escenario de precio)` y la **ventana** como distribución del tiempo hasta la siguiente
compra (cuantiles; nunca un día exacto). Calibración en holdout temporal con Brier, log loss,
fiabilidad por segmento y horizonte, PR-AUC, precision/recall/lift@K y utilidad económica del
ranking. Con IDs inestables, las recomendaciones individuales se deshabilitan y queda Demand Core. El
caso de las cubetas de huevo es un *fixture* funcional, no una validación real.

## ADR-V25-12 · Revenue Radar y Agenda Comercial sobre Customer Core

**Decisión.** El Revenue Radar es una capa de producto sobre las salidas de Customer Core,
disponibilidad de inventario y margen conocido. Los contratos V2.2 (`/api/v1/radar/summary`,
`/radar/opportunities`, `/radar/agenda`, `POST /radar/opportunities/{id}/feedback`,
`/customers/{id}/predictions|demand|products`, `/demand/forecasts`, `/supply/readiness`,
`/models/evaluations`) se implementan como fachadas sobre servicios existentes, sin duplicar lógica.
Los estados heredados Pendiente, Contactado, Interesado, Pedido recibido, Pospuesto y Descartado se
mapean al workflow Enterprise (ADR-V25-13) sin perder historial.

## ADR-V25-13 · Decision Engine y workflow con segregación de funciones

**Decisión.** Tabla `decision_recommendations` con el contrato de §11 (corte, horizonte, entidad,
fuente y huella, versiones de modelos, probabilidades/intervalos, supuestos, restricciones, costos,
valor bruto esperado, contribución estimada, incremental solo con evidencia, score con versión de
fórmula, explicación, prioridad, dueño, estado, expiración). Máquina de estados
`suggested → assigned → reviewed → approved | rejected → in_progress → executed → measured → closed`
(+ `expired`), transiciones auditadas e idempotentes; quien propone no aprueba su propia acción cuando
la política lo prohíbe. Predictily no escribe en el ERP en P0/P1. Sin evidencia de uplift, la
etiqueta es «oportunidad potencial, atribución no demostrada».

## ADR-V25-14 · Outcomes y ROI honestos

**Decisión.** `outcomes` vincula recomendación → tarea → decisión → acción → evento observable
posterior con IDs, fechas y fuente, y clasifica cada resultado como previsto, observado, asociado
temporalmente, incremental estimado o atribuible causalmente. La atribución causal exige diseño con
grupo de control (asignación aleatoria registrada al generar la recomendación) o un cuasiexperimento
documentado. El ROI usa contribución, no ingresos brutos, e incluye costos de contacto/uso. Se
registran también rechazadas, no contactadas y caducadas.

## ADR-V25-15 · Seguridad y aislamiento como bloqueo de release

**Decisión.** Roles mínimos: propietario Predictily (plataforma), administrador del tenant, gerente,
vendedor, analista y servicio/Bridge; MFA TOTP obligatorio para roles privilegiados; revocación de
sesiones y dispositivos. Pruebas negativas entre tenants en API, colas, caché, jobs concurrentes,
almacén de objetos, URLs prefirmadas, registro de modelos y exportaciones. RLS de PostgreSQL como
defensa adicional se evalúa en tablas piloto detrás de un flag (no sustituye la validación de la
aplicación). Endurecimiento de cargas (límite de descompresión de XLSX/ZIP, tipos, tamaños) y SSRF en
conectores.

## ADR-V25-16 · Taxonomía y Factor Intelligence dentro de Data Core

**Decisión.** Contratos y temporalidad en P0; integraciones externas y ablation sectorial en P2.
Tablas: `taxonomy_nodes` (jerarquía versionada Sector → Industria → Categoría → Subcategoría →
Producto → SKU), `product_taxonomy_assignments` (procedencia manual/llm_proposed/verified),
`sector_profiles`, `factor_definitions` (17 familias de V2.1 catalogadas, desactivadas por defecto),
`factor_observations` (fechas de evento/observación/publicación/disponibilidad/vigencia/emisión y
revisión), `external_events` (`PROPOSED/VERIFIED/REJECTED/EXPIRED`), `source_versions`,
`factor_applicability_rules`, `factor_evaluations` y `shared_forecast_grants` (contrato diseñado,
desactivado, con pruebas negativas de aislamiento). El pipeline de factores es desactivable por
tenant; las alertas oficiales verificadas se muestran aunque no mejoren el error. El SKU canónico
nunca se sustituye por una taxonomía no verificada.

## ADR-V25-17 · Intelligence Gateway sobre el asistente existente

**Decisión.** El servicio conversacional de la etapa 2 evoluciona a `PredictilyIntelligenceGateway`
con herramientas tipadas en lista blanca (`get_forecasts`, `get_opportunities`,
`get_inventory_risks`, `explain_recommendation`, `get_outcomes`, …), autorización por tenant y
usuario en cada herramienta, límites de uso, auditoría minimizada y defensa ante inyección de prompts
en datos importados. Adaptador Ollama Cloud opcional detrás de una interfaz multi-proveedor; el
negocio funciona sin LLM. WhatsApp queda como contrato (verificación de webhook, identidad,
confirmación explícita), P2.

## ADR-V25-18 · Planes comerciales configurables por tenant

**Decisión.** Catálogo de planes en base de datos (`plans.limits`), sin precios codificados como
límites: Starter, Growth y Enterprise se agregan como planes configurables junto al Básico heredado.
Enterprise admite límites por contrato (módulos, series, usuarios, sucursales, frecuencias,
retención, conectores, procesamiento privado, cómputo y LLM). Sin pasarela de pagos obligatoria.

## ADR-V25-19 · CLI de entrenamiento privado por tenant

**Decisión.** CLI `predictily-engine` con comandos documentados: `validate-data`, `build-features`,
`train --tenant`, `backtest`, `tune`, `evaluate`, `package`, `sign`, `verify`, `publish --env`,
`compare`, `rollback`, `diagnose`. CPU por defecto; GPU opcional cuando la librería la soporte, sin
dependencia obligatoria. Datasets locales solo con exportación autorizada, minimizada, cifrada y con
limpieza posterior. `publish` sube el artefacto firmado al almacén y lo registra como `candidate`;
promoverlo requiere aprobación (ADR-V25-07).

## ADR-V25-20 · Artefactos firmados antes de deserializar

**Contexto.** Los artefactos actuales son joblib (pickle) con SHA-256. La V2.5 prohíbe cargar
serializaciones de origen no confiable.

**Decisión.** Cada artefacto se empaqueta con un manifiesto (dependencias, versión de features,
huella del dataset, métricas) y se firma con Ed25519; la clave privada vive en el entorno de
entrenamiento autorizado y la pública en el serving. El serving verifica firma y checksum **antes**
de `joblib.load` y rechaza artefactos sin firma, con clave desconocida o alterados. Los artefactos
previos sin firma se tratan como legado del entorno local (verificables por SHA-256) y no pueden
publicarse a otro entorno sin re-firmarse. Formatos seguros (texto de LightGBM, parámetros en JSON)
se adoptan donde no cambian resultados.

## ADR-V25-21 · Política de selección heredada vs política de promoción V2.5

**Decisión.** La regla de selección vigente del motor se conserva como política `legacy` verificable y
parametrizada; la política V2.5 de promoción (gates: sin fuga, datos válidos, estabilidad temporal,
sin regresión material por segmento crítico, métricas/intervalos/calibración documentados, mejora
frente al champion o beneficio operacional, artefactos verificables, aprobación auditada) se calcula
en paralelo y su resultado se registra junto al de la legado. Si ningún modelo supera al baseline, se
publica el baseline. Optuna es opcional y acotado (`n_trials`, timeout, semilla, sin usar el holdout
final). Evaluación prospectiva en *shadow mode*: el challenger predice sin servir y se evalúa contra
datos posteriores al corte.

## ADR-V25-22 · Interfaz Enterprise

**Decisión.** Nuevas vistas: Executive Overview, Revenue Radar, Decision Center, ROI & Outcomes y
Administración (conectores, calidad, modelos, entrenamiento, costos, auditoría), además de las
existentes (Demanda, Abastecimiento/Radar de Reposición, Clientes). Toda cifra muestra fecha de
corte, unidad, incertidumbre, cobertura y si el dato es real, simulado o de demostración.
