# Predictily · Modelo de costos en AWS por empresa (V2.5 · W4-C)

> **Estado: modelo PARAMÉTRICO, DISEÑADO; sin cifras reales de factura (no hay cuenta AWS: BLOQUEADO).**
> Reglas de este documento: (1) **ningún precio inventado**: cada precio de la tabla de parámetros trae su fuente pública con fecha
> o la marca **«a confirmar por el propietario»**; (2) el único ejemplo numérico con dólares usa precios **citados** o supuestos
> **declarados como tales**; (3) los umbrales y reglas de reparto son propuestas **a aprobar en el piloto**, no universales.
> Contexto: §14 del documento maestro V2.5 (`cost_per_tenant`: ETL, almacenamiento, inferencia, reentrenamiento, APIs, LLM,
> soporte estimado) y arquitectura en `docs/architecture/AWS_ARCHITECTURE.md`.

## 1. Cómo se mide el consumo por empresa (contrato con `tenant_usage_records`)

La tabla la crea la migración `2026_03_12_000100` (índice único de idempotencia en `2026_03_12_000200`) y la escribe W4-A
(`TenantUsageMeter`, comando `predictily:usage-snapshot`, programado a las 05:00 America/Guayaquil en `routes/console.php`
y, como red de seguridad, a las 05:30 por EventBridge Scheduler; el comando es idempotente). **Sin precios**: solo cantidades.

| `metric` | `unit` | `source` · `source_ref` | Qué representa | Uso en el costo |
|---|---|---|---|---|
| `runs` | `run` | `forecast_run` / `core_run` · id | una ejecución completada del motor | informativo; proxy de mensajes SQS y de trabajo de ETL |
| `train_cpu_seconds` | `s` | ídem | segundos de las etapas de entrenamiento del motor (`backtesting`+`training`+`shadow`; en Customer, suma de `duration_s` por horizonte) | reparto del grupo «motor» |
| `inference_cpu_seconds` | `s` | ídem | segundos de la etapa de inferencia/reconciliación (en Customer, la duración de `predict`) | reparto del grupo «motor» |
| `run_wall_seconds` | `s` | ídem | duración total de la ejecución | contraste con las dos anteriores |
| `storage_bytes` | `bytes` | `bronze` / `artifacts` · fecha | foto diaria del almacenamiento de la empresa | almacenamiento S3 (GB‑mes = promedio de las fotos del mes) |
| `storage_objects` | `objects` | ídem | número de objetos de la foto diaria | estimación de solicitudes S3 |

Limitaciones que el modelo debe respetar (honestidad): (a) `*_cpu_seconds` son **tiempo de reloj de las etapas del motor**, no
CPU facturada ni núcleos‑segundo (el motor usa `ENGINE_N_JOBS=2` hilos); sirven como **proxy proporcional** para repartir el costo
del motor. (b) La foto de `artifacts` mide el volumen de artefactos si el backend lo ve y los objetos publicados; si no, W4-A
informa `artifacts_visible=false` y no inventa un valor (la plantilla monta el volumen de artefactos de solo lectura en las
tareas del programador y efímeras para que sea visible). (c) **No se mide hoy**: tamaño de la base de datos por empresa,
llamadas al asistente/LLM, transferencia de salida, solicitudes a la API, soporte humano. Son métricas **DISEÑADAS** (ver §7).

## 2. Qué es fijo compartido y qué es variable por empresa

