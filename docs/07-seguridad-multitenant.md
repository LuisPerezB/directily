# 07 · Plan de seguridad, aislamiento multi-tenant y privacidad

> **Aviso:** este documento describe medidas técnicas y organizativas de diseño. **No constituye
> asesoría legal.** El cumplimiento de la Ley Orgánica de Protección de Datos Personales (LOPDP,
> R.O. Suplemento 459 de 26-may-2021) y su Reglamento debe validarse con un profesional del
> derecho en Ecuador antes del lanzamiento comercial, incluyendo los contratos con clientes.

## 1. Modelo de amenazas (resumen)

| Amenaza | Ejemplo | Mitigación principal |
|---|---|---|
| Acceso cruzado entre empresas (IDOR) | Usuario de A pide `/products/123` de B | Middleware de tenant + global scope fail-closed + 404 genérico + FKs compuestas |
| Escalada de privilegios | Usuario de consulta intenta importar o cambiar roles | Abilities por rol en cada ruta (`tenant.ability:*`) y policies |
| Fuga en procesos asíncronos | Job procesa datos con el tenant equivocado | Jobs con `tenantId` explícito + middleware `SetTenantContext`; el motor exige `tenant_id` en cada consulta |
| Robo de sesión / CSRF | Sitio malicioso envía peticiones con la cookie | Cookies httpOnly + SameSite=Lax + `Secure` en HTTPS; CSRF de Sanctum |
| Inyección SQL | Parámetro `sort=name;DROP...` | Query builder parametrizado; ordenamientos y filtros por lista blanca |
| XSS | Nombre de producto con `<script>` | Vue escapa por defecto; prohibido `v-html` con datos de usuario; CSP estricta |
| Archivos maliciosos | CSV con fórmulas (`=HYPERLINK`) o xlsx malformado; **bomba de descompresión (ZIP bomb)** en un XLSX | Validación de tipo/tamaño; lectura en streaming; exportaciones CSV neutralizan celdas que inician con `= + - @`; el XLSX se inspecciona (directorio ZIP) **antes** de abrirse y su lectura inicial está acotada (§16) |
| Abuso / fuerza bruta | Intentos masivos de login | Rate limiting en nginx y Laravel; registro de `auth.failed` |
| Exposición del motor | Acceso directo a FastAPI | Red Docker interna sin puertos publicados + token Bearer comparado en tiempo constante |
| Pérdida de datos | Falla de disco, error humano | Respaldos diarios con rotación y restauración probada |
| Exposición de PII en logs | Correos o cédulas en trazas | Procesador de logs que enmascara PII; prohibido registrar cuerpos de petición |
| Conector SQL usado para escribir o para alcanzar la red interna (SSRF) | Una consulta autorizada con `DELETE`, o un host `169.254.169.254` / `::ffff:169.254.169.254` / `0xA9FEA9FE` / `10.x` interno de Predictily, o un nombre cuyo DNS cambia entre la validación y la conexión | Cuenta de solo lectura + validador de una sola `SELECT` + transacción de solo lectura + política de destino segura por defecto: solo nombres RFC 1123 o IP canónicas, A+AAAA, IP validada y fijada, redes privadas denegadas salvo lista blanca (§13.2) |
| Modelo serializado malicioso (ejecución de código al deserializar un pickle) | Fila de `model_registry` + archivo `.joblib` con `__reduce__` y SHA-256 coherente en el volumen de artefactos | Firma Ed25519 del manifiesto verificada con claves de confianza **antes** de `joblib.load`; sin firma, clave desconocida o bytes alterados no se deserializa (§17) |
| Fuga de datos hacia un proveedor LLM o cifras inventadas por el modelo | El asistente cita cifras de otra empresa, calcula por su cuenta o recibe datos personales | Herramientas de lista blanca por empresa, abilities y plan, verificación numérica, saneamiento de PII, desactivación sin clave (§14) |

## 2. Aislamiento multi-tenant: defensa en profundidad

| Capa | Mecanismo | Implementación |
|---|---|---|
| 1. Resolución | La empresa activa sale del header `X-Tenant-ID` (uuid) y se verifica la membresía del usuario; los tokens de integración están ligados a una empresa (`personal_access_tokens.tenant_id`) e ignoran el header. Respuesta **404** si no es miembro (no revela existencia); **423** si la empresa está suspendida o en eliminación. | `app/Http/Middleware/ResolveTenant.php` |
| 2. Consultas | Global scope `BelongsToTenant` en todos los modelos de negocio: filtra por `tenant_id` y **falla** si no hay empresa en contexto (fail-closed). `tenant_id` no es asignable masivamente; se fija desde el contexto al crear e impide crear registros de otra empresa. `withoutTenantScope()` solo para tareas de plataforma auditadas. | `app/Models/Concerns/BelongsToTenant.php` |
| 3. Autorización | Abilities por rol verificadas en rutas (`tenant.ability:forecast.run`) y policies por recurso. | `app/Enums/TenantRole.php`, `EnsureTenantAbility` |
| 4. Base de datos | FKs compuestas `(tenant_id, x_id) → x(tenant_id, id)`: la BD rechaza referencias entre empresas aunque exista un error de aplicación. | Migraciones |
| 5. Asíncrono | Cada Job transporta `tenantId` y restablece el contexto; el contexto es `scoped` y se limpia entre trabajos. El motor valida que `run_id`/`job_id` pertenezcan al `tenant_id` recibido y filtra cada lectura/escritura por él. | `SetTenantContext`, repositorio del motor |
| 6. Archivos y artefactos | Rutas `tenants/{tenant_id}/imports|exports/...` en disco privado (no público); artefactos de modelos en `artifacts/{tenant_id}/...`; descargas mediante URL temporal firmada tras verificar permisos. | Servicios de importación/exportación, motor |
| 7. Caché y colas | Claves de caché prefijadas con `tenant:{id}:`; nunca se cachean respuestas entre empresas. | Servicios |
| 8. Pruebas | Pruebas automatizadas: un usuario de A no puede leer, modificar, importar, pronosticar ni exportar datos de B por ningún endpoint; el motor no lee datos de B al ejecutar A. | `tests/Feature/*Isolation*`, `engine/tests` |
| 9. Endurecimiento futuro | **Row Level Security** de PostgreSQL con `SET app.tenant_id` por conexión y rol dedicado de solo lectura/escritura para el motor. | Backlog F4 |

## 3. Autenticación y sesiones

