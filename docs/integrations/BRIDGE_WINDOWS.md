# Predictily Bridge · agente de solo lectura (y su empaquetado en Windows)

> V2.5 · W6-B (ADR-V25-10; especificación §4). Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra),
> **IMPLEMENTADO / SIN VERIFICAR** (existe, pero no hay servidor ni host para probarlo), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO** (falta un recurso
> del propietario) o **NO APLICA**. Todo dato de ejemplo de este documento y de las pruebas es **ficticio**; nada se ha probado contra el ERP de un cliente.

## 0. Estado

| Capacidad | Estado | Evidencia |
|---|---|---|
| Agente Python 3.12 (`bridge/`, paquete `predictily_bridge`, dependencia obligatoria única: `cryptography`) | IMPLEMENTADO / VERIFICADO | `bridge/tests` (344 pruebas) |
| Adaptadores **PostgreSQL**, **SQLite**, **CSV** y **Excel (.xlsx)** | IMPLEMENTADO / VERIFICADO (PostgreSQL contra una base de pruebas ficticia, no contra el ERP de un cliente) | `test_postgres_integration.py`, `test_readonly_enforcement.py`, `test_files_adapter.py`, `BridgeAgentEndToEndTest` |
| Adaptadores **MySQL/MariaDB** y **SQL Server** | IMPLEMENTADO / SIN VERIFICAR (`implemented_untested`): solo pruebas con un cursor falso; no hay servidor | `test_readonly_enforcement.py` (cursor falso). Integración con servidor real: **BLOQUEADO** (sin servidor de pruebas) |
| Solo lectura en el origen (guardia SELECT + sesión de solo lectura del motor) | IMPLEMENTADO / VERIFICADO | §3 |
| Guardia SELECT con **paridad** con `SqlQueryGuard` (PHP) | IMPLEMENTADO / VERIFICADO (corpus de 187 casos: 175 generados con el guardia PHP; incluye `NEXT VALUE FOR`, `RECEIVE`, sugerencias de bloqueo de T-SQL y toda función `*_to_xml*`) | `test_sqlguard_parity.py`, `SqlGuardParityFixtureTest` |
| Registro del dispositivo: código de un solo uso, par Ed25519 local, huella, token con hash, firma de cada petición | IMPLEMENTADO / VERIFICADO | `BridgeEnrolmentTest`, `BridgeDeviceAuthTest`, `BridgeSignatureVectorsTest`, `test_signature_vectors.py` |
| Extracción incremental, marca de agua acotada, checkpoints confirmados por el servidor, reanudación sin duplicados | IMPLEMENTADO / VERIFICADO | `test_agent_resume.py`, `BridgeCheckpointTest`, `BridgeAgentEndToEndTest` |
| Reintentos con retroceso exponencial y jitter; búfer local cifrado y acotado; reenvío en orden e idempotente por SHA-256 | IMPLEMENTADO / VERIFICADO | `test_retry_cursor_batching.py`, `test_transport.py`, `test_agent_resume.py` |
| URL prefirmada por lote (empresa, dispositivo, entidad, SHA-256, tamaño, tipo, vencimiento), checksum validado en el servidor, registro en Bronze y pipeline de importación | IMPLEMENTADO / VERIFICADO | `BridgeUploadTest`, `BridgeAgentEndToEndTest` |
| Latido y revocación inmediata (un dispositivo revocado no sube nada; el agente borra su búfer) | IMPLEMENTADO / VERIFICADO | `BridgeDeviceAuthTest`, `BridgeAgentEndToEndTest::test_revoked_…` |
| Reprocesamiento determinista de un lote desde Bronze | IMPLEMENTADO / VERIFICADO | `BridgeUploadTest::test_bridge_batch_is_reprocessed_…` |
| **Instalador de Windows** (MSI/EXE), **servicio de Windows** y **firma de código** | **BLOQUEADO** (sin host Windows ni certificado de firma del propietario). Especificación de construcción: **DISEÑADO / NO IMPLEMENTADO** (`bridge/packaging/predictily-bridge.spec`, `bridge/packaging/windows_service.py`: NO construidos ni probados) | §5 |
| Ejecución como servicio en Linux (systemd) | DISEÑADO / NO IMPLEMENTADO (unidad de ejemplo en §4.5, no probada) | — |
| Descarga de mapeos aprobados desde el servidor (ADR-V25-10) | DISEÑADO / NO IMPLEMENTADO: en esta versión las consultas son **plantillas locales** y el servidor **nunca** envía SQL; el vínculo con las plantillas de mapeo de W6-C queda para el piloto | §8 |
| Inferencia de cobertura de fuente (`data_coverage`) desde el Bridge | DISEÑADO / NO IMPLEMENTADO: la cobertura se declara con la API de cobertura | §8 |
| ODBC genérico, Access, DBF; CDC | NO IMPLEMENTADO (ADR-V25-10: el Bridge no activa CDC) | `ERP_COMPATIBILITY.md` |

