# Predictily · Despliegue en AWS (guía del propietario) — V2.5 · W4-C

> **Estado: BLOQUEADO. Nada de esta guía se ha ejecutado contra AWS.**
> No hay cuenta AWS del propietario. Lo único comprobado es la **verificación local sin credenciales** (§5): compilación,
> pruebas de plantilla, `cdk synth` y `cdk-nag` de ambos entornos con datos ficticios. Cada paso de §6 en adelante es un
> procedimiento **DISEÑADO** que se valida por primera vez en **staging**; los comandos pueden requerir ajustes menores al ejecutarse
> por primera vez y, si los requieren, esta guía debe corregirse en el mismo cambio. Arquitectura: `docs/architecture/AWS_ARCHITECTURE.md`;
> costos: `docs/architecture/AWS_COST_MODEL.md`; recuperación: `docs/operations/RECOVERY.md`.

Quién hace qué (maestro V2.5 §17): el **propietario de Predictily** aporta cuenta, presupuesto, dominios, certificados,
credenciales y aprobaciones; el **agente/ingeniero** aporta el código, la infraestructura como código, las pruebas y los
runbooks. Esta guía solo incluye acciones que el propietario puede ejecutar; no pide compartir credenciales con nadie.

## 1. Requisitos y bloqueos

| Requisito | Detalle | Estado |
|---|---|---|
| Cuenta AWS del propietario (idealmente **una por entorno**) | MFA en la cuenta raíz, sin claves de acceso raíz | BLOQUEADO (la aporta el propietario) |
| Presupuesto mensual | límite en USD que el propietario decide | BLOQUEADO (decisión) |
| Dominio y certificados ACM | §4 | BLOQUEADO |
| Herramientas locales | Node ≥ 20 (probado con 22), AWS CLI v2, Docker con `buildx`, el repositorio | disponibles |
| Imágenes de contenedor | backend, motor y web en ECR, con **etiqueta inmutable** (nunca `latest`) | pendiente (§7) |
| Región | `us-east-1` en esta versión (§2) | decisión del propietario sobre residencia de datos |

## 2. Datos que solo el propietario puede aportar

Se pasan con `-c clave=valor` o en `infra/aws/owner-inputs.<entorno>.json` (ignorado por git; sin secretos; plantilla en
`owner-inputs.example.json`). La síntesis **falla con la lista completa** de lo que falta o es inválido.

| Clave | Qué es | Cómo se obtiene |
|---|---|---|
| `account` | id de 12 dígitos de la cuenta del entorno | consola de AWS · `aws sts get-caller-identity` |
| `region` | `us-east-1` | decisión |
| `availabilityZones` | dos zonas de la región, p. ej. `us-east-1a,us-east-1b` | `aws ec2 describe-availability-zones --region us-east-1` |
| `appDomainName` | dominio público de la app (CloudFront), p. ej. `app.su-dominio.com` | su DNS |
| `originDomainName` | nombre DNS del ALB, p. ej. `origen.su-dominio.com` (distinto del anterior) | su DNS |
| `albCertificateArn` | certificado ACM **que cubra `originDomainName` y `appDomainName`** (SAN o comodín) en la región de la pila | §4 |
| `cloudFrontCertificateArn` | certificado ACM de CloudFront, **siempre us-east-1** | §4 |
| `cloudFrontOriginPrefixListId` | lista administrada `com.amazonaws.global.cloudfront.origin-facing` | `aws ec2 describe-managed-prefix-lists --region us-east-1 --filters Name=prefix-list-name,Values=com.amazonaws.global.cloudfront.origin-facing --query 'PrefixLists[0].PrefixListId' --output text` |
| `alertEmails` | correos de alarmas y presupuesto (deben **confirmar** la suscripción de SNS) | propietario |
| `monthlyBudgetUsd` | límite mensual de AWS Budgets | propietario (decisión; no es un precio) |
| `signingKeyId`, `trustedPublicKeys` | **obligatorios**: el motor lleva la clave privada de firma (secreto de §8), así que necesita su identificador y su clave pública de confianza (`kid:clave`, debe incluir la del `signingKeyId`) | `python -m predictily_engine.cli keygen` (§8) |
| `budgetFilterByEnvironmentTag` | `true` filtra el presupuesto por la etiqueta `predictily:environment` (solo si hay varios entornos en una cuenta; **la etiqueta debe estar activada** en Billing, hasta ~24 h; por defecto `false` = toda la cuenta) | propietario |
| `signingKeySecretName` | nombre del secreto con la semilla privada (por defecto `predictily/<entorno>/engine-signing-key`) | §8 |
| `imageTag` | etiqueta inmutable de las imágenes (p. ej. el SHA corto del commit) | §7 |

