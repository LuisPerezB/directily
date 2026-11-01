# Despliegue de modelos: promoción, reversión y qué se publica dónde

Fecha: 1-nov-2026 · V2.5 · W4-A (backend) · Fuentes: ADR-V25-07 (registro: aprobación separada y serving solo de lo aprobado), ADR-V25-08 (almacén de objetos con prefijo de empresa), ADR-V25-19 (CLI), ADR-V25-20 (artefactos firmados).

Este documento describe **cómo un modelo llega a producción y cómo se revierte**, **dónde queda cada pieza** y **cómo se mide el uso por empresa**. El ciclo de vida, la segregación de funciones y las compuertas están en
[`MODEL_REGISTRY.md`](MODEL_REGISTRY.md) §5; la firma, en §2–§3 de ese documento. La CLI de entrenamiento privado y el serving del motor (W4-B) y la infraestructura AWS (W4-C) tienen sus propios documentos.

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** (no se puede construir o verificar en este entorno) · **NO APLICA**.

## 1. Flujo de un modelo publicado (candidato → producción)

| Paso | Quién | Qué ocurre | Estado |
|---|---|---|---|
| 1. Publicar | Quien entrena (CLI de entrenamiento privado, W4-B) | Sube el artefacto firmado y su manifiesto al almacén de objetos con una clave que genera el servidor (`tenants/<uuid de la empresa>/models/<core>/<versión>/…`) y registra el modelo como `candidate` con `published_by`, `published_env`, `published_at` y `published_object_key`. **Nunca** `production` | **DISEÑADO / NO IMPLEMENTADO** en la CLI (W4-B); el backend ya trata cualquier modelo con marcas `published_*` como no aprobado |
| 2. Validar | Analista o superior (`forecast.run`) para un candidato publicado; `models.approve` para un modelo del pipeline propio | `POST /api/v1/models/{id}/validate`: compuertas automáticas (firma Ed25519 verificada, gate de datos ≠ BLOCK, política no rechazada, compatibilidad). `candidate → validated`. **No vuelve servible al modelo**: solo la aprobación separada lo hace | **IMPLEMENTADO / VERIFICADO** (`ModelApprovalApiTest`) |
| 3. Aprobar | Propietario o administrador (`models.approve`) **distinto** de quien publicó o entrenó | `POST /api/v1/models/{id}/approve`. Vuelve a verificar firma y gate de datos; completa la decisión de promoción de W3. `validated → approved` | **IMPLEMENTADO / VERIFICADO** |
| 3b. Rechazar | Igual que aprobar | `POST /api/v1/models/{id}/reject` con motivo obligatorio. Terminal | **IMPLEMENTADO / VERIFICADO** |
| 4. Promover | Propietario o administrador (`models.approve`) | `POST /api/v1/models/{id}/promote`. Vuelve a verificar; retira al modelo que servía en el mismo alcance. `approved → production` | **IMPLEMENTADO / VERIFICADO** |
| 5. Servir | Motor (W4-B) | Carga solo `approved`/`production` con firma verificada; clave de caché con empresa + versión + esquema de variables | **DISEÑADO / NO IMPLEMENTADO** en este paquete (W4-B) |

Alcance de una promoción (qué reemplaza): Customer Core = el mismo slot (`h30:probability`, `h30:quantity`…); los demás cores = la misma familia, clase de demanda y rol. Lo retirado queda `retired` con `retired_at` y un evento
`production → retired` (`evidence.replaced_by`).
Promociones y reversiones bloquean la fila de la empresa y las de los modelos del alcance en una transacción: dos promociones del mismo alcance se serializan y queda una sola producción (garantía de la aplicación; el índice único parcial en la base es una recomendación **DISEÑADO / NO IMPLEMENTADO**, ver MODEL_REGISTRY §5.5).

## 2. Qué se publica dónde

