# Entrenamiento privado por empresa: CLI, publicación y serving solo de lo aprobado

Paquete W4-B · V2.5 §9 · ADR-V25-19 (CLI), ADR-V25-08 (almacén de objetos), ADR-V25-07 (registro: aprobación separada) y ADR-V25-20 (artefactos firmados).
Código: `engine/predictily_engine/cli.py` y `engine/predictily_engine/mlops/` (`commands.py`, `training.py`, `bundle.py`, `exports.py`, `serving.py`, `policy.py`,
`compare.py`, `diagnose.py`, `errors.py`), `engine/predictily_engine/platform/object_store.py` y el filtro de carga de `customer/service.py` (`_load_serving_models`,
`load_artifact`). Pasos exactos para quien opera: [`docs/operations/TENANT_TRAINING_RUNBOOK.md`](../operations/TENANT_TRAINING_RUNBOOK.md). Entrenamiento automático (diseño):
[`AUTOMATED_TRAINING.md`](AUTOMATED_TRAINING.md). Registro, aprobación y firma: [`MODEL_REGISTRY.md`](MODEL_REGISTRY.md) y [`MODEL_DEPLOYMENT.md`](MODEL_DEPLOYMENT.md).

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba que lo demuestra) · **IMPLEMENTADO / SOLO EMULADOR** · **DISEÑADO / NO IMPLEMENTADO** ·
**BLOQUEADO** (no se puede construir o verificar en este entorno) · **NO APLICA**.

## 1. Qué es, y qué garantiza

Una CLI para que una persona autorizada entrene los modelos de UNA empresa en su propia máquina (CPU por defecto), con datos exportados con autorización y minimizados, y los
**publique como candidatos** firmados. Publicar no sirve nada: un modelo solo se sirve cuando otra persona lo aprueba en el backend (ADR-V25-07).

Garantías (cada una con su prueba en §9):

1. **Entrenar no escribe en la base de datos.** `train`, `backtest`, `tune`, `build-features` y `validate-data` solo leen. El único camino al registro es `publish`, y escribe siempre `candidate`.
2. **`publish` nunca deja `production`** (ni `validated` ni `approved`). Cumple el contrato de publicación de `MODEL_REGISTRY.md` §5.6 al pie de la letra (§5).
3. **Toda clave del almacén la genera el servidor** con el prefijo de la empresa (`tenants/<uuid>/models/<core>/<versión>/<archivo>`); nunca se deriva de una ruta del cliente.
4. **Todo artefacto va firmado (Ed25519) y se verifica antes de cargarse**; el motor solo carga modelos `approved` o `legacy_pipeline` (§7), con clave de caché empresa + versión + esquema.
5. **Aislamiento entre empresas** en registro, almacén, caché y trabajos, con pruebas negativas.
6. **Sin secretos en la salida**: `diagnose` imprime identificadores y huellas de claves, nunca material de clave, contraseñas ni tokens.

Qué NO es: no es el entrenamiento automático (§10, DISEÑADO), no promueve ni revierte modelos (eso es del backend, `rollback` solo imprime la instrucción) y no entrena en AWS (BLOQUEADO: sin cuenta del propietario).

## 2. Estado

