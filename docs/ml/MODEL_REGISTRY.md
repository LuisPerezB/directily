# Registro de modelos: ciclo de vida, aprobación separada y firma de artefactos

Fecha: 31-oct-2026 (actualizado el 1-nov-2026 por V2.5 · W4-A y W4-B) · Rama `v25/w1-cierre-y-w2` · Fuentes: ADR-V25-07 (registro de modelos: aprobación separada y serving solo de lo aprobado), ADR-V25-20 (artefactos firmados antes de
deserializar), defecto HIGH de `docs/roadmap/audit/2026-10-31/modelos_aws.json`.

Este documento define **la firma y verificación de artefactos** (§2–§4) y **el ciclo de vida del registro con aprobación separada** (§5, implementado en el backend por W4-A). Lo que sigue pendiente de la gestión del registro está en
§6 (**DISEÑADO / NO IMPLEMENTADO**) y lo que depende del motor y de la CLI (W4-B) en §5.10. Para promoción, reversión, qué se publica dónde y medición de uso: [`MODEL_DEPLOYMENT.md`](MODEL_DEPLOYMENT.md). Para el plan de seguridad general:
[`docs/07-seguridad-multitenant.md`](../07-seguridad-multitenant.md) §17 y [`docs/security/ENTERPRISE_SECURITY.md`](../security/ENTERPRISE_SECURITY.md).

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** (no se puede construir o verificar en este entorno) ·
**NO APLICA**.

## 1. El registro hoy (descripción de lo existente)

`model_registry` (PostgreSQL) guarda por modelo: empresa, versión, familia, nivel, `core` (`demand`|`customer`), `status`
(`candidate`/`validated`/`approved`/`production`/`retired`/`rejected`/`failed`, con CHECK en la base de datos; ver §5) y `role` (`primary`/`fallback`), hiperparámetros, huella de datos, `artifact_path`, `artifact_sha256`, versiones de librerías, semilla y
`artifact_compat` (jsonb); desde W4 también propósito, granularidad, modelo anterior, decisión de promoción asociada, publicación (`published_by`, `published_env`, `published_at`, `published_object_key`) y aprobación (`approved_by`, `approved_at`, `rejected_reason`). El archivo del artefacto es un joblib (pickle comprimido) bajo `ENGINE_ARTIFACTS_DIR`:

- demanda: `<empresa>/run-<id>/<familia>.joblib` (solo LightGBM; hoy se escribe en cada corrida y **nunca se carga**: los estadísticos se reajustan);
- Customer Core: `<empresa>/customer/run-<id>/h<H>_<objetivo>_<familia>.joblib` (se carga al predecir).

`artifact_path` se guarda relativo a `ENGINE_ARTIFACTS_DIR`, con `/`. La firma **no añadió columnas ni migraciones**: el resumen de la firma va en el jsonb existente `artifact_compat.signature` y el manifiesto
completo, junto al archivo. Las columnas del ciclo de vida las añade la migración `2026_03_12_000100` (contrato del arquitecto, W4).

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
   (o tras reentrenar), retirar la pública antigua. Retirar una pública invalida de inmediato lo firmado con ella (es también la forma de **revocar**). Un re-firmado masivo de artefactos existentes no está implementado (§6).
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
Desde W4-A la reversión **además** exige que el modelo esté aprobado o sea un legado del pipeline propio (§5.6) y deja sus eventos de auditoría (§5.2).
**Aprobar** un modelo es más estricto que revertir: exige firma Ed25519 **válida** (un legado sin firma aceptado por `ENGINE_ALLOW_UNSIGNED_ARTIFACTS` no se aprueba) y que el archivo sea visible (§5.4).

### 2.6 Artefactos legados

Los artefactos entrenados antes de este cambio no tienen manifiesto: son **legado sin firma** (ADR-V25-20: verificables por SHA-256, no publicables a otro entorno sin re-firmarse). Con el indicador desactivado (por defecto) el motor no los carga y el backend
no revierte a ellos. Para un entorno **local** puede activarse `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true` en el motor y el backend; en cualquier otro, reentrenar con clave de firma.

### 2.7 Entorno local, Docker y E2E (qué hay que aprovisionar)

La firma es obligatoria: **sin claves, un entrenamiento deja artefactos `unsigned` y el motor no los carga** (el Customer Core usa la referencia validada o se abstiene, con `artifact_unsigned` en `limitations`). Una pila local o de E2E
necesita, para conservar el comportamiento anterior, una de estas dos opciones (ambas son variables de entorno del motor y del backend). `docker-compose.yml` ya las **pasa** a los servicios, sin valores secretos y vacías por defecto:
`ENGINE_SIGNING_KEY_ID`, `ENGINE_TRUSTED_PUBLIC_KEYS` y `ENGINE_ALLOW_UNSIGNED_ARTIFACTS` al motor, `ENGINE_TRUSTED_PUBLIC_KEYS` y `ENGINE_ALLOW_UNSIGNED_ARTIFACTS` al backend (que nunca recibe la clave privada) y la clave PRIVADA solo al
motor, como secreto de Compose montado en `/run/secrets/engine_signing_key` desde el archivo que indique `ENGINE_SIGNING_PRIVATE_KEY_FILE` (`ENGINE_SIGNING_PRIVATE_KEY=file:/run/secrets/engine_signing_key`; sin ese archivo el motor no firma). Sin claves, la pila arranca igual
y los modelos nuevos quedan sin firma, que es lo que hay que aprovisionar:

| Opción | Qué definir | Cuándo |
|---|---|---|
| **A. Claves de desarrollo (recomendada)** | `python -m predictily_engine.cli keygen --key-id k-dev --private-out ./firma-dev.key` y, en el motor: `ENGINE_SIGNING_KEY_ID`, `ENGINE_SIGNING_PRIVATE_KEY=file:<ruta>` y `ENGINE_TRUSTED_PUBLIC_KEYS`; en el backend: `ENGINE_TRUSTED_PUBLIC_KEYS` | Cualquier entorno que entrene y sirva. Los modelos nuevos quedan firmados |
| **B. Legado explícito** | `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true` en el motor y en el backend | Solo un entorno **local** con modelos ya entrenados antes de este cambio (sin manifiesto). No acepta firmas inválidas; no usarlo en staging ni producción |

Los modelos entrenados antes de este cambio son legado sin firma: con la opción A hay que reentrenarlos; con la B se cargan. Las pruebas automatizadas usan la opción A con claves efímeras y nunca la B.

### 2.8 Pruebas que lo demuestran

| Comportamiento | Prueba |
|---|---|
| Artefacto válido carga; el manifiesto liga empresa, core, corrida y datos | `engine/tests/unit/test_artifact_signing.py::test_a_signed_artifact_loads_and_its_manifest_binds_tenant_core_run_and_data`, `…::test_the_manifest_carries_data_fingerprint_and_versions_when_given` |
| La corrida del manifiesto se compara con la de la fila del registro cuando el llamador la conoce (motor: `verify_stored_artifact(..., run_id=)` y `verify-artifact --run-id`; backend: demanda, con `forecast_run_id`) | `engine/tests/unit/test_artifact_signing.py::test_the_run_of_the_registry_row_is_compared_with_the_manifest_when_the_caller_knows_it`, `backend/tests/Feature/Customer/ModelArtifactSignatureTest.php::test_the_manifest_run_is_compared_with_the_one_the_caller_knows`, `…::test_a_demand_registry_row_must_point_to_the_artifact_of_its_own_run` |
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
  subproceso sin credenciales es **DISEÑADO / NO IMPLEMENTADO** (§6).
- Un atacante con escritura en el volumen **y** que borre el manifiesto convierte un artefacto firmado en «sin firma»: se rechaza salvo `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true`, por eso el indicador es solo para entornos locales.
- No hay *replay* de versiones por firma: un artefacto antiguo con firma válida y SHA-256 coherente en su fila sigue siendo válido (es lo que hace la reversión). El control de qué versión sirve es el estado del registro y su aprobación (§5).
- La verificación del backend exige que el archivo sea visible; si no, delega en el motor.
- El backend requiere la extensión `sodium` de PHP: declarada como `ext-sodium` en `backend/composer.json` (con el hash de `composer.lock` actualizado) y comprobada en el `Dockerfile` del backend junto a las demás extensiones. Sin ella no se confía en nada
  (`signature_verifier_unavailable`).
- La corrida del manifiesto (`run_id`) se compara con la de la fila del registro **solo donde la fila la guarda con certeza**: la demanda en el backend (`forecast_run_id`; el motor ofrece `run_id=` en `verify_stored_artifact` para quien la conozca). El Customer
  Core NO se compara: su `INSERT … ON CONFLICT (tenant_id, version) DO UPDATE` no actualiza `core_run_id`, así que tras reentrenar una versión la fila conserva la corrida anterior mientras el artefacto y el manifiesto son de la nueva
  (daría un falso rechazo). Pendiente (`customer/service.py`, fuera de este paquete): actualizar `core_run_id` en el upsert y pasar `run_id` en `load_artifact`.

## 4. Matriz de estado de la firma (§2–§3)

| Elemento | Estado |
|---|---|
| Manifiesto canónico + firma Ed25519 al guardar (demanda y Customer Core) | **IMPLEMENTADO / VERIFICADO** |
| Verificación antes de `joblib.load` (firma, SHA-256, tamaño, empresa, core, ruta confinada, sin TOCTOU) en el motor | **IMPLEMENTADO / VERIFICADO** |
| Verificación de firma en la reversión del backend | **IMPLEMENTADO / VERIFICADO** |
| Comparación de la corrida del manifiesto con la de la fila del registro | **IMPLEMENTADO / VERIFICADO** (demanda en el backend; motor: parámetro opcional) · Customer Core **DISEÑADO / NO IMPLEMENTADO** (ver §3) |
| CLI `keygen` y `verify-artifact` | **IMPLEMENTADO / VERIFICADO** |
| Marca `unsigned` en `artifact_compat.signature` | **IMPLEMENTADO / VERIFICADO** |
| Servicio de demanda cargando artefactos | **NO APLICA** (no se cargan: estadísticos reajustados; LightGBM se reentrena en cada corrida) |
| Pasar las variables de firma en `docker-compose.yml` (sin valores secretos; clave privada como secreto de Compose solo para el motor) | **IMPLEMENTADO** (`docker compose config` válido; no ejecutado con un motor real) |
| Aprovisionar las claves reales en la pila Docker/E2E | **BLOQUEADO** (decisión del propietario sobre dónde vive la clave privada) |
| Verificación contra un backend con `sodium` ausente | **BLOQUEADO** (no reproducible aquí; el código falla cerrado) |

