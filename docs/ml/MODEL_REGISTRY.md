# Registro de modelos y firma de artefactos

Fecha: 31-oct-2026 (actualizado el 1-nov-2026) · Rama `v25/enterprise-core` · Fuentes: ADR-V25-07 (registro de modelos), ADR-V25-20 (artefactos firmados antes de deserializar), defecto HIGH de
`docs/roadmap/audit/2026-10-31/modelos_aws.json`.

Este documento define **la firma y verificación de artefactos** (§2–§4), que está implementada y verificada, y deja explícito en §5 que **el resto de la gestión del registro
queda DISEÑADO / NO IMPLEMENTADO**. Para el plan de seguridad general: [`docs/07-seguridad-multitenant.md`](../07-seguridad-multitenant.md) §17 y
[`docs/security/ENTERPRISE_SECURITY.md`](../security/ENTERPRISE_SECURITY.md).

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** (no se puede construir o verificar en este entorno) ·
**NO APLICA**.

## 1. El registro hoy (descripción de lo existente)

`model_registry` (PostgreSQL) guarda por modelo: empresa, versión, familia, nivel, `core` (`demand`|`customer`), `status`
(`candidate`/`validated`/`production`/`retired`/`failed`) y `role` (`primary`/`fallback`), hiperparámetros, huella de datos, `artifact_path`, `artifact_sha256`, versiones de librerías, semilla y
`artifact_compat` (jsonb). El archivo del artefacto es un joblib (pickle comprimido) bajo `ENGINE_ARTIFACTS_DIR`:

- demanda: `<empresa>/run-<id>/<familia>.joblib` (solo LightGBM; hoy se escribe en cada corrida y **nunca se carga**: los estadísticos se reajustan);
- Customer Core: `<empresa>/customer/run-<id>/h<H>_<objetivo>_<familia>.joblib` (se carga al predecir).

`artifact_path` se guarda relativo a `ENGINE_ARTIFACTS_DIR`, con `/`. Esta firma **no añade columnas ni migraciones**: el resumen de la firma va en el jsonb existente `artifact_compat.signature` y el manifiesto
completo, junto al archivo.

## 2. Firma de artefactos (ADR-V25-20) — IMPLEMENTADO / VERIFICADO

### 2.1 Qué se firma y dónde queda

Junto a cada artefacto se escribe `<artefacto>.manifest.json`, un **sobre** JSON:

```json
{
  "format": "predictily-signed-manifest/1",
  "algorithm": "ed25519",
  "key_id": "k-2026-11",
  "manifest": "{\"artifact\":{\"file_name\":\"h30_probability_logistic.joblib\",\"format\":\"joblib\",\"sha256\":\"…\",\"size_bytes\":12345},\"core\":\"customer\",\"created_at\":\"2026-10-31T21:08:54Z\",\"data_fingerprint\":\"…\",\"feature_schema_version\":\"cust-fs-2026.10-v1\",\"key_id\":\"k-2026-11\",\"libraries\":{\"lightgbm\":\"4.7.0\",\"scikit-learn\":\"1.9.1\"},\"name\":\"h30_probability_logistic\",\"run_id\":12,\"schema\":\"predictily-artifact-manifest/1\",\"tenant_id\":7}",
  "signature": "<base64 de 64 bytes>"
}
```

- `manifest` es **el texto exacto que se firma**: JSON canónico (claves ordenadas recursivamente, separadores `,` y `:` sin espacios, solo ASCII). Quien verifica firma sobre esos bytes sin re-serializar nada, por lo que Python (motor)
  y PHP (backend) no dependen de cómo cada uno canonicaliza JSON.
- Campos del manifiesto: `artifact` (`file_name`, `format`, `sha256`, `size_bytes`), `tenant_id`, `core`, `run_id`, `name` (familia/nombre), `created_at` (UTC), `libraries` (versiones), `feature_schema_version`
  (versión del esquema de variables), `data_fingerprint` (huella de los datos: `training_data_hash` en demanda, `data_snapshots.hash` en Customer Core), `key_id` y `schema`.
