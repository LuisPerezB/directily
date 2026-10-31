# PREDICTILY V2.5 — ENTERPRISE INTELLIGENCE & VALIDATED DECISION CORE
### EDICIÓN RECONCILIADA CON V2 · V2.1 · V2.2 · V2.3 · V2.4 ENTERPRISE
## Prompt maestro de consolidación, evolución no destructiva y validación para piloto real
**Fecha:** 30 de octubre de 2026 · **Estado:** especificación objetivo; NO certifica el repositorio existente.

> **INSTRUCCIÓN PARA EL AGENTE DE DESARROLLO:** Actúa como Principal Software Architect, Data Engineer, ML/Forecasting Engineer, MLOps Engineer, AWS Solutions Architect, Security Architect, QA Lead y Product Engineer para un SaaS B2B. Continúa el repositorio existente **Predictily**, no inicies otro proyecto. Esta V2.5 es **aditiva y reconciliadora**, no una orden de reemplazar componentes probados. Tu meta P0/P1 es dejar un core técnicamente verificable y realizar una prueba piloto empresarial real, no acumular módulos parcialmente funcionales. Nunca declares completado un requisito por la mera existencia de clases, pantallas, mocks o documentación. Si una integración o infraestructura depende de credenciales externas o aprobación humana, registra `BLOQUEADO`, continúa lo independiente y entrega instrucciones exactas al propietario, sin fingir que está ejecutado.

---

## 0. FUENTES, TRAZABILIDAD Y REGLA DE NO PÉRDIDA

**FUENTES ÍNTEGRAS EFECTIVAMENTE DISPONIBLES EN ESTA CONCILIACIÓN:** V2 (texto adjunto «Texto pegado(9).txt»), V2.1 (texto íntegro proporcionado en el mensaje actual), V2.2 (texto adjunto «Texto pegado (2).txt»), V2.3 (texto completo de «Texto pegado(7).txt» de la Biblioteca), V2.4 Enterprise (texto adjunto «Texto pegado(8).txt»). **FUENTES NO RECUPERADAS ÍNTEGRAS:** instrucción original/V1 y V2.4 estándar; solo se conocen por citas y decisiones históricas parciales. Verificar sus fuentes originales en Git/docs antes de declarar conciliación literal del 100%.

Esta edición sí compara directamente las V2, V2.1 y V2.2 completas; sustituye la indicación desactualizada de que solo estaban disponibles resúmenes. Las procedencias documentales deben referenciar sus textos reales. Los requisitos de Factor Intelligence, taxonomía y módulos sectoriales de V2/V2.1 son **parte del alcance preservado**, aunque sus implementaciones completas no deban bloquear el primer piloto comercial P0/P1 cuando sean extensiones P2/P3. Ningún apartado nuevo certifica que el repositorio ya implemente estas funciones.

### Instrucciones obligatorias de auditoría retrospectiva

1. Localiza prompts y documentos V1, V2, V2.1, V2.2, V2.3, V2.4, V2.4 Enterprise dentro del repositorio, historial Git, carpetas `docs` y archivos accesibles con permiso. Documenta qué fue hallado y qué no.
2. Crea `docs/roadmap/V25_SOURCE_TRACEABILITY.md` con cada requisito: ID estable; descripción; fuente literal y ubicación/commit (o `RECONSTRUIDO_PARCIAMENTE`, `NUEVO_V25`); versión; módulo; decisión (CONSERVAR/AMPLIAR/DEPRECAR/CONFLICTO); estado real; evidencia (código, tests, logs); prueba; prioridad; responsable; riesgos. V2.1 sí está disponible literalmente en el mensaje del propietario; cita su sección exacta. No marques V1 o V2.4 estándar como cotejadas literalmente si no recuperas sus textos.
3. Crea `docs/roadmap/V25_GAP_ANALYSIS.md` clasificando IMPLEMENTADO / PARCIAL / PENDIENTE / BLOQUEADO / NO APLICA, con evidencia ejecutable y diferencia frente a la meta.
4. Preserva código, contratos, migraciones, tests, esquemas y módulos operativos. Antes de editar, revisa estado de Git, ramas, migraciones, APIs, workers, despliegues, cobertura y cambios no confirmados. No sobrescribas trabajos ajenos ni ejecutes migraciones destructivas. Crea ADR para incompatibilidades y rollback.
5. En caso de contradicción, rige: **seguridad/correctitud y disponibilidad temporal > pruebas reproducibles > V2.4 Enterprise como visión comercial > requisitos demostrados V2/V2.1/V2.2/V2.3 > continuidad de V2.4 estándar cuando se recupere > fases opcionales**. En discrepancias de prioridad comercial, gana Enterprise; en cobertura técnica no se elimina una función demostrada de una versión previa. El agente no eliminará requisitos anteriores válidos solo por cambiar el producto prioritario.
6. No declarar V2.5 "completa" por entregar documentos o despliegues sin prueba integrada con datos válidos.

### Decisiones funcionales reconciliadas

- **Producto inicial visible:** *Revenue Opportunity Radar / Radar de Ventas* para distribuidores B2B con clientes identificados. **Conservar** *Radar de Reposición* para retail/negocios sin IDs confiables; *Radar Comercial* es el conjunto de capacidades Customer Core utilizado por ambas experiencias cuando corresponda. Los radares son vistas/casos de uso, no cores paralelos.
- **Arquitectura base:** Data Core, Demand Core, Customer Core y Supply Core; `PredictilyDecisionEngine`, Execution/Approval Workflow, `PredictilyOutcomeMeasurementService`, `PredictilyTrainingOrchestrator`, Model Registry, `PredictilyIntelligenceGateway` y `Predictily Bridge` son servicios transversales, no un quinto core que duplique predicción.
- **ERP/POS/CRM** permanece fuente de verdad operativa; Predictily es capa de inteligencia y recomendación; no reemplazar facturación, contabilidad, logística ERP ni CRM. Las escrituras futuras requieren consentimiento, permisos, contratos idempotentes y aprobación.
- **Modelo comercial:** pocos clientes medianos, alta integración/resultado, no masificación obligatoria desde el día 1. Rangos orientativos Enterprise: implementación USD 1.500–8.000, suscripción USD 1.000–3.000/mes, contratos personalizados superiores; no codificar precios como límites rígidos. Conservar catálogo Starter USD 49 y Growth USD 119 como capacidad heredada opcional, no como estrategia obligatoria de lanzamiento.
- **Entrenamiento:** inicialmente local y privado por tenant cuando sea necesario, publicación controlada de artefactos a S3, inferencia autorizada en AWS; eventual automatización de entrenamiento con límites; no GPU permanente, no reentrenar tras cada factura. Un modelo tabular/estadístico no necesariamente tiene "pesos" tipo red neuronal: registrar **artefactos, parámetros, features y dependencias**.
- **LLM/WhatsApp:** desacoplados; uso permitido para consultas, explicaciones y acciones supervisadas, nunca para inventar resultados, hacer SQL arbitrario, elegir por intuición el modelo "ganador" ni saltar controles.
- **Expansión** Manufacturing/Industry, Agro, Export, colaboración autorizada entre empresas y Private Edge se preserva como extensibilidad P2/P3. **Factor Intelligence dentro de Data Core y taxonomía de productos**, definidos expresamente en V2.1, se conservan desde el diseño del core: contratos y validadores temporales son P0, conectores/factores completos y ablation sectorial corresponden a P2. No borrar código funcional ya construido.

---

## 1. PRODUCTO FINAL Y CONTRATOS DE RESULTADO

La plataforma debe: conectar datos autorizados; validar integridad; reconstruir series *as-of*; pronosticar ventas/demanda; estimar recompra por cliente-producto; identificar oportunidades/stockouts; optimizar decisiones bajo restricciones; recomendar acciones explicables; gestionar responsables/aprobaciones; sincronizar outcomes; evaluar exactitud, impacto económico e incertidumbre; auditar decisiones por tenant.

### Casos prioritarios P0/P1

- Distribuidor B2B: `cliente × SKU × horizonte`, probabilidad de compra y ventana, cantidad si compra, valor y contribución esperada, priorización de contacto y disponibilidad. Horizontes de 7 días (central), 15/30 y otros configurables. **Compatibilidad heredada obligatoria con 14 días (V2.2), 60 y 90 días (V2), solo si hay datos suficientes; 7/14/15/30/60/90 deben ser parámetros, no valores fijos.** Detectar clientes tardíos, caída de frecuencia/volumen, sustitución de producto y pedidos próximos.
- Retail/tienda sin compradores identificados: demanda `SKU × sucursal`, categoría y total, riesgo de agotamiento, sobrestock, presupuesto y orden de reposición sugerida. Sin inventar datos de clientes. Conserva Starter/Growth.
- Compras/suministro: lead time, disponibilidad, reservas, en tránsito, pendientes, mínimos y múltiplos, perecibilidad, proveedores, capacidad, presupuesto y costo de servir.
- Gerente: dashboard ejecutivo con evidencia, calidad de predicción, oportunidades potenciales y acciones/beneficio económico observado, distinguiendo el **incremental atribuible** del correlacionado.

### No objetivos P0

No montar automáticamente compras reales ni modificar precios, no lanzar un marketplace B2B, no entrenar un LLM financiero propio, no prometer elasticidad causal sin datos experimentales, no requerir Private Edge ni docenas de conectores, no construir un ERP, no depender de Chronos/TimesFM/TimeGPT ni de APIs de pago para el camino base.

---

## 2. REVISIÓN TÉCNICA INICIAL DEL REPOSITORIO (ANTES DE CODIFICAR)

Inspeccionar y ejecutar: ramas Git; composer/npm/poetry/uv/requirements; Laravel controllers/jobs/services/auth/queues; Vue y routing; FastAPI endpoints; PostgreSQL/índices/migraciones; Redis si está presente; S3; IaC; contratos OpenAPI; ETL/Bridge; Bronze/Silver/Gold; dataset builders; features; modelos estadísticos y MLForecast/LightGBM; selector actual; backtests; Optuna; fallback; simulador de abastecimiento; tenant scoping; modelos/Registry; IAM/secrets; CI/CD; logs; costes; backups; pruebas unitarias, integración, seguridad, carga y E2E.

