# Aislamiento entre empresas · los mecanismos reales y las pruebas que los demuestran (paquete W6-A)

Fecha: 2-nov-2026 · Rama `v25/w1-cierre-y-w2` · Fuente: ADR-V25-15 (seguridad y aislamiento como bloqueo de release) y §15 de la especificación V2.5. Complementa el plan de
[`docs/07-seguridad-multitenant.md`](../07-seguridad-multitenant.md) §2 (defensa en profundidad) y los controles de [`docs/security/ENTERPRISE_SECURITY.md`](../security/ENTERPRISE_SECURITY.md).

Este documento describe **lo que existe**, no lo que se quiere: cada mecanismo dice qué es, dónde está el código y qué prueba falla si deja de cumplirse. Lo que no está cubierto se
dice en §5 sin adornos.

**Leyenda de estado** (la misma de los demás documentos de seguridad):

| Estado | Significa |
|---|---|
| **IMPLEMENTADO / VERIFICADO** | Existe el código y una prueba automatizada lo demuestra (se cita la prueba). |
| **IMPLEMENTADO, NO ACTIVADO** | Existe y está probado, pero está apagado por defecto (bandera). |
| **DISEÑADO / NO IMPLEMENTADO** | Está decidido o descrito, pero no existe código que lo cumpla. |
| **BLOQUEADO** | No se puede construir o verificar en este entorno (falta servidor, cuenta o credencial). |
| **NO APLICA** | No corresponde a este control. |

## 1. Mapa de capas