| Capacidad | Estado | Evidencia |
|---|---|---|
| CLI con ayuda en español y códigos de salida estables (§4) | IMPLEMENTADO / VERIFICADO | `engine/tests/unit/test_mlops_cli.py` |
| `validate-data` (gates W2-C sobre la base o una exportación), `build-features`, `train`, `backtest`, `evaluate` | IMPLEMENTADO / VERIFICADO | `test_mlops_integration.py::test_validate_build_features_backtest_and_train_never_write_to_the_database`, `test_mlops_demand_integration.py::test_backtest_and_validate_data_for_demand` |
| `train` equivale al servicio: misma huella de datos, mismos modelos y holdout (Customer) y **mismo artefacto byte a byte** (demanda) | IMPLEMENTADO / VERIFICADO | `test_cli_training_matches_the_service_for_the_customer_core`, `test_cli_demand_training_matches_the_service` |
| `tune` (Optuna acotado de W3-B; no usa el holdout) | IMPLEMENTADO / VERIFICADO si Optuna está instalado (llega con mlforecast) | `test_tune_is_bounded_and_never_uses_the_holdout`, `test_tune_without_optuna_is_a_configuration_error` |
| `package`, `sign`, `verify` (sobre firmado del paquete, versiones de librerías, huella de datos, gate) | IMPLEMENTADO / VERIFICADO | `test_mlops_bundle.py` (13 pruebas, incluye manipulación) |
| `publish --env` como `candidate` con el contrato §5.6; idempotente; limpia lo que creó si falla | IMPLEMENTADO / VERIFICADO | `test_publish_meets_the_registry_publication_contract`, `test_a_failed_registration_removes_only_the_objects_that_call_created` y el resto de `test_mlops_integration.py` |
| El backend reconoce lo publicado (firma verificada desde el almacén, gate de datos declarado, compatibilidad, `serving_basis = not_approved`) | VERIFICADO manualmente contra `ModelApprovalGates` real (ver §9.3) | ejecución de las compuertas del backend sobre un objeto publicado por la CLI |
| `compare` por horizonte y segmento (versiones del registro o paquetes locales) | IMPLEMENTADO / VERIFICADO | `test_compare_*`, `test_demand_bundle_is_packaged_verified_and_published_as_a_candidate` |
| `rollback`: comprueba y deja la instrucción del backend; nunca escribe | IMPLEMENTADO / VERIFICADO | `test_rollback_only_prints_the_backend_instruction_and_changes_nothing`, `test_demand_rollback_is_by_run_and_never_writes` |
| `diagnose` sin secretos, con reglas estrictas sobre lo que imprime (una URL con «@» sin codificar en la clave no se imprime ni se usa; servidor, base, usuario, bucket, región y raíz solo si parecen legítimos) | IMPLEMENTADO / VERIFICADO | `test_diagnose_never_prints_secrets` (valores centinela), `test_diagnose_never_prints_pieces_of_a_malformed_database_url`, `test_diagnose_prints_other_configuration_fields_only_when_they_look_legitimate` |
| Publicador declarado con rol `forecast.run`; operador de la CLI registrado; republicar a nombre de otro usuario o en otro entorno es conflicto; publicación concurrente serializada | IMPLEMENTADO / VERIFICADO (control organizativo) | `test_role_abilities_mirror.py`, `test_publish_needs_a_role_with_forecast_run_and_records_who_ran_the_cli`, `test_republishing_with_another_user_or_environment_is_a_conflict`, `test_two_processes_publishing_the_same_bundle_are_serialized_and_nothing_of_the_winner_is_deleted` |
| Un fallo del almacén (objeto ilegible, enlace fuera de la raíz, padre que es un archivo) degrada ese modelo y no tumba la corrida; `model_not_approved` en `horizon_support` | IMPLEMENTADO / VERIFICADO | `test_a_failing_object_store_never_stops_a_customer_run`, `test_a_refused_production_model_is_reported_as_model_not_approved_in_the_run`, `test_mlops_serving.py` |
| Exportación local autorizada, minimizada, cifrada (AES-256-GCM), con vencimiento y `cleanup-exports` | IMPLEMENTADO / VERIFICADO | `test_mlops_exports.py` (26 pruebas), `test_authorized_export_trains_locally_without_plaintext_and_is_cleaned_up`, `test_training_from_an_authorized_export_reproduces_the_database_training` |
| `platform/object_store.py`: controlador `local` (misma raíz y convención de claves que Bronze) | IMPLEMENTADO / VERIFICADO | `test_object_store.py` |
| Controlador `s3` (boto3) | IMPLEMENTADO / **SOLO EMULADOR** (moto); AWS real **BLOQUEADO** | `test_object_store_s3_emulator.py` (5 pruebas, ejecutadas en un venv propio con boto3 y moto; se omite con mensaje explícito si no están instalados). Además, un flujo manual de punta a punta contra el emulador: `publish` (16 objetos bajo el prefijo de la empresa, SSE AES256), republicar (idempotente), servir un modelo aprobado desde el almacén y rechazar el objeto alterado (§9.3) |
| Serving: el motor carga solo `approved` / `legacy_pipeline` con firma verificada, espejo de `ModelServingPolicy::basis()` | IMPLEMENTADO / VERIFICADO | `test_serving_policy_mirror.py` (mismos casos que el backend), `test_a_published_model_forced_to_production_without_approval_is_not_loaded` |
| Carga de modelos publicados desde el almacén de objetos, con clave de la empresa | IMPLEMENTADO / VERIFICADO | `test_mlops_serving.py`, `test_a_published_model_approved_by_someone_else_is_served_from_the_object_store` |
| Caché con empresa + versión + esquema de variables + SHA-256; la verificación se repite siempre | IMPLEMENTADO / VERIFICADO | `test_mlops_serving.py` (caché) |
| Pruebas negativas entre empresas: registro, caché y trabajos | IMPLEMENTADO / VERIFICADO | `test_registry_queries_return_only_the_jobs_own_company`, `test_a_job_of_company_a_never_loads_artifacts_or_caches_of_company_b`, `test_a_registry_row_of_a_pointing_at_the_artifacts_of_b_is_refused` |
| Con la configuración por defecto la demo sirve lo mismo que antes (demanda y Customer) | VERIFICADO (§9.2) | corrida antes/después sobre 552f6c0 |
| GPU | **NO APLICA** hoy: CPU por defecto, sin dependencia obligatoria; `diagnose` informa `device: cpu` | — |
| Entrenar y publicar con los modelos del propio pipeline (promoción automática) como `candidate` | **DISEÑADO / NO IMPLEMENTADO** (sigue siendo legado, §7.3 y `AUTOMATED_TRAINING.md`) | — |
| Entrenamiento privado en AWS aislado con presupuesto | **BLOQUEADO** (sin cuenta del propietario) | — |