| Recurso (plantilla) | Tipo | Impulsor del costo | Cómo se asigna a una empresa |
|---|---|---|---|
| Fargate API (2 tareas prod / 1 staging), workers, programador | Fijo compartido (24×7) | horas × vCPU × GB | regla de reparto del fijo (§4, ρₑ) |
| Fargate motor (2 vCPU / 4 GiB) | Fijo compartido, **reparto por uso** | horas × vCPU × GB | proporcional a `train+inference` (§4, Cmotor) |
| Fargate efímero (migraciones, comandos) | Variable de plataforma | segundos de tarea | fijo (despreciable) |
| RDS PostgreSQL (instancia, almacenamiento, respaldos, Performance Insights) | Fijo compartido (+ crecimiento con datos) | horas de instancia, GiB, GiB de respaldo | fijo por ρₑ; el crecimiento por empresa requiere la métrica `db_bytes` (DISEÑADA) |
| NAT Gateway (1) | Fijo compartido + variable por GB procesado | horas; GB procesados | fijo por ρₑ; el tráfico no se mide por empresa |
| ALB | Fijo compartido + LCU | horas; LCU | fijo por ρₑ |
| CloudFront + WAF | Fijo (WAF: ACL y reglas) + variable (solicitudes, GB) | ACL/reglas por mes; solicitudes | fijo por ρₑ |
| EFS (importaciones y artefactos) | Variable por GB | GiB‑mes; lectura/escritura elástica | proporcional a `storage_bytes` (aproximación) |
| S3 Bronze / Silver / Gold / Artefactos | **Variable por empresa** | GiB‑mes; solicitudes | `storage_bytes` y `storage_objects` de cada empresa |
| KMS (2 claves), Secrets Manager (4 secretos), ECR | Fijo compartido | por clave/secreto/GiB | fijo por ρₑ |
| SQS (3 colas + DLQ) | Variable casi nula | millones de solicitudes | proxy `runs`; dentro de la capa gratuita al inicio |
| CloudWatch (logs, alarmas, métricas), VPC Flow Logs | Fijo + variable por GB ingerido | GB ingeridos; alarmas | fijo por ρₑ |
| EventBridge Scheduler, Cloud Map, SNS, AWS Budgets | Fijo (despreciable) | invocaciones; consultas | fijo por ρₑ |
| LLM / asistente conversacional (proveedor externo, fuera de AWS) | Variable por empresa | tokens | métrica `llm_tokens` (DISEÑADA) |
| Soporte estimado | Variable por empresa | horas × tarifa | entrada manual del propietario |

## 3. Tabla de parámetros y fuentes

Fecha de consulta de todas las fuentes: **2026-11-01**. AWS no muestra fecha de actualización en estas páginas (solo el
copyright 2026); por eso la fuente es «página citada + fecha de consulta». Las tablas de precios por instancia no se renderizan en el
texto de varias páginas: esos parámetros quedan **«a confirmar por el propietario»** (con la página donde confirmarlos). El valor
**vigente** manda: el propietario confirma en la factura/calculadora de precios de AWS antes de usar el modelo para decidir.

