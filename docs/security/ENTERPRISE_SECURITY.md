# Seguridad Enterprise · controles de la V2.5 (paquetes W1-B y W6-A)

Fecha: 31-oct-2026 (actualizado el 2-nov-2026) · Rama `v25/w1-cierre-y-w2` · Fuente: ADR-V25-15 (seguridad y aislamiento como bloqueo de release) y ADR-V25-20 (artefactos
firmados antes de deserializar); defectos HIGH de `docs/roadmap/audit/2026-10-31/{conectores,seguridad_ui,modelos_aws}.json`.

Este documento recoge los controles de **W1-B** (endurecimiento P0: SSRF, ZIP bomb, firma de artefactos; §1–§4) y de **W6-A** (seguridad Enterprise: MFA TOTP, roles comerciales, tokens y
revocación, política de seguridad de la empresa; §5–§10). El plan general de seguridad, aislamiento multi-tenant y privacidad está en
[`docs/07-seguridad-multitenant.md`](../07-seguridad-multitenant.md); el aislamiento entre empresas, con la matriz de pruebas y RLS, en
[`docs/architecture/TENANT_ISOLATION.md`](../architecture/TENANT_ISOLATION.md); la firma de artefactos con su procedimiento de claves, en [`docs/ml/MODEL_REGISTRY.md`](../ml/MODEL_REGISTRY.md).
Lo que sigue pendiente de ADR-V25-15 se lista en §11.

**Leyenda de estado.** Cada afirmación se clasifica así, sin mezclarlas:

| Estado | Significa |
|---|---|
| **IMPLEMENTADO / VERIFICADO** | Existe el código y una prueba automatizada lo demuestra (se cita la prueba). |
| **DISEÑADO / NO IMPLEMENTADO** | Está decidido o descrito, pero no existe código que lo cumpla. |
| **BLOQUEADO** | No se puede construir o verificar en este entorno (falta servidor, extensión, cuenta o credencial). |
| **NO APLICA** | No corresponde a este control o formato. |

## 1. Resumen