## 1. Arquitectura

```
 Red del cliente                                                       Predictily (SaaS)
┌─────────────────────────────────────────────┐                  ┌──────────────────────────────────────────────┐
│ ERP / base de datos / archivos              │                  │ POST /bridge/enrol          (código de 1 uso) │
│    ▲ SOLO SELECT (sesión de solo lectura)   │                  │ POST /bridge/heartbeat      (token + firma)   │
│    │                                        │   HTTPS saliente │ GET|POST /bridge/checkpoints                  │
│ predictily-bridge ── lotes NDJSON gzip ─────┼──────────────────▶ POST /bridge/uploads  → ruta prefirmada        │
│   · plantillas SELECT locales (versionadas) │  (sin puertos    │ PUT  /bridge/uploads/{dev}/{sha}  → Bronze    │
│   · búfer local cifrado (AES-256-GCM)       │   entrantes)     │        │                                      │
│   · clave privada Ed25519 + token (cifrados)│                  │        ▼  cola «imports»                      │
│   · credenciales del ERP (NUNCA salen)      │                  │ BridgeBatchProcessor → importador existente   │
└─────────────────────────────────────────────┘                  │   → ventas (Silver) con procedencia y linaje  │
                                                                 └──────────────────────────────────────────────┘
```

Principios: el agente **inicia** toda conexión (sin puertos entrantes); **no escribe nunca** en el origen; las **credenciales del ERP no se envían** a Predictily
(ni el host ni la cadena de conexión); el servidor **nunca envía SQL** al agente; la empresa y la fuente salen siempre del dispositivo autenticado, nunca de un
parámetro del agente.

## 2. Protocolo y propiedades de seguridad

Orden: **registro → token → extracción → subida prefirmada → Bronze → importación**.

1. **Alta (administrador).** `POST /data-sources/{id}/bridge/enrolments` (habilidad `datasources.manage`) genera un **código de un solo uso** (`BRG-XXXX-XXXX-XXXX-XXXX-XXXX`,
   100 bits) ligado a UNA fuente con el conector `predictily_bridge`. Se muestra una vez; en la base solo está su SHA-256; vence (60 min por defecto, máx. 24 h).
2. **Registro (agente).** `predictily-bridge enroll --code …` genera el par **Ed25519 localmente**, envía solo la clave **pública** y una **prueba de posesión**
   (firma de código, clave y hora) a `POST /bridge/enrol` (público, 10 intentos/min por IP). El canje consume el código de forma **atómica** (dos canjes simultáneos
   no pueden ganar ambos). Respuesta idéntica para código desconocido, vencido o usado (sin oráculo). El servidor guarda `public_key_fingerprint` (SHA-256 de la clave
   pública) y `token_hash` (SHA-256 del token `brt_…`); el **token se devuelve una sola vez**.
3. **Cada petición** exige **dos factores**: el token (`Authorization: Bearer brt_…`) **y** la firma Ed25519 de la petición
   (`PREDICTILY-BRIDGE-V1 \n MÉTODO \n RUTA \n CONSULTA \n TIMESTAMP \n NONCE \n SHA256(cuerpo)`, encabezados `X-Bridge-Key/Timestamp/Nonce/Signature`). Un token robado sin la clave
   privada no sirve; la marca de tiempo (±5 min) y el nonce de un solo uso impiden repetir una petición capturada; la firma cubre método, ruta y cuerpo. El estado del
   dispositivo se **relee en cada petición**: revocar tiene efecto inmediato. El nonce se guarda en la **base de datos** (`bridge_request_nonces`, clave primaria dispositivo + nonce, `INSERT … ON CONFLICT DO NOTHING`), no en la caché: la protección anti-repetición no depende de la durabilidad ni del tipo del almacén de caché (un `Cache::flush()` o una caché por proceso no la reabren). Además de los límites por credencial hay un límite **por IP antes de autenticar** (240/min): rotar valores de `Bearer` no lo evade.