| # | Capa | Mecanismo (dónde) | Estado | Prueba que lo demuestra |
|---|---|---|---|---|
| 1 | **Resolución de la empresa** | `ResolveTenant`: la empresa sale de `X-Tenant-ID` (uuid) y se exige membresía; un token Bearer de integración está ligado a SU empresa (`personal_access_tokens.tenant_id`) e ignora el encabezado. 404 `tenant_not_found` si no es miembro (no revela si la empresa existe), 423 si está suspendida o en eliminación | **IMPLEMENTADO / VERIFICADO** | `TenantIsolationMatrixTest::test_a_member_of_company_a_gets_404_on_every_route_when_naming_company_b` (TODAS las rutas de empresa del router: 268), `TenantIsolationTest::test_user_cannot_access_another_tenant_through_header`, `TokenLifecycleTest::test_the_token_only_does_what_it_was_granted_and_only_in_its_company` |
| 2 | **Autenticación por defecto** | Toda ruta de `/api/v1` exige `auth:sanctum` salvo una lista cerrada (registro, inicio de sesión, desafío MFA y las rutas del agente Bridge, que se autentican por dispositivo con token + firma). Las rutas de CUENTA (tokens propios, segundo factor, crear/listar empresas, cambio de contraseña) exigen además una sesión o un token de inicio de sesión completo (`account.full`): las habilidades y la empresa de un token NO son una frontera para ellas | **IMPLEMENTADO / VERIFICADO** | `TenantIsolationMatrixTest::test_every_route_without_user_authentication_is_a_reviewed_one` (una ruta nueva sin autenticación rompe la prueba hasta que se revise), `…::test_without_credentials_every_company_route_is_401` |
| 3 | **Autorización por habilidad** | `tenant.ability:<habilidad>` en cada ruta de empresa (rol de la persona **y** habilidades del token). Las rutas sin habilidad propia son solo `GET /tenant` y `GET /tenant/usage` (lectura del contexto de la persona en SU empresa) | **IMPLEMENTADO / VERIFICADO** | `…::test_every_route_with_a_company_declares_an_ability_except_the_reviewed_ones`, `…::test_every_ability_a_route_requires_exists_and_the_hierarchy_holds`, `…::test_a_role_without_the_ability_is_forbidden_on_every_company_route_and_nothing_is_written` (131 pares ruta × rol denegados, ninguno escribe), `…::test_a_role_with_every_ability_is_never_stopped_by_the_ability_check_on_safe_company_routes` |
| 4 | **Consultas: global scope fail-closed** | `BelongsToTenant` filtra por `tenant_id` del contexto y **lanza** `TenantNotResolvedException` si no hay empresa; al crear fija `tenant_id` del contexto y rechaza crear para otra empresa | **IMPLEMENTADO / VERIFICADO** | `TenantIsolationTest::test_global_scope_fails_closed_without_tenant_context`, `TenantIsolationMatrixTest::test_a_job_of_company_a_cannot_write_a_record_of_company_b`, `…::test_a_job_without_the_company_middleware_fails_closed_and_a_forged_company_is_not_found` |
| 5 | **Base de datos: FKs compuestas** | `(tenant_id, x_id) → x(tenant_id, id)`: la base rechaza una referencia entre empresas aunque la aplicación se equivoque; con `ON DELETE SET NULL` solo se anula la referencia, nunca `tenant_id` (migraciones `2026_03_12_000400/500`, `CompositeForeignKeys`) | **IMPLEMENTADO / VERIFICADO** | `TenantIsolationTest::test_database_rejects_cross_tenant_foreign_keys` |
| 6 | **Colas y trabajos** | Cada trabajo transporta `public int $tenantId` y declara el middleware `SetTenantContext`, que restablece la empresa y **restaura la anterior** al terminar (importante con `QUEUE_CONNECTION=sync`) | **IMPLEMENTADO / VERIFICADO** | `…::test_a_queued_job_for_company_a_never_reads_company_b`, `…::test_a_job_restores_the_callers_company_and_never_leaks_its_own`, `…::test_nested_and_interleaved_company_contexts_never_mix`, `…::test_every_queued_job_of_the_application_carries_its_company_and_restores_the_context` (recorre `app/Jobs/**`: un trabajo nuevo sin empresa rompe la prueba) |
| 7 | **Caché** | Las claves llevan la empresa (`plans:active_series:{empresa}:{día}`, `tenant:{empresa}:assistant:…`, `tenant:{empresa}:gateway:draft:…`); las demás se justifican una a una (direccionadas por contenido, por dispositivo, por persona) | **IMPLEMENTADO / VERIFICADO** | `…::test_cache_entries_of_company_a_are_never_served_to_company_b`, `…::test_the_assistant_daily_limit_and_rate_are_counted_per_company`, `…::test_every_use_of_the_cache_in_the_application_has_a_reviewed_key_scope` (inventario: un uso nuevo de `Cache::`/`RateLimiter::` rompe la prueba hasta que se revise su clave) |
| 8 | **Almacén de objetos (Bronze)** | Clave generada solo por el servidor: `tenants/<uuid>/bronze/<entidad>/<aaaa>/<mm>/<sha256>.<ext>`; `read`/`purge` exigen que el objeto sea de la empresa activa; la API de linaje devuelve 404 a otra empresa | **IMPLEMENTADO / VERIFICADO** (disco local y emulador S3) · S3 real: **BLOQUEADO** | `…::test_object_store_keys_live_under_the_company_prefix_and_company_b_cannot_reach_them`, `BronzeStoreTest::test_companies_are_isolated_in_bronze`, `…::test_purge_refuses_objects_of_another_company`, `BronzeS3EmulatorTest` |
| 9 | **Exportaciones LOPDP** | `TenantDataExporter` filtra cada tabla por `tenant_id`; las credenciales, los secretos y el catálogo público no salen; toda tabla nueva con `tenant_id` debe clasificarse (`TenantDataExporterStage2Test`) | **IMPLEMENTADO / VERIFICADO** | `…::test_the_lopdp_export_of_company_a_contains_nothing_of_company_b`, `TenantDataExporterStage1Test`, `…Stage2Test`, `…W3Test` |
| 10 | **Registro de modelos** | `model_registry` y sus eventos con `BelongsToTenant`; validar, aprobar, rechazar y promover buscan el modelo en la empresa activa; la firma del manifiesto liga la empresa | **IMPLEMENTADO / VERIFICADO** | `…::test_a_model_of_company_b_cannot_be_read_validated_approved_or_promoted_by_company_a`, `ModelRegistryApiTest::test_the_detail_of_a_model_of_another_company_or_a_missing_one_is_404`, `test_artifact_signing.py::test_a_signed_artifact_of_another_company_is_rejected` (motor) |
| 11 | **Motor (Python)** | Cada lectura y escritura filtra por el `tenant_id` recibido; el artefacto se confina a `<ENGINE_ARTIFACTS_DIR>/<tenant_id>/`; el motor valida que `run_id`/`job_id` pertenezcan al `tenant_id` | **IMPLEMENTADO / VERIFICADO** | `engine/tests/integration/test_api_integration.py::test_tenant_isolation`, `engine/tests/unit/test_artifact_signing.py::test_stored_paths_cannot_leave_the_company_directory` |
| 12 | **URLs prefirmadas y Bridge** | La URL de subida del Bridge está ligada a empresa, dispositivo, objeto, tamaño, tipo y TTL, y la firma es del servidor; un dispositivo revocado no sube nada (la revocación se relee en cada petición); un checkpoint no puede citar un lote de otra fuente o empresa | **IMPLEMENTADO / VERIFICADO** (paquete W6-B) | `BridgeUploadTest::test_url_is_bound_to_content_type_signature_and_ttl`, `…::test_upload_url_signature_is_the_servers_and_cannot_be_forged_by_a_device`, `BridgeCheckpointTest::test_checkpoint_cannot_cite_a_batch_of_another_source_or_company_or_entity`, `BridgeDeviceAuthTest::test_revocation_is_immediate_for_every_endpoint_and_pending_upload_urls`, `BridgeRevocationSecurityTest` (W6-A) |
| 13 | **Alcance comercial dentro de la empresa** | Un vendedor solo ve su cartera y lo que le asignaron (no es aislamiento entre empresas, pero usa el mismo principio: el servidor decide, no el cliente). Registro único y fail-closed de las rutas de clientes (`CommercialSurfaces`) | **IMPLEMENTADO / VERIFICADO** | `CommercialRolesTest` (20 pruebas) y `CommercialSurfacesTest` (6); ver `ENTERPRISE_SECURITY.md` §7 |
| 14 | **RLS de PostgreSQL** | Defensa adicional en las tablas de ventas, detrás de la bandera `PREDICTILY_RLS_ENABLED` | **EXPERIMENTAL, NO ACTIVADO** (no apto para producción: §4) | `RowLevelSecurityTest` (11 pruebas) |
| 15 | **Registros y auditoría** | La auditoría guarda la empresa del contexto; el enmascarador de PII elimina secretos, códigos del segundo factor y desafíos | **IMPLEMENTADO / VERIFICADO** | `MfaFlowTest::test_no_secret_or_code_reaches_logs_audit_or_later_responses` |

