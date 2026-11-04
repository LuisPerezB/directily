import { RetentionDays } from 'aws-cdk-lib/aws-logs';

/**
 * Parámetros por entorno de la infraestructura (staging / production).
 *
 * IMPORTANTE: todos los tamaños, límites y umbrales de este archivo son PUNTOS DE PARTIDA a validar en el piloto
 * (docs/architecture/AWS_ARCHITECTURE.md §9). Ninguno es un precio ni una promesa de capacidad. Lo que depende del
 * propietario (cuenta, región, dominio, certificados, presupuesto, correos) NO está aquí: ver `inputs.ts`.
 */
export type EnvName = 'staging' | 'production';

export const ENV_NAMES: readonly EnvName[] = ['staging', 'production'] as const;

/** Tamaño de una tarea Fargate (combinaciones válidas de CPU/memoria de Fargate). */
export interface TaskSizing {
  readonly cpu: number;
  readonly memoryMiB: number;
  readonly desiredCount: number;
}

export interface DatabaseConfig {
  /** Clase de instancia RDS sin el prefijo `db.` (p. ej. `t4g.medium`). */
  readonly instanceType: string;
  readonly allocatedStorageGiB: number;
  /** Tope del autoescalado de almacenamiento de RDS (storage autoscaling). */
  readonly maxAllocatedStorageGiB: number;
  /** Single-AZ inicial (ADR-V25-09). Criterio para Multi-AZ: docs/architecture/AWS_ARCHITECTURE.md §6. */
  readonly multiAz: boolean;
  readonly backupRetentionDays: number;
  readonly deletionProtection: boolean;
  /** Ventana de respaldo en UTC (hh:mm-hh:mm); antes de las tareas programadas de las 01:30 America/Guayaquil. */
  readonly backupWindowUtc: string;
  readonly maintenanceWindowUtc: string;
}

export interface QueueConfig {
  /** Recepciones antes de enviar el mensaje a la DLQ. */
  readonly maxReceiveCount: number;
  readonly dlqRetentionDays: number;
}

export interface WafConfig {
  /**
   * Modo de los grupos de reglas administradas de AWS. `count` (arranque) solo mide: se pasa a `block` tras revisar en
   * el piloto las solicitudes muestreadas (riesgo de falsos positivos con cargas CSV/JSON). Los límites de tasa SIEMPRE bloquean.
   */
  readonly managedRulesMode: 'count' | 'block';
  /** Solicitudes por IP en 5 minutos (regla general). */
  readonly generalRateLimit: number;
  /** Solicitudes por IP en 5 minutos hacia la autenticación. */
  readonly authRateLimit: number;
}

export interface EnvironmentConfig {
  readonly name: EnvName;
  readonly vpcCidr: string;
  /** NAT gateways (1 = costo mínimo; uno por AZ cuando se pase a Multi-AZ). */
  readonly natGateways: number;
  /** Endpoints de interfaz (ECR, Logs, Secrets Manager, SQS, KMS): cuestan por hora y AZ; apagados en la línea base. */
  readonly interfaceEndpoints: boolean;
  readonly logRetention: RetentionDays;
  /** Retención de versiones no vigentes en S3 (días). Afecta cuándo una purga de Bronze es definitiva. */
  readonly noncurrentVersionDays: { readonly bronze: number; readonly silver: number; readonly gold: number; readonly artifacts: number };
  readonly accessLogsRetentionDays: number;
  readonly database: DatabaseConfig;
  readonly queue: QueueConfig;
  readonly waf: WafConfig;
  /** `X86_64` o `ARM64`; debe coincidir con la arquitectura de las imágenes publicadas en ECR. */
  readonly cpuArchitecture: 'X86_64' | 'ARM64';
  readonly services: {
    readonly api: TaskSizing & { readonly minCount: number; readonly maxCount: number };
    readonly workerDefault: TaskSizing;
    readonly workerForecasting: TaskSizing;
    readonly scheduler: TaskSizing;
    readonly engine: TaskSizing;
    /** Tarea efímera de migraciones y de comandos programados (RunTask). */
    readonly ephemeral: Omit<TaskSizing, 'desiredCount'>;
  };
  readonly cloudFrontPriceClass: 'PriceClass_100' | 'PriceClass_200' | 'PriceClass_All';
  readonly containerInsights: boolean;
  readonly albDeletionProtection: boolean;
  /** Presupuesto: umbrales (% del límite) de alertas sobre el gasto real. El límite en USD lo da el propietario. */
  readonly budgetActualThresholdsPct: readonly number[];
  readonly budgetForecastThresholdPct: number;
  /** Comandos programados con EventBridge Scheduler (RunTask efímero). */
  readonly scheduledCommands: ReadonlyArray<{
    readonly name: string;
    /** Expresión de EventBridge Scheduler (UTC). */
    readonly expression: string;
    readonly command: readonly string[];
    readonly enabled: boolean;
    readonly description: string;
  }>;
}