| Id | Parámetro | Unidad | Valor | Fuente y fecha | Estado |
|---|---|---|---|---|---|
| P01 | Fargate Linux/x86 · CPU (us-east-1) | USD / vCPU‑s | 0,000011244 | aws.amazon.com/fargate/pricing, ejemplo 1 (región N. Virginia) · 2026-11-01 | CITADO (ejemplo de la página) |
| P02 | Fargate Linux/x86 · memoria | USD / GB‑s | 0,000001235 | ídem | CITADO |
| P03 | Fargate Linux/ARM · CPU | USD / vCPU‑s | 0,0000089944 | ídem, ejemplo 2 | CITADO |
| P04 | Fargate Linux/ARM · memoria | USD / GB‑s | 0,0000009889 | ídem | CITADO |
| P05 | Fargate · almacenamiento efímero (sobre 20 GB incluidos) | USD / GB‑s | 0,0000000308 | ídem (ejemplo) | CITADO (no se usa: la plantilla no pide más de 20 GB) |
| P06 | ALB · hora | USD / h | 0,0225 | aws.amazon.com/elasticloadbalancing/pricing, ejemplo (US-East-1) · 2026-11-01 | CITADO (ejemplo) |
| P07 | ALB · LCU‑hora | USD / LCU‑h | 0,008 | ídem | CITADO (ejemplo) |
| P08 | WAF · web ACL | USD / mes | 5,00 | aws.amazon.com/waf/pricing, caso A · 2026-11-01 | CITADO |
| P09 | WAF · regla o grupo de reglas administrado | USD / mes | 1,00 | ídem (texto de la página) | CITADO (la plantilla usa 2 reglas de tasa + 3 grupos = 5) |
| P10 | WAF · solicitudes inspeccionadas | USD / millón | 0,60 | ídem, caso A | CITADO |
| P11 | KMS · clave administrada por el cliente | USD / mes | 1,00 | aws.amazon.com/kms/pricing · 2026-11-01 | CITADO (la rotación suma 1 USD/mes en las dos primeras rotaciones, según la página) |
| P12 | KMS · solicitudes simétricas | USD / 10 000 | 0,03 | ídem, ejemplo S3 (región no indicada) · capa gratuita 20 000/mes | CITADO (ejemplo) |
| P13 | Secrets Manager · secreto | USD / mes | 0,40 | aws.amazon.com/secrets-manager/pricing, ejemplo 1 · 2026-11-01 | CITADO (ejemplo) |
| P14 | Secrets Manager · llamadas | USD / 10 000 | 0,05 | ídem, ejemplo 2 | CITADO (ejemplo) |
| P15 | S3 Standard · GET | USD / 1 000 | 0,0004 | aws.amazon.com/s3/pricing, ejemplo S3 Object Lambda (N. Virginia) · 2026-11-01 | CITADO (ejemplo) |
| P16 | SQS · capa gratuita | solicitudes / mes | 1 000 000; cada 64 KB cuenta como 1 | aws.amazon.com/sqs/pricing · 2026-11-01 | CITADO (el precio por millón no se renderiza) |
| P17 | RDS PostgreSQL · `db.t4g.small` / `db.t4g.medium` Single‑AZ | USD / h | — | aws.amazon.com/rds/postgresql/pricing | **a confirmar por el propietario** |
| P18 | RDS · almacenamiento gp3 | USD / GB‑mes | — | ídem | **a confirmar por el propietario** |
| P19 | RDS · almacenamiento de respaldos sobre lo gratuito | USD / GB‑mes | — | ídem | **a confirmar por el propietario** |
| P20 | NAT Gateway · hora | USD / h | — | aws.amazon.com/vpc/pricing (el único valor del texto es de un ejemplo de **Ohio**, no se usa) | **a confirmar por el propietario** |
| P21 | NAT Gateway · datos procesados | USD / GB | — | ídem | **a confirmar por el propietario** |
| P22 | Endpoints de interfaz · hora y GB | USD / h‑AZ y GB | — | ídem (apagados en la línea base) | **a confirmar por el propietario** |
| P23 | S3 Standard · almacenamiento | USD / GB‑mes | — | aws.amazon.com/s3/pricing | **a confirmar por el propietario** |
| P24 | S3 · PUT/COPY/POST/LIST | USD / 1 000 | — | ídem | **a confirmar por el propietario** |
| P25 | SQS · solicitudes sobre la capa gratuita | USD / millón | — | aws.amazon.com/sqs/pricing | **a confirmar por el propietario** |
| P26 | EFS · almacenamiento Standard / IA y rendimiento elástico | USD / GB‑mes y USD / GB | — | aws.amazon.com/efs/pricing | **a confirmar por el propietario** |
| P27 | CloudFront · transferencia de salida y solicitudes (clase de precio 100) | USD / GB y / 10 000 | — | aws.amazon.com/cloudfront/pricing | **a confirmar por el propietario** |
| P28 | CloudWatch · ingesta y almacenamiento de logs, alarmas, métricas | USD / GB, alarma‑mes | — | aws.amazon.com/cloudwatch/pricing | **a confirmar por el propietario** |
| P29 | ECR · almacenamiento | USD / GB‑mes | — | aws.amazon.com/ecr/pricing | **a confirmar por el propietario** |
| P30 | Transferencia de datos de salida a Internet y entre AZ | USD / GB | — | aws.amazon.com/ec2/pricing/on-demand (sección Data Transfer) | **a confirmar por el propietario** |
| P31 | EventBridge Scheduler, Cloud Map, Budgets, SNS | USD | — | páginas de cada servicio | **a confirmar por el propietario** (se esperan valores despreciables) |
| P32 | LLM del asistente (proveedor externo) | USD / 1 000 tokens | — | contrato con el proveedor | **a confirmar por el propietario** |
| P33 | Soporte estimado | USD / hora | — | decisión del propietario | **a confirmar por el propietario** |
| S0 | Horas por mes | h | 730 | supuesto de cálculo (8760 / 12) | SUPUESTO |
| S1 | Peso del cómputo (λ) en el reparto del fijo | — | 0,5 | supuesto ilustrativo | SUPUESTO · a aprobar en el piloto |

## 4. Fórmulas

Sea `M` el mes, `S = S0 × 3600` los segundos del mes, `e` una empresa y `E` el conjunto de empresas activas.

**Costo fijo del entorno**

```
C_fargate   = Σ_servicios  n_s × ( vCPU_s × P01 + GB_s × P02 ) × S          (x86; con ARM64 usar P03 y P04)
C_fijo      = C_fargate(API, workers, programador)  + C_red(NAT, ALB, endpoints) + C_borde(CloudFront, WAF)
            + C_datos_fijos(RDS instancia, KMS, Secrets, ECR, alarmas) + C_otros(Scheduler, Cloud Map, SNS)
C_motor_pool = n_motor × ( vCPU_motor × P01 + GB_motor × P02 ) × S           (el grupo que se reparte por uso)
```