Registrar en informe: `función`, `estado`, `ruta`, `dependencias`, `cómo se ejecutó`, `resultado`, `evidencia`, `riesgo`, `siguiente acción`, `prueba de aceptación`. Si el selector actual aplica la regla WAPE mejora ≥3% y gana en 2/3 ventanas, **conservar como política heredada verificable**, parametrizar y comparar contra la política de V2.5 antes de cambiarla; no afirmar que sigue vigente sin ejecutar el código. Identificar hiperparámetros fijos, variables externas inactivas y uso de precio constante futuro como limitaciones si se observan.

Plan de migración incremental con feature flags y contratos API compatibles. Diseñar migraciones aditivas y backfills idempotentes. Evitar introducir complejidad infraestructural no necesaria para el piloto.

---

## 3. CONTRATOS DE DATOS CANÓNICOS, PROVENIENCIA E IDENTIDADES

Definir y versionar contratos `Tenant`, `Branch`, `SourceSystem`, `Product`, `ProductAlias`, `Category`, `Customer`, `CustomerAlias`, `Supplier`, `SalesDocument`, `SalesLine`, `Order`, `OrderLine`, `CreditNote`, `Return`, `PriceHistory`, `InventorySnapshot`, `InventoryMovement` (si disponible), `PurchaseOrder`, `InboundShipment`, `SupplierLeadTime`, `CostHistory`, `UnitConversion`, `CalendarEvent`, `ExternalSignal`, `Action`, `Outcome`, `Forecast`, `Recommendation`, `ModelVersion`, `DataQualityReport`.

Campos imprescindibles: `tenant_id` en TODAS las entidades, `source_system_id`, ID origen, ID estable canónico, evento/fecha de negocio, zona horaria, `ingested_at`, `effective_at`, `known_at/available_at`, moneda, unidades, estado documento, hash/fingerprint y versión de esquema. En líneas de venta: cantidad bruta/neta, devolución/anulación, precio unitario antes y después de descuento, impuestos por separado, coste conocido en su fecha, SKU/sucursal/cliente (cuando confiables). Distinguir facturas de pedidos, cotizaciones y reservas; venta confirmada vs posible; gravar flags de crédito/anulación y no duplicar facturación.

Reglas: no convertir días sin transacciones automáticamente en cero si hubo caída de integración, cierre, inventario no disponible o ruptura de origen; clasificar `zero_confirmed`, `missing_source`, `stockout_censored`, `closed`. No sumar cantidades de unidades distintas ni monedas sin conversión trazable. Reconciliar devoluciones y notas de crédito con venta original; evitar duplicar documentos de varios canales; controlar cambio de código, fusión/división de SKU y clientes homónimos. El cliente se identifica mediante IDs autorizados; almacenar PII mínima y no usar campos sensibles injustificados.

Implementar **modelo de disponibilidad temporal**: cada feature y variable externa debe ser observable al instante simulado de predicción; `known_at` y `effective_at` evitan usar revisiones publicadas después. Guardar precio histórico real y distinguir precio futuro confirmado vs escenario hipotético; no usar precio realizado futuro como predictor de un pronóstico pasado. No usar el resultado posterior de una acción como feature del mismo origen.

Capa Bronze inmutable o versionada con original autorizado/checksum/procedencia y retención; Silver normalizada con estado y linaje; Gold con series diarias/semanales, cliente-producto, historial de precios, inventario disponible, características *as-of*, score de calidad y datasets versionados. Políticas de retención y borrado por tenant con evaluación de obligaciones contractuales/legales.

---

## 4. PREDICTILY BRIDGE / FRAMEWORK DE CONECTORES (LEGADO V2.3)

Conservar Bridge opcional Windows 10/11 y Windows Server compatible; servicio autorizado de **lectura** sin modificar ERP; salida HTTPS iniciada por red del cliente sin puertos entrantes. Adaptadores: CSV/Excel, SQL Server, PostgreSQL, MySQL/MariaDB, SQLite, ODBC, Access y DBF/Visual FoxPro donde driver y permisos estén probados. Considerar Firebird/sistemas propietarios más adelante. No asumir que un `.exe` es fuente de datos: identificar almacenamiento con autorización. Preparar APIs empresariales y conectores potenciales Siigo Contífico, Perseo, Odoo, Loyverse, sujetos a APIs/planes vigentes y pruebas reales.

Mantener interfaces `ProductSource`, `SalesSource`, `CustomerSource`, `InventorySource`, `PurchaseSource`, `SupplierSource`, `BranchSource`. Soportar paginación, cuotas, rate limits, fallos de autenticación, extracción histórica por lotes, reintentos con backoff+jitter, checkpoints, watermark, solapamiento, relectura de ventana, modificaciones tardías, anulaciones, deletes/tombstones seguros, upsert idempotente, duplicados y reconciliación de totales. No activar CDC en bases legacy productivas sin evaluación y autorización. No consultar tablas completas repetidamente si hay extracción incremental segura.

El Bridge debe ofrecer instalación/configuración, registro de dispositivo ligado a tenant/sucursal, selección de origen, schema discovery, mapeos, salud, pausa/revocación, almacenamiento temporal cifrado acotado, colas de reintentos y actualización/desinstalación seguras; firmar paquetes cuando se disponga de certificado. Credenciales de solo lectura, revocables y nunca en texto plano. No acceder remotamente a equipos ni evadir controles de aplicaciones originales.

### Auto Mapper

`SchemaDiscoveryAndMappingService`: introspección autorizada de tablas, tipos, llaves/relaciones, muestras mínimas, propuesta por reglas determinísticas, puntaje de confianza **heurístico (no probabilidad calibrada)**, corrección/aprobación humana, plantillas versionadas por tenant, detección de drift de esquema, revisión ante ruptura. LLM opcional solo para sugerir equivalencias con metadatos/muestras minimizadas y herramientas permitidas; jamás SQL arbitrario.

### Cargas a AWS

HTTPS y credenciales temporales; preferir pre-signed S3 PUT limitado a tenant/objeto/tamaño/tipo/TTL, más validación posterior del contenido/checksum y evento de procesamiento; S3 Bronze → cola SQS → ETL Silver/Gold → actualización features/predicciones. URLs prefirmadas **no** sustituyen autorización app. Usar identificadores de evento, idempotency keys, DLQ, reintentos limitados, reconciliación y alarmas; nunca confiar en rutas ni identificadores de tenant enviados por el cliente sin autorización de servidor.

---

## 5. DATA QUALITY ENGINE Y GATES ANTES DE ENTRENAR

`PredictilyDataQualityEngine` detectará: duplicación, anulaciones, devoluciones, diferencias de totales, ventas sin detalle, cambios de códigos, costos/precios inválidos, outliers, días faltantes, unidades incompatibles, cambios de moneda, relaciones inexistentes, negativos (distinguir devoluciones), timezone, inventario negativo, stockouts, cambios bruscos, desfase de ingestión, retrasos, columnas nuevas/ausentes y huecos de cliente/SKU.

Clasificar cada observación/problema como `AUTO_FIX_SAFE`, `REVIEW_REQUIRED`, `QUARANTINED`, `WARNING`, `BLOCKER`, conservando originales y motivo. Para cada dataset calcular cobertura temporal, porcentaje inválido, integridad de claves, cantidad de historial por SKU y cliente, proporción demanda cero, variabilidad, estabilidad de IDs, presencia de costo/inventario/precios, porcentaje de fechas de cierre/stockout y frescura. Definir gates configurables por módulo/tenant: bloqueos en identidad, reconciliación monetaria o leakage; features optativas deshabilitadas si faltan datos. **No usar umbrales inventados como universales**: establecer criterios iniciales, justificación, sensibilidad y umbrales aprobados por el piloto, diferenciando `BLOCK`, `WARN`, `DEGRADE`.

Crear reporte de fuente → Bronze → Silver → Gold por ventana: filas aceptadas/invalidas, facturas netas, unidades, importes, cancelaciones, duplicados, delta de sincronización y discrepancias. Mantener bitácora de transformaciones y esquema/versión; permitir reprocesamiento determinista con datos autorizados, no destruir.

---

## 6. DEMAND CORE — FORECASTING AGREGADO ROBUSTO

Pronosticar `SKU × sucursal`, categoría, almacén, sucursal, mercado/región cuando disponibles, empresa y total, 7/15/30 días por defecto y horizontes configurables **7/14/15/30/60/90 heredados**, diarios, semanales, mensuales y por temporada según disponibilidad; salidas diarias y agregadas con intervalo/quantiles, as-of date, versión modelo/dataset, unidad, limitaciones, métricas y flags. Distinguir **ventas observadas** de **demanda latente** no satisfecha bajo stockout; si no hay inventario histórico confiable, mostrar limitación, no estimar demanda perdida como hecho.

Candidatos: Seasonal Naive y naive apropiado, ETS/AutoETS/estadísticos robustos, Croston/TSB u otros para demanda intermitente, regresión, LightGBM + MLForecast y modelos globales solo con datos autorizados del tenant; conjuntos/ensembles si superan controles de evaluación. Mantener adaptadores para TimesFM, Chronos-2, AutoGluon-TimeSeries, TimeGPT solo si valor/costo/privacidad permite, no dependencias obligatorias. Series escasas / nuevos SKU: fallback de clase/categoría/series similares **del mismo tenant**, indicando confianza y cobertura limitadas, nunca cifras falsas. Permitir agrupaciones por volumen, intermitencia, vida de producto o comportamiento.

Asegurar coherencia entre agregados mediante reconciliación jerárquica cuando corresponda; nunca contar dos veces pedidos confirmados ni sumar demanda total estimada + demanda de clientes que representa lo mismo. Al integrar demanda cliente-producto, documentar reconciliación top-down/bottom-up/ponderada y el efecto sobre sesgo y métricas. Tratar promociones, calendarios (incluidos pagos/feriados), cierres, feriados y precios como variables solo si conocidas ex ante; clima, maíz, inflación, competencia, escasez y eventos externos: source/version/time de disponibilidad; predicciones exógenas propias o escenarios, sin utilizar valores futuros observados.

---

## 7. CUSTOMER CORE — COMPRA, CANTIDAD Y TIEMPO POR CLIENTE

Granularidad prioritaria `cliente × SKU`, agregando a categoría/cliente cuando sea estadísticamente necesario. Evitar que un comprador frecuente con múltiples productos sea tratado como múltiples clientes; las recomendaciones referencian el par cliente-producto y pueden agruparse para contacto.