| Pieza | Dónde | Quién la escribe | Notas |
|---|---|---|---|
| Artefacto de un modelo del pipeline propio | `ENGINE_ARTIFACTS_DIR/<empresa>/…` (volumen del motor); `model_registry.artifact_path` relativo | Motor | Firmado con Ed25519 al guardar (MODEL_REGISTRY §2). El backend lo ve si monta el mismo volumen |
| Artefacto publicado por la CLI | Almacén de objetos (`config/mlops.php` → `published_disk`; local en desarrollo, S3 en AWS), clave `tenants/<uuid>/models/<core>/<versión>/…` y su `.manifest.json` al lado | CLI (W4-B) | La clave es del servidor; el backend rechaza una clave que no empiece por el prefijo de la empresa activa, con `..` o `//` |
| Metadatos, estado y aprobación | PostgreSQL `model_registry` | Motor / CLI / backend | `published_object_key` es interna: no se expone en la API ni en la exportación LOPDP |
| Historial de transiciones | `model_registry_events` (solo inserción) | Backend (`ModelLifecycle`) | Mismo commit que el cambio de estado |
| Decisión de la política de promoción | `model_promotion_decisions` | Motor (W3); el backend completa `approved_by`/`approved_at` | |
| Bitácora de auditoría | `audit_logs` (`models.validated`, `models.approved`, `models.rejected`, `models.promoted`, `models.demand_rolled_back`, `models.customer_rolled_back`) | Backend | |
| Consumo medido | `tenant_usage_records` | Backend (jobs y comando diario) | §6 |
| Claves de confianza | `ENGINE_TRUSTED_PUBLIC_KEYS` (motor y backend); la privada solo donde se entrena | Operador | MODEL_REGISTRY §2.2 |

## 3. Reversión (rollback)

`POST /api/v1/models/demand/rollback` (re-apunta la ejecución vigente a una anterior) y `POST /api/v1/models/customer/rollback` (re-sirve una versión del mismo slot). Habilidad `forecast.run`. Reglas añadidas por W4-A, además de las de la etapa 2:

- Solo se restauran modelos **aprobados** (por una persona distinta de quien los publicó o entrenó; incluye los `production` que luego se retiraron) o **legados del pipeline propio** (§4). Lo demás responde 409
  `rollback_target_not_approved` (con `context.model_ids`/`basis`) **sin cambiar nada**. Candidatos, rechazados y fallidos ni siquiera son restaurables (`rollback_target_not_restorable`).
- El Customer Core también acepta restaurar un `approved` que aún no estuvo en producción. Equivale a promoverlo, así que (decisión documentada) exige además `models.approve` (403 `forbidden` con solo `forecast.run`) y repite las compuertas de la promoción (firma, gate de datos, política y compatibilidad). Un modelo publicado que se restaura (aprobado y ya retirado) vuelve a verificarse contra el objeto del almacén.
- Cada retiro y cada restauración escribe su evento (`evidence.trigger = demand_rollback | customer_rollback`, corridas o slot, resultado de la verificación de artefactos) en la misma transacción.
- Se conservan todos los códigos de error y la verificación de artefactos de la etapa 2 (SHA-256 y firma si el archivo es visible; informe explícito si no lo es).

Estado: **IMPLEMENTADO / VERIFICADO** (`RollbackApprovalPolicyTest`, `CustomerModelRollbackTest`, `DemandV2ApiTest`). No implementado (DISEÑADO): reversión restringida con motivo obligatorio y MFA (MODEL_REGISTRY §6).

## 4. Política explícita para los modelos legados

Un **legado** es un modelo del pipeline propio de la plataforma (lo entrenó y promovió el motor en una corrida de la empresa) que nunca pasó por la publicación. La definición es **positiva** y falla cerrada: exige estado `production`, `validated` o `retired`, **origen
positivo** (una corrida enlazada —`forecast_run_id`, `core_run_id` o `training_job_id`— o el evento de línea base con la marca de legado), ningún `approved_*` y ninguna señal de publicación (ni marca `published_*` ni evento de la CLI), y que **ninguna persona lo haya validado**
(evento `candidate → validated` de un usuario). Se sigue sirviendo y revirtiendo **como hasta ahora**, porque la demo, el rollback y los E2E (`e2e/mvp_acceptance.py`, `e2e/stage2_acceptance.py`, que revierten demanda y Customer) dependen de ello.