## 3. Flujo de punta a punta

```
 SERVIDOR (con autorización escrita)         MÁQUINA DE ENTRENAMIENTO (clave privada de firma)                 BACKEND (otra persona)
 ───────────────────────────────────         ────────────────────────────────────────────────                  ─────────────────────
 export-local  ──(cifrado, vence)──►         validate-data → build-features → train [--from-export]
                                             backtest / tune (opcionales)
                                             package --sign → verify
                                             publish --env staging ──► almacén de objetos (tenants/<uuid>/models/…)
                                                         └──────────► model_registry: candidate (published_*) + evento cli
                                                                                                         POST /models/{id}/validate  (forecast.run)
                                                                                                         POST /models/{id}/approve   (models.approve, ≠ publicador)
                                                                                                         POST /models/{id}/promote   (models.approve)
 motor: carga solo approved / legacy_pipeline, verificando firma  ◄──────────────────────────────────────  approved → production
 cleanup-exports (borra lo vencido)                                         rollback: imprime la instrucción; el backend revierte
```

Las tres decisiones del backend las toma una persona distinta de quien publicó (segregación de funciones, `MODEL_REGISTRY.md` §5.3). La CLI recibe `--published-by-user-id`: ese usuario
queda como `published_by` y **no podrá aprobar** lo que publicó.

## 4. Comandos y códigos de salida

Se invocan con `python -m predictily_engine.cli <comando> …` (o `predictily-engine <comando> …` tras `pip install -e engine`). Toda ayuda (`<comando> --help`) está en español, con la tabla de códigos de salida. El
resultado de cada comando es JSON en stdout; los errores salen por stderr como `error: <mensaje>` (sin secretos).

| Código | Nombre | Significado |
|---|---|---|
| 0 | ok | Terminó bien (puede traer avisos en el JSON) |
| 1 | comprobación negativa | La comprobación pedida dio NEGATIVO: firma o huella que no verifican, gate de datos BLOCK, datos insuficientes, destino de reversión no servible, comparación sin cifras comunes, diagnóstico con fallos. No es un error del programa |
| 2 | uso inválido | Argumentos o valores inválidos (también los de argparse, en español). Toda opción numérica se valida contra su rango documentado ANTES de leer la base: `tune --trials` 1–200, `--timeout-seconds` 1–3600, `--max-cpu` 1–16, `--max-windows` 1–6, `--seed` 0–2147483647, `--ttl-hours` 1–720, `--limit` 1–500, ids ≥ 1; un horizonte fuera del catálogo del core es un error (nunca se descarta en silencio) |
| 3 | entorno o configuración | Claves de firma, base de datos, almacén de objetos o biblioteca opcional que faltan o son inválidos; también una variable de entorno mal escrita (`ENGINE_LOG_LEVEL` que no es un nivel de registro, `ENGINE_ARTIFACT_CACHE_ENTRIES` / `_MAX_MB` fuera de rango), con un mensaje en español y sin rastro de pila |
| 4 | sin autorización | Falta una autorización explícita o el actor no corresponde: exportación sin referencia, publicador ajeno a la empresa **o con un rol sin `forecast.run`**, empresa inactiva, exportación vencida, alterada o de otra empresa, `compare` con el paquete de otra empresa, `--env production` sin `--confirm-production` |
| 5 | no encontrado | Empresa, versión, paquete o exportación inexistentes |
| 6 | conflicto | Se negó a sobrescribir: versión ya publicada con otro contenido **o por otro usuario o en otro entorno**, clave del almacén con otro contenido, directorio de salida existente |
| 7 | error interno | Fallo inesperado (el mensaje solo trae el tipo de error) |