### Tres tareas (evaluarlas vs alternativas simples)

A. `P(compra en horizonte | información as-of)` con baseline recencia/frecuencia, heurística probabilística calibrada, clasificación LightGBM, modelos de supervivencia/discrete-time según viabilidad. Horizonte 7 días y 14/15/30/90 configurables cuando el histórico permita estimación razonable; establecer unidad correcta de observación, episodios, censura derecha y cohortes.

B. `E(cantidad | sí compra)` con baseline histórico por cliente/SKU, regresión/ML apropiado, estimación de dispersión e intervalos; aplicar unidades/múltiplos compatibles; no sustituir `P(compra)` por cantidad. `E(unidades totales) = P(compra) × E(unidades|compra)` solamente para un evento en el horizonte; con compras repetidas estimar número de eventos y cantidades por evento sin subcontar.

C. ventana probable: distribución de tiempo hasta siguiente compra; umbrales de retraso basados en perfil, riesgo y dispersión; no prometer un día exacto. Manejar clientes sin suficiente historial, reactivados, abandonados, productos sustitutos y cambios de identidad.

Features: últimos pedidos válidos, intervalos, volumen, días desde última compra, frecuencia y recencia, precio históricamente disponible, descuentos, categoría, estacionalidad, disponibilidad, desabastecimiento, comportamiento y cambios recientes. Modelar sensibilidad a precio **asociativa** si no puede identificarse efecto causal; evitar afirmaciones de elasticidad causal basadas en simple correlación.

Calibrar probabilidades en holdout temporal; informar Brier, log loss, reliability bins, calibración por segmento y horizonte, precision/recall/lift@K y utilidad económica de ranking. Validar ventana (coverage y error temporal) y cantidad condicionada (MAE/WAPE u otra métrica estable; dispersión/calibración). Evaluar baselines, cold start y drift. No fabricar 0.875 o porcentajes con apariencia científica sin datos y calibración.

#### Caso de prueba funcional (NO pronóstico real)

Cliente con 3 años: normalmente compra 5–7 cubetas de huevos cada 9–15 días; cuando subió el precio, pasó aproximadamente dos meses comprando 3–5 cada 6–10 días y luego retornó al patrón previo. Dado corte histórico y última compra 13 días antes, el pipeline debe producir: probabilidad calibrada para próximos 7 días (o comunicar imposibilidad), ventana, cantidad condicionada, factores, riesgo, valor esperado y recomendación; compare resultados con baseline y histórico posterior separado. Incorporar escenario de escasez de maíz, fenómeno de El Niño, costo de alimento, oferta e inventario solo con variables con fecha de conocimiento o escenarios explícitos; nunca validar este caso solamente con valores inventados.

---

## 8. MODEL SELECTION, OPTUNA Y VALIDACIÓN RIGUROSA

El **selector** es cuantitativo, no un LLM. Separar datasets de entrenamiento, tuning, selección, calibración, holdout final y validación prospectiva. Rolling-origin/expanding-window backtesting con gaps si aplica; ventanas representativas de ciclos comerciales; repetir por horizonte, SKU/segmento/cliente y estacionalidad. Obtener predicciones genuinas *out-of-fold* para ensembles/calibración. No fuga por normalización global, target encoding, agregados futuros, stockouts futuros, promociones no conocidas, columnas `updated_at` ni joins tardíos.

**Métricas Demand:** WAPE con denominador y cobertura explícitos, MAE, MASE cuando aplique, sesgo, error por horizonte, segmentos, quantile/pinball loss, cobertura de intervalo, anchura, estabilidad y coste de inferencia. WAPE es inestable o indefinido en series de demanda cero: usar métricas alternativas/agrupación adecuada. Evaluar métricas económicas downstream (stockouts, capital, margen). **Customer:** Brier/log loss/calibration, PR-AUC y lift@K con clases desbalanceadas, calidad de ventana de recompra, error de cantidad condicionada y utilidad de recomendaciones. Comparar contra baselines por segmento y una política comercial simple.

Optuna opcional y limitado: `n_trials`, timeout, CPU/memoria, coste/tenant, seeds, early stopping, registro de experimentos, revisión de variación temporal, contrato reproducible. Ajustar solo hiperparámetros dentro del proceso de validación: no usar holdout final para optimización. Ensembles/rankers solo si aportan mejora robusta, no por sofisticación. No entrenar miles de modelos independientes sin una razón cuantificada.

### Política de promoción (configurable, no umbral arbitrario)

Gates obligatorios: ausencia de leakage; datos válidos; estabilidad temporal; no regresión material por segmento crítico; métricas/intervalos/calibración documentados; mejora frente al *champion* y/o beneficio operacional con coste aceptable; artefactos verificables; decisión de aprobación/auditoría. La regla heredada `≥3% WAPE y 2/3 ventanas`, si existe en el código, debe poder compararse como política legacy; no convertirla automáticamente en criterio exclusivo para recompra o decisiones económicas. Registrar modelo candidato/champion/challenger y por qué se rechazó/promovió. Si ningún modelo supera baseline, publicar baseline.

Pruebas prospectivas en *shadow mode* contra datos que ocurran después del corte: evaluar error, cobertura, calibración, ranking económico, drift, costo/latencia. La prueba retrospectiva es necesaria, no evidencia suficiente para afirmar precisión futura óptima.

---

## 9. MODEL LIFECYCLE, ENTRENAMIENTO PRIVADO Y REGISTRY

Mantener por tenant datos, features, configuraciones, modelos, predicciones, recomendaciones, costos, cachés y auditorías aislados. **No compartir información/modelos entrenados en un tenant con otro**, ni utilizar datos conjuntos sin autorización explícita y diseño de privacidad/base jurídica apropiados. Los modelos globales pueden agrupar series de **un mismo tenant**, no entre empresas por defecto.

`PredictilyTrainingOrchestrator`: ingesta → features → inferencia → evaluación → señal de drift → entrenamiento candidato → tuning → backtest/calibración → validación → aprobación → publicación → monitoreo/rollback. Sin reentrenar tras cada factura; disparadores configurable: datos nuevos suficientes, drift, degradación sostenida, cambio de precios/mercado, calendario y solicitud autorizada. Retener champion para inferencia mientras corre challenger y cuando falla calidad o conexión.

CLI local ejecutable en CPU y GPU compatible cuando aplique, incluida posible RTX 5080 **sin dependencia obligatoria**. Debe permitir: `validate-data`, `build-features`, `train --tenant`, `backtest`, `tune`, `evaluate`, `package`, `sign/verify`, `publish --env`, `compare`, `rollback`, `diagnose` mediante los nombres finales documentados, no comandos ficticios. Configuración por tenant y perfiles reproducibles. Los datasets locales deben ser exportados con autorización y minimización, cifrado temporal y limpieza. El sistema debe contemplar entrenamiento AWS aislado con presupuestos solo cuando justificado, sin GPU permanente.

Registry versionado: tenant/model ID, algoritmo, versión, propósito/granularidad, esquema y versión features, transformadores, entorno/dependencias, fingerprint de dataset, ventana de entrenamiento, métricas train/backtest/holdout, parámetros, checksum/firma, URI S3, propietario, estado `candidate/validated/approved/production/retired/rejected`, fechas, justificación, modelo anterior, política y auditoría. Separar promoción de archivo subido: **solo serving approved production**; modelos externos/serializados no confiables prohibidos. Evitar pickle/joblib arbitrario de origen no confiable; verificar procedencia, contenido, dependencias y limitar ejecución/sandbox cuando sea necesario. S3 prefijos/políticas tenant y cifrado/versión/retención/revocación; keys no derivadas de rutas entregadas por usuario.

FastAPI en AWS: autorización server-side desde token validado internamente, resolver modelo en Registry, no elegir URI del request, cache key con tenant+model version+schema, protección concurrente, límites memoria/CPU, errores sin PII, cold starts controlados; fallback seguro a último modelo estable o baseline con marca clara. Canarios y rollback; pruebas negativas de contaminación cruzada entre tenants, caches y jobs.

---

## 10. SUPPLY CORE Y OPTIMIZACIÓN OPERATIVA

Calcular existencia disponible = stock físico válido - reservado, separado de pedidos confirmados, compras en tránsito y ajustes. Integrar previsiones con lead times, stock seguridad, reorder point, MOQ, múltiplos, costos, proveedores, capacidad logística/almacenamiento, presupuestos, caducidad y riesgo de no poder abastecer. Diferenciar SKU productos perecibles vs durables y fecha de vencimiento/FEFO cuando exista. Recomendar cantidad/fecha/costo y escenario sin compra. No emitir compra automáticamente P0/P1.

Reconciliar pedidos confirmados con proyecciones, y presupuesto de abastecimiento con costos financieros y compromisos; priorizar restricciones duras sobre un forecast optimista. Modelos deterministas primero; optimización matemática (lineal/mixta) y simulación Monte Carlo solo si existen datos/costo-beneficio, sin sugerir soluciones imposibles ni inventarios negativos silenciosos.

Para casos agrícolas: precio maíz, lluvia/eventos meteorológicos, oferta, tipo de cambio y otros factores externos como señales versionadas o escenarios **supuestos**, con diagnóstico de disponibilidad/forecast de esas señales. No extrapolar causalidad ni capacidad productiva sin datos. Retener extensibilidad Agro/Manufacturing/Export.

---

## 11. DECISION ENGINE Y MODELO DE RECOMENDACIÓN

`PredictilyDecisionEngine` combina Demand, Customer, Supply y reglas del tenant; no entrena modelos ni genera números sin fuentes. Generar recomendaciones de: contacto, recuperación comercial, riesgo cliente, provisión/reposición, incompatibilidad inventario-demanda, restricción presupuestaria y posibles escenarios.

Contrato recomendado: ID, tenant, fecha de corte, horizonte, entidad/cliente/SKU, fuente y fingerprint, modelo/versiones, probabilidades/intervalos, supuestos, restricciones, disponibilidad, costos de acción, valor bruto esperado, contribución estimada, estimación incremental **solo si hay evidencia**, score/ranking con versión de fórmula, incertidumbre, explicación verificable, política, prioridad, propietario, estado, expiración, siguiente paso y seguimiento outcome. Persistir inputs y parámetros de cálculo necesarios para reproducir la decisión sin filtrar PII.

