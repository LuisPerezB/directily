# Responsabilidades del propietario de Predictily

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · Fuentes: V2.5 §17 (operación del propietario), §19 Gate E, §22 (checklist por cliente) y §21; recopilación de todas las
menciones «a aprobar en el piloto» y «a aprobar con el propietario» de `docs/` y de `backend/config/` y `engine/predictily_engine/platform/config.py`.

**Quién es «el propietario».** La empresa titular y responsable legal de la plataforma Predictily (V2.5 §17: «cuenta y presupuesto AWS, dominios/credenciales, autorización de
acceso, acuerdos, obtención de datasets autorizados, revisión y aprobación de mapeos/reglas/modelos, soporte piloto, incidentes/contratos»). Este documento lista **solo lo que
ningún agente ni desarrollador puede decidir o ejecutar por él**, dónde se configura cada cosa y en qué estado está hoy. No contiene credenciales, datos reales ni nombres de
clientes; lo identificable del piloto vive solo en `reports/private/` (ignorado por git).

**Leyenda de estado.** **PENDIENTE (propietario)** (decisión o acción que nadie ha tomado) · **TOMADA** (decisión registrada, con su riesgo) · **BLOQUEADO** (impide verificar algo hasta que
el propietario actúe) · **VALOR INICIAL** (el producto trae un valor de diseño que funciona, pero es una hipótesis **a aprobar en el piloto**, nunca universal) · **NO APLICA**.
Para el estado del producto se usan las marcas de siempre: **IMPLEMENTADO / VERIFICADO**, **DISEÑADO / NO IMPLEMENTADO**.

> Los gates de release (GO / GO CONDICIONAL / NO-GO) **no** son una decisión de este documento: los evalúa el arquitecto en
> [`docs/roadmap/V25_RELEASE_GATES.md`](../roadmap/V25_RELEASE_GATES.md). La decisión GO/NO-GO **del piloto con el cliente** (§19 Gate E) sí la toman el propietario y el
> cliente, con responsables y evidencia, y alimenta esa evaluación.

## Contenido