4. **Extracción** (§3, §6): lotes de filas con campos canónicos → NDJSON UTF-8 → gzip **determinista** (mtime 0) → SHA-256.
5. **Declaración y URL prefirmada.** `POST /bridge/uploads` valida entidad permitida, límites (32 MB y 50 000 filas por defecto), columnas = campos canónicos de la entidad
   y obligatorios presentes, y la **cuota diaria de bytes de la empresa** (4096 MB por defecto; 429 `quota_exceeded` con `Retry-After`; los `duplicate` no consumen cuota). Si el contenido ya está en Bronze responde `duplicate` (idempotente por SHA-256; si su procesamiento había fallado lo vuelve a encolar). Si no,
   devuelve una **ruta relativa prefirmada** (firma de Laravel, 10 min) que fija empresa, dispositivo, entidad, SHA-256, tamaño, tipo y metadatos. El agente la une a SU URL
   de servidor configurada: el servidor **no puede desviarlo a otro host** (una URL absoluta se rechaza) y las redirecciones no se siguen.
6. **PUT.** El cuerpo se copia a un temporal acotado al tamaño declarado; se comprueban tamaño y **SHA-256** en el servidor (si no coinciden no se guarda nada). Después se **valida el lote COMPLETO en flujo, ANTES de
   guardar nada en Bronze**: gzip real (bytes `1f 8b 08`), cada línea un objeto JSON con claves dentro de las columnas declaradas (una columna extra —p. ej. un correo— que aparezca solo en la fila 2 se rechaza con
   422 `unknown_columns`), valores escalares de a lo sumo 2000 caracteres (un arreglo u objeto anidado es 422 `invalid_row`), número de filas **igual al declarado** (422 `row_count_mismatch`) y límites de filas, de bytes
   descomprimidos (512 MB) y de **relación de descompresión** (200×, a partir de 8 MB descomprimidos) frente a bombas de compresión. Si todo coincide se registra en Bronze con `BronzeStore` (`bridge_batch`, clave
   `tenants/<uuid>/bronze/<entidad>/<aaaa>/<mm>/<sha256>.ndjson.gz` generada por el servidor, procedencia `bridge:<dispositivo>`, huella y deriva de esquema) y se **encola** el procesamiento (cola `imports`; un solo trabajo
   por lote y empresa: `ShouldBeUnique`). Un objeto que llegara a Bronze con contenido inválido se pone en **cuarentena** (`status = quarantined`): no se reprocesa, no admite checkpoint y volver a declararlo responde 409 `batch_quarantined`.
7. **Checkpoint.** Solo tras el acuse del servidor el agente confirma con `POST /bridge/checkpoints`: el lote citado debe estar en Bronze para la misma empresa, entidad y fuente (409 `batch_not_found`) y **no haber
   fallado** (409 `batch_failed` si falló al procesarse o está en cuarentena: el agente lo conserva y lo reintenta en el siguiente ciclo). Cada lote confirmado entra en un **libro por extracción** (`bridge_run_batches`:
   dispositivo, entidad, `run_id`, posición 1…N). Un checkpoint intermedio guarda el cursor opaco (≤ 200 caracteres, con la posición del último lote para seguir contando tras perder el estado local). Al **completar** la
   extracción el servidor comprueba el libro: posiciones exactamente 1…N, sin huecos (409 `run_incomplete`) y ninguna fallida; solo entonces fija la **marca de agua**. Una extracción sin filas (`run_batches = 0`) no tiene
   lote que citar: su marca queda acotada por el servidor (no futura, no retrocede) pero no tiene otra prueba. Si el libro del servidor no coincide con el del agente (hueco, posición ocupada), el agente **descarta la corrida** y
   vuelve a extraer desde la última marca confirmada (los lotes son idénticos e idempotentes).