- Contraseñas con bcrypt/argon2 (hasher de Laravel), longitud mínima 10 y verificación contra
  contraseñas comunes; restablecimiento por enlace de un solo uso con expiración.
- SPA con **Sanctum en modo stateful**: cookies de sesión httpOnly, `SameSite=Lax`, `Secure` en
  producción (`SESSION_SECURE_COOKIE=true`), sesión en Redis, regeneración del id al iniciar sesión,
  expiración por inactividad (`SESSION_LIFETIME`).
- Integraciones REST: tokens Bearer de Sanctum con abilities mínimas, ligados a una empresa,
  revocables y mostrados una sola vez; se guarda solo su hash.
- Limitación de intentos: nginx (10/min por IP en login/registro) + `RateLimiter` de Laravel por
  usuario/IP; bloqueo temporal tras fallos consecutivos; `auth.failed` en auditoría.
- **Futuro:** 2FA TOTP para propietarios y administradores, SSO (OIDC/SAML) para planes empresariales.

## 4. Roles y capacidades

Fuente: `backend/app/Enums/TenantRole.php`. El **superadministrador** (`users.is_superadmin`) gestiona
empresas, planes y consumo, pero **no** obtiene acceso a datos de negocio de una empresa salvo que
sea miembro de ella (privacidad desde el diseño).

| Capacidad (ability) | Consulta | Analista | Admin | Propietario |
|---|:-:|:-:|:-:|:-:|
| `data.view` ver dashboards, ventas, catálogos | ✅ | ✅ | ✅ | ✅ |
| `forecast.view` ver pronósticos y precisión | ✅ | ✅ | ✅ | ✅ |
| `alerts.view` ver alertas | ✅ | ✅ | ✅ | ✅ |
| `imports.manage` importar archivos / API | — | ✅ | ✅ | ✅ |
| `forecast.run` generar pronósticos | — | ✅ | ✅ | ✅ |
| `scenarios.run` simulador | — | ✅ | ✅ | ✅ |
| `alerts.manage` atender alertas y recomendaciones | — | ✅ | ✅ | ✅ |
| `data.export` exportaciones CSV | — | ✅ | ✅ | ✅ |
| `catalog.manage` productos, proveedores, promociones | — | ✅ | ✅ | ✅ |
| `users.manage` usuarios y roles (excepto propietarios) | — | — | ✅ | ✅ |
| `branches.manage` sucursales | — | — | ✅ | ✅ |
| `datasources.manage` fuentes y tokens de API | — | — | ✅ | ✅ |
| `settings.manage` configuración de la empresa | — | — | ✅ | ✅ |
| `audit.view` bitácora de auditoría | — | — | ✅ | ✅ |
| `owners.manage` asignar/quitar propietarios | — | — | — | ✅ |
| `tenant.consent` consentimiento de entrenamiento compartido | — | — | — | ✅ |
| `tenant.export_all` exportación completa (portabilidad) | — | — | — | ✅ |
| `tenant.delete` eliminar la empresa | — | — | — | ✅ |

Regla adicional: una empresa nunca puede quedar sin al menos un propietario.

## 5. Cifrado

| Ámbito | Medida |
|---|---|
| En tránsito (externo) | TLS 1.2+ con certificados Let's Encrypt (Caddy en `docker-compose.prod.yml`, renovación automática), HSTS, redirección HTTP→HTTPS. |
| En tránsito (interno) | Tráfico Laravel ↔ motor ↔ PostgreSQL ↔ Redis dentro de la red Docker `internal` (sin salida a Internet, sin puertos publicados). Si los servicios se separan en varios hosts: TLS en PostgreSQL (`sslmode=verify-full`) y Redis, o túnel WireGuard. |
| En reposo (aplicación) | Cifrado AES-256 (APP_KEY) de `customers.name_encrypted` y `data_sources.credentials_encrypted`; cédula/RUC de clientes solo como HMAC-SHA256 (`tax_id_hash`), nunca en claro. |
| En reposo (infraestructura) | Disco del VPS cifrado (LUKS o cifrado del proveedor); respaldos cifrados con GPG (`BACKUP_GPG_RECIPIENT`) y copiados fuera del servidor. |
| Claves | `APP_KEY`, `ENGINE_API_TOKEN`, contraseñas de BD/Redis solo en `.env` del servidor (permisos 600), nunca en el repositorio; rotación documentada (rotar `APP_KEY` requiere re-cifrar con `APP_PREVIOUS_KEYS`). |

## 6. Credenciales de conectores

- Se guardan cifradas en `data_sources.credentials_encrypted`; la API **nunca** las devuelve (solo
  indica si existen) y no se registran en logs ni auditoría.
- Principio de mínimo privilegio: se solicitan credenciales de **solo lectura** al ERP/POS.
- Los conectores que requieren credenciales no disponibles se muestran como
  `requires_credentials`/`planned`; ninguna integración se presenta como activa sin estarlo.

## 7. Registros (logs) y auditoría

- Logs estructurados a stderr (recogidos por Docker). **Prohibido** registrar contraseñas, tokens,
  cuerpos de petición, archivos importados, nombres o identificaciones de clientes.
- Un procesador de Monolog enmascara patrones de correo electrónico, cédula/RUC (10/13 dígitos) y
  tokens antes de escribir.
- `import_errors.raw_value` se trunca y no almacena identificaciones completas.
- `audit_logs` (solo inserción) registra: inicio/cierre de sesión y fallos, registro, creación de
  empresa, cambios de rol y usuarios, importaciones confirmadas/rechazadas, ejecuciones de
  pronóstico, exportaciones, consentimiento de entrenamiento compartido, solicitudes de eliminación
  y acciones del superadmin. `metadata` sin PII. La IP es dato personal: se conserva 12 meses para
  seguridad y luego se elimina o se trunca.

## 8. Privacidad y LOPDP (Ecuador)

### 8.1 Roles en el tratamiento
- **Empresa cliente = responsable del tratamiento** de los datos personales de *sus* clientes
  (consumidores, personas naturales) incluidos en las ventas.
- **Predictily = encargado del tratamiento** de esos datos: los trata solo por instrucción del
  cliente y para prestar el servicio. Debe formalizarse un **contrato de encargo** (objeto, duración,
  finalidad, tipo de datos, obligaciones, subencargados como el proveedor del VPS, medidas de
  seguridad, devolución/eliminación al terminar).
- Predictily es **responsable** de los datos de sus propios usuarios (cuentas, facturación,
  auditoría).

