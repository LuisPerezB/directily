# Runbook del piloto con datos reales (Resgasa · Soyoda)

Especificación: V2.5 §17 (onboarding), §18 T01/T10, §19 Gate A/B/E y §22 (checklist del propietario).
Estado de este runbook: **DISEÑADO / NO EJECUTADO**. Se ejecuta en el equipo del propietario mediante Remote Control,
porque las bases de Soyoda y Resgasa solo son accesibles desde su red (VPN).

## 0. Reglas que no se negocian

1. **Solo lectura en el origen.** Usuario de base de datos de solo lectura (PostgreSQL: rol con `SELECT` y
   `default_transaction_read_only`; MySQL/MariaDB: solo `SELECT`; SQL Server: `db_datareader`). Ninguna migración,
   escritura, `CDC` ni consulta fuera de las autorizadas (las impone `SqlQueryGuard`).
2. **Credenciales** solo en la fuente de datos cifrada de Predictily o en el `.env` local no versionado. Nunca en
   código, documentos, logs, mensajes ni commits.
3. **Repositorio público.** Ningún dato real, extracto, nombre de cliente, documento o métrica identificable de la
   empresa se versiona sin autorización expresa del propietario. Los resultados con datos reales van a
   `reports/private/` y los extractos a `data/private/`, ambos ignorados por git.
4. **PII mínima.** El importador guarda la identificación de clientes como pseudónimo (HMAC); los nombres no se
   exportan ni se envían a terceros. Ningún dato del piloto se envía a un proveedor LLM.
5. **Sin cifras inventadas.** Si un dato no existe o no concilia, se informa la limitación; no se completa.

## 1. Preparación del equipo (una vez)

| Paso | Detalle |
|---|---|
| Remote Control | carpeta del repositorio (no el directorio personal) en Mac, Linux o **WSL** (en Windows, clonar dentro de WSL); abrir ahí la app de escritorio de Claude (Remote Control) o `claude remote-control` |
| Red | VPN activa y el host de la base alcanzable desde esa carpeta (en WSL2, si la VPN corre en Windows, puede requerir `networkingMode=mirrored` en `.wslconfig`) |
| Herramientas | PHP 8.3 con `pdo_pgsql` y el driver del motor de origen (`pdo_mysql` o `pdo_sqlsrv`), Composer, Python 3.12, PostgreSQL 16 y Redis locales para Predictily |
| Código | `git checkout main && git pull`; `cd backend && composer install && php artisan migrate`; `cd engine && python3.12 -m venv .venv && .venv/bin/pip install -e .` |
| Firma de modelos | `python -m predictily_engine.cli keygen --key-id <id> --private-out <ruta segura>` y variables `ENGINE_SIGNING_*` / `ENGINE_TRUSTED_PUBLIC_KEYS` (ver `docs/ml/MODEL_REGISTRY.md`) |
| Anti-SSRF | declarar el host o la red de la base en `PREDICTILY_SQL_ALLOWED_HOSTS` o `PREDICTILY_SQL_ALLOWED_CIDRS` del backend (las redes privadas se deniegan por defecto) |

## 2. Descubrimiento del origen (solo lectura)

1. Motor y versión de las bases de Soyoda y de Resgasa; zona horaria de sus marcas de tiempo.
2. Tablas de cabecera y detalle de ventas, notas de crédito/devoluciones, productos, clientes, sucursales,
   inventario y compras; claves y relaciones.
3. Rango de fechas y volumen por mes; valores de tipo y estado de documento (para `value_maps`); columnas de fecha
   de registro o modificación (para `source_recorded_at` y el orden de versiones).
4. **Totales de control por mes** en el origen: documentos, líneas, unidades, importe neto, anulados y notas de
   crédito. Se guardan en `reports/private/` y sirven para conciliar.
5. Diferencias entre la base de Soyoda y la de Resgasa (la de Resgasa tiene el histórico de ventas más
   actualizado): decidir cuál es la fuente de verdad por entidad y por periodo, y documentarlo.

## 3. Configuración en Predictily (local)

1. Empresa «Resgasa (piloto)», usuario propietario y sucursales.
2. Fuente de datos `sql_postgresql`, `sql_mysql` o `sql_sqlserver` con la cuenta de solo lectura; `POST
   /api/v1/data-sources/{id}/test`.
