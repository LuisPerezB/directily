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
| Archivos maliciosos | CSV con fórmulas (`=HYPERLINK`) o xlsx malformado | Validación de tipo/tamaño; lectura en streaming; exportaciones CSV neutralizan celdas que inician con `= + - @` |
| Abuso / fuerza bruta | Intentos masivos de login | Rate limiting en nginx y Laravel; registro de `auth.failed` |
| Exposición del motor | Acceso directo a FastAPI | Red Docker interna sin puertos publicados + token Bearer comparado en tiempo constante |
| Pérdida de datos | Falla de disco, error humano | Respaldos diarios con rotación y restauración probada |
| Exposición de PII en logs | Correos o cédulas en trazas | Procesador de logs que enmascara PII; prohibido registrar cuerpos de petición |

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
  nombre aleatorio, lectura en streaming; nunca se ejecuta contenido subido.
- **Exportaciones CSV**: neutralización de inyección de fórmulas; registradas en auditoría.
- **Dependencias**: `composer audit`, `npm audit`, `pip-audit` en CI; actualización mensual.
- **Errores**: `APP_DEBUG=false` en producción; mensajes genéricos al cliente, detalle solo en logs.
- **Motor**: token Bearer con `hmac.compare_digest`, sin CORS, sin puertos publicados, usuario sin
  privilegios en el contenedor; no carga artefactos de modelos fuera de su directorio ni de otra
  empresa (ruta derivada de `tenant_id`, verificación SHA-256 antes de cargar).
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