- Se identifica dinámicamente (`ModelServingPolicy::basis() = legacy_pipeline`), de modo que también cubre los modelos que el motor registre después del despliegue (que conservan su corrida enlazada).
- Los retadores que el motor deja como `candidate` **no** son legado servible: aunque una persona con `models.approve` los valide, siguen `not_approved` hasta su aprobación separada (otra persona ≠ quien los entrenó).
- La migración `2026_03_12_000300` fija la clasificación en un evento de línea base («legado aprobado por migración», `evidence.legacy_policy = legacy_approved_by_migration`) solo para lo que el motor dejó en `production`/`validated`/`retired` antes de W4 (idempotente, reversible); cubre también los modelos cuya corrida se purgó. Un `candidate` nunca recibe la marca.
- Un modelo con cualquier marca `published_*` o con un evento de la CLI jamás es legado. Que un legado no pueda publicarse a otro entorno sin aprobación ni re-firmado es regla de ADR-V25-20 que aplicará la CLI de W4-B (**DISEÑADO / NO IMPLEMENTADO** aquí). El contrato de publicación que debe cumplir la CLI está en [`MODEL_REGISTRY.md`](MODEL_REGISTRY.md) §5.6.
- La lista y el detalle exponen `serving_basis` (`approved` | `legacy_pipeline` | `not_approved`) para que la interfaz y el operador vean qué se sirve y por qué.
- Cuando el motor (W4-B) registre cada entrenamiento como `candidate` y exija aprobación, la categoría «legado» dejará de crecer; los existentes podrán aprobarse explícitamente (`validate` → `approve`) si se quiere salir de la excepción.

Estado: **IMPLEMENTADO / VERIFICADO** (`ModelLifecycleTest::test_legacy_is_a_positive_definition_that_fails_closed`, `ServingPolicyHardeningTest`, `RegistryMigrationsAndExportTest::test_the_backfill_*`, las pruebas de reversión). La suite E2E con motor real no se ejecutó en este paquete (requiere la pila completa).

## 5. Configuración

| Variable / archivo | Efecto |
|---|---|
| `ENGINE_ARTIFACTS_DIR` | Volumen de artefactos del motor que el backend ve para verificar firma al aprobar, promover y revertir, y para medir bytes |
| `ENGINE_TRUSTED_PUBLIC_KEYS` | Claves públicas de confianza (`kid:clave`); sin ellas ningún artefacto se aprueba |
| `ENGINE_ALLOW_UNSIGNED_ARTIFACTS` | Solo para revertir legados locales; **no** permite aprobar ni promover un artefacto sin firma |
| `PREDICTILY_MODELS_DISK` (`config/mlops.php`) | Disco donde se leen los objetos publicados para verificar su firma; por defecto el de Bronze (`PREDICTILY_BRONZE_DISK`). Debe apuntar al mismo almacén que usa la CLI |
| `php artisan predictily:usage-snapshot` | Foto diaria de almacenamiento (programada a las 05:00 America/Guayaquil) |

## 6. Medición de uso por empresa (`tenant_usage_records`) — IMPLEMENTADO / VERIFICADO

Registra **cantidades, nunca precios** (los precios son parámetros del modelo de costos de W4-C). Distinta de `tenant_usage` (contadores por período para los límites del plan). Una fila por (empresa, fuente, referencia, métrica), con índice único
(`2026_03_12_000200`): reintentar un job o repetir el comando reemplaza la fila, no duplica el consumo. `predictily:usage-snapshot` aísla los fallos por empresa: una empresa que falla se informa y se registra en el log, las demás se miden y el comando termina con código de error. Un fallo de medición se registra en el log y nunca interrumpe la ejecución (`TenantUsageMeter`; una transacción por ejecución, todo o nada).

| Métrica (unidad) | `source` / `source_ref` | Origen del dato |
|---|---|---|
| `runs` (run) | `forecast_run` / `core_run` + id | 1 por ejecución completada |
| `train_cpu_seconds` (s) | ídem | Demanda: `summary.timings_seconds` backtesting + training + shadow. Customer `train`: suma de `summary.horizons.*.duration_s` (o la duración de la corrida si no hay desglose) |
| `inference_cpu_seconds` (s) | ídem | Demanda: `timings_seconds.reconciling`. Customer `predict`: duración de la corrida |
| `run_wall_seconds` (s) | ídem | Duración total en el motor (`summary.duration_ms`); demanda sin ese dato: `total` + `loading_data` |
| `storage_bytes` (bytes), `storage_objects` (objects) | `bronze` / `artifacts` + fecha | Comando diario: `bronze_objects.size_bytes` no purgados; artefactos = archivos bajo `ENGINE_ARTIFACTS_DIR/<empresa>/` (si el volumen es visible) + objetos publicados (artefacto y manifiesto) del almacén |

