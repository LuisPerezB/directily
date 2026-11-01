import * as fs from 'fs';
import * as path from 'path';
import { EnvName } from './environments';

/**
 * Datos que SOLO puede aportar el propietario de la cuenta AWS (docs/operations/AWS_DEPLOYMENT.md §2).
 *
 * Origen, de mayor a menor precedencia:
 *   1. Contexto de CDK:        cdk synth -c env=staging -c account=<id> -c region=us-east-1 ...
 *   2. Archivo local ignorado: infra/aws/owner-inputs.<env>.json (ver owner-inputs.example.json; sin secretos).
 *
 * Verificación sin credenciales: `-c useFictitiousInputs=true` sustituye TODO por valores FICTICIOS (cuenta
 * 111111111111, dominios example.com reservados por la IANA, ARN de certificado de mentira). Una plantilla sintetizada
 * así NO es desplegable: sirve solo para verificar la forma de la infraestructura. Nada aquí es un secreto.
 */
export interface OwnerInputs {
  readonly account: string;
  readonly region: string;
  /** Dominio público de la aplicación (CloudFront): SPA y /api bajo el mismo origen (cookies de Sanctum). */
  readonly appDomainName: string;
  /** Nombre DNS del ALB (registro del propietario → ALB); CloudFront lo usa como origen de /api. */
  readonly originDomainName: string;
  /** Certificado ACM del ALB (región de la pila). Debe cubrir originDomainName y appDomainName (SAN o comodín). */
  readonly albCertificateArn: string;
  /** Certificado ACM de CloudFront (siempre us-east-1). */
  readonly cloudFrontCertificateArn: string;
  /** ID de la lista de prefijos administrada com.amazonaws.global.cloudfront.origin-facing de la región. */
  readonly cloudFrontOriginPrefixListId: string;
  /** Dos zonas de disponibilidad de la región (`aws ec2 describe-availability-zones`); explícitas para sintetizar sin credenciales. */
  readonly availabilityZones: readonly string[];
  readonly alertEmails: readonly string[];
  /** Límite mensual del presupuesto de AWS Budgets en USD (decisión del propietario; no es un precio). */
  readonly monthlyBudgetUsd: number;
  /** ENGINE_TRUSTED_PUBLIC_KEYS (`kid:clave,...`): SOLO claves públicas; debe incluir la del `signingKeyId`. OBLIGATORIO. */
  readonly trustedPublicKeys: string;
  /** ENGINE_SIGNING_KEY_ID (identificador público de la clave de firma del motor). OBLIGATORIO: el motor lleva la clave privada. */
  readonly signingKeyId: string;
  /** true = el presupuesto filtra por la etiqueta `predictily:environment` (exige activarla en Billing y ~24 h; por defecto, toda la cuenta). */
  readonly budgetFilterByEnvironmentTag: boolean;
  /** Nombre del secreto de Secrets Manager con la semilla Ed25519 (lo crea el propietario; nunca CloudFormation). */
  readonly signingKeySecretName: string;
  /** Etiqueta inmutable de las imágenes en ECR (p. ej. el SHA corto del commit). */
  readonly imageTag: string;
  /** true cuando los valores son los FICTICIOS de verificación local. */
  readonly fictitious: boolean;
}

export const FICTITIOUS_ACCOUNT = '111111111111';

export function fictitiousInputs(env: EnvName): OwnerInputs {
  const zone = `${env}.example.com`;
  const arn = `arn:aws:acm:us-east-1:${FICTITIOUS_ACCOUNT}:certificate/00000000-0000-0000-0000-000000000000`;
  return {
    account: FICTITIOUS_ACCOUNT,
    region: 'us-east-1',
    appDomainName: `app.${zone}`,
    originDomainName: `origin.${zone}`,
    albCertificateArn: arn,
    cloudFrontCertificateArn: arn,
    cloudFrontOriginPrefixListId: 'pl-00000000',
    availabilityZones: ['us-east-1a', 'us-east-1b'],
    alertEmails: ['alertas-ficticias@example.com'],
    monthlyBudgetUsd: 100,
    trustedPublicKeys: 'k-ficticio:CLAVE-PUBLICA-FICTICIA-NO-USAR',
    signingKeyId: 'k-ficticio',
    budgetFilterByEnvironmentTag: false,
    signingKeySecretName: `predictily/${env}/engine-signing-key`,
    imageTag: 'ficticio',
    fictitious: true,
  };
}

