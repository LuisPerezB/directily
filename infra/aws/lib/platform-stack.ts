import { CfnOutput, Duration, RemovalPolicy, Stack, StackProps, Tags } from 'aws-cdk-lib';
import * as budgets from 'aws-cdk-lib/aws-budgets';
import * as cloudwatch from 'aws-cdk-lib/aws-cloudwatch';
import * as cwActions from 'aws-cdk-lib/aws-cloudwatch-actions';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as ecr from 'aws-cdk-lib/aws-ecr';
import * as efs from 'aws-cdk-lib/aws-efs';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as kms from 'aws-cdk-lib/aws-kms';
import * as logs from 'aws-cdk-lib/aws-logs';
import * as rds from 'aws-cdk-lib/aws-rds';
import * as s3 from 'aws-cdk-lib/aws-s3';
import * as secretsmanager from 'aws-cdk-lib/aws-secretsmanager';
import * as sns from 'aws-cdk-lib/aws-sns';
import * as subs from 'aws-cdk-lib/aws-sns-subscriptions';
import * as sqs from 'aws-cdk-lib/aws-sqs';
import { Construct } from 'constructs';
import { ackAll } from './nag';
import { EnvironmentConfig } from './config/environments';
import { OwnerInputs } from './config/inputs';
import { DATA_BUCKETS, DataBucketName, POSIX, QUEUE_NAMES, QueueName, dataBucketName, secretPrefix } from './names';

export interface PlatformStackProps extends StackProps {
  readonly config: EnvironmentConfig;
  readonly inputs: OwnerInputs;
}

export interface QueuePair {
  readonly queue: sqs.Queue;
  readonly dlq: sqs.Queue;
}

/**
 * Pila de PLATAFORMA (datos y base): red, claves KMS, S3, RDS, EFS, SQS, ECR, secretos, alarmas de datos y presupuesto.
 * Todo lo que tiene datos o un ciclo de vida lento vive aquí; el cómputo y el borde viven en AppStack (que depende de esta).
 * Los grupos de seguridad se crean aquí (también el del ALB) para que las dependencias entre pilas vayan en un solo sentido.
 */
export class PlatformStack extends Stack {
  readonly dataKey: kms.Key;
  readonly platformKey: kms.Key;
  readonly vpc: ec2.Vpc;
  readonly securityGroups: {
    readonly alb: ec2.SecurityGroup;
    readonly api: ec2.SecurityGroup;
    readonly worker: ec2.SecurityGroup;
    readonly engine: ec2.SecurityGroup;
    readonly db: ec2.SecurityGroup;
    readonly efs: ec2.SecurityGroup;
  };
  readonly accessLogsBucket: s3.Bucket;
  readonly buckets: Record<DataBucketName, s3.Bucket>;
  readonly database: rds.DatabaseInstance;
  readonly databaseSecret: secretsmanager.ISecret;
  readonly queues: Record<QueueName, QueuePair>;
  readonly repositories: { readonly backend: ecr.Repository; readonly engine: ecr.Repository; readonly web: ecr.Repository };
  readonly secrets: { readonly appKey: secretsmanager.Secret; readonly engineApiToken: secretsmanager.Secret; readonly originVerify: secretsmanager.Secret };
  readonly alarmTopic: sns.Topic;
  readonly fileSystem: efs.FileSystem;
  readonly accessPoints: { readonly backendStorage: efs.AccessPoint; readonly engineArtifacts: efs.AccessPoint };

