# Predictily · Arquitectura en AWS (V2.5 · W4-C)

> **Estado global: la infraestructura está ESCRITA y VERIFICADA POR SÍNTESIS, NO DESPLEGADA.**
> No existe cuenta AWS del propietario: todo despliegue real es **BLOQUEADO** (ver `docs/operations/AWS_DEPLOYMENT.md`).
> Lo que se afirma aquí como verificado lo demuestran `cdk synth`, `cdk-nag` y las pruebas de plantilla de `infra/aws/test/`
> (ver §11). Nada de lo que depende de AWS real (latencias, costos reales, comportamiento de ECS/EFS/CloudFront, restauraciones
> de RDS) está probado y se marca así.

Decisiones de origen: ADR-V25-09 (AWS CDK en TypeScript, Single-AZ inicial con RTO/RPO y criterio para Multi-AZ),
ADR-V25-08 (almacén de objetos con claves del servidor y prefijo de empresa), ADR-V25-20 (claves de firma), §14–§15 del
documento maestro V2.5. Costos: `docs/architecture/AWS_COST_MODEL.md`. Recuperación: `docs/operations/RECOVERY.md`.

## 1. Resumen

- **IaC**: app AWS CDK v2 en TypeScript (`infra/aws`), con `package.json` y `node_modules` propios. Dos pilas por entorno
  (`staging`, `production`): `Predictily-<entorno>-Platform` (red, claves, datos) y `Predictily-<entorno>-App` (cómputo, borde,
  programación). La de aplicación depende de la de datos, nunca al revés.
- **Sin credenciales para verificar**: `npm run verify` ejecuta `tsc`, 153 pruebas de plantilla y de configuración (Jest; otras 9, de recuperación local, requieren PostgreSQL) y `cdk synth` de ambos
  entornos con `cdk-nag` (AwsSolutions) y las validaciones de CloudFormation de CDK. Se usan **datos FICTICIOS** (cuenta
  `111111111111`, región `us-east-1`, dominios `example.com`, ARN de certificado de mentira, `-c useFictitiousInputs=true`).
  Una plantilla sintetizada así **no es desplegable**; sirve para verificar la forma de la infraestructura.
- **Sin GPU, sin EC2 permanentes**: todo el cómputo es Fargate de CPU. El entrenamiento pesado sigue siendo local/privado
  (ADR-V25-19) y se publica como artefacto firmado.
- **Región**: la versión actual exige `us-east-1` (el WAF de CloudFront y su certificado viven allí). Otras regiones exigen un
  `EdgeStack` multirregión: DISEÑADO, no implementado. La región definitiva es decisión del propietario (residencia de datos).

## 2. Diagrama

```
 Usuarios ──HTTPS──► CloudFront (WAF, TLS 1.2+, cabeceras de seguridad, registros)
                       │  /*  (SPA, rutas sin extensión → /index.html)        /api/* · /sanctum/*  (sin caché)
                       ▼                                                      │  cabecera secreta X-Origin-Verify
                 S3 privado de la SPA (OAC)                                    ▼
                                                      ┌──────────────── VPC (2 AZ) ─────────────────────────────┐
              subredes públicas ─────────────────────►│ ALB :443 (solo prefijo de CloudFront; 403 por defecto)  │
                                                      │   └► Tarea API: nginx + php-fpm (127.0.0.1:9000)         │
              subredes privadas (salida por 1 NAT) ───┤ Workers SQS (imports/default · forecasting) · Programador│
                                                      │ Motor FastAPI :8100 (solo desde API/workers, Cloud Map)  │
                                                      │ Tareas efímeras (migraciones · EventBridge Scheduler)    │
              subredes aisladas (sin ruta a Internet)─┤ RDS PostgreSQL 16 (Single-AZ, cifrado, no público) · EFS │
                                                      └───────────────────────────────────────────────────────────┘
   S3 (Bronze · Silver · Gold · Artefactos; versionado; SSE-KMS)  ·  SQS (+DLQ por cola)  ·  Secrets Manager  ·  KMS ×2
   CloudWatch (logs cifrados, alarmas → SNS → correo)  ·  AWS Budgets  ·  ECR ×3 (etiquetas inmutables, escaneo al subir)
```