| # | Control | Defecto de la auditoría | Estado |
|---|---|---|---|
| 1 | SSRF en conectores SQL: sintaxis RFC 1123/IP canónica, A+AAAA, des-mapeo IPv4-en-IPv6, bloqueos permanentes, redes privadas denegadas salvo lista blanca, conexión a la IP validada | HIGH (conectores #3) y HIGH (seguridad_ui #1) | **IMPLEMENTADO / VERIFICADO** (PostgreSQL con servidor real; MySQL y SQL Server solo el DSN) |
| 2 | ZIP bomb en XLSX: inspección ANTES de abrir (directorio central + recuento de los bytes REALES de cada parte, sin fiarse de lo declarado), límites de entradas/tamaño/razón/anidados, tope de cadenas compartidas por relación, guarda de bytes y tiempo en la ventana interactiva (apertura incluida) | HIGH (seguridad_ui #2) | **IMPLEMENTADO / VERIFICADO** |
| 3 | Firma Ed25519 de artefactos de modelos verificada antes de `joblib.load` (motor y reversión del backend) | HIGH (modelos_aws #1) | **IMPLEMENTADO / VERIFICADO** |

## 2. SSRF en conectores SQL

**Control.** `SqlHostPolicy::resolve()` valida y resuelve el destino de cada conexión y devuelve la IP a la que debe conectarse el dialecto. Detalle y tablas de rangos: `docs/07` §13.2.

| Requisito | Estado | Evidencia |
|---|---|---|
| Solo nombres RFC 1123 o literales IP canónicos; se rechazan decimal (`2130706433`), hexadecimal (`0x7f.0.0.1`, `0xA9FEA9FE`), octal (`0177.0.0.1`) y abreviadas (`127.1`) | **IMPLEMENTADO / VERIFICADO** | `SqlHostPolicyTest::test_numeric_forms_and_malformed_hosts_are_rejected_before_any_resolution` (30 vectores; el resolvedor no se consulta) |
| Resolución de A y AAAA; todas las respuestas deben estar permitidas; un nombre sin respuesta se rechaza | **IMPLEMENTADO / VERIFICADO** | `…::test_a_and_aaaa_are_resolved_and_every_answer_must_be_allowed`, `…::test_an_unresolvable_host_is_denied…` |
| Des-mapeo de IPv4 mapeadas/compatibles/traducidas/NAT64 (`::ffff:169.254.169.254`, `::ffff:a9fe:a9fe`, `::a9fe:a9fe`, `64:ff9b::a9fe:a9fe`) | **IMPLEMENTADO / VERIFICADO** | `…::test_cloud_metadata_link_local_multicast_and_transition_forms_are_always_denied` (26 vectores) |
| Siempre bloqueados, aun con lista blanca: loopback salvo IP/CIDR explícito y nunca en producción; `0.0.0.0/8`, link-local, metadatos (`169.254.169.254`, `fd00:ec2::254`), multicast, broadcast | **IMPLEMENTADO / VERIFICADO** | misma prueba (con `allowed_hosts` y `allowed_cidrs = 0.0.0.0/0, ::/0` siguen bloqueados), `…::test_loopback_*` |
| Redes privadas (RFC 1918, ULA `fc00::/7`, `100.64.0.0/10`) denegadas por defecto salvo lista blanca explícita: un CIDR que cubra la IP, o una IP LITERAL en `allowed_hosts`. Un NOMBRE listado NO autoriza por sí solo una resolución privada (si su DNS pasa a apuntar a otra red interna, no se conecta) | **IMPLEMENTADO / VERIFICADO** | `…::test_private_networks_are_denied_by_default`, `…::test_a_private_address_is_authorized_by_the_explicit_ip_or_by_a_covering_cidr`, `…::test_a_listed_hostname_alone_does_not_authorize_a_private_resolution_but_a_listed_literal_ip_does`, `SqlQueryGuardTest::test_host_policy_denies_private_networks_by_default_and_enforces_a_closed_allow_list` |
| Conexión a la IP resuelta (anti DNS rebinding): PostgreSQL `hostaddr` + `host` para TLS; MySQL y SQL Server al literal IP | **IMPLEMENTADO / VERIFICADO** en PostgreSQL con servidor real; **BLOQUEADO** para MySQL/SQL Server con servidor | `…::test_the_validated_ip_is_pinned_so_a_second_resolution_cannot_change_the_destination`, `…::test_postgres_connects_to_the_pinned_ip_without_ever_resolving_the_name` (un nombre que no existe en ningún DNS conecta solo gracias a la IP fijada) |
| Mensajes de rechazo genéricos (sin oráculo de la red interna) | **IMPLEMENTADO / VERIFICADO** | `…::test_denial_messages_are_generic_and_do_not_reveal_the_network`, `…::test_the_connector_test_reports_the_generic_denial_not_the_network` |
| Desde la API: formas numéricas → 422 en `config.host`; metadatos, loopback y redes privadas dejan la fuente `inactive` con el mensaje genérico; lista cerrada rechaza lo demás | **IMPLEMENTADO / VERIFICADO** | `tests/Feature/DataCore/SqlSourceHostPolicyApiTest.php` (3 casos) |
| Las pruebas que usan el PostgreSQL local declaran su destino en la lista blanca | **IMPLEMENTADO / VERIFICADO** | `ErpSourceDatabase::authorizeInConnectorPolicy`, `SqlConnectorContractTest::runServerContract`; suites `tests/Unit/DataCore` y `tests/Feature/DataCore` en verde |
| Normalizar los mensajes de error del controlador (hoy hasta 400 caracteres del driver, con IP/puerto de un destino **permitido**) | **DISEÑADO / NO IMPLEMENTADO** | — |
| Limitar puertos de destino; exigir TLS hacia el origen | **DISEÑADO / NO IMPLEMENTADO** | defecto MEDIUM «TLS hacia el origen no es obligatorio» |
| SQL Server con `Encrypt=1`: el certificado debe cubrir la IP fijada o usarse `trust_server_certificate` | **BLOQUEADO** (sin servidor ni `pdo_sqlsrv`) — limitación conocida |  |
| Conectores REST/CSV/Excel | **NO APLICA** | no abren conexiones salientes hacia un destino de la empresa |

**Cambio de configuración (rompe compatibilidad, por diseño).** `PREDICTILY_SQL_DENY_PRIVATE` ya no existe (limpiado de `docker-compose.yml`, `.env.example` y `docs/architecture/v2/P3-datos.md`); se añade `PREDICTILY_SQL_ALLOWED_CIDRS`
(que `docker-compose.yml` ya pasa, junto con `PREDICTILY_SQL_ALLOWED_HOSTS`). Un ERP detrás de VPN ahora exige declarar su red (CIDR) y, en un SaaS multiempresa, su nombre en la lista de destinos: un nombre solo conecta a una red privada si resuelve
dentro de un CIDR autorizado; una IP literal de la lista se autoriza a sí misma. El E2E `e2e/stage2_acceptance.py` debe declarar `PREDICTILY_SQL_ALLOWED_HOSTS=127.0.0.1` (o el host del origen) en un entorno distinto de producción.

**Hermeticidad de las pruebas.** `backend/phpunit.xml` fija con `force="true"` las variables de este paquete que el `.env` de desarrollo puede definir (`PREDICTILY_SQL_ALLOWED_HOSTS`, `…_ALLOWED_CIDRS`, `ENGINE_TRUSTED_PUBLIC_KEYS`,
`ENGINE_ALLOW_UNSIGNED_ARTIFACTS`, `ENGINE_ARTIFACTS_DIR` y los límites `PREDICTILY_XLSX_*`); `engine/tests/conftest.py` anula `env_file` en todas las clases de configuración del motor, de modo que ninguna prueba lee `engine/.env`
(`engine/tests/unit/test_hermetic_env.py`). Ambas suites dan el mismo resultado con el `.env` de desarrollo presente o ausente.

## 3. Cargas: ZIP bomb en XLSX

**Control.** `ZipArchiveInspector` inspecciona el libro **antes** de abrirlo, tanto en la validación de la subida (`StoreImportRequest`, sin guardar el archivo) como en `XlsxReader`: lee el directorio central, resuelve por
relación qué parte es cada cosa y **cuenta los bytes realmente descomprimidos de cada parte** (el directorio central lo escribe el atacante y no se toma como verdad). Durante la lectura interactiva (encabezados y vista previa, dentro
de la petición) `ZipReadGuard` acota los bytes de hoja y el tiempo de TODA la ventana, apertura del libro incluida. Límites, variables y mediciones: `docs/07` §16 y `backend/config/upload_safety.php`.

| Requisito | Estado | Evidencia |
|---|---|---|
| Inspección del directorio ZIP: nº de entradas, suma de tamaños descomprimidos, razón por entrada y global, archivos anidados, entradas cifradas, nombres peligrosos, entradas solapadas | **IMPLEMENTADO / VERIFICADO** | `ZipArchiveInspectorTest` (36 casos en total) |
| Bomba generada en la prueba rechazada en menos de 2 s | **IMPLEMENTADO / VERIFICADO** | `ZipArchiveInspectorTest::test_the_audit_bomb_is_rejected_in_under_two_seconds` y `XlsxUploadHardeningTest::test_the_audit_bomb_is_rejected_with_413_a_stable_code_and_without_reading_it`: 874 KB con `sharedStrings.xml` de 200 000 183 bytes, rechazada en ≈ 2 ms (antes, 23 s de CPU). El tiempo de la petición se mide sin la generación del libro (criterio en `docs/07` §16) |
| **No se confía en los tamaños declarados:** contenido real distinto del declarado (hoja de 200 MB que declara 2 KB, cadenas compartidas de 100 MB que declaran 1 KB, CRC falsificado, cualquier parte) se rechaza antes de abrir el libro, < 2 s | **IMPLEMENTADO / VERIFICADO** | `…::test_a_forged_directory_that_declares_a_tiny_sheet_is_rejected_by_counting_the_real_bytes`, `…::test_a_forged_directory_that_declares_tiny_shared_strings_is_rejected_before_the_reader_opens_the_workbook`, `…::test_every_part_is_counted_not_only_the_ones_the_reader_opens`, `…::test_a_directory_that_declares_more_than_the_real_content_is_also_inconsistent`, `…::test_a_forged_crc_is_rejected_in_every_kind_of_part`, `XlsxUploadHardeningTest::test_a_forged_central_directory_is_rejected_at_upload_by_counting_the_real_bytes` |
| Tope de cadenas compartidas y estilos sobre la parte señalada por la relación, con cualquier nombre (`xl/ss.xml`, destino absoluto) | **IMPLEMENTADO / VERIFICADO** | `…::test_the_shared_strings_cap_applies_to_the_part_named_by_the_relationship_whatever_its_name`, `…::test_the_audit_case_of_a_renamed_40_mb_shared_strings_part_is_rejected_with_the_default_limits`, `…::test_styles_have_the_same_cap_because_the_reader_parses_them_entirely`, `…::test_the_guard_refuses_to_open_a_large_non_sheet_part_even_if_the_inspector_resolved_roles_differently` |
| Valores por defecto razonables para el histórico real | **IMPLEMENTADO / VERIFICADO** | `…::test_the_default_limits_accept_the_real_history_profile_of_99334_lines` (99 334 filas × 20 columnas: inspección 0,09 s, encabezados + vista previa 0,10 s; ver `docs/07` §16 para la lectura completa en cola) |
| Error en español con código estable y estado HTTP 413 (tamaño/razón) o 422 | **IMPLEMENTADO / VERIFICADO** | `XlsxUploadHardeningTest` (9 casos); códigos `xlsx_*` en `docs/07` §16 |
| Límites de columnas, filas recorridas, **bytes de hoja y tiempo** en la ventana interactiva, con la apertura del libro (cadenas compartidas, encabezado de cada hoja) incluida | **IMPLEMENTADO / VERIFICADO** | `…::test_nodes_before_the_first_row_are_cut_by_the_interactive_byte_budget`, `…::test_the_audit_case_of_63_mb_of_nodes_before_the_first_row_is_cut_with_the_default_limits`, `…::test_the_interactive_time_budget_applies_to_the_sheet_scan`, `…::test_the_interactive_time_budget_covers_loading_the_shared_strings_when_opening_the_workbook`, `…::test_the_header_of_every_sheet_is_read_inside_the_budget_not_only_the_first_sheets`, `…::test_a_header_row_wider…`, `…::test_scanning_empty_rows…` |
| La importación en cola no está sujeta a la ventana interactiva; cerrada la ventana el resto de la hoja se lee a su ritmo | **IMPLEMENTADO / VERIFICADO** | `…::test_queue_reads_are_not_subject_to_the_interactive_window`, `…::test_the_window_closes_after_the_preview_rows…`, `XlsxUploadHardeningTest::test_the_analysis_is_bounded_by_the_interactive_window_but_the_queued_validation_is_not` |
| La guarda restaura siempre el envoltorio `zip://` y el estado de libxml, también al abortar | **IMPLEMENTADO / VERIFICADO** | `ZipArchiveInspectorTest::assertNoLeakedReaderState` (usado en las pruebas de la guarda y de rechazo del lector) |
| Una sola apertura del libro para encabezados y vista previa (antes dos, con doble carga de cadenas compartidas) | **IMPLEMENTADO / VERIFICADO** | `…::test_the_reader_reads_headers_and_rows_with_one_opening` |
| Los límites de lectura (columnas/filas/bytes/tiempo) se informan con código estable HTTP | **DISEÑADO / NO IMPLEMENTADO** — hoy son una importación `failed` con mensaje en español (HTTP 201); requiere tocar `ImportService`, fuera de este paquete |  |
| Mover el análisis a la cola con timeout corto | **DISEÑADO / NO IMPLEMENTADO** | `ImportService::analyze` sigue siendo síncrono |
| Carga de las cadenas compartidas interrumpible por tiempo | **IMPLEMENTADO / VERIFICADO** (antes DISEÑADO: la acotaba solo el tope de 32 MB, que NO acota el tiempo: 8–16 s medidos) | presupuesto de 10 s de la ventana, cortado en cada lectura del flujo: `…::test_the_interactive_time_budget_covers_loading_the_shared_strings_when_opening_the_workbook` |
| El presupuesto de tiempo es de reloj de pared: una máquina muy cargada puede cortar un libro legítimo grande | Limitación conocida; ajustable con `PREDICTILY_XLSX_ANALYSIS_TIMEOUT`. No rige la lectura en cola |  |
| CSV/TXT/TSV | **NO APLICA** | no son contenedores comprimidos |

## 4. Firma de artefactos de modelos

**Control.** ADR-V25-20: cada artefacto lleva un manifiesto firmado con Ed25519; el serving verifica firma, SHA-256 y tamaño **antes** de `joblib.load`. Formato, claves y procedimiento: `docs/ml/MODEL_REGISTRY.md`.

| Requisito | Estado | Evidencia |
|---|---|---|
| Manifiesto JSON canónico con SHA-256 y tamaño, empresa, core, corrida, familia, fecha, librerías, versión de esquema de variables y huella de datos; firma sobre sus bytes; `<artefacto>.manifest.json` | **IMPLEMENTADO / VERIFICADO** | `test_artifact_signing.py::test_a_signed_artifact_loads_and_its_manifest_binds_tenant_core_run_and_data`, `…::test_the_manifest_carries_data_fingerprint_and_versions_when_given` |
| Al guardar: firma si hay clave privada; si no, queda marcado sin firma (también en `artifact_compat.signature`) | **IMPLEMENTADO / VERIFICADO** | `…::test_without_a_private_key_the_artifact_is_saved_marked_unsigned`, `…::test_the_registry_row_records_the_signature_summary_in_artifact_compat`, `test_customer_integration.py::test_every_registered_artifact_has_a_signed_manifest_recorded_in_the_registry`, `test_demand_v2_integration.py::test_demand_artifacts_are_signed…` |
| Al cargar: firma + SHA-256 + tamaño verificados **antes** de deserializar, desde los mismos bytes (sin TOCTOU) | **IMPLEMENTADO / VERIFICADO** | `…::test_altered_bytes_are_rejected_before_deserializing`, `…::test_deserialization_uses_the_verified_bytes_not_the_file_on_disk`, `…::test_only_verified_artifacts_can_be_deserialized` |
| Pickle malicioso con SHA-256 coherente: sin firma, con firma inválida o con clave desconocida → rechazado **sin efecto lateral** (el payload no se ejecutó) | **IMPLEMENTADO / VERIFICADO** | `…::test_malicious_pickle_with_coherent_sha_and_no_signature_is_rejected_without_side_effects`, `…::test_malicious_pickle_with_invalid_signature…`, `…::test_an_unknown_signing_key_is_rejected_without_side_effects` |
| El manifiesto liga la empresa y la ruta se confina a `<ENGINE_ARTIFACTS_DIR>/<tenant_id>/`; un artefacto firmado de otra empresa se rechaza | **IMPLEMENTADO / VERIFICADO** | `…::test_a_signed_artifact_of_another_company_is_rejected`, `…::test_stored_paths_cannot_leave_the_company_directory` |
| Sin firma: rechazado con el indicador desactivado (por defecto), aceptado solo con `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true`; el indicador **nunca** acepta firmas inválidas | **IMPLEMENTADO / VERIFICADO** | `…::test_an_unsigned_artifact_is_rejected_by_default_and_loaded_only_with_the_explicit_flag`, `…::test_the_legacy_flag_never_accepts_invalid_signatures_unknown_keys_or_other_companies` |
| Degradación explícita: sin firma válida se sirve la referencia validada con el motivo en `limitations` y un log sin PII | **IMPLEMENTADO / VERIFICADO** | `test_customer_integration.py::test_a_production_artifact_without_a_valid_signature_degrades_to_the_validated_reference`, `test_artifact_signing.py::test_rejections_are_logged_without_paths_or_payload_data` |
| CLI de claves (privada a archivo 0600, pública impresa, sin sobrescribir) | **IMPLEMENTADO / VERIFICADO** | `…::test_keygen_creates_a_0600_private_file_prints_only_the_public_key_and_refuses_to_overwrite` |
| Backend: la reversión verifica la firma con `sodium_crypto_sign_verify_detached` y las claves públicas de confianza, además del SHA-256; sin firma/firma inválida → 409 | **IMPLEMENTADO / VERIFICADO** | `ModelArtifactSignatureTest` (19 casos: empresa y core ligados por el manifiesto, e incluida una firma producida por el motor Python y verificada por PHP) |
| Formatos seguros sin pickle (texto de LightGBM, JSON) | **DISEÑADO / NO IMPLEMENTADO** | ADR-V25-20 |
| Deserialización en subproceso sin credenciales de BD; endurecimiento del contenedor del motor | **DISEÑADO / NO IMPLEMENTADO** | defecto LOW «el contenedor del motor deserializa pickles sin endurecimiento» |
| Separar el despliegue que entrena (con la clave privada) del que sirve (solo con las públicas) | **DISEÑADO / NO IMPLEMENTADO** | hoy un mismo proceso del motor entrena y sirve: la firma protege contra quien escribe filas o archivos del volumen, no contra una ejecución de código ya lograda dentro del motor (`docs/ml/MODEL_REGISTRY.md` §3) |
| Claves en KMS/HSM, revocación explícita, re-firmado de artefactos legados, rotación sin ventana de rechazo | **DISEÑADO / NO IMPLEMENTADO** | `docs/ml/MODEL_REGISTRY.md` §5 |
| `docker-compose.yml` pasa las variables de firma sin valores secretos (clave privada como secreto de Compose solo para el motor; públicas y `ENGINE_ALLOW_UNSIGNED_ARTIFACTS` también al backend) | **IMPLEMENTADO** (`docker compose config` válido; no se levantó la pila) | `docker-compose.yml`, `.env.example` |
| `ext-sodium` declarada en `composer.json` (hash del lock actualizado) y comprobada en el `Dockerfile` del backend | **IMPLEMENTADO** (el `Dockerfile` no se construyó aquí: la extensión viene en `php8.3-common` de Ubuntu 24.04 y la comprobación falla la construcción si falta) | `backend/composer.json`, `backend/Dockerfile` |
| Comparación de la corrida del manifiesto con la de la fila del registro | **IMPLEMENTADO / VERIFICADO** en demanda (backend) y como parámetro opcional del motor; **DISEÑADO / NO IMPLEMENTADO** para el Customer Core (su upsert no actualiza `core_run_id`) | `ModelArtifactSignatureTest::test_a_demand_registry_row_must_point_to_the_artifact_of_its_own_run`, `test_artifact_signing.py::test_the_run_of_the_registry_row_is_compared_with_the_manifest_when_the_caller_knows_it` |
| Aprovisionar las claves reales en la pila Docker/E2E | **BLOQUEADO** hasta decidir dónde vive la clave privada (secreto del entorno de entrenamiento autorizado) |  |

## 5. MFA TOTP (W6-A · ADR-V25-15)

**Control.** Segundo factor TOTP (RFC 6238; HMAC-SHA1, 6 dígitos, paso de 30 s, ±1 paso de tolerancia) para **cualquier persona** que lo active y **obligatorio** para el superadministrador de la
plataforma (siempre en producción) y para propietarios y administradores de una empresa que active la política. Se implementó RFC 6238 sin dependencias (no hay biblioteca TOTP en `composer.lock`):
`App\Services\Auth\Mfa\Totp`, verificada contra los **vectores de prueba de los propios RFC 4226 y 6238** (SHA-1, SHA-256 y SHA-512).

**Flujo.**
0. **Rutas de cuenta.** Todo lo de `/auth/mfa/*` (salvo el desafío de inicio de sesión) y `/auth/tokens*` exige una **sesión de la SPA o un token de INICIO DE SESIÓN completo** (`account.full`, §8): un token restringido, de conector o ligado a una empresa recibe 403 `token_scope_insufficient` (T1–T9 de `AccountScopeTokensTest`).
1. `POST /auth/mfa/setup` —con la **contraseña actual**— genera un secreto de 160 bits (Base32) y la URI `otpauth://`; el secreto se guarda **cifrado con la clave de la aplicación** (`users.mfa_secret_encrypted`) y se devuelve solo en esa respuesta. Aún no está activo. Quien solo tiene una sesión o un token no puede enrolar su propio autenticador (y dejar fuera al dueño de la cuenta): sin contraseña o con una incorrecta, 422 `mfa_invalid_password`, y el error cuenta contra el mismo bloqueo que los códigos.
2. `POST /auth/mfa/confirm` verifica la contraseña y el **primer código**, activa (`mfa_enabled_at`), entrega **10 códigos de recuperación** (16 caracteres, ~78 bits; se muestran una sola vez y se guardan únicamente como
   HMAC-SHA-256 con una clave derivada de la clave de la aplicación; un código se consume al usarse), deja el segundo factor fresco y revoca los demás tokens de inicio de sesión **y las demás sesiones web y cookies «recordarme»** (se emitieron sin segundo factor; la sesión o el token actual se conserva). Queda en la auditoría `mfa.enrolment_started` / `mfa.enabled` con la IP y el cliente; **no hay un canal de notificación existente** (no hay envío de correos en la aplicación), así que el aviso a la persona es solo la auditoría: **DISEÑADO / NO IMPLEMENTADO** (notificar por correo al activar, desactivar o restablecer el segundo factor).
3. **Inicio de sesión:** con MFA activo, una contraseña correcta responde **202 `mfa_required`** con un desafío opaco de un solo uso (5 min) y **no** emite sesión ni token; `POST /auth/mfa/challenge`
   (desafío + código TOTP o de recuperación) completa el inicio de sesión. La auditoría `auth.login` se escribe solo al completar el segundo paso.
4. **Acciones sensibles (step-up):** quien tiene MFA activo necesita haberlo verificado hace menos de 10 minutos (`POST /auth/mfa/step-up`) para: **ajustes** (`settings.manage`), **usuarios y roles comerciales**
   (`users.manage`), **fuentes de datos, tokens de conector y dispositivos del Bridge** (`datasources.manage`), **aprobar/rechazar/promover modelos** (`models.approve`), **exportaciones** (`data.export`,
   `tenant.export_all`), **consentimiento y eliminación de la empresa** (`tenant.consent`, `tenant.delete`, `owners.manage`), **aprobar o rechazar una decisión** (`POST /decisions/{id}/transition` con
   `to_status` approved|rejected, middleware `mfa.step_up:approval`; y el seguimiento de la agenda «descartado» sobre una oportunidad REVISADA, que el workflow sincroniza como un rechazo formal), **promover un modelo por la reversión del Customer Core** (restaurar un aprobado que nunca se sirvió = promoverlo) y **validar un modelo ajeno a la publicación** (decisiones en línea con `can('models.approve')`; `MfaStepUp::approveClassAllowed`), **emitir un token personal** y las **modificaciones de plataforma** del superadministrador. Se impone en un solo lugar para las habilidades
   (`EnsureTenantAbility`, lista `security.mfa.sensitive_abilities`), de modo que una ruta nueva con una habilidad sensible queda protegida sin recordarlo. Sin el segundo factor: **403 `mfa_step_up_required`**. La
   marca de «fresco» vive en la sesión (SPA) o atada al **id del token** (cliente API); un segundo token de la misma persona no hereda la verificación.
5. **Política por empresa** (`PUT /tenant/security-policy`, `settings.manage`): `mfa_required_for_privileged`. Con ella, un propietario o administrador sin MFA puede iniciar sesión y activarlo pero no operar la
   empresa (**403 `mfa_enrollment_required`**) y no puede desactivarlo después (409 `mfa_required_by_policy`). Quien la activa debe tener ya su propio MFA (no se bloquea a sí mismo). Apagada por defecto.
6. **Superadministrador:** `security.mfa.superadmin_required` (por defecto, obligatorio solo en producción); sin MFA no entra a `/admin/*` ni opera ninguna empresa; con MFA, las operaciones que modifican exigen segundo factor fresco.
7. **Desactivar** (`DELETE /auth/mfa`): reautenticación con contraseña **y** código; revoca los demás tokens de inicio de sesión. **Códigos nuevos** (`POST /auth/mfa/recovery-codes`): exige un código vigente; los anteriores dejan de valer.
8. **Pérdida del dispositivo y de los códigos:** `POST /admin/users/{user}/mfa-reset` (solo superadministrador, con segundo factor fresco; motivo obligatorio de al menos 10 caracteres; revoca los tokens de inicio de sesión;
   auditoría `mfa.reset_by_platform`). Lo hace la plataforma y no el administrador de una empresa porque el segundo factor es de la **persona**: puede ser propietaria de otra empresa.

**Fuerza bruta y repetición (tres niveles).** Cada intento (código, código de recuperación o contraseña de reautenticación) **reserva un lugar antes de comprobarse** (UPDATE atómico de la persona y contadores atómicos por persona + IP y por IP);
reservar antes de comprobar cierra la carrera de peticiones paralelas. De más rápido a más lento, para que **quien conoce la contraseña no pueda bloquear a la víctima desde una sola IP**:
1) **persona + IP**: 5 fallos seguidos desde la misma IP bloquean ESA combinación 15 minutos (423 `mfa_locked`, `Retry-After`); la víctima, desde su IP, sigue entrando; 2) **IP**: 30 fallos de cualquier persona desde una IP la bloquean 15 minutos
(barrido de cuentas); 3) **persona (todas las IP)**: 15 fallos seguidos —de varias IP— bloquean a la persona 15 minutos (`users.mfa_locked_until`, persistente: sobrevive a un vaciado de caché y es coherente entre nodos) y entonces ni un código correcto se acepta desde ninguna IP.
Un acierto reinicia el contador de la persona y el de persona + IP. Un paso TOTP ya aceptado no se acepta otra vez (`users.mfa_last_used_step`, reclamo atómico; RFC 6238 §5.2). Además hay un límite por petición (`throttle:mfa`, 10/min).
Los valores (5/30/15 intentos, 15 minutos, 10 minutos de frescura, ±1 paso) son hipótesis de endurecimiento: **a aprobar con el propietario antes de producción**. Limitación: rotar de IP permite 5 intentos por IP hasta los 15 de la persona; los
contadores por IP viven en caché (un vaciado los reinicia; el de la persona no). El desafío de inicio de sesión se completa **una sola vez** aunque se envíe en paralelo (candado de corta vida: la segunda petición recibe 409 `mfa_challenge_busy`).