- Sin clave privada configurada, el sobre se escribe **sin firma** (`algorithm`, `key_id` y `signature` en `null`): el artefacto queda *marcado como sin firma*, sin ser de confianza.
- En la base de datos, `model_registry.artifact_compat.signature` = `{status: signed|unsigned, algorithm, key_id, manifest_sha256}` (la huella del sobre).
- Escritura: el artefacto se serializa en memoria, su SHA-256 y tamaño salen de los bytes que se escriben (no se relee el archivo), se escribe de forma atómica y **después** el manifiesto.

### 2.2 Claves y variables

| Variable | Lado | Contenido |
|---|---|---|
| `ENGINE_SIGNING_KEY_ID` | entrenamiento | Identificador de la clave (`[A-Za-z0-9][A-Za-z0-9._-]{0,63}`); obligatorio si hay clave privada |
| `ENGINE_SIGNING_PRIVATE_KEY` | entrenamiento | Semilla Ed25519 de 32 bytes en base64, o `file:<ruta>` (preferible: secreto montado como archivo). **Solo** en el entorno autorizado a firmar |
| `ENGINE_TRUSTED_PUBLIC_KEYS` | servicio (motor y backend) | Lista `kid:clave` (clave pública de 32 bytes en base64) separada por comas, punto y coma o espacios. Una entrada inválida o un `kid` repetido con valores distintos no confía en nada |
| `ENGINE_ALLOW_UNSIGNED_ARTIFACTS` | servicio (motor y backend) | `false` por defecto. `true`/`1`/`yes`/`on` acepta artefactos **legados sin firma**, verificables por su SHA-256 del registro. Nunca acepta firmas inválidas |

El backend lee las mismas variables (`config/engine.php`, `engine.artifact_signing`) y **nunca** lleva claves privadas. Las variables están (comentadas, sin valores) en `engine/.env.example` y `backend/.env.example`.

### 2.3 Procedimiento: generar, instalar y rotar claves

1. En el entorno de entrenamiento autorizado: `python -m predictily_engine.cli keygen --key-id k-2026-11 --private-out /ruta/segura/firma.key`.
   La privada se crea con permisos **0600** desde la creación y **no se imprime**; el comando se niega a sobrescribir un archivo existente (`--force` lo permite: lo firmado con esa clave dejaría de verificarse).
   Imprime la clave pública, su huella SHA-256 (para confirmarla por otro canal) y las líneas de variables.
2. Entrenamiento: `ENGINE_SIGNING_KEY_ID=k-2026-11` y `ENGINE_SIGNING_PRIVATE_KEY=file:/ruta/segura/firma.key` (mejor como secreto del orquestador, no en un `.env` versionado).
3. Servicio: `ENGINE_TRUSTED_PUBLIC_KEYS=k-2026-11:<clave pública>` en el motor **y** en el backend.
4. Verificar sin deserializar: `python -m predictily_engine.cli verify-artifact --path <artifact_path> --tenant-id <id> --core customer --sha256 <artifact_sha256>` (salida JSON `ok`, `reason`, `status`, `key_id`).
5. **Rotación**: generar una clave nueva, añadir su pública a `ENGINE_TRUSTED_PUBLIC_KEYS` (se admiten varias), pasar el entrenamiento a la nueva y, cuando no queden artefactos firmados con la antigua que deban servirse
   (o tras reentrenar), retirar la pública antigua. Retirar una pública invalida de inmediato lo firmado con ella (es también la forma de **revocar**). Un re-firmado masivo de artefactos existentes no está implementado (§5).
6. Si una clave privada se compromete: retirar su pública de la lista de confianza en motor y backend, generar un par nuevo y reentrenar.

### 2.4 Verificación al cargar (motor)

`platform/artifacts.py::verify_stored_artifact` + `deserialize`, usados por `customer/service.py::load_artifact(row, tenant_id)`:

1. **Ruta confinada** a `<ENGINE_ARTIFACTS_DIR>/<tenant_id>/` tras normalizar (`..`, enlaces simbólicos, rutas absolutas ajenas) → `artifact_path_invalid`. Las filas antiguas (ruta absoluta o relativa al directorio de trabajo) siguen leyéndose si caen dentro.
2. **Lectura única**: los bytes se leen una vez; todo lo demás se calcula sobre ellos.
3. **SHA-256 del registro** → `artifact_sha256_mismatch` (también si el registro no lo trae).
4. **Manifiesto**: ausente o marcado sin firma → `artifact_unsigned` (salvo `ENGINE_ALLOW_UNSIGNED_ARTIFACTS`, verificable por el SHA-256 del paso 3); corrupto, demasiado grande (> 256 KB) o con forma inválida → `artifact_manifest_invalid`
   (**nunca** se trata como «sin firma», ni con el indicador).
5. **Clave de confianza**: `key_id` fuera de `ENGINE_TRUSTED_PUBLIC_KEYS` → `artifact_untrusted_key`.
6. **Firma** Ed25519 sobre los bytes de `manifest` → `artifact_signature_invalid`.
7. **El manifiesto firmado describe este archivo**: `sha256` → `artifact_manifest_mismatch`; `size_bytes` → `artifact_size_mismatch`; `file_name` → `artifact_manifest_mismatch`; `tenant_id` → `artifact_tenant_mismatch`; `core` → `artifact_core_mismatch`.
8. Solo entonces, y tras las comprobaciones de compatibilidad (esquema de variables, versiones de scikit-learn/LightGBM), **se deserializa desde esos mismos bytes** (`joblib.load(BytesIO)`): un reemplazo del archivo entre la verificación y la carga no tiene efecto.

Si algo falla **no se deserializa** y el Customer Core usa su respaldo existente: la referencia validada del horizonte (rol `fallback`, calidad `degraded`) o, si tampoco es utilizable, abstención; el motivo estable queda en `limitations` de la corrida y en un log
de nivel WARNING con el motivo, la empresa y la versión del modelo (sin rutas ni datos). La demanda no carga artefactos: reajusta los estadísticos en cada corrida (`artifact_kind = statistical_refit`).

### 2.5 Verificación al revertir (backend)

`ModelArtifactVerifier` (usado por `POST /models/demand/rollback` y `POST /models/customer/rollback`): si el archivo es visible en `engine.artifacts_dir`, comprueba el SHA-256 y la firma (`sodium_crypto_sign_verify_detached`, extensión `sodium`)
con las claves públicas de `engine.artifact_signing.trusted_public_keys`, y que el manifiesto sea de la empresa, del core (`demand`/`customer`, el de la fila del registro) y del archivo. Respuestas 409: `rollback_artifact_mismatch` (SHA-256), `rollback_artifact_unsigned` (sin firma, salvo el indicador) y
`rollback_artifact_signature_invalid` (firma inválida, clave desconocida, manifiesto corrupto, de otra empresa o de otro core; con el motivo en `context.reason`). La respuesta añade `artifact_verification.signed` y `.unsigned_allowed`.
Si el archivo no es visible desde el backend, se informa como hasta ahora (lo verifica el motor al cargar), pero la marca `artifact_compat.signature.status = unsigned` que dejó el motor basta para rechazar.

### 2.6 Artefactos legados

Los artefactos entrenados antes de este cambio no tienen manifiesto: son **legado sin firma** (ADR-V25-20: verificables por SHA-256, no publicables a otro entorno sin re-firmarse). Con el indicador desactivado (por defecto) el motor no los carga y el backend
no revierte a ellos. Para un entorno **local** puede activarse `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true` en el motor y el backend; en cualquier otro, reentrenar con clave de firma.

### 2.7 Entorno local, Docker y E2E (qué hay que aprovisionar)

La firma es obligatoria: **sin claves, un entrenamiento deja artefactos `unsigned` y el motor no los carga** (el Customer Core usa la referencia validada o se abstiene, con `artifact_unsigned` en `limitations`). Una pila local o de E2E
necesita, para conservar el comportamiento anterior, una de estas dos opciones (ambas son variables de entorno del motor y del backend; **no** están aprovisionadas por defecto en `docker-compose.yml`):