- [1. Reparto de responsabilidades (§17)](#1-reparto-de-responsabilidades-17)
- [2. Piloto y autorización de datos](#2-piloto-y-autorización-de-datos)
- [3. Umbrales provisionales «a aprobar en el piloto»](#3-umbrales-provisionales-a-aprobar-en-el-piloto)
- [4. Seguridad](#4-seguridad)
- [5. Legal y LOPDP](#5-legal-y-lopdp)
- [6. LLM y mensajería](#6-llm-y-mensajería)
- [7. AWS y costos](#7-aws-y-costos)
- [8. Bridge, servidores de prueba y repositorio](#8-bridge-servidores-de-prueba-y-repositorio)
- [9. Comercial y planes](#9-comercial-y-planes)
- [10. Checklist operativo por cliente (§22)](#10-checklist-operativo-por-cliente-22)
- [11. Resumen y orden sugerido](#11-resumen-y-orden-sugerido)
- [12. Tabla final por módulo](#12-tabla-final-por-módulo)

## 1. Reparto de responsabilidades (§17)

| Rol | Hace | Nunca hace |
|---|---|---|
| **Agente implementador / equipo técnico** | código, infraestructura como código, pruebas, CLI, runbooks, migraciones, fixtures sintéticas etiquetadas, guardrails | acciones empresariales sin consentimiento; cambiar código por cliente; decidir los umbrales de negocio; editar los gates (los edita el arquitecto) |
| **Propietario de Predictily** | todo lo de §2–§10 de este documento | compartir credenciales fuera de la fuente cifrada o del `.env` local; versionar datos reales |
| **Cliente** | validar definiciones comerciales, responsables, inventario y outcomes reales; revisar recomendaciones; acordar KPI y ventana de piloto | — |

Procedimientos completos: [`PILOT_RUNBOOK.md`](PILOT_RUNBOOK.md), [`docs/integrations/ENTERPRISE_ONBOARDING.md`](../integrations/ENTERPRISE_ONBOARDING.md) (20 pasos),
[`TENANT_TRAINING_RUNBOOK.md`](TENANT_TRAINING_RUNBOOK.md), [`AWS_DEPLOYMENT.md`](AWS_DEPLOYMENT.md), [`RECOVERY.md`](RECOVERY.md).

## 2. Piloto y autorización de datos

| ID | Decisión o acción | Dónde se configura o registra | Estado |
|---|---|---|---|
| OWN-01 | Contrato con el cliente piloto: uso previsto, autorización de tratamiento, responsable de datos, límites, retención, costos y soporte | fuera del producto (contrato); en el producto: consentimiento y retención por empresa | **PENDIENTE (propietario)** |
| OWN-02 | Registrar las fuentes SQL Server del piloto en la app local (empresa piloto, usuario propietario, sucursales, fuentes `sql_sqlserver`, prueba de conexión) | `POST /api/v1/tenants`, `POST /api/v1/data-sources`, `POST /api/v1/data-sources/{id}/test`; `PILOT_RUNBOOK.md` §3 | **BLOQUEADO**: el acceso por VPN existe, las fuentes no están registradas. Sin esto no hay línea base real (`reports/V25_PILOT_BASELINE.md`) |
| OWN-03 | Cuenta de base de datos del origen de **solo lectura** (SQL Server: `db_datareader`) | credencial en la fuente cifrada de Predictily | **TOMADA**: el propietario decidió usar una cuenta con más permisos que solo lectura (riesgo aceptado; detalle solo en `reports/private/`). La protección depende de `SqlQueryGuard` (una sola sentencia SELECT, `ApplicationIntent=ReadOnly`), porque SQL Server no tiene transacciones de solo lectura. Recomendación vigente: un login con solo `db_datareader` |
| OWN-04 | Fuente de verdad por entidad y por periodo cuando el piloto tiene más de una base de origen | `PILOT_RUNBOOK.md` §2 punto 5; documentarlo en `reports/private/` | **PENDIENTE (propietario)** |
| OWN-05 | Ajuste TLS de la fuente (`encrypt`, `trust_server_certificate`) | configuración de la fuente (`263a268`) | **PENDIENTE (propietario)** al registrar la fuente |
| OWN-06 | Revisar y **aprobar** los mapeos y su versión (plantillas del Auto Mapper; la aprobación es una habilidad distinta de proponer: `settings.manage`, sensible para el MFA) | `mapping-proposals` → `approve` → `apply` (`AUTO_MAPPING.md` §8, `ENTERPRISE_ONBOARDING.md` §3) | **PENDIENTE (propietario)** |
| OWN-07 | Totales de control por mes calculados en el origen y conciliación mes a mes (toda diferencia explicada o el Gate A queda bloqueado) | `php artisan predictily:reconcile <tenant_uuid> --entity=sales --from=AAAA-MM-DD --to=AAAA-MM-DD`; totales en `reports/private/` | **PENDIENTE (propietario)** |
| OWN-08 | Aprobar manifiesto del dataset, punto de corte, pipeline/modelo y backtest/holdout; aprobar cada modelo con una **persona distinta** de quien lo entrenó o publicó | `models.approve` (`docs/ml/MODEL_REGISTRY.md`); `TENANT_TRAINING_RUNBOOK.md` | **PENDIENTE (propietario)** |
| OWN-09 | KPI comercial del piloto (p. ej. precisión a 7 días, top-K de oportunidades, contribución, tiempo para contactar, margen, agotamientos) **sin objetivos engañosos ni WAPE universal** | acuerdo con el cliente; el producto mide con su método (no fija objetivos) | **PENDIENTE (propietario y cliente)** |
| OWN-10 | Ventana de piloto y grupo comparador (fracción de control por empresa; 0 por defecto = sin experimento; máximo 0,5) | `tenants.settings.decision.control_fraction` vía `PUT /api/v1/decisions/settings` (`settings.manage`) | **PENDIENTE (propietario y cliente)**; mecanismo IMPLEMENTADO / VERIFICADO (W5-B) |
| OWN-11 | Decisión GO / GO CONDICIONAL / NO-GO **del piloto**, con responsables y evidencia; si la precisión es insuficiente, se informa | `reports/private/` (resultados reales) y, solo si el propietario lo autoriza, un resumen sin datos identificables | **PENDIENTE**; no hay piloto (Gate E **BLOQUEADO**) |
| OWN-12 | Autorizar (o no) publicar en el repositorio un resumen del piloto sin datos identificables | `PILOT_RUNBOOK.md` §0 regla 3 y §5 punto 3 | **PENDIENTE (propietario)**; por defecto nada del piloto se versiona |

## 3. Umbrales provisionales «a aprobar en el piloto»

Todos funcionan hoy con un valor inicial de diseño y su justificación escrita. Ninguno es universal: se revisan con datos reales de cada empresa. Se cambian **por empresa** sin tocar
código (precedencia núcleo → perfil sectorial → empresa, `tenants.settings.cores.<core>`, consultable con `GET /api/v1/tenant/effective-config?core=<core>`) o por configuración del
servidor cuando así se indica. Estado de todos: **VALOR INICIAL**.

| ID | Grupo | Dónde se configura | Valores clave (defecto) | Justificación y lista completa |
|---|---|---|---|---|
| OWN-13 | Gates de calidad de datos (PASS/WARN/DEGRADE/BLOCK por módulo) | motor: bloque `quality_gates` de `platform/config.py`; ingestión: `backend/config/data_quality.php` (entra en la huella `rules_version`) | p. ej. `demand_min_history_days` 28, `demand_min_source_coverage_pct` 0,5, `demand_block_invalid_pct` 0,5, `demand_min_price_coverage` 0,8, `id_lookback_days` 90 | [`DATA_QUALITY_ENGINE.md`](../data/DATA_QUALITY_ENGINE.md) (tabla de claves y justificación) |
| OWN-14 | Regla de huecos inferidos (un día sin ventas de una sucursal activa se marca `missing_source`) | `demand.day_status_infer_gaps` (`true`) y `day_status_infer_min_median_lines` | encendida | un cierre no declarado se confunde con una caída de la fuente; [`TEMPORAL_AVAILABILITY.md`](../data/TEMPORAL_AVAILABILITY.md); `V25_RELEASE_GATES.md` Gate A |
| OWN-15 | Métricas por horizonte y segmento (denominador mínimo del WAPE, tolerancia de comparación, estabilidad, mínimos por segmento) | bloque `demand` (W3-A) | `demand.wape_min_denominator` y demás | [`FORECAST_VALIDATION.md`](../ml/FORECAST_VALIDATION.md) §3.5 |
| OWN-16 | **Qué política gobierna lo servido** (heredada o V2.5) y sus umbrales, el shadow y Optuna (apagado) | `promotion.governing_policy` (`legacy` por defecto) y bloque `promotion` | `legacy` | [`FORECAST_SELECTION.md`](../ml/FORECAST_SELECTION.md); cambiar a `v25` requiere aprobación humana (`V25_RELEASE_GATES.md` Gate B) |
| OWN-17 | Customer Core S05 (nº de pedidos, cantidad por pedido, ventana, cold start, segmentos, lift) | bloque `customer` (`settings.cores.customer`; `s05_enabled`) | `cold_start_min_purchases` 3 y demás | [`CUSTOMER_REPURCHASE.md`](../ml/CUSTOMER_REPURCHASE.md) §«Parámetros» |
| OWN-18 | Calibración y ranking (segmentos, tolerancias, PSI, cobertura) | bloque `customer` | — | [`CALIBRATION_AND_RANKING.md`](../ml/CALIBRATION_AND_RANKING.md) |
| OWN-19 | Supply: perecibilidad, FEFO frente a la alternativa, antigüedad de fotos de inventario, vencimiento implausible | bloque `supply` (`perishability_enabled`, …); `MAX_EXPIRY_YEARS` 15 | — | [`SUPPLY_CORE.md`](../supply/SUPPLY_CORE.md) §8 |
| OWN-20 | Pipeline de factores (margen, fracción de ventanas, ventanas mínimas, placebos) y antigüedades máximas | bloque `factors`: `arm_min_improvement` 0,03, `arm_windows_won_fraction` 2/3, `arm_min_windows` 2, `arm_placebo_arms` 5; `staleness_days_by_frequency`, `default_max_staleness_days` | apagado (`factors.enabled = false`) | [`FACTOR_PIPELINE.md`](../intelligence/FACTOR_PIPELINE.md) §4; [`FEATURE_SELECTION.md`](../ml/FEATURE_SELECTION.md) |
| OWN-21 | Justificación sectorial y aplicabilidad de las familias de factores; elección de factores aplicables y no aplicables con el cliente | catálogo de factores (`sector_justification`); activación `PUT /api/v1/intelligence/factors/{clave}/activation` | ninguna familia activa | [`FACTOR_INTELLIGENCE.md`](../intelligence/FACTOR_INTELLIGENCE.md); [`SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md`](../testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md) |
| OWN-22 | Revenue Radar y priorización económica (probabilidad mínima para proponer un contacto, tope por tipo y horizonte, pesos del puntaje) | `backend/config/radar.php` (`min_probability` 0,20, `max_per_kind_horizon`, …); parámetros del Decision Engine `PUT /api/v1/decisions/settings` | — | [`ECONOMIC_PRIORITIZATION.md`](../decision/ECONOMIC_PRIORITIZATION.md) §6; [`DECISION_ENGINE.md`](../decision/DECISION_ENGINE.md) |
| OWN-23 | Medición de outcomes (margen de espera de documentos tardíos, tamaño mínimo por grupo, compradores mínimos por grupo, nivel de confianza) | `backend/config/outcomes.php`: `settling_lag_days` 3, `min_group_size` 30, `min_buyers_per_arm` 10, `confidence_level` | — | [`OUTCOME_MEASUREMENT.md`](../decision/OUTCOME_MEASUREMENT.md) §13 |
| OWN-24 | Auto Mapper (puntaje mínimo, margen, pesos, topes de muestra y frecuencia mínima de un valor) | `backend/config/mapping.php`: `min_confidence` 0,60, margen 0,10, `min_value_count` 5, `distinct_scan_max_rows` 500 000, `statement_timeout_seconds` 5 | puntaje **heurístico**, no una probabilidad | [`AUTO_MAPPING.md`](../integrations/AUTO_MAPPING.md) §4.2 y §12 |
| OWN-25 | Bridge: cuota diaria por empresa, límites por credencial e IP, tamaño de lote, solape, búfer, vigencias | `backend/config/bridge.php` (`PREDICTILY_BRIDGE_TENANT_DAILY_QUOTA_MB` 4096; 240/min); configuración del agente (lote 5 000 filas, 32 MB, solape 120 min, búfer 512 MB, 10 / 60 min) | cuota por dispositivo: DISEÑADA | [`BRIDGE_WINDOWS.md`](../integrations/BRIDGE_WINDOWS.md) §7–§8 |
| OWN-26 | Capacidad de Bronze (tamaño máximo de un objeto) y retención de plataforma | `PREDICTILY_BRONZE_MAX_OBJECT_MB` (64), `PREDICTILY_BRONZE_RETENTION_DAYS` (0 = sin vencimiento); por empresa `data.bronze_retention_days` | — | [`BRONZE_SILVER_GOLD.md`](../data/BRONZE_SILVER_GOLD.md) §2 y §6.2 |
| OWN-27 | Topes operativos de Starter/Growth que la fuente no fija y definición de «serie activa» (ventas válidas en 90 días) | `backend/config/predictily.php` (planes) | marcados (*) | [`RETAIL_STARTER_GROWTH.md`](../product/RETAIL_STARTER_GROWTH.md) §3 |
| OWN-28 | Reparto del costo fijo de AWS entre empresas (peso del cómputo λ y demás supuestos) | parámetros de [`AWS_COST_MODEL.md`](../architecture/AWS_COST_MODEL.md) | λ = 0,5 (supuesto ilustrativo) | decisión comercial; sin factura real |
| OWN-29 | Disparadores del reentrenamiento automático | [`AUTOMATED_TRAINING.md`](../ml/AUTOMATED_TRAINING.md) §4.1 | — | **DISEÑADO / NO IMPLEMENTADO**; se fijan al implementarlo |

## 4. Seguridad

| ID | Decisión | Dónde se configura | Estado |
|---|---|---|---|
| OWN-30 | **Política de MFA** por empresa: hacer obligatorio el segundo factor para propietarios y administradores | `PUT /api/v1/tenant/security-policy` → `mfa_required_for_privileged` (`settings.manage`, sensible); la respuesta informa cuántos privilegiados aún no tienen MFA | **PENDIENTE (propietario)**; apagada por defecto. IMPLEMENTADO / VERIFICADO (W6-A; el E2E V2.5 recorre alta, desafío y step-up) |
| OWN-31 | MFA del superadministrador de plataforma | `PREDICTILY_MFA_SUPERADMIN_REQUIRED` (`null` = obligatorio solo en producción) | **VALOR INICIAL** |
| OWN-32 | Valores de bloqueo y frescura del segundo factor (5 intentos por IP y persona, 30 por IP, 15 por persona, 15 min de bloqueo, 10 min de frescura, ±1 paso TOTP) | `backend/config/security.php` (a propósito no por entorno: son política) | **VALOR INICIAL**: «a aprobar con el propietario antes de producción» ([`ENTERPRISE_SECURITY.md`](../security/ENTERPRISE_SECURITY.md) §5) |
| OWN-33 | Caducidad de tokens | `PREDICTILY_TOKEN_DEFAULT_TTL_DAYS` (30), `PREDICTILY_TOKEN_MAX_TTL_DAYS` (365), `PREDICTILY_CONNECTOR_TOKEN_TTL_DAYS` (365) | **VALOR INICIAL** |
| OWN-34 | Alcance mínimo de los tokens de conector ya emitidos (hoy `imports.manage` y `data.view`; reducirlo rompería conectores existentes) | emisión de tokens (`ENTERPRISE_SECURITY.md` §8) | **PENDIENTE (propietario)**: «a decidir con el propietario» |
| OWN-35 | **Activar RLS de PostgreSQL** (defensa adicional en 4 tablas de ventas) | `PREDICTILY_RLS_ENABLED` y `php artisan predictily:rls {status\|enable\|disable}` | **PENDIENTE**; apagada por defecto y **no apta para producción** hasta resolver tres bloqueos (motor sin RLS, cuenta de aplicación de mínimo privilegio, *pooler*). Solo se puede ensayar en un entorno de prueba ([`TENANT_ISOLATION.md`](../architecture/TENANT_ISOLATION.md) §4) |
| OWN-36 | Cuenta de base de datos de mínimo privilegio para la aplicación, otra para mantenimiento (`BYPASSRLS`) y otra para el motor | despliegue (no existe en `docker-compose.yml`, que usa un superusuario) | **PENDIENTE (propietario)**; DISEÑADO |
| OWN-37 | Lista blanca de hosts y redes de las fuentes SQL (anti-SSRF) y hacerla obligatoria en producción | `PREDICTILY_SQL_ALLOWED_HOSTS`, `PREDICTILY_SQL_ALLOWED_CIDRS` (backend; la IP del piloto no se versiona) | local configurada para el piloto; obligatoria en producción: **DISEÑADO / NO IMPLEMENTADO** |
| OWN-38 | Generación y custodia de la clave de firma Ed25519 de artefactos y de las claves públicas de confianza | `python -m predictily_engine.cli keygen --key-id <id> --private-out <ruta segura>`; `ENGINE_SIGNING_*`, `ENGINE_TRUSTED_PUBLIC_KEYS`; en AWS, `signingKeySecretName` (Secrets Manager + KMS) | local: **TOMADA** (clave creada con permisos 0600 fuera del repositorio); AWS: **BLOQUEADO** |
| OWN-39 | Visibilidad del vendedor sobre lo sin asignar de su cartera | `radar.seller_scope.unassigned_in_portfolio` (`true`) en `backend/config/radar.php` | **VALOR INICIAL**: «a aprobar con el propietario en el piloto» |
| OWN-40 | Restablecer un segundo factor perdido (solo la plataforma, con motivo) | `POST /api/v1/admin/users/{user}/mfa-reset` | procedimiento disponible; uso a criterio del propietario |

## 5. Legal y LOPDP

Predictily actúa como **encargado del tratamiento** de los datos de cada cliente ([`docs/07-seguridad-multitenant.md`](../07-seguridad-multitenant.md) §8). Nada de esto sustituye
la asesoría legal.

| ID | Decisión | Dónde | Estado |
|---|---|---|---|
| OWN-41 | Revisión legal por abogado: contrato de encargo por cliente, subencargados (proveedor de nube, proveedor LLM), medidas de seguridad | `docs/07-seguridad-multitenant.md` §8.1 | **BLOQUEADO** (propietario) |
| OWN-42 | Evaluación de impacto (EIPD) antes de habilitar un conector con datos de clientes, el asistente con un proveedor externo o segmentación avanzada | `docs/07-seguridad-multitenant.md` §8.8 | **PENDIENTE (propietario)** |
| OWN-43 | Transferencia internacional y residencia de datos (región de la nube; hoy la IaC exige `us-east-1`) | `docs/07-seguridad-multitenant.md` §8.7; `AWS_ARCHITECTURE.md` §1 | **PENDIENTE (propietario)** |
| OWN-44 | Política de retención por empresa: Bronze (`data.bronze_retention_days`), auditoría (`PREDICTILY_AUDIT_RETENTION_DAYS`, 730 días), eliminación diferida (30 días), enlaces de exportación (60 min; archivo 7 días); retención de archivos importados e IP | `backend/config/predictily.php` (`privacy`), `backend/config/bronze.php`, `tenants.settings` | **VALOR INICIAL**; retención de archivos importados e IP: DISEÑADO |
| OWN-45 | Con S3 versionado, cómo cumplir el borrado inmediato (bajar los días de versiones no vigentes o purga de versiones) | `AWS_DEPLOYMENT.md` (opciones a decidir por el propietario) | **PENDIENTE (propietario)**; AWS BLOQUEADO |
| OWN-46 | Ofrecer o no el **entrenamiento compartido** entre empresas (solo con consentimiento expreso y revocable del propietario de la empresa) | `tenant.consent` (`TenantManagementTest::test_shared_training_consent_requires_owner_and_is_recorded`) | apagado por defecto; **PENDIENTE (propietario)** |
| OWN-47 | Procedimiento de notificación de incidentes y acceso de emergencia registrado | `docs/07-seguridad-multitenant.md` §8 y `RECOVERY.md` | **PENDIENTE (propietario)** formalizarlo con el contrato |
| OWN-48 | Habilitar la red de pronósticos compartidos entre empresas (`SharedForecastGrant`) | `PREDICTILY_SHARED_FORECAST_GRANTS_ENABLED` (`false`) | **NO APLICA en P0/P1**: contrato y pruebas negativas existen; habilitarla exige autorización expresa y diseño legal (P3) |

## 6. LLM y mensajería

| ID | Decisión | Dónde se configura | Estado |
|---|---|---|---|
| OWN-49 | **Consentimiento para enviar datos a un LLM de terceros (V25-SEC-040 / V25-FAC-041).** Con una clave de proveedor, los resultados de las herramientas (cifras, códigos de producto y de comprador) salen hacia el proveedor; no existe consentimiento **por empresa** ni EIPD forzada por el código. Hasta que exista, el envío es una decisión del operador: comunicarla a las empresas y, si alguna no puede compartir esos datos, desactivar el asistente en su plan | `CONVERSATION_API_KEY`, `CONVERSATION_ENABLED` (servidor); `features.assistant` del plan o `plan_overrides` | **PENDIENTE (propietario)**; consentimiento por empresa **DISEÑADO / NO IMPLEMENTADO** ([`INTELLIGENCE_GATEWAY.md`](../architecture/INTELLIGENCE_GATEWAY.md), aviso inicial) |
| OWN-50 | Proveedor LLM (adaptador compatible con Ollama Cloud u otro, o runtime privado) y su contrato de tratamiento | `backend/config/conversation.php` | **BLOQUEADO** (sin clave en este entorno); el negocio funciona sin LLM |
| OWN-51 | **Proveedor de WhatsApp**: cuenta de WhatsApp Business Platform (directa o mediante un proveedor de soluciones), número verificado, verificación de empresa y plantillas aprobadas | `conversation.channels.whatsapp.enabled` (`CONVERSATION_WHATSAPP_ENABLED`, `false`); la aplicación no registra ningún proveedor | **BLOQUEADO** (propietario); el canal es **solo contrato** y no envía nada |
| OWN-52 | Consentimiento por canal, minimización y retención de mensajes; vinculación teléfono ↔ usuario | — | **DISEÑADO / NO IMPLEMENTADO** (V25-GWY-019/-020/-022); requiere OWN-51 |

## 7. AWS y costos

Guía paso a paso: [`AWS_DEPLOYMENT.md`](AWS_DEPLOYMENT.md). Plantilla de datos: `infra/aws/owner-inputs.example.json` (solo marcadores; el archivo real no se versiona).

| ID | Decisión o aporte | Dónde | Estado |
|---|---|---|---|
| OWN-53 | Cuenta AWS (idealmente una por entorno) con MFA en la raíz y sin claves de acceso raíz; rol de despliegue de mínimo privilegio | `account` | **BLOQUEADO** (propietario) |
| OWN-54 | Presupuesto mensual y correos de alertas (deben confirmar la suscripción SNS) | `monthlyBudgetUsd`, `alertEmails`, `budgetFilterByEnvironmentTag` | **BLOQUEADO** (decisión del propietario; no es un precio) |
| OWN-55 | Región (residencia de datos), dominios y certificados ACM | `region`, `appDomainName`, `originDomainName`, `albCertificateArn`, `cloudFrontCertificateArn` | **BLOQUEADO** |
| OWN-56 | Single-AZ inicial frente a Multi-AZ (RTO/RPO deseados y criterio de cambio) | ADR-V25-09; `AWS_ARCHITECTURE.md` §6 | **PENDIENTE (propietario)**; diseño Single-AZ con la limitación reconocida |
| OWN-57 | Confirmar los precios del modelo de costos marcados «a confirmar por el propietario» y revisar el costo real por empresa y la rentabilidad de cada contrato | `AWS_COST_MODEL.md`; `tenant_usage_records` (`predictily:usage-snapshot`) | **BLOQUEADO**: sin factura real no hay costo medido |
| OWN-58 | Separar identidades y secretos entre ambientes; nunca copiar datos reales a *staging* | despliegue | **PENDIENTE (propietario)** |

## 8. Bridge, servidores de prueba y repositorio

| ID | Decisión o aporte | Dónde | Estado |
|---|---|---|---|
| OWN-59 | **Certificado de firma de código de Windows** (OV o EV, con su `signtool` o token) y un host Windows 10/11 o Server 2019+ x64 con Python 3.12 para construir, firmar y probar el instalador y el servicio del Bridge | `bridge/packaging/` (especificación no construida); [`BRIDGE_WINDOWS.md`](../integrations/BRIDGE_WINDOWS.md) §5 (pasos exactos en PowerShell) | **BLOQUEADO** (propietario). El certificado y su contraseña nunca se guardan en el repositorio |
| OWN-60 | Servidores de prueba SQL Server y MySQL/MariaDB (o acceso de solo lectura a una instancia del cliente) | `TEST_ERP_*` de las suites | **BLOQUEADO**: las pruebas de servidor MySQL y SQL Server se omiten en cada integración; los adaptadores figuran `implemented_untested` |
| OWN-61 | Publicar la rama y abrir el PR (el `push` falla con la cuenta autenticada en el equipo de trabajo) | GitHub | **PENDIENTE (propietario)**: iniciar sesión con la cuenta dueña del repositorio |
| OWN-62 | Licencias y proveedores de fuentes externas (clima, tendencias, competencia, estadísticas) con historial de emisiones | [`EXTERNAL_SOURCES.md`](../intelligence/EXTERNAL_SOURCES.md) | **BLOQUEADO / P2** |

## 9. Comercial y planes

| ID | Decisión | Dónde | Estado |
|---|---|---|---|
| OWN-63 | Plan de cada empresa y límites de cada contrato Enterprise (series, usuarios, sucursales, horizontes, capacidades); un precio nunca es un límite | `PATCH /api/v1/admin/tenants/{uuid}` (`plan_code`, `plan_overrides`; solo el superadministrador, auditado) | **PENDIENTE (propietario)** por contrato; IMPLEMENTADO / VERIFICADO |
| OWN-64 | Criterio de «IDs de comprador confiables» para habilitar Customer en Growth | — | **PENDIENTE**: a definir en el piloto (el motor ya se abstiene sin compradores identificados y degrada a agregado con IDs inestables) |
| OWN-65 | Precios de referencia (informativos) y rangos Enterprise | `plans.monthly_price_usd`, `plans.metadata.reference_price_usd` | informativos; no hay pasarela de pagos (**NO APLICA**) |

## 10. Checklist operativo por cliente (§22)

Cada casilla es una acción del propietario. La columna «Respaldo en el producto» dice con qué se apoya el paso y dónde queda el rastro; el detalle paso a paso está en
`ENTERPRISE_ONBOARDING.md` §5.

| # | Casilla de §22 | IDs de este documento | Respaldo en el producto | Estado general |
|---|---|---|---|---|
| 1 | Contrato, uso previsto, autorización de tratamiento y responsable de datos; límites, retención, costos y soporte | OWN-01, OWN-41, OWN-44 | consentimiento y retención por empresa; exportación y eliminación LOPDP | **PENDIENTE** |
| 2 | Datos disponibles y permisos de solo lectura; validación de fuente, identidad cliente/SKU/sucursal, monedas y unidades | OWN-02, OWN-03, OWN-05, OWN-37 | descubrimiento de esquema, prueba de conexión con advertencia de escritura, hallazgos de calidad e identidades | **BLOQUEADO** (fuentes sin registrar) |
| 3 | Mapeos revisados y versión; reconciliar totales netos y anulaciones; calidad e historia suficientes y funciones que deben degradarse | OWN-06, OWN-07, OWN-13 | plantilla aprobada con huella, `predictily:reconcile`, gates por módulo | **PENDIENTE** |
| 4 | KPI comercial sin objetivos engañosos | OWN-09 | métricas por horizonte y segmento; outcomes con su método | **PENDIENTE** |
| 5 | Restricciones: stock, costos confiables, mínimos, capital, precios, lead time, calendario, responsables y acciones permitidas | OWN-19, OWN-22 | `decisions/settings`; un costo no confiable deja la contribución en «desconocida» | **PENDIENTE** |
| 6 | Manifest del dataset, corte, pipeline/modelo y backtest/holdout aprobados; CLI local autorizado si aplica | OWN-08, OWN-16 | CLI privada (`validate-data` … `publish`), registro con aprobación separada | **PENDIENTE** |
| 7 | Checksum, carga a S3, versión aprobada, ejecución en AWS y aislamiento; nada de un cliente se copia a otro | OWN-38, OWN-53–OWN-55 | checksum y firma IMPLEMENTADO; S3 y AWS **BLOQUEADO** | **BLOQUEADO** |
| 8 | Usuarios, roles, tableros y plan; pruebas de acción con revisión humana | OWN-30, OWN-39, OWN-63 | roles comerciales, MFA, workflow con segregación de funciones | **PENDIENTE** |
| 9 | Ventana de piloto y grupo comparador; feedback, outcomes, fallos y pérdidas; decisión GO/NO-GO | OWN-10, OWN-11, OWN-23 | fracción de control, `predictily:outcomes-measure`, ROI nunca «probado» sin control | **PENDIENTE** (Gate E **BLOQUEADO**) |
| 10 | Costos reales de AWS por empresa y rentabilidad del contrato; documentación y política de reentrenamiento | OWN-57, OWN-28, OWN-29 | `tenant_usage_records`; modelo de costos paramétrico | **BLOQUEADO** |

## 11. Resumen y orden sugerido

**65 decisiones o aportes** recopilados (OWN-01 a OWN-65): 12 del piloto y la autorización de datos, 17 grupos de umbrales provisionales, 11 de seguridad, 8 legales/LOPDP, 4 de LLM y
mensajería, 6 de AWS y costos, 4 de Bridge, servidores de prueba y repositorio, y 3 comerciales. Dos están **TOMADAS** (OWN-03, con riesgo aceptado, y OWN-38 en local); el resto está pendiente,
bloqueado o funciona con un valor inicial a aprobar.

Orden sugerido (lo que desbloquea más): **OWN-02** (registrar las fuentes del piloto) → OWN-01/OWN-41 (contrato y revisión legal) → OWN-03/OWN-04/OWN-07 (cuenta de solo lectura,
fuente de verdad, conciliación) → OWN-06/OWN-08 (mapeos y modelos) → OWN-09/OWN-10 (KPI y ventana con grupo de control) → OWN-13 a OWN-23 (umbrales con los primeros datos reales) →
OWN-30/OWN-49 (MFA obligatorio y decisión sobre el LLM) → OWN-53 a OWN-57 (AWS) → OWN-59 (firma del Bridge, si el cliente necesita el agente en Windows).

## 12. Tabla final por módulo

| Módulo | Evidencia | Estado de pruebas | Costo | Riesgo | Aprobación |
|---|---|---|---|---|---|
| Piloto y datos | `PILOT_RUNBOOK.md`, `infra/scripts/pilot/sql_source_discovery.php` | procedimiento probado con el ERP ficticio; con datos reales: no ejecutado | tiempo del propietario y del cliente | fuentes sin registrar; cuenta del origen con más permisos que solo lectura | OWN-01 a OWN-12 |
| Umbrales | documentos enlazados en §3 | cada valor tiene prueba de su efecto; ninguno validado con datos reales | ninguno | un umbral de diseño puede no servir a una empresa concreta | OWN-13 a OWN-29, en el piloto |
| Seguridad | `ENTERPRISE_SECURITY.md`, `TENANT_ISOLATION.md` | W1-B y W6-A en verde (integración de W6) | ninguno | MFA opcional por defecto; RLS apagada | OWN-30 a OWN-40 |
| Legal y LOPDP | `docs/07-seguridad-multitenant.md` §8 | exportación y eliminación probadas | asesoría legal (sin estimar) | tratamiento sin contrato revisado | OWN-41 a OWN-48 |
| LLM y mensajería | `INTELLIGENCE_GATEWAY.md` | gateway en verde en la integración de W6; sin modelo real (desactivado sin clave) | uso del proveedor (hoy 0: desactivado) | envío a terceros sin consentimiento por empresa | OWN-49 a OWN-52 |
| AWS | `AWS_ARCHITECTURE.md`, `AWS_DEPLOYMENT.md`, `AWS_COST_MODEL.md` | síntesis y pruebas de plantilla; nada desplegado | **sin medir** | todo BLOQUEADO hasta la cuenta | OWN-53 a OWN-58 |
| Bridge y entorno | `BRIDGE_WINDOWS.md` | agente probado con PostgreSQL, SQLite y archivos ficticios; Windows no construido | certificado de firma (propietario) | instalador sin firmar no apto para clientes | OWN-59 a OWN-62 |
| Comercial | `RETAIL_STARTER_GROWTH.md` | planes probados | — | topes supuestos (*) | OWN-63 a OWN-65 |