| Comando | Uso exacto | Qué hace | Códigos |
|---|---|---|---|
| `diagnose` | `diagnose [--require db,object-store,signing]` | Entorno, claves de confianza (identificador y huella), versiones, almacén y base de datos; sin secretos | 0, 1, 2 |
| `validate-data` | `validate-data --tenant-id N --core demand\|customer\|all [--from-export DIR --key-file F] [--cutoff AAAA-MM-DD] [--as-of AAAA-MM-DD]` | Gates de calidad de datos (W2-C): PASS/WARN/DEGRADE/BLOCK por core, con motivos y regla versionada | 0, 1 (BLOCK o sin ventas), 2, 3, 5 |
| `build-features` | `build-features --tenant-id N --core C [--from-export DIR --key-file F] [--horizons H…] [--out F.json]` | Panel o dataset de cortes: esquema de variables, formas, huella de datos y variables deshabilitadas por el gate | 0, 1, 2, 3, 5 |
| `train` | `train --tenant-id N --core C --out DIR [--from-export DIR --key-file F] [--seed S] [--horizons H…] [--max-windows K] [--cutoff D] [--as-of D] [--verbose]` | Entrena (CPU) sin escribir en la base; deja artefactos firmados, `train.json` y las evaluaciones en `DIR` (nuevo) | 0, 1, 2, 3, 4, 5, 6 |
| `backtest` | `backtest --tenant-id N --core C […mismos…] [--out F.json]` | Mismo protocolo, sin guardar artefactos: selección y métricas por horizonte y segmento | 0, 1, 2, 3, 5 |
| `tune` | `tune --tenant-id N --core demand […] [--trials 20] [--timeout-seconds 120] [--max-cpu 2] [--out DIR]` | Ajuste de LightGBM con Optuna acotado (solo ventanas de selección); con `--out` guarda lo entrenado | 0, 2, 3 (Optuna ausente), 5 |
| `evaluate` | `evaluate --tenant-id N [--training-job-id J] [--lookback-runs 10]` | **Heredado**: compara pronósticos pasados con ventas reales (alimenta `model_evaluations`) | 0 |
| `package` | `package --dir DIR [--sign] [--force]` | Verifica que todo artefacto esté firmado y crea `package.manifest.json` firmado (huellas, versiones de librerías, huella de datos, métricas, gate) | 0, 1, 2, 3, 6 |
| `sign` | `sign --dir DIR [--force]` | Firma los artefactos del borrador con `ENGINE_SIGNING_PRIVATE_KEY` (sin deserializarlos; exige que sigan siendo los entrenados) | 0, 3, 5, 6 |
| `verify` | `verify --dir DIR [--tenant-id N] [--require-package]` | Verifica firma Ed25519, clave de confianza, SHA-256, tamaño, empresa y core del paquete y de cada artefacto, sin deserializar | 0, 1, 5 |
| `publish` | `publish --dir DIR --env local\|staging\|production --published-by-user-id U [--tenant-id N] [--dry-run] [--confirm-production]` | Sube el paquete verificado al almacén y registra cada modelo como `candidate` | 0, 1, 3, 4, 5, 6 |
| `compare` | `compare --tenant-id N --core C --a V1\|bundle:DIR --b V2\|bundle:DIR [--out F.json]` | Cifras por horizonte y segmento en común, deltas y veredicto descriptivo por métrica | 0, 1 (no comparables), 5 |
| `rollback` | `rollback --tenant-id N --core C --to-version V` | **No cambia nada**: comprueba si la versión es servible y deja la instrucción exacta del backend | 0, 1 (no servible), 5 |
| `list-models` | `list-models --tenant-id N [--core C] [--limit 50]` | Versiones del registro con su `serving_basis` y su publicación | 0 |
| `export-local` | `export-local --tenant-id N --core C --authorized-export REF --authorized-by QUIEN --purpose TEXTO --out DIR --key-file F [--generate-key] [--ttl-hours 72]` | Exportación autorizada, minimizada y cifrada (§8) | 0, 2, 3, 4, 5 |
| `cleanup-exports` | `cleanup-exports --root DIR [--all] [--dry-run]` | Borra las exportaciones vencidas (o todas); solo toca carpetas con la marca de exportación | 0, 2, 5 |
| `keygen`, `verify-artifact` | (heredados, `MODEL_REGISTRY.md` §2.3) | Generar el par Ed25519 y verificar un artefacto del registro | 0, 1, 2 |

`--tenant` es sinónimo de `--tenant-id`. Variables de entorno relevantes: `ENGINE_DATABASE_URL`, `ENGINE_SIGNING_KEY_ID`, `ENGINE_SIGNING_PRIVATE_KEY` (solo donde se firma), `ENGINE_TRUSTED_PUBLIC_KEYS`,
`ENGINE_ARTIFACTS_DIR`, `ENGINE_N_JOBS`, `ENGINE_RANDOM_SEED`, y las del almacén: `ENGINE_OBJECT_STORE_DRIVER` (`local` | `s3`), `ENGINE_OBJECT_STORE_ROOT` (local: la raíz del disco `local` del
backend, `backend/storage/app/private`; si no se define dentro del monorepo se usa esa y `diagnose` lo avisa), `ENGINE_OBJECT_STORE_BUCKET`, `_REGION`, `_ENDPOINT` (emuladores), `_PATH_STYLE`,
`_SSE` (`AES256` | `aws:kms`), `_KMS_KEY_ID`. Las credenciales de S3 **no** se leen de estas variables: las resuelve la cadena estándar de boto3 (rol de tarea, perfil o entorno).

## 5. Paquete y contrato de publicación

Directorio de trabajo (`--out` de `train`, `--dir` del resto): `train.json` (borrador, no firmado), `artifacts/<nombre>.joblib` + `<nombre>.joblib.manifest.json` (manifiesto firmado del artefacto, el que verifica el
serving), `report/evaluations.json` (demanda) y `package.manifest.json` (sobre firmado `predictily-signed-package/1`). `publish` lee **solo** el paquete firmado y exige que cada artefacto verifique.

Contrato de `MODEL_REGISTRY.md` §5.6 y cómo lo cumple `publish`:

| Campo | Valor que escribe `publish` | Prueba |
|---|---|---|
| `status` | `candidate` (constante `PUBLISH_STATUS`; no es un parámetro) | `test_the_status_written_by_publish_can_only_be_candidate`, `test_production_publish_with_confirmation_is_still_only_a_candidate` |
| `published_by` | el `--published-by-user-id` (declarado por el operador; debe ser un usuario de la empresa, no eliminado y con un rol con `forecast.run`; si no, salida 4. Control organizativo, no criptográfico: ver §10) | `test_publish_refuses_actors_environments_and_packages_it_must_not_trust` |
| `published_env` / `published_at` | `local`, `staging` o `production` / momento de la publicación | `test_publish_meets_the_registry_publication_contract` |
| `published_object_key` | `tenants/<uuid de la empresa>/models/<core>/<versión>/<archivo>`; el `.manifest.json` firmado va al lado | ídem, `test_server_generated_keys_never_leave_the_companys_prefix`, `test_object_store.py` |
| `artifact_sha256` | SHA-256 del objeto (se relee del almacén y se compara) | ídem |
| `model_registry_events` | un evento `(NULL → candidate)` con `actor_kind = cli`, `actor_user_id`, motivo y evidencia (`env`, `bundle_id`, `sha256_prefix`, `key_id`, `data_gate`, **`cli_operator`** = usuario del sistema operativo y equipo, **`declared_publisher_role`**) | ídem, `test_publish_needs_a_role_with_forecast_run_and_records_who_ran_the_cli` |
| `artifact_compat.data_gate.outcome` | resultado del gate de datos de `train` (PASS/WARN/DEGRADE; un BLOCK no llega a empaquetarse); además `signature`, `feature_schema_version`, `libraries` y `evaluation_summary` | ídem |
| Sin corrida enlazada | `forecast_run_id`, `core_run_id` y `training_job_id` quedan nulos (un publicado jamás es legado) | ídem |

Versión generada por el servidor: `t<empresa>-pub-<core>-<id del paquete>-<nombre del artefacto>` (única por empresa; el mismo paquete publicado dos veces es idempotente: segunda salida `already_published`; otra
fila con ese nombre y otro contenido es un conflicto, salida 6). Si el registro falla, `publish` borra del almacén solo lo que creó esa llamada.

## 6. Qué entrena cada core

| Core | Artefacto serializable | Qué se publica | Nota |
|---|---|---|---|
| Customer | por horizonte y objetivo (`h30_probability_logistic.joblib`…): el servido, el retador y la referencia | una fila `candidate` por artefacto con su `slot` (`h30:probability`) | El motor SÍ carga estos artefactos al predecir (`_load_serving_models`) |
| Demanda | solo `lightgbm_global` (mlforecast); los estadísticos se reajustan en cada corrida | una fila `candidate` `lightgbm_global` | El motor de demanda **no carga artefactos** hoy (`MODEL_REGISTRY.md` §4: NO APLICA); lo publicado es evidencia auditable y candidato para cuando se sirva desde el almacén. La reversión de demanda es por corrida |

Un entrenamiento de demanda sin LightGBM (pocas series o historia corta) no produce artefacto: `package` responde «no produjo artefactos» (salida 2).

## 7. Serving del motor: solo lo aprobado

### 7.1 El filtro es el espejo de la política del backend

`mlops/policy.py::classify` reproduce en el mismo orden `ModelServingPolicy::basis()` (aprobado / legado del pipeline / no aprobado; `MODEL_REGISTRY.md` §5.6). El motor carga solo `approved` y `legacy_pipeline`;
**jamás** un `not_approved` (publicado sin aprobar, candidato, validado por una persona, sin origen, rechazado o fallido), aunque alguien deje su estado en `production`. Las pruebas ejecutan los **mismos casos** que el backend
(`engine/tests/mlops_policy_cases.py`, con la referencia a cada aserción de `ModelLifecycleTest::test_legacy_is_a_positive_definition_that_fails_closed` y de `ServingPolicyHardeningTest`), más las filas leídas con pandas (NaN/NaT = NULL).

Un modelo `production` no aprobado se trata como un artefacto que falló: se sirve la referencia validada del mismo horizonte (legado, rol `fallback`) o, si no hay, el horizonte se abstiene con `reason_code = model_not_approved`. En `summary.horizon_support` esos horizontes llevan el motivo `model_not_approved` («el modelo en producción … no está aprobado y no se cargó: se sirve la referencia validada de respaldo»), no el genérico «no superó la regla de promoción»; la etiqueta está en `frontend/src/locales/es.json` y el código en la descripción de `/customer/predictions` de OpenAPI.

### 7.2 Carga verificada y caché

- Modelo del pipeline propio: se verifica en `ENGINE_ARTIFACTS_DIR/<empresa>/…` (sin cambios). Modelo **publicado** (`published_object_key`): la clave debe ser `tenants/<uuid de la empresa del trabajo>/models/…` (mismas reglas que
  el backend; si no, se rechaza **sin consultar el almacén**), el objeto y su manifiesto se descargan a un directorio temporal privado (0700), se verifican (firma, SHA-256 del registro, tamaño, empresa y core) y se borran. Un publicado
  siempre debe estar firmado: `ENGINE_ALLOW_UNSIGNED_ARTIFACTS` no se aplica a lo publicado.