| Opción | Qué definir | Cuándo |
|---|---|---|
| **A. Claves de desarrollo (recomendada)** | `python -m predictily_engine.cli keygen --key-id k-dev --private-out ./firma-dev.key` y, en el motor: `ENGINE_SIGNING_KEY_ID`, `ENGINE_SIGNING_PRIVATE_KEY=file:<ruta>` y `ENGINE_TRUSTED_PUBLIC_KEYS`; en el backend: `ENGINE_TRUSTED_PUBLIC_KEYS` | Cualquier entorno que entrene y sirva. Los modelos nuevos quedan firmados |
| **B. Legado explícito** | `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true` en el motor y en el backend | Solo un entorno **local** con modelos ya entrenados antes de este cambio (sin manifiesto). No acepta firmas inválidas; no usarlo en staging ni producción |

Los modelos entrenados antes de este cambio son legado sin firma: con la opción A hay que reentrenarlos; con la B se cargan. Las pruebas automatizadas usan la opción A con claves efímeras y nunca la B.

### 2.8 Pruebas que lo demuestran

| Comportamiento | Prueba |
|---|---|
| Artefacto válido carga; el manifiesto liga empresa, core, corrida y datos | `engine/tests/unit/test_artifact_signing.py::test_a_signed_artifact_loads_and_its_manifest_binds_tenant_core_run_and_data`, `…::test_the_manifest_carries_data_fingerprint_and_versions_when_given` |
| Bytes alterados → rechazado antes de deserializar | `…::test_altered_bytes_are_rejected_before_deserializing`, `…::test_a_truncated_or_extended_file_is_rejected_by_size…` |
| Pickle malicioso con SHA-256 coherente, sin firma / firma inválida / clave desconocida → rechazado **sin que el payload se ejecute** | `…::test_malicious_pickle_with_coherent_sha_and_no_signature…`, `…::test_malicious_pickle_with_invalid_signature…`, `…::test_an_unknown_signing_key…` |
| Manifiesto corrupto nunca equivale a «sin firma» | `…::test_a_corrupt_manifest_is_never_treated_as_an_unsigned_artifact` |
| Artefacto de otra empresa; ruta fuera del directorio; manifiesto de otro archivo | `…::test_a_signed_artifact_of_another_company_is_rejected`, `…::test_stored_paths_cannot_leave_the_company_directory`, `…::test_the_manifest_is_bound_to_its_own_file` |
| Sin firma: rechazado con el indicador desactivado, cargado con él; el indicador no acepta firmas inválidas | `…::test_an_unsigned_artifact_is_rejected_by_default_and_loaded_only_with_the_explicit_flag`, `…::test_the_legacy_flag_never_accepts_invalid_signatures_unknown_keys_or_other_companies`, `…::test_the_flag_defaults_to_false…` |
| TOCTOU: se deserializa lo verificado | `…::test_deserialization_uses_the_verified_bytes_not_the_file_on_disk` |
| Claves: formato, errores, `file:` | `…::test_trusted_keys_parsing_is_strict_and_fails_closed`, `…::test_private_key_can_be_read_from_a_file…` |
| CLI de claves y verificación | `…::test_keygen_creates_a_0600_private_file…`, `…::test_verify_artifact_command_reports_the_verification_without_deserializing` |
| Entrenar → firmar → predecir; degradación a la referencia | `engine/tests/integration/test_customer_integration.py::test_every_registered_artifact_has_a_signed_manifest…`, `…::test_a_production_artifact_without_a_valid_signature_degrades_to_the_validated_reference`; `test_demand_v2_integration.py::test_demand_artifacts_are_signed…` |
| Backend: reversión con firma, sin firma, firma inválida, otra empresa, marca `unsigned`; firma del motor Python verificada por PHP | `backend/tests/Feature/Customer/ModelArtifactSignatureTest.php` (fixture `backend/tests/Fixtures/engine-signed/`) |

Las pruebas del motor generan un par de claves **efímero** por sesión (`engine/tests/conftest.py`) y **no** activan `ENGINE_ALLOW_UNSIGNED_ARTIFACTS`; las del backend lo hacen por prueba (`SignsModelArtifacts`).

## 3. Límites conocidos de la firma