3. Mapeos por entidad (`POST /api/v1/data-sources/{id}/mappings`): una sola consulta `SELECT` autorizada y su
   `field_map`; `preview` y `approve` (aprobación explícita).
4. Ventas: incluir `source_recorded_at` si el origen lo tiene; `value_maps` de tipo y estado; la primera
   sincronización es **backfill** (carga histórica, ADR-V25-23).

## 4. Carga histórica y conciliación (Gate A)

1. Sincronizar por entidad (`POST /api/v1/data-sources/{id}/sync`): catálogos primero, luego ventas e inventario.
2. Revisar estadísticas y calidad: `stale_skipped`, `updated_without_source_order`, `future_recorded_at`, errores
   `REVIEW_REQUIRED`.
3. Conciliar mes a mes contra los totales de control del paso 2 (documentos, unidades, neto, anulados, notas de
   crédito). Toda diferencia queda **explicada** o bloquea el Gate A.
4. Declarar cobertura y huecos conocidos (`/api/v1/data-coverage`, `/api/v1/data-coverage/gaps`).

## 5. Línea base predictiva con datos reales (Gate B)

1. Pronóstico de demanda con backtest rolling-origin y periodo final reservado; comparación con Seasonal Naive y
   métricas por horizonte y segmento (WAPE con su denominador, MASE, sesgo, cobertura de intervalos).
2. Customer Core: entrenamiento y evaluación por horizonte (Brier, log loss, precision@K frente a popularidad).
3. Informe en `reports/private/V25_PILOT_BASELINE.md`, con fecha de corte, huella del dataset y versiones de
   modelos. Solo se publica en el repositorio un resumen sin datos identificables, si el propietario lo autoriza.

## 5 bis. Lecciones del primer pull real con SQL Server (2026-11-06)

Sin datos del cliente: solo lo que cambia la forma de ejecutar los pasos 1–4.

| Tema | Qué pasa | Qué hacer |
|---|---|---|
| OpenSSL en macOS (Homebrew) | El ODBC Driver 18 carga OpenSSL desde `/opt/homebrew/opt/openssl/lib`. Si `openssl` ya apunta a OpenSSL 4, toda conexión falla con «OpenSSL library could not be loaded». | Arrancar cada proceso PHP que conecte (servidor, worker, pruebas) con `DYLD_LIBRARY_PATH=/opt/homebrew/opt/openssl@3/lib`, invocando `php` directamente: los scripts con `#!/usr/bin/env` pierden las variables `DYLD_*`. |
| DSN de `pdo_sqlsrv` | `CharacterSet` no es válido en el DSN de PDO («invalid keyword»). | Corregido en `SqlServerDialect` (la codificación va como atributo PDO) y cubierto por prueba. |
| Fuente creada con la prueba fallida | Queda `inactive` y una prueba posterior correcta no la reactiva: la sincronización responde `source_inactive`. | `PATCH /api/v1/data-sources/{id}` con `{"status": "active"}` tras una prueba correcta. |
| Vista previa en SQL Server | Envuelve la consulta en una tabla derivada: no admite `WITH` (CTE) ni `ORDER BY` de nivel superior. | Escribir la consulta con tablas derivadas y `UNION ALL`. |
| Intercalaciones mezcladas | Tablas del mismo ERP con `SQL_Latin1_General_CP1_CI_AS` y `Modern_Spanish_CI_AS`: los `JOIN`/`UNION` fallan. | `COLLATE DATABASE_DEFAULT` en cada texto que se compara o se une. |
| Consultas históricas pesadas | `statement_timeout_seconds` vale 60 por defecto. | Declararlo en la configuración de la fuente (máximo 600). |
| Tablas de «histórico» del ERP | Algunas son registros de sincronización con copias repetidas del mismo documento. | Verificar unicidad por clave natural antes de mapear y deduplicar dentro de la consulta. |

## 6. Lo que este runbook no cubre todavía

Evaluación prospectiva (requiere una ventana futura acordada con el cliente: Gate E), Revenue Radar, Decision
Engine y outcomes (oleada W5), y el informe de reconciliación automático por capas (oleada W2). Ver
`V25-ESTADO-Y-PROXIMOS-PASOS.md`.
