import { OriginRequestPolicy } from 'aws-cdk-lib/aws-cloudfront';
import { Match, Template } from 'aws-cdk-lib/assertions';
import { ENVIRONMENTS, EnvName } from '../lib/config/environments';
import { dataBucketName } from '../lib/names';
import { Built, assertNoGpuNoEc2, asArray, build, iamStatements } from './helpers';

type Json = Record<string, any>;

describe.each(['staging', 'production'] as EnvName[])('AppStack · %s', (envName) => {
  let built: Built;
  let t: Template;
  let res: Record<string, Json>;
  const cfg = ENVIRONMENTS[envName];

  beforeAll(() => {
    built = build(envName);
    t = built.appTemplate;
    res = t.toJSON().Resources;
  });

  const ofType = (type: string): Array<[string, Json]> => Object.entries(res).filter(([, r]) => r.Type === type);
  const taskDef = (family: string): Json => ofType('AWS::ECS::TaskDefinition').find(([, r]) => r.Properties.Family === `predictily-${envName}-${family}`)![1];
  const roleStatements = (logicalPrefix: string): Json[] =>
    iamStatements(t)
      .filter(({ owner }) => owner.startsWith(logicalPrefix.replace(/-/g, '')) && owner.includes('TaskRoleDefaultPolicy'))
      .map(({ statement }) => statement);
  const actionsOf = (statements: Json[]): string[] => statements.flatMap((s) => asArray<string>(s.Action));

  describe('borde: ALB, CloudFront y WAF', () => {
    it('el ALB tiene un único listener HTTPS (TLS 1.2+), cabeceras inválidas descartadas y respuesta 403 por defecto', () => {
      t.resourceCountIs('AWS::ElasticLoadBalancingV2::Listener', 1);
      t.hasResourceProperties('AWS::ElasticLoadBalancingV2::Listener', {
        Port: 443,
        Protocol: 'HTTPS',
        SslPolicy: 'ELBSecurityPolicy-TLS13-1-2-2021-06',
        DefaultActions: [{ Type: 'fixed-response', FixedResponseConfig: Match.objectLike({ StatusCode: '403' }) }],
      });
      t.hasResourceProperties('AWS::ElasticLoadBalancingV2::LoadBalancer', { Scheme: 'internet-facing', Type: 'application' });
      const attrs = Object.fromEntries(ofType('AWS::ElasticLoadBalancingV2::LoadBalancer')[0][1].Properties.LoadBalancerAttributes.map((a: Json) => [a.Key, a.Value]));
      expect(attrs['routing.http.drop_invalid_header_fields.enabled']).toBe('true');
      expect(attrs['access_logs.s3.enabled']).toBe('true');
      expect(attrs['deletion_protection.enabled'] === 'true').toBe(cfg.albDeletionProtection);
    });

    it('solo se reenvía a la API cuando llega la cabecera secreta de origen (resuelta desde Secrets Manager, no literal)', () => {
      const [, rule] = ofType('AWS::ElasticLoadBalancingV2::ListenerRule')[0];
      const cond = rule.Properties.Conditions[0];
      expect(cond.Field).toBe('http-header');
      expect(cond.HttpHeaderConfig.HttpHeaderName).toBe('X-Origin-Verify');
      expect(JSON.stringify(cond.HttpHeaderConfig.Values)).toContain('{{resolve:secretsmanager:');
    });

    it('CloudFront: HTTPS obligatorio, TLS 1.2, WAF, registros, HTTP/3 y OAC (no OAI) hacia el bucket privado', () => {
      t.resourceCountIs('AWS::CloudFront::Distribution', 1);
      t.hasResourceProperties('AWS::CloudFront::Distribution', {
        DistributionConfig: Match.objectLike({
          Aliases: [`app.${envName}.example.com`],
          DefaultRootObject: 'index.html',
          HttpVersion: 'http2and3',
          PriceClass: cfg.cloudFrontPriceClass,
          WebACLId: Match.anyValue(),
          Logging: Match.objectLike({ IncludeCookies: false }),
          ViewerCertificate: Match.objectLike({ MinimumProtocolVersion: 'TLSv1.2_2021', SslSupportMethod: 'sni-only' }),
          DefaultCacheBehavior: Match.objectLike({ ViewerProtocolPolicy: 'redirect-to-https', FunctionAssociations: [Match.objectLike({ EventType: 'viewer-request' })] }),
        }),
      });
      t.resourceCountIs('AWS::CloudFront::OriginAccessControl', 1);
      const dist = ofType('AWS::CloudFront::Distribution')[0][1].Properties.DistributionConfig;
      const s3Origin = dist.Origins.find((o: Json) => o.S3OriginConfig);
      expect(s3Origin.OriginAccessControlId).toBeDefined();
      expect(s3Origin.S3OriginConfig.OriginAccessIdentity).toBe('');
    });

    it('/api y /sanctum van al ALB por HTTPS con TLS 1.2, sin caché y con la cabecera de origen', () => {
      const dist = ofType('AWS::CloudFront::Distribution')[0][1].Properties.DistributionConfig;
      const paths = dist.CacheBehaviors.map((b: Json) => b.PathPattern).sort();
      expect(paths).toEqual(['/api/*', '/sanctum/*']);
      const apiOrigin = dist.Origins.find((o: Json) => o.CustomOriginConfig);
      expect(apiOrigin.DomainName).toBe(`origin.${envName}.example.com`);
      expect(apiOrigin.CustomOriginConfig).toMatchObject({ OriginProtocolPolicy: 'https-only', OriginSSLProtocols: ['TLSv1.2'] });
      expect(apiOrigin.OriginCustomHeaders[0].HeaderName).toBe('X-Origin-Verify');
      for (const b of dist.CacheBehaviors) {
        // Reenvía los encabezados CloudFront-* (CloudFront-Viewer-Address = IP real del cliente; ver AWS_ARCHITECTURE.md §10).
        expect(b.OriginRequestPolicyId).toBe(OriginRequestPolicy.ALL_VIEWER_AND_CLOUDFRONT_2022.originRequestPolicyId);
        expect(b.CachePolicyId).toBe('4135ea2d-6df8-44a3-9df3-4b5a84be39ad'); // CachingDisabled administrada
        expect(b.ViewerProtocolPolicy).toBe('redirect-to-https');
      }
    });

    it('la SPA vive en un bucket S3 privado, versionado, solo legible por CloudFront (OAC) y sin sitio web público', () => {
      const [spaId, spa] = ofType('AWS::S3::Bucket').find(([id]) => id.startsWith('SpaBucket'))!;
      expect(spa.Properties.PublicAccessBlockConfiguration).toEqual({ BlockPublicAcls: true, BlockPublicPolicy: true, IgnorePublicAcls: true, RestrictPublicBuckets: true });
      expect(spa.Properties.VersioningConfiguration).toEqual({ Status: 'Enabled' });
      expect(spa.Properties.WebsiteConfiguration).toBeUndefined();
      const policy = ofType('AWS::S3::BucketPolicy').find(([, p]) => p.Properties.Bucket.Ref === spaId)![1];
      const allow = asArray<Json>(policy.Properties.PolicyDocument.Statement).filter((s) => s.Effect === 'Allow');
      expect(allow).toHaveLength(1);
      expect(allow[0].Principal).toEqual({ Service: 'cloudfront.amazonaws.com' });
      expect(allow[0].Condition.StringEquals['AWS:SourceArn']).toBeDefined();
      expect(asArray<string>(allow[0].Action)).toEqual(['s3:GetObject']);
    });

    it('el WAF de CloudFront bloquea por tasa (autenticación y general) y las reglas administradas arrancan en modo count', () => {
      t.hasResourceProperties('AWS::WAFv2::WebACL', { Scope: 'CLOUDFRONT', DefaultAction: { Allow: {} } });
      const [, waf] = ofType('AWS::WAFv2::WebACL')[0];
      const rules: Json[] = waf.Properties.Rules;
      const rate = rules.filter((r) => r.Statement.RateBasedStatement);
      expect(rate).toHaveLength(2);
      for (const r of rate) expect(r.Action).toEqual({ Block: {} });
      expect(rate.map((r) => r.Statement.RateBasedStatement.Limit).sort((a, b) => a - b)).toEqual([cfg.waf.authRateLimit, cfg.waf.generalRateLimit]);
      const managed = rules.filter((r) => r.Statement.ManagedRuleGroupStatement);
      expect(managed.map((r) => r.Statement.ManagedRuleGroupStatement.Name)).toEqual([
        'AWSManagedRulesCommonRuleSet',
        'AWSManagedRulesKnownBadInputsRuleSet',
        'AWSManagedRulesAmazonIpReputationList',
      ]);
      for (const r of managed) expect(r.OverrideAction).toEqual(cfg.waf.managedRulesMode === 'count' ? { Count: {} } : { None: {} });
      // Las cargas CSV/Excel de hasta 25 MB no pueden caer en SizeRestrictions_BODY.
      expect(JSON.stringify(managed[0])).toContain('SizeRestrictions_BODY');
    });

    it('envía las mismas cabeceras de seguridad que nginx (HSTS, CSP, X-Frame-Options, Referrer-Policy)', () => {
      t.hasResourceProperties('AWS::CloudFront::ResponseHeadersPolicy', {
        ResponseHeadersPolicyConfig: Match.objectLike({
          SecurityHeadersConfig: Match.objectLike({
            StrictTransportSecurity: Match.objectLike({ IncludeSubdomains: true, AccessControlMaxAgeSec: 31536000 }),
            FrameOptions: Match.objectLike({ FrameOption: 'DENY' }),
            ContentTypeOptions: { Override: true },
            ContentSecurityPolicy: Match.objectLike({ ContentSecurityPolicy: Match.stringLikeRegexp("frame-ancestors 'none'") }),
          }),
        }),
      });
    });
  });

  describe('cómputo: ECS Fargate', () => {
    it('cinco servicios Fargate en subredes privadas, sin IP pública, con disyuntor de despliegue y sin ECS Exec', () => {
      t.resourceCountIs('AWS::ECS::Service', 5);
      for (const [, s] of ofType('AWS::ECS::Service')) {
        expect(s.Properties.LaunchType).toBe('FARGATE');
        expect(s.Properties.NetworkConfiguration.AwsvpcConfiguration.AssignPublicIp).toBe('DISABLED');
        expect(s.Properties.DeploymentConfiguration.DeploymentCircuitBreaker).toEqual({ Enable: true, Rollback: true });
        expect(s.Properties.EnableExecuteCommand).not.toBe(true);
      }
    });

    it('el motor se registra en Cloud Map (DNS privado), no tiene balanceador y su grupo de seguridad es el del motor', () => {
      t.resourceCountIs('AWS::ServiceDiscovery::PrivateDnsNamespace', 1);
      t.hasResourceProperties('AWS::ServiceDiscovery::PrivateDnsNamespace', { Name: `predictily-${envName}.internal` });
      const engine = ofType('AWS::ECS::Service').find(([, s]) => s.Properties.ServiceName === `predictily-${envName}-engine`)![1];
      expect(engine.Properties.LoadBalancers ?? []).toHaveLength(0);
      expect(engine.Properties.ServiceRegistries).toHaveLength(1);
      const api = ofType('AWS::ECS::Service').find(([, s]) => s.Properties.ServiceName === `predictily-${envName}-api`)![1];
      expect(api.Properties.LoadBalancers).toHaveLength(1);
      expect(api.Properties.LoadBalancers[0]).toMatchObject({ ContainerName: 'web', ContainerPort: 80 });
      expect(api.Properties.DesiredCount).toBe(cfg.services.api.desiredCount);
    });

    it('la plantilla de aplicación no contiene GPU, instancias EC2 ni capacity providers (verificación firme)', () => {
      assertNoGpuNoEc2(t);
    });

    it('las tareas usan solo CPU (sin GPU, sin EC2) y los tamaños del entorno', () => {
      for (const [, td] of ofType('AWS::ECS::TaskDefinition')) {
        expect(td.Properties.RequiresCompatibilities).toEqual(['FARGATE']);
        expect(td.Properties.NetworkMode).toBe('awsvpc');
        for (const c of td.Properties.ContainerDefinitions) expect(c.ResourceRequirements).toBeUndefined();
      }
      expect(taskDef('engine').Properties).toMatchObject({ Cpu: String(cfg.services.engine.cpu), Memory: String(cfg.services.engine.memoryMiB) });
      expect(taskDef('api').Properties).toMatchObject({ Cpu: String(cfg.services.api.cpu), Memory: String(cfg.services.api.memoryMiB) });
    });

    it('ningún secreto va como variable de entorno: DB, clave de la app, token del motor y clave de firma llegan por Secrets Manager', () => {
      const secretLike = /PASSWORD|SECRET|TOKEN|PRIVATE|APP_KEY|API_KEY|SIGNING_PRIVATE/i;
      for (const [, td] of ofType('AWS::ECS::TaskDefinition')) {
        for (const c of td.Properties.ContainerDefinitions) {
          for (const e of c.Environment ?? []) {
            expect({ container: c.Name, name: e.Name, secretLike: secretLike.test(e.Name) }).toEqual({ container: c.Name, name: e.Name, secretLike: false });
          }
        }
      }
      const backend = taskDef('api').Properties.ContainerDefinitions.find((c: Json) => c.Name === 'backend');
      expect(backend.Secrets.map((s: Json) => s.Name).sort()).toEqual(['APP_KEY', 'DB_HOST', 'DB_PASSWORD', 'DB_USERNAME', 'ENGINE_API_TOKEN']);
      const engine = taskDef('engine').Properties.ContainerDefinitions[0];
      expect(engine.Secrets.map((s: Json) => s.Name).sort()).toEqual(['DB_HOST', 'DB_PASSWORD', 'DB_USERNAME', 'ENGINE_API_TOKEN', 'ENGINE_SIGNING_PRIVATE_KEY']);
      // La clave de firma NO se crea en CloudFormation: se importa por nombre (la crea el propietario).
      expect(JSON.stringify(res)).toContain(`predictily/${envName}/engine-signing-key`);
    });

    it('la API usa colas SQS (sin Redis), sesiones y caché en base de datos y Bronze en S3 cifrado con la clave de datos', () => {
      const env = Object.fromEntries(taskDef('api').Properties.ContainerDefinitions.find((c: Json) => c.Name === 'backend').Environment.map((e: Json) => [e.Name, e.Value]));
      expect(env).toMatchObject({
        QUEUE_CONNECTION: 'sqs',
        SQS_SUFFIX: `-predictily-${envName}`,
        CACHE_STORE: 'database',
        SESSION_DRIVER: 'database',
        SESSION_SECURE_COOKIE: 'true',
        DB_SSLMODE: 'require',
        PREDICTILY_BRONZE_DISK: 'bronze_s3',
        PREDICTILY_BRONZE_S3_SSE: 'aws:kms',
        ENGINE_ALLOW_UNSIGNED_ARTIFACTS: 'false',
        APP_DEBUG: 'false',
      });
      expect(JSON.stringify(env)).not.toMatch(/redis/i);
    });

    it('los workers consumen las colas correctas con los mismos parámetros que docker-compose', () => {
      const cmd = (family: string): string[] => taskDef(family).Properties.ContainerDefinitions[0].Command;
      expect(cmd('worker-default')).toEqual(expect.arrayContaining(['queue:work', 'sqs', '--queue=imports,default', '--timeout=600']));
      expect(cmd('worker-forecasting')).toEqual(expect.arrayContaining(['queue:work', 'sqs', '--queue=forecasting', '--timeout=1000']));
      expect(cmd('scheduler')).toEqual(['php', 'artisan', 'schedule:work']);
      expect(cmd('migrate')).toEqual(['php', 'artisan', 'migrate', '--force', '--no-interaction']);
    });

    it('el almacenamiento compartido (EFS) va cifrado en tránsito y autorizado por IAM mediante puntos de acceso', () => {
      for (const family of ['api', 'worker-default', 'worker-forecasting', 'scheduler', 'engine', 'job']) {
        expect(taskDef(family).Properties.Volumes.length).toBeGreaterThan(0);
        for (const v of taskDef(family).Properties.Volumes) {
          expect(v.EFSVolumeConfiguration.TransitEncryption).toBe('ENABLED');
          expect(v.EFSVolumeConfiguration.AuthorizationConfig.IAM).toBe('ENABLED');
          expect(v.EFSVolumeConfiguration.AuthorizationConfig.AccessPointId).toBeDefined();
        }
      }
      // El backend ve los artefactos del motor SOLO en lectura; el motor los escribe.
      const mounts = (family: string, container: string): Json[] => taskDef(family).Properties.ContainerDefinitions.find((c: Json) => c.Name === container).MountPoints;
      expect(mounts('api', 'backend').find((m) => m.ContainerPath === '/data/artifacts')!.ReadOnly).toBe(true);
      expect(mounts('engine', 'engine').find((m) => m.ContainerPath === '/data/artifacts')!.ReadOnly).toBe(false);
    });

    it('registros de contenedores en CloudWatch Logs cifrados con KMS y con la retención del entorno', () => {
      const groups = ofType('AWS::Logs::LogGroup');
      expect(groups.length).toBeGreaterThanOrEqual(6);
      for (const [, g] of groups) {
        expect(g.Properties.KmsKeyId).toBeDefined();
        expect(g.Properties.RetentionInDays).toBe(cfg.logRetention);
      }
    });
  });

  describe('permisos de las tareas (mínimo privilegio)', () => {
    it('el motor no toca Bronze ni SQS: solo el bucket de artefactos y su punto de acceso EFS', () => {
      const statements = roleStatements('engineTask');
      expect(actionsOf(statements).some((a) => a.startsWith('sqs:'))).toBe(false);
      const resources = JSON.stringify(statements.map((s) => s.Resource));
      expect(resources).not.toContain(dataBucketName(envName, 'bronze', '111111111111', 'us-east-1'));
      expect(resources).toContain(dataBucketName(envName, 'artifacts', '111111111111', 'us-east-1'));
    });

    it('la API no puede borrar de Bronze; los workers y el programador sí (purga auditada), siempre bajo tenants/*', () => {
      const bronzeArn = `arn:aws:s3:::${dataBucketName(envName, 'bronze', '111111111111', 'us-east-1')}/tenants/*`;
      const bronzeActions = (prefix: string): string[] =>
        roleStatements(prefix).filter((s) => JSON.stringify(s.Resource).includes(bronzeArn)).flatMap((s) => asArray<string>(s.Action));
      expect(bronzeActions('apiTask')).toEqual(['s3:GetObject', 's3:PutObject']);
      expect(bronzeActions('worker-defaultTask')).toContain('s3:DeleteObject');
      expect(bronzeActions('schedulerTask')).toContain('s3:DeleteObject');
    });

    it('solo los workers pueden recibir mensajes; la API únicamente encola', () => {
      expect(actionsOf(roleStatements('apiTask'))).not.toContain('sqs:ReceiveMessage');
      expect(actionsOf(roleStatements('worker-forecastingTask'))).toContain('sqs:ReceiveMessage');
      expect(actionsOf(roleStatements('apiTask'))).toContain('sqs:SendMessage');
    });

    it('ninguna sentencia IAM de la pila usa Action "*" ni "servicio:*"', () => {
      for (const { owner, statement } of iamStatements(t)) {
        for (const action of asArray<string>(statement.Action)) {
          expect({ owner, wildcard: action === '*' || action.endsWith(':*') }).toEqual({ owner, wildcard: false });
        }
      }
    });

    it('el rol por empresa (ABAC) exige la etiqueta tenant_id con forma de UUID y limita S3 a tenants/${aws:PrincipalTag/tenant_id}/*', () => {
      const role = ofType('AWS::IAM::Role').find(([, r]) => r.Properties.RoleName === `predictily-${envName}-tenant-data-access`)![1];
      const trust = asArray<Json>(role.Properties.AssumeRolePolicyDocument.Statement);
      expect(trust.flatMap((s) => asArray<string>(s.Action)).sort()).toEqual(['sts:AssumeRole', 'sts:TagSession']);
      for (const s of trust) {
        expect(s.Condition.StringLike['aws:RequestTag/tenant_id']).toBe('????????-????-????-????-????????????');
        expect(s.Condition['ForAllValues:StringEquals']['aws:TagKeys']).toEqual(['tenant_id']);
        expect(JSON.stringify(s.Principal)).not.toContain('"*"');
      }
      expect(role.Properties.MaxSessionDuration).toBe(3600);
      const policy = ofType('AWS::IAM::Policy').find(([id]) => id.startsWith('TenantDataAccessRoleDefaultPolicy'))![1];
      const stmts = asArray<Json>(policy.Properties.PolicyDocument.Statement);
      const objects = stmts.filter((s) => asArray<string>(s.Action).includes('s3:GetObject'));
      expect(objects).toHaveLength(1);
      expect(objects[0].Action).toEqual(['s3:GetObject', 's3:PutObject']);
      const resources = asArray<string>(objects[0].Resource);
      expect(resources).toHaveLength(2); // bronze y artefactos
      for (const r of resources) expect(r).toMatch(/\/tenants\/\$\{aws:PrincipalTag\/tenant_id\}\/\*$/);
      const list = stmts.filter((s) => asArray<string>(s.Action).includes('s3:ListBucket'));
      for (const s of list) expect(s.Condition.StringLike['s3:prefix']).toEqual(['tenants/${aws:PrincipalTag/tenant_id}/*']);
    });
  });

  describe('EventBridge Scheduler', () => {
    it('lanza el comando diario de medición de uso como tarea efímera de ECS con DLQ y reintentos acotados', () => {
      t.hasResourceProperties('AWS::Scheduler::ScheduleGroup', { Name: `predictily-${envName}` });
      const [, sched] = ofType('AWS::Scheduler::Schedule')[0];
      expect(sched.Properties).toMatchObject({ Name: `usage-snapshot-${envName}`, ScheduleExpression: 'cron(30 10 * * ? *)', ScheduleExpressionTimezone: 'UTC', State: 'ENABLED', FlexibleTimeWindow: { Mode: 'OFF' } });
      expect(JSON.parse(sched.Properties.Target.Input)).toEqual({ containerOverrides: [{ name: 'backend', command: ['php', 'artisan', 'predictily:usage-snapshot'] }] });
      expect(sched.Properties.Target.EcsParameters).toMatchObject({ LaunchType: 'FARGATE', TaskCount: 1 });
      expect(sched.Properties.Target.EcsParameters.NetworkConfiguration.AwsvpcConfiguration.AssignPublicIp).toBe('DISABLED');
      expect(sched.Properties.Target.RetryPolicy).toEqual({ MaximumRetryAttempts: 2, MaximumEventAgeInSeconds: 3600 });
      expect(sched.Properties.Target.DeadLetterConfig.Arn).toBeDefined();
    });

    it('el rol del Scheduler solo puede lanzar la familia job en este clúster y pasar sus roles a ECS', () => {
      const statements = iamStatements(t).filter(({ owner }) => owner.startsWith('SchedulerRoleDefaultPolicy')).map((x) => x.statement);
      const runTask = statements.find((s) => asArray<string>(s.Action).includes('ecs:RunTask'))!;
      expect(JSON.stringify(runTask.Resource)).toContain(`task-definition/predictily-${envName}-job:*`);
      expect(runTask.Condition.ArnEquals['ecs:cluster']).toBeDefined();
      const pass = statements.find((s) => asArray<string>(s.Action).includes('iam:PassRole'))!;
      expect(pass.Condition.StringEquals['iam:PassedToService']).toBe('ecs-tasks.amazonaws.com');
      expect(asArray(pass.Resource)).toHaveLength(2);
    });
  });

  describe('alarmas de servicio', () => {
    it('alarmas de ALB, latencia, destinos no saludables, CloudFront, Scheduler y CPU/memoria de cada servicio', () => {
      for (const id of ['Alb5xx', 'AlbTargetLatencyP95', 'ApiUnhealthyHosts', 'CloudFront5xxRate', 'SchedulerFailuresNotEmpty']) {
        t.hasResourceProperties('AWS::CloudWatch::Alarm', { AlarmName: `predictily-${envName}-${id}`, AlarmActions: [Match.anyValue()] });
      }
      for (const svc of ['api', 'engine', 'workerDefault', 'workerForecasting', 'scheduler']) {
        for (const metric of ['Cpu', 'Memory']) t.hasResourceProperties('AWS::CloudWatch::Alarm', { AlarmName: `predictily-${envName}-${svc}${metric}` });
      }
    });

    it('la API se escala por CPU entre el mínimo y el máximo del entorno', () => {
      t.hasResourceProperties('AWS::ApplicationAutoScaling::ScalableTarget', { MinCapacity: cfg.services.api.minCount, MaxCapacity: cfg.services.api.maxCount });
      t.hasResourceProperties('AWS::ApplicationAutoScaling::ScalingPolicy', {
        TargetTrackingScalingPolicyConfiguration: Match.objectLike({ TargetValue: 60 }),
      });
    });
  });

  describe('correcciones de la revisión adversarial', () => {
    const policies = (): Array<[string, Json]> => ofType('AWS::IAM::Policy');
    const statementsOfPolicy = (policy: Json): Json[] => asArray<Json>(policy.Properties.PolicyDocument.Statement);
    const roleLogicalOf = (policy: Json): string => policy.Properties.Roles[0].Ref;
    const taskRoleLogical = (td: Json): string => td.Properties.TaskRoleArn['Fn::GetAtt'][0];
    const policyOfRole = (roleLogical: string): Json | undefined => policies().find(([, p]) => roleLogicalOf(p) === roleLogical)?.[1];
    const apKey = (v: unknown): string => /(BackendStorageAp|EngineArtifactsAp)/.exec(JSON.stringify(v))![1];

    it('[HIGH] todo rol con GetObject sobre un bucket tiene ListBucket sobre ese bucket (no sobre los objetos), acotado a tenants/', () => {
      let checked = 0;
      for (const [id, policy] of policies()) {
        const st = statementsOfPolicy(policy);
        for (const reader of st.filter((x) => asArray<string>(x.Action).includes('s3:GetObject'))) {
          for (const resource of asArray<string>(reader.Resource).filter((r) => typeof r === 'string' && r.endsWith('/tenants/*'))) {
            const bucketArn = resource.replace(/\/tenants\/\*$/, '');
            const list = st.find((x) => asArray<string>(x.Action).includes('s3:ListBucket') && asArray<string>(x.Resource).includes(bucketArn));
            expect({ policy: id, bucketArn, hasList: list !== undefined }).toEqual({ policy: id, bucketArn, hasList: true });
            expect(asArray<string>(list!.Action)).toEqual(expect.arrayContaining(['s3:ListBucket', 's3:GetBucketLocation']));
            expect(list!.Condition['ForAllValues:StringLike']['s3:prefix']).toEqual(['tenants/*']);
            checked++;
          }
        }
      }
      // API, 2 workers, programador, tareas efímeras (Bronze + artefactos) y motor (artefactos): 5×2 + 1
      expect(checked).toBe(11);
    });

    it('[HIGH] la API y los workers pueden hacer HeadObject de una clave inexistente en Bronze y artefactos (ListBucket presente)', () => {
      for (const prefix of ['apiTask', 'workerdefaultTask', 'workerforecastingTask']) {
        const [, p] = policies().find(([id]) => id.startsWith(`${prefix}TaskRoleDefaultPolicy`))!;
        const buckets = statementsOfPolicy(p).filter((x) => asArray<string>(x.Action).includes('s3:ListBucket')).flatMap((x) => asArray<string>(x.Resource));
        expect(buckets.sort()).toEqual([
          `arn:aws:s3:::${dataBucketName(envName, 'artifacts', '111111111111', 'us-east-1')}`,
          `arn:aws:s3:::${dataBucketName(envName, 'bronze', '111111111111', 'us-east-1')}`,
        ]);
      }
    });

    it('[HIGH] cada definición de tarea con volúmenes EFS tiene en SU rol exactamente los permisos de esos puntos de acceso (montaje y, si es de escritura, ClientWrite)', () => {
      const withVolumes = ofType('AWS::ECS::TaskDefinition').filter(([, td]) => (td.Properties.Volumes ?? []).length > 0);
      expect(withVolumes).toHaveLength(6); // api, 2 workers, programador, job y motor
      for (const [tdId, td] of withVolumes) {
        const policy = policyOfRole(taskRoleLogical(td));
        expect({ task: tdId, hasRolePolicy: policy !== undefined }).toEqual({ task: tdId, hasRolePolicy: true });
        const granted = new Map<string, Set<string>>(); // punto de acceso -> acciones
        for (const st of statementsOfPolicy(policy!).filter((x) => asArray<string>(x.Action).some((a) => a.startsWith('elasticfilesystem:')))) {
          const key = apKey(st.Condition.StringEquals['elasticfilesystem:AccessPointArn']);
          granted.set(key, new Set([...(granted.get(key) ?? []), ...asArray<string>(st.Action)]));
        }
        const volumes = new Map<string, string>(); // nombre del volumen -> punto de acceso
        for (const v of td.Properties.Volumes) volumes.set(v.Name, apKey(v.EFSVolumeConfiguration.AuthorizationConfig.AccessPointId));
        expect({ task: tdId, aps: [...granted.keys()].sort() }).toEqual({ task: tdId, aps: [...new Set(volumes.values())].sort() });
        for (const c of td.Properties.ContainerDefinitions) {
          for (const m of c.MountPoints ?? []) {
            const actions = granted.get(volumes.get(m.SourceVolume)!)!;
            expect(actions.has('elasticfilesystem:ClientMount')).toBe(true);
            if (m.ReadOnly === false) expect({ task: tdId, volume: m.SourceVolume, write: actions.has('elasticfilesystem:ClientWrite') }).toEqual({ task: tdId, volume: m.SourceVolume, write: true });
          }
        }
      }
    });

    it('[HIGH] las migraciones no montan EFS y su rol de tarea no tiene ninguna política (solo necesitan la base de datos)', () => {
      const migrate = taskDef('migrate');
      expect(migrate.Properties.Volumes ?? []).toHaveLength(0);
      for (const c of migrate.Properties.ContainerDefinitions) expect(c.MountPoints ?? []).toHaveLength(0);
      expect(policyOfRole(taskRoleLogical(migrate))).toBeUndefined();
    });

    it('[MEDIUM] Resource "*" solo aparece junto a ecr:GetAuthorizationToken (y solo en los roles de ejecución)', () => {
      for (const { owner, statement } of iamStatements(t)) {
        const star = asArray<string>(statement.Resource).includes('*');
        if (!star) continue;
        expect({ owner, actions: asArray<string>(statement.Action) }).toEqual({ owner, actions: ['ecr:GetAuthorizationToken'] });
        expect(owner).toMatch(/ExecutionRoleDefaultPolicy/);
      }
    });

    it('[MEDIUM] los roles de tarea nunca llevan Resource "*" y solo los que escriben/leen objetos nombran tenants/*', () => {
      for (const { statement } of iamStatements(t).filter((x) => /TaskRoleDefaultPolicy/.test(x.owner))) {
        expect(asArray<string>(statement.Resource)).not.toContain('*');
      }
      const tenantsWildcard = (prefix: string): boolean =>
        iamStatements(t).some((x) => x.owner.startsWith(`${prefix}TaskTaskRoleDefaultPolicy`) && JSON.stringify(x.statement.Resource).includes('/tenants/*'));
      expect(tenantsWildcard('migrate')).toBe(false);
      for (const p of ['api', 'workerdefault', 'workerforecasting', 'scheduler', 'job', 'engine']) expect(tenantsWildcard(p)).toBe(true);
    });

    it('[MEDIUM] el host de la base sale de la clave host del secreto de RDS (no de un export del endpoint)', () => {
      for (const family of ['api', 'worker-default', 'worker-forecasting', 'scheduler', 'engine', 'migrate', 'job']) {
        for (const c of taskDef(family).Properties.ContainerDefinitions) {
          expect((c.Environment ?? []).map((e: Json) => e.Name)).not.toContain('DB_HOST');
        }
      }
      const secrets = (family: string, container: string): Json[] => taskDef(family).Properties.ContainerDefinitions.find((c: Json) => c.Name === container).Secrets;
      for (const [family, container] of [['api', 'backend'], ['engine', 'engine']]) {
        const host = secrets(family, container).find((x) => x.Name === 'DB_HOST')!;
        expect(JSON.stringify(host.ValueFrom)).toContain(':host::');
      }
      // Ningún recurso de esta pila importa el endpoint de RDS de la pila Platform.
      expect(JSON.stringify(res)).not.toMatch(/DatabaseEndpoint|EndpointAddress/);
    });

    it('[MEDIUM] los modelos se publican en el bucket de artefactos mediante PREDICTILY_MODELS_DISK (disco pendiente en el backend)', () => {
      const env = Object.fromEntries(taskDef('api').Properties.ContainerDefinitions.find((c: Json) => c.Name === 'backend').Environment.map((e: Json) => [e.Name, e.Value]));
      expect(env.PREDICTILY_MODELS_DISK).toBe('models_s3');
      expect(env.PREDICTILY_MODELS_S3_BUCKET).toBe(dataBucketName(envName, 'artifacts', '111111111111', 'us-east-1'));
      expect(env.PREDICTILY_MODELS_S3_SSE).toBe('aws:kms');
      expect(env.PREDICTILY_BRONZE_S3_BUCKET).toBe(dataBucketName(envName, 'bronze', '111111111111', 'us-east-1'));
      expect(env.PREDICTILY_BRONZE_S3_BUCKET).not.toBe(env.PREDICTILY_MODELS_S3_BUCKET);
    });

    it('[LOW] la clave de firma del motor lleva su identificador y las claves públicas de confianza, en el motor y en el backend', () => {
      const envOf = (family: string, container: string): Record<string, string> =>
        Object.fromEntries(taskDef(family).Properties.ContainerDefinitions.find((c: Json) => c.Name === container).Environment.map((e: Json) => [e.Name, e.Value]));
      expect(envOf('engine', 'engine').ENGINE_SIGNING_KEY_ID).toBe('k-ficticio');
      expect(envOf('engine', 'engine').ENGINE_TRUSTED_PUBLIC_KEYS).toMatch(/^k-ficticio:/);
      expect(envOf('api', 'backend').ENGINE_TRUSTED_PUBLIC_KEYS).toMatch(/^k-ficticio:/);
      expect(envOf('api', 'backend')).not.toHaveProperty('ENGINE_SIGNING_KEY_ID'); // el backend nunca firma
    });

    it('[LOW] el Scheduler puede etiquetar las tareas que lanza (hipótesis ecs:TagResource, acotada al clúster y a RunTask)', () => {
      const tag = iamStatements(t).find((x) => x.owner.startsWith('SchedulerRoleDefaultPolicy') && asArray<string>(x.statement.Action).includes('ecs:TagResource'))!;
      expect(tag.statement.Condition.StringEquals['ecs:CreateAction']).toBe('RunTask');
      expect(JSON.stringify(tag.statement.Resource)).toContain(':task/');
    });
  });

  it('las salidas de la pila no contienen secretos', () => {
    expect(JSON.stringify(t.toJSON().Outputs)).not.toMatch(/secretsmanager:|BEGIN .*PRIVATE|AKIA/);
  });
});