### 8.2 Bases de legitimación
- Datos de usuarios de la plataforma: ejecución del contrato de servicio.
- Datos de clientes finales contenidos en las ventas: base de legitimación del cliente responsable;
  Predictily actúa como encargado.
- **Entrenamiento compartido entre empresas** (modelos globales/sectoriales con datos de varias
  empresas): **desactivado por defecto**; requiere consentimiento expreso y revocable del
  propietario (`tenants.allow_shared_training`, con fecha y usuario) y revisión jurídica de la base
  de legitimación. Solo se usan datos agregados de demanda por producto/fecha, nunca datos de
  clientes finales. **Seudonimizar (reemplazar identificadores por códigos o hashes) no equivale a
  anonimizar**: los datos seudonimizados siguen siendo datos personales si pueden reidentificarse
  combinándolos con otra información.

### 8.3 Minimización
- El pronóstico no necesita datos personales: se usa código externo del cliente, segmento y ubicación
  agregada (provincia/cantón/zona), **no** direcciones exactas.
- Cédula/RUC de clientes: solo HMAC para deduplicar. Nombre del cliente: opcional y cifrado.
- Datos de demostración generados sintéticamente y marcados como **ficticios**.

### 8.4 Derechos de los titulares
- **Acceso, rectificación, eliminación, oposición, portabilidad, suspensión**: las solicitudes de
  clientes finales se canalizan a través de la empresa cliente (responsable); Predictily provee las
  herramientas: exportación completa (`POST /tenant/export`), edición/eliminación de clientes y
  purga.
- Usuarios de la plataforma: perfil editable, exportación de sus datos y eliminación de cuenta.

### 8.5 Retención y eliminación
| Dato | Retención por defecto |
|---|---|
| Ventas y líneas | `tenants.data_retention_months` (60 meses, configurable) |
| Archivos importados | 90 días tras completar la importación; inmediato si se rechaza |
| Exportaciones generadas | 7 días |
| `forecast_values` de ejecuciones no vigentes | últimas 12 ejecuciones por empresa (métricas y `forecast_actuals` se conservan) |
| Auditoría | 24 meses (IP: 12 meses) |
| Empresa eliminada | purga definitiva 30 días después de la solicitud (cascada por `tenant_id` + archivos + artefactos) |
| Respaldos | rotación `BACKUP_RETENTION_DAYS` (14 días): los datos eliminados desaparecen de los respaldos al expirar |

### 8.6 Vulneraciones de seguridad
- Procedimiento: detección → contención → evaluación de impacto → **notificación a la Autoridad de
  Protección de Datos Personales sin dilación y dentro del plazo legal (la LOPDP establece 5 días
  desde que se tuvo conocimiento)** → notificación a los responsables (empresas clientes) para que
  informen a los titulares cuando corresponda → registro del incidente y lecciones aprendidas.
  Verificar plazos y formularios vigentes con asesoría legal.

### 8.7 Transferencias internacionales
- Si el VPS o los respaldos se alojan fuera de Ecuador, existe transferencia internacional: elegir
  países/proveedores con nivel adecuado de protección o cláusulas contractuales tipo, e informarlo
  en el contrato de encargo y en la política de privacidad.

### 8.8 Evaluación de impacto
- Antes de habilitar entrenamiento compartido, conectores con datos de clientes o funciones de
  segmentación avanzada, realizar una evaluación de impacto de protección de datos (EIPD).

## 9. Seguridad de la aplicación

- **Validación** estricta con Form Requests (tipos, rangos, enums, tamaños); errores 422 uniformes.
- **SQL**: solo query builder/Eloquent con parámetros; columnas de ordenamiento y filtros por lista
  blanca; nunca concatenar entrada del usuario en SQL crudo. El motor usa SQLAlchemy con parámetros
  ligados.