## 2. La matriz de rutas × roles × empresa

`TenantIsolationMatrixTest` **enumera las rutas del router real** (324 operaciones bajo `/api/v1`; 276 con empresa activa) en vez de una lista escrita a mano, de modo que una ruta
nueva de cualquier paquete entra sola en la matriz:

1. **Por defecto, denegado.** Toda ruta sin autenticación de persona está en una lista de 15 rutas revisadas una a una; toda ruta de empresa declara una habilidad (salvo 2 revisadas);
   toda ruta autenticada sin empresa es de `auth/`, de plataforma (`admin/`, con `superadmin`) o está en una lista de 5 revisadas. Toda ruta que NO es de `/api/v1` (`routes/web.php`, paquetes) está en una
   lista de 5 revisadas del framework (`test_no_route_exists_outside_the_api_except_the_reviewed_framework_ones`). Si un paquete agrega una ruta que no cumple, la prueba falla
   y obliga a revisarla antes de integrar.
2. **Empresa.** Sin credenciales, cada ruta de empresa responde 401; con la credencial de una persona de la empresa A y el encabezado de la empresa B, cada una responde 404
   `tenant_not_found` (268 rutas por cada cara; 8 bajo bandera de concesiones quedan fuera porque con la bandera apagada «no existen» y devuelven 404 antes de autenticar:
   `SharedForecastGrantTest`).