- Confía en la custodia de la clave privada: quien la tenga puede firmar. Hoy es un archivo/secreto del entorno de entrenamiento; KMS/HSM está **DISEÑADO / NO IMPLEMENTADO**.
- **Entrenamiento y serving comparten proceso.** Hoy el mismo despliegue del motor entrena (firma) y sirve (verifica), así que la clave privada es legible por el código del motor: la firma protege contra quien escribe una
  fila de `model_registry` o un archivo del volumen, **no** contra una ejecución de código ya lograda dentro del motor. Separar el despliegue que entrena (con la privada) del que sirve (solo con las públicas) y deserializar en un
  subproceso sin credenciales es **DISEÑADO / NO IMPLEMENTADO** (§5).
- Un atacante con escritura en el volumen **y** que borre el manifiesto convierte un artefacto firmado en «sin firma»: se rechaza salvo `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true`, por eso el indicador es solo para entornos locales.
- No hay *replay* de versiones por firma: un artefacto antiguo con firma válida y SHA-256 coherente en su fila sigue siendo válido (es lo que hace la reversión). El control de qué versión sirve es el estado del registro (§5).
- La verificación del backend exige que el archivo sea visible; si no, delega en el motor.
- El backend requiere la extensión `sodium` de PHP (presente en este entorno; declararla en `composer.json` y en la verificación de extensiones del `Dockerfile` queda pendiente del arquitecto). Sin ella no se confía en nada
  (`signature_verifier_unavailable`).

## 4. Matriz de estado de este documento

| Elemento | Estado |
|---|---|
| Manifiesto canónico + firma Ed25519 al guardar (demanda y Customer Core) | **IMPLEMENTADO / VERIFICADO** |
| Verificación antes de `joblib.load` (firma, SHA-256, tamaño, empresa, core, ruta confinada, sin TOCTOU) en el motor | **IMPLEMENTADO / VERIFICADO** |
| Verificación de firma en la reversión del backend | **IMPLEMENTADO / VERIFICADO** |
| CLI `keygen` y `verify-artifact` | **IMPLEMENTADO / VERIFICADO** |
| Marca `unsigned` en `artifact_compat.signature` | **IMPLEMENTADO / VERIFICADO** |
| Servicio de demanda cargando artefactos | **NO APLICA** (no se cargan: estadísticos reajustados; LightGBM se reentrena en cada corrida) |
| Aprovisionar claves en la pila Docker/E2E | **BLOQUEADO** (decisión del propietario sobre dónde vive la clave privada) |
| Verificación contra un backend con `sodium` ausente | **BLOQUEADO** (no reproducible aquí; el código falla cerrado) |

## 5. Gestión del registro — DISEÑADO / NO IMPLEMENTADO

Nada de lo siguiente existe todavía; es el diseño de ADR-V25-07 y de los defectos de `modelos_aws.json`, a implementar en oleadas posteriores:

- **Ciclo de vida con aprobación separada**: `candidate → validated → approved → production → retired`, más `rejected`; `approved` exige un usuario con la habilidad `models.approve` distinto de quien entrenó o publicó; el serving
  solo carga artefactos `production` aprobados con firma verificada (hoy la firma sí se verifica; la aprobación no existe).
- **Metadatos completos** (algoritmo, propósito, granularidad, transformadores, dependencias, ventana de entrenamiento, métricas train/backtest/holdout, dueño, justificación, modelo anterior, política aplicada) y la columna dedicada de firma
  y URI de almacén de objetos (ADR-V25-08: `ObjectStore` local/S3 con claves de servidor por tenant).
- **Reversión solo a candidatos que superaron la regla de promoción**; reversión de producción restringida a roles con motivo obligatorio y MFA.
- **Invariantes en BD**: una sola producción por slot del Customer Core, CHECK de estados, coherencia de tenant en el esquema del registro.
- **Conciliación y retención** registro ↔ almacenamiento; purga de artefactos al purgar una empresa; migración idempotente de filas legadas a ruta relativa.
- **Formatos seguros sin pickle** (texto de LightGBM, parámetros en JSON), deserialización en subproceso sin credenciales de BD y endurecimiento del contenedor del motor.
- **Re-firmado** de artefactos legados, firma por KMS/HSM, revocación explícita de claves y volumen de artefactos montado solo en el servicio que verifica.
