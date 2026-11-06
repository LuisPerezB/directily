# ADR-V26-01: Aislamiento de fallos y desacople entre procesos

**Estado:** Propuesto (revisión 3, 2026-11-06: los ocho pasos del plan están aplicados en el código, **sin ejecutar ni probar**; ver el plan de acción)
**Fecha:** 2026-11-06
**Decisores:** Responsable técnico de Predictily
**Alcance:** `backend/` (Laravel), `engine/` (FastAPI), `docker-compose.yml`, `backend/routes/console.php`

## Contexto

Predictily es un monolito modular Laravel (≈44 000 líneas en `app/Services`, 25 carpetas de dominio, 109 modelos) más un motor Python interno, PostgreSQL y Redis.

Requisito de esta revisión: **un proceso que falla debe mostrar su error y detenerse, sin arrastrar ni dejar esperando a los demás.**

Cada hallazgo lleva su nivel de evidencia:

- **Verificado**: leído en el código o la configuración.
- **Por medir**: la configuración lo permite, pero que ocurra depende de volúmenes reales que no se midieron.

## Lo que ya está bien (conservar)

| Punto fuerte | Dónde |
|---|---|
| Un error de una empresa no corta el lote | `try/catch` por empresa en los comandos de `app/Console/Commands` |
| Generadores idempotentes | Decisiones, radar, outcomes: repetir no duplica |
| Jobs del motor con reintentos, espera y `failed()` que deja el estado en la base | `RunForecastPipeline`, `RunSupplyOperation`, `RunCustomerOperation`, `RefreshReadiness` |
| Errores del motor tipados, con `isRetryable()` | `Services/Forecasting/Exceptions/` |
| Los comandos de radar y outcomes terminan con código distinto de cero si algo falló | `MaterializeRadarOpportunitiesCommand`, `MeasureOutcomesCommand` |
| La evaluación nocturna y los pronósticos programados ya delegan a la cola (un job por empresa) | `EvaluateActualsCommand`, `ScheduleForecastsCommand` |
| `withoutOverlapping()->onOneServer()` en todo el planificador | `routes/console.php` |
| Motor, PostgreSQL y Redis sin salida a Internet | redes `edge` / `internal` |
| La cadena analítica no tiene ciclos | `Outcomes → Decision → Customer → Forecasting` |

## Falencias

### F1 · La cadena nocturna depende de la hora y comparte un único worker (alto · verificado; magnitud por medir)

`routes/console.php` ordena las etapas por reloj: 02:00 evaluación, 03:00 pronósticos, 04:20 predicción de clientes, 04:50 radar, 05:30 decisiones, 06:10 outcomes.

Las tres primeras **solo encolan** en la cola `forecasting`, que atiende **un único worker** (`queue-forecasting`). La predicción de clientes de las 04:20 queda detrás de todas las evaluaciones y pronósticos de la noche. Un pronóstico puede tardar hasta 960 s.

El radar de las 04:50 toma `latestPrediction()`: la última predicción **completada**, sin comprobar de qué día es (`CustomerRunService.php:152`). Si la de hoy sigue en cola, el radar y las decisiones trabajan con la de ayer, terminan en "éxito" y nadie se entera.

Efecto: un proceso anterior lento no detiene al siguiente, lo hace correr con datos viejos en silencio. Es peor que detenerse.

### F2 · `retry_after` menor que la duración declarada de los jobs largos (alto · verificado en configuración; ocurrencia por medir)

Todas las colas usan la misma conexión Redis con `REDIS_QUEUE_RETRY_AFTER=1100`. Estos jobs declaran más:

| Job | `$timeout` | `$tries` | Cola |
|---|---|---|---|
| `CommitImport`, `LoadDemoData`, `SyncDataSource`, `ProcessBridgeBatch` | 3600 s | 1 | `imports` |
| `ExportTenantData` | 3600 s | 1 | `default` |
| `ValidateImport` | 1800 s | 1 | `imports` |