3. **Rol.** Para cada ruta con habilidad y sin enlace implícito de modelo (192 de las 266 rutas con habilidad), cada rol que no tiene la habilidad exigida recibe 403 `forbidden` con la habilidad que falta (131 pares ruta × rol
   verificados) y **no se escribe ninguna fila**. Las 74 rutas con enlace implícito (`{product}`, `{sale}`…) resuelven el id antes de la habilidad (404 si no existe); su 403/404 por recurso lo
   prueban las suites de cada recurso.
4. **Rol con todas las habilidades.** Las rutas GET sin enlace de modelo nunca son detenidas por la comprobación de habilidad ni fallan con 5xx sobre una empresa vacía.

Los límites de tasa por persona (`throttle:*`, compartidos entre rutas) se desactivan en esta matriz porque 300 peticiones en un minuto los agotarían; el del inicio de sesión se prueba en `AuthTest` y los del segundo factor (bloqueo persistente por fuerza bruta) en `MfaFlowTest`.

## 3. Lo que prueban las pruebas de colas, caché y almacén (y lo que no)

- **Colas.** Se ejecuta un trabajo real de cola (`QUEUE_CONNECTION=sync`) por empresa y se comprueba qué filas vio; que el contexto del llamador se restablece; que dos contextos anidados no se
  mezclan; que un trabajo sin el middleware falla cerrado; que una empresa inexistente no se resuelve; y que, dentro de A, escribir un registro de B es imposible. Un **escaneo de
  `app/Jobs/**`** exige `tenantId` y `SetTenantContext` en cada trabajo, y otro escaneo de TODO `app/` exige que ninguna clase `ShouldQueue` viva fuera de `app/Jobs`
  (`test_no_queued_class_lives_outside_app_jobs`).
- **Concurrencia.** *No hay* una prueba con trabajadores en paralelo reales (procesos distintos contra la misma base): la prueba de contextos anidados e intercalados cubre el estado
  compartido por proceso (el contexto es `scoped` y se restablece), que es donde puede haber mezcla; la ejecución en paralelo entre procesos es **DISEÑADA, no verificada** (cada proceso
  tiene su propio contexto y su propia conexión; no hay estado compartido entre procesos salvo la base y la caché, ambos con empresa en la clave o en la fila).
- **Caché.** Se prueba el comportamiento de las claves con empresa y se **inventaría** cada archivo que usa `Cache::`/`RateLimiter::` con la razón por la que su clave no mezcla empresas.
  El segundo factor (`MfaStepUp`, `MfaChallenge`) usa claves por token, persona o desafío: el segundo factor es de la PERSONA, no de la empresa.
- **Almacén de objetos.** Se prueban las claves con prefijo de empresa, la lectura/purga cruzada (rechazadas) y la API (404). Un **inventario** de los archivos que usan `Storage::` (o `File::put/copy/move`) con la
  razón por la que sus rutas no mezclan empresas rompe la prueba ante un uso nuevo (`test_every_use_of_the_file_store_in_the_application_is_reviewed`). El almacén **S3 real** con políticas IAM por prefijo y la
  retención/replicación están **BLOQUEADOS** aquí (sin cuenta AWS): lo cubre el emulador S3 de `BronzeS3EmulatorTest`.

**Límite conocido de los inventarios.** Son estáticos: detectan una ruta, un trabajo, un uso de caché o de archivos NUEVOS sin revisar, pero no pueden juzgar que una ruta con habilidad correcta lea con una consulta cruda
(`DB::table(...)`) sin el filtro de empresa. Esa defensa es la revisión de código, el global scope fail-closed de los modelos y, cuando se active, RLS (§4). Es un hallazgo de revisión, no una prueba automática.

## 4. RLS de PostgreSQL (EXPERIMENTAL; defensa adicional en las tablas de ventas)

**Estado: EXPERIMENTAL y APAGADO en esta versión.** Decisión del arquitecto: RLS queda desactivada por defecto y no se hace al motor consciente de RLS ahora. Existe para evaluarla en un piloto; **no debe activarse en producción**
hasta resolver los bloqueos de abajo.

**Qué es.** Una segunda barrera por si una consulta se escribe sin el filtro de empresa. No sustituye la validación de la aplicación (capas 1 a 6).