8. **Importación.** `BridgeBatchProcessor` lee el original de Bronze (verifica el SHA-256), aplica las mismas transformaciones que la sincronización SQL (`CanonicalStager::stageRows`:
   mapeos de valores y zonas horarias), escribe un CSV canónico y lo entrega a `CanonicalImportRunner` (validación, idempotencia por clave natural + `source_hash`, procedencia
   `data_source_id`, linaje `bronze_object_id`). Un lote con filas inválidas queda `needs_review` (el original está a salvo; se resuelve con `/imports/{id}/errors`); un fallo
   queda `failed` y se reintenta cuando el agente reenvía el mismo lote. Modo de ingestión de las ventas (ADR-V25-23): la primera extracción completa de la fuente es `backfill`;
   las demás, `incremental`.

| Propiedad | Cómo se logra | Prueba |
|---|---|---|
| Credenciales del ERP no salen del equipo | solo existen en el almacén cifrado local / variable de entorno; el cuerpo de enrolamiento solo lleva la clave pública | `test_cli.py::test_enroll_…`, `BridgeAgentEndToEndTest::test_agent_leaves_no_secret_…` |
| Token solo como hash; clave privada solo en el dispositivo | `token_hash`/`public_key_fingerprint`; token en `secrets.enc` cifrado | `BridgeEnrolmentTest`, `test_crypto_store.py` |
| Revocación inmediata y completa | se relee la fila; URL prefirmada ya emitida tampoco sube; el agente borra su búfer | `BridgeDeviceAuthTest::test_revocation_…` |
| Aislamiento entre empresas | empresa/fuente salen del dispositivo; Bronze y checkpoints son por empresa; la firma de la URL cubre la empresa | `BridgeDeviceAuthTest::test_device_of_another_company_…`, `BridgeCheckpointTest::…another_source_or_company…` |
| Un lote nunca se registra ni se importa dos veces | idempotencia por SHA-256 (Bronze único por empresa/entidad/contenido) y estado de procesamiento en el objeto | `BridgeUploadTest::test_replayed_batch_…`, E2E |
| Reanudación sin duplicados ni huecos | cursor por llave de orden confirmado por el servidor; estado local reconciliado con el servidor | `test_agent_resume.py`, E2E |
| Sin secretos en registros | filtro de registro (token, contraseñas, códigos, firmas) | `test_logging_redaction.py` |

## 3. Solo lectura en el origen

Capas (de fuera hacia dentro); ninguna sustituye a una **cuenta del cliente con `GRANT SELECT` únicamente**, que es la recomendación de producción:

1. **Plantillas locales, versionadas, validadas.** Cada consulta es un `SELECT` (o `WITH … SELECT`) escrito y revisado por el cliente en `config.toml`; se valida al cargar y de nuevo antes de
   cada ejecución con el **puerto de `SqlQueryGuard`**: una sola sentencia, sin escritura/DDL/DCL/transacciones/procedimientos, sin `SELECT … INTO`, sin bloqueos de filas, sin funciones u objetos de
   sistema (`pg_*`, `information_schema`, `sys.*`, `xp_*`…; cualquier función `*_to_xml*`), sin `NEXT VALUE FOR` (avanza una secuencia), sin `RECEIVE` ni sugerencias de bloqueo (`UPDLOCK`, `HOLDLOCK`, `XLOCK`, `TABLOCKX`) en T-SQL, sin barra invertida, `$`, `?`, `@`, `#` ni comentarios ejecutables, y con un único parámetro (`:since`). `templates_sha256` fija la huella del
   conjunto: si alguien cambia una consulta sin volver a fijarla, el agente no arranca. El agente informa al servidor las huellas de sus plantillas en el latido (auditoría); el servidor **nunca envía SQL**.
2. **Solo se ejecuta lo validado.** El agente envuelve la plantilla en una subconsulta (`SELECT * FROM (<plantilla>) … WHERE <llave > cursor> ORDER BY <llaves> LIMIT n+1`) con identificadores validados y
   **parámetros ligados** (nunca concatenados); el texto envuelto se valida otra vez. Aparte, existe un conjunto cerrado de sentencias de infraestructura (modo de solo lectura de la sesión,
   tiempos de espera, versión, permisos); cualquier otra sentencia lanza `ReadOnlyViolation` antes de llegar al controlador.