El `$timeout` del job manda sobre el `--timeout=600` del worker. Si un job de estos supera 1100 s, Redis lo vuelve a entregar mientras sigue corriendo. Con `tries = 1` el segundo intento falla por intentos agotados y puede marcar como fallida una importación que en realidad termina bien.

Relacionado: `stop_grace_period` es de 60 s en `queue` y 120 s en `queue-forecasting`. Un despliegue mata cualquier job que lleve más de eso, y con `tries = 1` queda como fallido.

### F3 · Vecino ruidoso: una empresa hace esperar a todas (alto · verificado)

- `queue-forecasting`: un worker para pronóstico, suministro, clientes, evaluación y readiness de todas las empresas.
- `queue`: un worker para `imports,default`. Una importación larga bloquea validaciones, exportaciones y tareas generales de las demás.
- Consecuencia adicional: `ForecastRunService::activeRun()` da por vencida una ejecución con más de 120 min **desde su creación**, aunque solo esté esperando en cola. Con cola larga, una ejecución legítima deja de contar como activa y se puede pedir otra encima.

### F4 · Tres etapas corren todas las empresas en serie dentro del planificador (medio · verificado)

`radar-materialize`, `decisions-generate` y `outcomes-measure` recorren las empresas dentro del proceso `schedule:work`. El `try/catch` evita que un error corte el lote, pero una empresa lenta retrasa a las siguientes, y no hay reintento por empresa: la que falla espera a la noche siguiente.

### F5 · Los fallos se registran pero no avisan a nadie (medio · verificado)

- Ninguna tarea de `routes/console.php` tiene `onFailure`, `pingOnFailure` ni `emailOutputOnFailure`. Los códigos de salida de radar y outcomes no los escucha nadie.
- `composer.json` no incluye Horizon, Pulse ni un recolector de errores.
- Los tres workers y el planificador tienen `healthcheck: disable: true`.
- El registro va a `stderr` del contenedor.

### F6 · Sin cortacircuitos hacia el motor (medio · verificado)

`EngineClient` reintenta 2 veces ante error de conexión. Con el motor caído, cada job de cada empresa gasta sus 2 intentos (esperas de 60 y 180 s) y queda como fallido. Al volver el motor hay que relanzarlos a mano.

Cuatro controladores llaman al motor dentro de la petición web (`CausalController`, `ScenarioEvaluationController`, `ExtensionsController`, `EffectiveConfigController`). No se revisó cómo degradan si el motor no responde.

### F7 · El bloque de ingesta tiene dependencias circulares (medio · verificado por conteo de `use`)

Conteo de referencias `use App\Services\X` entre carpetas de `app/Services`:

| Ciclo | Referencias en cada sentido |
|---|---|
| `DataCore ↔ Imports` | 34 / 16 |
| `DataCore ↔ Sales` | 11 / 7 |
| `Imports ↔ Sales` | 6 / 6 |
| `Catalog ↔ Imports` | 1 / 12 |
| `Forecasting ↔ Models`, `Customer ↔ Models` | 4 / 3 y 5 / 1 |

`Imports`, `DataCore`, `Sales` y `Catalog` funcionan como un solo módulo repartido en cuatro carpetas. Además `ImportService.php:402` encola `RefreshReadiness` directamente: la ingesta conoce al pronóstico. Solo hay una interfaz en `app/Contracts` (`RecommendationSink`).

El conteo no incluye el acoplamiento a través de modelos Eloquent ni de jobs.

### F8 · Raíz del repositorio (retirado: el diagnóstico era erróneo)

En la raíz hay cinco documentos (`PROMPT-ETAPA-2-ORIGINAL-RECUPERADO.md`, `V25-ESTADO-Y-PROXIMOS-PASOS.md`, `EJECUCION-PREDICTILY.md`, `VALIDACION-PREDICTILY-2026-10-28.md`, `instrucctions.md`) y `reports/`. Las revisiones 1 y 2 los trataron como restos de trabajo que convenía mover. Al revisarlos uno por uno (paso 8) resultó que **no deben moverse**:

- Cuatro figuran con su ruta y su SHA-256 en el paquete de evidencia `docs/validation/2026-10-28/FILES-SHA256.json`. Moverlos invalida ese registro.
- `instrucctions.md` es la instrucción vigente de la etapa 2: la citan 33 archivos, entre ellos comentarios de migraciones.
- `V25-ESTADO-Y-PROXIMOS-PASOS.md` es el estado vigente de V2.5, citado por guías de operación y guiones de trabajo.
- `reports/` es una carpeta de salida en uso (`reports/private/` recibe los resultados del piloto).
- Solo `PROMPT-ETAPA-2-ORIGINAL-RECUPERADO.md` es histórico, y también está en el paquete de evidencia.

El problema real era de legibilidad: el README no los mencionaba. `engine/build` y `*.egg-info` **no** están versionados (la revisión 1 lo dejaba en duda).

## Decisión

Mantener el monolito modular y el motor como servicio interno. No mover carpetas en masa. Añadir aislamiento en cuatro frentes.

### 1. Dependencias por estado, no por reloj (resuelve F1)

Tabla `pipeline_stages`: `tenant_id`, `business_date`, `stage`, `status` (`pending`, `running`, `succeeded`, `failed`, `blocked`), `blocked_by`, `error`, marcas de tiempo.

- Cada etapa, al terminar, registra su resultado y **dispara la siguiente de esa empresa** (evento + listener). El reloj solo arranca la primera etapa.
- Antes de ejecutar, una etapa comprueba que su precedente esté `succeeded` para esa empresa y fecha. Si falló, queda `blocked` con el motivo. Si sigue en curso, espera sin ocupar un worker.
- Regla de dependencia explícita por etapa: `radar` y `decisions` requieren `customer-predict`; `outcomes` no depende de ninguna y corre siempre.

### 2. Colas y workers por tipo de carga (resuelve F2, F3, F4)

| Cola | Contenido | `retry_after` | Workers |
|---|---|---|---|
| `imports` | importación, bridge, sync SQL, exportación | > 3600 s | 1–2 |
| `engine-heavy` | entrenamiento y pronóstico | > 1000 s | 2+ (según memoria del motor) |
| `engine-light` | readiness, evaluación, predicción diaria de clientes | > 720 s | 1–2 |
| `batch` | radar, decisiones, outcomes: un job por empresa | 300 s | 1–2 |
| `default` | correo, tareas cortas | 90 s | 1 |

Requiere conexiones Redis separadas en `config/queue.php`, porque `retry_after` es por conexión. `stop_grace_period` de cada worker debe cubrir su job más largo, o los jobs largos deben poder reanudarse.

La vigencia de una ejecución (`activeRun`) debe medirse desde que **empieza**, no desde que se crea.

### 3. Cortacircuitos y fallos visibles (resuelve F5, F6)

- Cortacircuitos en `EngineClient` con estado en Redis. Con el motor caído, los jobs se devuelven a la cola con espera, sin gastar intentos, y se reanudan solos.
- `onFailure` en cada tarea programada, alerta sobre `failed_jobs`, sobre la edad del job más antiguo por cola y sobre etapas `blocked`.
- Healthcheck real en workers y planificador.

### 4. Límites entre dominios (resuelve F7)

Agrupar las 25 carpetas en cinco capas, sin moverlas, y fijar el sentido permitido de las dependencias con una regla automática en CI (Deptrac o pruebas de arquitectura de Pest):

```
Lectura      Analytics · Conversation · Exports · Scenarios · Promotions · Privacy
   ↓
Decisión     Radar · Decision · Outcomes · Pipeline
   ↓
Núcleos      Forecasting · Customer · Supply · Models · Intelligence
   ↓
Ingesta      Imports · DataCore · Sales · Catalog · Bridge · Connectors · Demo
   ↓
Plataforma   Auth · Tenancy · Plans · Audit
```

