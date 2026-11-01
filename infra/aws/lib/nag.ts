import { Validations } from 'aws-cdk-lib';
import { IConstruct } from 'constructs';

/**
 * Reconocimientos (supresiones) de cdk-nag. Cada uno exige un motivo concreto: una prueba
 * (test/nag.test.ts) falla si algún reconocimiento no tiene motivo o este es genérico.
 * El identificador es EXACTO (`AwsSolutions-IAM5[Resource::...]` para hallazgos puntuales): no hay comodines.
 */
export const MIN_REASON_LENGTH = 60;

export interface Ack {
  readonly id: string;
  readonly reason: string;
}

export function ackAll(scopes: IConstruct | readonly IConstruct[], items: readonly Ack[]): void {
  const list = Array.isArray(scopes) ? (scopes as readonly IConstruct[]) : [scopes as IConstruct];
  for (const scope of list) {
    for (const item of items) {
      if (item.reason.trim().length < MIN_REASON_LENGTH) {
        throw new Error(`El reconocimiento de ${item.id} en ${scope.node.path} necesita un motivo concreto (>= ${MIN_REASON_LENGTH} caracteres).`);
      }
      Validations.of(scope).acknowledge({ id: item.id, reason: item.reason });
    }
  }
}

export interface AcknowledgmentRecord {
  readonly path: string;
  readonly id: string;
  readonly reason: string;
}

/** Inventario de todos los reconocimientos del árbol (para auditoría y pruebas). */
export function listAcknowledgments(root: IConstruct): AcknowledgmentRecord[] {
  const out: AcknowledgmentRecord[] = [];
  const walk = (node: IConstruct): void => {
    for (const entry of node.node.metadata) {
      if (entry.type === Validations.ACKNOWLEDGED_RULES_METADATA_KEY && entry.data) {
        for (const [qualified, reason] of Object.entries(entry.data as Record<string, string>)) {
          out.push({ path: node.node.path, id: qualified.replace(/^annotation::/i, ''), reason });
        }
      }
    }
    node.node.children.forEach(walk);
  };
  walk(root);
  return out;
}