- Caché (`ARTIFACT_CACHE`): clave = (empresa, core, versión del modelo, versión del esquema de variables, SHA-256 del artefacto). La verificación se repite **siempre antes** de consultar la caché: un artefacto alterado se nota en la
  siguiente carga; la caché solo evita volver a deserializar. Acotada **por entradas y por bytes** (`ENGINE_ARTIFACT_CACHE_ENTRIES`, 32 por defecto, 0 la desactiva; `ENGINE_ARTIFACT_CACHE_MAX_MB`, 256 por defecto, medido sobre el tamaño serializado,
  un tope conservador porque el objeto deserializado ocupa más); ambas variables se validan al arrancar (CLI: salida 3; API: falla al iniciar). `invalidate_tenant` libera la memoria de una empresa cuando sus modelos cambian
  (el motor lo llama al terminar un entrenamiento del Customer Core); no es necesario para la corrección, porque la verificación no depende de la caché.
- **Alcance de la revocación de claves**: la verificación lee `ENGINE_TRUSTED_PUBLIC_KEYS` del entorno **del proceso** en cada carga. Retirar una clave en un motor EN MARCHA no surte efecto hasta reiniciarlo o redesplegarlo (el entorno de un proceso no cambia
  desde fuera); la prueba que lo demuestra cambia el entorno dentro del propio proceso. En producción, revocar = quitar la clave de la configuración y redesplegar el motor (y el backend).
- **Fallos del almacén**: un objeto ilegible, un directorio que es un enlace fuera de la raíz, un padre que es un archivo o cualquier error del disco o de S3 degradan **ese modelo** con un motivo estable (`artifact_store_unavailable`, `artifact_object_key_invalid`, `artifact_missing`):
  se sirve la referencia validada o el horizonte se abstiene, y la corrida completa termina. Ningún mensaje ni limitación lleva rutas del servidor (solo se registra el tipo del error). El cliente S3 es uno por proceso y por configuración (`get_store`).

### 7.3 Lo que sigue siendo legado

El pipeline propio sigue promoviendo automáticamente los modelos de sus propias corridas (legado, `MODEL_REGISTRY.md` §5.6): la demo, el rollback y los E2E dependen de ello y esta tarea no lo cambia. Que cada entrenamiento del
pipeline se registre como `candidate` y espere aprobación es **DISEÑADO / NO IMPLEMENTADO** (`AUTOMATED_TRAINING.md`).

## 8. Exportación local autorizada

`export-local` produce una carpeta `export-<id>` con tres archivos (`data.enc`, `export.manifest.json`, `.predictily-export`; el motor nunca escribe el conjunto de datos en claro):

- **Autorización**: sin `--authorized-export <referencia>`, `--authorized-by` y `--purpose` no se toca la base (salida 4). La referencia y quién autorizó quedan en el manifiesto. El motor **no puede validar** la autorización contra un
  sistema externo: es un registro obligatorio; el control real es organizacional.
- **Minimización por lista blanca** (`mlops/exports.py::ALLOWED_COLUMNS`): solo las columnas que el modelado usa; lo demás se descarta y queda reportado en `dropped_columns`. Sucursales, productos, categorías y proveedores salen con
  seudónimos (`B12`, `P340`…); el nombre y la fuente (texto libre) de los eventos del calendario se sustituyen por su código y por `export`. Una prueba compara la lista blanca con las columnas que devuelven los cargadores reales (con eventos, cierres y huecos declarados solo se descarta el `id` de los eventos). Los compradores se seudonimizan con HMAC-SHA256 con una clave aleatoria **por exportación que se descarta**: irreversible para quien recibe la exportación (los artefactos no contienen identificadores de compradores). El mapa es **uno solo para toda la exportación** (la unión de los compradores de las compras y de las devoluciones): un mapa por tabla rompería la relación devolución ↔ compra (revisión W4-B · H1).
  Nombres, correos, documentos de identidad y texto libre no salen.
- **Cifrado en reposo y manifiesto autenticado**: AES-256-GCM por fragmentos. El encabezado, el identificador y **todo el manifiesto salvo el bloque `encryption`** (empresa, core, vencimiento, autorización, propósito, tablas) son datos autenticados: editar el manifiesto —p. ej. cambiar la empresa o alargar el vencimiento— hace que no descifre (salida 4); un fragmento cambiado, reordenado o truncado, otra clave u otra exportación tampoco descifran. Tras descifrar se compara además la empresa del contenido con `--tenant-id`. La clave es un archivo local
  0600 (`--key-file`; `--generate-key` lo crea); el comando se niega a usar una clave legible por otros.