**Sin secretos en registros.** Ningún código, secreto, desafío ni código de recuperación se escribe en logs ni en auditoría (que guarda hechos: activó, falló, bloqueó, usó recuperación); `PiiMasker::SENSITIVE_KEYS`
elimina esas claves si alguien las pasara; el modelo `User` oculta `mfa_secret_encrypted` y `mfa_recovery_codes`.

| Requisito | Estado | Evidencia |
|---|---|---|
| TOTP RFC 6238 y HOTP RFC 4226 con los vectores del RFC (SHA-1/256/512, 8 dígitos; HOTP 0–9), Base32 RFC 4648, ventana ±1, repetición | **IMPLEMENTADO / VERIFICADO** | `tests/Unit/Security/TotpTest.php` (18 pruebas) |
| Activación con verificación del primer código; secreto cifrado; códigos de recuperación solo como hash y de un solo uso | **IMPLEMENTADO / VERIFICADO** | `MfaFlowTest::test_enrolment_stores_the_secret_encrypted_and_activates_only_after_a_valid_first_code`, `…::test_recovery_codes_are_shown_once_and_stored_only_as_hashes`, `…::test_a_recovery_code_completes_the_login_once`, `…::test_regenerating_recovery_codes_requires_a_current_code_and_invalidates_the_old_ones` |
| Inicio de sesión con desafío (sin sesión ni token hasta el segundo factor; un solo uso; vence; atado a la persona) | **IMPLEMENTADO / VERIFICADO** | `…::test_login_with_mfa_returns_a_challenge_and_no_token_until_the_code_is_verified`, `…::test_the_challenge_is_single_use_and_a_wrong_code_does_not_complete_the_login`, `…::test_an_expired_challenge_is_refused`, `…::test_a_user_cannot_use_another_persons_challenge_or_codes` |
| Ventana ±1 paso y sin repetición (reloj fijo) | **IMPLEMENTADO / VERIFICADO** | `…::test_codes_one_step_either_side_are_accepted_and_two_steps_are_not`, `…::test_a_totp_code_cannot_be_replayed` |
| Segundo factor fresco en acciones sensibles, que vence; cada familia protegida; sin MFA no se pide nada; la habilidad se evalúa antes | **IMPLEMENTADO / VERIFICADO** | `…::test_sensitive_abilities_need_a_fresh_second_factor_that_expires`, `…::test_every_sensitive_family_is_protected` (17 familias, incluidas aprobar/rechazar decisiones y token personal), `…::test_people_without_mfa_are_never_asked_for_a_second_factor`, `…::test_a_wrong_ability_is_reported_before_any_hint_about_the_second_factor`, `…::test_step_up_over_a_real_bearer_token_is_tied_to_that_token` |
| Bloqueo por fuerza bruta en tres niveles (persona + IP, IP, persona), atómico, también para códigos de recuperación y contraseñas; la víctima no queda bloqueada desde su IP; vencimiento | **IMPLEMENTADO / VERIFICADO** | `…::test_five_failures_from_one_ip_lock_that_combination_but_not_the_victim_elsewhere`, `…::test_the_person_is_locked_only_after_failures_from_several_ips_and_then_everywhere`, `…::test_one_ip_sweeping_many_accounts_is_locked_by_ip`, `…::test_the_lock_expires_and_a_success_resets_the_counter`, `…::test_attempts_are_reserved_before_checking_so_a_burst_cannot_try_more_than_the_limit` (50 intentos seguidos: solo 5 se comprueban) |
| Contraseña actual para activar, regenerar códigos (y los errores cuentan contra el bloqueo); auditoría con IP y cliente | **IMPLEMENTADO / VERIFICADO** | `MfaHardeningTest::test_M8_enrolment_needs_the_current_password_…`, `…::test_M8_wrong_passwords_count_against_the_same_lock_as_codes` |
| El desafío de inicio de sesión se completa una sola vez aunque haya peticiones paralelas | **IMPLEMENTADO / VERIFICADO** | `MfaHardeningTest::test_L1_a_challenge_held_by_another_request_is_refused_and_the_code_is_not_consumed`, `…::test_L1_a_failed_verification_releases_the_challenge_for_a_retry` |
| Activar, desactivar y restablecer cierran las demás sesiones web y las cookies «recordarme» (la sesión actual sigue) | **IMPLEMENTADO / VERIFICADO** | `MfaHardeningTest::test_L2_…` (sesiones de la SPA con su propia jarra de cookies; migración `2026_03_14_000220`, middleware `AuthenticateSessionWithRevocation`) |
| Una petición paralela que reclama el mismo paso TOTP antes gana y la otra se rechaza (reclamo atómico); un bloqueo vigente no se alarga ni suma intentos aunque otros sigan intentando; los valores por defecto de la política de bloqueo son los documentados (5/30/15, 15 min) | **IMPLEMENTADO / VERIFICADO** | `MfaHardeningTest::test_a_parallel_request_that_claims_the_same_step_first_wins_and_the_other_is_refused`, `…::test_a_locked_person_neither_extends_the_lock_nor_adds_attempts_when_others_keep_trying`, `…::test_the_lockout_policy_defaults_are_the_documented_ones` |
| Una sesión emitida en el mismo instante del corte de revocación queda cerrada; una posterior sigue | **IMPLEMENTADO / VERIFICADO** | `MfaHardeningTest::test_l2_a_session_issued_exactly_at_the_cutoff_instant_is_closed_and_a_later_one_is_not` |
| Segundo factor fresco que vence en la sesión de la SPA y no se hereda entre sesiones | **IMPLEMENTADO / VERIFICADO** | `MfaHardeningTest::test_the_second_factor_freshness_expires_on_the_spa_session_path` |
| Acciones de la clase «aprobar» decididas en línea (promover por reversión, validar un modelo ajeno a la publicación, descartar una oportunidad revisada) | **IMPLEMENTADO / VERIFICADO** | `StepUpBypassModelsTest` (M7; incluye una auditoría estática de los controladores con `can('models.approve')` y similares), `StepUpBypassFeedbackTest` |
| Campos ocultos del modelo, enmascarado de claves del segundo factor, HMAC con clave de la aplicación, modelo cargado a medias | **IMPLEMENTADO / VERIFICADO** | `MfaHardeningTest::test_the_mfa_columns_are_hidden_from_serialization`, `…::test_the_masker_removes_every_second_factor_secret_and_code`, `…::test_the_recovery_hash_is_a_keyed_hmac_not_a_plain_digest`, `…::test_has_mfa_enabled_reads_the_database_when_the_model_is_loaded_partially_or_stale` |
| Desactivación con reautenticación; revocación de tokens de inicio de sesión al activar/desactivar | **IMPLEMENTADO / VERIFICADO** | `…::test_disabling_requires_the_password_and_a_code`, `…::test_enabling_mfa_revokes_the_other_login_tokens` |
| Sin secretos ni códigos en logs, auditoría ni respuestas posteriores | **IMPLEMENTADO / VERIFICADO** | `…::test_no_secret_or_code_reaches_logs_audit_or_later_responses` |
| Política por empresa (obligatorio para propietario y administrador) y superadministrador | **IMPLEMENTADO / VERIFICADO** | `…::test_the_company_policy_makes_mfa_mandatory_for_owners_and_admins`, `…::test_the_superadmin_must_have_mfa_where_the_platform_requires_it_and_steps_up_to_change_things`, `…::test_by_default_the_platform_requirement_follows_the_environment` |
| Restablecimiento por la plataforma con motivo | **IMPLEMENTADO / VERIFICADO** | `…::test_the_platform_resets_a_lost_second_factor_with_a_reason_and_only_the_platform` |
| Interfaz de usuario del alta (QR, códigos de recuperación, pantalla de step-up) | **DISEÑADO / NO IMPLEMENTADO** | el frontend no forma parte de W6-A; la API devuelve la URI `otpauth://` y los códigos de error estables (`mfa_required`, `mfa_step_up_required`, `mfa_enrollment_required`) |
| WebAuthn/llaves de seguridad, SMS, SSO (OIDC/SAML) | **DISEÑADO / NO IMPLEMENTADO** | fuera del alcance de P0/P1 |
| Rotar `APP_KEY` | **IMPLEMENTADO / VERIFICADO** con `APP_PREVIOUS_KEYS` | el secreto TOTP cifrado y los códigos de recuperación se verifican con la clave vigente y las anteriores (el uso con una anterior queda auditado y `GET /auth/mfa` informa `recovery_codes_on_previous_key`). **Al retirar la clave anterior, los códigos sin usar que quedaron con ella dejan de servir** (el código en claro no se guarda y no se puede recalcular): regenerarlos ANTES de retirarla; sin `APP_PREVIOUS_KEYS` hay que restablecer el segundo factor con `mfa-reset`. Prueba: `MfaHardeningTest::test_L5_…` |