`valor esperado de pedido = probabilidad compra × contribución condicionada`, solo bajo suposiciones claras sobre número de compras y confiabilidad de costos; **no implica ventas incrementales causadas por contacto**. Para priorización de *contacto*, cuando sea validable estimar uplift (`P(compra|contacto) - P(compra|sin contacto)`) y costo; sin evidencia, etiquetar como `oportunidad potencial, atribución no demostrada` y no venderla como revenue recuperado.

Workflow `suggested → assigned → reviewed → approved/rejected → in_progress → executed → measured → closed` (ajustar al dominio), con roles, expiración, revisiones concurrentes, idempotencia, aprobación para acciones sensibles, trazabilidad y reversión/compensación cuando esté permitido. Usuario/servicio nunca puede aprobar su propia acción si la segregación de funciones lo prohíbe. No escribir en ERP P0/P1; para fases futuras: allowlist de herramientas, dry-run, confirmación explícita, deduplicación, límites, revalidación al ejecutar, auditoría y rollback.

---

## 12. OUTCOMES, EXPERIMENTACIÓN Y ROI

`PredictilyOutcomeMeasurementService` une recomendación → tarea → decisión → acción → evento observable posterior con IDs, fechas y fuente; distingue previsto, observado, asociado temporalmente, incremental estimado y atribuible causalmente. Registrar muestras no contactadas, acciones rechazadas y oportunidades que caducaron, no solo éxitos.

Métricas: oportunidades identificadas/atendidas; tasa de acción; tasa de respuesta; ventas posteriores; margen de contribución conocido, costos de contacto, ahorro comprobado, stockouts, exceso, rotación, capital inmovilizado, tiempo de trabajo, coste por tenant, frecuencia y calidad de datos; desglosar por segmento, horizonte y responsable. ROI no debe computarse con ingresos brutos si interesa contribución; incluir costos de uso/implantación relevantes, intervalos/limitaciones.

Preparar pilotos A/B o grupo de control (p.ej. asignación por clientes con reglas de negocio), análisis por intención de tratamiento y cuasiexperimentos documentados cuando aleatorización no sea viable; evitar contaminación entre vendedores/clientes y sesgo de selección de casos más fáciles. Cuando no haya causalidad demostrable, reportar *observación* y oportunidad, no *beneficio atribuible*. No divulgar resultados económicos ficticios como reales.

---

## 13. INTELLIGENCE GATEWAY, AGENTES Y WHATSAPP (P2 NO BLOQUEANTE)

`PredictilyIntelligenceGateway`: proveedores API y/o runtimes privados compatibles, tool calling tipado y allowlist (`get_forecasts`, `get_opportunities`, `get_inventory_risks`, `explain_recommendation`, `get_outcomes`, etc), autorización por tenant+usuario en **cada tool**, rate/compute limits, auditoría minimizada, aislamiento de contexto, fallbacks y desactivación por contrato. LLM interpreta y explica, no ejecuta SQL libre ni modifica registros por razonamiento autónomo. Defender contra prompt injection en datos importados y resultados de herramientas; salidas numéricas siempre provenientes de servicios de negocio verificables.

Futuro WhatsApp Business Platform: webhook verificable, vinculación/identidad de gerente o usuario empresarial, auth y permisos, catálogo limitado de herramientas, notificaciones autorizadas, consentimiento, minimización, retención y confirmación explícita antes de actuaciones sensibles. Agentes de integración (descubrir/proponer mapa), análisis ejecutivo y ejecución supervisada; no permitir que un agente descubra/ejecute SQL arbitrario en ERP ni se autentique por mensajes no verificados.

---

## 14. AWS DESDE EL DÍA 1, CON COSTO CONTROLADO

Conservar implementaciones verificadas y elegir IaC existente (Terraform o AWS CDK). Diseño objetivo: frontend Vue 3 en S3+CloudFront/ACM cuando sea actual; Laravel API/admin en ECS/Fargate/ALB si corresponde; ML FastAPI/worker Python; RDS PostgreSQL privado; S3 versionado/cifrado para Bronze/Silver/Gold y artefactos; SQS+DLQ; EventBridge Scheduler; ECS tasks efímeras para heavy jobs; IAM/Secrets Manager/KMS/VPC/Security Groups/TLS; CloudWatch, alertas, AWS Budgets; CI/CD y entornos local/staging/prod, despliegue y rollback. No mantener GPU AWS permanente. Considerar ECS/Fargate, RDS, ALB, NAT Gateways y endpoints privados según coste real y necesidades: documentar arquitectura y exposición, no optimizar precio debilitando seguridad.

Segregar identidades, cuentas/roles y secretos entre ambientes. Producción puede arrancar Single-AZ si se reconoce la limitación de disponibilidad y el riesgo comercial; definir RTO/RPO deseados, backups, restauración y criterio para Multi-AZ. `cost_per_tenant`: ETL, almacenamiento, inferencia, reentrenamiento, APIs, LLM, soporte estimado. Nunca compartir innecesariamente datos reales con staging. Definir healthchecks, deploy preflight, DLQ monitor, crash recover, idempotencia y quotas.

---

## 15. SEGURIDAD/PRIVACIDAD COMO RELEASE BLOCKER

Aislamiento tenant en: autorización del API, queries SQL/repositorios, servicios, colas, S3/presigned URLs, model registry, cachés, Bridge/dispositivos, logs, ML, dashboards, exports, backups, herramientas LLM y WhatsApp. Roles mínimos (propietario Predictily, administrador tenant, gerente, vendedor, analista, servicio/Bridge) con MFA para privilegiados, sesiones, revocación y segregación de funciones. Considerar RLS PostgreSQL como defensa adicional donde viable sin sustituir validación de aplicación. Autenticación de servicios, IAM least privilege, claves KMS cuando corresponda, cifrado, rotación, TLS, validaciones de payload, SQL injection, IDOR/BOLA, mass assignment, SSRF en conectores, carga maliciosa, ZIP bomb, rate limits, CSRF/XSS según superficie, dependencias y supply chain.

No almacenar PII en logs, training manifests, herramientas o prompts LLM si no es imprescindible. Contratos de tratamiento de datos y retención/borrado por tenant, controles de autorización explícita para extracción local y endpoints de auditoría. Operador SaaS no obtiene por defecto acceso irrestricto a la información de empresas. Pruebas negativas cross-tenant incluyendo colas, cache, jobs concurrencia, S3, endpoints, publicación de modelos y exportaciones. Backups cifrados y **restauración probada**. Incidentes: detección, acceso de emergencia registrado, respuesta, notificación contractual, revocación y recuperación.

---

## 16. UI / UX Y PLANES COMERCIALES

Dashboard **Executive Overview** (riesgos/opportunities, forecast, capacidad/stock, acciones e outcomes), **Revenue Radar** (prioridades por cliente-producto, ventana, probabilidad, margen esperado, inventario, próxima acción), **Demand Intelligence**, **Supply Intelligence/Radar de Reposición**, **Decision Center** (recomendaciones, aprobaciones, escenarios), **ROI & Outcomes**, **Administración** (tenants, usuarios, roles, conectores, calidad, modelos, entrenamiento, costos, contratos/auditoría). Claridad de fecha as-of, unidad, incertidumbre, cobertura y si el dato es real/simulado.

Conservar sistema Starter (USD 49/mes, 1 sucursal, ≤750 series SKU×sucursal, CSV/Excel, 7/30 días, alertas/forecast/reposición) y Growth (USD 119/mes, hasta 5 sucursales/5.000 series, Bridge si compatible, 7/15/30, Customer/Radar Comercial si IDs confiables, agenda, comparación, API, usuarios/roles). Son hipótesis antiguas configurables, no core de estrategia enterprise. Enterprise añade setup fee, mensualidad, módulos, API/series/usuarios/sucursales, frecuencias, retención, conectores, procesamiento privado, SLA, soporte, límites de cómputo y LLM. Configurar **per tenant**, sin hardcoding ni pasarela de pagos compleja obligatoria para MVP. Medir coste/margen operativo por cliente.

---

## 17. ONBOARDING ENTERPRISE Y OPERACIÓN DEL PROPIETARIO

Flujo repetible (sin alterar código por cliente): 1 contrato y permisos; 2 tenant/usuarios/roles; 3 origen y Bridge; 4 diagnóstico calidad e identificación de datos; 5 propuesta/aprobación de mapeos; 6 cargas históricas y conciliación; 7 features/requisitos habilitados; 8 particiones temporales y baseline; 9 entrenamiento local autorizado; 10 backtest/tuning/calibración/holdout; 11 revisión aprobación; 12 carga/cifrado/registro S3; 13 inferencia AWS; 14 pruebas aislamiento y latencia; 15 costos y reglas comerciales; 16 Revenue Radar y Supply; 17 simulaciones/decisiones; 18 responsables y capacitación; 19 shadow mode/piloto; 20 seguimiento de outcomes y ajuste.

**Agente implementador**: código, infra, pruebas, scripts CLI, documentación/runbooks, migraciones, fixtures sintéticas identificadas, guardrails y herramientas; nunca ejecutar acciones empresariales sin consentimiento. **Propietario Predictily**: cuenta y presupuesto AWS, dominios/credenciales, autorización de acceso, acuerdos, obtención de datasets autorizados, revisión y aprobación de mapeos/reglas/modelos, soporte piloto, incidentes/contratos. **Cliente**: validar definiciones comerciales, responsables, inventario y outcomes reales, revisar recomendaciones. Documentar operación automatizada vs manual y comandos exactos, sin modificar código por tenant.

---

## 18. MATRIZ DE PRUEBAS EXIGIBLES EN CI/STAGING/PILOTO

### T01 — Fuentes e integridad
- CSV/Excel, SQL Server/SQLite reales para al menos una fuente SQL según acceso disponible; driver exacto probado; API mock etiquetada mock (no confundir con conector real). Duplicados, anulación, nota de crédito, devolución, cancelación y retraso deben generar totales canónicos correctos.
- Importar lote dos veces: mismo conteo neto; reintentos fuera de orden; edición histórica; watermark/offset; esquema cambiado; disconnect/reconnect; gran volumen; claves erróneas; idempotencia y DLQ.
- Reconciliación numérica origen→Bronze→Silver→Gold con controles y excepciones documentadas. Fechas ausentes != días cero; unidades distintas no se suman.