## 5. Ciclo de vida del registro y aprobación separada (W4-A) — IMPLEMENTADO / VERIFICADO en el backend

Implementa ADR-V25-07 sobre el contrato de la migración `2026_03_12_000100` (estados con CHECK, `approved_by`, `published_*`, `model_registry_events`). Código: `backend/app/Services/Models/**`
(`ModelState`, `ModelLifecycle`, `ModelServingPolicy`, `ModelApprovalService`, `ModelApprovalGates`, `ModelResponsibles`), `backend/app/Http/Controllers/Api/Models/ModelRegistryController.php`, `backend/routes/api/models.php`.

### 5.1 Estados y transiciones

```
candidate ─► validated ─► approved ─► production ─► retired ─┐
                                          ▲                    │ (reversión)
                                          └────────────────────┘
rejected (terminal)   desde candidate, validated o approved
failed   (terminal)   legado del motor, desde candidate
```

| Desde | Hacia (permitido) |
|---|---|
| `candidate` | `validated`, `rejected`, `failed` |
| `validated` | `approved`, `rejected`, `retired`, `production` (solo legado) |
| `approved` | `production`, `rejected`, `retired` |
| `production` | `retired` |
| `retired` | `production` (reversión; solo aprobado o legado) |
| `rejected`, `failed` | (terminales) |

Cualquier otra transición responde 409 `model_transition_not_allowed`. `ModelLifecycle` es el **punto único** que cambia `model_registry.status` en el backend (aprobación, rechazo, promoción y las dos reversiones): valida la tabla
anterior, impide que un modelo no aprobado entre en producción (409 `model_not_approved`) y escribe el evento **en la misma transacción** que el cambio de estado.

### 5.2 Eventos de auditoría (`model_registry_events`)

Una fila por transición: `from_status` (nulo en la línea base), `to_status`, `actor_user_id`, `actor_kind` (`user` | `system` | `cli`), `reason` y `evidence` (jsonb: compuertas con firma, gate de datos, decisión de política, alcance,
`serving_basis`, y para reversiones `trigger`, corridas y verificación de artefactos). Solo inserción. Si el modelo aún no tiene eventos (legado escrito por el motor) se escribe primero una **línea base** con su estado actual. Además cada
decisión queda en la bitácora general (`audit_logs`: `models.validated|approved|rejected|promoted`, `models.demand_rolled_back`, `models.customer_rolled_back`). `GET /models/{id}` devuelve los eventos en orden de inserción (por `id`: el reloj de quien escribe —CLI o backend— no decide el orden).

### 5.3 Aprobación separada y segregación de funciones

- `POST /models/{id}/approve` y `/reject` (y `/promote`) exigen la habilidad **`models.approve`** (nueva; propietario y administrador; analista y consulta no).
- **Segregación de funciones** (`ModelResponsibles`): no puede aprobar **ni rechazar** quien **publicó** el modelo (`published_by`), quien **disparó su entrenamiento** ni quien lo **registró como candidato** (primer evento con actor). Del entrenamiento
  se consideran **todos** los trabajos que enlacen al modelo o a su corrida: `model_registry.training_job_id`, `training_jobs.model_registry_id`, `training_jobs.forecast_run_id` / `core_run_id` (incluidos los reintentos disparados por otra persona), más
  `forecast_runs.user_id` y `core_runs.user_id`. Responde 403 `approval_segregation_of_duties` con las funciones que lo impiden (`context.roles`, sin identificadores). Un entrenamiento programado o del sistema (sin `user_id`) no tiene responsable humano.
  `promote` vuelve a comprobar que el aprobador registrado no sea responsable (defensa ante una fila manipulada).
- `reject` exige un motivo de 5 a 1000 caracteres (422 sin él); queda en `rejected_reason` y en el evento. `approved → rejected` retira una aprobación antes de que el modelo entre en producción: **limpia** `approved_by`/`approved_at` del modelo (y la
  aprobación de la decisión de W3 asociada) y el evento conserva la historia (`evidence.previous_approval` = quién y cuándo). Además `basis()` mira el estado: un `rejected`/`failed` nunca es servible aunque conserve una huella de aprobación.