**Consumo medido de la empresa `e` en `M`** (consultas sobre `tenant_usage_records`, ver §6):
`u_cpu(e) = Σ train_cpu_seconds + Σ inference_cpu_seconds`; `GBm_b(e)` y `GBm_a(e)` = promedio de las fotos diarias de
`storage_bytes` de Bronze y artefactos dividido entre 2³⁰ (convención GiB; **confirmar** la unidad de la factura).

**Costo atribuido a la empresa**

```
C_motor(e) = C_motor_pool × u_cpu(e) / Σ_E u_cpu          si Σ u_cpu = 0 el grupo queda como «capacidad ociosa» (no se reparte)
C_s3(e)    = ( GBm_b(e) + GBm_a(e) ) × P23  +  ( objetos(e) × k_put ) × P24 / 1000       (k_put = escrituras por objeto, a medir)
C_efs(e)   = GBm_efs(e) × P26                              GBm_efs ≈ GBm_a(e) mientras los artefactos sigan en EFS
C_sqs(e)   = runs(e) × k_msg × P25 / 10^6                  k_msg = mensajes por ejecución (a medir; hoy bajo la capa gratuita P16)
C_db(e)    = C_datos_variables × share_db(e)               share_db: por ahora ∝ GBm_b(e) (aproximación); db_bytes(e) cuando exista
C_ext(e)   = tokens_llm(e) × P32 + horas_soporte(e) × P33  (métricas DISEÑADAS; entrada manual mientras tanto)
C_compartido(e) = ρₑ × C_fijo                              Σ_E ρₑ = 1
C(e)       = C_motor(e) + C_s3(e) + C_efs(e) + C_sqs(e) + C_db(e) + C_compartido(e) + C_ext(e)
Margen(e)  = Ingreso_plan(e) − C(e)       (el plan de cada empresa es configurable por tenant; los 49/119 USD del maestro §16 son hipótesis antiguas)
```

**Reglas de reparto del fijo (ρₑ) — a aprobar por el propietario en el piloto**

- **R1, igual**: `ρₑ = 1 / |E|`. Simple, castiga a las empresas pequeñas.
- **R2, por uso**: `ρₑ = λ · u_cpu(e)/Σu_cpu + (1−λ) · (GBm_b(e)+GBm_a(e))/Σ(GBm_b+GBm_a)`, con `λ = S1 = 0,5` como supuesto.
- **R3, mínimo + uso**: un piso por empresa activa más el resto por R2. Recomendable cuando hay pocas empresas y el fijo domina.

El modelo informa siempre, además del costo por empresa, la **utilización del motor** `Σ u_cpu / (vCPU_motor × S)` y el fijo no
absorbido: con pocas empresas el costo por empresa lo domina el fijo, y eso es información de negocio, no un error.

## 5. Ejemplo numérico (parámetros CITADOS y SUPUESTOS declarados)

**A. Fargate de producción, con los tamaños de la plantilla y los precios citados P01/P02 (x86), `S0 = 730` (S = 2 628 000 s):**

| Servicio | Tareas × (vCPU, GiB) | USD / mes |
|---|---|---|
| API | 2 × (0,5; 1) | 36,04 |
| Worker default | 1 × (0,5; 2) | 21,27 |
| Worker forecasting | 1 × (0,25; 1) | 10,63 |
| Programador | 1 × (0,25; 0,5) | 9,01 |
| **Motor** | 1 × (2; 4) | **72,08** |
| **Total Fargate (producción, 24×7)** | | **149,03** |

Staging (API de 1 tarea): 131,01. Con ARM64 (P03/P04) el total de producción sería 119,24 (‑20 %), **si** las imágenes y
dependencias nativas se validan en `arm64`. Esto es **solo Fargate**: no incluye RDS, NAT, EFS, CloudFront, S3, CloudWatch
ni transferencia, cuyos precios están «a confirmar por el propietario» (P17–P30); por tanto **no es el costo total del entorno**.

**B. Reparto entre tres empresas (SUPUESTOS ilustrativos, no mediciones ni precios de AWS).**
Consumo del mes: A = 40 000 s de entrenamiento + 6 000 s de inferencia y 20 GiB; B = 10 000 + 4 000 s y 5 GiB;
C = 0 + 2 000 s y 1 GiB. Supuesto de fijo compartido: `C_fijo = 1 000 USD/mes` (cifra redonda **inventada para el ejemplo**, no
un precio). Grupo del motor: 72,08 USD (del cuadro A).