### T02 — Feature store y temporalidad
- Reproducibilidad `dataset_fingerprint+cutoff+features_version`; reconstruir features *as-of* de una fecha pasada y comprobar que no incluyen hechos posteriores.
- Test adversarial: precio de mañana, documento cargado tardíamente, climatología publicada después, descuento futuro, estado anulado posteriormente → prohibidos como feature disponible en fecha pasada.

### T03 — Demand
- Baselines vs ML por ventanas temporales, por SKU/grupo, horizontes 7/15/30; serie constante/irregular/cero, nuevo SKU, varios picos, stockout, ruptura estacional, promociones. WAPE/MAE/MASE/bias y cobertura/intervalos reportados. Sumas jerárquicas y reconciliación demanda total vs por cliente.

### T04 — Customer
- Calibración Brier/reliability, lift@K vs baseline, cantidad condicionada, próxima ventana, observaciones censuradas, cliente con cambios temporales de precio, inactividad, IDs no confiables (degrada a agregado). No fuga temporal; no certeza artificial. Caso cubetas de huevo 9–15/5–7 y 6–10/3–5 como *fixture* (no validación real).

### T05 — Training/Registry
- Entrenar local sin GPU obligatorio; validar/firmar/cargar; champion/challenger, publicar solo aprobado, inferencia usando `tenant_id` autorizado, rollback bajo fallo, proceso continúa con champion; mismatch esquema/features prohibido; artefactos maliciosos/alterados rechazados.

### T06 — Supply/Decision
- Costo/margen correctos; múltiples de empaque, MOQ, lead time, inventario reservado, pendientes, stockouts y perecibilidad; no recomendar cantidades negativas o comprar sin presupuesto; mismo input produce mismo ranking/recomendación y explicación trazable; no doble conteo de pedidos confirmados.

### T07 — Outcomes/causalidad
- Compra posterior no produce automáticamente atribución causal; recomendaciones ejecutadas/no ejecutadas, outcomes tardíos, retornos/reversiones, costos, grupo control cuando disponible. Dashboard separa oportunidad, observación y beneficio incremental estimado.

### T08 — Seguridad tenant
- Tenant A no lee/escribe datos, modelo, predicciones, recomendaciones, registros, logs, caché, S3, URLs, colas ni exports de B; incluso concurrente. Revocación Bridge, expiración de URL, RBAC/MFA privilegiado, SQLi/IDOR/SSRF, replay/webhook si se usa y rate limiting.

### T09 — AWS/operación
- IaC plan/apply a staging autorizada; health checks; crash/retry/DLQ, monitoreo, backups y restauración de muestra, fallo de proveedor, despliegue rollback, alertas de costo y presupuesto, desconexión Bridge, degradación esperada. Medición de latencia, consumo, coste por tenant e inferencias simultáneas; establecer SLO reales con datos del piloto, no inventar SLA universal.

### T10 — E2E y prueba real
1. Crear 2 tenants para prueba de aislamiento; uno piloto autorizado con datos reales (al menos un origen SQL y uno CSV/Excel cuando los permisos lo permitan).
2. Importar históricos y reconciliar ventas netas, clientes, SKU y fechas; snapshot dataset con cutoff.
3. Ejecutar evaluación temporal, baseline, candidates, tuning opcional, holdout independiente y calibración; producir reporte firmado de métricas y calidad.
4. Cargar/registrar/aprobar modelo privado; AWS carga modelo correcto; mismo modelo ofrece predicciones con versión y fecha de corte.
5. Revenue Radar detecta al menos una oportunidad **con datos reales**, genera recomendación reproducible y asociada a inventario/costo (si existen).
6. Un usuario autorizado aprueba/rechaza/asigna; quedan auditoría y prohibición de escritura sensible sin permiso.
7. Observación posterior real o seguimiento manual documentado; evaluar outcome sin atribución indebida.
8. Repetir ante data quality fail, rollback y cambio tenant; documentar costo y rendimiento.

**No confundir capacidad técnica de completar el flujo con evidencia de precisión/ROI alto.** Éxito operacional puede estar aprobado y éxito estadístico/económico pendiente de una ventana prospectiva.

---

## 19. CRITERIOS DE SALIDA: CORE MEDIANAMENTE MADURO ≠ MODELO PERFECTO

### Gate A — Integridad de datos (bloqueante)
- [ ] Fuente SQL real + CSV/Excel probados; sincronización, idempotencia, conciliación, lineage, manejo de notas/anulaciones y calidad/cortes as-of correctos.
- [ ] Esquemas versionados, test de leakage y trazabilidad; falta de datos activa degradación/bloqueo, no inventa.

### Gate B — Calidad ML (bloqueante para piloto predictivo)
- [ ] Demand Core produce predicciones 7/15/30 y comparaciones baselines con backtesting sin leakage, holdout separado y métricas por segmento/horizonte.
- [ ] Customer Core devuelve probabilidad calibrada (o explícito *no estimable*), ventana y cantidad condicionada; utilidad de ranking evaluada vs baseline.
- [ ] Falta de stock, series intermitentes, cliente nuevo y total vs individual tratados explícitamente; reproducibilidad y drift monitoring.

### Gate C — Seguridad/ML Ops (bloqueante)
- [ ] Tenants A/B aislados a través de API, S3, modelos, caché, jobs y export; RBAC, revocación, secreto/cifrado y backups restaurables comprobados.
- [ ] CLI local + Registry + publicación aprobada a AWS + inferencia por tenant + rollback y artefactos verificables.

### Gate D — Flujo empresarial (bloqueante para Enterprise P1)
- [ ] Opportunity Radar, Supply constraints, Decision Engine económico reproducible, asignación/aprobación, outcome posterior trazado y ROI honesto.
- [ ] Dashboard funcional, onboarding repetible, costos por tenant y runbooks/observabilidad básicos.

### Gate E — Piloto prospectivo (resultado se informa, no se finge)
- [ ] Registrar con cliente duración, objetivos y unidad de evaluación; ventana de observación posterior suficiente para el ciclo de recompra, sin exigir cifras mágicas; contra baseline y/o grupo control.
- [ ] Comparar pronósticos y decisiones en período no utilizado para entrenar; reporte de calibración, pérdidas/beneficio, errores, latencia, costos, participación de vendedores y principales fallos.
- [ ] Decisión explícita GO / GO-CONDICIONAL / NO-GO con responsables y evidencia. Si precisión insuficiente, no ocultarlo tras un dashboard.

La fecha del piloto y los umbrales económicos se fijan con el cliente, en función de historia, estacionalidad, costo y tolerancia a error. Proponer objetivos cuantitativos y razones; **no inventar WAPE objetivo universal ni garantía de ingresos**.

---

## 20. PRIORIZACIÓN CON DEPENDENCIAS

**P0 Core Verified:** auditoría y trazabilidad, seguridad tenant, fuentes CSV+SQL, canonical model, Bridge operativo mínimo, Quality Engine, reconstrucción temporal, Demand baseline+ML y backtest, Customer repurchase + cantidad + ventana, Registry/CLI local/private publish/serving AWS, Revenue Radar inicial, testeabilidad y costo. No bloquearlo por LLM.

**P1 Enterprise Pilot:** Decision Engine, Supply constraints, approvals/tasks, Outcome/ROI, dashboard ejecutivo, piloto real, métricas por segmento, controles operativos, onboarding repetible y reportes. Para llamar Enterprise vendible se requiere completar P0 **y** P1 pertinentes al contrato.

**P2:** Factor Intelligence completo (fuentes externas verificadas, ablation por sector, alertas, taxonomía avanzada), tuning ampliado/AutoML, simulaciones complejas, señales económicas/clima/competencia/sanidad/mercados, conectores adicionales, agentes/LLM/WhatsApp supervisado, automatización de entrenamiento si viable, API avanzada, SLO/SLA ampliados. **No degradar los metadatos temporales de factores ni eliminar los adaptadores ya existentes aunque no se activen en P0.**

**P3:** Private Edge, Manufacturing/Industry, Agro y Export operativos con contratos y pruebas sectoriales de V2/V2.1; intercambio de pronósticos entre empresas explícitamente autorizadas y con reconciliación de demanda, planificación productiva, red de proveedores y ejecución supervisada avanzada.

Retener componentes P2/P3 funcionales existentes, sin imponerlos como condición para la primera demo.

---

## 21. DOCUMENTACIÓN Y ENTREGABLES EXIGIDOS

Actualizar/crear (reutilizar docs previos cuando existan y evitar duplicados inconsistentes):

- `docs/roadmap/V25_SOURCE_TRACEABILITY.md`, `V25_GAP_ANALYSIS.md`, `V25_IMPLEMENTATION_PLAN.md`, `V25_RELEASE_GATES.md`, `V25_RISK_REGISTER.md`.
- `docs/architecture/ENTERPRISE_ARCHITECTURE.md`, `AWS_ARCHITECTURE.md`, `AWS_COST_MODEL.md`, `TENANT_ISOLATION.md`, ADRs.
- `docs/data/CANONICAL_CONTRACTS.md`, `BRONZE_SILVER_GOLD.md`, `DATA_QUALITY_ENGINE.md`, `TEMPORAL_AVAILABILITY.md`, `LINEAGE_AND_RECONCILIATION.md`, `FEATURE_CONTRACTS.md`.
- **Legado V2.1 obligatorio:** `docs/intelligence/FACTOR_INTELLIGENCE.md`, `PRODUCT_TAXONOMY.md`, `EXTERNAL_SOURCES.md`, `SECTOR_PROFILES.md`; `docs/ml/FEATURE_SELECTION.md`, `POINT_IN_TIME_VALIDATION.md`.
- `docs/integrations/CONNECTOR_FRAMEWORK.md`, `BRIDGE_WINDOWS.md`, `ERP_COMPATIBILITY.md`, `AUTO_MAPPING.md`, `ENTERPRISE_ONBOARDING.md`.
- `docs/ml/FORECAST_VALIDATION.md`, `FORECAST_SELECTION.md`, `CUSTOMER_REPURCHASE.md`, `CALIBRATION_AND_RANKING.md`, `PRIVATE_TENANT_TRAINING.md`, `AUTOMATED_TRAINING.md`, `MODEL_REGISTRY.md`, `MODEL_DEPLOYMENT.md`.
- `docs/decision/DECISION_ENGINE.md`, `ECONOMIC_PRIORITIZATION.md`, `OUTCOME_MEASUREMENT.md`, `CONTROLLED_ACTIONS.md`.
- `docs/security/ENTERPRISE_SECURITY.md`; `docs/operations/OWNER_RESPONSIBILITIES.md`, `TENANT_TRAINING_RUNBOOK.md`, `AWS_DEPLOYMENT.md`, `RECOVERY.md`, `PILOT_RUNBOOK.md`.
- `docs/product/RETAIL_STARTER_GROWTH.md`, `RETAIL_MVP_ACCEPTANCE.md`, `ENTERPRISE_MVP_ACCEPTANCE.md`; `reports/V25_PILOT_BASELINE.md`, `V25_PROSPECTIVE_VALIDATION.md`.