## 3. Recursos por pila

**Platform** (`infra/aws/lib/platform-stack.ts`): 2 claves KMS con rotación (datos; plataforma) · VPC `/16` con subredes
públicas, privadas con salida y aisladas, 1 NAT (parámetro), endpoint de pasarela de S3, registros de flujo · 6 grupos de
seguridad · 5 buckets (registros de acceso + `bronze`, `silver`, `gold`, `artifacts`) · RDS PostgreSQL 16 + grupo de parámetros
(`rds.force_ssl=1`) + grupos de log · EFS cifrado con 2 puntos de acceso · 3 colas SQS (`imports`, `default`, `forecasting`) con su
DLQ · 3 repositorios ECR · 3 secretos generados (clave de la app, token del motor, cabecera de origen) + secreto de RDS · tema SNS
cifrado con suscripciones por correo · 9 alarmas (RDS y 3×2 de colas) · presupuesto mensual de AWS Budgets.

**App** (`infra/aws/lib/app-stack.ts`): clúster ECS + espacio de nombres privado de Cloud Map · 7 definiciones de tarea
(`api`, `worker-default`, `worker-forecasting`, `scheduler`, `engine`, `migrate`, `job`) y 5 servicios Fargate · ALB público
(solo desde CloudFront) · bucket privado de la SPA + distribución CloudFront (OAC, función de reescritura, política de cabeceras)
+ WAF · rol ABAC por empresa (`TenantDataAccessRole`) · EventBridge Scheduler (grupo, DLQ, rol, programación) · 15 alarmas
(ALB, latencia, destinos no saludables, CloudFront, Scheduler y CPU/memoria por servicio).

## 4. Red y exposición (qué es público y qué no)

| Componente | Exposición | Control |
|---|---|---|
| CloudFront | Internet (443) | WAF (límites de tasa que bloquean; reglas administradas en `count` al arrancar), TLS 1.2+, HTTPS obligatorio |
| ALB | Internet, **pero** solo acepta 443 desde la lista de prefijos administrada de CloudFront y exige la cabecera secreta | grupo de seguridad sin 0.0.0.0/0 de entrada; respuesta 403 por defecto |
| API / workers / motor | Privado (subredes con salida por NAT) | grupos de seguridad por rol; el motor solo recibe 8100 desde API y workers; sin IP pública |
| RDS | **Privado y aislado** (sin ruta a Internet ni a NAT) | 5432 solo desde API, workers y motor; TLS obligatorio; sin acceso público |
| EFS | Privado (subredes aisladas) | 2049 solo desde API, workers y motor; TLS en tránsito; IAM por punto de acceso; la política del sistema de archivos solo contiene denegaciones (sin punto de acceso o sin TLS) y ningún Allow a `*` |
| S3 / SQS / ECR | Servicios de AWS | bloqueo de acceso público total, TLS obligatorio, SSE-KMS |

Notas honestas: la **salida a Internet** del tráfico privado pasa por **un único NAT** (punto único de fallo y tráfico entre AZ;
una NAT por AZ llega con Multi-AZ). Los endpoints de interfaz (ECR, Logs, Secrets Manager, SQS, KMS) existen como parámetro
(`interfaceEndpoints`) pero están apagados: cuestan por hora y por AZ (ver costo). El tráfico interno ALB→API y API→motor es
HTTP dentro de la VPC (igual que Compose); TLS interno/mTLS es DISEÑADO.

## 5. Aislamiento por empresa

Capas, de la más a la menos implementada:

1. **Claves de objeto del servidor con prefijo de empresa** (`tenants/<uuid>/bronze/...`, `tenants/<uuid>/models/...`; ADR-V25-08,
   código de W2-B/W4-B). Las tareas solo pueden tocar `tenants/*` de **su** capa (IMPLEMENTADO en la plantilla; probado en
   `app.test.ts`: el motor no toca Bronze ni SQS; la API no puede borrar de Bronze; workers y programador sí, para la purga).
   Todo rol que lee objetos de una capa recibe también `s3:ListBucket` y `s3:GetBucketLocation` sobre **el bucket** (no sobre los
   objetos): en S3, `HeadObject`/`GetObject` de una clave inexistente devuelve 403 si el rol no puede listar, y
   `FilesystemBronzeStore::exists()` antes de escribir (o las verificaciones de artefactos y la foto de almacenamiento) fallarían. La
   condición es `ForAllValues:StringLike` sobre `s3:prefix = tenants/*`: con `StringLike` a secas la sentencia no aplicaría a
   `HeadObject` (no lleva `s3:prefix`). Una prueba exige esta propiedad para cada política de la pila. Salvedad: el rol ABAC
   (punto 3) conserva `StringLike` estricto (para no listar a otras empresas), de modo que con él un `HeadObject` de clave inexistente
   daría 403; la aplicación deberá tratarlo como «no existe» al integrarlo.
2. **Roles de tarea separados** por servicio (API, worker-default, worker-forecasting, programador/efímeras, motor), con la
   política mínima. Solo los workers reciben mensajes; la API únicamente encola.
3. **Rol ABAC por empresa** (`predictily-<entorno>-tenant-data-access`): la aplicación lo asumiría con la etiqueta de sesión
   `tenant_id` (UUID) y obtendría solo `tenants/${aws:PrincipalTag/tenant_id}/*` en Bronze y artefactos. **IMPLEMENTADO en la
   plantilla y verificado por síntesis; NO integrado**: ni el backend ni el motor llaman hoy a `sts:AssumeRole`. Hasta entonces el
   aislamiento entre empresas depende del código (claves del servidor, `BelongsToTenant`, FKs compuestas).
4. **Secretos**: un único conjunto por entorno (no por empresa): la base y el motor se comparten en la plataforma; el aislamiento
   de datos entre empresas en PostgreSQL es de aplicación (`tenant_id`, FKs compuestas, ámbito global fail-closed), no de roles
   de base. Roles de base de datos por servicio con privilegios mínimos (V25-AWS-034): **DISEÑADO, no implementado**.
5. **Colas y cachés**: las colas SQS son por tipo de trabajo, no por empresa (el `tenant_id` viaja en el trabajo; la
   contaminación cruzada se prueba en la aplicación, W4-B). Sesiones y caché de Laravel van a las tablas `sessions` y `cache` de la
   base de datos: **no tienen columna `tenant_id`** (la sesión se liga al usuario y la caché es clave/valor); que las claves de caché
   incluyan la empresa y que un job de una empresa no lea la caché de otra es una propiedad **de la aplicación** (pruebas negativas
   de W4-B), no de esta infraestructura.
6. **Separación entre entornos**: pilas, claves, secretos, buckets y repositorios distintos por entorno; se recomienda **una
   cuenta AWS por entorno** (rol de despliegue distinto). Nunca copiar datos reales de clientes a staging.

## 6. Base de datos: Single-AZ, RTO/RPO y criterio para Multi-AZ

Producción arranca **Single-AZ** (ADR-V25-09, `database.multiAz=false`). Eso significa: un fallo de la zona o de la instancia
interrumpe el servicio hasta restaurar; no hay conmutación automática. Es una limitación **aceptada y explícita**, no un olvido
(`cdk-nag` RDS3 reconocido con ese motivo).

