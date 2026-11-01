import { App, Stack } from 'aws-cdk-lib';
import * as s3 from 'aws-cdk-lib/aws-s3';
import { AwsSolutionsChecks } from 'cdk-nag';
import { EnvName } from '../lib/config/environments';
import { MIN_REASON_LENGTH, ackAll, listAcknowledgments } from '../lib/nag';
import { build, cdkContext } from './helpers';

/**
 * cdk-nag (reglas AwsSolutions) y las validaciones de CloudFormation que incluye CDK se ejecutan en la síntesis; aquí se
 * comprueba que NO queda ningún hallazgo sin reconocer y que cada reconocimiento está justificado uno por uno.
 */
describe.each(['staging', 'production'] as EnvName[])('cdk-nag AwsSolutions · %s', (envName) => {
  it('la síntesis completa con cdk-nag activo no produce hallazgos (los reconocidos están justificados abajo)', () => {
    expect(() => build(envName, { nag: true })).not.toThrow();
  });

  it('el validador de reglas informa cero violaciones en ambas pilas', () => {
    const { platform, application, app } = build(envName);
    const checks = new AwsSolutionsChecks(app);
    for (const stack of [platform, application]) {
      const report = checks.validateScope(stack);
      expect({ stack: stack.stackName, violations: report.violations.map((v) => v.ruleName) }).toEqual({ stack: stack.stackName, violations: [] });
    }
  });

  it('cada reconocimiento tiene un motivo concreto y ninguno está duplicado en la misma construcción', () => {
    const { app } = build(envName);
    const mine = listAcknowledgments(app).filter((a) => a.id.startsWith('AwsSolutions-'));
    expect(mine.length).toBeGreaterThan(0);
    const seen = new Set<string>();
    for (const a of mine) {
      expect({ path: a.path, id: a.id, ok: a.reason.trim().length >= MIN_REASON_LENGTH }).toEqual({ path: a.path, id: a.id, ok: true });
      const key = `${a.path}|${a.id}`;
      expect(seen.has(key)).toBe(false);
      seen.add(key);
    }
  });

  it('el conjunto de reglas reconocidas es el esperado: agregar una supresión nueva exige actualizar esta lista a propósito', () => {
    const { app } = build(envName);
    const rules = new Set(listAcknowledgments(app).map((a) => a.id.replace(/\[.*$/, '')).filter((id) => id.startsWith('AwsSolutions-')));
    const expected = [
      'AwsSolutions-CFR1',
      'AwsSolutions-ECS2',
      'AwsSolutions-IAM5',
      'AwsSolutions-RDS11',
      'AwsSolutions-RDS3',
      'AwsSolutions-RDS6',
      'AwsSolutions-S1',
      'AwsSolutions-SMG4',
      'AwsSolutions-SQS3',
      // Solo staging (decisión documentada de costo y de baja de entorno):
      ...(envName === 'staging' ? ['AwsSolutions-ECS4', 'AwsSolutions-RDS10'] : []),
    ].sort();
    expect([...rules].sort()).toEqual(expected);
  });

  it('[MEDIUM] los reconocimientos de IAM5 están acotados a la política concreta con el comodín, nunca a una tarea o rol completos', () => {
    const { app } = build(envName);
    const iam5 = listAcknowledgments(app).filter((a) => a.id.startsWith('AwsSolutions-IAM5'));
    expect(iam5.length).toBeGreaterThan(0);
    for (const a of iam5) {
      expect({ path: a.path, onPolicy: a.path.endsWith('/DefaultPolicy') }).toEqual({ path: a.path, onPolicy: true });
      if (a.id === 'AwsSolutions-IAM5[Resource::*]') expect(a.path).toMatch(/\/ExecutionRole\/DefaultPolicy$/);
      if (a.id.includes('/tenants/*]')) expect(a.path).toMatch(/\/TaskRole\/DefaultPolicy$/);
      if (a.id.includes('<aws:PrincipalTag/tenant_id>')) expect(a.path).toMatch(/TenantDataAccessRole\/DefaultPolicy$/);
    }
    // El motor solo recibe el reconocimiento de la capa de artefactos; las migraciones, ninguno de tenants/*.
    const tenantAcks = (task: string): string[] => iam5.filter((a) => a.path.includes(`/${task}/TaskRole/`)).map((a) => a.id);
    expect(tenantAcks('engineTask')).toHaveLength(1);
    expect(tenantAcks('migrateTask')).toHaveLength(0);
  });

  it('[MEDIUM] ECS2 se reconoce solo en el recurso de la definición de tarea (no en sus roles)', () => {
    const { app } = build(envName);
    const ecs2 = listAcknowledgments(app).filter((a) => a.id === 'AwsSolutions-ECS2');
    expect(ecs2).toHaveLength(7);
    for (const a of ecs2) expect(a.path).toMatch(/Task\/Resource$/);
  });

  it('las reglas críticas NO están reconocidas en ningún sitio: cifrado, acceso público, TLS, DLQ, RDS público y GPU', () => {
    const { app } = build(envName);
    const forbidden = ['S2', 'S5', 'S10', 'SQS2', 'SQS4', 'RDS2', 'RDS13', 'KMS5', 'SNS2', 'SNS3', 'EC23', 'ELB2', 'CFR2', 'CFR4', 'CFR5', 'CFR7', 'VPC7', 'EFS1', 'ECR1'];
    const ids = listAcknowledgments(app).map((a) => a.id);
    for (const f of forbidden) expect(ids.some((id) => id.startsWith(`AwsSolutions-${f}`))).toBe(false);
  });
});

describe('el verificador no es decorativo', () => {
  it('detecta un bucket inseguro (sin TLS obligatorio, sin registros) y exige motivo al reconocer', () => {
    const app = new App({ context: cdkContext() });
    const stack = new Stack(app, 'Inseguro', { env: { account: '111111111111', region: 'us-east-1' } });
    const bucket = new s3.Bucket(stack, 'Cubo', { blockPublicAccess: new s3.BlockPublicAccess({ blockPublicPolicy: false }) });
    const report = new AwsSolutionsChecks(app).validateScope(stack);
    const names = report.violations.map((v) => v.ruleName);
    expect(report.success).toBe(false);
    expect(names).toEqual(expect.arrayContaining(['AwsSolutions-S1', 'AwsSolutions-S10']));
    expect(() => ackAll(bucket, [{ id: 'AwsSolutions-S1', reason: 'no hace falta' }])).toThrow(/motivo concreto/);
  });
});