**Cómo funciona** (`App\Services\Tenancy\TenantRls`, `php artisan predictily:rls {status|enable|disable}`):

1. Bandera `security.rls.enabled` (`PREDICTILY_RLS_ENABLED`, **falso por defecto**; una prueba fija ese valor por defecto). Con ella apagada no cambia nada: `TenantContext` no toca la conexión.
2. Con la bandera activa, cada cambio de empresa del contexto (`set`, `forget`, `runAs`) fija `app.tenant_id` en la **conexión** (`set_config(…, false)`); al salir del contexto se limpia. **Tras cualquier rollback se vuelve a fijar
   la empresa vigente del contexto** (un `SET` hecho dentro de una transacción revertida se deshace: sin esto la conexión y el contexto de la aplicación divergirían). Fijarla nunca lanza: si la conexión está en una transacción
   abortada, el `finally` de `runAs` ya no oculta el error SQL original con «current transaction is aborted»; el rollback realinea la conexión.
3. `predictily:rls enable` crea en `sales`, `sale_items`, `sales_document_revisions` y `sale_item_revisions` la política `predictily_tenant_isolation` (USING y WITH CHECK: `tenant_id = app.tenant_id`, **sin ninguna puerta**) y la activa con
   `ENABLE` + `FORCE ROW LEVEL SECURITY` (sin FORCE, el dueño de la tabla —el usuario de la aplicación— la esquivaría). `enable` se niega si la bandera de la aplicación está apagada: con políticas activas y la aplicación sin fijar la
   empresa, toda consulta devolvería cero filas.
4. **No hay «puerta de plataforma» dentro de la sesión.** Una versión anterior permitía `app.rls_bypass = on`; se retiró: el propio rol de la aplicación podía fijarlo, así que anulaba la defensa justo contra el error que debe atrapar. Las
   tareas de plataforma sin empresa (mantenimiento, `withoutTenantScope()`) deben conectarse con **otro rol de base de datos con `BYPASSRLS`** (o ser dueño de la tabla sin FORCE): **DISEÑADO / NO IMPLEMENTADO**.

**Qué prueba `RowLevelSecurityTest`** (con el rol de base de las pruebas, no superusuario): apagado por defecto y sin efecto; el valor por defecto del código es falso; con las políticas activas **una consulta SIN contexto de empresa no devuelve filas
ni siquiera con SQL escrito a mano sin filtro** (las 4 tablas); con contexto solo las de esa empresa (también anidado); no se puede insertar sin contexto ni para otra empresa, y un UPDATE sobre una fila ajena no la toca; **ningún parámetro de
sesión (`app.rls_bypass`, `app.bypass_rls`, `app.tenant_id` vacío) abre la política y la política no menciona ninguna puerta**; tras un rollback la conexión vuelve a coincidir con el contexto; `runAs` no oculta el error original de una transacción
abortada; una petición HTTP fija y limpia la empresa de la conexión; desactivar restaura el comportamiento anterior; el comando se niega a activar con la bandera apagada.

**BLOQUEOS antes de activarla en producción** (ninguno está resuelto):
1. **El motor (Python) debe fijar `app.tenant_id` en cada transacción.** Hoy no lo hace: con las políticas activas sus lecturas y escrituras sobre ventas devolverían cero filas o fallarían. Hacerlo consciente de RLS queda fuera de esta versión.
2. **Una cuenta de aplicación de mínimo privilegio, sin ser superusuario ni `BYPASSRLS`.** `docker-compose.yml` usa un superusuario: allí **RLS no hace nada** (la prueba se omite con un aviso si el rol de la base lo es). Hay que crear el rol de la aplicación y otro, aparte, para el
   mantenimiento (con `BYPASSRLS`, fuera del alcance de la aplicación) y el del motor.
3. **Pooler de conexiones.** El valor de sesión (`set_config(…, false)`) se filtraría entre clientes con un pooler externo en modo transacción (p. ej. PgBouncer): habría que usar `SET LOCAL` dentro de cada transacción o un rol por transacción. **DISEÑADO / NO IMPLEMENTADO.**
4. Solo cuatro tablas (ventas); el resto de las tablas con `tenant_id` no tiene políticas. Ampliarlo es agregar nombres a `security.rls.tables` tras medir el costo.
5. El rendimiento no se midió con volúmenes reales (la política es una igualdad sobre `tenant_id`, que ya está indexado en esas tablas); **a medir en el piloto**.