  constructor(scope: Construct, id: string, props: PlatformStackProps) {
    super(scope, id, props);
    const { config: cfg, inputs } = props;
    const env = cfg.name;
    const region = this.region;
    const account = this.account;

    Tags.of(this).add('predictily:stack', 'platform');

    // ── Claves KMS (rotación anual automática) ─────────────────────────────────────────────────────────────────────
    this.dataKey = new kms.Key(this, 'DataKey', {
      alias: `alias/predictily-${env}-data`,
      description: `Predictily ${env}: cifrado de Bronze, Silver, Gold y artefactos de modelos (S3)`,
      enableKeyRotation: true,
      removalPolicy: RemovalPolicy.RETAIN,
    });
    this.platformKey = new kms.Key(this, 'PlatformKey', {
      alias: `alias/predictily-${env}-platform`,
      description: `Predictily ${env}: cifrado de RDS, EFS, SQS, SNS, Secrets Manager, ECR y CloudWatch Logs`,
      enableKeyRotation: true,
      removalPolicy: RemovalPolicy.RETAIN,
    });
    // CloudWatch Logs cifra los grupos con la clave de plataforma (la política de la clave debe permitirlo al servicio).
    this.platformKey.addToResourcePolicy(
      new iam.PolicyStatement({
        sid: 'PermitirCloudWatchLogs',
        principals: [new iam.ServicePrincipal(`logs.${region}.amazonaws.com`)],
        actions: ['kms:Encrypt*', 'kms:Decrypt*', 'kms:ReEncrypt*', 'kms:GenerateDataKey*', 'kms:Describe*'],
        resources: ['*'],
        conditions: { ArnLike: { 'kms:EncryptionContext:aws:logs:arn': `arn:${this.partition}:logs:${region}:${account}:log-group:*` } },
      }),
    );
    // Las alarmas de CloudWatch publican en un tema SNS cifrado con CMK: el servicio necesita la clave.
    this.platformKey.addToResourcePolicy(
      new iam.PolicyStatement({
        sid: 'PermitirAlarmasCloudWatch',
        principals: [new iam.ServicePrincipal('cloudwatch.amazonaws.com')],
        actions: ['kms:Decrypt', 'kms:GenerateDataKey*'],
        resources: ['*'],
        conditions: { StringEquals: { 'aws:SourceAccount': account } },
      }),
    );

    // ── Red: VPC privada ───────────────────────────────────────────────────────────────────────────────────────────
    const flowLogs = new logs.LogGroup(this, 'VpcFlowLogs', {
      encryptionKey: this.platformKey,
      retention: cfg.logRetention,
      removalPolicy: RemovalPolicy.RETAIN,
    });
    this.vpc = new ec2.Vpc(this, 'Vpc', {
      ipAddresses: ec2.IpAddresses.cidr(cfg.vpcCidr),
      availabilityZones: [...inputs.availabilityZones],
      natGateways: cfg.natGateways,
      subnetConfiguration: [
        { name: 'public', subnetType: ec2.SubnetType.PUBLIC, cidrMask: 24, mapPublicIpOnLaunch: false },
        { name: 'app', subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS, cidrMask: 22 },
        { name: 'data', subnetType: ec2.SubnetType.PRIVATE_ISOLATED, cidrMask: 24 },
      ],
      gatewayEndpoints: { S3: { service: ec2.GatewayVpcEndpointAwsService.S3 } },
      flowLogs: {
        all: { destination: ec2.FlowLogDestination.toCloudWatchLogs(flowLogs), trafficType: ec2.FlowLogTrafficType.ALL },
      },
    });
    if (cfg.interfaceEndpoints) {
      const endpointSg = new ec2.SecurityGroup(this, 'EndpointsSg', { vpc: this.vpc, description: 'Endpoints de interfaz: 443 desde la VPC', allowAllOutbound: false });
      endpointSg.addIngressRule(ec2.Peer.ipv4(this.vpc.vpcCidrBlock), ec2.Port.tcp(443), 'HTTPS desde la VPC');
      const services: Record<string, ec2.InterfaceVpcEndpointAwsService> = {
        EcrApi: ec2.InterfaceVpcEndpointAwsService.ECR,
        EcrDocker: ec2.InterfaceVpcEndpointAwsService.ECR_DOCKER,
        Logs: ec2.InterfaceVpcEndpointAwsService.CLOUDWATCH_LOGS,
        SecretsManager: ec2.InterfaceVpcEndpointAwsService.SECRETS_MANAGER,
        Sqs: ec2.InterfaceVpcEndpointAwsService.SQS,
        Kms: ec2.InterfaceVpcEndpointAwsService.KMS,
      };
      for (const [name, service] of Object.entries(services)) {
        this.vpc.addInterfaceEndpoint(`${name}Endpoint`, {
          service,
          subnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
          securityGroups: [endpointSg],
          privateDnsEnabled: true,
        });
      }
    }

    // ── Grupos de seguridad (mínimo privilegio entre capas) ────────────────────────────────────────────────────────
    const sg = (name: string, description: string, allowAllOutbound: boolean): ec2.SecurityGroup =>
      new ec2.SecurityGroup(this, name, { vpc: this.vpc, description, allowAllOutbound });
    const alb = sg('AlbSg', 'ALB: solo CloudFront (lista de prefijos administrada) por 443', false);
    const api = sg('ApiSg', 'Tareas API (nginx + php-fpm)', false);
    const worker = sg('WorkerSg', 'Workers de colas, programador y tareas efimeras (salida abierta: conectores SQL del cliente)', true);
    const engine = sg('EngineSg', 'Motor predictivo (8100 solo desde API y workers)', false);
    const db = sg('DbSg', 'RDS PostgreSQL: 5432 solo desde API, workers y motor', false);
    const files = sg('EfsSg', 'EFS: 2049 solo desde API, workers y motor', false);
    this.securityGroups = { alb, api, worker, engine, db, efs: files };

    alb.addIngressRule(ec2.Peer.prefixList(inputs.cloudFrontOriginPrefixListId), ec2.Port.tcp(443), 'CloudFront (origin-facing) hacia el ALB');
    // Salida de API y motor: HTTPS (SQS, S3 por el endpoint de pasarela, ECR, Secrets Manager, KMS) hacia cualquier destino.
    api.addEgressRule(ec2.Peer.anyIpv4(), ec2.Port.tcp(443), 'HTTPS a servicios de AWS');
    engine.addEgressRule(ec2.Peer.anyIpv4(), ec2.Port.tcp(443), 'HTTPS a servicios de AWS');
    for (const client of [api, worker, engine]) {
      db.addIngressRule(client, ec2.Port.tcp(5432), 'PostgreSQL desde la capa de aplicación');
      files.addIngressRule(client, ec2.Port.tcp(2049), 'NFS (EFS) desde la capa de aplicación');
    }
    for (const client of [api, engine]) {
      client.addEgressRule(db, ec2.Port.tcp(5432), 'PostgreSQL');
      client.addEgressRule(files, ec2.Port.tcp(2049), 'NFS (EFS)');
    }
    for (const client of [api, worker]) engine.addIngressRule(client, ec2.Port.tcp(8100), 'API interna del motor');
    api.addEgressRule(engine, ec2.Port.tcp(8100), 'API interna del motor'); // worker ya tiene salida abierta

    // ── S3: registro de accesos y capas de datos ───────────────────────────────────────────────────────────────────
    this.accessLogsBucket = new s3.Bucket(this, 'AccessLogsBucket', {
      encryption: s3.BucketEncryption.S3_MANAGED, // el destino de los registros de acceso de S3 no admite SSE-KMS
      blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      minimumTLSVersion: 1.2,
      objectOwnership: s3.ObjectOwnership.BUCKET_OWNER_ENFORCED,
      lifecycleRules: [{ id: 'expirar-registros', expiration: Duration.days(cfg.accessLogsRetentionDays), abortIncompleteMultipartUploadAfter: Duration.days(7) }],
      removalPolicy: RemovalPolicy.RETAIN,
    });
    ackAll(this.accessLogsBucket, [
      { id: 'AwsSolutions-S1', reason: 'Es el bucket destino de los registros de acceso de los demás buckets; registrar sus propios accesos crearía recursión sin valor de auditoría.' },
    ]);

    const noncurrent: Record<DataBucketName, number> = cfg.noncurrentVersionDays;
    const bucketsPartial: Partial<Record<DataBucketName, s3.Bucket>> = {};
    for (const name of DATA_BUCKETS) {
      bucketsPartial[name] = new s3.Bucket(this, `${name[0].toUpperCase()}${name.slice(1)}Bucket`, {
        bucketName: dataBucketName(env, name, account, region),
        encryption: s3.BucketEncryption.KMS,
        encryptionKey: this.dataKey,
        bucketKeyEnabled: true,
        blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
        enforceSSL: true,
        minimumTLSVersion: 1.2,
        versioned: true,
        objectOwnership: s3.ObjectOwnership.BUCKET_OWNER_ENFORCED,
        serverAccessLogsBucket: this.accessLogsBucket,
        serverAccessLogsPrefix: `s3/${name}/`,
        lifecycleRules: [
          { id: 'abortar-multipart', abortIncompleteMultipartUploadAfter: Duration.days(7) },
          { id: 'versiones-no-vigentes', noncurrentVersionExpiration: Duration.days(noncurrent[name]) },
          { id: 'marcas-de-borrado', expiredObjectDeleteMarker: true },
        ],
        removalPolicy: RemovalPolicy.RETAIN,
      });
    }
    this.buckets = bucketsPartial as Record<DataBucketName, s3.Bucket>;

    // ── Secretos generados (valores jamás en la plantilla ni en el código) ─────────────────────────────────────────
    const sp = secretPrefix(env);
    const generated = (id: string, name: string, length: number, retain: boolean): secretsmanager.Secret =>
      new secretsmanager.Secret(this, id, {
        secretName: `${sp}/${name}`,
        encryptionKey: this.platformKey,
        generateSecretString: { passwordLength: length, excludePunctuation: true, includeSpace: false },
        removalPolicy: retain ? RemovalPolicy.RETAIN : RemovalPolicy.DESTROY,
      });
    this.secrets = {
      // Laravel acepta una clave cruda de 32 caracteres (AES-256). Si se pierde, los datos cifrados no se pueden leer.
      appKey: generated('AppKey', 'app-key', 32, true),
      engineApiToken: generated('EngineApiToken', 'engine-api-token', 48, false),
      originVerify: generated('OriginVerify', 'origin-verify', 40, false),
    };
    ackAll(
      Object.values(this.secrets),
      [
        {
          id: 'AwsSolutions-SMG4',
          reason:
            'Credencial compartida entre servicios que la leen UNA vez al iniciar la tarea (variable de entorno de ECS): una rotación automática dejaría tareas en ejecución con el valor anterior. ' +
            'Se rota con un procedimiento manual con redespliegue (docs/operations/AWS_DEPLOYMENT.md §9); la rotación automática queda DISEÑADA para cuando la aplicación recargue credenciales.',
        },
      ],
    );

    // ── RDS PostgreSQL privado (Single-AZ inicial: ADR-V25-09) ─────────────────────────────────────────────────────
    const dbCredentials = rds.Credentials.fromGeneratedSecret('predictily', {
      secretName: `${sp}/database`,
      encryptionKey: this.platformKey,
      // Solo alfanuméricos: la contraseña se usa también dentro de la URL de conexión del motor.
      excludeCharacters: ' !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~',
    });
    const parameterGroup = new rds.ParameterGroup(this, 'DatabaseParameters', {
      engine: rds.DatabaseInstanceEngine.postgres({ version: rds.PostgresEngineVersion.VER_16 }),
      description: `Predictily ${env}: TLS obligatorio y registro de conexiones`,
      parameters: { 'rds.force_ssl': '1', log_connections: '1', log_disconnections: '1', log_min_duration_statement: '2000' },
    });
    const dbIdentifier = `predictily-${env}`;
    const dbLogGroups = ['postgresql', 'upgrade'].map(
      (kind) =>
        new logs.LogGroup(this, `DatabaseLogs${kind}`, {
          logGroupName: `/aws/rds/instance/${dbIdentifier}/${kind}`,
          encryptionKey: this.platformKey,
          retention: cfg.logRetention,
          removalPolicy: RemovalPolicy.RETAIN,
        }),
    );
    this.database = new rds.DatabaseInstance(this, 'Database', {
      instanceIdentifier: dbIdentifier,
      engine: rds.DatabaseInstanceEngine.postgres({ version: rds.PostgresEngineVersion.VER_16 }),
      instanceType: new ec2.InstanceType(cfg.database.instanceType),
      vpc: this.vpc,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_ISOLATED },
      securityGroups: [db],
      publiclyAccessible: false,
      databaseName: 'predictily',
      credentials: dbCredentials,
      parameterGroup,
      allocatedStorage: cfg.database.allocatedStorageGiB,
      maxAllocatedStorage: cfg.database.maxAllocatedStorageGiB,
      storageType: rds.StorageType.GP3,
      storageEncrypted: true,
      storageEncryptionKey: this.platformKey,
      multiAz: cfg.database.multiAz,
      backupRetention: Duration.days(cfg.database.backupRetentionDays),
      preferredBackupWindow: cfg.database.backupWindowUtc,
      preferredMaintenanceWindow: cfg.database.maintenanceWindowUtc,
      deleteAutomatedBackups: false,
      copyTagsToSnapshot: true,
      deletionProtection: cfg.database.deletionProtection,
      removalPolicy: RemovalPolicy.SNAPSHOT,
      autoMinorVersionUpgrade: true,
      enablePerformanceInsights: true,
      performanceInsightEncryptionKey: this.platformKey,
      cloudwatchLogsExports: ['postgresql', 'upgrade'],
    });
    for (const lg of dbLogGroups) this.database.node.addDependency(lg);
    this.databaseSecret = this.database.secret!;
    const dbSuppressions = [
      {
        id: 'AwsSolutions-RDS3',
        reason: `Single-AZ inicial por decisión documentada (ADR-V25-09, docs/architecture/AWS_ARCHITECTURE.md §6): RTO/RPO objetivo declarados y criterio explícito para pasar a Multi-AZ. Parámetro database.multiAz de lib/config/environments.ts (${cfg.name}).`,
      },
      {
        id: 'AwsSolutions-RDS6',
        reason: 'El backend (Laravel) y el motor se autentican con usuario y contraseña desde Secrets Manager; la autenticación IAM de base de datos no está soportada por la aplicación actual. DISEÑADO para cuando existan roles por servicio (V25-AWS-034).',
      },
      {
        id: 'AwsSolutions-RDS11',
        reason: 'El acceso lo controla el grupo de seguridad (5432 solo desde API, workers y motor) en subredes aisladas sin ruta a Internet; cambiar el puerto sería oscuridad, no un control, y rompería los valores por defecto de la aplicación.',
      },
    ];
    if (!cfg.database.deletionProtection) {
      dbSuppressions.push({
        id: 'AwsSolutions-RDS10',
        reason: 'Solo en staging: sin protección contra borrado para poder destruir y recrear el entorno de pruebas (sin datos reales de clientes). Production la tiene activa.',
      });
    }
    ackAll(this.database, dbSuppressions);
    ackAll(this.database.node.findChild('Secret'), [
      {
        id: 'AwsSolutions-SMG4',
        reason:
          'Las tareas de ECS leen la contraseña UNA vez al iniciar; una rotación automática las dejaría con la contraseña anterior. Rotación manual con redespliegue (docs/operations/AWS_DEPLOYMENT.md §9); rotación automática DISEÑADA para cuando la aplicación recargue credenciales.',
      },
    ]);