| Objetivo | Valor propuesto | Estado |
|---|---|---|
| RPO (pérdida máxima de datos) | ≤ 15 min con restauración a un punto en el tiempo (PITR) de RDS | DISEÑADO · a aprobar por el propietario · la cifra real se mide en el primer simulacro |
| RTO (tiempo de recuperación) | ≤ 4 h (el mismo objetivo de `infra/scripts/deploy.md` para el VPS) | DISEÑADO · a aprobar · a medir en simulacro (la restauración crea una instancia nueva) |
| Retención de respaldos automáticos | staging 7 días · production 14 días (parámetro) | IMPLEMENTADO en la plantilla (`BackupRetentionPeriod`, verificado por síntesis) |

**Criterio para pasar a Multi-AZ** (`database.multiAz=true` + un NAT por AZ), cualquiera de estos disparadores, a aprobar en el
piloto: (a) un contrato con disponibilidad comprometida mayor que la que da un RTO de horas; (b) el RTO medido en simulacro
supera el RTO objetivo; (c) el costo de una hora de indisponibilidad para los clientes en producción supera el costo mensual
adicional de Multi-AZ (ver `AWS_COST_MODEL.md`); (d) un incidente real de zona o de instancia. Ningún umbral numérico es
universal: se fijan con datos del piloto.

## 7. Seguridad y cdk-nag

- Cifrado: S3 (SSE-KMS con clave de bucket, salvo registros y SPA, que usan SSE-S3 por exigencia del servicio o para evitar una
  dependencia circular), RDS, EFS, SQS, SNS, Secrets Manager, ECR y CloudWatch Logs con KMS; rotación de claves activa.
- Sin acceso público: las cuatro opciones de bloqueo en todos los buckets; política que rechaza el tráfico sin TLS.
- Secretos: generados por Secrets Manager (la plantilla no contiene ningún valor), inyectados como `secrets` de ECS (nunca como
  variables de entorno). La **clave privada de firma Ed25519 del motor** no se crea en CloudFormation: la crea el propietario
  (`AWS_DEPLOYMENT.md` §6). El serving en AWS solo necesita claves públicas (`ENGINE_TRUSTED_PUBLIC_KEYS`).
- `cdk-nag` v3 (AwsSolutions) corre en cada síntesis y **interrumpe** si hay un hallazgo sin reconocer. Los reconocimientos (con
  motivo, uno por regla y construcción) son: CFR1 (sin restricción geográfica: decisión comercial), ECS2 (la regla señala todo uso de
  `environment`; solo hay configuración no sensible; reconocida **solo en el recurso de la definición de tarea**), ECS4 (staging:
  Container Insights apagado por costo), IAM5 (**acotado a la política concreta con el comodín**, nunca a una tarea o rol completos:
  `tenants/*` solo en la política del rol de tarea que lo concede y solo para las capas que toca; `Resource::*` solo en la política del
  rol de ejecución, por `ecr:GetAuthorizationToken`; rol ABAC; `RunTask` por familia y `ecs:TagResource` del Scheduler), RDS3 (Single-AZ),
  RDS6 (autenticación IAM de base de datos no soportada por la app), RDS10 (solo staging), RDS11 (puerto por defecto, protegido por
  grupo de seguridad), S1 (buckets que son destino de registros), SMG4 (rotación manual con redespliegue, §9 de
  `AWS_DEPLOYMENT.md`), SQS3 (la DLQ del Scheduler). `test/nag.test.ts` falla si aparece una supresión nueva o si alguna
  no tiene motivo, y comprueba que las reglas críticas (cifrado, TLS, acceso público, DLQ, WAF, OAC) **no** están reconocidas.
- WAF: límites de tasa que **bloquean** (autenticación y general) y tres grupos de reglas administradas de AWS en modo `count`
  (pasan a `block` tras revisar en el piloto las solicitudes muestreadas; `SizeRestrictions_BODY` queda en `count` siempre porque
  las cargas CSV/Excel llegan a 25 MB). Registro completo de WAF: DISEÑADO (decisión sobre datos personales en los registros).

## 8. Observabilidad