- **XSS**: escape por defecto de Vue, sin `v-html` con datos externos, CSP `script-src 'self'`.
- **CSRF**: token de Sanctum para la SPA; las rutas con Bearer no usan cookies.
- **Cabeceras**: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`,
  `Permissions-Policy`, `Cross-Origin-Opener-Policy`, CSP y HSTS (`infra/nginx/snippets`).
- **Subidas**: máx. 20 MB, extensión y tipo MIME verificados, almacenamiento fuera de `public/`,
  nombre aleatorio, lectura en streaming; nunca se ejecuta contenido subido. Un XLSX (que es un ZIP) se
  inspecciona antes de guardarse y de abrirse: entradas, tamaño descomprimido, razón de compresión y archivos
  anidados (§16).
- **Exportaciones CSV**: neutralización de inyección de fórmulas; registradas en auditoría.
- **Dependencias**: `composer audit`, `npm audit`, `pip-audit` en CI; actualización mensual.
- **Errores**: `APP_DEBUG=false` en producción; mensajes genéricos al cliente, detalle solo en logs.
- **Motor**: token Bearer con `hmac.compare_digest`, sin CORS, sin puertos publicados, usuario sin
  privilegios en el contenedor; no carga artefactos de modelos fuera del directorio de su empresa ni de otra
  empresa: la ruta se confina a `<ENGINE_ARTIFACTS_DIR>/<tenant_id>/` y **antes de deserializar** se verifican
  la firma Ed25519 con claves de confianza, el SHA-256 y el tamaño, y que el manifiesto firmado sea de esa
  empresa (§17).
- **Contenedores**: procesos sin root (`www-data`, `engine`), imágenes LTS con parches, sin
  secretos en las imágenes.

## 10. Copias de seguridad y restauración

- `infra/scripts/backup.sh`: `pg_dump` en formato custom, diario (cron del host 03:00), con
  suma SHA-256, cifrado opcional GPG y rotación (`BACKUP_RETENTION_DAYS`). Copiar fuera del servidor
  (almacenamiento de objetos u otro proveedor).
- También respaldar el volumen `backend-storage` (archivos importados) y `engine-artifacts`
  (los modelos pueden regenerarse; los archivos son trazabilidad).
- `infra/scripts/restore.sh`: restauración a una base indicada; **prueba mensual** de restauración
  en una base temporal (`--verify`).
- **Objetivos:** RPO ≤ 24 h · RTO ≤ 4 h. Para RPO menor: archivado WAL continuo (pgBackRest) — F4.

## 11. Operación segura

- Acceso SSH al VPS solo con llaves, sin root directo, firewall que permita únicamente 22/80/443.
- Actualizaciones automáticas de seguridad del sistema operativo.
- Monitoreo de salud (`/up`, `/healthz`, healthchecks de contenedores) y alertas de disco/memoria.
- Separación de entornos (desarrollo, staging, producción) con credenciales distintas.

## 12. Endurecimiento posterior (backlog)

1. Row Level Security en PostgreSQL por `tenant_id` y roles dedicados (`predictily_app`,
   `predictily_engine` con permisos mínimos por tabla).
2. 2FA TOTP y SSO.
3. Registro de auditoría inmutable externo (WORM) y alertas SIEM.
4. Escaneo de vulnerabilidades de imágenes (Trivy) en CI.
5. Cifrado por empresa (claves por tenant) para datos sensibles.
6. Pruebas de penetración externas antes del lanzamiento comercial.

## 13. Conectores SQL de solo lectura (etapa 2 · ADR-V2-13)

Los conectores SQL (PostgreSQL probado contra una base de pruebas; MySQL/MariaDB y SQL Server implementados, pendientes de prueba con servidor)
extraen filas de la base del ERP del cliente y las pasan por el **mismo** pipeline de importación. El detalle técnico está en
`docs/architecture/v2/P3-datos.md` §4; aquí se fijan los requisitos de seguridad que debe cumplir quien los opera.

### 13.1 Cuenta de solo lectura (obligatoria)
- Pedir al cliente (o crear) **una cuenta dedicada con permiso únicamente de lectura** sobre las vistas o tablas que alimentarán el mapeo. El
  validador de consultas (una sola sentencia `SELECT`, sin escritura, sin múltiples sentencias) **no sustituye** a esa cuenta: es la segunda barrera.
- PostgreSQL: `CREATE ROLE predictily_ro LOGIN PASSWORD '…'; GRANT CONNECT ON DATABASE erp TO predictily_ro; GRANT USAGE ON SCHEMA public TO predictily_ro;
  GRANT SELECT ON <vistas/tablas> TO predictily_ro; ALTER ROLE predictily_ro SET default_transaction_read_only = on;` (el conector además abre la
  transacción en modo solo lectura con `statement_timeout`).
- MySQL/MariaDB: `GRANT SELECT ON erp.<vistas> TO 'predictily_ro'@'<host de Predictily>';` (el conector usa una transacción de solo lectura).
- SQL Server: miembro solo de `db_datareader` (o `GRANT SELECT` por objeto). **SQL Server no tiene modo de solo lectura por transacción**: la
  garantía es el validador más la cuenta; por eso el conector figura como `implemented_untested`.
- Preferir **vistas** dedicadas (columnas mínimas: sin datos personales que el pronóstico no necesite) a tablas completas del ERP.
- Las credenciales se guardan cifradas (`data_sources.credentials_encrypted`), la API nunca las devuelve, no aparecen en errores, `last_error`, logs ni
  auditoría, y **no se incluyen en la exportación LOPDP** (tampoco la `config` de conexión de la fuente).

### 13.2 Política de destino (anti-SSRF): `PREDICTILY_SQL_ALLOWED_HOSTS` y `PREDICTILY_SQL_ALLOWED_CIDRS`

**Estado: IMPLEMENTADO y VERIFICADO** (ADR-V25-15; defectos HIGH de `conectores.json` y `seguridad_ui.json` de la auditoría del 31-oct-2026).
Código: `app/Services/Connectors/Sql/SqlHostPolicy.php` (+ `SqlNetworkClassifier`, `IpAddress`, `SystemHostResolver`), `config/connectors.php`.
Pruebas: `tests/Unit/DataCore/SqlHostPolicyTest.php` (144 casos: un caso por cada vector de evasión), `tests/Feature/DataCore/SqlSourceHostPolicyApiTest.php` (desde la API de fuentes de datos) y las
pruebas de sincronización con el PostgreSQL local, que ahora **declaran** su destino en la lista blanca (`ErpSourceDatabase::authorizeInConnectorPolicy`).

La política es **segura por defecto y no tiene un interruptor que la abra** (se eliminó `PREDICTILY_SQL_DENY_PRIVATE`, cuyo valor por defecto dejaba abiertas
las redes privadas). Se aplica en este orden a cada conexión (`SqlSourceConnection::open`); la sintaxis (paso 1) se valida además al guardar la configuración de la fuente:

1. **Sintaxis.** Solo nombres RFC 1123 (etiquetas de 1–63 caracteres, total ≤ 253, sin `_`, sin Unicode) o literales IP canónicos (IPv6 con o sin corchetes).
   Se rechazan las formas numéricas que `getaddrinfo` interpreta como direcciones: `2130706433` (decimal), `0x7f.0.0.1` y `0xA9FEA9FE` (hexadecimal),
   `0177.0.0.1` (octal), `127.1` (abreviada), `127.0.0.1.`, zonas (`fe80::1%eth0`), URL, usuario, puerto o ruta. Regla: una última etiqueta numérica o `0x…` solo
   es válida dentro de un IPv4 decimal canónico.
2. **Lista blanca de nombres.** Si `PREDICTILY_SQL_ALLOWED_HOSTS` no está vacía, **solo** esos destinos (nombres exactos o IP literales, sin distinguir mayúsculas).
   Una lista mal escrita (p. ej. un comodín) no abre nada: falla cerrado.
3. **Resolución de A y AAAA** (`dns_get_record` y, si no hay respuesta, el resolvedor de libc para IPv4: archivo hosts y nombres internos de Docker). **Todas** las
   respuestas deben estar permitidas; un nombre que no resuelve se rechaza (antes pasaba la política).
4. **Clase de cada dirección** (las IPv4 embebidas en IPv6 —`::ffff:a.b.c.d`, `::ffff:a9fe:a9fe`, `::a.b.c.d`, `::ffff:0:a.b.c.d`, NAT64 `64:ff9b::a.b.c.d`— se
   clasifican por la IPv4 que alcanzan):

   | Clase | Rangos | Regla |
   |---|---|---|
   | **Bloqueada siempre** | `0.0.0.0/8`, link-local `169.254.0.0/16` (incluye `169.254.169.254`), `192.0.0.0/24`, multicast `224.0.0.0/4`, reservado `240.0.0.0/4` (incluye broadcast), `168.63.129.16` (Azure), `100.100.100.200` (Alibaba), `::`, `fe80::/10`, `ff00::/8`, `fd00:ec2::/32` (metadatos IPv6 de AWS), 6to4 `2002::/16`, Teredo `2001::/32`, `100::/64`, NAT64 local `64:ff9b:1::/48` | **Ninguna lista las autoriza** |
   | **Loopback** | `127.0.0.0/8`, `::1` | Denegado. Solo lo autoriza una **IP literal** en `allowed_hosts` o un **CIDR** en `allowed_cidrs` (un nombre en la lista no basta) y **nunca en producción** (`APP_ENV=production`). Uso previsto: laboratorio y pruebas |
   | **Privadas** | `10/8`, `172.16/12`, `192.168/16`, `100.64.0.0/10`, `198.18.0.0/15`, TEST-NET, `fc00::/7` (ULA), `fec0::/10`, `2001:db8::/32` y todo lo que PHP no considere de alcance global | **Denegadas por defecto.** Las autoriza figurar en `allowed_hosts` (el operador aprobó ese destino) o que la IP caiga en `PREDICTILY_SQL_ALLOWED_CIDRS` |
   | **Públicas** | el resto | Permitidas |

5. **La conexión usa la IP validada** (`SqlResolvedTarget`): no hay segunda resolución, de modo que un DNS que cambia entre la validación y la conexión (*DNS
   rebinding*) no tiene efecto. PostgreSQL: `hostaddr=<IP>` y `host=<nombre>` (el certificado TLS se verifica contra el nombre con `sslmode=verify-full`); MySQL y
   SQL Server: se conecta al literal IP.

Los **mensajes de rechazo son genéricos** y no distinguen «no resuelve», «red privada» o «metadatos» (se devuelven a la empresa en la prueba de conexión y en
`last_error`; distinguirlos sería un oráculo de la red interna). El motivo exacto queda solo en el registro del servidor (`reason`, `host`, `address`).

| Entorno | Configuración recomendada |
|---|---|
| **SaaS público multiempresa** | Definir **siempre** `PREDICTILY_SQL_ALLOWED_HOSTS` (nombres exactos, separados por comas): cada alta de un cliente exige añadir su host tras verificarlo con él. Sin lista, cualquier host público es alcanzable (nunca privado, loopback ni metadatos). |
| **ERP detrás de VPN / red privada del cliente** | Añadir el host del ERP a `PREDICTILY_SQL_ALLOWED_HOSTS` (autoriza que resuelva a una red privada) o la red del túnel a `PREDICTILY_SQL_ALLOWED_CIDRS` (p. ej. `10.20.0.0/16`), alcanzable solo por el túnel (WireGuard/IPsec) desde el servidor que ejecuta los trabajos de la cola `imports`. Preferir un túnel por cliente; nunca abrir el puerto de la base a Internet. Con solo `allowed_cidrs` (sin hosts) cualquier nombre que resuelva dentro del CIDR queda autorizado. |
| **Instalación dedicada de un solo cliente** | Igual que la anterior; la lista puede incluir únicamente el ERP de la empresa. |
| **Laboratorio / pruebas** | La base local (loopback) se declara con la IP literal: `PREDICTILY_SQL_ALLOWED_HOSTS=127.0.0.1` (entorno distinto de producción). El E2E `e2e/stage2_acceptance.py` y las pruebas de sincronización lo requieren. |

Estado de lo que NO cubre (honesto):
- **DISEÑADO / NO IMPLEMENTADO**: normalizar los mensajes de error del controlador (hoy `safeMessage` devuelve hasta 400 caracteres del mensaje del driver, que puede incluir
  la IP y el puerto de un destino **permitido**), limitar los puertos de destino y exigir TLS hacia el origen (defecto MEDIUM «TLS hacia el origen no es obligatorio»), y el modo `bridge_push`
  (ADR-V25-10) que elimina la conexión saliente.
- **BLOQUEADO para verificar en este entorno**: MySQL/MariaDB y SQL Server no tienen servidor ni extensión (`pdo_sqlsrv`) aquí; para ellos solo se verifica el DSN generado
  (`SqlHostPolicyTest::test_the_validated_ip_is_pinned…`), no una conexión real. Con SQL Server y `Encrypt=1`, conectar al literal IP exige un certificado que cubra la IP o
  `trust_server_certificate`: **limitación conocida** de conectar a la IP fijada.
- **Variable obsoleta / infraestructura pendiente del arquitecto** (fuera de los archivos de este paquete): `docker-compose.yml` y el `.env.example` de la raíz aún pasan o documentan
  `PREDICTILY_SQL_DENY_PRIVATE` (se ignora), no pasan `PREDICTILY_SQL_ALLOWED_CIDRS` (`PREDICTILY_SQL_ALLOWED_HOSTS` sí se pasa) y la pila no declara el host del origen: el E2E
  (`e2e/stage2_acceptance.py`) conecta a `127.0.0.1` y necesita `PREDICTILY_SQL_ALLOWED_HOSTS=127.0.0.1` en un entorno distinto de producción.
- Usar TLS en la conexión a la base (`sslmode=verify-full` en PostgreSQL) cuando el trayecto no sea un túnel cifrado. Otros topes: `PREDICTILY_SQL_MAX_ROWS` (filas por sincronización) y
  paginación por cursor.

### 13.3 Datos personales y auditoría
- La consulta puede traer datos de compradores: se importan solo los campos mapeados, la cédula/RUC se convierte a HMAC, la vista previa enmascara los
  códigos de comprador y rige la minimización de §8.3. Antes de habilitar un conector con datos de clientes, realizar la EIPD (§8.8).
- Se auditan `datasource.created|updated|deleted`, `datasource.mapping_created|approved|deactivated` y `datasource.sync_requested|completed|failed`.
  Una versión de consulta nace **sin aprobar**: solo la aprobada y activa sincroniza (separación entre quien redacta y quien autoriza la consulta,
  ambos con `datasources.manage`).

## 14. Capa conversacional opcional (etapa 2 · ADR-V2-12)

El asistente (`POST /api/v1/assistant/messages`, `GET /api/v1/assistant/status`) permite consultar en lenguaje natural resultados **ya calculados**.
No es parte de ningún core: sin proveedor, o con el servicio desactivado, los cuatro cores (Datos, Demanda, Comportamiento de compra y Abastecimiento)
funcionan igual (prueba `CoresWorkWithoutAssistantTest`; ningún módulo de los cores ni del motor referencia la capa conversacional:
`CoresDoNotDependOnAssistantTest`).

### 14.1 Qué puede hacer el modelo y qué no
- **No calcula**: no suma, promedia, convierte ni proyecta. Las cifras de la respuesta están en `figures`, tal como las devolvió cada herramienta con su
  envoltura de procedencia (corrida, fecha de corte, horizonte, calidad). El texto del modelo solo las explica; si contiene cifras que no proceden de las
  herramientas (verificación numérica conservadora, no formal), el texto se **descarta** (`ungrounded_rejected`) y se devuelven solo las cifras.
- **Sin herramienta aplicable** (o sin permiso) la respuesta es fija: «No puedo responder…»; nunca texto libre del modelo.
- **Herramientas de lista blanca, solo lectura** (`dashboard_summary`, `product_forecast`, `forecast_accuracy`, `inventory_recommendations`,
  `customer_predictions`, `supply_plan`): llaman a los servicios internos de resultados dentro de la empresa activa (`TenantContext`), exigen la **misma
  habilidad** que la ruta equivalente (rol y, si hay token Bearer, también la habilidad del token) y el **mismo plan** (un horizonte fuera del plan se
  rechaza sin revelar datos). No aceptan `tenant_id` ni claves fuera de su esquema, no disparan cálculos y no escriben.
- Los resultados de las herramientas y el texto del usuario son **datos, no instrucciones** (mitigación de inyección de instrucciones): el sistema no
  concede capacidades por lo que diga el modelo, solo por la lista blanca y las abilities.

### 14.2 Qué se envía al proveedor y qué nunca
| Se envía | Nunca se envía |
|---|---|
| Instrucciones fijas, fecha de hoy, el mensaje del usuario y un historial corto (hasta 6 mensajes) con correos, cédula/RUC y tokens **enmascarados** (`PiiMasker`) | Nombre o RUC de la empresa, nombres/correos de usuarios, credenciales, tokens, clave del proveedor |
| Resultados de las herramientas **saneados** y acotados en tamaño: códigos y nombres de producto, sucursales, cifras agregadas y su procedencia | Nombre, correo, cédula/RUC o dirección de compradores (solo el **código externo** cuando la herramienta de compradores está activa) |

El proveedor es un **subencargado** del tratamiento: debe constar en el contrato de encargo (§8.1), su ubicación puede implicar transferencia internacional
(§8.7) y debe realizarse la EIPD (§8.8) antes de activarlo para una empresa. Antes de producción hay que verificar con el proveedor elegido, por contrato,
que no usa los datos enviados para entrenar sus modelos y dónde los procesa; Predictily no puede garantizarlo técnicamente.

### 14.3 Configuración y desactivación
Variables (solo en el `.env` del servidor, permisos 600; nunca en el repositorio, imágenes, logs ni respuestas):
`CONVERSATION_ENABLED`, `CONVERSATION_PROVIDER` (`ollama_cloud` | `none`), `CONVERSATION_BASE_URL`, `CONVERSATION_API_KEY`, `CONVERSATION_MODEL`,
`CONVERSATION_TIMEOUT_SECONDS`, `CONVERSATION_DEADLINE_SECONDS`, `CONVERSATION_MAX_MESSAGE_CHARS`, `CONVERSATION_RATE_PER_MINUTE`,
`CONVERSATION_DAILY_LIMIT`, `CONVERSATION_CUSTOMER_TOOLS` (ver `backend/config/conversation.php`). Rotar la clave = cambiar la variable y reiniciar.
- **Sin `CONVERSATION_API_KEY` el servicio queda desactivado** y no se hace ninguna llamada de red: `POST /assistant/messages` responde 503
  `assistant_disabled` (con `reason`) y `GET /assistant/status` informa `enabled=false`.
- Desactivar para todos: `CONVERSATION_ENABLED=false` o `CONVERSATION_PROVIDER=none`. Desactivar para una empresa: `plan_overrides.features.assistant=false`
  (superadministrador, `PATCH /admin/tenants/{uuid}`) o un plan sin esa capacidad (`402`). Retirar el acceso a datos de compradores: `CONVERSATION_CUSTOMER_TOOLS=false`.
- Con `APP_ENV` de producción el endpoint del proveedor debe ser `https://`.

