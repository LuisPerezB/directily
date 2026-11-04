import { CfnOutput, Duration, RemovalPolicy, Stack, StackProps, Tags } from 'aws-cdk-lib';
import * as acm from 'aws-cdk-lib/aws-certificatemanager';
import * as cloudfront from 'aws-cdk-lib/aws-cloudfront';
import * as origins from 'aws-cdk-lib/aws-cloudfront-origins';
import * as cloudwatch from 'aws-cdk-lib/aws-cloudwatch';
import * as cwActions from 'aws-cdk-lib/aws-cloudwatch-actions';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as ecs from 'aws-cdk-lib/aws-ecs';
import * as elbv2 from 'aws-cdk-lib/aws-elasticloadbalancingv2';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as logs from 'aws-cdk-lib/aws-logs';
import * as s3 from 'aws-cdk-lib/aws-s3';
import * as scheduler from 'aws-cdk-lib/aws-scheduler';
import * as secretsmanager from 'aws-cdk-lib/aws-secretsmanager';
import * as servicediscovery from 'aws-cdk-lib/aws-servicediscovery';
import * as sqs from 'aws-cdk-lib/aws-sqs';
import * as wafv2 from 'aws-cdk-lib/aws-wafv2';
import { Construct } from 'constructs';
import { ackAll } from './nag';
import { EnvironmentConfig, TaskSizing } from './config/environments';
import { OwnerInputs } from './config/inputs';
import { DataBucketName, QUEUE_NAMES, QueueName, dataBucketName, sqsSuffix, tenantObjectsArn } from './names';
import { PlatformStack } from './platform-stack';

export interface AppStackProps extends StackProps {
  readonly config: EnvironmentConfig;
  readonly inputs: OwnerInputs;
  readonly platform: PlatformStack;
}

const ENGINE_PORT = 8100;

/**
 * Pila de APLICACIÓN: cómputo (ECS Fargate), borde (ALB + CloudFront + WAF), SPA en S3, tareas programadas
 * (EventBridge Scheduler) y alarmas de servicio. Depende de PlatformStack (datos); nunca al revés.
 */
export class AppStack extends Stack {
  readonly cluster: ecs.Cluster;
  readonly distribution: cloudfront.Distribution;
  readonly loadBalancer: elbv2.ApplicationLoadBalancer;
  readonly services: Record<'api' | 'workerDefault' | 'workerForecasting' | 'scheduler' | 'engine', ecs.FargateService>;
  readonly taskRoles: Record<'api' | 'workerDefault' | 'workerForecasting' | 'scheduler' | 'engine' | 'jobs', iam.IRole>;
  readonly tenantDataAccessRole: iam.Role;
  readonly spaBucket: s3.Bucket;