**Variables de entorno de W6-A** (todas con valor por defecto; ninguna es un secreto; `backend/config/security.php`): `PREDICTILY_MFA_ISSUER` (nombre que muestra la aplicación autenticadora, por defecto
`Predictily`), `PREDICTILY_MFA_SUPERADMIN_REQUIRED` (vacío = obligatorio solo en producción; `true`/`false` lo fuerza), `PREDICTILY_MFA_STEP_UP_TTL` (segundos de frescura, 600),
`PREDICTILY_TOKEN_DEFAULT_TTL_DAYS` (30), `PREDICTILY_TOKEN_MAX_TTL_DAYS` (365), `PREDICTILY_CONNECTOR_TOKEN_TTL_DAYS` (365), `PREDICTILY_RLS_ENABLED` (falso). `backend/phpunit.xml` las fija para que las pruebas
no dependan del `.env` de desarrollo. Los intentos, el bloqueo y los códigos de recuperación se cambian en el archivo de configuración (no por entorno), a propósito: son parte de la política que aprueba el propietario.

**Migraciones de W6-A** (prefijo `2026_03_14_0002xx`; aditivas y reversibles; no se modificó ninguna anterior): `2026_03_14_000200_add_mfa_lockout_and_replay_state_to_users` (`users.mfa_failed_attempts`,
`mfa_locked_until`, `mfa_last_used_step`: el contrato `000100` no trae el estado de bloqueo ni de repetición, que no puede vivir solo en caché) y `2026_03_14_000220_add_sessions_valid_after_to_users` (`users.sessions_valid_after`: instante desde el que valen las sesiones web; cada sesión guarda el de su emisión).

