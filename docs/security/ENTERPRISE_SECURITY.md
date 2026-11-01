# Seguridad Enterprise · controles P0 de la V2.5 (paquete W1-B)

Fecha: 31-oct-2026 · Rama `v25/enterprise-core` · Fuente: ADR-V25-15 (seguridad y aislamiento como bloqueo de release) y ADR-V25-20 (artefactos
firmados antes de deserializar); defectos HIGH de `docs/roadmap/audit/2026-10-31/{conectores,seguridad_ui,modelos_aws}.json`.

Este documento recoge **solo** los controles del paquete W1-B (endurecimiento P0). El plan general de seguridad, aislamiento multi-tenant y privacidad está en
[`docs/07-seguridad-multitenant.md`](../07-seguridad-multitenant.md); la firma de artefactos con su procedimiento de claves, en [`docs/ml/MODEL_REGISTRY.md`](../ml/MODEL_REGISTRY.md).
Los demás controles de ADR-V25-15 (MFA TOTP, roles Bridge/servicio, RLS, pruebas negativas por canal, retención…) **no** forman parte de este paquete y se listan en §5.

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
| 2 | ZIP bomb en XLSX: inspección del directorio ZIP antes de abrir, límites de entradas/tamaño/razón/anidados, lectura de encabezados acotada | HIGH (seguridad_ui #2) | **IMPLEMENTADO / VERIFICADO** |
| 3 | Firma Ed25519 de artefactos de modelos verificada antes de `joblib.load` (motor y reversión del backend) | HIGH (modelos_aws #1) | **IMPLEMENTADO / VERIFICADO** |

## 2. SSRF en conectores SQL

**Control.** `SqlHostPolicy::resolve()` valida y resuelve el destino de cada conexión y devuelve la IP a la que debe conectarse el dialecto. Detalle y tablas de rangos: `docs/07` §13.2.

| Requisito | Estado | Evidencia |
|---|---|---|
| Solo nombres RFC 1123 o literales IP canónicos; se rechazan decimal (`2130706433`), hexadecimal (`0x7f.0.0.1`, `0xA9FEA9FE`), octal (`0177.0.0.1`) y abreviadas (`127.1`) | **IMPLEMENTADO / VERIFICADO** | `SqlHostPolicyTest::test_numeric_forms_and_malformed_hosts_are_rejected_before_any_resolution` (30 vectores; el resolvedor no se consulta) |
| Resolución de A y AAAA; todas las respuestas deben estar permitidas; un nombre sin respuesta se rechaza | **IMPLEMENTADO / VERIFICADO** | `…::test_a_and_aaaa_are_resolved_and_every_answer_must_be_allowed`, `…::test_an_unresolvable_host_is_denied…` |
| Des-mapeo de IPv4 mapeadas/compatibles/traducidas/NAT64 (`::ffff:169.254.169.254`, `::ffff:a9fe:a9fe`, `::a9fe:a9fe`, `64:ff9b::a9fe:a9fe`) | **IMPLEMENTADO / VERIFICADO** | `…::test_cloud_metadata_link_local_multicast_and_transition_forms_are_always_denied` (26 vectores) |
| Siempre bloqueados, aun con lista blanca: loopback salvo IP/CIDR explícito y nunca en producción; `0.0.0.0/8`, link-local, metadatos (`169.254.169.254`, `fd00:ec2::254`), multicast, broadcast | **IMPLEMENTADO / VERIFICADO** | misma prueba (con `allowed_hosts` y `allowed_cidrs = 0.0.0.0/0, ::/0` siguen bloqueados), `…::test_loopback_*` |
| Redes privadas (RFC 1918, ULA `fc00::/7`, `100.64.0.0/10`) denegadas por defecto salvo lista blanca explícita (hosts y CIDR) | **IMPLEMENTADO / VERIFICADO** | `…::test_private_networks_are_denied_by_default`, `…::test_a_private_address_is_authorized_by_the_explicit_ip_or_by_a_covering_cidr`, `…::test_a_listed_hostname_may_resolve_to_a_private_network_but_never_to_loopback_or_metadata` |
| Conexión a la IP resuelta (anti DNS rebinding): PostgreSQL `hostaddr` + `host` para TLS; MySQL y SQL Server al literal IP | **IMPLEMENTADO / VERIFICADO** en PostgreSQL con servidor real; **BLOQUEADO** para MySQL/SQL Server con servidor | `…::test_the_validated_ip_is_pinned_so_a_second_resolution_cannot_change_the_destination`, `…::test_postgres_connects_to_the_pinned_ip_without_ever_resolving_the_name` (un nombre que no existe en ningún DNS conecta solo gracias a la IP fijada) |
| Mensajes de rechazo genéricos (sin oráculo de la red interna) | **IMPLEMENTADO / VERIFICADO** | `…::test_denial_messages_are_generic_and_do_not_reveal_the_network`, `…::test_the_connector_test_reports_the_generic_denial_not_the_network` |
| Desde la API: formas numéricas → 422 en `config.host`; metadatos, loopback y redes privadas dejan la fuente `inactive` con el mensaje genérico; lista cerrada rechaza lo demás | **IMPLEMENTADO / VERIFICADO** | `tests/Feature/DataCore/SqlSourceHostPolicyApiTest.php` (3 casos) |
| Las pruebas que usan el PostgreSQL local declaran su destino en la lista blanca | **IMPLEMENTADO / VERIFICADO** | `ErpSourceDatabase::authorizeInConnectorPolicy`, `SqlConnectorContractTest::runServerContract`; suites `tests/Unit/DataCore` y `tests/Feature/DataCore` en verde |
| Normalizar los mensajes de error del controlador (hoy hasta 400 caracteres del driver, con IP/puerto de un destino **permitido**) | **DISEÑADO / NO IMPLEMENTADO** | — |
| Limitar puertos de destino; exigir TLS hacia el origen | **DISEÑADO / NO IMPLEMENTADO** | defecto MEDIUM «TLS hacia el origen no es obligatorio» |
| SQL Server con `Encrypt=1`: el certificado debe cubrir la IP fijada o usarse `trust_server_certificate` | **BLOQUEADO** (sin servidor ni `pdo_sqlsrv`) — limitación conocida |  |
| Conectores REST/CSV/Excel | **NO APLICA** | no abren conexiones salientes hacia un destino de la empresa |

**Cambio de configuración (rompe compatibilidad, por diseño).** `PREDICTILY_SQL_DENY_PRIVATE` ya no existe; se añade `PREDICTILY_SQL_ALLOWED_CIDRS`. Un ERP detrás de VPN ahora exige
declarar su host o su red. El E2E `e2e/stage2_acceptance.py` y `docker-compose.yml` deben declarar `PREDICTILY_SQL_ALLOWED_HOSTS=127.0.0.1` (o el host del origen) en un entorno distinto de producción.

## 3. Cargas: ZIP bomb en XLSX

**Control.** `ZipArchiveInspector` inspecciona el directorio central **antes** de abrir el libro, tanto en la validación de la subida (`StoreImportRequest`, sin guardar el archivo) como en `XlsxReader`.
Límites y variables: `docs/07` §16 y `backend/config/upload_safety.php`.

| Requisito | Estado | Evidencia |
|---|---|---|
| Inspección del directorio ZIP: nº de entradas, suma de tamaños descomprimidos, razón por entrada y global, archivos anidados, entradas cifradas, nombres peligrosos, entradas solapadas | **IMPLEMENTADO / VERIFICADO** | `ZipArchiveInspectorTest` (18 casos) |
| Bomba generada en la prueba rechazada en menos de 2 s | **IMPLEMENTADO / VERIFICADO** | `ZipArchiveInspectorTest::test_the_audit_bomb_is_rejected_in_under_two_seconds` y `XlsxUploadHardeningTest::test_the_audit_bomb_is_rejected_with_413_a_stable_code_and_in_under_two_seconds`: 874 KB con `sharedStrings.xml` de 200 000 183 bytes, rechazada en ≈ 1 ms (antes, 23 s de CPU) |
| Valores por defecto razonables para el histórico real | **IMPLEMENTADO / VERIFICADO** | `…::test_the_default_limits_accept_the_real_history_profile_of_99334_lines` (99 335 filas × 20 columnas: 12 entradas, 124 MB descomprimidos, razón 12,7:1) |
| Error en español con código estable y estado HTTP 413 (tamaño/razón) o 422 | **IMPLEMENTADO / VERIFICADO** | `XlsxUploadHardeningTest` (7 casos); códigos `xlsx_*` en `docs/07` §16 |
| Límites de columnas, filas recorridas y tiempo en la lectura de encabezados y vista previa | **IMPLEMENTADO / VERIFICADO** | `ZipArchiveInspectorTest::test_a_header_row_wider…`, `…::test_scanning_empty_rows…`, `…::test_the_header_and_preview_read_has_a_time_budget` |
| Una sola apertura del libro para encabezados y vista previa (antes dos, con doble carga de cadenas compartidas) | **IMPLEMENTADO / VERIFICADO** | `…::test_the_reader_reads_headers_and_rows_with_one_opening` |
| Los límites de lectura (columnas/filas/tiempo) se informan con código estable HTTP | **DISEÑADO / NO IMPLEMENTADO** — hoy son una importación `failed` con mensaje en español (HTTP 201); requiere tocar `ImportService`, fuera de este paquete |  |
| Mover el análisis a la cola con timeout corto | **DISEÑADO / NO IMPLEMENTADO** | `ImportService::analyze` sigue siendo síncrono |
| Carga de las cadenas compartidas interrumpible por tiempo | **DISEÑADO / NO IMPLEMENTADO** — la acota el tope de 32 MB (≈ 4 s en el peor caso) |  |
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
| Claves en KMS/HSM, revocación explícita, re-firmado de artefactos legados, rotación sin ventana de rechazo | **DISEÑADO / NO IMPLEMENTADO** | `docs/ml/MODEL_REGISTRY.md` §5 |
| Aprovisionar claves en la pila Docker/E2E | **BLOQUEADO** hasta decidir dónde vive la clave privada (secreto del entorno de entrenamiento autorizado) |  |

## 5. Fuera de este paquete (no cubierto aquí)

Siguen **DISEÑADOS / NO IMPLEMENTADOS** por este paquete, con su dueño en el plan de oleadas (`docs/roadmap/`): MFA TOTP y revocación de sesiones/dispositivos, roles Bridge/servicio, RLS en tablas piloto,
pruebas negativas entre tenants en colas/caché/almacén de objetos/URLs prefirmadas/registro de modelos, retención de archivos importados e IP, cuenta de BD de mínimo privilegio para el motor, enmascaramiento de PII en el
canal de log `monthly`, CI efectivo con `composer audit`/`pip-audit`, y los defectos MEDIUM/LOW de las tres auditorías no listados arriba (p. ej. confinar las rutas absolutas del verificador del backend, purga de
artefactos al purgar una empresa, TLS obligatorio hacia el origen). Que estén fuera de este documento no significa que estén resueltos.