Cada doc debe distinguir: IMPLEMENTADO/VERIFICADO, DISEÑADO/NO IMPLEMENTADO, BLOQUEADO, NO APLICA. Adjuntar comandos exactos (reales), tests y resultados. Generar una tabla final por módulo con evidencia, test status, costo, riesgo y aprobación.

---

## 22. CHECKLIST OPERATIVO POR CLIENTE (EL PROPIETARIO)

- [ ] Contrato, uso previsto, autorización de tratamiento y responsable de datos; límites/retención, costos y soporte.
- [ ] Datos disponibles y permisos de solo lectura; validación de fuente/identidad cliente/SKU/sucursal y monedas/unidades.
- [ ] Mapeos revisados y versión; reconcile totales netos y anulaciones; calidad/historia suficiente y funciones que deben degradarse.
- [ ] Definir KPI comercial (p.ej. precisión 7 días, top-K oportunidades, beneficio de contribución, tiempo para contactar, margen, agotamientos) sin fijar objetivos engañosos.
- [ ] Definir restricciones: stock, costos confiables, mínimos, capital, precios, lead time, calendario, responsables y acciones permitidas.
- [ ] Aprobar manifest de dataset, punto de corte, pipeline/modelo, backtest/holdout; ejecutar CLI local autorizado si aplica.
- [ ] Confirmar checksum, carga a S3, versión aprobada, ejecución AWS y aislamiento; no copiar información del cliente a otros tenants.
- [ ] Configurar usuarios/roles, dashboards y plan; activar pruebas de acción con revisión humana.
- [ ] Fijar ventana de piloto y grupo comparador; recoger feedback y outcomes, registrar fallos y pérdidas, decidir GO/NO-GO.
- [ ] Revisar costes reales de AWS por tenant y rentabilidad del contrato; actualizar documentación y política de reentrenamiento.

---

## 23. ORDEN DE TRABAJO Y DEFINICIÓN DE FINALIZACIÓN

1. Entrega primero un **inventario de requisitos** proveniente de cada versión original que esté realmente accesible y una matriz explícita de fuentes no recuperadas. Ninguna nueva modificación sin saber lo que ya existe.
2. Audita, ejecuta tests y fotografía métricas de baseline actual (incluidas políticas legacy); clasifica bloqueos/defectos por severidad.
3. Prioriza corrección de datos/as-of/leakage/idempotencia/aislamiento antes de mejorar dashboards o incorporar LLM.
4. Implementa por slices verticales funcionales, con migración aditiva, pruebas y logs reproducibles al final de cada slice; no declarar completo un paquete a medias.
5. Construye y ejecuta E2E en staging autorizado (o registra bloqueo de credenciales/infraestructura) con 2 tenants, una fuente SQL y CSV, entrenamiento validado, Registry/S3, inferencia AWS, Radar/Decision/Outcome.
6. Produce informes de métricas **reales** sobre holdout y, cuando haya horizonte suficiente, evaluación prospectiva. No sustituir datos reales por fakes silenciosos.
7. Publica `V25_RELEASE_GATES.md` con GO / GO CONDICIONAL / NO-GO; para cada NO-GO registra prueba fallida, error, riesgo y plan de remediación. Entrega un changelog de lo **hecho** y de lo **pendiente**, no de lo deseado.

**INSTRUCCIÓN FINAL: Conserva todas las capacidades existentes verificadas de Predictily original/V1/V2/V2.1/V2.2/V2.3/V2.4/V2.4 Enterprise. Si una versión fuente no está disponible, no asegures que la validaste: haz inventario y busca evidencias en el repositorio. En V2.5 consigue primero un core de datos y forecasting fiable, una recompra cliente-producto calibrada, aislamiento privado de modelos y un circuito reproducible de oportunidad→decisión→acción→resultado sobre AWS, sin confundir precisión técnica con causalidad económica. No termines ni declares listo P0/P1 con gates obligatorios fallidos; si falta una dependencia externa o datos futuros, informa el bloqueo y entrega todo lo ejecutable restante.**


---

## 24. REQUISITOS V2.1 ÍNTEGROS — FACTOR INTELLIGENCE EN DATA CORE

**Estado de fuente:** V2.1 fue proporcionada íntegramente por el propietario. Esta sección es un requisito heredado, no una invención V2.5 ni una sustitución por un nuevo core. El motor será transversal, **dentro de Data Core**, sin afectar la independencia de Demand, Customer y Supply. Las extensiones son progresivas: `P0 = contratos/temporalidad/seguridad + factor pipeline desactivable`; `P1 = piloto de factores internos y un factor externo solo si es válido, con comparación real`; `P2 = familias/sectores/conectores ampliados y pruebas sectoriales`. La ausencia de proveedor externo o LLM **nunca** paralizará el camino predictivo base.

### 24.1 Auditoría de V2.1 antes de cambiar código

Inspeccionar la clasificación actual de productos y reglas sectoriales, features que usa Demand Core, generación y versionado de características, fuentes externas, trazabilidad, backtesting point-in-time, variables futuras y contratos entre cores. No construir un segundo Feature Store ni un segundo LLM Gateway si el existente cubre la interfaz. Documentar divergencias por clase, API, migración y prueba.

### 24.2 Taxonomía y contexto de producto

Modelo jerárquico versionado `Sector → Industria → Categoría → Subcategoría → Producto → SKU`, con relación a categorías ERP existentes, alias, historial de cambios, unidades/presentaciones, regiones, mercados, certificaciones verificadas, atributos y usos. **No sustituir el identificador canónico de SKU con taxonomías no verificadas.** Permitir uno o varios perfiles de relevancia por producto, con reglas de prioridad y ámbito de tenant; un fabricante de bebidas, uno de productos sin gluten y uno de insumos agrícolas usan factores distintos. La propuesta de clasificación por LLM es candidata con procedencia y revisión, no verdad oficial.

### 24.3 Servicios mínimos o adaptadores equivalentes

Preservar/implementar con interfaces y DI testeables: `ProductContextResolver`, `ExternalSourceRegistry`, `ExternalDataConnector`, `EventExtractor`, `EventVerifier`, `FactorApplicabilityEngine`, `FeatureTransformer`, `FeatureAvailabilityValidator`, `FeatureSelectionEvaluator`, `FactorAuditService`. Se permite consolidar clases cuando una misma implementación satisfaga contratos sin perder trazabilidad. Definir esquemas `SectorProfile`, `FactorDefinition`, `FactorObservation`, `ExternalEvent`, `SourceVersion`, `FactorApplicabilityRule`, `FactorEvaluation`, `SharedForecastGrant`. Reutilizar `PredictilyIntelligenceGateway` para LLM, sin crear proveedor duplicado.

### 24.4 Familias de factores heredadas, sin omisiones

Catalogar independientemente las siguientes 17 familias: (1) ventas históricas; (2) precios y descuentos; (3) competencia; (4) tendencias de búsqueda; (5) preferencias del consumidor; (6) clima observado y pronosticado; (7) epidemiología; (8) sanidad animal/vegetal; (9) alertas de inocuidad; (10) restricciones comerciales/regulatorias; (11) estadísticas de exportación; (12) pedidos/contratos; (13) proyecciones compartidas con autorización; (14) estimaciones de producción; (15) reseñas/satisfacción; (16) capacidad e inventario; (17) calendarios/eventos y feriados heredados de V2. De cada una: esquema, fuente/versión, licencia/permisos, frecuencia, unidad, cobertura geográfica, identificadores, calidad/frescura, estrategia de ausencia, retención, justificación sectorial y fecha en que era conocida. No activar todas por defecto.

### 24.5 Disponibilidad de cada variable y revisión de datos

Clasificar cada valor como `OBSERVED_HISTORICAL`, `STATIC`, `KNOWN_IN_ADVANCE`, `THIRD_PARTY_FORECAST`, `USER_SCENARIO`, `UNAVAILABLE_FOR_HORIZON`. Registrar **fecha de evento**, **fecha de observación**, **fecha de publicación**, **fecha de disponibilidad real para el sistema**, **vigencia**, **emisión/issue time del forecast externo**, **versión/revisión de fuente**, ámbito geográfico, licencia y caducidad. `FeatureAvailabilityValidator` reconstruye el valor visible en el instante `cutoff` de un experimento, incluso si hoy existe una revisión más reciente. Escenarios se almacenan aparte, etiquetados y no contaminan la medición de precisión. Si el histórico de emisiones de un proveedor no está disponible, prohibir un backtest que use su pronóstico publicado posteriormente como si hubiese existido; usar histórico archivado real o desactivar el factor/limitar la conclusión.

### 24.6 LLM para contexto y extracción, jamás como fuente financiera

La V2.1 propone compatibilidad inicial con **Ollama Cloud** mediante API configurable; conservar este adaptador como opción, junto con otros proveedores o runtimes privados, sin acoplar el core a él. El LLM podrá clasificar productos, proponer factores, interpretar noticias/documentos, extraer eventos estructurados con JSON Schema, relacionar alertas con cultivo/producto/región, resumir reseñas y explicar decisiones. Toda salida de evento incluye URL/ID de fuente, autor/organismo, hora de publicación, fecha de vigencia, jurisdicción, nivel de corroboración y estado `PROPOSED/VERIFIED/REJECTED/EXPIRED`. No inventar valores, alertas oficiales, certificaciones, sanciones ni probabilidades. Validar esquema, confianza calibrada si corresponde, allowlist de herramientas, licencias, permisos y verificación humana para eventos sensibles. No enviar PII, contratos o registros de una empresa a proveedores externos sin autorización.