### 14.4 Límites, registro y auditoría
- Por mensaje: tamaño máximo (`CONVERSATION_MAX_MESSAGE_CHARS`, 1000 por defecto), máximo 3 rondas de herramientas y 3 llamadas por ronda, resultado de
  herramienta acotado, tiempo por llamada y tiempo total. Tasa por usuario/empresa (`CONVERSATION_RATE_PER_MINUTE`, 429 `assistant_rate_limited`) y tope diario
  por empresa (`CONVERSATION_DAILY_LIMIT`, 429 `assistant_quota_exceeded`); las claves de caché llevan el prefijo `tenant:{id}:`.
- **No se registra el contenido** de la conversación ni de las herramientas en logs ni en auditoría. Se audita `assistant.message` (estado, herramientas
  usadas y su resultado, rondas, tokens, tamaño del mensaje, duración, cantidad de cifras descartadas) y `assistant.provider_error` (motivo y estado HTTP).
  Los errores del proveedor nunca incluyen el cuerpo de su respuesta, la clave ni la conversación.
- Pendiente: desactivación por empresa desde la interfaz de configuración (requiere ampliar el esquema de `tenants.settings`).

## 15. Retención de artefactos de la etapa 2

Todas las tablas nuevas llevan `tenant_id` con eliminación en cascada, de modo que la purga definitiva de una empresa (30 días tras la solicitud,
`predictily:purge`) elimina sus ejecuciones, huellas, predicciones, planes y demás resultados. La exportación LOPDP (`POST /tenant/export`) incluye ahora
todas esas tablas (nunca credenciales ni la configuración de conexión de las fuentes) y una prueba exige que cualquier tabla con `tenant_id` esté
clasificada como exportada o excluida con motivo (`TenantDataExporter::TABLES` / `NOT_EXPORTED`).

