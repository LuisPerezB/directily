import { App } from 'aws-cdk-lib';
import { EnvironmentConfig } from './config/environments';
import { OwnerInputs } from './config/inputs';
import { AppStack } from './app-stack';
import { PlatformStack } from './platform-stack';

export interface Stacks {
  readonly platform: PlatformStack;
  readonly application: AppStack;
}

/** Crea las dos pilas del entorno: datos (platform) y aplicación (app), esta dependiente de aquella. */
export function buildStacks(app: App, config: EnvironmentConfig, inputs: OwnerInputs): Stacks {
  const env = { account: inputs.account, region: inputs.region };
  // Zonas de disponibilidad fijadas por el propietario: evita la consulta de contexto (que exigiría credenciales de AWS al sintetizar).
  app.node.setContext(`availability-zones:account=${inputs.account}:region=${inputs.region}`, [...inputs.availabilityZones]);
  const platform = new PlatformStack(app, `Predictily-${config.name}-Platform`, {
    env,
    config,
    inputs,
    description: `Predictily ${config.name}: red, datos (S3, RDS, EFS), colas, claves, imágenes y presupuesto`,
  });
  const application = new AppStack(app, `Predictily-${config.name}-App`, {
    env,
    config,
    inputs,
    platform,
    description: `Predictily ${config.name}: cómputo (ECS Fargate), borde (ALB, CloudFront, WAF), SPA y tareas programadas`,
  });
  application.addStackDependency(platform, 'La aplicación consume los datos y la red de la pila Platform');
  return { platform, application };
}
