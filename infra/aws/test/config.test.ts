import { ENVIRONMENTS, ENV_NAMES, environmentConfig } from '../lib/config/environments';
import { FICTITIOUS_ACCOUNT, fictitiousInputs, resolveOwnerInputs } from '../lib/config/inputs';

const noContext = (): undefined => undefined;
const real = {
  account: '222222222222',
  region: 'us-east-1',
  appDomainName: 'app.predictily.test',
  originDomainName: 'origin.predictily.test',
  albCertificateArn: 'arn:aws:acm:us-east-1:222222222222:certificate/11111111-2222-3333-4444-555555555555',
  cloudFrontCertificateArn: 'arn:aws:acm:us-east-1:222222222222:certificate/11111111-2222-3333-4444-555555555555',
  cloudFrontOriginPrefixListId: 'pl-3b927c52',
  availabilityZones: 'us-east-1a,us-east-1b',
  alertEmails: 'ops@predictily.test',
  monthlyBudgetUsd: '300',
  signingKeyId: 'k-2026',
  trustedPublicKeys: `k-2026:${'A'.repeat(43)}=`,
  imageTag: 'abc1234',
};
const ctx = (extra: Record<string, unknown> = {}) => (k: string): unknown => ({ ...real, ...extra } as Record<string, unknown>)[k];

describe('entornos', () => {
  it('solo existen staging y production y se rechaza cualquier otro nombre', () => {
    expect([...ENV_NAMES]).toEqual(['staging', 'production']);
    expect(() => environmentConfig('dev')).toThrow(/Entorno desconocido/);
  });

  it('production arranca Single-AZ con protección contra borrado y respaldo más largo que staging', () => {
    const { staging, production } = ENVIRONMENTS;
    expect(production.database.multiAz).toBe(false);
    expect(production.database.deletionProtection).toBe(true);
    expect(production.database.backupRetentionDays).toBeGreaterThan(staging.database.backupRetentionDays);
    expect(staging.vpcCidr).not.toBe(production.vpcCidr);
  });
});

describe('datos del propietario', () => {
  it('los valores ficticios usan la cuenta 111111111111, dominios example.com y están marcados como ficticios', () => {
    const i = fictitiousInputs('staging');
    expect(i.fictitious).toBe(true);
    expect(i.account).toBe(FICTITIOUS_ACCOUNT);
    expect(i.appDomainName.endsWith('.example.com')).toBe(true);
    expect(resolveOwnerInputs('production', (k) => (k === 'useFictitiousInputs' ? 'true' : undefined), '/nonexistent').fictitious).toBe(true);
  });

  it('acepta un conjunto completo y válido', () => {
    const i = resolveOwnerInputs('production', ctx(), '/nonexistent');
    expect(i.fictitious).toBe(false);
    expect(i.availabilityZones).toEqual(['us-east-1a', 'us-east-1b']);
    expect(i.monthlyBudgetUsd).toBe(300);
    expect(i.signingKeySecretName).toBe('predictily/production/engine-signing-key');
    expect(i.signingKeyId).toBe('k-2026');
    expect(i.budgetFilterByEnvironmentTag).toBe(false);
  });

  it('informa TODOS los datos que faltan, no solo el primero', () => {
    expect(() => resolveOwnerInputs('staging', noContext, '/nonexistent')).toThrow(/falta «account»[\s\S]*falta «region»[\s\S]*falta «appDomainName»[\s\S]*falta «imageTag»/);
  });

  it.each([
    ['la cuenta ficticia', { account: FICTITIOUS_ACCOUNT }, /cuenta FICTICIA/],
    ['una región distinta de us-east-1', { region: 'sa-east-1', albCertificateArn: real.albCertificateArn.replace('us-east-1', 'sa-east-1'), availabilityZones: 'sa-east-1a,sa-east-1b' }, /us-east-1/],
    ['la etiqueta latest (ECR inmutable)', { imageTag: 'latest' }, /latest/],
    ['un certificado de CloudFront fuera de us-east-1', { cloudFrontCertificateArn: 'arn:aws:acm:eu-west-1:222222222222:certificate/11111111-2222-3333-4444-555555555555' }, /us-east-1/],
    ['el mismo nombre para la aplicación y el origen', { originDomainName: 'app.predictily.test' }, /distintos/],
    ['un presupuesto no positivo', { monthlyBudgetUsd: '0' }, /monthlyBudgetUsd/],
    ['zonas de otra región', { availabilityZones: 'eu-west-1a,eu-west-1b' }, /no pertenece a la región/],
    ['un correo inválido', { alertEmails: 'no-es-un-correo' }, /correo inválido/],
    ['la clave de firma sin identificador (el secreto privado exige su kid)', { signingKeyId: '' }, /falta «signingKeyId»/],
    ['claves públicas de confianza ausentes', { trustedPublicKeys: '' }, /falta «trustedPublicKeys»/],
    ['claves públicas que no incluyen la del signingKeyId', { trustedPublicKeys: `otra:${'B'.repeat(43)}=` }, /debe incluir la clave pública/],
  ])('rechaza %s', (_name, extra, message) => {
    expect(() => resolveOwnerInputs('production', ctx(extra), '/nonexistent')).toThrow(message);
  });
});