3. **Modo de solo lectura del motor.**

| Motor | Mecanismo | Estado |
|---|---|---|
| PostgreSQL | `default_transaction_read_only=on` + `BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY` por página + `statement_timeout`; el servidor rechaza `INSERT/UPDATE/DELETE/TRUNCATE/CREATE/DROP` y `SET TRANSACTION READ WRITE` (probado con la conexión cruda del agente) | VERIFICADO (`test_the_database_itself_refuses_writes_…`) |
| SQLite | archivo abierto con `mode=ro` + `PRAGMA query_only` + autorizador del motor que solo permite SELECT/READ/FUNCTION (no `sqlite_*`, no `load_extension`) | VERIFICADO (`test_sqlite_engine_refuses_writes_…`) |
| CSV / Excel | archivos abiertos solo en lectura; no se modifican, mueven ni borran | VERIFICADO (`test_files_adapter.py`) |
| MySQL / MariaDB | `SET SESSION TRANSACTION READ ONLY` + `START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT` por página | SIN VERIFICAR (cursor falso; sin servidor) |
| SQL Server | no existe transacción de solo lectura: `ApplicationIntent=ReadOnly` + cuenta `db_datareader`; el agente **consulta los permisos** y advierte (o, con `require_readonly_account = true`, se niega a extraer) si la cuenta puede escribir | SIN VERIFICAR (cursor falso; sin servidor) |

4. **Cuenta con privilegios de escritura.** `describe()` y el latido informan `writable_account`; con `require_readonly_account` el agente no extrae. Su valor por defecto es **`true` en MySQL y SQL Server** (donde el motor no garantiza el solo lectura como PostgreSQL/SQLite) y `false` en el resto; en producción se recomienda `true` en todos. En PostgreSQL los privilegios se leen como **efectivos**, también los heredados por pertenencia a roles (`has_table_privilege`, `pg_has_role`, rol `pg_write_all_data`): verificado con un rol de grupo con `INSERT` (`test_writable_account_is_detected_through_inherited_role_grants_too`, requiere un rol con `CREATEROLE` vía `TEST_ERP_ADMIN_USERNAME`; sin él se omite con un mensaje explícito). En las pruebas, la cuenta
   propietaria de la base ficticia dispara la advertencia (`test_account_with_write_privileges_…`).

Verificación de que el origen no cambia: las pruebas comparan el SHA-256 del archivo SQLite y un `md5` del contenido de las tablas de PostgreSQL antes y después de extraer.

## 4. Instalación y operación (cualquier sistema operativo)

### 4.1 Requisitos
Python 3.12; `pip install './bridge[postgres]'` desde el repositorio (el paquete no está publicado en un índice público; extras: `mysql`, `sqlserver` —requiere *ODBC Driver 18 for SQL Server* en el equipo—, `excel`, `keyring`). Salida HTTPS hacia el host de Predictily (443). Ningún puerto entrante.

### 4.2 Configuración
`bridge/packaging/config.example.toml` es un ejemplo comentado **sin secretos**. `config.toml` se rechaza si contiene `password`/`token`, si es legible por «otros» (POSIX) o si `[server] url` no es `https://`
(única excepción, para pruebas: `http://localhost` con `allow_insecure_localhost = true`). La contraseña del origen va en el almacén cifrado (`predictily-bridge secret set source_password`, lee la entrada estándar)
o en una variable de entorno (`password_env`). `[agent] source_timezone` es obligatoria con `recorded_at_column`: el «desde» incremental se expresa en la hora local del origen. **PostgreSQL exige TLS por defecto** (`sslmode = "require"`); para un PostgreSQL LOCAL sin TLS (solo pruebas) se relaja de forma explícita con `sslmode = "prefer"` o `"disable"`; en producción use `verify-full`.

**Plantillas** (`[[templates]]`): `entity` (ventas, productos, inventario…), `version`, `sql` con alias = **campos canónicos** de Predictily (el servidor rechaza columnas desconocidas: `unknown_columns`),
`order_by` (columnas del resultado que ordenan TOTALMENTE las filas, llave única y `NOT NULL`, para reanudar sin repetir; el cursor ≤ 200 caracteres), `recorded_at_column` (fecha de modificación en el ERP; marca de agua).
Se usa la plantilla de mayor versión de cada entidad. En SQL Server la plantilla debe ser un `SELECT` simple (sin `WITH`/`ORDER BY` internos, porque se envuelve en una subconsulta): use vistas.
Para CSV/Excel: `path` (admite `*`), `columns` (campo canónico → encabezado), `delimiter`, `encoding`, `sheet`.