Registros de contenedores, flujo de VPC y de RDS en CloudWatch Logs (KMS, retención 30/90 días según entorno). Alarmas → SNS →
correo (la suscripción exige confirmar el correo): DLQ con ≥ 1 mensaje, antigüedad del mensaje más viejo por cola, CPU/almacenamiento
/memoria de RDS, 5xx del ALB, latencia p95, destinos no saludables, 5xx de CloudFront, fallos del Scheduler y CPU/memoria por
servicio. Umbrales iniciales **a calibrar con datos del piloto** (no hay SLO real todavía; §17 T09 del maestro: «no inventar SLA
universal»). Tableros, trazas distribuidas y alarmas por empresa: DISEÑADO. El presupuesto de AWS Budgets cubre **toda la cuenta** por defecto (lo correcto
con una cuenta por entorno); con varios entornos en una cuenta puede filtrarse por la etiqueta `predictily:environment`
(`budgetFilterByEnvironmentTag=true`), pero solo funciona si la etiqueta está **activada** en Billing (tarda hasta ~24 h): mientras no lo
esté, el filtro no ve gasto y las alertas no avisan.

## 9. Parámetros por entorno (puntos de partida a validar en el piloto)

Viven en `infra/aws/lib/config/environments.ts`; **ninguno es un precio ni una promesa de capacidad**.

| Parámetro | staging | production | Cómo se valida |
|---|---|---|---|
| RDS | `db.t4g.small`, 50 GiB (máx. 200), respaldo 7 d | `db.t4g.medium`, 100 GiB (máx. 500), respaldo 14 d, protección contra borrado | CPU/memoria/IOPS reales con datos piloto |
| API (nginx + php-fpm) | 1 tarea 0,5 vCPU / 1 GiB | 2–4 tareas 0,5 vCPU / 1 GiB (escala por CPU 60 %) | latencia p95 y CPU bajo carga |
| Worker default / forecasting | 0,5 vCPU / 2 GiB · 0,25 vCPU / 1 GiB | igual | profundidad de cola y antigüedad |
| Motor | 2 vCPU / 4 GiB (≈ `ENGINE_MEMORY_LIMIT=4g` de Compose) | igual | duración de ejecuciones y memoria máxima |
| Programador | 0,25 vCPU / 0,5 GiB, 1 tarea | igual | — |
| NAT | 1 | 1 (uno por AZ con Multi-AZ) | tráfico por NAT |
| Arquitectura de CPU | x86_64 | x86_64 | ARM64 (Graviton) es más barato pero exige imágenes `arm64` y validar dependencias nativas |
| Retención de logs | 30 días | 90 días | política de datos del propietario |
| WAF | límites 2000/100 por IP y 5 min; reglas en `count` | igual | muestras reales |
| Alarmas | ver §8 | igual | calibrar con 2–4 semanas de datos |

## 10. Adaptaciones aplicación ↔ AWS (hallazgos que el equipo debe conocer)

Compose y AWS no son equivalentes uno a uno. Lo siguiente se resolvió **en la plantilla** (sin tocar la aplicación), pero cada
punto es una hipótesis **sin ejercer en AWS real**:

1. **El almacenamiento local es compartido entre contenedores.** `ImportService` usa rutas locales (`Storage::path`) que comparten
   la API (subida) y los workers (procesamiento), y el backend verifica en solo lectura los artefactos que escribe el motor.
   En ECS no hay volúmenes compartidos entre tareas: se usa **EFS** (dos puntos de acceso: `/backend-storage` uid 33 y
   `/engine-artifacts` uid 10001). Se retira cuando importaciones y artefactos usen únicamente el almacén de objetos (W4-B).
2. **nginx habla con php-fpm por el nombre `backend:9000`.** En una tarea `awsvpc` ese nombre no existe: el contenedor `web` mapea
   `backend` a `127.0.0.1` en `/etc/hosts` antes de arrancar nginx (`command` de la tarea). Alternativa más limpia: que la imagen
   `web` tome el *upstream* de una variable de entorno; es un cambio fuera de este paquete.