Los valores ficticios (`-c useFictitiousInputs=true`: cuenta `111111111111`, dominios `*.example.com`) son **solo para verificar la
plantilla**; con ellos la plantilla no se puede desplegar, y la síntesis con datos reales **rechaza** la cuenta ficticia.

## 3. Cuenta, rol de despliegue y presupuesto

1. Cree la cuenta del entorno (staging y producción separadas). Active MFA a la cuenta raíz y no cree claves de acceso para ella.
2. Active **IAM Identity Center** (o usuarios IAM con MFA) para las personas. Nadie despliega con la cuenta raíz.
3. Un administrador ejecuta **una sola vez** `cdk bootstrap` (§6, paso 1), que crea los roles `cdk-*` de despliegue.
4. Cree un **rol de despliegue de mínimo privilegio** para quien ejecuta `cdk deploy` o para el CI: solo puede asumir los roles
   de bootstrap `cdk-*` de **esa** cuenta (`sts:AssumeRole`), hacer `ecr:*` de subida sobre los repositorios `predictily/<entorno>/*`,
   `s3:PutObject/DeleteObject/ListBucket` sobre el bucket de la SPA, `cloudfront:CreateInvalidation` y `ecs:RunTask/DescribeTasks` sobre el
   clúster del entorno (con `iam:PassRole` solo de los roles de tarea). **Valide el JSON final con IAM Access Analyzer antes de
   usarlo**; no se incluye aquí una política completa porque no se ha podido ejercer sin cuenta (DISEÑADO).
5. **Presupuesto y costos**: la plantilla crea el presupuesto mensual con alertas al 50/80/100 % reales y 100 % previsto. Además,
   en Billing → *Cost allocation tags* active `predictily:environment` y `predictily:stack` (las aplica la plantilla a todos los
   recursos) para separar costos por entorno. Los costos **por empresa** salen de `tenant_usage_records` (`AWS_COST_MODEL.md`). El presupuesto cubre toda la cuenta; use
`budgetFilterByEnvironmentTag=true` solo si hay varios entornos en la misma cuenta **y** la etiqueta ya está activada (si no, el filtro no
ve gasto y no alerta).
6. Active **CloudTrail** en la cuenta (registro de acceso de emergencia) y **AWS Backup** si decide respaldar EFS fuera de la
   configuración automática de la plantilla. (No los crea esta plantilla: DISEÑADO.)

## 4. Dominio y certificados

1. Verifique la titularidad del dominio (`infra/scripts/deploy.md` §2 ya lo exige para el VPS).
2. En **ACM**, región **us-east-1**: solicite un certificado para `appDomainName` (CloudFront) y otro (o el mismo) que cubra
   `originDomainName` **y** `appDomainName` (el ALB). Si el ALB estuviera en otra región, ACM del ALB va en esa región (esta versión
   exige us-east-1). Valide por DNS.
3. Copie los ARN a `owner-inputs.<entorno>.json`.
4. **Después** del despliegue de la pila `App` cree dos registros DNS (la plantilla no administra Route 53): `appDomainName` →
   salida `DistributionDomainName` (CNAME/ALIAS) y `originDomainName` → salida `AlbDnsName` (CNAME/ALIAS).

## 5. Verificación local previa (sin credenciales) — IMPLEMENTADA Y PROBADA

```bash
cd infra/aws
npm ci                       # instala aws-cdk-lib, cdk-nag, jest... (solo en esta carpeta)
npm run verify               # tsc + pruebas de plantilla + cdk synth de staging y production, con cdk-nag
npm test                     # solo las pruebas (la de recuperación se omite sin RECOVERY_CHECK_DB)
```

Con **datos reales** (sin desplegar todavía): `npx cdk synth -c env=production` (lee `owner-inputs.production.json`). Si cdk-nag o
las validaciones de CloudFormation encuentran algo, la síntesis termina con error y lista los hallazgos.

## 6. Despliegue paso a paso (primero **staging**)

Todos los comandos desde `infra/aws`, con las credenciales del rol de despliegue (§3) y `-c env=staging` (o `production`).

