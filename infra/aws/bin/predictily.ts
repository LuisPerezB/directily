#!/usr/bin/env node
import * as path from 'path';
import { App, Tags, Validations } from 'aws-cdk-lib';
import { AwsSolutionsChecks } from 'cdk-nag';
import { environmentConfig } from '../lib/config/environments';
import { resolveOwnerInputs } from '../lib/config/inputs';
import { buildStacks } from '../lib/stacks';

/**
 * Punto de entrada de la app CDK.
 *   cdk synth -c env=staging -c useFictitiousInputs=true      (verificación local, sin credenciales)
 *   cdk synth -c env=production -c account=... -c region=... (datos reales del propietario: ver AWS_DEPLOYMENT.md)
 * `-c nag=false` desactiva cdk-nag (solo para depurar; el CI y `npm run verify` lo mantienen activo).
 */
const app = new App();
const envName = String(app.node.tryGetContext('env') ?? '');
const config = environmentConfig(envName);
const inputs = resolveOwnerInputs(config.name, (k) => app.node.tryGetContext(k), path.resolve(__dirname, '..'));
buildStacks(app, config, inputs);

Tags.of(app).add('predictily:environment', config.name);
Tags.of(app).add('predictily:managed-by', 'cdk');
if (inputs.fictitious) Tags.of(app).add('predictily:inputs', 'FICTICIOS-no-desplegar');
if (String(app.node.tryGetContext('nag')) !== 'false') {
  // cdk-nag v3: complemento de validación de CDK. Una violación interrumpe `cdk synth` (código de salida distinto de 0).
  Validations.of(app).addPlugins(new AwsSolutionsChecks(app, { verbose: true, writeSuppressionsToCloudFormation: true }));
}
app.synth();