`Privacy` (la exportación de datos de la empresa) va arriba porque por naturaleza lee todos los dominios. `Pipeline` (ciclo diario) orquesta radar, decisiones y resultados.

Al fijar la regla había 8 referencias en sentido contrario: `Decision → Analytics` (3), `Radar → Analytics` (2), `Plans → Analytics` (1), `DataCore → Forecasting` (1), `Privacy → Intelligence` (1). Tras el paso 7 quedan 6, todas hacia `Analytics`, registradas como excepciones conocidas en la prueba de arquitectura. Se corrigen moviendo la clase compartida a la capa inferior o con una interfaz en `app/Contracts`.

Los ciclos dentro de Ingesta se aceptan como un solo módulo: no vale la pena separarlos ahora. La ingesta deja de encolar `RefreshReadiness` y emite un evento `ImportCommitted`.

### Reglas de fallo

| Tipo | Ejemplo | Qué pasa |
|---|---|---|
| Definitivo | datos insuficientes (422), configuración (401/404) | La etapa de esa empresa queda `failed` con el motivo. Sus dependientes quedan `blocked`. |
| Transitorio | conexión, 5xx, 409 | Reintento con espera y tope. |
| Dependencia caída | motor no responde | Cortacircuitos: los jobs esperan, no fallan. |
| Nunca | — | Un fallo no pasa a otra empresa ni a una etapa que no depende de la fallida. |

## Opciones consideradas

### Opción A: monolito modular con aislamiento por empresa y etapa (recomendada)

| Dimensión | Evaluación |
|---|---|
| Complejidad | Media |
| Costo | Bajo: mismos servicios, más workers |
| Escalabilidad | Se añaden workers por cola |
| Familiaridad del equipo | Alta: sigue siendo Laravel |

**Pros:** resuelve F1–F6 sin reescribir; cada paso es reversible.
**Contras:** un solo despliegue de código; los límites entre dominios dependen de la regla en CI.

### Opción B: microservicios por dominio

| Dimensión | Evaluación |
|---|---|
| Complejidad | Alta |
| Costo | Alto: varios despliegues, red, datos distribuidos |
| Escalabilidad | Muy alta, prematura |
| Familiaridad del equipo | Baja |

**Pros:** aislamiento físico.
**Contras:** todos los dominios comparten el esquema PostgreSQL y el aislamiento por empresa (RLS). Separarlos convierte transacciones locales en distribuidas. No resuelve F1, que es de orquestación, no de despliegue.

### Opción C: orquestador externo (Temporal, Airflow)

**Pros:** dependencias, reintentos y visibilidad resueltos de fábrica.
**Contras:** un servicio más que operar y otro lenguaje de flujos, para una cadena de seis etapas. Reconsiderar si la cadena crece o se ramifica.

### Opción D: no cambiar nada

**Contras:** F1 y F3 empeoran con cada empresa nueva, porque la ventana nocturna es fija y el worker es uno.

## Análisis de trade-offs

La Opción A mueve la dependencia del reloj a una tabla de estado y reduce el alcance de un fallo de "todas las empresas" a "una empresa y una etapa". Cuesta una tabla, más tipos de job y más colas que vigilar. La Opción C da lo mismo con menos código propio y más operación. Para el tamaño actual de la cadena, la tabla propia es más barata.

## Consecuencias

- **Más fácil:** saber por qué una empresa no tiene recomendaciones hoy, reintentar una sola etapa, añadir empresas sin alargar la noche.
- **Más difícil:** más colas y jobs que vigilar; las pruebas deben cubrir el estado `blocked`.
- **A revisar:** memoria del motor (4 GB) frente a 2 o más entrenamientos simultáneos; si el estado de etapas se muestra en el panel.

## Plan de acción

Cada paso es independiente y reversible. El orden va de menor a mayor riesgo.