Opciones de la fuente en Predictily (`data_sources.config.bridge`, se validan al procesar cada lote): `source_timezone`, `value_maps` (mapeos de valores, p. ej. estados del ERP) e `import`
(`skip_invalid_rows`, `default_branch_code`, `create_missing_products|branches|categories`).

### 4.3 Comandos

```
predictily-bridge check-config --pin        # valida y muestra la huella a fijar en templates_sha256
predictily-bridge secret set source_password
predictily-bridge test-source               # versión, modo de solo lectura efectivo, advertencias
predictily-bridge enroll --code BRG-…       # una vez
predictily-bridge run --once                # un ciclo (cron/tareas programadas) · sin --once: bucle de servicio
predictily-bridge status                    # estado local sin secretos
```
Códigos de salida: 0 correcto · 1 error · 2 configuración · 3 dispositivo revocado · 4 alguna entidad con error, bloqueada, pausada o sin conexión. Variables: `PREDICTILY_BRIDGE_HOME`,
`PREDICTILY_BRIDGE_LOG_LEVEL`, `PREDICTILY_BRIDGE_LOG_FILE`, `PREDICTILY_BRIDGE_MASTER_KEY` (clave maestra por entorno, para contenedores).

### 4.4 Qué guarda el agente y cómo
`<home>/` (0700): `device.json` (no secreto), `secrets.enc` (token, clave privada, secretos de la fuente), `state.enc` (estado de extracción), `spool/` (lotes pendientes), todo cifrado con AES-256-GCM y una
**clave maestra** (variable de entorno → almacén del sistema con el extra `keyring` → `master.key` 0600; este último es el **último recurso** y el agente lo advierte en el registro —una vez por proceso— y en la salud de cada latido). Honestidad: el archivo `master.key` protege contra copias de seguridad y lectura por otros usuarios,
**no** contra quien ya controla la cuenta del servicio; en Windows se recomienda el almacén del sistema (DPAPI) y una cuenta de servicio dedicada (§5). El búfer está **acotado** (`spool_max_mb`): al llenarse,
la extracción se detiene (contrapresión) y nunca se descarta un lote sin enviar. Un dispositivo **revocado** borra su búfer y se niega a seguir (`revoked.flag`).

### 4.5 Servicio en Linux (DISEÑADO / NO PROBADO)
```ini
# /etc/systemd/system/predictily-bridge.service
[Service]
User=predictily-bridge
Environment=PREDICTILY_BRIDGE_HOME=/var/lib/predictily-bridge
ExecStart=/opt/predictily-bridge/.venv/bin/predictily-bridge run
Restart=on-failure
RestartSec=30
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/var/lib/predictily-bridge
```

## 5. Windows: empaquetado, servicio y firma · **BLOQUEADO** (diseño)

**BLOQUEADO** hasta disponer de (a) un host Windows 10/11 o Server 2019+ x64 con Python 3.12 y (b) el **certificado de firma de código del propietario** (OV o EV, con su `signtool`/token). Nada de esto se ha
construido ni ejecutado. Los archivos `bridge/packaging/predictily-bridge.spec` (PyInstaller, carpeta, sin «onefile») y `bridge/packaging/windows_service.py` (servicio con `pywin32`) son el **diseño**; la
lógica que invocan (`cli.run_service`, el bucle con parada limpia) sí está probada en cualquier sistema operativo (`test_cli.py::test_run_service_…`).

**Pasos exactos para el propietario (PowerShell de administrador en el host Windows):**

