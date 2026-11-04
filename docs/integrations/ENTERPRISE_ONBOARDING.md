# Onboarding Enterprise repetible, paso a paso

Paquete W6-C · V2.5 §17 («flujo repetible, sin alterar código por cliente») y §22 («checklist operativo por cliente»). Complementa el runbook del piloto
([`docs/operations/PILOT_RUNBOOK.md`](../operations/PILOT_RUNBOOK.md)) y el del entrenamiento privado ([`docs/operations/TENANT_TRAINING_RUNBOOK.md`](../operations/TENANT_TRAINING_RUNBOOK.md)). El detalle del Auto Mapper
(pasos 3 a 6) está en [`AUTO_MAPPING.md`](AUTO_MAPPING.md).

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (hay código y pruebas; el documento enlazado cita cuáles) · **MANUAL** (lo hace una persona, no hay herramienta) · **DISEÑADO / NO EJECUTADO**
(el procedimiento existe pero no se ha ejecutado con datos reales) · **BLOQUEADO** (depende de algo que este entorno no tiene) · **NO APLICA**.

> Este documento describe el procedimiento. **No** declara que un cliente real esté incorporado: ninguna base real de cliente se ha descubierto ni mapeado con esta herramienta (solo el ERP ficticio de las
> pruebas). Los umbrales de los pasos 4, 5 y 8 son provisionales y se aprueban en el piloto.

## 0. Quién hace qué (§17)

| Rol | Responsabilidades |
|---|---|
| **Agente implementador** | código, scripts, pruebas, documentación, migraciones, fixtures sintéticas, guardrails. Nunca ejecuta acciones empresariales sin consentimiento y no modifica código por cliente |
| **Propietario de Predictily** | contrato y autorización de acceso, cuenta y presupuesto AWS, credenciales de solo lectura del origen, **revisión y aprobación de mapeos, reglas y modelos**, soporte del piloto, incidentes |
| **Cliente** | validar definiciones comerciales, responsables, inventario y outcomes reales; revisar las recomendaciones |

Reglas que no cambian (ver `PILOT_RUNBOOK.md` §0): solo lectura en el origen, credenciales solo en la fuente cifrada de Predictily, ningún dato real en el repositorio, PII mínima (cédulas y RUC solo como huella),
sin cifras inventadas.

## 1. Los 20 pasos