1. **Bootstrap (una vez por cuenta y región)**: `npx cdk bootstrap aws://<cuenta>/us-east-1`.
2. **Pila de datos**: `npx cdk deploy Predictily-staging-Platform -c env=staging`. Crea red, claves, buckets, RDS, EFS, colas, repositorios
   ECR, secretos y presupuesto. Revise el resumen de cambios de IAM y de seguridad que muestra CDK antes de aprobar.
   Confirme el correo de suscripción de SNS (llega a cada dirección de `alertEmails`).
3. **Imágenes** (§7): construya, etiquete con `imageTag` y suba las tres imágenes a ECR.
4. **Secreto de firma del motor** (§8): créelo **antes** de la pila `App`.
5. **Antes de la pila de aplicación — requisito del backend**: la plantilla define `PREDICTILY_MODELS_DISK=models_s3` y las variables
   `PREDICTILY_MODELS_S3_*`, pero el disco `models_s3` **aún no existe** en `backend/config/filesystems.php` (DISEÑADO/PENDIENTE; ver
   `AWS_ARCHITECTURE.md` §10, punto 9). Mientras no exista, la aprobación de modelos fallaría en AWS.
   Tampoco está resuelta la IP real del cliente detrás de CloudFront (`AWS_ARCHITECTURE.md` §10, punto 8).
6. **Pila de aplicación**: `npx cdk deploy Predictily-staging-App -c env=staging`. Crea ECS, ALB, CloudFront, WAF, la SPA y el Scheduler.
   Si falla por una imagen o un secreto inexistente, corrija y repita: el disyuntor de ECS revierte el despliegue.
7. **Migraciones y datos base** (tareas efímeras con las salidas de la pila `App`):
   ```bash
   aws ecs run-task --cluster predictily-staging --launch-type FARGATE \
     --task-definition predictily-staging-migrate \
     --network-configuration "awsvpcConfiguration={subnets=[<JobSubnets>],securityGroups=[<JobSecurityGroup>],assignPublicIp=DISABLED}"
   aws ecs wait tasks-stopped --cluster predictily-staging --tasks <arn-de-la-tarea>
   aws ecs describe-tasks --cluster predictily-staging --tasks <arn> --query 'tasks[0].containers[0].exitCode'   # debe ser 0
   ```
   Los planes base (`php artisan db:seed --force`) y el primer superadministrador se crean con una tarea puntual sobre
   `predictily-staging-job` con `--overrides` (`containerOverrides[0].command`). El procedimiento exacto para **provisionar el
   primer superadministrador sin escribir su contraseña en la línea de comandos** está **por definir** (DISEÑADO): no la pase por
   variables de entorno visibles ni la guarde en documentos.
8. **Publicar la SPA**:
   ```bash
   cd frontend && npm ci && npm run build
   aws s3 sync dist s3://<SpaBucketName> --delete --exclude index.html --cache-control "public,max-age=31536000,immutable"
   aws s3 cp dist/index.html s3://<SpaBucketName>/index.html --cache-control "no-cache"
   aws cloudfront create-invalidation --distribution-id <DistributionId> --paths "/index.html"
   ```
   Verifique que la SPA llama a `/api` por ruta relativa (mismo origen), como con nginx.
9. **DNS** (§4, paso 4) y espera de propagación.
10. **Prueba de humo** (CloudFront solo enruta a la API `/api/*` y `/sanctum/*`; `/up` **no** pasa por CloudFront: cualquier ruta sin extensión devuelve la SPA):
   ```bash
   curl -sI https://<appDomainName>/ | head -20                                   # 200 con HSTS, CSP, X-Frame-Options
   curl -s -o /dev/null -w '%{http_code}\n' -H 'Accept: application/json' https://<appDomainName>/api/v1/models   # 401: llegó a Laravel
   curl -s -o /dev/null -w '%{http_code}\n' https://<originDomainName>/up         # 403: el ALB no es accesible sin la cabecera secreta
   ```
   Con la cabecera secreta (solo en staging; evita dejar el valor en el historial): `curl -s -o /dev/null -w '%{http_code}\n' -H "X-Origin-Verify: $(aws secretsmanager get-secret-value --secret-id predictily/staging/origin-verify --query SecretString --output text)" https://<originDomainName>/up`
   debe dar 200. Después: iniciar sesión, lanzar un pronóstico de la empresa demo y comprobar que el worker y el motor lo procesan.

## 7. Imágenes

Cada imagen se etiqueta con el mismo `imageTag` (los repositorios son inmutables: una etiqueta no se reutiliza).

