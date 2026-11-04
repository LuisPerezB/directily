# Predictily V2.5 · Línea base del piloto con datos reales

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · Fuentes: V2.5 §18 T10 (pasos 2 y 3), §19 Gates A y B, §21 y §23 paso 6; procedimiento en
[`docs/operations/PILOT_RUNBOOK.md`](../docs/operations/PILOT_RUNBOOK.md) §2–§5.

> ## Estado: **BLOQUEADO**
>
> **No existe todavía ninguna línea base con datos reales.** El acceso de solo lectura al origen del cliente piloto existe, pero el propietario **no ha registrado las fuentes
> SQL Server** en la app local (`docs/operations/OWNER_RESPONSIBILITIES.md`, OWN-02). Sin fuentes registradas no hay carga histórica, conciliación ni backtest con datos reales.
>
> **Este archivo versionado nunca contendrá datos del cliente.** El repositorio es público: los resultados reales (métricas, conteos, totales de control, nombres de tablas o de
> productos, fechas identificables) se escriben **solo** en `reports/private/` (ignorado por git, `.gitignore`) y **no se copian aquí**. Aquí solo se describe el procedimiento y la
> forma del informe. Publicar un resumen sin datos identificables exige autorización expresa del propietario (OWN-12).

**Leyenda.** **IMPLEMENTADO / VERIFICADO** (la herramienta existe y tiene pruebas, con datos ficticios) · **DISEÑADO / NO EJECUTADO** (procedimiento escrito, no ejecutado con datos reales) ·
**BLOQUEADO** · **NO APLICA**.

## Contenido

