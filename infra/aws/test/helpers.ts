import * as fs from 'fs';
import * as path from 'path';
import { App, Validations } from 'aws-cdk-lib';
import { Template } from 'aws-cdk-lib/assertions';
import { AwsSolutionsChecks } from 'cdk-nag';
import { EnvName, environmentConfig } from '../lib/config/environments';
import { resolveOwnerInputs } from '../lib/config/inputs';
import { Stacks, buildStacks } from '../lib/stacks';

const ROOT = path.resolve(__dirname, '..');

/** Las mismas banderas de características que usa `cdk synth` (cdk.json): las pruebas deben ver la misma plantilla. */
export function cdkContext(): Record<string, unknown> {
  return JSON.parse(fs.readFileSync(path.join(ROOT, 'cdk.json'), 'utf8')).context as Record<string, unknown>;
}

export interface Built extends Stacks {
  readonly app: App;
  readonly platformTemplate: Template;
  readonly appTemplate: Template;
}

export function build(envName: EnvName, options: { nag?: boolean; context?: Record<string, unknown> } = {}): Built {
  const app = new App({ context: { ...cdkContext(), env: envName, useFictitiousInputs: 'true', ...(options.context ?? {}) } });
  const config = environmentConfig(envName);
  const inputs = resolveOwnerInputs(envName, (k) => app.node.tryGetContext(k), ROOT);
  const stacks = buildStacks(app, config, inputs);
  if (options.nag) Validations.of(app).addPlugins(new AwsSolutionsChecks(app, { verbose: true }));
  return { app, ...stacks, platformTemplate: Template.fromStack(stacks.platform), appTemplate: Template.fromStack(stacks.application) };
}

type Json = Record<string, any>;

/** Todas las sentencias IAM (políticas administradas en línea, políticas de rol y de confianza) de una plantilla. */
export function iamStatements(template: Template): Array<{ owner: string; statement: Json }> {
  const out: Array<{ owner: string; statement: Json }> = [];
  const resources = template.toJSON().Resources as Record<string, Json>;
  for (const [id, r] of Object.entries(resources)) {
    const docs: Json[] = [];
    if (r.Type === 'AWS::IAM::Policy' || r.Type === 'AWS::IAM::ManagedPolicy') docs.push(r.Properties.PolicyDocument);
    if (r.Type === 'AWS::IAM::Role') {
      docs.push(r.Properties.AssumeRolePolicyDocument);
      for (const p of r.Properties.Policies ?? []) docs.push(p.PolicyDocument);
    }
    for (const d of docs) {
      const st = Array.isArray(d.Statement) ? d.Statement : [d.Statement];
      for (const s of st) out.push({ owner: id, statement: s });
    }
  }
  return out;
}

export function asArray<T>(v: T | T[] | undefined): T[] {
  return v === undefined ? [] : Array.isArray(v) ? v : [v];
}

/** Familias de instancias con GPU o aceleradores (EC2 y RDS): jamás deben aparecer en una plantilla de Predictily. */
export const ACCELERATED_FAMILIES = /^(db\.)?(p[2-6]\w*|g[3-7]\w*|gr6\w*|inf[12]\w*|trn[12]\w*|dl[12]\w*|f[12]|vt1|u-\w+|x2\w*)$/;

/**
 * Comprobación firme de «sin GPU permanente ni EC2»: (1) ningún recurso de cómputo EC2/AutoScaling/Batch/SageMaker/capacity provider,
 * (2) toda tarea ECS es solo FARGATE sin ResourceRequirements/InferenceAccelerators, (3) todo texto con forma de tipo de instancia
 * (`familia.tamaño` o `db.familia.tamaño`) pertenece a una lista permitida (solo db.t4g.* en RDS) y no a una familia acelerada.
 */
export function assertNoGpuNoEc2(template: Template, allowedInstanceTypes: readonly string[] = []): void {
  const json = template.toJSON() as Json2;
  const forbiddenTypes = ['AWS::EC2::Instance', 'AWS::EC2::LaunchTemplate', 'AWS::EC2::SpotFleet', 'AWS::EC2::CapacityReservation'];
  for (const [id, r] of Object.entries(json.Resources)) {
    const type: string = r.Type;
    const bad = forbiddenTypes.includes(type) || /^AWS::(AutoScaling::|Batch::|SageMaker::|ECS::CapacityProvider|EC2::Fleet)/.test(type);
    if (type === 'AWS::ECS::CapacityProvider') throw new Error(`${id}: los capacity providers EC2 no están permitidos`);
    expect({ id, type, bad }).toEqual({ id, type, bad: false });
    if (type === 'AWS::ECS::TaskDefinition') {
      expect(r.Properties.RequiresCompatibilities).toEqual(['FARGATE']);
      expect(r.Properties.InferenceAccelerators).toBeUndefined();
      for (const c of r.Properties.ContainerDefinitions) expect(c.ResourceRequirements).toBeUndefined();
    }
  }
  const found = new Set<string>();
  const walk = (v: unknown): void => {
    if (typeof v === 'string') {
      if (/^(db\.)?[a-z]+[0-9]+[a-z0-9-]*\.[0-9a-z]+$/.test(v) && !/^(nodejs|python|java|dotnet|ruby|provided)/.test(v)) found.add(v); // los runtimes de Lambda no son tipos de instancia
    } else if (Array.isArray(v)) v.forEach(walk);
    else if (v && typeof v === 'object') Object.values(v).forEach(walk);
  };
  walk(json.Resources);
  for (const type of found) {
    expect({ type, accelerated: ACCELERATED_FAMILIES.test(type.replace(/\.[0-9a-z]+$/, '').replace(/^db\./, '')) }).toEqual({ type, accelerated: false });
    expect({ type, allowed: allowedInstanceTypes.includes(type) }).toEqual({ type, allowed: true });
  }
}

type Json2 = { Resources: Record<string, any> };