- **Vencimiento y limpieza**: vence a las `--ttl-hours` (72 por defecto, máximo 720); `train --from-export` rechaza una vencida. `cleanup-exports` borra las vencidas (o todas) sobrescribiendo con ceros antes de borrar (mejor esfuerzo: en SSD o
  sistemas con copia al escribir no hay garantía física; la defensa real es el cifrado) y solo toca carpetas hijas directas con la marca y un manifiesto válido, nunca enlaces ni carpetas ajenas.
- **Fidelidad**: lo entrenado desde una exportación no tiene el historial de versiones con tiempo de conocimiento (ADR-V25-23): asume cada documento conocido desde su día de negocio (`asof_fidelity = approximate_no_versions`). Lo que está PROBADO:
  - **Demanda**: con datos sin documentos tardíos, la huella de datos, los ganadores, las métricas y el artefacto (byte a byte) resultaron idénticos a los de entrenar desde la base (`test_training_from_an_authorized_export_reproduces_the_database_training`). Con documentos tardíos o anulaciones posteriores pueden diferir.
  - **Customer Core**: las devoluciones conservan su relación con las compras tal como en la base (misma proporción de devoluciones que coinciden con una compra) y lo entrenado desde la exportación tiene los mismos horizontes, familias, roles, compuertas de promoción y cifras del holdout que lo entrenado desde la base, hasta 1e-9
    (`test_customer_training_from_an_export_matches_training_from_the_database_up_to_relabelling`). **No** es idéntico en todo: los compradores cambian de etiqueta, así que la huella de datos difiere; y los resultados dependen de que el orden de las filas no altere el entrenamiento (en el mundo de prueba no lo altera).
- Alcance: requiere `pyarrow` (extra `mlops`; ya llega transitivamente). El Customer Core exporta compras seudonimizadas; los gates que dependen de la base (informes de ingestión, alias) quedan «no evaluados» y así consta.

## 9. Pruebas que lo demuestran

Comandos (desde un directorio sin `.env`, con el esquema migrado a HEAD: `cd backend && DB_DATABASE=predictily_engine_test_w4b php artisan migrate --force`):

```
cd <directorio sin .env> && ENGINE_TEST_DATABASE_URL=postgresql+psycopg://predictily:<clave local>@127.0.0.1:5432/predictily_engine_test_w4b PYTHONPATH=engine \
  engine/.venv/bin/python -m pytest -q --rootdir=engine -c engine/pyproject.toml engine/tests
```

### 9.1 Archivos de prueba nuevos

| Archivo | Cubre |
|---|---|
| `tests/unit/test_object_store.py` | convención de claves compartida con el backend, controlador local, escritura única, enlaces simbólicos, configuración sin secretos |
| `tests/unit/test_object_store_s3_emulator.py` | controlador S3 contra un **EMULADOR** (moto). Se ejecuta en un venv propio; se omite con mensaje explícito si faltan boto3/moto |
| `tests/unit/test_serving_policy_mirror.py` (+ `tests/mlops_policy_cases.py`) | espejo de `ModelServingPolicy::basis()` con los casos del backend |
| `tests/unit/test_role_abilities_mirror.py` | el mapa rol → habilidad (`forecast.run`, `models.approve`) de la CLI se compara con el código PHP de `TenantRole` (se omite si el backend no está junto al motor) |
| `tests/unit/test_mlops_serving.py` | carga de publicados, claves ajenas, objetos alterados/sin firma/clave no confiable, caché y aislamiento entre empresas |
| `tests/unit/test_mlops_bundle.py`, `test_mlops_cli.py`, `test_mlops_exports.py` | paquete/firma/verificación y manipulación, ayuda en español y códigos de salida, `diagnose` sin secretos, exportación |
| `tests/integration/test_mlops_integration.py` | contrato de publicación, filtro de serving con la base, aislamiento (registro/caché/trabajos), paridad, comparación, reversión, solo-lectura, exportación → entrenamiento → limpieza |
| `tests/integration/test_mlops_demand_integration.py` | demanda: paridad con el servicio, entrenamiento desde exportación, publicación, `backtest`, `tune` |
| `tests/tools/demo_parity.py`, `tests/tools/compare_demo_parity.py` | herramientas (no son pruebas) de la verificación antes/después de §9.2 |

### 9.2 Con la configuración por defecto, lo servido no cambia

Verificación de extremo a extremo con las herramientas versionadas `engine/tests/tools/demo_parity.py` y `compare_demo_parity.py` (uso en la cabecera de cada archivo; reproducible con `git archive 552f6c0 engine`; vacía una base de PRUEBAS, no la de desarrollo). Se repitió con el código final de este paquete: la demo FICTICIA —demanda de una ferretería de 12 productos y un mundo Customer de 140 compradores
(entrenar demanda, entrenar Customer y predecir con los 5 horizontes)— se ejecutó sobre el árbol de `552f6c0` y sobre el árbol modificado, con la misma base vacía, semilla y fechas. Resultado: **idéntico** en las nueve comparaciones.

