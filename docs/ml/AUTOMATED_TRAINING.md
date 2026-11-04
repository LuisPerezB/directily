# Entrenamiento automático con límites y aprobación (DISEÑO)

Paquete W4-B · V2.5 §9 (`PredictilyTrainingOrchestrator`, disparadores configurables, «sin reentrenar tras cada factura», champion retenido mientras corre el challenger) · ADR-V25-07, -19, -21.

**Estado de TODO este documento: DISEÑADO / NO IMPLEMENTADO.** No hay planificador, ni disparadores, ni orquestador en el código. Lo que existe hoy son los bloques sobre los que se construiría (§2) y un hecho
que conviene tener presente: **el pipeline propio sigue promoviendo automáticamente los modelos de sus propias corridas** (§3, pendiente documentado). Ningún umbral de este documento es universal: todo valor es un parámetro inicial
**a aprobar en el piloto** con datos reales; no se fijan cifras aquí.

## 1. Objetivo y principios

Reentrenar los modelos de una empresa cuando haga falta —no tras cada factura— y llevarlos al registro como **candidatos**, de modo que servir uno nuevo siga exigiendo la aprobación separada (otra persona, `models.approve`)
con las compuertas del backend (firma, gate de datos, política). Principios:

1. **Un candidato nunca se sirve solo.** Automatizar el entrenamiento no automatiza la aprobación.
2. **El champion se retiene** para la inferencia mientras corre el challenger y si falla la calidad o la conexión (fallback seguro: último modelo estable o baseline, con marca clara).
3. **Aislamiento por empresa**: cada ejecución usa solo los datos, la configuración y las claves de esa empresa; ninguna ejecución comparte caché ni artefactos con otra (ya probado para el serving, `PRIVATE_TENANT_TRAINING.md` §7).
4. **Acotado**: un tope de cómputo y de costo por empresa y por periodo; sin GPU permanente; Optuna solo con los límites de W3-B.
5. **Idempotente y auditable**: cada decisión de entrenar o no entrenar deja un registro con su motivo.
6. **Sin secretos en el programador**: la clave de firma no vive en el planificador; firma el entorno de entrenamiento autorizado (§6).

## 2. Qué ya existe (bloques reutilizables)

| Bloque | Estado | Dónde |
|---|---|---|
| Entrenamiento por empresa sin escribir en la base, paridad con el servicio | IMPLEMENTADO / VERIFICADO | `mlops/training.py`; `PRIVATE_TENANT_TRAINING.md` |
| Paquete firmado, publicación como `candidate`, filtro de serving, caché | IMPLEMENTADO / VERIFICADO | `mlops/bundle.py`, `mlops/serving.py` |
| Gates de calidad de datos que bloquean o degradan (W2-C) | IMPLEMENTADO / VERIFICADO | `quality/` |
| Política de promoción V2.5, champion/challenger, shadow, decisiones registradas (W3-B) | IMPLEMENTADO / VERIFICADO | `selection/`; `model_promotion_decisions` |
| Optuna acotado, sin usar el holdout (W3-B) | IMPLEMENTADO / VERIFICADO | `selection/tuning.py` |
| Medición de uso por ejecución para el costo por empresa (W4-A) | IMPLEMENTADO / VERIFICADO | `tenant_usage_records` |
| Evaluación prospectiva (pronóstico vs. ventas reales) como señal de degradación | IMPLEMENTADO / VERIFICADO | `evaluation/actuals.py`, `cli.py evaluate` |
| Deriva de la distribución de probabilidades del Customer Core (señal de revisión) | IMPLEMENTADO / VERIFICADO | `customer/drift.py` (W3-C) |
| Programador (EventBridge Scheduler → SQS → tarea de ECS) | **DISEÑADO**; la plantilla de W4-C define colas y programador | `docs/architecture/AWS_ARCHITECTURE.md`; despliegue **BLOQUEADO** |

## 3. Pendiente documentado: la promoción automática del pipeline propio (legado)

Hoy `execute_forecast_run` y `execute_customer_run` registran los modelos de cada corrida de producción y **promueven** a `production` lo que supera su regla (estado `production` escrito por el motor). El backend los reconoce como
**legado del pipeline propio** (`serving_basis = legacy_pipeline`, `MODEL_REGISTRY.md` §5.6) para no romper la demo, el rollback ni los E2E (`e2e/mvp_acceptance.py`, `e2e/stage2_acceptance.py`). Este paquete **no lo cambia**: con la configuración
por defecto lo servido es idéntico (verificado, `PRIVATE_TENANT_TRAINING.md` §9.2).

Para cerrar la excepción, en una iteración posterior (con revisión de la demo y los E2E):

1. Que el pipeline registre cada entrenamiento como `candidate` con el contrato de publicación (o con un actor `system` explícito) y espere `validate → approve → promote`.
2. Mientras tanto, mantener el champion vigente y servir su `legacy_pipeline`/`approved` (la prueba de que un candidato no se sirve ya existe).
3. Decidir con el propietario qué empresas conservan la promoción automática (por contrato) y cuáles exigen aprobación humana; la decisión de promoción de W3 ya deja `approved_by`/`approved_at` pendientes para completar.
4. Migrar las filas legadas (aprobarlas explícitamente) cuando se quiera salir de la excepción.

Riesgo si no se hace: un cambio de datos o de código puede cambiar lo servido sin una persona que lo apruebe; lo acotan los gates de calidad y la política de promoción, no la aprobación separada.