1. [x] **F2:** conexión `redis-long` (`retry_after` 3700 s) para el worker de `imports,default`; `stop_grace_period` cubre el job más largo (3660 s y 1020 s, ajustables con `QUEUE_STOP_GRACE_PERIOD` y `FORECASTING_STOP_GRACE_PERIOD`); prueba `tests/Unit/Core/QueueRetryAfterTest.php`. Hecho el 2026-11-06 para Docker Compose y el worker nativo; la prueba quedó sin ejecutar (sin PHP en la máquina). Pendiente: revisar el mismo desajuste en la variante SQS de `infra/aws`.
2. [x] **F5 (parcial):** alerta operativa (`App\Support\Ops\OpsAlert`, `config/ops.php`) cuando una tarea programada termina con error (`routes/console.php`) o un job queda fallido (`Queue::failing` en `AppServiceProvider`). Siempre va al registro como CRITICAL con `ops_alert`; opcionalmente a otro canal de log (Slack), con límite de repetición. Hecho el 2026-11-06; pruebas en `tests/Unit/Ops/OpsAlertTest.php` sin ejecutar (sin PHP en la máquina). Pendiente de F5: edad del job más antiguo por cola, healthcheck de workers y planificador, aviso por correo.
3. [x] **F3 (parcial):** cola de trabajos cortos del motor `forecasting-light` con su worker `queue-forecasting-light` (predicción diaria de clientes, evaluación contra reales, preparación de datos); la pesada conserva el nombre `forecasting` para no dejar huérfanos los jobs ya encolados ni romper la variante SQS (`App\Services\Forecasting\Support\EngineQueue`, `ENGINE_LIGHT_QUEUE`). La vigencia de una ejecución se mide desde que empieza y las que esperan en cola tienen su propio tope (`ActiveRuns`, `ENGINE_STALE_QUEUED_MINUTES`). Hecho el 2026-11-06 solo para Docker Compose; pruebas sin ejecutar (sin PHP en la máquina). Pendiente de F3: la cola `batch` llega con el paso 5; las operaciones de Supply siguen en la cola pesada porque no se midió su duración; la cola `imports,default` sigue con un solo worker; `infra/aws` no tiene la cola ligera. A vigilar: un entrenamiento y un trabajo corto de la misma empresa ahora pueden correr a la vez (antes el único worker los serializaba) y el motor recibe dos llamadas simultáneas.
4. [x] **F1 (parcial):** tabla `pipeline_stages` (una fila por empresa, día de negocio y etapa) y `App\Services\Pipeline\DailyPipeline`. El **radar** depende ahora del estado de la predicción de clientes de hoy, leído de `core_runs`: en curso → `waiting` (no propone con la de ayer); fallida → `blocked` con motivo; terminada → propone. Al terminar la predicción de una empresa (bien o mal) se lanza su radar (`RunRadarStage`), sin esperar a las 04:50; el comando de las 04:50 queda como barrido y usa la misma regla (`RadarStageRunner`; `--force` la omite). Las **decisiones** no se bloquean (reposición, inventario y presupuesto no dependen de la predicción): dejan constancia y avisan si la predicción de hoy no estaba lista; el comando sale con código distinto de cero si una empresa o fuente falla. Hecho el 2026-11-06; pruebas en `tests/Feature/Radar/RadarPipelineGateTest.php` sin ejecutar (sin PHP en la máquina). Pendiente de F1: la dependencia de las decisiones respecto al pronóstico de demanda de las 03:00 sigue siendo por hora; `outcomes`, pronósticos y evaluación no escriben aún en `pipeline_stages`; no hay pantalla ni comando para consultar la tabla (solo SQL y la exportación de datos de la empresa).
5. [x] **F4:** radar, decisiones y medición de resultados como un trabajo por empresa (`App\Jobs\Pipeline\Run{Radar,Decisions,Outcomes}Stage`, base `TenantStageJob`) en la cola `batch`, con su worker `queue-batch`. El planificador lanza los tres comandos con `--queue`, que solo encola; sin esa opción los comandos siguen ejecutando en línea (uso manual, `--dry-run`). Cada etapa pasa por su ejecutor (`*StageRunner`) y deja constancia en `pipeline_stages`, incluida `outcomes`. Un trabajo con errores parciales queda fallido con un código estable y genera su alerta. Hecho el 2026-11-06 para Docker Compose; fuera de él la cola es `default` (`PIPELINE_QUEUE`). Pruebas en `tests/Feature/Pipeline/StageFanOutTest.php` sin ejecutar (sin PHP en la máquina). A vigilar: con más de un worker `queue-batch`, dos etapas de la misma empresa pueden coincidir.
6. [x] **F6:** cortacircuitos compartido por los cuatro clientes del motor (`App\Services\Forecasting\Support\EngineCircuit`, estado en la caché). Tras 3 llamadas seguidas sin lograr conectar se abre 60 s: las llamadas fallan al instante (`engine_circuit_open`) y se genera una alerta (`engine_unavailable`). Los trabajos del motor llevan el middleware `WaitForEngine`: mientras está abierto se encola una copia con espera, sin gastar intentos, hasta 60 veces (≈ 1 h); después fallan por la vía normal. Un tiempo de lectura agotado o una respuesta HTTP de error no lo abren. Hecho el 2026-11-06; apagado por defecto en las pruebas existentes (`phpunit.xml`) y probado en `tests/Unit/Analytics/EngineCircuitTest.php`, sin ejecutar (sin PHP en la máquina). Límites: los trabajos únicos (evaluación contra reales, preparación de datos) vuelven a la cola y sí gastan intento; la detección de «motor caído» se basa en el texto del error de cURL.
7. [x] **F7 (parcial):** regla de capas como prueba (`tests/Unit/Architecture/LayerDependenciesTest.php`): falla ante cualquier referencia nueva hacia una capa superior y ante un dominio nuevo sin capa. Corregidas 3 de las 9 referencias que la incumplían: `PlatformEngineClient` se movió junto a los demás clientes del motor (2) y `Privacy` se reclasificó en la capa de lectura (1). Las 6 restantes quedan registradas como excepciones conocidas en la propia prueba, con su corrección: todas apuntan a tres ayudas de lectura de `Analytics` (`RunResolver`, `SalesQuery`, `ForecastNode`) que usan más de diez archivos cada una y no se movieron sin poder ejecutar las pruebas. Hecho el 2026-11-06. La lógica de la prueba se verificó con un script equivalente en Python (6 de 6); la prueba PHP en sí no se ejecutó (sin PHP en la máquina). **No hay CI activa:** el único flujo de trabajo está en `backend/.github/workflows/` (GitHub solo lee `.github/` en la raíz) y es el de la plantilla de Laravel, con SQLite, mientras que las pruebas exigen PostgreSQL. Hasta que exista una CI, la regla solo se cumple si alguien ejecuta `php artisan test`. Pendiente: crear la CI, bajar de capa las tres ayudas, y que la ingesta emita `ImportCommitted` en vez de encolar `RefreshReadiness`.
8. [x] **F8 (cambiado):** **no se movió ningún documento**; el diagnóstico era erróneo (ver F8). En su lugar el README incluye un índice de los documentos de la raíz, con qué es cada uno, su vigencia y la advertencia de no moverlos. Hecho el 2026-11-06. Ningún archivo del paquete de evidencia se modificó en los pasos 1 a 8.

## Por medir antes de dimensionar

- Duración real de cada job nocturno por empresa y cuántas empresas activas hay. Decide el número de workers y confirma la magnitud de F1 y F3.
- Duración de las importaciones más grandes. Confirma si F2 ya ocurre.
- Memoria pico del motor por entrenamiento. Limita los workers de `engine-heavy`.
- Comportamiento de los cuatro controladores que llaman al motor cuando este no responde.