const ephemeral = { cpu: 512, memoryMiB: 1024 };

const sharedScheduledCommands: EnvironmentConfig['scheduledCommands'] = [
  {
    name: 'usage-snapshot',
    // 10:30 UTC = 05:30 America/Guayaquil (UTC-5, sin horario de verano): red de seguridad 30 min DESPUÉS de la programación de
    // Laravel (routes/console.php, 05:00 Guayaquil). El comando es idempotente (índice único de tenant_usage_records): un doble
    // disparo reemplaza la fila, no duplica el consumo. Poner `enabled: false` si se prefiere un único disparador.
    expression: 'cron(30 10 * * ? *)',
    command: ['php', 'artisan', 'predictily:usage-snapshot'],
    enabled: true,
    description: 'Medicion diaria de bytes Bronze y de artefactos por empresa (tenant_usage_records; insumo del modelo de costos).',
  },
];

export const ENVIRONMENTS: Record<EnvName, EnvironmentConfig> = {
  staging: {
    name: 'staging',
    vpcCidr: '10.20.0.0/16',
    natGateways: 1,
    interfaceEndpoints: false,
    logRetention: RetentionDays.ONE_MONTH,
    noncurrentVersionDays: { bronze: 30, silver: 30, gold: 30, artifacts: 90 },
    accessLogsRetentionDays: 90,
    database: {
      instanceType: 't4g.small',
      allocatedStorageGiB: 50,
      maxAllocatedStorageGiB: 200,
      multiAz: false,
      backupRetentionDays: 7,
      deletionProtection: false,
      backupWindowUtc: '05:00-05:30',
      maintenanceWindowUtc: 'sun:08:00-sun:09:00',
    },
    queue: { maxReceiveCount: 3, dlqRetentionDays: 14 },
    waf: { managedRulesMode: 'count', generalRateLimit: 2000, authRateLimit: 100 },
    cpuArchitecture: 'X86_64',
    services: {
      api: { cpu: 512, memoryMiB: 1024, desiredCount: 1, minCount: 1, maxCount: 2 },
      workerDefault: { cpu: 512, memoryMiB: 2048, desiredCount: 1 },
      workerForecasting: { cpu: 256, memoryMiB: 1024, desiredCount: 1 },
      scheduler: { cpu: 256, memoryMiB: 512, desiredCount: 1 },
      engine: { cpu: 2048, memoryMiB: 4096, desiredCount: 1 },
      ephemeral,
    },
    cloudFrontPriceClass: 'PriceClass_100',
    containerInsights: false,
    albDeletionProtection: false,
    budgetActualThresholdsPct: [50, 80, 100],
    budgetForecastThresholdPct: 100,
    scheduledCommands: sharedScheduledCommands,
  },
  production: {
    name: 'production',
    vpcCidr: '10.30.0.0/16',
    natGateways: 1,
    interfaceEndpoints: false,
    logRetention: RetentionDays.THREE_MONTHS,
    noncurrentVersionDays: { bronze: 30, silver: 30, gold: 30, artifacts: 365 },
    accessLogsRetentionDays: 365,
    database: {
      instanceType: 't4g.medium',
      allocatedStorageGiB: 100,
      maxAllocatedStorageGiB: 500,
      multiAz: false,
      backupRetentionDays: 14,
      deletionProtection: true,
      backupWindowUtc: '05:00-05:30',
      maintenanceWindowUtc: 'sun:08:00-sun:09:00',
    },
    queue: { maxReceiveCount: 3, dlqRetentionDays: 14 },
    waf: { managedRulesMode: 'count', generalRateLimit: 2000, authRateLimit: 100 },
    cpuArchitecture: 'X86_64',
    services: {
      api: { cpu: 512, memoryMiB: 1024, desiredCount: 2, minCount: 2, maxCount: 4 },
      workerDefault: { cpu: 512, memoryMiB: 2048, desiredCount: 1 },
      workerForecasting: { cpu: 256, memoryMiB: 1024, desiredCount: 1 },
      scheduler: { cpu: 256, memoryMiB: 512, desiredCount: 1 },
      engine: { cpu: 2048, memoryMiB: 4096, desiredCount: 1 },
      ephemeral,
    },
    cloudFrontPriceClass: 'PriceClass_100',
    containerInsights: true,
    albDeletionProtection: true,
    budgetActualThresholdsPct: [50, 80, 100],
    budgetForecastThresholdPct: 100,
    scheduledCommands: sharedScheduledCommands,
  },
};

export function environmentConfig(name: string): EnvironmentConfig {
  if (!(ENV_NAMES as readonly string[]).includes(name)) {
    throw new Error(`Entorno desconocido «${name}». Use -c env=staging o -c env=production.`);
  }
  return ENVIRONMENTS[name as EnvName];
}