## 4. Diseño del orquestador

Etapas (V2.5 §9): ingesta → variables → inferencia → evaluación → señal de deriva → entrenamiento candidato → ajuste → backtest/calibración → validación → aprobación → publicación → monitoreo/reversión.

```
 Programador (calendario) ──┐
 Señales (deriva, degradación, datos nuevos, cambio de precios, solicitud autorizada) ──┐
                                                                                      ▼
                          Evaluador de disparadores (por empresa; decide entrenar o no, y por qué)
                                                  │ sí (dentro del presupuesto)
                                                  ▼
          Tarea de entrenamiento aislada de UNA empresa  ──►  train → package → publish (candidate)  ──►  notificación al aprobador
                                                                                                   │
                                          backend: validate → approve (otra persona) → promote  ◄─┘
                          el champion sigue sirviendo mientras tanto; si falla, no se promueve nada
```

### 4.1 Disparadores (configurables por empresa; todos los umbrales «a aprobar en el piloto»)

| Disparador | Señal | Notas |
|---|---|---|
| Datos nuevos suficientes | volumen o antigüedad de datos desde el último entrenamiento | nunca «tras cada factura»; con un mínimo de intervalo entre entrenamientos |
| Deriva | PSI ajustado del Customer Core (alerta existente) y deriva de entradas de demanda (a diseñar) | una alerta pide revisión; el disparador la convierte en un entrenamiento candidato |
| Degradación sostenida | error prospectivo (`evaluate`) por encima del baseline durante varias evaluaciones | «sostenida» = ventana y umbral parametrizados |
| Cambio de precios / mercado | variación de precios o evento extraordinario conocido (calendario) | usa los eventos versionados existentes |
| Calendario | frecuencia por plan (los planes ya declaran frecuencias) | respeta `plans.limits` |
| Solicitud autorizada | un usuario con permiso lo pide | pasa por el mismo evaluador de presupuesto |

### 4.2 Límites y presupuestos

- Tope de ejecuciones y de segundos de CPU por empresa y periodo, tomados del plan y de `tenant_usage_records`; al agotarse, no se entrena y se registra el motivo.
- Concurrencia máxima por empresa (una) y global; cola con reintentos acotados y DLQ (plantilla de W4-C).
- Sin GPU permanente; CPU por defecto. Un entrenamiento que exceda su límite se corta y no deja candidatos a medias.
- Alertas de presupuesto: AWS Budgets de W4-C; el costo por empresa se calcula con el modelo paramétrico de `AWS_COST_MODEL.md` (sin precios inventados).

### 4.3 Aprobación y notificación

El candidato publicado crea la tarea de aprobación para quienes tienen `models.approve` (otra persona distinta de quien disparó el entrenamiento; un entrenamiento programado no tiene responsable humano, `MODEL_REGISTRY.md` §5.3).
El comparativo (`compare`) y las decisiones de la política de W3 son la evidencia que ve el aprobador. Si nadie aprueba en un plazo configurable, el candidato caduca (`retired`/`rejected` con motivo) y el champion sigue.

### 4.4 Idempotencia, bloqueos y fallos

- Un identificador de disparo por (empresa, causa, ventana) evita entrenamientos duplicados; el paquete ya es idempotente al publicar.
- Un bloqueo por empresa evita dos entrenamientos simultáneos; una ejecución abandonada se retoma o se descarta como las de demanda y Customer (`stale_run_seconds`).
- Si falla la calidad (gate BLOCK), la conexión o la verificación, no se publica y se conserva el champion; el motivo queda en `model_registry_events` o en el registro de disparos.

## 5. Qué habría que construir (y probar)

| Pieza | Prueba prevista |
|---|---|
| Registro de disparos y evaluador de disparadores por empresa | un caso por disparador y uno de «no entrenar» con motivo |
| Tarea de entrenamiento aislada que ejecuta `train → package → publish` con un actor `system` | un entrenamiento programado no tiene responsable humano y su candidato no se sirve sin aprobación |
| Presupuesto por empresa contra `tenant_usage_records` | al agotarse el presupuesto no se entrena |
| Caducidad de candidatos sin aprobar | el champion sigue sirviendo |
| Cambio del pipeline propio para registrar `candidate` (§3) | demo y E2E siguen funcionando con la política documentada |
| Pruebas negativas entre empresas del orquestador | un disparo de A nunca entrena con datos ni publica bajo el prefijo de B |

## 6. Seguridad

- La clave privada de firma no vive en el programador ni en el evaluador: la custodia el entorno de entrenamiento autorizado; el objetivo es KMS/HSM (`MODEL_REGISTRY.md` §3 y §6, DISEÑADO).
- El rol de la tarea de entrenamiento escribe solo bajo `tenants/<uuid de la empresa>/models/` (ABAC por etiqueta, `AWS_ARCHITECTURE.md`); lee solo los datos de esa empresa.
- Sin datos de una empresa en los registros de disparos más allá de identificadores y cifras agregadas (sin PII).

## 7. Decisiones que necesita del propietario

1. Qué empresas conservan la promoción automática del legado y por cuánto tiempo (§3).
2. Presupuesto de cómputo por plan y por empresa; frecuencia máxima de reentrenamiento por plan.
3. Quién aprueba (roles) y el plazo de caducidad de un candidato sin aprobar.
4. Si el entrenamiento automático corre en AWS (BLOQUEADO hasta tener cuenta) o en la máquina de entrenamiento privada.