3. **Redis → SQS + base de datos.** Compose usa Redis para colas, caché y sesiones. La línea base de AWS usa `QUEUE_CONNECTION=sqs`
   (con `SQS_SUFFIX=-predictily-<entorno>`, así `onQueue('imports')` resuelve a `imports-predictily-<entorno>`), `CACHE_STORE=database`
   y `SESSION_DRIVER=database`. **No hay ElastiCache** (costo fijo adicional; alternativa documentada en el modelo de costos). El
   comportamiento de reintentos de `queue:work sqs` con `--tries=2` y la DLQ no está probado contra SQS real: BLOQUEADO.
4. **Variables de entorno nuevas para la aplicación**: `DB_SSLMODE=require` (RDS fuerza TLS), `TRUSTED_PROXIES` = CIDR de la VPC,
   `SESSION_DOMAIN`/`SANCTUM_STATEFUL_DOMAINS` = dominio de la aplicación, `PREDICTILY_BRONZE_DISK=bronze_s3` con bucket, región,
   SSE=`aws:kms` y la clave de datos. `DB_HOST` llega por `secrets` desde la clave `host` del secreto de RDS (no de un export del
   endpoint). Las variables del almacén de artefactos **del motor** (W4-B) **no existen aún**: pendiente. Para el backend se define
   `PREDICTILY_MODELS_DISK=models_s3` + `PREDICTILY_MODELS_S3_{BUCKET,REGION,SSE,KMS_KEY_ID}` (el bucket de artefactos): ver el punto 9.
5. **`ENGINE_DATABASE_URL` se compone al arrancar** (el motor solo lee la URL completa): el `command` de la tarea reproduce el
   `CMD` de `engine/Dockerfile` y arma la URL con la contraseña alfanumérica del secreto. Si cambia el `CMD`, hay que actualizar la tarea.
6. **El motor firma artefactos** (ADR-V25-20 prefiere clave privada solo en el entorno de entrenamiento autorizado, pero el motor
   actual entrena y firma). La plantilla lo soporta con un secreto creado por el propietario; el ideal (entrenamiento solo local)
   exigiría `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=false` y claves públicas en AWS únicamente.
7. **Entrada única**: SPA y `/api` bajo el mismo dominio por CloudFront (cookies de Sanctum); el certificado del ALB debe cubrir el
   dominio de origen **y** el de la aplicación (SAN o comodín) porque CloudFront reenvía el `Host` original.
8. **IP real del cliente detrás de CloudFront — adaptación PENDIENTE (no resuelta).** `infra/nginx/default.conf` confía en
   `10.0.0.0/8` con `real_ip_header X-Forwarded-For` y `real_ip_recursive on`. Con la cadena cliente → CloudFront → ALB → nginx, el
   `X-Forwarded-For` que llega es `<cliente>, <IP de borde de CloudFront>` (el ALB añade la IP de borde): nginx descarta la IP privada
   del ALB pero se queda con la **IP de borde de CloudFront**, que es pública y no está en su lista de confianza. Consecuencias: el
   límite de peticiones de nginx (`limit_req`, por IP), el `request()->ip()` de Laravel (auditoría y *throttling*) y `TRUSTED_PROXIES`
   (hoy el CIDR de la VPC) verían IP de borde compartidas por muchos usuarios, no la del cliente. El WAF de CloudFront sí limita por IP
   real. **Solución propuesta** (requiere tocar `infra/nginx` o el middleware de Laravel; lo decide el arquitecto): *(a)* derivar la IP del
   encabezado `CloudFront-Viewer-Address` (`ip:puerto`), que CloudFront fija y sobrescribe —no lo puede falsificar el visitante— y que el
   ALB solo recibe de CloudFront (lista de prefijos + cabecera secreta); en nginx, un `map` que quite el puerto y alimente `$remote_addr` o
   `real_ip_header`; o *(b)* confiar en la entrada que CloudFront añade al `X-Forwarded-For` agregando los rangos de CloudFront (prefijo
   administrado / `ip-ranges.json`) a `set_real_ip_from` y a `TRUSTED_PROXIES`. **No** usar `TRUSTED_PROXIES=**` (confiaría en entradas
   que el visitante puede inyectar). Lo único hecho en la plantilla: el origen `/api/*` usa la política de solicitud
   `AllViewerAndCloudFrontHeaders` (reenvía `CloudFront-Viewer-Address` y demás `CloudFront-*`), y `TRUSTED_PROXIES` sigue siendo el
   CIDR de la VPC. Estado: **DISEÑADO / PENDIENTE**; hasta entonces no se debe confiar en límites por IP de la aplicación en AWS.