## 6. Dónde está el segundo factor en la API (resumen)

`POST /auth/login` (202 `mfa_required`) · `POST /auth/mfa/challenge` · `GET|DELETE /auth/mfa` · `POST /auth/mfa/{setup,confirm,step-up,recovery-codes}` · `POST /admin/users/{user}/mfa-reset` ·
`GET|PUT /tenant/security-policy`. Códigos estables: `mfa_required` (202), `mfa_invalid_code` (422), `mfa_challenge_invalid` (422), `mfa_locked` (423), `mfa_step_up_required` (403),
`mfa_enrollment_required` (403), `mfa_already_enabled`, `mfa_not_enabled`, `mfa_not_pending`, `mfa_required_by_policy` (409). Contrato: `docs/openapi/v2.yaml` (bloque W6-A) y `core.yaml`.

## 7. Roles comerciales: gerente, vendedor y servicio (W6-A · ADR-V25-15, §15)

**Modelo.** `tenant_users.commercial_role` (`gerente`, `vendedor`, `servicio`) y `tenant_users.commercial_scope` (jsonb) **se suman** al rol de acceso (`owner/admin/analyst/viewer`) y nunca lo
reemplazan: el rol de acceso dice **qué** puede hacer la persona (habilidades, sin cambios: los nombres no cambian y la prueba de espejo del motor `test_role_abilities_mirror.py` sigue en verde); el comercial
dice **sobre qué datos comerciales** y, en el caso de servicio, pone un techo de solo lectura. Se asigna con `PUT /tenant/users/{user}/commercial-role` (`users.manage`, sensible, auditado) y se ve en `GET /tenant/users`.