1. Instalar Python 3.12 x64 y, si el origen es SQL Server, *Microsoft ODBC Driver 18 for SQL Server*.
2. `cd bridge` · `py -3.12 -m venv .venv` · `.venv\Scripts\pip install -e ".[postgres,sqlserver,mysql,excel,keyring,windows-package]"`.
3. Ejecutar las pruebas en Windows: `.venv\Scripts\python -m pytest -q` (las de permisos POSIX se omiten solas; las de PostgreSQL requieren `TEST_ERP_*`). **Esto es el criterio de aceptación de portabilidad.**
4. Construir: `.venv\Scripts\pyinstaller packaging\predictily-bridge.spec --noconfirm` → `dist\predictily-bridge\predictily-bridge.exe`.
5. **Firmar** (certificado del propietario; el certificado y su contraseña NUNCA se guardan en el repositorio):
   `signtool sign /fd SHA256 /tr http://timestamp.digicert.com /td SHA256 /a dist\predictily-bridge\predictily-bridge.exe` y las DLL propias; verificar con `signtool verify /pa /v …`.
6. Instalar en `C:\Program Files\Predictily\Bridge` (solo lectura para la cuenta de servicio). Datos en `C:\ProgramData\Predictily\Bridge` (`PREDICTILY_BRIDGE_HOME`).
7. Crear una **cuenta de servicio local sin privilegios** (`svc-predictily-bridge`, «iniciar sesión como servicio») y restringir el directorio de datos:
   `icacls "C:\ProgramData\Predictily\Bridge" /inheritance:r /grant:r "svc-predictily-bridge:(OI)(CI)F" "SYSTEM:(OI)(CI)F"`.
8. Registrar el servicio: nativo con `windows_service.py` (`predictily-bridge-service.exe --startup auto install`) **o** con NSSM/WinSW apuntando a `predictily-bridge.exe run`; configurar reinicio automático
   (`sc.exe failure PredictilyBridge reset= 86400 actions= restart/60000/restart/60000/restart/60000`).
9. Como esa cuenta: `predictily-bridge secret set source_password` (con el extra `keyring` la clave maestra queda en el almacén de Windows/DPAPI), `check-config --pin`, `test-source`, `enroll --code …`, `run --once` y `status`.
10. Cortafuegos: **solo salida** TCP 443 hacia el host de Predictily; si hay proxy corporativo, definir `HTTPS_PROXY` para la cuenta de servicio y, si inspecciona TLS, `[server] ca_bundle`. Ningún puerto entrante.
11. Aceptación: un ciclo completo con una cuenta de solo lectura real; revocar el dispositivo desde Predictily y comprobar que el servicio deja de enviar y borra el búfer; reinicio del equipo a mitad de una extracción y reanudación sin duplicados.

Actualización: reemplazar la carpeta firmada con el servicio detenido; el estado (`state.enc`, `spool/`) se conserva. Desinstalación: `sc.exe delete PredictilyBridge`, borrar los directorios y **revocar el dispositivo** en Predictily.

## 6. Pruebas (comandos exactos)

```
# Agente (desde bridge/; crear el entorno: python3.12 -m venv .venv && .venv/bin/pip install -e '.[dev]')
cd bridge && TEST_ERP_DATABASE=<bd_erp> TEST_ERP_PASSWORD=<clave> .venv/bin/python -m pytest -q          # 344 pruebas
#   sin base PostgreSQL, las 9 de integración con PostgreSQL se omiten con un mensaje explícito (no es un fallo); la de privilegios heredados por roles necesita además `TEST_ERP_ADMIN_USERNAME` (un rol con `CREATEROLE`)

# Backend (PHP_INI_SCAN_DIR de este Mac: ver docs del repositorio)
cd backend && LARAVEL_STORAGE_PATH=<S> DB_DATABASE=<bd> TEST_ERP_DATABASE=<bd_erp> php artisan test --filter='Bridge'
#   Feature: BridgeEnrolmentTest (9) · BridgeDeviceAuthTest (10) · BridgeUploadTest (14) · BridgeCheckpointTest (11) · BridgeHardeningTest (16)
#   Unit: BridgeSignatureVectorsTest (4) · SqlGuardParityFixtureTest (176)
#   Extremo a extremo con el agente REAL: BridgeAgentEndToEndTest (6)
```
El extremo a extremo (`BridgeAgentEndToEndTest`) arranca un servidor HTTP real del backend (`php -S`, almacenamiento y caché aislados), ejecuta el agente Python como proceso (CLI) contra una base «ERP de origen»
ficticia en PostgreSQL (tablas con prefijo aleatorio) y procesa la cola `imports` como en producción. Se **omite con un mensaje explícito** si falta una dependencia (base ERP, entorno Python del agente con `psycopg`,
`proc_open` o un puerto libre). Resultado verificado: 3 lotes en Bronze con SHA-256 íntegro, 6 documentos y 8 líneas de venta (el mismo resultado que la sincronización SQL del backend), cédula nunca en claro,
marca de agua acotada, ERP sin cambios, reanudación tras interrupción sin lotes repetidos, revocación efectiva.