- Al aprobar se completa la **decisión de promoción de W3** asociada (`promotion_decision_id`): `model_promotion_decisions.approved_by` y `approved_at`.
- **Validar no sirve el modelo.** `POST /models/{id}/validate` (`candidate → validated`) ejecuta las compuertas automáticas. A un candidato **publicado** por la CLI lo valida quien tiene `forecast.run`; a un modelo que **no** entró por la publicación (un retador que el
  motor dejó como `candidate`) solo quien tiene `models.approve` (403 `forbidden`, `required_ability = models.approve`). En ningún caso la validación vuelve servible al modelo: un evento `candidate → validated` de una **persona** deja al modelo `not_approved` hasta su aprobación separada.

### 5.4 Compuertas (todas fallan cerrado)

`validate` las ejecuta (candidate → validated); `approve` y `promote` las repiten, porque el archivo o el informe pueden cambiar entre pasos. Cada resultado queda en `evidence.gates`.

| Compuerta | Regla | Error 409 (`code`) |
|---|---|---|
| Firma | `ModelArtifactVerifier::inspect`: SHA-256 del registro, manifiesto firmado, clave de confianza, firma Ed25519, empresa, core y archivo. Debe estar **firmado** (el indicador de legado no basta). Un modelo publicado se verifica sobre el **objeto del almacén** (`config/mlops.php` → `published_disk`; la clave debe ser `tenants/<uuid de la empresa>/models/…`); el resto, en `ENGINE_ARTIFACTS_DIR`. Archivo no visible = no se aprueba. **Un modelo publicado sin `published_object_key` falla cerrado** (no hay objeto que verificar y no se verifica otro archivo en su lugar). Sin artefacto (estadístico del pipeline que se reajusta) = NO APLICA, dicho en la evidencia | `approval_artifact_not_verifiable`, `approval_artifact_unsigned`, `approval_artifact_mismatch`, `approval_artifact_signature_invalid`, `approval_artifact_without_checksum`, `approval_object_key_invalid` |
| Datos (W2-C) | `data_quality_reports` (scope `run`) de la ejecución del modelo → `gate_results[módulo].outcome` **≠ BLOCK** (DEGRADE y WARN pasan). Sin informe de ejecución se admite el resultado declarado al publicar (`artifact_compat.data_gate.outcome`); sin evidencia no se aprueba. El informe manda sobre lo declarado | `approval_data_gate_blocked`, `approval_data_gate_missing` |
| Política (W3) | Si la decisión asociada es `reject`, una aprobación humana no anula los gates de la política | `approval_policy_rejected` |
| Compatibilidad (Customer) | Slot `h<H>:<objetivo>` válido (H del catálogo del Customer Core) y esquema de variables igual al del motor | `model_without_slot`, `model_incompatible` |

### 5.5 Promoción a producción

`POST /models/{id}/promote`: `approved → production`; retira (`retired`) al modelo que servía en el mismo **alcance** en la misma transacción (Customer: el mismo slot; demás cores: misma familia, clase de demanda y rol), registra
`previous_model_registry_id`, vuelve a verificar las compuertas y respeta el plan (402 si el horizonte del Customer Core no está incluido). Un candidato, un validado o un rechazado **no se promueven**: 409.

**Concurrencia.** `promote` y las dos reversiones bloquean la fila de la empresa (`SELECT … FOR UPDATE`) y las de los modelos del alcance dentro de la transacción: dos promociones del mismo alcance se serializan y la segunda retira a la primera, de modo que queda **una** producción por alcance (`ServingPolicyHardeningTest::test_successive_promotions_of_the_same_scope_always_leave_a_single_production_model`). Eso es una garantía de la aplicación, no de la base: un índice único parcial (`UNIQUE (tenant_id, core, (hyperparameters->>'slot')) WHERE status = 'production'` para el Customer Core) la haría estructural, pero exige revisar los datos del motor y los E2E antes de crearlo (**DISEÑADO / NO IMPLEMENTADO**, recomendado). La prueba de dos procesos simultáneos reales no se automatizó (las pruebas corren dentro de una transacción de una sola conexión).

### 5.6 Política explícita para los modelos LEGADOS

Los modelos que entrenó y promovió el pipeline propio **antes** de la aprobación separada no dejan de servirse ni de poder revertirse: son el comportamiento de la demo, del rollback y de los E2E (`e2e/mvp_acceptance.py`, `e2e/stage2_acceptance.py`).
`ModelServingPolicy::basis()` evalúa, en este orden (la definición de legado es **positiva** y falla cerrada):

1. `rejected` / `failed` → **`not_approved`** (el estado manda, aunque conserve `approved_*`).
2. `approved_at` o `approved_by` y estado `approved`/`production`/`retired` → **`approved`**.
3. Entró por la **publicación** → `not_approved`: cualquier marca `published_*` **o** cualquier evento con `actor_kind = cli`.
4. Estado distinto de `production`/`validated`/`retired` (un `candidate`, incluso del pipeline) → `not_approved`.
5. Una **persona** lo validó (evento `candidate → validated` con `actor_kind = user`) → `not_approved` hasta su aprobación.
6. **Origen positivo del pipeline** → **`legacy_pipeline`**: una corrida enlazada (`forecast_run_id`, `core_run_id` o `training_job_id`) o el evento de línea base con `evidence.legacy_policy`.
7. Cualquier otro caso (sin corrida ni marca) → `not_approved`.