9. **Disco de modelos del backend — PENDIENTE (DISEÑADO).** `backend/config/mlops.php` lee `published_disk` de `PREDICTILY_MODELS_DISK`
   (con respaldo al disco de Bronze), pero `backend/config/filesystems.php` **no tiene** un disco S3 de artefactos: sin él, los modelos se
   publicarían en el bucket de Bronze. La plantilla ya define `PREDICTILY_MODELS_DISK=models_s3` y las variables
   `PREDICTILY_MODELS_S3_*` (nombres propuestos, misma convención que `PREDICTILY_BRONZE_S3_*`), apuntando al bucket de artefactos y a la
   clave de datos. **Hasta que exista el disco `models_s3` (W4-B o el arquitecto), el despliegue real no debe arrancar con esa variable
   apuntando a un disco inexistente**: crear el disco, o quitar la variable (se vuelve al disco de Bronze, que no es lo deseado).
10. **Referencias entre pilas «strong» (`Fn::ImportValue`).** `cdk.json` fija `@aws-cdk/core:defaultCrossStackReferences = strong` para
    no depender de `Fn::GetStackOutput`, que no se pudo comprobar. Con exportaciones fuertes CloudFormation **impide cambiar o quitar
    un export mientras la pila `App` lo use**. Para cambiar o retirar un recurso de `Platform` que `App` consume: *(1)* desplegar `App`
    **sin** la referencia, *(2)* desplegar `Platform` con el cambio, *(3)* volver a añadir la referencia a `App` si procede
    (`AWS_DEPLOYMENT.md` §11). Para reducir el acoplamiento, `App` no importa el endpoint de RDS (usa la clave `host` del secreto) ni
    los nombres de bucket (son deterministas).
11. **Hipótesis sin verificar en AWS real**: `ecs:TagResource` para el rol del Scheduler (RunTask con `propagateTags` lo exige a quien
    lanza la tarea; se concede acotado al clúster y a `ecs:CreateAction=RunTask`), el formato del filtro por etiqueta de AWS Budgets, la
    sintaxis de `containerOverrides` del `Input` del Scheduler y el comportamiento de `queue:work sqs` frente a la DLQ.

## 11. Estado por requisito