### Paridad con `SqlQueryGuard`
`bridge/tests/fixtures/sql_guard_cases.json` se **genera con el guardia PHP** (`python3 bridge/tests/tools/make_guard_corpus.py`, requiere `php` y `backend/vendor`): 175 casos con dialectos PostgreSQL, MySQL y SQL Server
(veredicto, código de rechazo y texto resultante) más 12 de SQLite (dialecto propio del agente, sin paralelo PHP). Lo comprueban `SqlGuardParityFixtureTest` (PHP: si alguien cambia una regla de `SqlQueryGuard` falla)
y `test_sqlguard_parity.py` (Python). Para cambiar una regla: modificar el guardia PHP, regenerar el corpus, ajustar `bridge/src/predictily_bridge/sqlguard.py` y ejecutar ambas suites. Los vectores de firma
(`signature_vectors.json`) cumplen la misma función entre `BridgeSignature.php` y `crypto.py`.

## 7. Operación y diagnóstico

- Administración (`datasources.manage`): `GET /bridge/devices` (estado `active` / `offline` —sin latido en 15 min— / `revoked`, huella, versión, salud informada), `GET /bridge/devices/{id}` (con checkpoints),
  `POST /bridge/devices/{id}/revoke`, `DELETE /bridge/devices/{id}/checkpoints/{entity}` (pide una carga completa nueva; los lotes ya recibidos no se duplican).
- Un lote `needs_review` o `failed` figura en `bronze_objects.metadata.bridge.processing` (y en `/lineage/bronze`); la fuente refleja `last_error`.
- Auditoría: `bridge.enrolment_issued`, `bridge.device_enrolled`, `bridge.batch_received|completed|needs_review|failed`, `bridge.checkpoint`, `bridge.checkpoint_reset`, `bridge.device_revoked` (sin secretos).
- Operación en producción: la cola `imports` debe tener trabajadores; la caché **compartida** entre instancias (Redis) solo la necesitan la salud informada por el agente (informativa) y el bloqueo de unicidad de los trabajos (`ShouldBeUnique`). La protección anti-repetición vive en la base de datos y no depende de la caché.
- Cuotas y tasas (valores iniciales **a aprobar en el piloto**): cuota diaria por empresa (`PREDICTILY_BRIDGE_TENANT_DAILY_QUOTA_MB`, 4096 MB; 0 = sin tope), límite por credencial (240/min) y por IP (240/min). **Cuota por dispositivo: DISEÑADA, no implementada.**

## 8. Lo que NO hace esta versión (honesto)

- No hay instalador ni servicio de Windows construidos ni firmados (§5, BLOQUEADO).
- MySQL/MariaDB y SQL Server: sin servidor de pruebas; solo pruebas con cursor falso.
- El servidor no distribuye mapeos aprobados ni SQL; la relación con las plantillas de mapeo de W6-C (`mapping_templates`) queda **DISEÑADA**: hoy el cliente escribe la plantilla con los alias canónicos.
- El Bridge no declara cobertura de fuente (`data_coverage`); el administrador la declara con su API.
- Sin CDC, sin ODBC genérico/Access/DBF, sin escritura en el ERP (ni en P0/P1 ni después).
- Un mismo contenido idéntico en dos fuentes distintas de la misma empresa se rechaza (409 `batch_conflict`); Bronze identifica los objetos por (empresa, SHA-256, entidad).
- Subida directa a S3 con URL prefirmada de S3: DISEÑADA (hoy la URL prefirmada es del propio backend, que registra en el almacén Bronze configurado, local o S3).
- Los umbrales (lote de 5 000 filas, 32 MB, solape de 120 min, búfer de 512 MB, vigencias de 10 min / 60 min) son valores iniciales razonables, **a aprobar en el piloto**; no son universales.