| Tabla / salida | Filas | Resultado |
|---|---|---|
| `forecast_values` | 5 952 | idéntico (exacto, columna por columna) |
| `customer_predictions` | 1 690 | idéntico |
| `customer_product_rankings` | 1 690 | idéntico |
| `customer_activity` | 139 | idéntico |
| `customer_reconciliations` | 40 | idéntico |
| `model_registry` (versión, estado, rol, SHA-256 del artefacto, huella de datos, hiperparámetros) | 26 | idéntico |
| `recommendations` | 36 | idéntico |
| Respuesta de la corrida de predicción (modelos servidos, limitaciones, calidad) | — | idéntica (solo varía `duration_ms`) |

Además: las suites de integración existentes del Customer Core y de demanda (`test_customer_integration.py`, `test_customer_s05_integration.py`, `test_demand_v2_integration.py`…) siguen en verde sin editarse (ver el informe de entrega para los conteos).
Los E2E con la pila completa (`e2e/mvp_acceptance.py`, `e2e/stage2_acceptance.py`) **no se ejecutaron** (requieren backend, cola y motor levantados): la equivalencia se apoya en que el filtro admite `legacy_pipeline` y en la corrida anterior.

### 9.3 Verificación cruzada con el backend

- **Lectura del objeto publicado**: un objeto publicado por la CLI (`train` → `package --sign` → `publish`) se evaluó con `ModelApprovalGates` y `ModelServingPolicy` reales del backend, con el disco `local` apuntando a la raíz del motor
  (`LARAVEL_STORAGE_PATH`): la firma se verificó **desde el almacén** (`source = object_store`, `key_id` correcto), el gate de datos salió «declarado por quien publica» y la compatibilidad «compatible» (`slot` y esquema de variables), y `basis = not_approved`, `published = yes`.
- **Almacén S3 (emulador)**: con `ENGINE_OBJECT_STORE_DRIVER=s3` apuntando a un S3 en memoria (moto), el flujo `train` → `package --sign` → `publish` → (aprobación simulada como la del backend) → `_load_serving_models` publicó 16 objetos (8 modelos y sus manifiestos) bajo `tenants/<uuid>/models/`, republicar fue idempotente, el motor sirvió desde allí el modelo aprobado verificando firma y empresa, y un objeto alterado en el almacén dejó de servirse (`artifact_sha256_mismatch`). Es un emulador, no AWS.
- **Política**: los 33 casos de la política (los de `ModelLifecycleTest` y `ServingPolicyHardeningTest`) se insertaron en la base y se clasificaron con el PHP real del backend y con el espejo de Python: **33 de 33 coinciden** (y con lo esperado por las pruebas del backend).

## 10. Límites y pendientes (honestos)

- **S3 real**: verificado solo contra un emulador; AWS real BLOQUEADO. El backend necesita su disco `models_s3` (`AWS_DEPLOYMENT.md` paso 5) y el motor las variables `ENGINE_OBJECT_STORE_*` (aquí definidas) en las tareas.
- **Publicación concurrente**: `publish` toma un bloqueo consultivo de PostgreSQL por (empresa, paquete) y hace subida y registro en una sola transacción: un segundo proceso con el MISMO paquete espera, ve las filas del primero, responde `already_published` y no borra ningún objeto suyo (probado con dos hilos de forma determinista, `test_two_processes_publishing_the_same_bundle_are_serialized_and_nothing_of_the_winner_is_deleted`); una carrera perdida en el `INSERT` se resuelve contra la fila existente o es un conflicto (exit 6). No se probó con dos procesos del sistema operativo ni contra un S3 real (un `put` repetido es idempotente por contenido, no atómico entre procesos).
- **Reentrenamiento programado con aprobación**, disparadores y presupuestos: `AUTOMATED_TRAINING.md` (DISEÑADO).
- **KMS/HSM para la clave de firma** y separar el despliegue que entrena del que sirve: DISEÑADO (`MODEL_REGISTRY.md` §3, §6). Hoy la clave privada es un archivo/secreto del entorno de entrenamiento.
- **Re-firmado de artefactos legados** para publicarlos: no implementado (se reentrena con la clave de firma; ADR-V25-20 exige re-firmar un legado antes de publicarlo a otro entorno y `publish` rechaza lo que no verifica como firmado).
- **Quién publica es una declaración**: `--published-by-user-id` lo escribe el operador; el motor comprueba que el usuario sea de la empresa, no esté eliminado y tenga un rol con `forecast.run` (mapa espejo de `TenantRole`, anclado al código PHP por `test_role_abilities_mirror.py`), y deja `cli_operator` (usuario del sistema operativo y equipo) y el rol declarado en la evidencia del evento. La segregación de funciones de la aprobación descansa, por tanto, en un control **organizativo, no criptográfico**: quien tenga acceso a la CLI y a la base puede declarar a otro usuario. Una identidad verificada (inicio de sesión, token del backend) está DISEÑADA, no implementada.
- **GPU**: no aplica hoy.
- El backend no puede impedir una escritura directa de `status = production` en una fila publicada (no hay CHECK, `MODEL_REGISTRY.md` §5.10): lo acotan la política (`serving_basis`) y este filtro del motor.