| `serving_basis` | Quién | ¿Se sirve / revierte? |
|---|---|---|
| `approved` | `approved_at`/`approved_by` con estado de aprobado | Sí |
| `legacy_pipeline` | Del pipeline propio, con origen positivo, nunca publicado ni validado por una persona | Sí (la decisión automática de la política de promoción de W3 hace de aprobación) |
| `not_approved` | Todo lo demás: publicado sin aprobar, candidato, validado por una persona, sin origen, `rejected`, `failed` | **No**: 409 `rollback_target_not_approved` / `model_not_approved` |

La migración `2026_03_12_000300` fija la clasificación en un **evento de línea base** («legado aprobado por migración», `evidence.legacy_policy = legacy_approved_by_migration`) solo para lo que el motor dejó en `production`, `validated` o `retired` sin
`published_*` ni `approved_*` (idempotente y reversible); sirve también cuando la corrida enlazada se purgó (FK en nulo). Lo que el motor registre después conserva su corrida enlazada y recibe la misma línea base —solo si es `production`/`validated`/`retired`— al
primer cambio de estado; un `candidate` **nunca** recibe la marca. Que un legado no pueda publicarse a otro entorno sin aprobación y sin re-firmado (ADR-V25-20) lo aplica la CLI de W4-B (§5.10): `publish` solo acepta paquetes firmados que creó `train` y registra siempre un `candidate`; re-firmar un legado para publicarlo no está implementado (se reentrena).

**Contrato de publicación que debe cumplir la CLI (W4-B).** Un modelo publicado jamás es legado, pero solo si el backend puede reconocerlo como publicado. Al registrar el candidato la CLI debe:

| Campo | Valor |
|---|---|
| `status` | `candidate` (nunca `validated`, `approved` ni `production`) |
| `published_by` | id del usuario que publica |
| `published_env` | `local`, `staging` o `production` |
| `published_at` | fecha de publicación |
| `published_object_key` | clave del servidor `tenants/<uuid de la empresa>/models/<core>/<versión>/…` (el `.manifest.json` firmado va al lado); sin ella el modelo no puede validarse ni aprobarse |
| `artifact_sha256` | SHA-256 del objeto |
| `model_registry_events` | sus eventos con `actor_kind = cli` y `actor_user_id` |
| `artifact_compat.data_gate.outcome` | resultado de `validate-data` (`PASS`/`WARN`/`DEGRADE`/`BLOCK`) cuando el candidato no tiene corrida asociada |

Cualquier incumplimiento parcial sigue exigiendo aprobación (basta una marca `published_*` o un evento `cli`): `ServingPolicyHardeningTest::test_the_cli_publication_contract_requires_approval_even_when_the_cli_forgets_some_marks`.

### 5.7 API (OpenAPI en `docs/openapi/analytics.yaml`)

| Ruta | Habilidad | Función |
|---|---|---|
| `GET /models` | `forecast.view` | Lista con filtros `core`, `status` (también los alias de la etapa 1), `role`, `run_id` y **`family`**; cada fila trae `serving_basis`, publicación y aprobación (nunca `published_object_key`) |
| `GET /models/{id}` | `forecast.view` | Detalle con `events` y `promotion_decision` |
| `POST /models/{id}/validate` | `forecast.run` (publicado por la CLI) · `models.approve` (modelo del pipeline propio) | candidate → validated tras las compuertas; no vuelve servible al modelo |
| `POST /models/{id}/approve` | `models.approve` | validated → approved (segregación + compuertas) |
| `POST /models/{id}/reject` | `models.approve` | → rejected con motivo obligatorio (segregación) |
| `POST /models/{id}/promote` | `models.approve` | approved → production |
| `POST /models/demand/rollback`, `POST /models/customer/rollback` | `forecast.run` (Customer: `models.approve` para un `approved` que nunca sirvió) | Solo aprobados o legados (§5.6); un publicado se vuelve a verificar contra el almacén; eventos en la misma transacción |

El alias heredado `status=rejected` devuelve los validados no promovidos de la etapa 1 **y** los rechazados reales; `legacy_status` de un rechazado real es `rejected`. Un modelo de otra empresa responde 404 en todas las rutas.

### 5.8 Medición de uso por empresa

`tenant_usage_records` (sin precios; distinta de `tenant_usage`): segundos de entrenamiento e inferencia que ya reportan las ejecuciones del motor y los bytes almacenados. Detalle de métricas, fuentes y límites en
[`MODEL_DEPLOYMENT.md`](MODEL_DEPLOYMENT.md) §6. Exportación LOPDP: `model_registry_events` y `tenant_usage_records` están en `TenantDataExporter` (sin `published_object_key`).

### 5.9 Pruebas que lo demuestran (`backend/tests/Feature/Models/`)