| # | Paso (§17) | Cómo se hace | Quién | Estado |
|---|---|---|---|---|
| 1 | Contrato y permisos | contrato, uso previsto, autorización de tratamiento y responsable de datos; límites y retención. Checklist §22 abajo | Propietario + cliente | **MANUAL** |
| 2 | Empresa, usuarios y roles | `POST /api/v1/tenants`; usuarios con `POST /api/v1/tenant/users` (roles `owner`, `admin`, `analyst`, `viewer`; el rol comercial gerente/vendedor/servicio y el MFA los define [`ENTERPRISE_SECURITY.md`](../security/ENTERPRISE_SECURITY.md)) | Propietario | **IMPLEMENTADO / VERIFICADO** (`TenantManagementTest`; seguridad: W6-A) |
| 3 | Origen y Bridge | fuente SQL de solo lectura: `POST /api/v1/data-sources` (`sql_postgresql`, `sql_mysql`, `sql_sqlserver`) y `POST /api/v1/data-sources/{id}/test` (§2 abajo). Origen detrás de red privada: agente Bridge (W6-B) | Propietario | SQL: **IMPLEMENTADO / VERIFICADO** (PostgreSQL); MySQL y SQL Server **sin servidor** de prueba. Bridge Windows: **BLOQUEADO** (ver `BRIDGE_WINDOWS.md`) |
| 4 | Diagnóstico de calidad e identificación de datos | descubrimiento de esquema (`POST /data-sources/{id}/schema/discover`); totales de control por mes en el origen (`PILOT_RUNBOOK.md` §2); identidades y calidad ([`DATA_QUALITY_ENGINE.md`](../data/DATA_QUALITY_ENGINE.md)) | Propietario | **IMPLEMENTADO / VERIFICADO** (descubrimiento: `AutoMapperEndToEndTest`) |
| 5 | Propuesta y aprobación de mapeos | `mapping-proposals` → corregir → `approve` → `apply` (§3 abajo) | Agente propone; **el propietario revisa y aprueba** | **IMPLEMENTADO / VERIFICADO** (`AutoMapperEndToEndTest`, `MappingTemplateAccessTest`) |
| 6 | Cargas históricas y conciliación | `POST /api/v1/data-sources/{id}/sync` por entidad (catálogos primero, luego ventas e inventario); conciliación: `php artisan predictily:reconcile <tenant_uuid> --entity=sales --from=AAAA-MM-DD --to=AAAA-MM-DD` ([`LINEAGE_AND_RECONCILIATION.md`](../data/LINEAGE_AND_RECONCILIATION.md)) | Propietario | **IMPLEMENTADO / VERIFICADO** (con el ERP ficticio); con datos reales: **DISEÑADO / NO EJECUTADO** |
| 7 | Features y requisitos habilitados | gates por módulo (PASS/WARN/DEGRADE/BLOCK) y mapa feature → datos (`DATA_QUALITY_ENGINE.md` §6–7); `GET /api/v1/data-quality/extended` | Propietario | **IMPLEMENTADO / VERIFICADO** (W2-C) |
| 8 | Particiones temporales y baseline | validación point-in-time y baseline ([`POINT_IN_TIME_VALIDATION.md`](../ml/POINT_IN_TIME_VALIDATION.md), [`FORECAST_VALIDATION.md`](../ml/FORECAST_VALIDATION.md)) | Propietario | **IMPLEMENTADO / VERIFICADO**; baseline con datos reales: **DISEÑADO / NO EJECUTADO** |
| 9 | Entrenamiento local autorizado | exportación autorizada y entrenamiento en la máquina del propietario ([`TENANT_TRAINING_RUNBOOK.md`](../operations/TENANT_TRAINING_RUNBOOK.md) §2–3) | Propietario | **IMPLEMENTADO / VERIFICADO** (W4-B) |
| 10 | Backtest, ajuste, calibración y holdout | CLI `backtest`, `tune`, `train` ([`PRIVATE_TENANT_TRAINING.md`](../ml/PRIVATE_TENANT_TRAINING.md)); calibración ([`CALIBRATION_AND_RANKING.md`](../ml/CALIBRATION_AND_RANKING.md)) | Propietario | **IMPLEMENTADO / VERIFICADO** |
| 11 | Revisión y aprobación del modelo | publicar como candidato; aprobación separada (`models.approve`, ≠ quien entrenó) ([`MODEL_REGISTRY.md`](../ml/MODEL_REGISTRY.md)) | Propietario (otra persona) | **IMPLEMENTADO / VERIFICADO** (W4-A) |
| 12 | Carga, cifrado y registro en S3 | publicación al almacén de objetos con firma | Propietario | Almacén local: **IMPLEMENTADO**; **AWS/S3: BLOQUEADO** (sin cuenta ni presupuesto de AWS en este entorno) |
| 13 | Inferencia en AWS | ejecución del servicio de inferencia | Propietario | **BLOQUEADO** (AWS); en local, el motor sirve solo lo aprobado |
| 14 | Pruebas de aislamiento y de latencia | aislamiento entre empresas ([`TENANT_ISOLATION.md`](../architecture/TENANT_ISOLATION.md)); latencia medida en el entorno de producción | Agente + propietario | Aislamiento: **IMPLEMENTADO / VERIFICADO**; latencia en AWS: **BLOQUEADO** |
| 15 | Costos y reglas comerciales | parámetros del Decision Engine `PUT /api/v1/decisions/settings` (`settings.manage`); costos AWS: [`AWS_COST_MODEL.md`](../architecture/AWS_COST_MODEL.md) | Propietario + cliente | **IMPLEMENTADO / VERIFICADO**; costos reales de AWS: **BLOQUEADO** |
| 16 | Revenue Radar y abastecimiento | `php artisan predictily:radar-materialize`; vistas Radar y Supply | Cliente | **IMPLEMENTADO / VERIFICADO** (W5-A) |
| 17 | Simulaciones y decisiones | `php artisan predictily:decisions-generate`; workflow de aprobación ([`DECISION_ENGINE.md`](../decision/DECISION_ENGINE.md)) | Cliente | **IMPLEMENTADO / VERIFICADO** (W5-B) |
| 18 | Responsables y capacitación | asignar responsables y roles comerciales; capacitación del equipo | Propietario + cliente | **MANUAL** (roles: paso 2) |
| 19 | Shadow mode / piloto | fracción de control en `decisions/settings` (el grupo de control no se le revela a quien contacta); ventana de piloto y comparador | Propietario + cliente | Mecanismo **IMPLEMENTADO / VERIFICADO** (W5-B); piloto: **DISEÑADO / NO EJECUTADO** |
| 20 | Seguimiento de outcomes y ajuste | `php artisan predictily:outcomes-measure` ([`OUTCOME_MEASUREMENT.md`](../decision/OUTCOME_MEASUREMENT.md)) | Cliente | **IMPLEMENTADO / VERIFICADO** (W5-C) |