type Raw = Record<string, unknown>;

function fromFile(env: EnvName, baseDir: string): Raw {
  const file = path.join(baseDir, `owner-inputs.${env}.json`);
  if (!fs.existsSync(file)) return {};
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8')) as Raw;
  } catch (err) {
    throw new Error(`No se pudo leer ${file}: ${(err as Error).message}`);
  }
}

function str(raw: Raw, key: string): string {
  const v = raw[key];
  return typeof v === 'string' ? v.trim() : v === undefined || v === null ? '' : String(v).trim();
}

/**
 * Resuelve y valida los datos del propietario. `context` es una función tipo `node.tryGetContext`.
 * Lanza un error con TODOS los problemas encontrados (no uno por uno).
 */
export function resolveOwnerInputs(env: EnvName, context: (key: string) => unknown, baseDir: string): OwnerInputs {
  const useFictitious = String(context('useFictitiousInputs')) === 'true';
  if (useFictitious) {
    return { ...fictitiousInputs(env), budgetFilterByEnvironmentTag: String(context('budgetFilterByEnvironmentTag')) === 'true' };
  }

  const file = fromFile(env, baseDir);
  const raw: Raw = {};
  const keys = [
    'account', 'region', 'appDomainName', 'originDomainName', 'albCertificateArn', 'cloudFrontCertificateArn',
    'cloudFrontOriginPrefixListId', 'availabilityZones', 'alertEmails', 'monthlyBudgetUsd', 'trustedPublicKeys', 'signingKeyId',
    'signingKeySecretName', 'budgetFilterByEnvironmentTag', 'imageTag',
  ];
  for (const k of keys) {
    const fromCtx = context(k);
    raw[k] = fromCtx !== undefined && fromCtx !== null && fromCtx !== '' ? fromCtx : file[k];
  }

  const errors: string[] = [];
  const need = (k: string): string => {
    const v = str(raw, k);
    if (!v) errors.push(`falta «${k}»`);
    return v;
  };

  const account = need('account');
  if (account && !/^\d{12}$/.test(account)) errors.push('«account» debe tener 12 dígitos');
  if (account === FICTITIOUS_ACCOUNT) errors.push(`«account» ${FICTITIOUS_ACCOUNT} es la cuenta FICTICIA de verificación: use -c useFictitiousInputs=true solo para sintetizar`);
  const region = need('region');
  if (region && region !== 'us-east-1') {
    errors.push('«region» debe ser us-east-1 en esta versión: el WAF y el certificado de CloudFront viven en us-east-1 (EdgeStack multirregión: DISEÑADO, no implementado)');
  }
  const appDomainName = need('appDomainName');
  const originDomainName = need('originDomainName');
  for (const [k, v] of [['appDomainName', appDomainName], ['originDomainName', originDomainName]] as const) {
    if (v && !/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(v)) errors.push(`«${k}» no es un nombre DNS válido en minúsculas`);
  }
  if (appDomainName && appDomainName === originDomainName) errors.push('«appDomainName» y «originDomainName» deben ser distintos');
  const albCertificateArn = need('albCertificateArn');
  if (albCertificateArn && !new RegExp(`^arn:aws:acm:${region || '[a-z0-9-]+'}:\\d{12}:certificate/[0-9a-f-]{36}$`).test(albCertificateArn)) {
    errors.push('«albCertificateArn» no es un ARN de ACM de la región de la pila');
  }
  const cloudFrontCertificateArn = need('cloudFrontCertificateArn');
  if (cloudFrontCertificateArn && !/^arn:aws:acm:us-east-1:\d{12}:certificate\/[0-9a-f-]{36}$/.test(cloudFrontCertificateArn)) {
    errors.push('«cloudFrontCertificateArn» debe ser un ARN de ACM en us-east-1');
  }
  const cloudFrontOriginPrefixListId = need('cloudFrontOriginPrefixListId');
  if (cloudFrontOriginPrefixListId && !/^pl-[0-9a-f]{8,17}$/.test(cloudFrontOriginPrefixListId)) errors.push('«cloudFrontOriginPrefixListId» debe tener la forma pl-xxxxxxxx');

  const azRaw = raw['availabilityZones'];
  const availabilityZones = (Array.isArray(azRaw) ? azRaw.map(String) : String(azRaw ?? '').split(',')).map((z) => z.trim()).filter(Boolean);
  if (availabilityZones.length !== 2) errors.push('«availabilityZones» debe listar exactamente dos zonas, p. ej. us-east-1a,us-east-1b');
  for (const z of availabilityZones) if (region && !z.startsWith(region)) errors.push(`la zona ${z} no pertenece a la región ${region}`);

  const emailsRaw = raw['alertEmails'];
  const alertEmails = (Array.isArray(emailsRaw) ? emailsRaw.map(String) : String(emailsRaw ?? '').split(','))
    .map((e) => e.trim())
    .filter(Boolean);
  if (alertEmails.length === 0) errors.push('falta «alertEmails» (al menos un correo para alarmas y presupuesto)');
  for (const e of alertEmails) if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(e)) errors.push(`correo inválido en «alertEmails»: ${e}`);

  const monthlyBudgetUsd = Number(str(raw, 'monthlyBudgetUsd'));
  if (!Number.isFinite(monthlyBudgetUsd) || monthlyBudgetUsd <= 0) errors.push('«monthlyBudgetUsd» debe ser un número mayor que 0 (decisión del propietario)');

  const imageTag = need('imageTag');
  if (imageTag && !/^[A-Za-z0-9_.-]{1,128}$/.test(imageTag)) errors.push('«imageTag» tiene caracteres no permitidos');
  if (imageTag === 'latest') errors.push('«imageTag» no puede ser latest: los repositorios ECR son de etiquetas inmutables');

  const trustedPublicKeys = need('trustedPublicKeys');
  const signingKeyId = need('signingKeyId');
  if (signingKeyId && !/^[A-Za-z0-9._-]{1,64}$/.test(signingKeyId)) errors.push('«signingKeyId» tiene caracteres no permitidos');
  if (trustedPublicKeys && signingKeyId && !trustedPublicKeys.split(',').some((entry) => entry.trim().startsWith(`${signingKeyId}:`) && entry.trim().length > signingKeyId.length + 1)) {
    errors.push('«trustedPublicKeys» debe incluir la clave pública de «signingKeyId» con la forma kid:clave');
  }
  const budgetFilterByEnvironmentTag = String(raw['budgetFilterByEnvironmentTag'] ?? 'false') === 'true';
  const signingKeySecretName = str(raw, 'signingKeySecretName') || `predictily/${env}/engine-signing-key`;
  if (!/^[A-Za-z0-9/_+=.@-]{1,512}$/.test(signingKeySecretName)) errors.push('«signingKeySecretName» tiene caracteres no permitidos');

  if (errors.length > 0) {
    throw new Error(
      `Datos del propietario incompletos o inválidos para «${env}»:\n  - ${errors.join('\n  - ')}\n` +
        'Defínalos con -c clave=valor o en infra/aws/owner-inputs.' + env + '.json (ver owner-inputs.example.json y docs/operations/AWS_DEPLOYMENT.md §2).',
    );
  }

  return {
    account, region, appDomainName, originDomainName, albCertificateArn, cloudFrontCertificateArn, cloudFrontOriginPrefixListId,
    availabilityZones, alertEmails, monthlyBudgetUsd, trustedPublicKeys, signingKeyId, signingKeySecretName, budgetFilterByEnvironmentTag, imageTag, fictitious: false,
  };
}