| Comportamiento | Prueba |
|---|---|
| Tabla de transiciones explícita; toda transición prohibida se rechaza sin cambiar nada ni dejar evento; el CHECK de la base admite exactamente esos estados | `ModelLifecycleTest::test_the_transition_table_is_explicit_and_complete`, `…::test_every_forbidden_transition_is_rejected_without_changing_anything`, `…::test_the_database_check_admits_exactly_the_states_of_the_machine` |
| Evento con actor, motivo y evidencia **en la misma transacción** (se deshace junto con el estado) | `ModelLifecycleTest::test_an_allowed_transition_writes_the_event_…`, `…::test_the_status_change_and_its_event_roll_back_together` |
| Un publicado sin aprobar nunca entra en producción; aprobado requiere `approved_at` | `ModelLifecycleTest::test_a_published_candidate_can_never_enter_production_without_approval`, `…::test_approved_always_records_the_approval_timestamp` |
| Política de legados (clasificación, línea base marcada, idempotente) | `ModelLifecycleTest::test_legacy_policy_classifies_pipeline_models_…`, `…::test_a_legacy_model_gets_a_baseline_event_…`; `RegistryMigrationsAndExportTest::test_the_backfill_marks_legacy_pipeline_models_…` |
| Habilidad `models.approve` solo propietario y administrador | `ModelApprovalApiTest::test_models_approve_belongs_to_owner_and_admin_only`, `…::test_validate_needs_forecast_run_…` |
| **Segregación con usuarios reales**: publicador no aprueba ni rechaza; quien disparó el entrenamiento tampoco; un tercero sí; entrenamiento programado sin responsable | `ModelApprovalApiTest::test_the_publisher_cannot_approve_or_reject_their_own_model_but_another_admin_can`, `…::test_whoever_triggered_the_training_cannot_approve_it_either`, `…::test_a_scheduled_training_without_a_human_trigger_…`, `…::test_promote_refuses_an_approval_given_by_someone_responsible_for_the_model` |
| Firma verificada: sin firma (aun con el indicador de legado), alterado, clave desconocida, otra empresa, no visible, sin huella; se repite al aprobar y al promover | `ModelApprovalApiTest::test_approval_requires_a_verified_signature_and_never_accepts_the_unsigned_legacy_flag`, `…::test_the_signature_is_checked_again_…`, `…::test_promote_respects_the_plan_horizons_and_rechecks_the_signature` |
| Objeto publicado en el almacén: firma verificada desde allí; alterado, ausente, clave de otra empresa o con rutas relativas se rechaza; sin copias temporales | `ModelRegistryApiTest::test_the_signature_of_a_published_object_is_verified_from_the_object_store`, `…::test_a_published_object_that_was_altered_or_is_missing_or_belongs_to_another_company_is_not_approved` |
| Gate de datos ≠ BLOCK (BLOCK, sin evidencia, declarado, informe manda sobre lo declarado, informe de otra empresa) | `ModelApprovalApiTest::test_a_blocked_or_missing_data_gate_prevents_approval`, `…::test_the_data_gate_report_of_another_company_is_never_used` |
| Decisión de promoción de W3 completada al aprobar; `reject` de la política no se anula | `ModelApprovalApiTest::test_approval_completes_the_w3_promotion_decision_…` |
| Rechazo con motivo, terminal; retirar una aprobación; producción no se rechaza | `ModelApprovalApiTest::test_reject_requires_a_reason_records_it_and_is_terminal`, `…::test_an_approval_can_be_withdrawn_before_production_…` |
| Un candidato no se sirve; promoción retira solo al del mismo alcance; plan | `ModelApprovalApiTest::test_a_candidate_or_unapproved_model_is_never_promoted_or_served`, `…::test_promote_puts_the_approved_model_in_production_…` |
| Reversión de demanda y de Customer: legados siguen funcionando con eventos; no aprobados se rechazan sin cambiar nada; aprobados se restauran | `RollbackApprovalPolicyTest` (8 pruebas) además de `CustomerModelRollbackTest` y `DemandV2ApiTest` (sin cambios) |
| Aislamiento entre empresas (no ve, no aprueba, no revierte, no mezcla informes) | `ModelApprovalApiTest::test_the_approver_must_be_a_user_of_the_company_and_other_companies_cannot_decide`, `ModelRegistryApiTest::test_the_detail_of_a_model_of_another_company_or_a_missing_one_is_404`, `RollbackApprovalPolicyTest::test_*_is_isolated_between_companies*` |
| Lista (core, estado, familia, alias heredado) y detalle con eventos sin la clave del almacén | `ModelRegistryApiTest` |
| **Validar un legado candidato no lo sirve** (analista → 403; con `models.approve` queda `not_approved`; revertir → 409 `rollback_target_not_approved`); solo aprobar lo sirve | `ServingPolicyHardeningTest::test_an_analyst_cannot_validate_a_legacy_candidate_and_a_validated_one_is_still_not_servable`, `…::test_a_published_candidate_validated_by_an_analyst_is_not_servable_either`, `…::test_a_validation_event_written_by_a_person_makes_even_an_engine_validated_row_not_servable_until_approved` |
| Legado = definición positiva que falla cerrada; clasificación completa; una consulta de eventos por página | `ModelLifecycleTest::test_legacy_is_a_positive_definition_that_fails_closed`, `…::test_annotate_computes_the_basis_of_a_whole_page_with_one_event_query`, `ServingPolicyHardeningTest::test_a_model_without_a_positive_pipeline_origin_is_not_legacy_and_cannot_be_restored`, `…::test_the_listing_reports_the_serving_basis_of_every_row_with_a_constant_number_of_queries` |
| Contrato de publicación de la CLI (incumplimientos parciales siguen exigiendo aprobación; publicado sin clave no se aprueba) | `ServingPolicyHardeningTest::test_the_cli_publication_contract_requires_approval_even_when_the_cli_forgets_some_marks`, `ModelApprovalApiTest::test_a_published_model_without_its_object_key_cannot_be_approved` |
| Retirar una aprobación limpia `approved_*` y conserva la historia | `ServingPolicyHardeningTest::test_rejecting_an_approved_model_clears_the_approval_and_keeps_the_history_in_the_event` |
| Todos los trabajos de entrenamiento enlazados hacen responsable a su usuario | `ServingPolicyHardeningTest::test_every_training_job_linked_to_the_model_or_its_run_makes_its_user_responsible` |
| Restaurar un `approved` que nunca sirvió exige `models.approve` y repite las compuertas; un publicado se reverifica al revertir | `RollbackApprovalPolicyTest::test_customer_rollback_serves_approved_published_models_…`, `…::test_restoring_a_never_served_approved_model_repeats_the_promotion_gates_…`, `…::test_demand_rollback_refuses_a_run_with_unapproved_published_models_…` |
| Promociones sucesivas del mismo alcance dejan una sola producción | `ServingPolicyHardeningTest::test_successive_promotions_of_the_same_scope_always_leave_a_single_production_model` |
| Medición de uso y foto diaria de almacenamiento (el fallo de una empresa no detiene a las demás) | `TenantUsageMeterTest`, `UsageSnapshotCommandTest` (incl. `test_a_failure_in_one_company_does_not_stop_the_others`), `RegistryMigrationsAndExportTest` |