```bash
aws ecr get-login-password --region us-east-1 | docker login --username AWS --password-stdin <cuenta>.dkr.ecr.us-east-1.amazonaws.com
TAG=<imageTag>; REG=<cuenta>.dkr.ecr.us-east-1.amazonaws.com/predictily/staging
docker buildx build --platform linux/amd64 -t $REG/backend:$TAG --push backend
docker buildx build --platform linux/amd64 -t $REG/engine:$TAG  --push engine
docker buildx build --platform linux/amd64 --build-context infra=./infra -t $REG/web:$TAG --push frontend
```

`linux/amd64` coincide con `cpuArchitecture: X86_64` de la plantilla; para Graviton hay que cambiar el parámetro y construir `arm64`.
ECR escanea al subir: revise los hallazgos antes de promover a producción.

## 8. Secreto de firma del motor (nunca pasa por CloudFormation)

La clave privada Ed25519 la crea el propietario **una vez** (ADR-V25-20) y se guarda cifrada con la clave KMS de la plataforma:

```bash
python -m predictily_engine.cli keygen --key-id k-2026 --private-out /ruta/segura/engine_signing_key   # imprime la clave PÚBLICA
aws secretsmanager create-secret --name predictily/staging/engine-signing-key \
  --kms-key-id alias/predictily-staging-platform --secret-string file:///ruta/segura/engine_signing_key
```

Ponga el `key_id` en `signingKeyId` y `kid:clave` pública en `trustedPublicKeys` (**ambos obligatorios**; `file://` evita que la clave privada quede en la línea de comandos o en el historial del intérprete). Guarde la privada **fuera de línea** y bórrela
del equipo local. Sin este secreto la pila `App` no se despliega (la tarea del motor no arranca). Recomendación de ADR-V25-20: usar
claves distintas para el motor en AWS y para el entrenamiento local, y listar ambas públicas en `trustedPublicKeys`.

## 9. Rotación de secretos (procedimientos manuales con redespliegue)

Las tareas leen los secretos **una vez al iniciar**; por eso no hay rotación automática (cdk-nag SMG4 reconocido con este motivo).

| Secreto | Procedimiento |
|---|---|
| `engine-api-token` | actualizar el valor en Secrets Manager → `aws ecs update-service --force-new-deployment` de `api`, `worker-*`, `scheduler` y `engine` (en ese orden; hay una breve ventana de 401 entre ellos) |
| `origin-verify` | rotación en dos fases: no admitida sin interrupción por esta plantilla (ALB y CloudFront comparten el valor); programar ventana y redesplegar `App` |
| contraseña de RDS | cambiar con `aws secretsmanager put-secret-value` **y** `ALTER USER` en la base, luego redesplegar los servicios; probar en staging primero |
| `app-key` | **no rotar** sin un procedimiento de recifrado de datos: perdería los datos cifrados; respaldarla fuera del sistema |
| clave de firma | generar un par nuevo, añadir la pública a `trustedPublicKeys`, redesplegar, firmar con la nueva; retirar la anterior solo cuando ningún artefacto vigente la use |

## 10. Operación: alarmas y colas de mensajes fallidos (DLQ)

Alarmas (SNS → correo): consulte `AWS_ARCHITECTURE.md` §8. Ante una alarma de **DLQ**:

```bash
aws sqs get-queue-attributes --queue-url <url-dlq> --attribute-names ApproximateNumberOfMessages
aws sqs receive-message --queue-url <url-dlq> --max-number-of-messages 5      # inspeccione SIN borrar; los trabajos pueden contener ids de empresa
aws sqs start-message-move-task --source-arn <arn-dlq>                         # reproceso tras corregir la causa (redrive)
```

Revise primero los registros en CloudWatch (`/predictily/<entorno>/worker-*`) y `failed_jobs`. No reprocese a ciegas: un trabajo que
falló por datos incorrectos volverá a la DLQ. Registre el incidente.