`commercial_scope`: `{"customer_ids":[…], "zones":[…], "segment_ids":[…], "team_user_ids":[…]}`; los identificadores se validan contra la empresa activa (uno de otra empresa es 422). La cartera de clientes es la
unión de los clientes listados, los de las zonas (`customers.commercial_zone`) y los de los segmentos.

**Mapa de habilidades (rol de acceso × rol comercial).** Verificado para las 4 × 4 combinaciones (excepto servicio en propietario, que se rechaza): `CommercialRolesTest::test_the_ability_mapping_of_access_role_by_commercial_role`.

| Rol comercial | Habilidades efectivas | Alcance de datos |
|---|---|---|
| (ninguno) | las del rol de acceso | toda la empresa |
| **gerente** | las del rol de acceso (no concede ninguna) | **todo el equipo**; si se le asigna alcance (zonas, segmentos, clientes, equipo), solo esa cartera y las recomendaciones asignadas a su equipo |
| **vendedor** | las del rol de acceso (no concede ninguna) | siempre acotado: las recomendaciones **asignadas a él** y las **sin asignar** de los clientes de su cartera; lo asignado a **otra persona** nunca se muestra, aunque el cliente esté en su cartera; sin cartera ni asignaciones, lista vacía |
| **servicio** | **techo de solo lectura = las del rol de consulta** (`data.view`, `forecast.view`, `alerts.view`, `decisions.view`), cualquiera sea su rol de acceso | toda la empresa (o su alcance, si lo tiene); no se asigna a un propietario |

**Valores inesperados fallan CERRADO, nunca abierto.** Solo `gerente`, `vendedor`, `servicio` o `NULL` se pueden escribir (422 en cualquier otro caso, `CommercialRoleRequest`; el único camino de escritura de la aplicación). La columna es texto libre
(contrato `2026_03_14_000100`); una restricción CHECK en la base sería una defensa adicional (**DISEÑADO / NO IMPLEMENTADO**: la prueba del gateway de W6-D simula un valor desconocido escribiéndolo a mano). Si la lectura
encuentra un valor no nulo que no es uno de los tres (otra mayúscula, un espacio, un rol retirado, una
cadena vacía), **no** lo trata como «sin rol comercial» (el alcance más amplio): la persona queda con el alcance MÁS restrictivo —ninguna recomendación ni cliente, ni siquiera lo que le asignaron— y en solo lectura (techo del
rol de consulta), se registra una advertencia (`Rol comercial inesperado en tenant_users…`, con persona y empresa pero sin la cartera) y la administración ve `commercial_role_unexpected: true` en `GET /tenant/users` para corregirlo.
Solo `NULL` es «sin rol comercial». Implementación: `CommercialRole::parseStored()` y `CommercialRole::effectiveAccessRole()`, usados por `ResolveTenant`, `CommercialScope::fromMembership()`, `TokenService` y `MemberResource`.

Consecuencia: **el rol comercial no concede habilidades**. Un vendedor que deba registrar seguimiento o gestionar decisiones necesita un rol de acceso con `decisions.manage` (analista); si en el piloto se necesita un vendedor
«de solo cartera» sin las demás habilidades del analista, hace falta una habilidad/rol de acceso nuevo (**DISEÑADO / NO IMPLEMENTADO**: no se hizo porque obliga a cambiar `TenantRole` y su espejo del motor, y el workflow de
decisiones compara contra el rol de acceso).

**Dónde se aplica (en el servidor, nunca en el cliente).** Un único servicio, `App\Services\Auth\Commercial\CommercialScope`, que fija `ResolveTenant` por petición, y un **registro único y fail-closed** de las rutas,
`App\Services\Auth\Commercial\CommercialSurfaces`, que `EnforceCommercialScope` consulta (corre justo después de resolver la empresa y **antes** del enlace de modelos: una ruta de equipo responde 403 aunque el id no exista, y una por id responde 404 exista o no: sin oráculo de existencia).
Con un alcance restringido (vendedor, o gerente/servicio con cartera), **cualquier ruta que toque clientes, recomendaciones u oportunidades y no esté clasificada responde 403 `commercial_scope_forbidden`** (por su URI o nombre —`radar.`, `decisions.`, `customer.`, `customers.`, `commitments.`, `catalog.customers`— o por
un parámetro `customer`/`recommendation`/`opportunity`). `CommercialSurfacesTest` enumera el router y falla si una ruta así queda sin clasificar o si el registro conserva una entrada que ya no existe.

**Lista exacta de superficies** (clave = `MÉTODO uri` de `CommercialSurfaces::SURFACES`):

| Clasificación | Superficies | Qué ocurre con un alcance restringido |
|---|---|---|
| **acotada: lista** (la consulta se acota con `restrictRecommendations`) | `GET radar/summary`, `GET radar/opportunities`, `GET radar/agenda`, `GET decisions`, `GET decisions/summary` | solo lo visible (asignado a la persona; sin asignar de su cartera); totales, valor potencial, estados y conteos se calculan sobre lo visible |
| **acotada: recomendación por id** | `GET radar/opportunities/{id}`, `POST radar/opportunities/{id}/feedback`, `GET decisions/{id}`, `POST decisions/{id}/transition` | 404 si no es visible; las escrituras no cambian nada |
| **acotada: cliente por id** (lecturas y ESCRITURAS) | `GET customers/{id}/predictions|demand|products|overview`, `GET customer/customers/{id}/profile`, `GET|POST catalog/customers/{customer}/aliases`, `DELETE catalog/customers/{customer}/aliases/{alias}`, `POST catalog/customers/{customer}/code-change` | 404 si el cliente no es de su cartera ni tiene una recomendación asignada a la persona; alias y cambio de código fuera de la cartera no se escriben |
| **acotada: filtro de cliente** | `GET customer/predictions` | exige `customer_id` visible (403 sin él, 404 si no es visible) |
| **de EQUIPO** (toda la empresa) | `POST radar/opportunities/materialize`, `GET decisions/outcomes`, `GET decisions/roi`, `POST customer/runs`, `GET customer/reconciliation`, `GET|POST commitments`, `GET commitments/{commitment}`, `POST commitments/{commitment}/cancel` | 403 `commercial_scope_forbidden` |
| **sin datos por cliente** | `GET demand/forecasts`, `GET supply/readiness`, `GET|PUT decisions/settings`, `GET customer/runs`, `GET customer/runs/{id}`, `GET customer/evaluation` | pasan (agregados por producto, metadatos de corridas, parámetros y métricas del modelo) |
| **fuera del registro** (no hablan de clientes por nombre ni por id) | `GET /sales`, `/sales/{sale}`, `/sales/summary`, `/alerts`, `/inventory/*`, `/exports/*.csv`, `/dashboard/*`, `/forecasts/*`, `/data-quality/*`… | de EMPRESA para quien tiene la habilidad de su rol de acceso: el rol comercial no las acota (**DISEÑADO / NO IMPLEMENTADO**; para un vendedor externo, usar un rol de acceso de consulta) |