| Requisito (ADR-V25-09 · §14) | Estado | Evidencia |
|---|---|---|
| IaC en CDK/TypeScript, `package.json` propio | IMPLEMENTADO/VERIFICADO | `infra/aws/`; `npm run verify` |
| `cdk synth` sin credenciales (ambos entornos) | IMPLEMENTADO/VERIFICADO | `npm run synth` (datos ficticios) |
| `cdk-nag` AwsSolutions sin errores; supresiones justificadas | IMPLEMENTADO/VERIFICADO | `test/nag.test.ts` (15 pruebas) |
| VPC privada; RDS privado y aislado | IMPLEMENTADO/VERIFICADO | `platform.test.ts` («vive en subredes AISLADAS», «no es público») |
| S3 versionado, SSE-KMS, sin acceso público | IMPLEMENTADO/VERIFICADO | `platform.test.ts` (S3) |
| SQS + DLQ por cola, cifrado, alarma de DLQ | IMPLEMENTADO/VERIFICADO | `platform.test.ts` (SQS, alarmas) |
| Secretos en Secrets Manager; ninguno en la plantilla | IMPLEMENTADO/VERIFICADO | `platform.test.ts`, `app.test.ts` |
| AWS Budgets con alertas | IMPLEMENTADO/VERIFICADO | `platform.test.ts` («AWS Budgets») |
| ECS Fargate (API, workers, motor), sin GPU | IMPLEMENTADO/VERIFICADO | `app.test.ts` (cómputo) |
| S3 + CloudFront para la SPA (OAC, WAF) | IMPLEMENTADO/VERIFICADO | `app.test.ts` (borde) |
| EventBridge Scheduler | IMPLEMENTADO/VERIFICADO | `app.test.ts` (Scheduler) |
| Parámetros por entorno (staging/production) | IMPLEMENTADO/VERIFICADO | `config.test.ts`, `describe.each` en las pruebas |
| Aislamiento por prefijo y por rol de tarea | IMPLEMENTADO/VERIFICADO (plantilla) | `app.test.ts` (permisos) |
| Rol ABAC por empresa | IMPLEMENTADO (plantilla) · **integración con la app: DISEÑADO** | `app.test.ts` (ABAC) |
| `ListBucket` en todo rol que lee objetos (404 en vez de 403) | IMPLEMENTADO/VERIFICADO (plantilla) · comportamiento real de S3: BLOQUEADO | `app.test.ts` («[HIGH] todo rol con GetObject…») |
| Permisos EFS de cada tarea = sus puntos de acceso montados; migraciones sin EFS | IMPLEMENTADO/VERIFICADO (plantilla) | `app.test.ts` («[HIGH] cada definición de tarea con volúmenes EFS…») |
| Reconocimientos de cdk-nag acotados a la política concreta | IMPLEMENTADO/VERIFICADO | `nag.test.ts` («[MEDIUM] … acotados») |
| IP real del cliente detrás de CloudFront (nginx/Laravel) | **DISEÑADO / PENDIENTE** (§10, punto 8) | — |
| Disco S3 `models_s3` en `backend/config/filesystems.php` | **DISEÑADO / PENDIENTE** (backend, §10, punto 9) | variable definida en la plantilla (`app.test.ts`) |
| Restauración local de PostgreSQL | IMPLEMENTADO/VERIFICADO | `RECOVERY.md` §4; `test/recovery.test.ts` |
| Despliegue en AWS (plan/apply, staging, E2E) | **BLOQUEADO** (sin cuenta) | `AWS_DEPLOYMENT.md` |
| Latencia, costo real, simulacro de restauración en RDS, conmutación | **BLOQUEADO** | — |
| Multi-AZ; EdgeStack multirregión; CI/CD; autoescalado de workers; mTLS interno; rotación automática de secretos; restricción geográfica; registro completo de WAF; roles de base de datos por servicio; endpoints de interfaz activos; Route 53 | DISEÑADO / NO IMPLEMENTADO | §12 |
| GPU permanente en AWS | NO APLICA (prohibido por diseño) | — |

## 12. Diseñado y no implementado

Multi-AZ (RDS + un NAT por AZ) · EdgeStack en `us-east-1` para otras regiones · CI/CD con *deploy preflight* y despliegue azul/verde ·
autoescalado de workers por profundidad de cola · TLS entre ALB↔API y API↔motor · rotación automática de secretos (requiere que
la aplicación recargue credenciales) · restricción geográfica · registro completo de WAF con redacción de cabeceras · roles de base
de datos por servicio · integración de la aplicación con el rol ABAC por empresa · registros DNS en Route 53 (hoy son pasos
manuales del propietario) · apagado nocturno de staging con EventBridge Scheduler · Fargate Spot para el worker de pronósticos ·
entrenamiento aislado en AWS con presupuesto (solo si se justifica; ver `docs/ml/AUTOMATED_TRAINING.md`).