    // ── EFS: almacenamiento POSIX compartido (importaciones/exportaciones del backend y artefactos del motor) ────────
    // Necesario porque ImportService usa rutas locales (Storage::path) compartidas entre la API y los workers, y porque el
    // backend verifica en solo lectura los artefactos que escribe el motor. Se retira cuando ambos usen el almacén de objetos.
    this.fileSystem = new efs.FileSystem(this, 'FileSystem', {
      vpc: this.vpc,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_ISOLATED },
      securityGroup: files,
      encrypted: true,
      kmsKey: this.platformKey,
      performanceMode: efs.PerformanceMode.GENERAL_PURPOSE,
      throughputMode: efs.ThroughputMode.ELASTIC,
      lifecyclePolicy: efs.LifecyclePolicy.AFTER_30_DAYS,
      outOfInfrequentAccessPolicy: efs.OutOfInfrequentAccessPolicy.AFTER_1_ACCESS,
      enableAutomaticBackups: true,
      // `allowAnonymousAccess: true` solo desactiva la sentencia Allow que CDK agrega por defecto (a `*`); NO abre el acceso anónimo:
      // al existir una política sin ningún Allow, el montaje sin identidad IAM queda denegado.
      allowAnonymousAccess: true,
      // Política del sistema de archivos SOLO con denegaciones: ningún Allow para `*` (el valor por defecto de CDK concede
      // ClientRootAccess/ClientWrite a cualquier principal autenticado que llegue por un destino de montaje). El acceso lo conceden
      // las políticas de identidad de cada rol de tarea, limitadas a su punto de acceso; sin punto de acceso o sin TLS se deniega.
      fileSystemPolicy: new iam.PolicyDocument({
        statements: [
          new iam.PolicyStatement({
            sid: 'DenegarSinPuntoDeAcceso',
            effect: iam.Effect.DENY,
            principals: [new iam.AnyPrincipal()],
            actions: ['elasticfilesystem:ClientMount', 'elasticfilesystem:ClientWrite', 'elasticfilesystem:ClientRootAccess'],
            conditions: { Null: { 'elasticfilesystem:AccessPointArn': 'true' } },
          }),
          new iam.PolicyStatement({
            sid: 'DenegarSinTLS',
            effect: iam.Effect.DENY,
            principals: [new iam.AnyPrincipal()],
            actions: ['elasticfilesystem:*'],
            conditions: { Bool: { 'aws:SecureTransport': 'false' } },
          }),
        ],
      }),
      removalPolicy: RemovalPolicy.RETAIN,
    });
    this.accessPoints = {
      backendStorage: this.fileSystem.addAccessPoint('BackendStorageAp', {
        path: '/backend-storage',
        createAcl: { ownerUid: POSIX.backend.uid, ownerGid: POSIX.backend.gid, permissions: '0750' },
        posixUser: { uid: POSIX.backend.uid, gid: POSIX.backend.gid },
      }),
      engineArtifacts: this.fileSystem.addAccessPoint('EngineArtifactsAp', {
        path: '/engine-artifacts',
        createAcl: { ownerUid: POSIX.engine.uid, ownerGid: POSIX.engine.gid, permissions: '0750' },
        posixUser: { uid: POSIX.engine.uid, gid: POSIX.engine.gid },
      }),
    };

    // ── Colas SQS con DLQ ──────────────────────────────────────────────────────────────────────────────────────────
    // Visibilidad > --timeout del worker (imports/default: 600 s; forecasting: 1000 s), igual que REDIS_QUEUE_RETRY_AFTER.
    const visibility: Record<QueueName, number> = { imports: 700, default: 700, forecasting: 1200 };
    const queuesPartial: Partial<Record<QueueName, QueuePair>> = {};
    for (const name of QUEUE_NAMES) {
      const base = `${name}-predictily-${env}`;
      const dlq = new sqs.Queue(this, `${name}Dlq`, {
        queueName: `${base}-dlq`,
        encryption: sqs.QueueEncryption.KMS,
        encryptionMasterKey: this.platformKey,
        enforceSSL: true,
        retentionPeriod: Duration.days(cfg.queue.dlqRetentionDays),
      });
      const queue = new sqs.Queue(this, `${name}Queue`, {
        queueName: base,
        encryption: sqs.QueueEncryption.KMS,
        encryptionMasterKey: this.platformKey,
        enforceSSL: true,
        visibilityTimeout: Duration.seconds(visibility[name]),
        receiveMessageWaitTime: Duration.seconds(20),
        deadLetterQueue: { queue: dlq, maxReceiveCount: cfg.queue.maxReceiveCount },
      });
      queuesPartial[name] = { queue, dlq };
    }
    this.queues = queuesPartial as Record<QueueName, QueuePair>;

    // ── Repositorios de imágenes ───────────────────────────────────────────────────────────────────────────────────
    const repo = (id: string, name: string): ecr.Repository =>
      new ecr.Repository(this, id, {
        repositoryName: `predictily/${env}/${name}`,
        imageScanOnPush: true,
        imageTagMutability: ecr.TagMutability.IMMUTABLE,
        encryption: ecr.RepositoryEncryption.KMS,
        encryptionKey: this.platformKey,
        lifecycleRules: [{ description: 'Conserva las 25 imágenes más recientes', maxImageCount: 25 }],
        removalPolicy: RemovalPolicy.RETAIN,
      });
    this.repositories = { backend: repo('BackendRepo', 'backend'), engine: repo('EngineRepo', 'engine'), web: repo('WebRepo', 'web') };

    // ── Alarmas y presupuesto ──────────────────────────────────────────────────────────────────────────────────────
    this.alarmTopic = new sns.Topic(this, 'AlarmTopic', { topicName: `predictily-${env}-alarms`, masterKey: this.platformKey, enforceSSL: true });
    for (const email of inputs.alertEmails) this.alarmTopic.addSubscription(new subs.EmailSubscription(email));
    const notify = new cwActions.SnsAction(this.alarmTopic);
    const alarm = (id: string, metric: cloudwatch.IMetric, threshold: number, op: cloudwatch.ComparisonOperator, description: string, evaluationPeriods = 3, treatMissing = cloudwatch.TreatMissingData.NOT_BREACHING): cloudwatch.Alarm => {
      const a = new cloudwatch.Alarm(this, id, {
        alarmName: `predictily-${env}-${id}`,
        alarmDescription: description,
        metric,
        threshold,
        comparisonOperator: op,
        evaluationPeriods,
        treatMissingData: treatMissing,
      });
      a.addAlarmAction(notify);
      return a;
    };
    const gte = cloudwatch.ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD;
    const lt = cloudwatch.ComparisonOperator.LESS_THAN_THRESHOLD;
    alarm('RdsCpuHigh', this.database.metricCPUUtilization({ period: Duration.minutes(5) }), 80, gte, 'RDS: CPU >= 80% durante 15 min (umbral inicial, a calibrar en el piloto)');
    alarm('RdsFreeStorageLow', this.database.metricFreeStorageSpace({ period: Duration.minutes(5) }), cfg.database.allocatedStorageGiB * 0.2 * 1024 ** 3, lt, 'RDS: menos del 20% del almacenamiento asignado libre', 1);
    alarm('RdsFreeableMemoryLow', this.database.metricFreeableMemory({ period: Duration.minutes(5) }), 256 * 1024 ** 2, lt, 'RDS: memoria liberable < 256 MiB durante 15 min');
    for (const name of QUEUE_NAMES) {
      const { queue, dlq } = this.queues[name];
      alarm(`${name}DlqNotEmpty`, dlq.metricApproximateNumberOfMessagesVisible({ period: Duration.minutes(1), statistic: 'Maximum' }), 1, gte, `DLQ de «${name}» con mensajes: revisar y reprocesar (docs/operations/AWS_DEPLOYMENT.md §10)`, 1);
      const maxAgeSeconds = name === 'forecasting' ? 3600 : 900;
      alarm(`${name}QueueOldestMessage`, queue.metricApproximateAgeOfOldestMessage({ period: Duration.minutes(5), statistic: 'Maximum' }), maxAgeSeconds, gte, `Cola «${name}»: mensaje más antiguo >= ${maxAgeSeconds} s (workers detenidos o saturados)`);
    }

    const budgetEmails = inputs.alertEmails.map((address) => ({ subscriptionType: 'EMAIL', address }));
    new budgets.CfnBudget(this, 'MonthlyCostBudget', {
      budget: {
        budgetName: `predictily-${env}-monthly`,
        budgetType: 'COST',
        timeUnit: 'MONTHLY',
        budgetLimit: { amount: inputs.monthlyBudgetUsd, unit: 'USD' },
        // Por defecto el presupuesto cubre TODA la cuenta (lo correcto con una cuenta por entorno). Con varios entornos en una misma
        // cuenta puede filtrarse por la etiqueta de entorno, pero la etiqueta debe estar ACTIVADA como etiqueta de asignación de costos
        // en Billing (tarda hasta ~24 h): mientras no lo esté, el filtro no ve gasto y las alertas no avisan. Hipótesis de formato del
        // filtro sin verificar en AWS.
        ...(inputs.budgetFilterByEnvironmentTag
          ? { filterExpression: { tags: { key: 'predictily:environment', values: [env], matchOptions: ['EQUALS'] } } }
          : {}),
      },
      notificationsWithSubscribers: [
        ...cfg.budgetActualThresholdsPct.map((threshold) => ({
          notification: { notificationType: 'ACTUAL', comparisonOperator: 'GREATER_THAN', threshold, thresholdType: 'PERCENTAGE' },
          subscribers: budgetEmails,
        })),
        {
          notification: { notificationType: 'FORECASTED', comparisonOperator: 'GREATER_THAN', threshold: cfg.budgetForecastThresholdPct, thresholdType: 'PERCENTAGE' },
          subscribers: budgetEmails,
        },
      ],
    });

    // ── Salidas (para el despliegue y la documentación; sin secretos) ──────────────────────────────────────────────
    const out = (id: string, value: string, description: string): CfnOutput => new CfnOutput(this, id, { value, description });
    out('DatabaseEndpoint', this.database.dbInstanceEndpointAddress, 'Punto de enlace privado de RDS (solo resoluble dentro de la VPC)');
    out('DatabaseSecretArn', this.databaseSecret.secretArn, 'ARN del secreto con las credenciales de RDS (valor NO incluido)');
    for (const name of DATA_BUCKETS) out(`${name}BucketName`, this.buckets[name].bucketName, `Bucket de la capa ${name}`);
    for (const [name, r] of Object.entries(this.repositories)) out(`${name}RepositoryUri`, r.repositoryUri, `Repositorio ECR de la imagen ${name}`);
    for (const name of QUEUE_NAMES) out(`${name}QueueUrl`, this.queues[name].queue.queueUrl, `Cola SQS «${name}»`);
  }
}