| Artefacto | Dónde | Retención |
|---|---|---|
| Exportaciones ZIP | disco privado `tenants/{id}/exports` | 7 días (**implementado**, `predictily:purge`) |
| Bitácora de auditoría (incluye `assistant.*` y `datasource.*`) | `audit_logs` | 24 meses; IP 12 meses (**implementado** para los 24 meses) |
| Empresa eliminada: filas de todas las tablas | PostgreSQL | cascada por `tenant_id` (**implementado**); desaparece de los respaldos al expirar la rotación |
| Ejecuciones y resultados (`core_runs`, `customer_*`, `supply_plans`, `cost_forecasts`, `scenario_evaluations`) | PostgreSQL | **Política objetivo**: conservar el detalle de las últimas 12 ejecuciones por core y empresa y, de las anteriores, sus métricas, manifiestos y huellas. **Pendiente de implementar**: hoy no hay depuración automática |
| Huellas de datos (`data_snapshots`) y configuración efectiva de cada ejecución | PostgreSQL | mientras exista una ejecución que las referencie (reproducibilidad); son hashes y recuentos, sin filas de negocio |
| Artefactos de modelos (motor, `storage/artifacts/{tenant_id}`) y su manifiesto firmado `<artefacto>.manifest.json` (§17) | volumen `engine-artifacts` | **Política objetivo**: conservar el modelo en producción y las 2 versiones `validated`/`retired` más recientes (necesarias para la reversión verificada por SHA-256); eliminar `candidate`/`failed` con más de 30 días. **Pendiente de implementar**: no existe limpieza automática ni eliminación de los artefactos de una empresa purgada; hasta entonces, al purgar una empresa debe borrarse manualmente `artifacts/{tenant_id}` en el volumen del motor |
| Planes de abastecimiento, simulaciones y escenarios guardados | PostgreSQL | iguales a las ejecuciones; son sugerencias, no compromisos |
| Calendario público (`tenant_id` NULL) | PostgreSQL | no pertenece a ninguna empresa: se conserva y no se exporta |