### 5.10 Lo que dependía del motor y la CLI (W4-B) — IMPLEMENTADO / VERIFICADO en el motor

Detalle, comandos, códigos de salida y pruebas en [`PRIVATE_TENANT_TRAINING.md`](PRIVATE_TENANT_TRAINING.md); pasos del propietario en [`docs/operations/TENANT_TRAINING_RUNBOOK.md`](../operations/TENANT_TRAINING_RUNBOOK.md).

| Elemento | Estado | Evidencia (`engine/tests/`) |
|---|---|---|
| La CLI `publish` sube el artefacto firmado al almacén de objetos (clave del servidor `tenants/<uuid>/models/<core>/<versión>/…` + `.manifest.json` al lado) y registra el `candidate` con el contrato completo de §5.6: `published_by`, `published_env`, `published_at`, `published_object_key`, `artifact_sha256`, eventos `actor_kind = cli` con `actor_user_id` y `artifact_compat.data_gate.outcome`; **nunca** `validated`, `approved` ni `production` | **IMPLEMENTADO / VERIFICADO** | `integration/test_mlops_integration.py::test_publish_meets_the_registry_publication_contract`, `…::test_the_status_written_by_publish_can_only_be_candidate`, `…::test_production_publish_with_confirmation_is_still_only_a_candidate`; `integration/test_mlops_demand_integration.py::test_demand_bundle_is_packaged_verified_and_published_as_a_candidate` |
| El backend reconoce lo que escribe la CLI: `ModelApprovalGates` verifica la firma desde el objeto del almacén, toma el gate de datos declarado y la compatibilidad, y `ModelServingPolicy` lo clasifica `not_approved` | **VERIFICADO** ejecutando el backend real sobre un objeto publicado por la CLI (no es una prueba automática: ver `PRIVATE_TENANT_TRAINING.md` §9.3) | — |
| El motor carga solo modelos con base de servicio `approved` o `legacy_pipeline` (espejo de `ModelServingPolicy::basis()`, mismos casos que el backend) y con firma verificada; nunca un `not_approved`, aunque alguien deje su estado en `production`. Un modelo publicado se verifica desde el almacén (clave de la empresa del trabajo) y siempre debe estar firmado | **IMPLEMENTADO / VERIFICADO** | `unit/test_serving_policy_mirror.py` (33 casos del backend; coincidencia con el PHP real comprobada también contra la base), `unit/test_mlops_serving.py`, `integration/test_mlops_integration.py::test_a_published_model_forced_to_production_without_approval_is_not_loaded`, `…::test_a_published_model_approved_by_someone_else_is_served_from_the_object_store` |
| Clave de caché = empresa + core + versión del modelo + versión del esquema de variables (+ SHA-256 del artefacto), acotada por entradas y por bytes; la verificación se repite siempre antes de consultar la caché. Caveat: la clave de confianza se lee del entorno **del proceso**; retirarla en un motor en marcha exige reiniciarlo (la prueba cambia el entorno dentro del propio proceso) | **IMPLEMENTADO / VERIFICADO** | `unit/test_mlops_serving.py::test_the_cache_key_carries_company_version_schema_and_content`, `…::test_a_cache_hit_skips_deserialization_but_verification_is_always_repeated`, `…::test_retiring_a_trusted_key_takes_effect_even_for_cached_artifacts` |
| Pruebas negativas entre empresas en registro, caché y trabajos: un trabajo de A nunca carga artefactos ni cachés de B (ni con una fila de A envenenada con la ruta o la clave de B) | **IMPLEMENTADO / VERIFICADO** | `integration/test_mlops_integration.py::test_registry_queries_return_only_the_jobs_own_company`, `…::test_a_job_of_company_a_never_loads_artifacts_or_caches_of_company_b`, `…::test_a_registry_row_of_a_pointing_at_the_artifacts_of_b_is_refused`, `…::test_an_approved_published_model_of_b_cannot_be_served_to_a_even_if_a_row_claims_it`; `unit/test_mlops_serving.py::test_two_companies_never_share_cache_entries_even_with_the_same_version_and_content` |
| `rollback` de la CLI no escribe `production`: comprueba la versión con la misma política y deja la instrucción del backend (`POST /models/{id}/promote`, `/models/customer/rollback` o `/models/demand/rollback`) | **IMPLEMENTADO / VERIFICADO** | `integration/test_mlops_integration.py::test_rollback_only_prints_the_backend_instruction_and_changes_nothing` |
| La compuerta de datos de un candidato sin corrida asociada: la CLI declara `artifact_compat.data_gate.outcome` a partir del gate de `train` (un BLOCK no llega a empaquetarse) | **IMPLEMENTADO / VERIFICADO** | ídem contrato de publicación |
| Controlador S3 del almacén (boto3) | **IMPLEMENTADO / SOLO EMULADOR** (moto); AWS real **BLOQUEADO** | `unit/test_object_store_s3_emulator.py` |