- Configuración: `radar.seller_scope.unassigned_in_portfolio` (por defecto verdadero) decide si el vendedor ve además lo sin asignar de su cartera. Es una decisión de visibilidad: **a aprobar con el propietario en el piloto**.

| Requisito | Estado | Evidencia |
|---|---|---|
| El vendedor ve solo su cartera en lista, resumen y agenda; dos vendedores no se ven entre sí | **IMPLEMENTADO / VERIFICADO** | `CommercialRolesTest::test_a_vendedor_sees_only_their_portfolio_in_the_list_the_summary_and_the_agenda`, `…::test_assignments_decide_visibility_and_never_leak_to_the_other_vendedor`, `…::test_filtering_by_another_customer_or_seller_never_widens_the_scope` |
| Cartera por zona o segmento; sin cartera y sin asignaciones no ve nada | **IMPLEMENTADO / VERIFICADO** | `…::test_a_portfolio_can_be_defined_by_zone_or_segment_too`, `…::test_a_vendedor_without_portfolio_or_assignments_sees_nothing_and_fails_closed`, `…::test_the_unassigned_in_portfolio_rule_can_be_switched_off_by_configuration` |
| Ids fuera del alcance: 404 y sin cambios (detalle, seguimiento, decisión, transición, ficha, predicciones) | **IMPLEMENTADO / VERIFICADO** | `…::test_ids_outside_the_scope_are_not_found_and_nothing_changes`, `…::test_the_buyer_file_and_customer_predictions_follow_the_portfolio` |
| Listas y conteos de decisiones solo con lo visible | **IMPLEMENTADO / VERIFICADO** | `…::test_decision_lists_and_counts_only_include_what_the_vendedor_can_see` |
| Gerente ve el equipo; alcance regional y equipo | **IMPLEMENTADO / VERIFICADO** | `…::test_the_gerente_sees_the_whole_team_and_a_regional_scope_narrows_it` |
| Solo los tres roles o null se escriben (422); un valor guardado inesperado cierra el alcance (sin clientes, solo lectura, advertencia registrada) y nunca equivale a «sin rol» | **IMPLEMENTADO / VERIFICADO** | `CommercialRolesTest::test_only_the_three_commercial_roles_or_null_can_be_written`, `…::test_an_unexpected_stored_role_fails_closed_never_open` (administrador con valor dañado: lista, resumen, decisiones, ids, ficha y ROI cerrados; solo lectura; token sin escritura; ni una asignación explícita abre el alcance), `…::test_the_scope_object_closes_on_anything_that_is_not_a_known_role` |
| Servicio de solo lectura aunque su rol de acceso sea analista | **IMPLEMENTADO / VERIFICADO** | `…::test_servicio_is_read_only_whatever_the_access_role` |
| Vistas de equipo cerradas al vendedor | **IMPLEMENTADO / VERIFICADO** | `…::test_team_wide_views_are_closed_to_vendedores_until_they_are_scoped` |
| El gateway lee el rol comercial con la MISMA regla que la API (`CommercialRole::parseStored`): solo NULL es «sin rol»; la cadena vacía, los espacios o una variante de mayúsculas ya no abren el alcance | **IMPLEMENTADO / VERIFICADO** | `CommercialSurfacesTest::test_L4_the_gateway_scope_resolver_closes_on_blank_or_unexpected_roles_like_the_api` (edición mínima en `MembershipCommercialScopeResolver` de W6-D) |
| Alcance por empresa (la misma persona puede ser vendedora en una y analista en otra); orden del middleware (después de resolver la empresa y antes del enlace de modelos) | **IMPLEMENTADO / VERIFICADO** | `…::test_the_commercial_scope_is_resolved_per_company`, `…::test_the_scope_middleware_runs_after_the_company_is_resolved_on_every_scoped_route` |
| Asignación: validación de ids de la empresa, protección de propietarios, auditoría, listado | **IMPLEMENTADO / VERIFICADO** | `…::test_the_commercial_role_is_assigned_by_users_manage_audited_and_listed`, `…::test_assignment_rules_validate_tenant_ids_and_protect_owners` |
| Compromisos, alias y cambio de código del cliente (con sus escrituras), conciliación y entrenamiento del Customer Core; registro fail-closed de las rutas de clientes | **IMPLEMENTADO / VERIFICADO** | `CommercialSurfacesTest::test_M4_every_route_that_touches_customers_or_recommendations_is_classified_and_the_registry_has_no_stale_entries`, `…::test_M4_a_new_unclassified_customer_route_is_closed_for_restricted_scopes_and_open_for_everyone_else`, `…::test_S6_commitments_are_team_only_for_restricted_scopes`, `…::test_S6_customer_aliases_and_code_change_follow_the_portfolio_including_writes`, `…::test_S6_customer_core_views_are_scoped_or_team_only_or_free_of_customer_data` |
| Demás listas y vistas por empresa que no hablan de clientes (`/sales`, `/alerts`, `/inventory/*`, `/exports/*`…) | **DISEÑADO / NO IMPLEMENTADO** | el rol comercial no las acota (ver la tabla de superficies). Para un vendedor externo, usar un rol de acceso de consulta |
| Outcomes/ROI por cartera del vendedor | **DISEÑADO / NO IMPLEMENTADO** | `CommercialScope::restrictRecommendations()` está disponible para W5-C; mientras tanto, 403 |

## 8. Tokens con alcance y caducidad; revocación inmediata (W6-A)

Sanctum ya valida `abilities` y `expires_at` en **cada petición contra la base**: no hay caché de tokens, así que **revocar es borrar la fila y el efecto es inmediato** (la siguiente petición responde 401).

- **Las habilidades y la empresa de un token NO son una frontera en las rutas de cuenta, así que esas rutas no las admiten** (revisión HIGH-1). Tokens propios (`/auth/tokens*`), segundo factor (`/auth/mfa/*`), `GET|POST /tenants` y `PUT /auth/password` exigen una **sesión de
  la SPA o un token de inicio de sesión completo** (`api-login`: habilidades `*`, sin empresa; middleware `account.full`). Un token restringido (p. ej. solo `data.view`), de conector o ligado a una empresa recibe 403 `token_scope_insufficient`: no puede emitir otro con más habilidades,
  ni para otra empresa, ni enrolar su propio autenticador, ni revocar los demás tokens de la persona, ni crear o listar empresas. Defensa en profundidad: `TokenService::create` además exige que las habilidades nuevas sean un subconjunto de las del token que llama y que la empresa sea la suya
  (`TokenScopeException`). `GET /auth/me` con un token restringido o ligado a una empresa devuelve SOLO esa empresa. Los dispositivos del Bridge no usan Sanctum y no llegan a estas rutas. Consecuencia: un token restringido tampoco puede verificar el segundo factor (`step-up`), así
  que con MFA activo nunca hace acciones sensibles aunque tenga la habilidad.