Las copias de seguridad siguen §10 (`BACKUP_RETENTION_DAYS`); el volumen `engine-artifacts` se respalda porque la reversión de modelos depende de él.

## 16. Endurecimiento de cargas: XLSX / ZIP (ADR-V25-15)

**Estado: IMPLEMENTADO y VERIFICADO** (defecto HIGH «Sin protección ZIP bomb» de `seguridad_ui.json`: un XLSX de 680 KB consumía 23 s de CPU dentro de la petición de subida).
Código: `app/Services/Imports/Reading/ZipArchiveInspector.php`, `XlsxReader.php`, `UnsafeSpreadsheetException.php`, `app/Http/Requests/Core/StoreImportRequest.php`,
`config/upload_safety.php`. Pruebas: `tests/Unit/Imports/ZipArchiveInspectorTest.php` (18) y `tests/Feature/Core/XlsxUploadHardeningTest.php` (7).

**Qué se hace.** Un XLSX es un ZIP. En la validación de la subida (`StoreImportRequest`, antes de guardar el archivo y de crear la importación) y de nuevo antes de abrirlo
para leerlo (`XlsxReader`), `ZipArchiveInspector` lee **solo el directorio central** (`ZipArchive::statIndex`, y los 4 primeros bytes de las entradas no XML) y comprueba:

| Control | Valor por defecto | Variable | Código estable (HTTP) |
|---|---|---|---|
| Firma de ZIP, estructura, partes mínimas de un libro (`[Content_Types].xml`, `xl/workbook.xml`), nombres sin `..`/NUL/absolutos ni repetidos, suma de tamaños comprimidos ≤ archivo (entradas solapadas) | — | — | `xlsx_invalid_archive` (422) |
| Entradas cifradas | — | — | `xlsx_encrypted` (422) |
| Nº de entradas | 1 000 (un libro normal tiene decenas; el histórico real, 12) | `PREDICTILY_XLSX_MAX_ENTRIES` | `xlsx_too_many_entries` (422) |
| Archivos anidados (extensión `zip`, `xlsx`, `gz`, `7z`…, o firma `PK`, gzip, RAR, 7z en entradas no XML) | — | — | `xlsx_nested_archive` (422) |
| Tamaño descomprimido por entrada / total | 512 MB / 1 024 MB | `PREDICTILY_XLSX_MAX_ENTRY_MB`, `_TOTAL_MB` | `xlsx_uncompressed_too_large` (**413**) |
| Cadenas compartidas (`xl/sharedStrings.xml`): el lector las carga **enteras** al abrir el libro, sin poder interrumpirse (≈ 8 MB/s medidos) | 32 MB | `PREDICTILY_XLSX_MAX_SHARED_STRINGS_MB` | `xlsx_uncompressed_too_large` (**413**) |
| Razón descomprimido/comprimido por entrada y global (desde 5 MB descomprimidos) | 100:1 | `PREDICTILY_XLSX_MAX_RATIO` | `xlsx_compression_ratio_exceeded` (**413**) |

La respuesta es JSON `{message, code, errors: {file: [message]}}` con el mensaje en español. No se crea la importación ni se guarda el archivo.

**Lectura de encabezados y vista previa** (la ventana interactiva: las primeras 25 filas, que ocurre dentro de la petición): máximo de columnas por fila (500,
`PREDICTILY_XLSX_MAX_COLUMNS`), de filas recorridas —también las vacías— hasta hallar encabezados y vista previa (20 000, `PREDICTILY_XLSX_ANALYSIS_MAX_SCANNED_ROWS`) y
presupuesto de tiempo para el recorrido de filas (10 s con el libro ya abierto, `PREDICTILY_XLSX_ANALYSIS_TIMEOUT`); códigos `xlsx_too_many_columns`, `xlsx_header_not_found`, `xlsx_read_timeout`. Además `headers()` y `rows()`
comparten ahora **una sola apertura** del libro (antes se cargaban dos veces las cadenas compartidas). Pasada esa ventana, la lectura completa (cola) sigue el ritmo del archivo.

**Calibración medida (31-oct-2026).**
- La bomba de la auditoría, regenerada en la prueba (874 KB; `sharedStrings.xml` de 200 000 183 bytes, ≈ 229:1), se rechaza en **≈ 1 ms** (`xlsx_uncompressed_too_large`, 413); sin la inspección, 22–23 s de CPU.
- El histórico real de la ferretería demo (99 335 filas × 20 columnas) escrito como XLSX con OpenSpout: 12 entradas; con cadenas «inline» 9,8 MB comprimidos, 123,7 MB descomprimidos y razón 12,7:1 →
  **pasa** y su análisis tarda 0,03 s. La prueba `test_the_default_limits_accept_the_real_history_profile_of_99334_lines` lo fija.
- Con cadenas **compartidas sin deduplicar** (modo del escritor de OpenSpout) el mismo histórico tiene `sharedStrings.xml` de 86,8 MB (razón 22,5:1); abrirlo cuesta 10,4 s (el análisis tardaba 20 s al abrir el libro dos veces): **se rechaza** por el tope de 32 MB.
  Un libro guardado por Excel deduplica las cadenas compartidas (no medido con Excel real en este entorno); si un cliente topa con el límite, puede exportar a CSV o subir `PREDICTILY_XLSX_MAX_SHARED_STRINGS_MB`.

