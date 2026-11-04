import { EnvName } from './config/environments';

/** Nombres lógicos de las colas de Laravel (los mismos que usa onQueue() en el backend). */
export const QUEUE_NAMES = ['imports', 'default', 'forecasting'] as const;
export type QueueName = (typeof QUEUE_NAMES)[number];

/**
 * Sufijo que Laravel agrega a TODAS las colas SQS (SQS_SUFFIX). Así onQueue('imports') resuelve a
 * `imports-predictily-<entorno>` sin tocar el código de la aplicación.
 */
export function sqsSuffix(env: EnvName): string {
  return `-predictily-${env}`;
}

export function secretPrefix(env: EnvName): string {
  return `predictily/${env}`;
}

/** Contenedores de datos de la plataforma (un bucket por capa). */
export const DATA_BUCKETS = ['bronze', 'silver', 'gold', 'artifacts'] as const;
export type DataBucketName = (typeof DATA_BUCKETS)[number];

/** Identidad POSIX del usuario del contenedor (Dockerfile del backend: www-data; del motor: engine). */
export const POSIX = { backend: { uid: '33', gid: '33' }, engine: { uid: '10001', gid: '10001' } } as const;

/**
 * Nombre explícito y estable de cada bucket de datos (único global por cuenta+región). Al ser determinista, las políticas de
 * las tareas lo referencian sin dependencias entre pilas y los reconocimientos de cdk-nag tienen identificadores estables.
 */
export function dataBucketName(env: EnvName, layer: DataBucketName, account: string, region: string): string {
  return `predictily-${env}-${layer}-${account}-${region}`;
}

/** ARN (sin token) del prefijo `tenants/*` de un bucket de datos. */
export function tenantObjectsArn(env: EnvName, layer: DataBucketName, account: string, region: string): string {
  return `arn:aws:s3:::${dataBucketName(env, layer, account, region)}/tenants/*`;
}