## 2. Paso 3: crear la fuente SQL de solo lectura

Previo, en el servidor de Predictily: declarar el host o la red del origen en `PREDICTILY_SQL_ALLOWED_HOSTS` / `PREDICTILY_SQL_ALLOWED_CIDRS` (las redes privadas se deniegan por defecto; política SSRF). En el origen: una
cuenta de **solo lectura** (PostgreSQL: rol con `SELECT`; MySQL/MariaDB: solo `SELECT`; SQL Server: `db_datareader`). El descubrimiento necesita poder leer el catálogo y `SELECT` sobre las tablas que se vayan a mapear.

```bash
API=https://<predictily>/api/v1
# $TOKEN: token de una persona con rol owner o admin (con MFA activo, hace falta además el segundo factor fresco: POST /auth/mfa/step-up)
curl -sS -X POST "$API/data-sources" -H "Authorization: Bearer $TOKEN" -H "X-Tenant-ID: $TENANT" -H 'Content-Type: application/json' -d '{
  "type": "connector", "connector_key": "sql_postgresql", "name": "ERP del cliente",
  "config": {"host": "<host>", "port": 5432, "database": "<base>", "sslmode": "verify-full"},
  "credentials": {"username": "<usuario_de_solo_lectura>", "password": "<clave>"}}'
curl -sS -X POST "$API/data-sources/$DS/test" -H "Authorization: Bearer $TOKEN" -H "X-Tenant-ID: $TENANT"
```

La clave se cifra en reposo y nunca se devuelve. La prueba de conexión **advierte** si la cuenta puede escribir (la política real es una cuenta de solo lectura).

## 3. Pasos 4 y 5: descubrir, proponer, revisar, aprobar, aplicar

Cada llamada indica la habilidad que exige (rol owner o admin tienen ambas hoy).