### 24.7 Relevancia y ablation temporal

Ejecutar comparaciones por `tenant × segmento × horizonte × cutoff`:
1. baseline sin factores externos;
2. una familia nueva a la vez (ablation);
3. combinaciones justificadas;
4. candidato final en validación temporal;
5. holdout independiente **no usado** en búsqueda de variables o hiperparámetros.

Reportar WAPE/MAE/MASE o RMSSE, sesgo, cobertura/calibración, estabilidad por horizonte y segmento, costo API/cómputo, fuentes caídas y métricas relevantes para decisiones. Controlar comparaciones múltiples, poca muestra y cambios de distribución; no promover factores con mejora aparente sin repetición. **Regla crítica V2.1:** alertas regulatorias, epidemiológicas, sanitarias y de seguridad deben conservarse como alertas informativas verificadas aun si no mejoran el error numérico. No asumir causalidad por correlación o reseñas.

### 24.8 Competencia y reseñas

Identificar equivalencia entre productos con identidad/criterios explicables; normalizar precios por unidad, impuestos, descuentos, moneda, región, canal y periodo. Registrar fuentes, vigencia, cobertura y calidad. Agrupar reseñas por tema/sentimiento con niveles de incertidumbre, evitar sesgo por muestra y no atribuir causalidad automáticamente. Cumplir permisos y condiciones de acceso a datos; no hacer scraping o extracción prohibida.

### 24.9 Resiliencia y aislamiento

Cuando una API externa falle: timeouts, circuit breaker/backoff, cache de último dato válido **con etiqueta de antigüedad** y expiración, fallback a modelo sin factor o resultado `UNAVAILABLE`, aviso/auditoría y medición de impacto. Nunca imputar silenciosamente información no disponible como si fuera una medición actual. Aislar por tenant las consultas privadas, eventos derivados, features, permisos y artefactos. Fuentes públicas pueden compartir definiciones de adaptadores, pero **no datos privados entre empresas**. Versionar costos y tasas de llamadas para evitar facturas imprevistas.

### 24.10 Contratos entre empresas autorizadas (V2.1 §10)

`SharedForecastGrant` tiene emisor, receptor autorizado, producto/categoría, periodo, granularidad, contenido permitido, versión de forecast, emitido/expira, propósito, revocación y registro de accesos. Predicciones compartidas no constituyen pedidos confirmados. Reconciliar proyección retailer + pedidos efectivamente confirmados + pronóstico fabricante, evitando sumas dobles de demanda. No habilitar la red colaborativa en P0, pero **mantener el contrato diseñado y pruebas negativas de aislamiento**.

### 24.11 Perfiles sectoriales V2.1

- **Manufacturing:** ventas a distribuidores, demanda retail autorizada, capacidad, materias primas, tendencias y riesgos por producto.
- **Retail:** venta final, promociones, inventario, competencia, clima, tendencias y perfiles de compradores si existen.
- **Agro:** diferenciar oferta agrícola (siembra, rendimiento, cosecha, plagas, clima) de demanda comercial y demanda autorizada de fabricantes/exportadores.
- **Export:** compradores, pedidos, destinos, países, presentaciones, estadísticas, regulaciones, oferta de proveedores, precios internacionales y restricciones.

### 24.12 Pruebas de V2.1 (P2 para integración sectorial; P0 para temporalidad/seguridad)

Fixtures y/o pilotos verificables, por separado, de: fabricante de agua embotellada; fabricante de alimentos sin gluten; fabricante de insumos agrícolas; retail multisucursal; productor agrícola; exportadora de camarón. Validar taxonomía/perfil, factores aplicables/no aplicables, disponibilidad temporal, event provenance, APIs caídas, costos, seguridad, mejora real vs baseline y reconciliación sin dobles. Los tests sectoriales P2 **no deben declararse aprobados** solo por contar con fixtures sintéticas; incluir integración y datos autorizados cuando sea parte de la promesa comercial.

### 24.13 Entregables específicos heredados

Conservar/crear `docs/intelligence/FACTOR_INTELLIGENCE.md`, `PRODUCT_TAXONOMY.md`, `EXTERNAL_SOURCES.md`, `SECTOR_PROFILES.md`, `docs/ml/FEATURE_SELECTION.md`, `POINT_IN_TIME_VALIDATION.md`, tests con as-of y scripts de ablation. Reproducir reportes que demuestren mejora/ausencia de mejora de factores sin inventar porcentajes.

---

## 25. REQUISITOS HEREDADOS DE V2 Y V2.2 QUE NO PUEDEN PERDERSE

### 25.1 Horizontes, granularidades y reportes: resolver diferencia 14 vs 15

V2 define pronósticos por empresa, sucursal, almacén, producto, categoría, mercado, región, día/semana/mes/temporada y horizontes **7, 14, 30, 60 y 90 días**; V2.2 define recompra **7, 14, 30 y 90**; V2.3/Enterprise priorizan **7, 15 y 30**. **Resolución: 7/15/30 por defecto en Enterprise y catálogo parametrizado que soporte 7/14/15/30/60/90** cuando el dataset/objetivo lo permitan. Si una frecuencia/horizonte no tiene información suficiente, indicar `INSUFFICIENT_DATA` y no fabricar un resultado. API y UI muestran horizonte explícito, fecha as-of, unidades, agregación, revisión y modelo.

### 25.2 Customer Core V2/V2.2: distinguir probabilidad, número de pedidos y cantidad

Requerir: `P(≥1 pedido en horizonte)`, `E(nº pedidos en horizonte)`, `E(cantidad por pedido | ocurre)`, `E(unidades totales)`, `E(importe bajo escenario de precio)`, ventana próxima compra, riesgo inactividad, cambio de productos, recencia/frecuencia/valor, canal, segmento, contratos y pedidos. Modelos candidatos condicionados a datos: baseline frecuencia/recencia, BG/NBD solo si supuestos de relación comprador-recompra se cumplen, clasificación y cantidad LightGBM, supervivencia, ranking de SKU/categoría y modelos globales **dentro del tenant**. Métricas: PR-AUC, Brier, log-loss, Precision@K, Recall@K, MAE/WAPE por cantidad, error de ventana hasta recompra, calibración por segmento/horizonte, estabilidad temporal y evaluación de sesgo.

No calcular `P(compra) × cantidad por pedido` para una ventana que permita varios pedidos sin modelar la frecuencia; no tratar cada SKU como cliente distinto; no asignar automáticamente abandono por un único retraso. Reconciliar agregados, demanda comprometida, ventas ya realizadas, demanda residual y nuevos clientes. Si los IDs son inestables, deshabilitar recomendaciones individualizadas y conservar Demand Core general.

### 25.3 Agenda Comercial V2.2 y contratos API

Conservar `PredictiveSalesAgenda`/`CommercialOpportunityService` como servicios reutilizables o interfaces equivalentes. Filtros: vendedor, cliente, segmento, zona, SKU, categoría, fecha/horizonte, estado. Estados legados: **Pendiente, Contactado, Interesado, Pedido recibido, Pospuesto, Descartado**; mapearlos en el workflow Enterprise sin perder historial. Mantener ficha cliente con compras, frecuencia, SKU habituales, evolución, ventana futura y seguimiento. Permitir feedback auditable `POST /api/v1/radar/opportunities/{id}/feedback` y conservar/adaptar contratos V2.2: `GET /api/v1/radar/summary`, `/opportunities`, `/agenda`, `/api/v1/customers/{id}/predictions`, `/demand`, `/products`, `GET /api/v1/demand/forecasts`, `/api/v1/supply/readiness`, `/api/v1/models/evaluations`. Si hay otros endpoints equivalentes, **no crear duplicados**: versionar y mantener adaptadores compatibles/OpenAPI.

No enviar mensajes WhatsApp/correos automáticamente en MVP sin permiso; dejar solo contrato de mensajería y confirmación humana.

### 25.4 Calendarios y Feature Pipeline V2

Servicio unificado reusable de feriados nacionales/locales, fechas de pago, fines de semana, campañas, promociones, temporadas comerciales/agrícolas, eventos sectoriales, condiciones extraordinarias y periodos de demanda internacional; ámbito país/región/empresa/sucursal/sector/producto. Features internas: lags, sumas, medias, desviaciones móviles, recencia, frecuencia, precios/costos/stock historial, descuento, day-of-week, mes, feriados y cambios de comportamiento. Cada evento/feature incluye inicio-fin, fecha conocida y versión; no introducir calendarios conocidos solamente a posteriori.

### 25.5 Supply Core V2 — optimización con restricciones y evaluación

Fase base: posición de inventario, demanda durante lead time, seguridad, punto reorden, fecha/cantidad sugerida, tránsito, reservas, compra mínima, múltiplos, costo, presupuesto, capacidad almacenamiento, recepción y perecibilidad. Comparar proveedores y costos; distinguir demanda probabilística de pedido confirmado sin reservar el stock automáticamente. Simulación de inventario **cronológica as-of** y métricas de servicio, faltantes, exceso, rotación, días en inventario, almacenamiento, compras y capital. Preparar **Google OR-Tools** para optimización restringida P2 si supera reglas simples. Recomendaciones/órdenes reales requieren permisos/aprobación y se ejecutan solo mediante integraciones autorizadas.

### 25.6 Módulos Sectoriales V2 — conservar contratos específicos

**Retail:** SKU/sucursal, inventario multibodega, clasificación ABC/XYZ, rotación, sustitutos/complementarios, promociones, descuentos, calendario y recompra. Primer flujo sectorial comercial antiguo sigue disponible, pero el marketing Enterprise prioriza B2B.

**Export (camarón, banano, cacao, flores, etc.):** comprador/país/mercado, contrato y orden exportación, categoría/presentación/talla/calidad/grado, materia prima y producto terminado, rendimiento por proceso, lotes, inventario comprometido, capacidad, contenedores/embarques/zarpe, flete, Incoterms, FOB/CIF, divisas, costos, cotización y cambios de mercado. Conversiones kg/lb/cajas/toneladas precisas y versionadas; distinguir materia prima vs terminado. Flujo mínimo de Export: **demanda → pedidos confirmados → inventario → materia prima**; logística/puertos avanzado es expansión.