- **Descarga de una exportación**: el enlace firmado vive una hora y la ruta no pasa por `tenant`; al descargar se vuelve a comprobar que la persona sea la que lo pidió, que SIGA siendo miembro con `tenant.export_all` y que un token ligado a otra empresa o sin esa habilidad no lo use (`TenantExportController::download`).
- **Emisión con alcance mínimo** (`POST /auth/tokens`): atado a una empresa de la que la persona es miembro (404 si no), habilidades **explícitas** (nunca `*`; 422 si superan las del rol efectivo de la persona en esa
  empresa), caducidad por defecto de 30 días (`security.tokens.default_ttl_days`) con tope de 365 (`max_ttl_days`), máximo 25 activos, y segundo factor fresco si tiene MFA. Se muestra una vez; solo se guarda el hash.
  Un token con la habilidad pero cuyo dueño perdió el rol no la ejerce (el rol manda además del token: `TokenLifecycleTest::test_a_demoted_member_cannot_use_abilities_the_token_still_lists`).
- **Listar y revocar los propios** (`GET /auth/tokens`, `DELETE /auth/tokens/{id}`, `POST /auth/tokens/revoke-others`): nunca se devuelve el valor; el id de un token ajeno es 404; «revocar los demás» conserva el actual y los de conector salvo `include_connectors`.
- **Revocación por la administración de la empresa** (`GET /tenant/tokens`, `DELETE /tenant/tokens/{id}`, `users.manage`): solo tokens ligados a la empresa activa; un token de otra empresa o de inicio de sesión sin empresa es 404. Quitar a un miembro revoca sus tokens de la empresa (`MembershipService`).
- **Caducidad por defecto configurable**: el token de inicio de sesión (`security.tokens.default_ttl_days`, antes 30 días fijos en el código) y el de conector (`security.tokens.connector_default_ttl_days`, 365; antes `addYear()` fijo). El de conector tiene un **tope de 365 días** (antes la API admitía hasta 730) también para el valor por defecto configurado.
- **Dispositivos del Bridge:** la revocación (`POST /bridge/devices/{device}/revoke`, `datasources.manage`, paquete W6-B) surte efecto en la **siguiente** petición del dispositivo y de la URL prefirmada ya emitida
  (`BridgeDeviceAuthTest::test_revocation_is_immediate_for_every_endpoint_and_pending_upload_urls`); por ser `datasources.manage` una habilidad sensible, exige segundo factor fresco (`BridgeRevocationSecurityTest`).
- **Sesiones del navegador:** no hay listado de sesiones web (el controlador de sesión puede ser `database` o `redis`); cerrar sesión revoca el token actual, cambiar la contraseña revoca los tokens de inicio de sesión y activar/desactivar el segundo factor o
  restablecerlo también. Listar y cerrar sesiones web individuales es **DISEÑADO / NO IMPLEMENTADO**.

| Requisito | Estado | Evidencia |
|---|---|---|
| Rutas de cuenta exigen inicio de sesión completo: un token restringido, de conector o ligado a una empresa no emite tokens, no enrola MFA, no revoca los de la persona, no crea ni lista empresas, no cambia la contraseña; `auth/me` solo muestra su empresa; el servicio de tokens rechaza más alcance o otra empresa | **IMPLEMENTADO / VERIFICADO** | `AccountScopeTokensTest` (T1–T9: restringido, conector y entre empresas, por familia de rutas; con MFA; sesión transitoria sin cambios; `…::test_a_wildcard_token_bound_to_a_company_is_not_a_full_login`; `…::test_the_wildcard_is_always_denied_when_issuing_even_if_a_role_list_contained_it` sobre `TokenService::deniedAbilities`) |
| La descarga de la exportación vuelve a comprobar membresía y habilidad; tope de 365 días del token de conector | **IMPLEMENTADO / VERIFICADO** | `AccountRoutesHardeningTest::test_L8_…` (2), `…::test_L9_the_connector_token_lifetime_is_capped_at_365_days` |
| Emisión con habilidades explícitas dentro del rol, caducidad por defecto y tope; mostrado una vez | **IMPLEMENTADO / VERIFICADO** | `TokenLifecycleTest::test_a_token_is_issued_with_explicit_minimal_abilities_a_default_expiry_and_is_shown_once`, `…::test_the_expiry_is_configurable_and_capped`, `…::test_abilities_must_be_explicit_and_within_the_role`, `…::test_the_number_of_active_personal_tokens_is_bounded` |
| Solo hace lo concedido y solo en su empresa | **IMPLEMENTADO / VERIFICADO** | `…::test_the_token_only_does_what_it_was_granted_and_only_in_its_company`, `…::test_a_token_cannot_be_issued_for_a_company_the_person_does_not_belong_to` |
| Revocación inmediata (propia y por la administración); vencimiento sin revocar | **IMPLEMENTADO / VERIFICADO** | `…::test_revoking_a_token_takes_effect_on_the_very_next_request`, `…::test_an_expired_token_stops_working_without_being_revoked`, `…::test_the_company_admin_lists_and_revokes_tenant_tokens_immediately`, `…::test_revoke_others_keeps_the_current_token_and_the_connectors`, `…::test_removing_a_member_revokes_their_company_tokens` |
| Aislamiento: solo tokens propios; administración sin cruzar empresas | **IMPLEMENTADO / VERIFICADO** | `…::test_a_person_only_manages_their_own_tokens`, `…::test_tenant_token_administration_never_crosses_companies_and_needs_users_manage` |
| Caducidad por defecto configurable (inicio de sesión y conector) | **IMPLEMENTADO / VERIFICADO** | `…::test_the_login_token_follows_the_configured_default_expiry`, `…::test_the_connector_token_follows_the_configured_default_expiry` |
| Los tokens de conector existentes conservan `imports.manage` y `data.view` | **LIMITACIÓN CONOCIDA** | el alcance mínimo de ingestión sería solo `imports.manage`; reducirlo rompería conectores ya emitidos y su `usage` publicado: **a decidir con el propietario** |

## 9. Política de seguridad de la empresa

`GET|PUT /tenant/security-policy` (`settings.manage`, sensible): `mfa_required_for_privileged` (ver §5). La respuesta informa cuántos propietarios y administradores aún no tienen MFA antes de activarla.
Se guarda en `tenants.settings.security`; la auditoría `tenant.security_policy_updated` registra el cambio. **IMPLEMENTADO / VERIFICADO** en `MfaFlowTest::test_the_company_policy_makes_mfa_mandatory_for_owners_and_admins`.

## 10. RLS de PostgreSQL y aislamiento entre empresas

Ver [`docs/architecture/TENANT_ISOLATION.md`](../architecture/TENANT_ISOLATION.md): matriz de rutas × roles × empresa generada del router real, pruebas negativas en colas, caché, almacén de objetos, registro de
modelos y exportaciones LOPDP, y RLS (**EXPERIMENTAL, apagada por defecto y no apta para producción**: sin puerta de plataforma en la sesión, con la empresa vuelta a fijar tras un rollback, y con tres bloqueos documentados —el motor no fija la empresa por transacción, cuenta de aplicación de mínimo privilegio y *pooler*—; `RowLevelSecurityTest`, 11 pruebas).

## 11. Pendiente de ADR-V25-15 (no cubierto)

Siguen **DISEÑADOS / NO IMPLEMENTADOS**: listado y cierre de sesiones web individuales, interfaz del segundo factor, WebAuthn/SSO, un rol de acceso de «vendedor» sin habilidades de analista, alcance comercial en las
demás listas por empresa y en outcomes/ROI, RLS en más tablas y con *pooler* transaccional, retención de archivos importados e IP, cuenta de BD de mínimo privilegio para el motor, enmascaramiento de PII en el
canal de log `monthly`, CI efectivo con `composer audit`/`pip-audit`, y los defectos MEDIUM/LOW de las tres auditorías no listados arriba (p. ej. confinar las rutas absolutas del verificador del backend, purga de
artefactos al purgar una empresa, TLS obligatorio hacia el origen). **BLOQUEADOS**: S3 real con IAM por prefijo y KMS, el instalador Windows del Bridge y su firma de código. Que estén fuera de este documento no
significa que estén resueltos.
