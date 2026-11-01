import { App, Stack } from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import { CfnInstance } from 'aws-cdk-lib/aws-ec2';
import { ENVIRONMENTS, EnvName } from '../lib/config/environments';
import { DATA_BUCKETS, QUEUE_NAMES, dataBucketName } from '../lib/names';
import { Built, assertNoGpuNoEc2, asArray, build, cdkContext, iamStatements } from './helpers';

type Json = Record<string, any>;

describe.each(['staging', 'production'] as EnvName[])('PlatformStack · %s', (envName) => {
  let built: Built;
  let t: Template;
  let res: Record<string, Json>;
  const cfg = ENVIRONMENTS[envName];

  beforeAll(() => {
    built = build(envName);
    t = built.platformTemplate;
    res = t.toJSON().Resources;
  });

  const ofType = (type: string): Array<[string, Json]> => Object.entries(res).filter(([, r]) => r.Type === type);

  describe('S3: cifrado, versionado y acceso público bloqueado', () => {
    it('crea el bucket de registros y un bucket por capa (bronze, silver, gold, artifacts)', () => {
      t.resourceCountIs('AWS::S3::Bucket', 1 + DATA_BUCKETS.length);
      for (const layer of DATA_BUCKETS) {
        t.hasResourceProperties('AWS::S3::Bucket', { BucketName: dataBucketName(envName, layer, '111111111111', 'us-east-1') });
      }
    });

    it('NINGÚN bucket queda sin bloqueo de acceso público (las cuatro opciones activas)', () => {
      for (const [id, b] of ofType('AWS::S3::Bucket')) {
        expect({ id, pab: b.Properties.PublicAccessBlockConfiguration }).toEqual({
          id,
          pab: { BlockPublicAcls: true, BlockPublicPolicy: true, IgnorePublicAcls: true, RestrictPublicBuckets: true },
        });
      }
    });

    it('los buckets de datos usan SSE-KMS con la clave de datos, clave de bucket y versionado', () => {
      for (const layer of DATA_BUCKETS) {
        const [, b] = ofType('AWS::S3::Bucket').find(([, r]) => r.Properties.BucketName === dataBucketName(envName, layer, '111111111111', 'us-east-1'))!;
        const rule = b.Properties.BucketEncryption.ServerSideEncryptionConfiguration[0];
        expect(rule.ServerSideEncryptionByDefault.SSEAlgorithm).toBe('aws:kms');
        expect(rule.ServerSideEncryptionByDefault.KMSMasterKeyID).toBeDefined();
        expect(rule.BucketKeyEnabled).toBe(true);
        expect(b.Properties.VersioningConfiguration).toEqual({ Status: 'Enabled' });
        expect(b.DeletionPolicy).toBe('Retain');
        expect(b.Properties.LoggingConfiguration?.LogFilePrefix).toBe(`s3/${layer}/`);
      }
    });

    it('todos los buckets rechazan el tráfico sin TLS y no existe ninguna política de lectura pública', () => {
      const policies = ofType('AWS::S3::BucketPolicy');
      expect(policies).toHaveLength(1 + DATA_BUCKETS.length);
      for (const [, p] of policies) {
        const stmts = asArray<Json>(p.Properties.PolicyDocument.Statement);
        const denyInsecure = stmts.find((s) => s.Effect === 'Deny' && s.Condition?.Bool?.['aws:SecureTransport'] === 'false');
        expect(denyInsecure).toBeDefined();
        for (const s of stmts.filter((x) => x.Effect === 'Allow')) {
          expect(JSON.stringify(s.Principal)).not.toMatch(/"\*"/); // ningún Allow para el principal público
        }
      }
    });
  });

  describe('claves KMS', () => {
    it('hay dos claves (datos y plataforma) con rotación automática y alias por entorno', () => {
      t.resourceCountIs('AWS::KMS::Key', 2);
      for (const [, k] of ofType('AWS::KMS::Key')) expect(k.Properties.EnableKeyRotation).toBe(true);
      t.hasResourceProperties('AWS::KMS::Alias', { AliasName: `alias/predictily-${envName}-data` });
      t.hasResourceProperties('AWS::KMS::Alias', { AliasName: `alias/predictily-${envName}-platform` });
    });
  });

  describe('RDS PostgreSQL privado', () => {
    it('no es público, está cifrado con KMS, usa TLS obligatorio y respaldo con la retención del entorno', () => {
      t.resourceCountIs('AWS::RDS::DBInstance', 1);
      t.hasResourceProperties('AWS::RDS::DBInstance', {
        Engine: 'postgres',
        PubliclyAccessible: false,
        StorageEncrypted: true,
        KmsKeyId: Match.anyValue(),
        MultiAZ: false,
        BackupRetentionPeriod: cfg.database.backupRetentionDays,
        DeletionProtection: cfg.database.deletionProtection,
        DBInstanceClass: `db.${cfg.database.instanceType}`,
        EnablePerformanceInsights: true,
        CopyTagsToSnapshot: true,
        EnableCloudwatchLogsExports: ['postgresql', 'upgrade'],
      });
      t.hasResourceProperties('AWS::RDS::DBParameterGroup', { Parameters: Match.objectLike({ 'rds.force_ssl': '1' }) });
    });

    it('vive en subredes AISLADAS: sin ruta a Internet ni a un NAT', () => {
      const [, group] = ofType('AWS::RDS::DBSubnetGroup')[0];
      const subnetRefs = asArray<Json>(group.Properties.SubnetIds).map((s) => s.Ref);
      expect(subnetRefs).toHaveLength(2);
      const routeTables = new Set(
        ofType('AWS::EC2::SubnetRouteTableAssociation')
          .filter(([, a]) => subnetRefs.includes(a.Properties.SubnetId.Ref))
          .map(([, a]) => a.Properties.RouteTableId.Ref),
      );
      expect(routeTables.size).toBe(2);
      const routesOfDbTables = ofType('AWS::EC2::Route').filter(([, r]) => routeTables.has(r.Properties.RouteTableId.Ref));
      expect(routesOfDbTables).toHaveLength(0);
    });

    it('solo acepta conexiones al 5432 desde los grupos de seguridad de API, workers y motor (ningún CIDR)', () => {
      const dbSg = ofType('AWS::EC2::SecurityGroup').find(([, r]) => /RDS PostgreSQL/.test(r.Properties.GroupDescription))!;
      const ingress = ofType('AWS::EC2::SecurityGroupIngress').filter(([, r]) => r.Properties.GroupId?.['Fn::GetAtt']?.[0] === dbSg[0]);
      expect(ingress).toHaveLength(3);
      for (const [, r] of ingress) {
        expect(r.Properties.FromPort).toBe(5432);
        expect(r.Properties.ToPort).toBe(5432);
        expect(r.Properties.CidrIp).toBeUndefined();
        expect(r.Properties.SourceSecurityGroupId).toBeDefined();
      }
      expect(dbSg[1].Properties.SecurityGroupIngress).toBeUndefined();
    });

    it('la contraseña la genera Secrets Manager (cifrada con KMS): no hay credenciales en la plantilla', () => {
      t.hasResourceProperties('AWS::SecretsManager::Secret', {
        Name: `predictily/${envName}/database`,
        KmsKeyId: Match.anyValue(),
        GenerateSecretString: Match.objectLike({ GenerateStringKey: 'password', SecretStringTemplate: Match.stringLikeRegexp('"username":"predictily"') }),
      });
    });
  });

  describe('red', () => {
    it('VPC con registros de flujo, endpoint de pasarela de S3 y los NAT del entorno', () => {
      t.resourceCountIs('AWS::EC2::VPC', 1);
      t.resourceCountIs('AWS::EC2::FlowLog', 1);
      t.hasResourceProperties('AWS::EC2::FlowLog', { TrafficType: 'ALL' });
      t.resourceCountIs('AWS::EC2::NatGateway', cfg.natGateways);
      t.hasResourceProperties('AWS::EC2::VPCEndpoint', { VpcEndpointType: 'Gateway', ServiceName: Match.anyValue() });
    });

    it('el ALB solo acepta 443 desde la lista de prefijos de CloudFront; ninguna regla abre 0.0.0.0/0 de entrada', () => {
      const all = [...ofType('AWS::EC2::SecurityGroup'), ...ofType('AWS::EC2::SecurityGroupIngress')];
      for (const [, r] of all) {
        const inline = asArray<Json>(r.Properties.SecurityGroupIngress);
        for (const rule of [...inline, r.Properties]) {
          expect(rule.CidrIp).not.toBe('0.0.0.0/0');
          expect(rule.CidrIpv6).toBeUndefined();
        }
      }
      const albSg = ofType('AWS::EC2::SecurityGroup').find(([, r]) => /^ALB: solo CloudFront/.test(r.Properties.GroupDescription))!;
      const albIngress = [...ofType('AWS::EC2::SecurityGroupIngress')].filter(([, r]) => r.Properties.GroupId?.['Fn::GetAtt']?.[0] === albSg[0]);
      expect(albIngress).toHaveLength(1);
      expect(albIngress[0][1].Properties).toMatchObject({ SourcePrefixListId: 'pl-00000000', FromPort: 443, ToPort: 443, IpProtocol: 'tcp' });
    });

    it('el motor solo recibe 8100 desde la API y los workers', () => {
      const engineSg = ofType('AWS::EC2::SecurityGroup').find(([, r]) => /Motor predictivo/.test(r.Properties.GroupDescription))!;
      const ingress = ofType('AWS::EC2::SecurityGroupIngress').filter(([, r]) => r.Properties.GroupId?.['Fn::GetAtt']?.[0] === engineSg[0]);
      expect(ingress).toHaveLength(2);
      for (const [, r] of ingress) expect(r.Properties.FromPort).toBe(8100);
    });
  });

  describe('EFS (almacenamiento compartido POSIX)', () => {
    it('cifrado con KMS, respaldo automático, rendimiento elástico y dos puntos de acceso con identidad POSIX fija', () => {
      t.hasResourceProperties('AWS::EFS::FileSystem', {
        Encrypted: true,
        KmsKeyId: Match.anyValue(),
        ThroughputMode: 'elastic',
        BackupPolicy: { Status: 'ENABLED' },
      });
      const [, fsRes] = ofType('AWS::EFS::FileSystem')[0];
      const policy = asArray<Json>(fsRes.Properties.FileSystemPolicy.Statement);
      expect(policy.every((s) => s.Effect === 'Deny')).toBe(true); // ningún Allow a «*»
      expect(policy.some((s) => s.Condition?.Null?.['elasticfilesystem:AccessPointArn'] === 'true')).toBe(true); // solo por punto de acceso
      expect(policy.some((s) => s.Condition?.Bool?.['aws:SecureTransport'] === 'false')).toBe(true); // solo con TLS
      t.resourceCountIs('AWS::EFS::AccessPoint', 2);
      t.hasResourceProperties('AWS::EFS::AccessPoint', { PosixUser: { Uid: '33', Gid: '33' }, RootDirectory: Match.objectLike({ Path: '/backend-storage' }) });
      t.hasResourceProperties('AWS::EFS::AccessPoint', { PosixUser: { Uid: '10001', Gid: '10001' }, RootDirectory: Match.objectLike({ Path: '/engine-artifacts' }) });
    });
  });

  describe('SQS + DLQ', () => {
    it('cada cola de Laravel tiene su DLQ con redrive, cifrado KMS y TLS obligatorio', () => {
      t.resourceCountIs('AWS::SQS::Queue', QUEUE_NAMES.length * 2);
      for (const name of QUEUE_NAMES) {
        const base = `${name}-predictily-${envName}`;
        t.hasResourceProperties('AWS::SQS::Queue', {
          QueueName: base,
          KmsMasterKeyId: Match.anyValue(),
          ReceiveMessageWaitTimeSeconds: 20,
          RedrivePolicy: { deadLetterTargetArn: Match.anyValue(), maxReceiveCount: cfg.queue.maxReceiveCount },
        });
        t.hasResourceProperties('AWS::SQS::Queue', {
          QueueName: `${base}-dlq`,
          KmsMasterKeyId: Match.anyValue(),
          MessageRetentionPeriod: cfg.queue.dlqRetentionDays * 86400,
        });
      }
      for (const [, p] of ofType('AWS::SQS::QueuePolicy')) {
        const stmts = asArray<Json>(p.Properties.PolicyDocument.Statement);
        expect(stmts.some((s) => s.Effect === 'Deny' && s.Condition?.Bool?.['aws:SecureTransport'] === 'false')).toBe(true);
      }
    });

    it('la visibilidad supera el timeout del worker (imports/default 600 s; forecasting 1000 s)', () => {
      t.hasResourceProperties('AWS::SQS::Queue', { QueueName: `imports-predictily-${envName}`, VisibilityTimeout: 700 });
      t.hasResourceProperties('AWS::SQS::Queue', { QueueName: `default-predictily-${envName}`, VisibilityTimeout: 700 });
      t.hasResourceProperties('AWS::SQS::Queue', { QueueName: `forecasting-predictily-${envName}`, VisibilityTimeout: 1200 });
    });
  });

  describe('alarmas y presupuesto', () => {
    it('hay una alarma por DLQ que avisa al tema SNS cifrado con CMK', () => {
      for (const name of QUEUE_NAMES) {
        t.hasResourceProperties('AWS::CloudWatch::Alarm', {
          AlarmName: `predictily-${envName}-${name}DlqNotEmpty`,
          MetricName: 'ApproximateNumberOfMessagesVisible',
          Threshold: 1,
          AlarmActions: [Match.anyValue()],
        });
      }
      t.hasResourceProperties('AWS::SNS::Topic', { TopicName: `predictily-${envName}-alarms`, KmsMasterKeyId: Match.anyValue() });
      t.hasResourceProperties('AWS::SNS::Subscription', { Protocol: 'email' });
    });

    it('alarmas de RDS: CPU, almacenamiento libre y memoria', () => {
      for (const metric of ['CPUUtilization', 'FreeStorageSpace', 'FreeableMemory']) {
        t.hasResourceProperties('AWS::CloudWatch::Alarm', { Namespace: 'AWS/RDS', MetricName: metric });
      }
    });

    it('AWS Budgets: presupuesto mensual con alertas reales (50/80/100 %) y pronosticada por correo', () => {
      t.resourceCountIs('AWS::Budgets::Budget', 1);
      const [, b] = ofType('AWS::Budgets::Budget')[0];
      expect(b.Properties.Budget).toMatchObject({ BudgetType: 'COST', TimeUnit: 'MONTHLY', BudgetLimit: { Amount: 100, Unit: 'USD' } });
      const notifications = b.Properties.NotificationsWithSubscribers as Json[];
      const actual = notifications.filter((n) => n.Notification.NotificationType === 'ACTUAL').map((n) => n.Notification.Threshold).sort();
      expect(actual).toEqual([...cfg.budgetActualThresholdsPct].sort());
      expect(notifications.some((n) => n.Notification.NotificationType === 'FORECASTED')).toBe(true);
      for (const n of notifications) expect(n.Subscribers).toEqual([{ SubscriptionType: 'EMAIL', Address: 'alertas-ficticias@example.com' }]);
    });
  });

  describe('secretos e imágenes', () => {
    it('los secretos se generan en Secrets Manager con KMS y no existe ninguna SecretString literal', () => {
      for (const [id, s] of ofType('AWS::SecretsManager::Secret')) {
        expect({ id, hasLiteral: s.Properties.SecretString !== undefined }).toEqual({ id, hasLiteral: false });
        expect(s.Properties.KmsKeyId).toBeDefined();
      }
      for (const name of ['app-key', 'engine-api-token', 'origin-verify']) t.hasResourceProperties('AWS::SecretsManager::Secret', { Name: `predictily/${envName}/${name}` });
      // La clave de firma del motor la crea el propietario fuera de CloudFormation (nunca pasa por una plantilla).
      expect(JSON.stringify(res)).not.toMatch(/BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}/);
    });

    it('tres repositorios ECR con escaneo al subir, etiquetas inmutables y cifrado KMS', () => {
      t.resourceCountIs('AWS::ECR::Repository', 3);
      for (const [, r] of ofType('AWS::ECR::Repository')) {
        expect(r.Properties.ImageScanningConfiguration).toEqual({ ScanOnPush: true });
        expect(r.Properties.ImageTagMutability).toBe('IMMUTABLE');
        expect(r.Properties.EncryptionConfiguration.EncryptionType).toBe('KMS');
      }
    });
  });

  it('ninguna sentencia IAM de la pila usa acciones comodín totales (service:* ni *)', () => {
    for (const { owner, statement } of iamStatements(t)) {
      for (const action of asArray<string>(statement.Action)) {
        expect({ owner, action }).not.toEqual({ owner, action: '*' });
        expect({ owner, action: action.endsWith(':*') ? action : 'ok' }).toEqual({ owner, action: 'ok' });
      }
    }
  });

  it('no despliega cómputo con GPU ni instancias EC2 permanentes: solo RDS db.t4g.* y Fargate', () => {
    assertNoGpuNoEc2(t, [`db.${cfg.database.instanceType}`]);
    expect(ofType('AWS::EC2::Instance')).toHaveLength(0);
  });

  it('el verificador de GPU no es decorativo: rechaza una plantilla con una instancia acelerada', () => {
    const app = new App({ context: cdkContext() });
    const stack = new Stack(app, 'ConGpu', { env: { account: '111111111111', region: 'us-east-1' } });
    new CfnInstance(stack, 'Entrenador', { instanceType: 'g5.xlarge', imageId: 'ami-00000000' });
    expect(() => assertNoGpuNoEc2(Template.fromStack(stack))).toThrow();
  });

  describe('AWS Budgets filtrado por entorno (opcional)', () => {
    const budget = (template: Template): Json => Object.values(template.toJSON().Resources as Record<string, Json>).find((r) => r.Type === 'AWS::Budgets::Budget')!.Properties.Budget;

    it('por defecto cubre toda la cuenta (lo correcto con una cuenta por entorno)', () => {
      expect(budget(t).FilterExpression).toBeUndefined();
      expect(budget(t).CostFilters).toBeUndefined();
    });

    it('con budgetFilterByEnvironmentTag=true filtra por la etiqueta predictily:environment del entorno', () => {
      const filtered = build(envName, { context: { budgetFilterByEnvironmentTag: 'true' } });
      expect(budget(filtered.platformTemplate).FilterExpression).toEqual({ Tags: { Key: 'predictily:environment', Values: [envName], MatchOptions: ['EQUALS'] } });
    });
  });
});