- [1. Qué producirá la línea base](#1-qué-producirá-la-línea-base)
- [2. Precondiciones (propietario)](#2-precondiciones-propietario)
- [3. Procedimiento exacto](#3-procedimiento-exacto)
- [4. Métricas que se reportarán](#4-métricas-que-se-reportarán)
- [5. Reglas que el informe debe cumplir](#5-reglas-que-el-informe-debe-cumplir)
- [6. Qué hay hoy y qué falta](#6-qué-hay-hoy-y-qué-falta)
- [7. Tabla final por módulo](#7-tabla-final-por-módulo)

## 1. Qué producirá la línea base

Un informe **privado** `reports/private/V25_PILOT_BASELINE.md` (más sus JSON en `reports/private/pilot/`) con:

1. **Identidad de la evaluación:** fecha de corte as-of, zona horaria de la empresa, huella del dataset (`data_snapshots.hash` / `data_fingerprint`), `config_version`,
   versión del registro de contratos de features, versiones de los modelos y política que gobernó lo servido (`legacy` por defecto).
2. **Integridad de datos (Gate A):** conciliación **mes a mes** contra los totales de control calculados en el origen (documentos, líneas, unidades, importe neto, anulados, notas de
   crédito), con toda diferencia **explicada** o marcada como bloqueante; cobertura y huecos declarados; resultado del gate de calidad por módulo.
3. **Demanda (Gate B):** WAPE (con su denominador y estado), MAE, MASE, RMSSE, sesgo y cobertura de los intervalos del 80 % y 95 %, por horizonte y por segmento, **frente a las
   referencias ingenuas** (Seasonal Naive y la familia baseline), el mejor estadístico y LightGBM, en las ventanas de selección y en el período final reservado.
4. **Customer Core (Gate B):** probabilidad de compra calibrada o «no estimable» por horizonte (Brier, log loss, curvas de confiabilidad con *n*), ranking (precision@K, recall@K,
   lift@K, PR-AUC) frente a popularidad y recencia, cantidad por pedido y número de pedidos frente a sus referencias, ventana P10/P50/P90 solo si se valida por cobertura,
   calibración por segmento, abstenciones con su motivo.
5. **Limitaciones** declaradas (quiebres censurados, días `missing_source`, horizontes `insufficient_data`, segmentos sin cifra por poca muestra).

## 2. Precondiciones (propietario)

| # | Precondición | Referencia | Estado |
|---|---|---|---|
| 1 | Contrato y autorización de tratamiento del cliente piloto | OWN-01 | PENDIENTE |
| 2 | Fuentes SQL Server registradas en la app local, con `encrypt` / `trust_server_certificate` ajustados y prueba de conexión correcta | OWN-02, OWN-05 | **BLOQUEADO** |
| 3 | Cuenta del origen de solo lectura (`db_datareader`) | OWN-03 | decisión tomada con riesgo aceptado (cuenta con más permisos); la protección depende de `SqlQueryGuard` |
| 4 | Host o red del origen en la lista blanca local (`PREDICTILY_SQL_ALLOWED_HOSTS` / `PREDICTILY_SQL_ALLOWED_CIDRS`, no versionada) | OWN-37 | configurada en local |
| 5 | Fuente de verdad por entidad y periodo si hay más de una base | OWN-04 | PENDIENTE |
| 6 | Claves de firma de artefactos (`ENGINE_SIGNING_*`, `ENGINE_TRUSTED_PUBLIC_KEYS`) | OWN-38 | hecha en local |

## 3. Procedimiento exacto

Todo en **solo lectura** sobre el origen, en el equipo del propietario. Abreviatura: `CLI = engine/.venv/bin/python -m predictily_engine.cli` (desde la raíz del repositorio,
con el `.env` del motor). `T` = id de la empresa piloto en Predictily; `U` = su UUID; `S` = id de la fuente. Los archivos de salida van **siempre** a `reports/private/pilot/`.

| Paso | Qué | Comando o ruta | Estado de la herramienta |
|---|---|---|---|
| 1 | Probar la conexión | `POST /api/v1/data-sources/S/test` | IMPLEMENTADO / VERIFICADO (con PostgreSQL ficticio; SQL Server sin servidor de prueba) |
| 2 | Descubrir el esquema sin leer filas de negocio | `cd backend && php ../infra/scripts/pilot/sql_source_discovery.php S` (salida JSON en `reports/private/pilot/`) o `POST /api/v1/data-sources/S/schema/discover` | IMPLEMENTADO (SELECT fijos sobre el catálogo; en SQL Server `ApplicationIntent=ReadOnly`, `LOCK_TIMEOUT` 5 s, `READ UNCOMMITTED`) |
| 3 | Totales de control por mes **en el origen** | consultas de solo lectura del propietario; resultado en `reports/private/` | DISEÑADO / NO EJECUTADO |
| 4 | Proponer, revisar y aprobar los mapeos | `POST /api/v1/data-sources/S/mapping-proposals` → revisar → `POST /api/v1/mapping-templates/{id}/approve` (`settings.manage`) → `POST /api/v1/data-sources/S/mapping-templates/{id}/apply` | IMPLEMENTADO / VERIFICADO (W6-C, con esquemas ficticios) |
| 5 | Carga histórica `backfill` por entidad (catálogos, luego ventas e inventario) | `POST /api/v1/data-sources/S/sync` | IMPLEMENTADO / VERIFICADO (ERP ficticio) |
| 6 | Revisar calidad y cuarentena (`stale_skipped`, `updated_without_source_order`, `future_recorded_at`, `REVIEW_REQUIRED`) | `GET /api/v1/data-quality/extended` y la cuarentena | IMPLEMENTADO / VERIFICADO |
| 7 | Conciliar mes a mes contra los totales del paso 3 | `cd backend && php artisan predictily:reconcile U --entity=sales --from=AAAA-MM-DD --to=AAAA-MM-DD` | IMPLEMENTADO / VERIFICADO (los totales de «origen» del comando son los leídos; los del paso 3 se comparan aparte) |
| 8 | Declarar cobertura y huecos conocidos (cierres, caídas de integración) | `PUT /api/v1/data-coverage`, `POST /api/v1/data-coverage/gaps` | IMPLEMENTADO / VERIFICADO |
| 9 | Gates de calidad | `CLI validate-data --tenant-id T --core all` (BLOCK = salida 1: corregir los datos, no seguir) | IMPLEMENTADO / VERIFICADO |
| 10 | Huella y esquema de variables | `CLI build-features --tenant-id T --core demand --cutoff AAAA-MM-DD --out reports/private/pilot/features-demand.json` (y `--core customer --as-of AAAA-MM-DD`) | IMPLEMENTADO / VERIFICADO |
| 11 | Backtest de demanda as-of con período final reservado | `CLI backtest --tenant-id T --core demand --cutoff AAAA-MM-DD --out reports/private/pilot/backtest-demand.json`; o una ejecución histórica en la app: `cd backend && php artisan predictily:forecast U --cutoff=AAAA-MM-DD` (métricas en `GET /api/v1/models/evaluations`) | IMPLEMENTADO / VERIFICADO (datos ficticios) |
| 12 | Backtest del Customer Core | `CLI backtest --tenant-id T --core customer --as-of AAAA-MM-DD --out reports/private/pilot/backtest-customer.json` | IMPLEMENTADO / VERIFICADO (datos sintéticos) |
| 13 | Redactar el informe privado con la estructura de §1 | `reports/private/V25_PILOT_BASELINE.md` | DISEÑADO / NO EJECUTADO |

Notas de fidelidad: el backtest contra la base local reconstruye lo conocido en cada corte con las versiones de los documentos (W1). Si se entrena desde una exportación
autorizada (`CLI export-local … --from-export`), la exportación declara `asof_fidelity: approximate_no_versions` (no trae el historial de versiones): para la línea base se prefiere la
base local. Con 84–149 días de historia el horizonte 15 queda `insufficient_data` (no hay escalón de backtest de 15 días).

## 4. Métricas que se reportarán

| Métrica | Definición (implementada) | Nivel | Contra qué |
|---|---|---|---|
| WAPE | Σ\|error\| / Σ reales, con el denominador y su estado (`ok` · `unstable` · `undefined`); sin cifra si todos los reales son 0 | horizonte (7/14/15/30/60/90 que el backtest alcance) × segmento (clase de demanda, categoría, sucursal, tramo de volumen, vida de la serie as-of) | Seasonal Naive / baseline, mejor estadístico, LightGBM, servido |
| MAE, MASE, RMSSE | escalas por serie sin días con quiebre, fuente ausente ni cierre; escala indefinida = sin cifra, no 0 | ídem | ídem |
| Sesgo | Σ(pronóstico − real) / Σ\|real\| (`backtest/metrics.py::bias`); positivo = sobrestimación | ídem | ídem |
| Cobertura 80 % / 95 % | fracción de reales dentro del intervalo conformal | ídem | nominal |
| Brier, log loss, confiabilidad | por horizonte, calibración ajustada en un bloque posterior, *n* por grupo | Customer por horizonte y segmento | referencia de popularidad / recencia |
| precision@K, recall@K, lift@K, PR-AUC | por comprador × corte | Customer | popularidad, recencia, aleatorio |
| MAE/WAPE de cantidad por pedido y de nº de pedidos | modelo hurdle y su referencia | Customer | referencia de tasa |
| Cobertura de la ventana [P10, P90] | nominal 80 %; si no se valida, la ventana no se publica | Customer | — |

**Ningún objetivo numérico universal** (V2.5 §19): los umbrales de aceptación se fijan con el cliente en función de su historia, estacionalidad, costo y tolerancia al error
(OWN-09). Los umbrales de los gates de calidad y de las métricas son valores iniciales **a aprobar en el piloto** (OWN-13 a OWN-18).

## 5. Reglas que el informe debe cumplir

1. Ninguna cifra inventada ni completada: lo que no existe o no concilia se informa como limitación.
2. El período final reservado no se usa para elegir variables ni hiperparámetros (Optuna, si se usa, solo ve las ventanas de selección).
3. Se declara qué política gobernó lo servido y si el período final intervino en la decisión (`holdout_used_for_decision`).
4. No se compara con la demo ficticia ni se extrapolan sus cifras.
5. Ningún dato del piloto se envía a un proveedor LLM (`PILOT_RUNBOOK.md` §0 regla 4).
6. La línea base **no** es una evaluación prospectiva: mide el pasado con lo conocido en cada corte. La prospectiva está en [`V25_PROSPECTIVE_VALIDATION.md`](V25_PROSPECTIVE_VALIDATION.md).

## 6. Qué hay hoy y qué falta

| Pieza | Estado |
|---|---|
| Herramientas de los pasos 1, 2 y 4 a 12 | IMPLEMENTADO (verificadas con datos ficticios; última evidencia de integración: `docs/roadmap/evidence/2026-11-03-w6-integration/`, donde el E2E V2.5 recorre CSV, fuente SQL ficticia, calidad, cuarentena, entrenamiento con gates y aprobación: 160/160) |
| Totales de control en el origen y conciliación mes a mes con datos reales | **BLOQUEADO** (fuentes sin registrar) |
| Métricas de demanda y Customer con datos reales | **BLOQUEADO** |
| Informe privado | no existe; no se creará contenido real en este archivo |
| SQL Server con el controlador exacto | sin servidor de prueba (pruebas omitidas en la integración); se verificará con la fuente del piloto |

## 7. Tabla final por módulo

| Módulo | Evidencia | Estado de pruebas | Costo | Riesgo | Aprobación |
|---|---|---|---|---|---|
| Conexión y descubrimiento | `infra/scripts/pilot/sql_source_discovery.php`; `SqlSourceSyncTest`, `AutoMapperEndToEndTest` | verde con PostgreSQL ficticio (también en el E2E V2.5); SQL Server sin servidor | tiempo del propietario | cuenta del origen con más permisos que solo lectura | OWN-02, OWN-03, OWN-06 |
| Carga y conciliación | `predictily:reconcile`; `WindowReconciliationTest` | verde con datos ficticios | cómputo local | diferencias no explicadas bloquean el Gate A | OWN-07 |
| Línea base de demanda | CLI `backtest`; `FORECAST_VALIDATION.md` | verde con datos ficticios | CPU local | ninguna cifra real todavía | OWN-09, OWN-13–OWN-16 |
| Línea base de Customer Core | CLI `backtest --core customer`; `CUSTOMER_REPURCHASE.md` | verde con datos sintéticos | CPU local | IDs de comprador inestables degradan a agregado | OWN-17, OWN-18, OWN-64 |
| Informe | `reports/private/` | — | — | fuga de datos si se copiara aquí: **prohibido** | OWN-12 |