| Empresa | `u_cpu` (s) | % cómputo | `C_motor` | % almacenamiento | ρ (R1) | `ρ·C_fijo` (R1) | ρ (R2, λ = 0,5) | `ρ·C_fijo` (R2) |
|---|---|---|---|---|---|---|---|---|
| A | 46 000 | 74,19 % | 53,48 | 76,92 % | 33,33 % | 333,33 | 75,56 % | 755,58 |
| B | 14 000 | 22,58 % | 16,28 | 19,23 % | 33,33 % | 333,33 | 20,91 % | 209,06 |
| C | 2 000 | 3,23 % | 2,33 | 3,85 % | 33,33 % | 333,33 | 3,54 % | 35,36 |
| Total | 62 000 | 100 % | 72,08 | 100 % | 100 % | 1 000,00 | 100 % | 1 000,00 |

Lectura: con R1 la empresa C absorbe 335,66 USD (333,33 + 2,33) usando el 3 % del motor; con R2, 37,69. Elegir la regla es una
decisión comercial (precio mínimo del plan) y por eso queda **a aprobar en el piloto**. El costo de almacenamiento S3 de cada
empresa se obtiene multiplicando sus GiB‑mes por P23 cuando el propietario lo confirme (no se muestra en dólares a propósito).

## 6. Consultas de medición (contrato de datos para el informe mensual)

```sql
-- Consumo de ejecuciones del motor por empresa en el mes
select tenant_id,
       sum(quantity) filter (where metric = 'train_cpu_seconds')     as train_s,
       sum(quantity) filter (where metric = 'inference_cpu_seconds') as inference_s,
       sum(quantity) filter (where metric = 'run_wall_seconds')      as wall_s,
       sum(quantity) filter (where metric = 'runs')                  as runs
from tenant_usage_records
where period >= date '2026-11-01' and period < date '2026-12-01'
group by tenant_id;

-- Almacenamiento: promedio de las fotos diarias por capa (bytes) y objetos
select tenant_id, source,
       avg(quantity) filter (where metric = 'storage_bytes')   as avg_bytes,
       avg(quantity) filter (where metric = 'storage_objects') as avg_objects,
       count(distinct period)                                   as dias_con_foto
from tenant_usage_records
where source in ('bronze', 'artifacts') and period >= date '2026-11-01' and period < date '2026-12-01'
group by tenant_id, source;
```

`dias_con_foto` < días del mes indica que el comando diario no corrió: el promedio es entonces una estimación y debe informarse así.
La consulta agrega **todas** las empresas: es un informe del propietario de la plataforma; cualquier informe para un cliente se limita a
su propia empresa (ámbito de tenant), nunca muestra consumo ni costos de otra.

## 7. Qué falta para cerrar el modelo (estado honesto)

| Pendiente | Estado |
|---|---|
| Confirmar P17–P33 y repetir el ejemplo A completo | **a confirmar por el propietario** |
| Datos reales de consumo (`tenant_usage_records` con empresas del piloto) | BLOQUEADO hasta el piloto; el contrato y los registros existen (W4-A) |
| Métricas `db_bytes`, `llm_tokens`, `api_requests`, transferencia por empresa | DISEÑADAS, no implementadas |
| Etiquetas de asignación de costos (`predictily:environment` ya se aplica a todos los recursos; activarlas en Billing) | paso del propietario (`AWS_DEPLOYMENT.md` §3) |
| Informe mensual automatizado por empresa y margen contra el plan | DISEÑADO |
| Comparar costo estimado vs factura real (cierre de mes) | BLOQUEADO (sin cuenta) |

## 8. Palancas de costo (decididas por datos, nunca debilitando seguridad)

NAT única en lugar de una por AZ (ya aplicado; riesgo: punto único de fallo) · endpoints de interfaz **apagados** (encenderlos
reduce el tráfico por NAT pero cuesta por hora y AZ: decidir con P20–P22) · ARM64 (‑20 % en Fargate, ver cuadro A) · clase de
precio 100 de CloudFront · retención de logs · WAF con reglas administradas solo si la muestra justifica su costo (P08–P10) · Fargate
Spot para el worker de pronósticos (DISEÑADO; los trabajos tienen reintentos pero no se ha probado la interrupción) · apagado
nocturno de staging con EventBridge Scheduler (DISEÑADO) · **sin GPU permanente** (regla de diseño) · alternativa evaluada y
descartada en la línea base: ElastiCache para Redis (costo fijo adicional; Laravel opera con SQS y base de datos, ver
`AWS_ARCHITECTURE.md` §10).