**Industry/Manufacturing:** BOM/listas materiales, insumos, terminados, rendimientos, órdenes producción, capacidad planta, tiempos, mermas, restricciones y necesidades de compras. No crear otro ERP.

**Agro:** cultivos, siembra/cosecha, rendimientos, centros de acopio, oferta estimada, clima/plagas, calidad/precios, temporadas, abastecimiento y clientes; **separar predicción de producción agrícola de demanda comercial**.
Los cuatro módulos heredan cores/identidad/seguridad y presentan interfaces extensionables. No borrar migraciones o módulos ya implementados aunque sean P3.

### 25.7 API/QA y aceptación V2

Mantener APIs OpenAPI versionadas, jobs pesados asíncronos, idempotencia y seguimiento de fallos. Predicciones son consultables por API, no solo dashboard. V2 exige pruebas con históricos multianuales, cutoff editable, división train/validation/test y resultados reproducibles; comparar baseline vs modelos (incluidos ETS, MLForecast, LightGBM y demanda intermitente). **Prueba mínima Export** se conserva como objetivo de módulo, no se declara completa por una pantalla. Mantener compatibilidad de V1 mediante pruebas de regresión cuando se recupere su especificación exacta.

---

## 26. MATRIZ NUEVA DE PRUEBAS ADICIONALES Y GATES EXPRESOS

### T11 — Factores externos, fuentes y tiempo

Test de `cutoff`: documento sanitario publicado después, pronóstico meteorológico emitido mañana pero registrado hoy, precio de competencia actualizado retroactivamente, noticia sin fecha, nueva revisión de estadísticas, API fallida y evento de campaña conocido por anticipado. Cada uno debe activar `AVAILABLE`, `NOT_YET_AVAILABLE`, `STALE`, `INVALID` o `SCENARIO_ONLY`. No usar conocimiento futuro. Demostrar con fixtures adversariales y un origen temporal real cuando se habiliten conectores P2.

### T12 — Ablation por familia y alerta no predictiva

Para una familia de factores, producir tabla baseline vs +factor vs combinaciones, costo, métricas por horizonte y holdout intacto; no promocionar factor sin evidencia. La alerta oficial verificada se muestra aunque no mejore WAPE, claramente separada de feature predictiva.

### T13 — Taxonomía, equivalencias y perfiles

Verificar `sector → industria → categoría → subcategoría → producto → SKU`, perfiles múltiples, clasificación equivocada propuesta por LLM (revisión), mapping SKU legacy, cambios códigos, unidades/precios equivalentes, disponibilidad regional, reglas por empresa, productos descontinuados y certificaciones no corroboradas.

### T14 — Escenarios y contratos entre empresas

Para shared forecast, probar destinatario, producto, fechas, granularidad, expiración, revocación, auditoría, intentos cross-tenant y no duplicación con pedidos confirmados. Prohibir exportar datos confidenciales sin consentimiento; conservar modelo para P3, gate de seguridad P0 aun cuando funcionalidad comercial no activada.

### T15 — Seis casos sectoriales explícitos de V2.1

Reproducir: agua embotellada, alimentos sin gluten, insumos agrícolas, retail multisucursal, productor agrícola y exportadora de camarón. Para cada uno documentar datos/fuentes, factores elegidos y excluidos, supuesto de disponibilidad, test de API caída, baseline con y sin factores, contratos intercore, métricas por horizonte, costos y controles de aislamiento. **Estas pruebas son gate de release de Factor Intelligence / sector correspondiente (P2/P3), no un requisito artificial para el primer piloto B2B P0/P1.**

### T16 — Contratos precisos de V2.2

API de Radar y Customer conserva comportamiento y autorizaciones; recompra a 7/14/30/90 cuando los datos bastan, cantidades con varios pedidos, estados de agenda, filtros, feedback y audit trail; transición a flujo Enterprise sin perder acciones previas.

### T17 — Conversión productiva V2

Producto exportado debe convertir unidades por presentación, mermas/rendimientos por proceso, producir requerimientos válidos de materia prima y no mezclar pesos terminado/bruto; mismo flujo para Retail/Industry/Agro en sus fixtures. Si no hay módulo productivo implementado, marcar `P3_PENDIENTE`, no declarar cobertura.

### Gates de madurez revisados

- **P0 piloto predictivo controlado:** T01–T05, T08 (aislamiento/seguridad), T10 (hasta inferencia/radar), T11 **en la parte de features internas y control temporal**, T13 **taxonomía básica SKU/categoría**, T16 **objetivos de recompra y endpoints existentes**. No depender de APIs externas ni seis pruebas sectoriales.
- **P1 piloto Enterprise:** P0 + T06/T07/T09/T10 completos, agenda/Decision/Outcome/ROI auditables, administración tenant, costos y runbooks. Una característica de Factor Intelligence solo se activa si supera T11/T12 para ese factor.
- **P2 Factor Intelligence ampliado:** T11–T15, permisos de proveedores y licencias, resiliencia, ablation por familia, evaluaciones por sector.
- **P3 Release vertical Industry/Agro/Export:** T17 y pruebas de integración de sistemas, datos y operaciones reales del sector.

**No se admite GO cuando hay fuga temporal, diferencia de ventas netas no explicada, mezcla tenant, modelo no aprobado, métricas fabricadas ni retorno atribuible no sustentado.**

---

## 27. RESOLUCIONES EXPLÍCITAS DE CONFLICTOS ENTRE VERSIONES

| Conflicto | Especificaciones históricas | Decisión V2.5 sin pérdida |
|---|---|---|
| Radar principal | V2.2 cliente B2B; V2.3 reposición retail; Enterprise Revenue | Revenue Radar B2B en lanzamiento; Radar Reposición sigue como vista/capacidad sectorial. |
| Horizontes | V2 = 7/14/30/60/90; V2.2 = 7/14/30/90; V2.3/Enterprise = 7/15/30 | Configurar todos 7/14/15/30/60/90; 7/15/30 defaults Enterprise; habilitar solo si histórico es suficiente. |
| Modelos compartidos | V2 menciona modelos globales/sectoriales; Enterprise exige no compartir aprendizaje privado entre tenants | Global **dentro de un mismo tenant**, o sectorial solo con datos autorizados. No combinar datos privados entre clientes sin autorización y diseño legal. |
| Prioridad de factores | V2.1 establece Factor Intelligence; V2.2/V2.3 ponen factores externos P2 | Contratos, origen y seguridad temporal en P0; integración externa/ablation sectorial P2 sin cancelar la extensión. |
| LLM | V2/V2.1 proveedor Ollama Cloud inicial; Enterprise gateway independiente | Adaptador compatible con Ollama Cloud opcional, interfaz multi-proveedor y herramientas tipadas; negocio funciona sin LLM. |
| Entrenamiento | V2 prevé modelos globales/per-company; Enterprise local privado y registro S3 por tenant | Reutilizar baselines y ajustar/entrenar local por tenant cuando lo justifique; almacenar artefactos/decisiones independientes y privados; publicar tras pruebas. |
| Ejecución | V2.2 sin WhatsApp/correos automáticos; Enterprise permite supervisión | P0/P1 manual/aprobado; mensajes/acciones sensibles solo con autorización de rol, confirmación y auditoría en fases posteriores. |
| Retail vs Enterprise | Starter/Growth heredados; foco Enterprise ticket alto | Conservar suscripciones/funciones legacy, no priorizar crecimiento por volumen al inicio. |
| Red cross-company | V2.1 contratos de proyecciones; Enterprise privacidad fuerte | Crear especificación/controles de grants, habilitar solo tras autorización explícita y pruebas de no doble conteo. |
| Módulos Export/Industry/Agro | V2 exige alcance sectorial, Enterprise difiere ampliación a P3 | Preservar contratos y avances; pruebas sectoriales como requisito para su respectivo release, no para el primer piloto B2B. |
| Semántica de demanda | V2 venta observada y faltante; V2.2 reconciliación; V2.3 calidad; Enterprise oportunidades | Mantener venta confirmada, demanda latente, pedidos, reservas, proyecciones, probabilidades y resultados como entidades distinguibles. |

---

## 28. ENTREGABLES DE ESTA CONCILIACIÓN Y ÚLTIMA REGLA DE ACEPTACIÓN

### El agente debe entregar también

- `docs/roadmap/V25_VERSION_DIFF.md` con filas literales por versión y etiquetas `LEGACY_PRESERVED`, `EXTENDED`, `MOVED_PHASE`, `CONFLICT_RESOLVED`, `NOT_VERIFIED`.
- `docs/intelligence/FACTOR_INTELLIGENCE.md` con data contracts, proveedores, disponibilidad temporal y auditoría.
- `docs/intelligence/PRODUCT_TAXONOMY.md` con modelo sectorial y mapeo de SKUs.
- `docs/intelligence/EXTERNAL_SOURCES.md` con estado legal/técnico de cada fuente; no inventar conectores activos.
- `docs/intelligence/SECTOR_PROFILES.md` y los seis escenarios de V2.1.
- `docs/ml/FEATURE_SELECTION.md`, `POINT_IN_TIME_VALIDATION.md` con reportes de ablation/holdout.
- `docs/product/LEGACY_API_COMPATIBILITY.md` con compatibilidad V2.2 de agenda/endpoints.
- `docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md` y `docs/testing/V25_TEST_MATRIX.md` con T01–T17.

### Fin del trabajo

**No declarar «todo conciliado literalmente»** mientras original/V1 y V2.4 estándar estén ausentes; recuperar si existen en repositorio/Git o etiquetar brecha documental. **No declarar «core maduro» sin ejecutar gates P0 sobre repositorio/datos/entorno autorizados**, ni «Enterprise listo» sin gates P1 completos. No prometer ROI por coincidencia temporal. Los desarrollos V2.1 que afectan temporalidad, seguridad, trazabilidad y contratos se consideran parte del núcleo desde P0; sus integraciones externas avanzadas son P2. No reemplazar los cuatro cores ni borrar módulos heredados. Implementar y probar por slices, registrar acciones ejecutadas, bloqueos y evidencias reales, y continuar todas las tareas independientes posibles.