**Purga de Bronze con versionado — advertencia LOPDP.** Los buckets de datos están **versionados**. La aplicación borra con
`DeleteObject` (`predictily:bronze-purge`): en un bucket versionado eso solo crea una *marca de borrado*; el contenido original **sigue
existiendo como versión no vigente** hasta que la regla de ciclo de vida la expira (`noncurrentVersionDays.bronze` = 30 días,
`environments.ts`). Hasta entonces el dato sigue recuperable y almacenado: una solicitud de supresión de datos personales (LOPDP) o un contrato con
borrado inmediato **no se cumple** con la purga actual. Opciones, a decidir por el propietario: *(a)* bajar `noncurrentVersionDays.bronze`
(p. ej. a 1 día) y documentar el plazo; *(b)* cambiar `FilesystemBronzeStore::purge` para borrar también las versiones, lo que exigiría
conceder `s3:DeleteObjectVersion` y `s3:ListBucketVersions` **solo al rol del programador** (no se concede hoy: la aplicación no lo usa y daría
al rol la capacidad de destruir datos de forma irrecuperable sin necesidad); *(c)* Object Lock en modo gobernanza (DISEÑADO; choca con la
supresión por empresa). Estado: **DISEÑADO / PENDIENTE de decisión**.

## 11. Despliegues posteriores y reversión

- **Nueva versión**: construir y subir imágenes con una etiqueta nueva → `npx cdk deploy Predictily-<entorno>-App -c env=<entorno> -c imageTag=<nueva>`
  → ejecutar la tarea de migraciones **antes** de que el tráfico use el código nuevo si hay migraciones → publicar la SPA.
- **Revertir el código**: `cdk deploy ... -c imageTag=<anterior>` (las imágenes son inmutables y siguen en ECR). ECS revierte solo si el
  despliegue nuevo no se estabiliza (disyuntor).
- **Cambiar o retirar un recurso de `Platform` que `App` consume (referencias «strong»).** CloudFormation no permite modificar ni quitar un
  export mientras otra pila lo importe (error *Cannot delete export ... as it is in use*). Procedimiento de dos pasos: *(1)* desplegar
  `Predictily-<entorno>-App` **sin** la referencia (p. ej., quitando el uso en el código o apuntándolo a un valor constante); *(2)*
  desplegar `Predictily-<entorno>-Platform` con el cambio; *(3)* si procede, volver a añadir la referencia y desplegar `App`. En la práctica
  las referencias son pocas (grupos de seguridad, subredes, claves, ARN de secretos, colas, EFS): `App` no importa el endpoint de RDS (usa
  la clave `host` del secreto) ni los nombres de bucket (son deterministas). Si la CLI de CDK instalada soporta referencias «weak»
  (`Fn::GetStackOutput`), puede evaluarse cambiar la bandera de `cdk.json` con el procedimiento oficial «strong → both → weak»; no se
  usa porque no pudo verificarse sin cuenta.
- **Revertir una migración destructiva**: restaurar la base (`RECOVERY.md` §6). Haga un snapshot manual de RDS **antes** de migrar en producción.

## 12. Baja de un entorno

Los recursos de datos tienen `RETAIN` a propósito (claves KMS, buckets, EFS, registros, secretos de la app, repositorios ECR) y RDS
toma un snapshot final. Tras `cdk destroy` hay que eliminar a mano lo retenido que ya no se necesite (con aprobación del
propietario: es una acción irreversible). Nunca destruir `production` sin copia verificada de `app-key` y de los respaldos.

## 13. Estado por paso

| Paso | Estado |
|---|---|
| §5 verificación local (tsc, pruebas, synth, cdk-nag) | **IMPLEMENTADO/VERIFICADO** |
| §2 datos del propietario (validación de entradas) | IMPLEMENTADO/VERIFICADO (`config.test.ts`) |
| §3 cuenta, rol de despliegue, CloudTrail, Backup | DISEÑADO · BLOQUEADO |
| §4 dominio y certificados | BLOQUEADO |
| §6 bootstrap, `cdk deploy` de ambas pilas | BLOQUEADO (sin cuenta) |
| §6.7 migraciones por `RunTask`; primer superadministrador | DISEÑADO · BLOQUEADO |
| §7 imágenes a ECR | DISEÑADO · BLOQUEADO |
| Disco `models_s3` del backend; IP real del cliente detrás de CloudFront | DISEÑADO / PENDIENTE (previos al despliegue real) |
| §10 purga definitiva de Bronze (versionado) | DISEÑADO / PENDIENTE de decisión |
| §8 secreto de firma | DISEÑADO · BLOQUEADO |
| §9 rotación de secretos | DISEÑADO (manual) |
| §10 reproceso de DLQ | DISEÑADO · BLOQUEADO |
| E2E en staging con 2 empresas, pruebas de aislamiento, latencia y costo reales (T09/T10) | **BLOQUEADO** |
| CI/CD | NO IMPLEMENTADO |