**Estado de lo que NO cubre (honesto).**
- **DISEÑADO / NO IMPLEMENTADO**: mover el análisis a la cola con timeout corto (`ImportService::analyze` sigue siendo síncrono: queda en manos del paquete dueño de la importación); verificar firmas
  («magic bytes») de los CSV; límite de filas totales del libro en la lectura completa.
- Los errores de ZIP salen como 413/422 con código estable **antes** de guardar el archivo. Los límites de lectura (columnas, filas recorridas, tiempo) se producen dentro del análisis y
  se manifiestan como importación `failed` con el mensaje en español en `error_message` (HTTP 201), sin código estable en la respuesta.
- La carga de las cadenas compartidas no es interrumpible: su coste lo acota el tope de tamaño (≈ 4 s en el peor caso permitido), no el presupuesto de tiempo, que cubre el recorrido de filas.
- La inspección confía en los tamaños del directorio central; un libro que miente sobre ellos queda acotado por la lectura interactiva (columnas, filas, tiempo) y por `memory_limit`.
- **NO APLICA**: CSV/TXT/TSV (no son contenedores comprimidos; su tope es el tamaño de subida de 20 MB) y `.xlsm`/`.xlsb`/`.xls`, que no se aceptan.

## 17. Firma de artefactos de modelos (ADR-V25-20)

**Estado: IMPLEMENTADO y VERIFICADO** (defecto HIGH de `modelos_aws.json`: `joblib.load` con el SHA-256 como única garantía permitía ejecución de código arbitrario a quien pudiera escribir una
fila de `model_registry` y un archivo en el volumen; contradecía V2.5 §9, «modelos serializados no confiables prohibidos»). Detalle completo, formato del manifiesto y procedimiento
de claves en `docs/ml/MODEL_REGISTRY.md`.

- **Al guardar** (`platform/artifacts.py::save_signed_artifact`): el artefacto se serializa, se calcula su SHA-256 sobre los bytes que se escriben y se escribe `<artefacto>.manifest.json` con el
  manifiesto JSON canónico (SHA-256 y tamaño, empresa, core, corrida, familia, fecha, versiones de librerías, versión del esquema de variables, huella de datos) y su firma Ed25519.
  Sin clave privada configurada el artefacto queda **marcado sin firma** (`artifact_compat.signature.status = unsigned`).
- **Al cargar** (`customer/service.py::load_artifact`): la ruta se confina a `<ENGINE_ARTIFACTS_DIR>/<tenant_id>/`; se lee el archivo **una vez**; se verifica el SHA-256 del registro, la firma con
  las claves de `ENGINE_TRUSTED_PUBLIC_KEYS`, y que el manifiesto firmado describa **ese** archivo (SHA-256, tamaño, nombre) y **esa empresa**/core; **solo entonces** se deserializa, desde los mismos bytes
  (sin reabrir el archivo: sin TOCTOU). Si algo falla, **no se deserializa**: el Customer Core sirve la referencia validada del horizonte (o se abstiene) con el motivo en `limitations` y un registro sin PII.
- **Sin firma**: rechazado, salvo `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true` (legado de un entorno local, verificable por SHA-256). El indicador **nunca** acepta una firma inválida, una clave desconocida, un manifiesto corrupto ni
  un artefacto de otra empresa.
- **Backend** (`ModelArtifactVerifier`): al revertir un modelo cuyo artefacto es visible comprueba el SHA-256 **y** la firma (`sodium_crypto_sign_verify_detached`) con las claves públicas de `engine.artifact_signing`, y que el manifiesto firmado sea de esa empresa, de ese core y de ese archivo;
  `409 rollback_artifact_unsigned` / `rollback_artifact_signature_invalid` / `rollback_artifact_mismatch`. La marca `unsigned` que dejó el motor basta para rechazar aunque el backend no vea el archivo.
- **Claves**: `python -m predictily_engine.cli keygen --key-id <id> --private-out <archivo>` crea el par (la privada con permisos 0600, nunca impresa). La privada vive solo en el entorno de entrenamiento
  (`ENGINE_SIGNING_PRIVATE_KEY`, preferible `file:` desde un secreto); la pública, en el motor de servicio y en el backend (`ENGINE_TRUSTED_PUBLIC_KEYS`).

Pruebas: `engine/tests/unit/test_artifact_signing.py` (artefacto válido carga; bytes alterados, pickle malicioso con SHA-256 coherente sin firma o con firma inválida —verificando que su carga útil **no se ejecutó**—,
clave desconocida, artefacto de otra empresa, ruta fuera del directorio, sin firma con el indicador desactivado y activado, TOCTOU, configuración de claves y CLI),
`engine/tests/integration/test_customer_integration.py` y `test_demand_v2_integration.py` (entrenar→firmar→predecir con claves efímeras; degradación a la referencia sin firma válida),
`backend/tests/Feature/Customer/ModelArtifactSignatureTest.php` (incluye una firma producida por el motor Python verificada por PHP/sodium).

**Estado de lo que NO cubre (honesto).**
- **DISEÑADO / NO IMPLEMENTADO**: formatos seguros sin pickle (texto de LightGBM, parámetros en JSON); deserialización en un subproceso sin credenciales de BD; endurecimiento del contenedor del motor
  (`read_only`, `cap_drop`, `no-new-privileges`, seccomp); claves en KMS/HSM; revocación explícita de claves y comando de re-firmado de artefactos legados; rotación sin ventana de rechazo.
- **Efecto operativo a tener en cuenta**: sin claves de firma configuradas, los modelos entrenados quedan `unsigned` y el motor **no los carga** (el Customer Core usa la referencia o se abstiene). Los modelos
  entrenados antes de este cambio son legado sin firma: requieren `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true` (solo local) o reentrenar con clave. La pila Docker/E2E debe aprovisionar claves.
- **Pendiente (otros defectos de la auditoría, no cubiertos)**: confinar las rutas absolutas de filas antiguas en el verificador del backend; migrar esas filas a ruta relativa; purgar los artefactos al purgar una empresa; el backend
  requiere `ext-sodium` (declararlo en `composer.json` y en la verificación de extensiones del `Dockerfile`).
- Los artefactos de demanda (LightGBM) se firman al guardarse, pero hoy **nunca se cargan** (defecto LOW aparte): su firma protege una futura carga y la reversión del backend.