Límites honestos:

- Son **segundos de reloj** del proceso del motor, un proxy del tiempo de CPU (el motor corre en CPU y no mide vCPU); el modelo de costos los multiplica por los vCPU de la tarea. No hay medición de GPU (no hay GPU permanente).
- Solo cuentan las ejecuciones **completadas**; una fallida consumió cómputo que hoy no se registra (**DISEÑADO / NO IMPLEMENTADO**). Tampoco se miden la API, el SPA, las colas ni el tráfico de red por empresa (costos compartidos).
- El estado de la medición es el que reportan los resúmenes del motor: una ejecución cuyo resumen no desglosa tiempos registra solo `runs` (y la duración total si existe); no se inventan valores.
- Si el backend no ve el volumen de artefactos, el comando lo informa (`artifacts_visible = false`) y registra 0 para ese origen en lugar de estimar; los objetos publicados sí se miden.
- Zona horaria: `period` es el día en la zona de la empresa (`tenants.timezone`).
- Las ejecuciones anteriores al despliegue no se miden retroactivamente.

Pruebas: `TenantUsageMeterTest`, `UsageSnapshotCommandTest`, `RegistryMigrationsAndExportTest` (índice único y exportación LOPDP).

## 7. Despliegue en AWS — BLOQUEADO

No hay cuenta AWS del propietario: nada se despliega y el controlador S3 real no se ejercita en este paquete. Las pruebas de verificación de objetos publicados usan un disco local (`Storage::fake`). Cuando exista la cuenta, el backend solo necesita
apuntar `PREDICTILY_MODELS_DISK` (y `PREDICTILY_BRONZE_DISK`) a un disco S3 de `config/filesystems.php` y que el motor use el mismo bucket y prefijo; la arquitectura, el costo y el procedimiento de despliegue están en los documentos de W4-C
(`docs/architecture/AWS_ARCHITECTURE.md`, `docs/architecture/AWS_COST_MODEL.md`, `docs/operations/AWS_DEPLOYMENT.md`).

## 8. Matriz de estado

| Elemento | Estado |
|---|---|
| Máquina de estados con transiciones explícitas y evento en la misma transacción | **IMPLEMENTADO / VERIFICADO** |
| Aprobación separada (`models.approve`, aprobador ≠ publicador/entrenador) con firma verificada y gate de datos | **IMPLEMENTADO / VERIFICADO** |
| Promoción `approved → production` y retiro del anterior del mismo alcance | **IMPLEMENTADO / VERIFICADO** |
| Reversión de demanda y de Customer solo de lo aprobado o legado | **IMPLEMENTADO / VERIFICADO** |
| Política de legados explícita y marcada | **IMPLEMENTADO / VERIFICADO** |
| Lista (core, estado, familia), detalle con eventos, OpenAPI, exportación LOPDP | **IMPLEMENTADO / VERIFICADO** |
| Medición de uso por ejecución y foto diaria de almacenamiento | **IMPLEMENTADO / VERIFICADO** |
| CLI `publish` que registra el candidato con las marcas `published_*` | **DISEÑADO / NO IMPLEMENTADO** aquí (W4-B) |
| Serving del motor solo de `approved`/`production`; clave de caché empresa + versión + esquema | **DISEÑADO / NO IMPLEMENTADO** aquí (W4-B) |
| CHECK en la base de datos que impida `production` sin aprobación para lo publicado | **DISEÑADO / NO IMPLEMENTADO** (ver MODEL_REGISTRY §5.10 y §6) |
| Índice único parcial de una producción por alcance | **DISEÑADO / NO IMPLEMENTADO** (MODEL_REGISTRY §5.5); hoy lo garantizan los bloqueos de la aplicación |
| Medición del consumo de ejecuciones fallidas | **DISEÑADO / NO IMPLEMENTADO** |
| Almacén S3 real y despliegue en AWS | **BLOQUEADO** (sin cuenta del propietario) |
| Pruebas E2E con la pila real y un modelo publicado | **BLOQUEADO** hasta integrar W4-B |