**Orden de activación en un entorno de PRUEBA (no producción).** (1) Respaldo. (2) `PREDICTILY_RLS_ENABLED=true` y desplegar. (3) `php artisan predictily:rls enable`. (4) Vigilar errores `row-level security` y consultas vacías inesperadas (comandos y trabajos sin
`SetTenantContext`). **Reversión:** `php artisan predictily:rls disable`, luego la bandera en falso.

## 5. Lo que NO está cubierto

| Tema | Estado | Detalle |
|---|---|---|
| Trabajadores de cola concurrentes en procesos distintos | **DISEÑADO / NO VERIFICADO** | ver §3, «Concurrencia» |
| Almacén S3 real, IAM por prefijo, URLs prefirmadas contra S3 real, KMS | **BLOQUEADO** | sin cuenta AWS; el emulador cubre la lógica del servidor |
| Backups, restauraciones y réplicas por empresa | **DISEÑADO / NO VERIFICADO** | `docs/07` §10; la restauración parcial por empresa no está implementada |
| Dashboards (Grafana/Metabase, si se usan) y logs de infraestructura | **DISEÑADO / NO IMPLEMENTADO** | no hay dashboards externos en este repositorio |
| Herramientas del asistente/gateway y WhatsApp | W6-D | el alcance comercial de W6-A lo consume el gateway (`ToolContext`); su matriz de pruebas es de W6-D |
| Vendedor: lo que el registro (`CommercialSurfaces`) NO acota | **DISEÑADO / NO IMPLEMENTADO** | el registro acota o cierra TODA ruta de Radar, agenda, decisiones, ficha del comprador, Customer Core, compromisos y alias/cambio de código (ver `ENTERPRISE_SECURITY.md` §7). Siguen siendo de EMPRESA para quien tiene la habilidad de su rol de acceso las superficies que no hablan de clientes por nombre ni por id: `GET /sales` y `/sales/{sale}`, `/alerts`, `/inventory/*`, `/exports/*.csv`, `/dashboard/*`, `/forecasts/*`, `/data-quality/*`. Para un vendedor externo, usar un rol de acceso de consulta |
| Outcomes y ROI para vendedores | **DISEÑADO / NO IMPLEMENTADO** | cerrados (403 `commercial_scope_forbidden`, vistas de equipo) a un rol comercial con alcance restringido hasta que W5-C los acote por cartera con `CommercialScope` |
| RLS en el resto de las tablas, con el motor y con *pooler* transaccional | **DISEÑADO / NO IMPLEMENTADO** (RLS entera: EXPERIMENTAL) | ver §4 |
| Pruebas de carga/DoS por empresa («vecino ruidoso») | **NO VERIFICADO** | solo los límites de tasa (`throttle:*`, topes del asistente) |

## 6. Lista de comprobación al agregar algo nuevo

- **Ruta:** `auth:sanctum` + `tenant` + `tenant.ability:<habilidad>` (la matriz falla si falta alguna). Sensible (ajustes, usuarios, fuentes, aprobaciones, exportaciones) → la habilidad ya está en `security.mfa.sensitive_abilities`.
- **Modelo:** `BelongsToTenant` y FK compuesta `(tenant_id, x_id)`; clasificar la tabla en `TenantDataExporter`.
- **Trabajo de cola:** `public int $tenantId` y `middleware(): [new SetTenantContext]` (el escaneo lo exige).
- **Caché o límite de tasa:** la clave lleva la empresa (o se justifica) y se agrega a la lista revisada de `TenantIsolationMatrixTest`.
- **Objeto en almacén:** la clave la genera el servidor bajo `tenants/<uuid>/…`; nunca desde un nombre del cliente.
- **Datos para el vendedor:** pasar por `CommercialScope` (`restrictRecommendations` / `restrictCustomers`) en la consulta, no en el cliente.