Pendiente (no cubierto por W4-B):

- El motor sigue **promoviendo automáticamente** los modelos de sus propias corridas (legado, §5.6): no se cambió para no alterar la demo ni los E2E (con la configuración por defecto lo servido es idéntico, verificado). Que cada entrenamiento
  del pipeline se registre como `candidate` y espere aprobación es **DISEÑADO / NO IMPLEMENTADO** ([`AUTOMATED_TRAINING.md`](AUTOMATED_TRAINING.md) §3).
- El motor de **demanda** no carga artefactos al servir (reajusta los estadísticos y reentrena LightGBM en cada corrida): el filtro de serving está implementado y probado para el Customer Core, que sí los carga; lo publicado para demanda es evidencia auditable.
- **No hay un CHECK** que impida a un proceso con escritura directa en la base poner `status = production` en una fila publicada: lo acotan la política (`serving_basis = not_approved` en lista y detalle) y el filtro del motor.
- Re-firmado de artefactos legados para publicarlos: no implementado (se reentrena con la clave de firma; `publish` solo acepta paquetes firmados que creó `train`).
- La identidad del publicador (`published_by`) es una **declaración** del operador de la CLI, comprobada contra la empresa y el rol (`forecast.run`) y acompañada de `cli_operator` en el evento: control organizativo, no criptográfico (`PRIVATE_TENANT_TRAINING.md` §10).

## 6. Gestión del registro — DISEÑADO / NO IMPLEMENTADO (lo que sigue pendiente)

Es el diseño de ADR-V25-07 y de los defectos de `modelos_aws.json` que W4-A **no** implementó:

- **Metadatos completos** (algoritmo, transformadores, dependencias, ventana de entrenamiento, métricas train/backtest/holdout, dueño, justificación) más allá de los campos de W4 (propósito, granularidad, modelo anterior, política aplicada, publicación y
  aprobación); la columna dedicada de firma (hoy: `artifact_compat.signature`).
- **Reversión restringida a roles con motivo obligatorio y MFA**, y reversión solo a candidatos que superaron la regla de promoción (hoy: aprobado o legado, sin MFA ni motivo en la reversión).
- **Invariantes en BD**: una sola producción por alcance (índice único parcial, §5.5) y «publicado ⇒ no producción sin `approved_at`» como CHECK (no se añadieron para no romper datos del motor ni fixtures de otros paquetes; recomendados), coherencia de tenant en el esquema del registro.
  Las FK compuestas `(tenant_id, x_id)` con `ON DELETE SET NULL` que anulaban también `tenant_id` quedaron corregidas por el arquitecto (`2026_03_12_000400`, `ON DELETE SET NULL (x_id)`).
- **Conciliación y retención** registro ↔ almacenamiento; purga de artefactos al purgar una empresa; migración idempotente de filas legadas a ruta relativa.
- **Formatos seguros sin pickle** (texto de LightGBM, parámetros en JSON), deserialización en subproceso sin credenciales de BD y endurecimiento del contenedor del motor.
- **Re-firmado** de artefactos legados, firma por KMS/HSM, revocación explícita de claves y volumen de artefactos montado solo en el servicio que verifica.
- **BLOQUEADO**: verificación contra un almacén S3 real (sin cuenta del propietario; las pruebas usan un disco local/fake de Laravel).