  constructor(scope: Construct, id: string, props: AppStackProps) {
    super(scope, id, props);
    const { config: cfg, inputs, platform } = props;
    const env = cfg.name;
    Tags.of(this).add('predictily:stack', 'app');

    const arch = cfg.cpuArchitecture === 'ARM64' ? ecs.CpuArchitecture.ARM64 : ecs.CpuArchitecture.X86_64;
    const privateApp = { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS };
    const namespaceName = `predictily-${env}.internal`;

    // ── Cluster ECS con descubrimiento de servicios privado (el motor no se expone fuera de la VPC) ────────────────
    this.cluster = new ecs.Cluster(this, 'Cluster', {
      clusterName: `predictily-${env}`,
      vpc: platform.vpc,
      containerInsightsV2: cfg.containerInsights ? ecs.ContainerInsights.ENABLED : ecs.ContainerInsights.DISABLED,
      defaultCloudMapNamespace: { name: namespaceName, type: servicediscovery.NamespaceType.DNS_PRIVATE, vpc: platform.vpc },
    });
    if (!cfg.containerInsights) {
      ackAll(this.cluster, [
        {
          id: 'AwsSolutions-ECS4',
          reason: `Container Insights tiene costo por métrica y por tarea; se desactiva en ${env} (parámetro containerInsights de lib/config/environments.ts). Quedan las alarmas de CPU y memoria por servicio. Se activa en production.`,
        },
      ]);
    }

    // ── Registros de acceso del borde ──────────────────────────────────────────────────────────────────────────────
    const edgeLogs = new s3.Bucket(this, 'EdgeLogsBucket', {
      encryption: s3.BucketEncryption.S3_MANAGED, // los registros de ALB y de S3 no admiten SSE-KMS
      blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      minimumTLSVersion: 1.2,
      objectOwnership: s3.ObjectOwnership.BUCKET_OWNER_ENFORCED,
      lifecycleRules: [{ id: 'expirar-registros', expiration: Duration.days(cfg.accessLogsRetentionDays), abortIncompleteMultipartUploadAfter: Duration.days(7) }],
      removalPolicy: RemovalPolicy.RETAIN,
    });
    const cloudFrontLogs = new s3.Bucket(this, 'CloudFrontLogsBucket', {
      encryption: s3.BucketEncryption.S3_MANAGED,
      blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      minimumTLSVersion: 1.2,
      objectOwnership: s3.ObjectOwnership.BUCKET_OWNER_PREFERRED, // CloudFront entrega los registros estándar con ACL
      lifecycleRules: [{ id: 'expirar-registros', expiration: Duration.days(cfg.accessLogsRetentionDays), abortIncompleteMultipartUploadAfter: Duration.days(7) }],
      removalPolicy: RemovalPolicy.RETAIN,
    });
    ackAll([edgeLogs, cloudFrontLogs], [
      { id: 'AwsSolutions-S1', reason: 'Es un bucket destino de registros de acceso (ALB, CloudFront, S3); registrar sus propios accesos crearía recursión sin valor de auditoría.' },
    ]);

    // ── Registros de contenedores ──────────────────────────────────────────────────────────────────────────────────
    const logGroup = (name: string): logs.LogGroup =>
      new logs.LogGroup(this, `${name}Logs`, {
        logGroupName: `/predictily/${env}/${name}`,
        encryptionKey: platform.platformKey,
        retention: cfg.logRetention,
        removalPolicy: RemovalPolicy.RETAIN,
      });

    // ── Definiciones de tarea ──────────────────────────────────────────────────────────────────────────────────────
    const backendImage = ecs.ContainerImage.fromEcrRepository(platform.repositories.backend, inputs.imageTag);
    const engineImage = ecs.ContainerImage.fromEcrRepository(platform.repositories.engine, inputs.imageTag);
    const webImage = ecs.ContainerImage.fromEcrRepository(platform.repositories.web, inputs.imageTag);
    const appUrl = `https://${inputs.appDomainName}`;
    // Los secretos se importan por ARN: así ecs.Secret NO agrega sentencias a la política de la clave KMS (que vive en la pila
    // Platform y crearía una referencia circular hacia los roles de ejecución de esta pila). El permiso kms:Decrypt se concede
    // en la política de identidad de cada rol de ejecución (la clave delega en IAM: sentencia raíz de la cuenta).
    const importedSecret = (id: string, source: secretsmanager.ISecret): secretsmanager.ISecret => secretsmanager.Secret.fromSecretCompleteArn(this, id, source.secretArn);
    const secretAppKey = importedSecret('AppKeyRef', platform.secrets.appKey);
    const secretEngineToken = importedSecret('EngineApiTokenRef', platform.secrets.engineApiToken);
    const secretDatabase = importedSecret('DatabaseRef', platform.databaseSecret);

    // Configuración NO sensible (nombres, URLs, banderas). Los secretos van SIEMPRE por `secrets` (Secrets Manager).
    const backendEnvironment: Record<string, string> = {
      APP_NAME: 'Predictily',
      APP_ENV: 'production',
      APP_DEBUG: 'false',
      APP_URL: appUrl,
      APP_LOCALE: 'es',
      APP_FALLBACK_LOCALE: 'es',
      APP_TIMEZONE: 'UTC',
      FRONTEND_URL: appUrl,
      LOG_CHANNEL: 'stderr',
      LOG_LEVEL: 'info',
      DB_CONNECTION: 'pgsql',
      DB_PORT: '5432',
      DB_DATABASE: 'predictily',
      DB_SSLMODE: 'require',
      // Colas SQS (sin Redis en la línea base): SQS_SUFFIX hace que onQueue('imports') resuelva a imports-predictily-<entorno>.
      QUEUE_CONNECTION: 'sqs',
      SQS_PREFIX: `https://sqs.${this.region}.amazonaws.com/${this.account}`,
      SQS_SUFFIX: sqsSuffix(env),
      SQS_QUEUE: 'default',
      AWS_DEFAULT_REGION: this.region,
      CACHE_STORE: 'database',
      SESSION_DRIVER: 'database',
      SESSION_LIFETIME: '120',
      SESSION_SECURE_COOKIE: 'true',
      SESSION_SAME_SITE: 'lax',
      SESSION_DOMAIN: inputs.appDomainName,
      SANCTUM_STATEFUL_DOMAINS: inputs.appDomainName,
      TRUSTED_PROXIES: platform.vpc.vpcCidrBlock,
      ENGINE_URL: `http://engine.${namespaceName}:${ENGINE_PORT}`,
      ENGINE_TIMEOUT: '900',
      ENGINE_ARTIFACTS_DIR: '/data/artifacts',
      ENGINE_ALLOW_UNSIGNED_ARTIFACTS: 'false',
      ENGINE_TRUSTED_PUBLIC_KEYS: inputs.trustedPublicKeys,
      FILESYSTEM_DISK: 'local',
      PREDICTILY_BRONZE_DISK: 'bronze_s3',
      PREDICTILY_BRONZE_S3_BUCKET: dataBucketName(env, 'bronze', this.account, this.region), // nombre determinista: sin export entre pilas
      PREDICTILY_BRONZE_S3_REGION: this.region,
      PREDICTILY_BRONZE_S3_SSE: 'aws:kms',
      PREDICTILY_BRONZE_S3_KMS_KEY_ID: platform.dataKey.keyArn,
      // Disco de publicación de modelos (backend/config/mlops.php → published_disk). Sin él, cae en el disco de Bronze y los modelos
      // acabarían en el bucket equivocado. El disco `models_s3` AÚN NO EXISTE en backend/config/filesystems.php: DISEÑADO/PENDIENTE
      // (docs/architecture/AWS_ARCHITECTURE.md §11). Los nombres PREDICTILY_MODELS_S3_* siguen la convención de PREDICTILY_BRONZE_S3_*.
      PREDICTILY_MODELS_DISK: 'models_s3',
      PREDICTILY_MODELS_S3_BUCKET: dataBucketName(env, 'artifacts', this.account, this.region),
      PREDICTILY_MODELS_S3_REGION: this.region,
      PREDICTILY_MODELS_S3_SSE: 'aws:kms',
      PREDICTILY_MODELS_S3_KMS_KEY_ID: platform.dataKey.keyArn,
    };
    const backendSecrets: Record<string, ecs.Secret> = {
      // El host sale de la clave `host` del secreto de RDS (lo agrega la vinculación del secreto con la instancia): la tarea no
      // depende de un export del endpoint entre pilas (referencias «strong»; AWS_DEPLOYMENT.md §11).
      DB_HOST: ecs.Secret.fromSecretsManager(secretDatabase, 'host'),
      APP_KEY: ecs.Secret.fromSecretsManager(secretAppKey),
      ENGINE_API_TOKEN: ecs.Secret.fromSecretsManager(secretEngineToken),
      DB_USERNAME: ecs.Secret.fromSecretsManager(secretDatabase, 'username'),
      DB_PASSWORD: ecs.Secret.fromSecretsManager(secretDatabase, 'password'),
    };

    const filesVolume = (name: string, accessPoint: { accessPointId: string }): ecs.Volume => ({
      name,
      efsVolumeConfiguration: {
        fileSystemId: platform.fileSystem.fileSystemId,
        transitEncryption: 'ENABLED',
        authorizationConfig: { accessPointId: accessPoint.accessPointId, iam: 'ENABLED' },
      },
    });

    // ECS2 se reconoce SOLO en el recurso de la definición de tarea (no en sus roles): los reconocimientos de IAM5 se hacen más
    // abajo, en cada política concreta que contiene el comodín.
    const suppressTaskDef = (taskDef: ecs.TaskDefinition): void => {
      ackAll(taskDef.node.defaultChild as ecs.CfnTaskDefinition, [
        {
          id: 'AwsSolutions-ECS2',
          reason: 'Las variables de entorno contienen SOLO configuración no sensible (URLs, nombres de colas y buckets, banderas); toda credencial se inyecta con `secrets` desde Secrets Manager. La regla señala cualquier uso de `environment`.',
        },
      ]);
    };

    const newTaskDef = (name: string, sizing: Pick<TaskSizing, 'cpu' | 'memoryMiB'>, volumes: ecs.Volume[]): ecs.FargateTaskDefinition => {
      const td = new ecs.FargateTaskDefinition(this, `${name}Task`, {
        family: `predictily-${env}-${name}`,
        cpu: sizing.cpu,
        memoryLimitMiB: sizing.memoryMiB,
        runtimePlatform: { cpuArchitecture: arch, operatingSystemFamily: ecs.OperatingSystemFamily.LINUX },
        volumes,
      });
      suppressTaskDef(td);
      td.obtainExecutionRole().addToPrincipalPolicy(
        new iam.PolicyStatement({
          actions: ['kms:Decrypt'],
          resources: [platform.platformKey.keyArn],
          conditions: { StringEquals: { 'kms:ViaService': `secretsmanager.${this.region}.amazonaws.com` } },
        }),
      );
      return td;
    };

    const backendMounts = (artifactsReadOnly = true): ecs.MountPoint[] => [
      { sourceVolume: 'backend-storage', containerPath: '/var/www/html/storage/app/private', readOnly: false },
      { sourceVolume: 'engine-artifacts', containerPath: '/data/artifacts', readOnly: artifactsReadOnly },
    ];
    const backendVolumes = [filesVolume('backend-storage', platform.accessPoints.backendStorage), filesVolume('engine-artifacts', platform.accessPoints.engineArtifacts)];

    const addBackendContainer = (td: ecs.FargateTaskDefinition, name: string, lg: logs.ILogGroup, command?: string[], extra: Partial<ecs.ContainerDefinitionOptions> = {}, mounts = true): ecs.ContainerDefinition => {
      const c = td.addContainer(name, {
        image: backendImage,
        command,
        environment: backendEnvironment,
        secrets: backendSecrets,
        logging: ecs.LogDrivers.awsLogs({ streamPrefix: name, logGroup: lg }),
        linuxParameters: new ecs.LinuxParameters(this, `${td.node.id}${name}Linux`, { initProcessEnabled: true }),
        ...extra,
      });
      if (mounts) c.addMountPoints(...backendMounts());
      return c;
    };

    // API: nginx (proxy FastCGI) + php-fpm en la MISMA tarea (comparten red: nginx habla con php-fpm por 127.0.0.1:9000).
    const apiLogs = logGroup('api');
    const apiTask = newTaskDef('api', cfg.services.api, backendVolumes);
    const apiBackend = addBackendContainer(apiTask, 'backend', apiLogs, undefined, {
      portMappings: [{ containerPort: 9000 }],
      healthCheck: {
        command: ['CMD-SHELL', 'SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1'],
        interval: Duration.seconds(30),
        timeout: Duration.seconds(5),
        retries: 3,
        startPeriod: Duration.seconds(30),
      },
    });
    const apiWeb = apiTask.addContainer('web', {
      image: webImage,
      // La imagen web resuelve `backend:9000` (Compose). En awsvpc no existe ese nombre: se mapea a localhost antes de arrancar nginx.
      command: ['sh', '-c', 'echo "127.0.0.1 backend" >> /etc/hosts && exec nginx -g "daemon off;"'],
      portMappings: [{ containerPort: 80 }],
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'web', logGroup: apiLogs }),
      healthCheck: {
        command: ['CMD-SHELL', 'wget -q -O /dev/null http://127.0.0.1/healthz || exit 1'],
        interval: Duration.seconds(30),
        timeout: Duration.seconds(5),
        retries: 3,
        startPeriod: Duration.seconds(15),
      },
    });
    apiWeb.addContainerDependencies({ container: apiBackend, condition: ecs.ContainerDependencyCondition.HEALTHY });

    // Workers de colas SQS (mismos parámetros que docker-compose.yml: timeout, memoria y tiempo máximo).
    const workerDefaultLogs = logGroup('worker-default');
    const workerDefaultTask = newTaskDef('worker-default', cfg.services.workerDefault, backendVolumes);
    addBackendContainer(workerDefaultTask, 'worker', workerDefaultLogs, ['php', 'artisan', 'queue:work', 'sqs', '--queue=imports,default', '--tries=2', '--timeout=600', '--memory=1024', '--max-time=3600', '--sleep=3'], { stopTimeout: Duration.seconds(60) });
    const workerForecastingLogs = logGroup('worker-forecasting');
    const workerForecastingTask = newTaskDef('worker-forecasting', cfg.services.workerForecasting, backendVolumes);
    addBackendContainer(workerForecastingTask, 'worker', workerForecastingLogs, ['php', 'artisan', 'queue:work', 'sqs', '--queue=forecasting', '--tries=2', '--timeout=1000', '--memory=512', '--max-time=7200', '--sleep=3'], { stopTimeout: Duration.seconds(120) });
    const schedulerLogs = logGroup('scheduler');
    const schedulerTask = newTaskDef('scheduler', cfg.services.scheduler, backendVolumes);
    addBackendContainer(schedulerTask, 'scheduler', schedulerLogs, ['php', 'artisan', 'schedule:work']);

    // Tareas EFÍMERAS (RunTask): migraciones (paso previo de cada despliegue) y comandos de EventBridge Scheduler.
    const jobsLogs = logGroup('jobs');
    // Las migraciones solo necesitan la base de datos: SIN volúmenes EFS y con un rol de tarea vacío (mínimo privilegio).
    const migrateTask = newTaskDef('migrate', cfg.services.ephemeral, []);
    addBackendContainer(migrateTask, 'backend', jobsLogs, ['php', 'artisan', 'migrate', '--force', '--no-interaction'], {}, false);
    const jobTask = newTaskDef('job', cfg.services.ephemeral, backendVolumes);
    // El comando real lo fija cada programación (containerOverrides); por defecto no hace nada con efectos.
    addBackendContainer(jobTask, 'backend', jobsLogs, ['php', 'artisan', '--version']);

    // Motor predictivo (FastAPI): solo accesible por la red privada; escribe y firma los artefactos.
    const engineLogs = logGroup('engine');
    const engineTask = newTaskDef('engine', cfg.services.engine, [filesVolume('engine-artifacts', platform.accessPoints.engineArtifacts)]);
    const signingSecret = secretsmanager.Secret.fromSecretNameV2(this, 'EngineSigningKey', inputs.signingKeySecretName);
    const engineEnvironment: Record<string, string> = {
      ENGINE_ARTIFACTS_DIR: '/data/artifacts',
      ENGINE_ALLOW_UNSIGNED_ARTIFACTS: 'false',
      ENGINE_RANDOM_SEED: '42',
      ENGINE_LOG_LEVEL: 'INFO',
      ENGINE_N_JOBS: '2',
      ENGINE_WORKERS: '2',
      ENGINE_MAX_SERIES_LGBM: '5000',
      ENGINE_STALE_RUN_SECONDS: '1800',
      DB_PORT: '5432',
      DB_NAME: 'predictily',
      // La clave de firma privada (secreto) exige su identificador y la pública de confianza: ambos son obligatorios (inputs.ts).
      ENGINE_TRUSTED_PUBLIC_KEYS: inputs.trustedPublicKeys,
      ENGINE_SIGNING_KEY_ID: inputs.signingKeyId,
    };
    const engineContainer = engineTask.addContainer('engine', {
      image: engineImage,
      // ENGINE_DATABASE_URL se compone al arrancar (la contraseña llega por Secrets Manager, solo alfanumérica); el resto replica el CMD de engine/Dockerfile.
      command: [
        'sh',
        '-c',
        'export ENGINE_DATABASE_URL="postgresql+psycopg://$DB_USERNAME:$DB_PASSWORD@$DB_HOST:$DB_PORT/$DB_NAME?sslmode=require"; exec uvicorn predictily_engine.api.main:app --host 0.0.0.0 --port 8100 --workers $ENGINE_WORKERS --timeout-keep-alive 75 --no-server-header',
      ],
      environment: engineEnvironment,
      secrets: {
        ENGINE_API_TOKEN: ecs.Secret.fromSecretsManager(secretEngineToken),
        DB_HOST: ecs.Secret.fromSecretsManager(secretDatabase, 'host'),
        DB_USERNAME: ecs.Secret.fromSecretsManager(secretDatabase, 'username'),
        DB_PASSWORD: ecs.Secret.fromSecretsManager(secretDatabase, 'password'),
        // Clave PRIVADA de firma (semilla Ed25519): la crea el propietario fuera de CloudFormation; solo este rol de ejecución la lee.
        ENGINE_SIGNING_PRIVATE_KEY: ecs.Secret.fromSecretsManager(signingSecret),
      },
      portMappings: [{ containerPort: ENGINE_PORT }],
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'engine', logGroup: engineLogs }),
      healthCheck: {
        command: ['CMD-SHELL', 'python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen(\'http://127.0.0.1:8100/health\', timeout=4).status == 200 else 1)"'],
        interval: Duration.seconds(30),
        timeout: Duration.seconds(5),
        retries: 3,
        startPeriod: Duration.seconds(60),
      },
      linuxParameters: new ecs.LinuxParameters(this, 'EngineLinux', { initProcessEnabled: true }),
      stopTimeout: Duration.seconds(120),
    });
    engineContainer.addMountPoints({ sourceVolume: 'engine-artifacts', containerPath: '/data/artifacts', readOnly: false });

    // ── Servicios Fargate ──────────────────────────────────────────────────────────────────────────────────────────
    const service = (name: string, td: ecs.FargateTaskDefinition, desiredCount: number, sg: ec2.ISecurityGroup, extra: Partial<ecs.FargateServiceProps> = {}): ecs.FargateService =>
      new ecs.FargateService(this, `${name}Service`, {
        serviceName: `predictily-${env}-${name}`,
        cluster: this.cluster,
        taskDefinition: td,
        desiredCount,
        securityGroups: [sg],
        vpcSubnets: privateApp,
        assignPublicIp: false,
        circuitBreaker: { rollback: true },
        minHealthyPercent: 100,
        maxHealthyPercent: 200,
        enableExecuteCommand: false,
        propagateTags: ecs.PropagatedTagSource.SERVICE,
        enableECSManagedTags: true,
        ...extra,
      });
    const sgs = platform.securityGroups;
    const apiService = service('api', apiTask, cfg.services.api.desiredCount, sgs.api, { healthCheckGracePeriod: Duration.seconds(90) });
    const engineService = service('engine', engineTask, cfg.services.engine.desiredCount, sgs.engine, {
      healthCheckGracePeriod: undefined,
      cloudMapOptions: { name: 'engine', dnsRecordType: servicediscovery.DnsRecordType.A, dnsTtl: Duration.seconds(10) },
    });
    const workerDefaultService = service('worker-default', workerDefaultTask, cfg.services.workerDefault.desiredCount, sgs.worker);
    const workerForecastingService = service('worker-forecasting', workerForecastingTask, cfg.services.workerForecasting.desiredCount, sgs.worker);
    const schedulerService = service('scheduler', schedulerTask, cfg.services.scheduler.desiredCount, sgs.worker, { maxHealthyPercent: 100, minHealthyPercent: 0 });
    this.services = { api: apiService, engine: engineService, workerDefault: workerDefaultService, workerForecasting: workerForecastingService, scheduler: schedulerService };

    const scaling = apiService.autoScaleTaskCount({ minCapacity: cfg.services.api.minCount, maxCapacity: cfg.services.api.maxCount });
    scaling.scaleOnCpuUtilization('ApiCpu', { targetUtilizationPercent: 60, scaleInCooldown: Duration.minutes(5), scaleOutCooldown: Duration.minutes(2) });

    // ── Permisos de las tareas (mínimo privilegio por servicio) ────────────────────────────────────────────────────
    this.taskRoles = {
      api: apiTask.taskRole,
      workerDefault: workerDefaultTask.taskRole,
      workerForecasting: workerForecastingTask.taskRole,
      scheduler: schedulerTask.taskRole,
      engine: engineTask.taskRole,
      jobs: jobTask.taskRole,
    };
    const dataKeyUse = new iam.PolicyStatement({ actions: ['kms:Decrypt', 'kms:Encrypt', 'kms:GenerateDataKey', 'kms:DescribeKey'], resources: [platform.dataKey.keyArn] });
    const platformKeyUse = new iam.PolicyStatement({ actions: ['kms:Decrypt', 'kms:GenerateDataKey', 'kms:DescribeKey'], resources: [platform.platformKey.keyArn] });
    const objectsArn = (layer: DataBucketName): string => tenantObjectsArn(env, layer, this.account, this.region);
    const bucketArnOf = (layer: DataBucketName): string => `arn:aws:s3:::${dataBucketName(env, layer, this.account, this.region)}`;
    /**
     * Permisos S3 de una capa. SIEMPRE que un rol puede leer objetos recibe también `s3:ListBucket` sobre el bucket (no sobre los
     * objetos): sin él, HeadObject/GetObject de una clave inexistente devuelve 403 en vez de 404, y `FilesystemBronzeStore::exists()`
     * antes de escribir (o ModelApprovalGates / StorageUsageSnapshot sobre artefactos) fallaría. La condición usa
     * `ForAllValues:StringLike` sobre `s3:prefix`: se cumple con las listas bajo `tenants/` y, como HeadObject no lleva `s3:prefix`,
     * también cuando la clave está ausente (con `StringLike` a secas la sentencia no aplicaría y volvería el 403). Todas las claves
     * del bucket están bajo `tenants/`, de modo que no amplía lo que el rol ya puede leer con GetObject.
     */
    const s3Allow = (role: iam.IRole, layer: DataBucketName, actions: string[]): void => {
      role.addToPrincipalPolicy(new iam.PolicyStatement({ actions, resources: [objectsArn(layer)] }));
      if (actions.includes('s3:GetObject')) {
        role.addToPrincipalPolicy(
          new iam.PolicyStatement({
            actions: ['s3:ListBucket', 's3:GetBucketLocation'],
            resources: [bucketArnOf(layer)],
            conditions: { 'ForAllValues:StringLike': { 's3:prefix': ['tenants/*'] } },
          }),
        );
      }
    };
    const efsAllow = (role: iam.IRole, accessPointArn: string, write: boolean): void => {
      role.addToPrincipalPolicy(
        new iam.PolicyStatement({
          actions: write ? ['elasticfilesystem:ClientMount', 'elasticfilesystem:ClientWrite'] : ['elasticfilesystem:ClientMount'],
          resources: [platform.fileSystem.fileSystemArn],
          conditions: { StringEquals: { 'elasticfilesystem:AccessPointArn': accessPointArn } },
        }),
      );
    };
    const queueArn = (n: QueueName): string => platform.queues[n].queue.queueArn;
    const sqsSend = (role: iam.IRole): void => {
      role.addToPrincipalPolicy(new iam.PolicyStatement({ actions: ['sqs:SendMessage', 'sqs:GetQueueUrl', 'sqs:GetQueueAttributes'], resources: QUEUE_NAMES.map(queueArn) }));
      role.addToPrincipalPolicy(platformKeyUse);
    };
    const sqsConsume = (role: iam.IRole, names: QueueName[]): void => {
      role.addToPrincipalPolicy(
        new iam.PolicyStatement({ actions: ['sqs:ReceiveMessage', 'sqs:DeleteMessage', 'sqs:ChangeMessageVisibility'], resources: names.map(queueArn) }),
      );
    };
    const backendFiles = (role: iam.IRole): void => {
      efsAllow(role, platform.accessPoints.backendStorage.accessPointArn, true);
      efsAllow(role, platform.accessPoints.engineArtifacts.accessPointArn, false);
    };
    // API: sube a Bronze, lee artefactos, encola.
    const apiRole = this.taskRoles.api;
    s3Allow(apiRole, 'bronze', ['s3:GetObject', 's3:PutObject']);
    s3Allow(apiRole, 'artifacts', ['s3:GetObject']);
    apiRole.addToPrincipalPolicy(dataKeyUse);
    sqsSend(apiRole);
    backendFiles(apiRole);
    // Workers: procesan colas; Bronze lectura/escritura/purga; artefactos de solo lectura.
    for (const [role, queues] of [[this.taskRoles.workerDefault, ['imports', 'default']], [this.taskRoles.workerForecasting, ['forecasting']]] as const) {
      sqsSend(role);
      sqsConsume(role, [...queues]);
      s3Allow(role, 'bronze', ['s3:GetObject', 's3:PutObject', 's3:DeleteObject']);
      s3Allow(role, 'artifacts', ['s3:GetObject']);
      role.addToPrincipalPolicy(dataKeyUse);
      backendFiles(role);
    }
    // Programador y tareas efímeras: purgas, instantáneas de uso y encolado. Borran de Bronze con DeleteObject: en un bucket
    // versionado eso crea una marca de borrado y el contenido sigue como versión no vigente hasta su expiración (ver AWS_DEPLOYMENT.md §10).
    for (const role of [this.taskRoles.scheduler, this.taskRoles.jobs]) {
      sqsSend(role);
      s3Allow(role, 'bronze', ['s3:GetObject', 's3:DeleteObject']);
      s3Allow(role, 'artifacts', ['s3:GetObject']);
      role.addToPrincipalPolicy(dataKeyUse);
      backendFiles(role);
    }
    // Motor: escribe artefactos (S3 y EFS) y nada más.
    const engineRole = this.taskRoles.engine;
    s3Allow(engineRole, 'artifacts', ['s3:GetObject', 's3:PutObject']);
    engineRole.addToPrincipalPolicy(dataKeyUse);
    efsAllow(engineRole, platform.accessPoints.engineArtifacts.accessPointArn, true);

    // Reconocimientos de cdk-nag IAM5 ACOTADOS a la política concreta que contiene cada comodín (no a todo el subárbol de la tarea):
    //  - `tenants/*` solo en la política del rol de tarea que lo concede (y solo para las capas que ese rol toca).
    //  - `Resource::*` solo en la política del rol de ejecución (ecr:GetAuthorizationToken). test/nag.test.ts lo exige.
    const policyOf = (role: iam.IRole): iam.Policy => role.node.findChild('DefaultPolicy') as iam.Policy;
    const tenantObjectAcks = (layers: readonly DataBucketName[]) =>
      layers.map((layer) => ({
        id: `AwsSolutions-IAM5[Resource::${objectsArn(layer)}]`,
        reason:
          'El prefijo `tenants/<uuid>/` lo genera el servidor (ADR-V25-08), por lo que el rol no puede enumerar las empresas en la plantilla: el acceso queda limitado al prefijo `tenants/` del bucket de su capa. ' +
          'El aislamiento fino por empresa (rol ABAC con etiqueta de sesion tenant_id, TenantDataAccessRole) esta DISENADO e implementado en la plantilla, pero la aplicacion aun no lo asume (docs/architecture/AWS_ARCHITECTURE.md seccion 5).',
      }));
    for (const task of [apiTask, workerDefaultTask, workerForecastingTask, schedulerTask, jobTask]) ackAll(policyOf(task.taskRole), tenantObjectAcks(['bronze', 'artifacts']));
    ackAll(policyOf(engineTask.taskRole), tenantObjectAcks(['artifacts']));
    for (const task of [apiTask, workerDefaultTask, workerForecastingTask, schedulerTask, jobTask, migrateTask, engineTask]) {
      ackAll(policyOf(task.obtainExecutionRole()), [
        {
          id: 'AwsSolutions-IAM5[Resource::*]',
          reason: 'ecr:GetAuthorizationToken solo admite Resource "*" (token de registro de la cuenta); el resto de la politica del rol de ejecucion nombra recursos concretos (repositorios de Predictily, grupos de log del servicio, secretos y clave).',
        },
      ]);
    }

    // ── Aislamiento fino por empresa (ABAC): rol que la aplicación asumirá con la etiqueta de sesión tenant_id ────────
    const tenantPrefix = '${aws:PrincipalTag/tenant_id}';
    const tenantRoleCallers = [this.taskRoles.api, this.taskRoles.workerDefault, this.taskRoles.workerForecasting, this.taskRoles.engine];
    const sessionConditions = {
      // La etiqueta debe ser un UUID (forma 8-4-4-4-12) y la única etiqueta de sesión permitida es tenant_id.
      StringLike: { 'aws:RequestTag/tenant_id': '????????-????-????-????-????????????' },
      'ForAllValues:StringEquals': { 'aws:TagKeys': ['tenant_id'] },
    };
    this.tenantDataAccessRole = new iam.Role(this, 'TenantDataAccessRole', {
      roleName: `predictily-${env}-tenant-data-access`,
      description: 'Rol de sesión por empresa: solo objetos tenants/<tenant_id>/* (etiqueta de sesión tenant_id); DISEÑADO: la aplicación aún no lo asume',
      maxSessionDuration: Duration.hours(1),
      assumedBy: new iam.CompositePrincipal(...tenantRoleCallers.map((r) => new iam.ArnPrincipal(r.roleArn))).withConditions(sessionConditions),
    });
    // sts:TagSession es una acción distinta de sts:AssumeRole: sin ella la sesión no puede llevar la etiqueta.
    this.tenantDataAccessRole.assumeRolePolicy?.addStatements(
      new iam.PolicyStatement({
        principals: tenantRoleCallers.map((r) => new iam.ArnPrincipal(r.roleArn)),
        actions: ['sts:TagSession'],
        conditions: sessionConditions,
      }),
    );
    for (const r of tenantRoleCallers) {
      r.addToPrincipalPolicy(new iam.PolicyStatement({ actions: ['sts:AssumeRole', 'sts:TagSession'], resources: [this.tenantDataAccessRole.roleArn] }));
    }
    for (const layer of ['bronze', 'artifacts'] as const) {
      const bucketName = dataBucketName(env, layer, this.account, this.region);
      this.tenantDataAccessRole.addToPolicy(
        new iam.PolicyStatement({ actions: ['s3:GetObject', 's3:PutObject'], resources: [`arn:aws:s3:::${bucketName}/tenants/${tenantPrefix}/*`] }),
      );
      this.tenantDataAccessRole.addToPolicy(
        new iam.PolicyStatement({
          actions: ['s3:ListBucket'],
          resources: [`arn:aws:s3:::${bucketName}`],
          conditions: { StringLike: { 's3:prefix': [`tenants/${tenantPrefix}/*`] } },
        }),
      );
    }
    this.tenantDataAccessRole.addToPolicy(
      new iam.PolicyStatement({
        actions: ['kms:Decrypt', 'kms:Encrypt', 'kms:GenerateDataKey'],
        resources: [platform.dataKey.keyArn],
        conditions: { StringEquals: { 'kms:ViaService': `s3.${this.region}.amazonaws.com` } },
      }),
    );
    ackAll(
      policyOf(this.tenantDataAccessRole),
      (['bronze', 'artifacts'] as const).map((layer) => ({
        id: `AwsSolutions-IAM5[Resource::arn:aws:s3:::${dataBucketName(env, layer, this.account, this.region)}/tenants/<aws:PrincipalTag/tenant_id>/*]`,
        reason:
          'El comodin final cubre los objetos de UNA empresa: la variable de politica aws:PrincipalTag/tenant_id la fija la sesion (etiqueta tenant_id con forma de UUID exigida por la politica de confianza), de modo que el rol nunca alcanza otra empresa.',
      })),
    );

    // ── ALB (solo alcanzable desde CloudFront, con cabecera secreta de origen) ─────────────────────────────────────
    this.loadBalancer = new elbv2.ApplicationLoadBalancer(this, 'Alb', {
      loadBalancerName: `predictily-${env}`,
      vpc: platform.vpc,
      internetFacing: true,
      securityGroup: sgs.alb,
      vpcSubnets: { subnetType: ec2.SubnetType.PUBLIC },
      dropInvalidHeaderFields: true,
      deletionProtection: cfg.albDeletionProtection,
      http2Enabled: true,
    });
    this.loadBalancer.logAccessLogs(edgeLogs, 'alb');
    const originVerifyValue = platform.secrets.originVerify.secretValue.unsafeUnwrap();
    const listener = this.loadBalancer.addListener('Https', {
      port: 443,
      protocol: elbv2.ApplicationProtocol.HTTPS,
      certificates: [elbv2.ListenerCertificate.fromArn(inputs.albCertificateArn)],
      sslPolicy: elbv2.SslPolicy.RECOMMENDED_TLS,
      open: false,
      defaultAction: elbv2.ListenerAction.fixedResponse(403, { contentType: 'text/plain', messageBody: 'Prohibido' }),
    });
    const apiTargets = listener.addTargets('Api', {
      priority: 10,
      conditions: [elbv2.ListenerCondition.httpHeader('X-Origin-Verify', [originVerifyValue])],
      port: 80,
      protocol: elbv2.ApplicationProtocol.HTTP,
      targets: [apiService.loadBalancerTarget({ containerName: 'web', containerPort: 80 })],
      deregistrationDelay: Duration.seconds(30),
      healthCheck: { path: '/up', healthyHttpCodes: '200', interval: Duration.seconds(30), timeout: Duration.seconds(5), healthyThresholdCount: 2, unhealthyThresholdCount: 3 },
    });

    // ── SPA: S3 privado + CloudFront (OAC) + WAF ──────────────────────────────────────────────────────────────────
    this.spaBucket = new s3.Bucket(this, 'SpaBucket', {
      // SSE-S3: solo contiene los activos públicos compilados de la SPA; con SSE-KMS la política de la clave tendría que
      // referenciar la distribución y crearía una dependencia circular entre pilas.
      encryption: s3.BucketEncryption.S3_MANAGED,
      blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      minimumTLSVersion: 1.2,
      versioned: true,
      objectOwnership: s3.ObjectOwnership.BUCKET_OWNER_ENFORCED,
      serverAccessLogsBucket: edgeLogs,
      serverAccessLogsPrefix: 's3/spa/',
      lifecycleRules: [{ id: 'versiones-no-vigentes', noncurrentVersionExpiration: Duration.days(30), abortIncompleteMultipartUploadAfter: Duration.days(7) }],
      removalPolicy: RemovalPolicy.RETAIN,
    });

    const managedGroup = (name: string, priority: number): wafv2.CfnWebACL.RuleProperty => ({
      name,
      priority,
      overrideAction: cfg.waf.managedRulesMode === 'count' ? { count: {} } : { none: {} },
      statement: {
        managedRuleGroupStatement: {
          vendorName: 'AWS',
          name,
          ...(name === 'AWSManagedRulesCommonRuleSet'
            ? { ruleActionOverrides: [{ name: 'SizeRestrictions_BODY', actionToUse: { count: {} } }] }
            : {}),
        },
      },
      visibilityConfig: { sampledRequestsEnabled: true, cloudWatchMetricsEnabled: true, metricName: name },
    });
    const webAcl = new wafv2.CfnWebACL(this, 'EdgeWebAcl', {
      name: `predictily-${env}-edge`,
      scope: 'CLOUDFRONT',
      description: `Predictily ${env} edge: rate limits block, AWS managed rules in ${cfg.waf.managedRulesMode} mode`,
      defaultAction: { allow: {} },
      visibilityConfig: { sampledRequestsEnabled: true, cloudWatchMetricsEnabled: true, metricName: `predictily-${env}-edge` },
      rules: [
        {
          name: 'LimiteAutenticacion',
          priority: 5,
          action: { block: {} },
          statement: {
            rateBasedStatement: {
              limit: cfg.waf.authRateLimit,
              aggregateKeyType: 'IP',
              scopeDownStatement: {
                byteMatchStatement: {
                  searchString: '/api/v1/auth/',
                  fieldToMatch: { uriPath: {} },
                  textTransformations: [{ priority: 0, type: 'NONE' }],
                  positionalConstraint: 'STARTS_WITH',
                },
              },
            },
          },
          visibilityConfig: { sampledRequestsEnabled: true, cloudWatchMetricsEnabled: true, metricName: 'LimiteAutenticacion' },
        },
        {
          name: 'LimiteGeneral',
          priority: 6,
          action: { block: {} },
          statement: { rateBasedStatement: { limit: cfg.waf.generalRateLimit, aggregateKeyType: 'IP' } },
          visibilityConfig: { sampledRequestsEnabled: true, cloudWatchMetricsEnabled: true, metricName: 'LimiteGeneral' },
        },
        managedGroup('AWSManagedRulesCommonRuleSet', 10),
        managedGroup('AWSManagedRulesKnownBadInputsRuleSet', 20),
        managedGroup('AWSManagedRulesAmazonIpReputationList', 30),
      ],
    });

    const securityHeaders = new cloudfront.ResponseHeadersPolicy(this, 'SecurityHeaders', {
      comment: 'Cabeceras equivalentes a infra/nginx/snippets/security-headers.conf',
      securityHeadersBehavior: {
        strictTransportSecurity: { accessControlMaxAge: Duration.days(365), includeSubdomains: true, override: true },
        contentTypeOptions: { override: true },
        frameOptions: { frameOption: cloudfront.HeadersFrameOption.DENY, override: true },
        referrerPolicy: { referrerPolicy: cloudfront.HeadersReferrerPolicy.STRICT_ORIGIN_WHEN_CROSS_ORIGIN, override: true },
        contentSecurityPolicy: {
          contentSecurityPolicy:
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; worker-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
          override: true,
        },
      },
      customHeadersBehavior: {
        customHeaders: [
          { header: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=(), payment=()', override: true },
          { header: 'Cross-Origin-Opener-Policy', value: 'same-origin', override: true },
        ],
      },
    });
    // Historial de la SPA (Vue Router en modo history): toda ruta sin extensión sirve index.html. No se usan errorResponses
    // porque afectarían también a las respuestas 403/404 legítimas de /api.
    const spaRewrite = new cloudfront.Function(this, 'SpaRewrite', {
      comment: 'Reescribe rutas de la SPA (sin extensión) a /index.html',
      runtime: cloudfront.FunctionRuntime.JS_2_0,
      code: cloudfront.FunctionCode.fromInline(
        "function handler(event) {\n  var request = event.request;\n  if (!/\\.[A-Za-z0-9]+$/.test(request.uri)) { request.uri = '/index.html'; }\n  return request;\n}\n",
      ),
    });
    const apiOrigin = new origins.HttpOrigin(inputs.originDomainName, {
      protocolPolicy: cloudfront.OriginProtocolPolicy.HTTPS_ONLY,
      originSslProtocols: [cloudfront.OriginSslPolicy.TLS_V1_2],
      readTimeout: Duration.seconds(60),
      connectionAttempts: 3,
      customHeaders: { 'X-Origin-Verify': originVerifyValue },
    });
    const apiBehavior: cloudfront.BehaviorOptions = {
      origin: apiOrigin,
      viewerProtocolPolicy: cloudfront.ViewerProtocolPolicy.REDIRECT_TO_HTTPS,
      allowedMethods: cloudfront.AllowedMethods.ALLOW_ALL,
      cachePolicy: cloudfront.CachePolicy.CACHING_DISABLED,
      // Reenvía todo lo del visitante y los encabezados CloudFront-* (incluye CloudFront-Viewer-Address con la IP real del cliente).
      originRequestPolicy: cloudfront.OriginRequestPolicy.ALL_VIEWER_AND_CLOUDFRONT_2022,
      responseHeadersPolicy: securityHeaders,
      compress: true,
    };
    const priceClass = { PriceClass_100: cloudfront.PriceClass.PRICE_CLASS_100, PriceClass_200: cloudfront.PriceClass.PRICE_CLASS_200, PriceClass_All: cloudfront.PriceClass.PRICE_CLASS_ALL }[cfg.cloudFrontPriceClass];
    this.distribution = new cloudfront.Distribution(this, 'Distribution', {
      comment: `Predictily ${env}: SPA (S3) + /api (ALB)`,
      domainNames: [inputs.appDomainName],
      certificate: acm.Certificate.fromCertificateArn(this, 'EdgeCertificate', inputs.cloudFrontCertificateArn),
      minimumProtocolVersion: cloudfront.SecurityPolicyProtocol.TLS_V1_2_2021,
      httpVersion: cloudfront.HttpVersion.HTTP2_AND_3,
      priceClass,
      defaultRootObject: 'index.html',
      webAclId: webAcl.attrArn,
      enableLogging: true,
      logBucket: cloudFrontLogs,
      logFilePrefix: 'cloudfront/',
      logIncludesCookies: false,
      defaultBehavior: {
        origin: origins.S3BucketOrigin.withOriginAccessControl(this.spaBucket),
        viewerProtocolPolicy: cloudfront.ViewerProtocolPolicy.REDIRECT_TO_HTTPS,
        allowedMethods: cloudfront.AllowedMethods.ALLOW_GET_HEAD,
        cachePolicy: cloudfront.CachePolicy.CACHING_OPTIMIZED,
        responseHeadersPolicy: securityHeaders,
        compress: true,
        functionAssociations: [{ function: spaRewrite, eventType: cloudfront.FunctionEventType.VIEWER_REQUEST }],
      },
      additionalBehaviors: { '/api/*': apiBehavior, '/sanctum/*': apiBehavior },
    });
    ackAll(this.distribution, [
      {
        id: 'AwsSolutions-CFR1',
        reason: 'Producto B2B con clientes y usuarios en varios países de Latinoamérica y acceso remoto: una lista de países sería una decisión comercial del propietario (no técnica). Mitigaciones: WAF con límites de tasa y autenticación obligatoria. DISEÑADO: restricción geográfica configurable.',
      },
    ]);

    // ── Tareas programadas (EventBridge Scheduler → RunTask efímero) ───────────────────────────────────────────────
    const scheduleGroup = new scheduler.CfnScheduleGroup(this, 'ScheduleGroup', { name: `predictily-${env}` });
    const schedulerDlq = new sqs.Queue(this, 'SchedulerDlq', {
      queueName: `scheduler-failures-predictily-${env}`,
      encryption: sqs.QueueEncryption.KMS,
      encryptionMasterKey: platform.platformKey,
      enforceSSL: true,
      retentionPeriod: Duration.days(14),
    });
    ackAll(schedulerDlq, [
      { id: 'AwsSolutions-SQS3', reason: 'Es la cola de mensajes fallidos de EventBridge Scheduler (destino de deadLetterConfig); no se le agrega otra DLQ. La alarma de abajo avisa al primer mensaje.' },
    ]);
    const schedulerRole = new iam.Role(this, 'SchedulerRole', {
      assumedBy: new iam.ServicePrincipal('scheduler.amazonaws.com', { conditions: { StringEquals: { 'aws:SourceAccount': this.account } } }),
      description: 'EventBridge Scheduler: lanza tareas efímeras de ECS y envía fallos a su DLQ',
    });
    schedulerRole.addToPolicy(
      new iam.PolicyStatement({
        actions: ['ecs:RunTask'],
        // Cualquier revisión de la familia `job` (la revisión cambia en cada despliegue).
        resources: [`arn:${this.partition}:ecs:${this.region}:${this.account}:task-definition/${jobTask.family}:*`],
        conditions: { ArnEquals: { 'ecs:cluster': this.cluster.clusterArn } },
      }),
    );
    schedulerRole.addToPolicy(new iam.PolicyStatement({ actions: ['iam:PassRole'], resources: [jobTask.taskRole.roleArn, jobTask.obtainExecutionRole().roleArn], conditions: { StringEquals: { 'iam:PassedToService': 'ecs-tasks.amazonaws.com' } } }));
    schedulerRole.addToPolicy(new iam.PolicyStatement({ actions: ['sqs:SendMessage'], resources: [schedulerDlq.queueArn] }));
    schedulerRole.addToPolicy(new iam.PolicyStatement({ actions: ['kms:GenerateDataKey', 'kms:Decrypt'], resources: [platform.platformKey.keyArn] }));
    // HIPÓTESIS (no verificada sin cuenta AWS): RunTask con `propagateTags` exige ecs:TagResource a quien lanza la tarea; sin él la
    // programación fallaría con AccessDenied en la primera ejecución. Se concede solo para tareas de este clúster y solo al crearlas por RunTask.
    schedulerRole.addToPolicy(
      new iam.PolicyStatement({
        actions: ['ecs:TagResource'],
        resources: [`arn:${this.partition}:ecs:${this.region}:${this.account}:task/${this.cluster.clusterName}/*`],
        conditions: { StringEquals: { 'ecs:CreateAction': 'RunTask' } },
      }),
    );
    ackAll(policyOf(schedulerRole), [
      {
        id: `AwsSolutions-IAM5[Resource::arn:aws:ecs:${this.region}:${this.account}:task-definition/${jobTask.family}:*]`,
        reason: 'ecs:RunTask se limita a la familia de la tarea efimera `job`; el sufijo :* cubre sus revisiones, que cambian en cada despliegue.',
      },
      {
        id: `AwsSolutions-IAM5[Resource::arn:aws:ecs:${this.region}:${this.account}:task/<ClusterEB0386A7>/*]`,
        reason: 'ecs:TagResource solo sobre las tareas de este clúster y solo con ecs:CreateAction=RunTask; el identificador de la tarea no existe al sintetizar (hipótesis de permiso, ver AWS_ARCHITECTURE.md seccion 10).',
      },
    ]);
    const subnetIds = platform.vpc.selectSubnets(privateApp).subnetIds;
    for (const job of cfg.scheduledCommands) {
      new scheduler.CfnSchedule(this, `Schedule${job.name.replace(/[^A-Za-z0-9]/g, '')}`, {
        name: `${job.name}-${env}`,
        groupName: scheduleGroup.name,
        description: job.description,
        flexibleTimeWindow: { mode: 'OFF' },
        scheduleExpression: job.expression,
        scheduleExpressionTimezone: 'UTC',
        state: job.enabled ? 'ENABLED' : 'DISABLED',
        target: {
          arn: this.cluster.clusterArn,
          roleArn: schedulerRole.roleArn,
          input: JSON.stringify({ containerOverrides: [{ name: 'backend', command: [...job.command] }] }),
          ecsParameters: {
            taskDefinitionArn: jobTask.taskDefinitionArn,
            launchType: 'FARGATE',
            taskCount: 1,
            propagateTags: 'TASK_DEFINITION',
            networkConfiguration: { awsvpcConfiguration: { subnets: subnetIds, securityGroups: [sgs.worker.securityGroupId], assignPublicIp: 'DISABLED' } },
          },
          retryPolicy: { maximumRetryAttempts: 2, maximumEventAgeInSeconds: 3600 },
          deadLetterConfig: { arn: schedulerDlq.queueArn },
        },
      }).node.addDependency(scheduleGroup);
    }

    // ── Alarmas de servicio ────────────────────────────────────────────────────────────────────────────────────────
    const notify = new cwActions.SnsAction(platform.alarmTopic);
    const alarm = (aid: string, metric: cloudwatch.IMetric, threshold: number, description: string, periods = 3, op = cloudwatch.ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD): void => {
      const a = new cloudwatch.Alarm(this, aid, {
        alarmName: `predictily-${env}-${aid}`,
        alarmDescription: description,
        metric,
        threshold,
        evaluationPeriods: periods,
        comparisonOperator: op,
        treatMissingData: cloudwatch.TreatMissingData.NOT_BREACHING,
      });
      a.addAlarmAction(notify);
    };
    alarm('SchedulerFailuresNotEmpty', schedulerDlq.metricApproximateNumberOfMessagesVisible({ period: Duration.minutes(5), statistic: 'Maximum' }), 1, 'EventBridge Scheduler no pudo lanzar una tarea programada', 1);
    alarm('Alb5xx', this.loadBalancer.metrics.httpCodeTarget(elbv2.HttpCodeTarget.TARGET_5XX_COUNT, { period: Duration.minutes(5), statistic: 'Sum' }), 20, 'ALB: >= 20 respuestas 5xx de la API en 5 min (umbral inicial)', 2);
    alarm('AlbTargetLatencyP95', apiTargets.metrics.targetResponseTime({ period: Duration.minutes(5), statistic: 'p95' }), 3, 'API: latencia p95 >= 3 s durante 15 min (umbral inicial, a calibrar con datos del piloto)');
    alarm('ApiUnhealthyHosts', apiTargets.metrics.unhealthyHostCount({ period: Duration.minutes(1), statistic: 'Maximum' }), 1, 'API: al menos una tarea no saludable en el grupo de destino', 3);
    alarm('CloudFront5xxRate', this.distribution.metric5xxErrorRate({ period: Duration.minutes(5) }), 5, 'CloudFront: >= 5% de respuestas 5xx durante 15 min');
    for (const [name, svc] of Object.entries(this.services)) {
      alarm(`${name}Cpu`, svc.metricCpuUtilization({ period: Duration.minutes(5) }), 85, `Servicio ${name}: CPU >= 85% durante 15 min`);
      alarm(`${name}Memory`, svc.metricMemoryUtilization({ period: Duration.minutes(5) }), 85, `Servicio ${name}: memoria >= 85% durante 15 min`);
    }

    // ── Salidas ────────────────────────────────────────────────────────────────────────────────────────────────────
    const out = (oid: string, value: string, description: string): CfnOutput => new CfnOutput(this, oid, { value, description });
    out('DistributionDomainName', this.distribution.distributionDomainName, `DNS: ${inputs.appDomainName} (CNAME/ALIAS) → este dominio de CloudFront`);
    out('DistributionId', this.distribution.distributionId, 'Id de la distribución (invalidaciones de caché tras publicar la SPA)');
    out('AlbDnsName', this.loadBalancer.loadBalancerDnsName, `DNS: ${inputs.originDomainName} (CNAME/ALIAS) → este ALB`);
    out('SpaBucketName', this.spaBucket.bucketName, 'Bucket de la SPA (aws s3 sync frontend/dist)');
    out('ClusterName', this.cluster.clusterName, 'Clúster ECS');
    out('MigrateTaskDefinition', migrateTask.family, 'Familia de la tarea de migraciones (RunTask previo a cada despliegue)');
    out('JobSubnets', subnetIds.join(','), 'Subredes privadas para RunTask');
    out('JobSecurityGroup', sgs.worker.securityGroupId, 'Grupo de seguridad para RunTask');
    out('TenantDataAccessRoleArn', this.tenantDataAccessRole.roleArn, 'Rol ABAC por empresa (DISEÑADO: la aplicación aún no lo asume)');
  }
}