| # | Acción | Llamada | Habilidad |
|---|---|---|---|
| a | Descubrir el esquema (solo metadatos; `include_columns=1` trae las columnas) | `POST $API/data-sources/$DS/schema/discover` | `datasources.manage` |
| b | Proponer el mapeo (no aprueba nada). `sample: false` = no leer ni una fila | `POST $API/data-sources/$DS/mapping-proposals` con `{"entities": ["products","sales","inventory","suppliers","lead_times"], "vendor": "<producto de origen>"}` | `datasources.manage` |
| c | Revisar cada plantilla: `complete`, `missing_required`, `confidence.unmapped` (por qué quedó sin mapear), `confidence.pii_columns_excluded`, `confidence.warnings`, `confidence.value_maps` y la `query` | `GET $API/mapping-templates/{id}` | `datasources.manage` |
| d | Corregir lo que haga falta (columna, constante o `null` para quitar; mapeos de valores) | `PATCH $API/mapping-templates/{id}` con `{"field_map": {"channel": null}, "value_maps": {"status": {"RZD": "voided"}}}` | `datasources.manage` |
| e | Cotejar el resultado con la persona del cliente que conoce el ERP (qué significa cada tipo y estado, qué columna es la fecha real) | — | **MANUAL** |
| f | **Aprobar** (acción humana, auditada). La aprobación la hace OTRA persona con `settings.manage` si la empresa tiene más de una (403 `approval_segregation_of_duties`); compara la plantilla con el esquema ACTUAL de la fuente (409 `schema_drift_review_required` si cambió) | `POST $API/mapping-templates/{id}/approve` | `settings.manage` |
| g | Aplicar: crea el mapeo SQL activo para el pipeline de importación | `POST $API/data-sources/$DS/mapping-templates/{id}/apply` | `datasources.manage` + `settings.manage` |
| h | Vista previa de campos canónicos (primeras filas, ya convertidas; el código de cliente va enmascarado) | `POST $API/data-sources/$DS/mappings/{source_mapping_id}/preview` | `datasources.manage` |
| i | Sincronizar (acción aparte, nada sincroniza solo) | `POST $API/data-sources/$DS/sync` con `{"entity": "products", "mode": "full"}` y así por entidad | `datasources.manage` |

Qué revisar antes de aprobar (cualquiera de estos hallazgos exige una decisión, no un «aceptar todo»):

- **Campos obligatorios sin mapear** (`complete: false`): la plantilla no se puede aprobar. Típico: inventario sin columna de fecha de corte. Se resuelve con una vista en el origen que traiga la fecha o con otra
  fuente; el Auto Mapper no inventa la fecha.
- **Campos ambiguos** (`ambiguous`): dos columnas con la misma evidencia (listas de precios, fechas). Decide quien conoce el ERP.
- **Uniones inferidas** (advertencia «se infirió por el nombre… verifíquela»): comprobar que unen lo que parecen.
- **Valores de tipo y estado sin resolver** (`unresolved`): una cotización, proforma o guía NO es una venta y el importador la marcará para revisión; una letra suelta (`A`, `E`) es ambigua; códigos `0/1` en un estado
  se leen como bandera de anulación (1 = anulada): si en el origen significan otra cosa, declarar el mapeo de valores antes de aprobar.
- **Sin columna de modificación** (advertencia): cada sincronización lee todas las filas; aceptable para tablas pequeñas, valorar una vista con `fecha_modificacion` para las grandes.
- **Columnas con datos personales** (`pii_columns_excluded`): se excluyen solas; confirmar que no falta ningún dato imprescindible que no sea personal. Una identificación nacional (cédula, RUC de cliente) **no se propone** como código de cliente: se usa el identificador interno; si el cliente no tiene otro, asignarla a mano (el importador la guarda como huella).
- **Valores raros** (`suppressed_rare_values`): los valores de tipo o estado que aparecen en menos de 5 filas no se muestran; si existen en el origen, el importador los marcará para revisión y se resuelven con un mapeo de valores a mano.

Orden de las cargas (paso 6): productos, ventas, inventario, proveedores, plazos. Con SQL Server o MySQL, hacer primero una carga de prueba en un entorno de ensayo: sus conectores están implementados y probados solo
con catálogos de ejemplo.

## 4. Si el esquema del origen cambia

El ERP se actualiza y renombra o retipa una columna. `apply` ya no aplica (409 `schema_drift_review_required`), la plantilla de la empresa vuelve a `proposed` con el informe en `drift` y el mapeo SQL anterior **sigue activo
y fallará en la próxima sincronización** si su consulta ya no es válida (la sincronización queda `failed`, no importa nada). Procedimiento:

1. `POST …/mapping-templates/{id}/drift` para ver qué cambió (columnas quitadas, con otro tipo, agregadas).
2. `POST …/mapping-proposals` para proponer de nuevo sobre el esquema actual (versión siguiente); revisar con el cliente.
3. `approve` y `apply` de la versión nueva; sincronizar y conciliar el periodo afectado.

Una plantilla pública afectada no se modifica: se avisa a la plataforma (queda `mapping.template_drift_detected`); mientras tanto la empresa puede bifurcarla y adaptarla.

## 5. Checklist operativo por cliente (§22)

Cada casilla es una **acción del propietario**; la columna derecha dice con qué respalda el producto el paso y dónde queda el rastro.

- [ ] **Contrato, uso previsto, autorización de tratamiento y responsable de datos; límites, retención, costos y soporte.** Manual. Producto: consentimiento y retención por empresa (`tenant.consent`, retención en `tenants`), exportación y eliminación LOPDP.
- [ ] **Datos disponibles y permisos de solo lectura; validación de fuente, identidad cliente/SKU/sucursal, monedas y unidades.** Pasos 3–4. Rastro: descubrimiento (`mapping.discovery_run`), prueba de conexión con advertencia de escritura, hallazgos de calidad e identidades.
- [ ] **Mapeos revisados y versión; reconciliar totales netos y anulaciones; calidad e historia suficientes y funciones que deben degradarse.** Pasos 5–7. Rastro: plantilla aprobada (`approved_by`, versión, huella), `mapping.template_approved`, informe de reconciliación (`predictily:reconcile`), gates por módulo.
- [ ] **KPI comercial definido (p. ej. precisión a 7 días, top-K de oportunidades, beneficio de contribución, tiempo para contactar, margen, agotamientos), sin objetivos engañosos.** Manual con el cliente; el producto mide precisión y outcomes con su método (no fija objetivos).
- [ ] **Restricciones: stock, costos confiables, mínimos, capital, precios, lead time, calendario, responsables y acciones permitidas.** Paso 15 y `decisions/settings`; los costos no confiables dejan la contribución en «desconocida».
- [ ] **Manifest del dataset, punto de corte, pipeline/modelo, backtest/holdout aprobados; CLI local autorizado si aplica.** Pasos 8–11 (`TENANT_TRAINING_RUNBOOK.md`).
- [ ] **Checksum confirmado, carga a S3, versión aprobada, ejecución en AWS y aislamiento; nada de un cliente se copia a otro.** Aislamiento y checksum: **IMPLEMENTADO**; carga y ejecución en AWS: **BLOQUEADO**.
- [ ] **Usuarios, roles, tableros y plan configurados; pruebas de acción con revisión humana activadas.** Pasos 2, 17 y 18.
- [ ] **Ventana de piloto y grupo comparador; feedback y outcomes recogidos, fallos y pérdidas registrados; decisión GO/NO-GO.** Pasos 19–20; criterios de salida: `docs/roadmap/V25_RELEASE_GATES.md` (lo emite el cierre W6-F).
- [ ] **Costos reales de AWS por tenant y rentabilidad del contrato; documentación y política de reentrenamiento actualizadas.** **BLOQUEADO** hasta tener AWS; el modelo de costos está en `AWS_COST_MODEL.md`.

## 6. Qué es repetible y qué no

- **Repetible sin tocar código**: todo lo anterior vive en datos (plantillas, mapeos, ajustes de empresa, roles). Un segundo cliente con el mismo ERP puede arrancar de una plantilla pública aprobada por la plataforma
  (aplicarla si el esquema coincide con su huella, o bifurcarla); la primera vez de cada ERP se propone y se aprueba a mano.
- **No repetible hoy**: la propuesta no se ha medido contra ERPs reales (los umbrales son provisionales); MySQL y SQL Server no se han probado con servidor; el Bridge Windows y todo lo de AWS están **BLOQUEADOS**.
