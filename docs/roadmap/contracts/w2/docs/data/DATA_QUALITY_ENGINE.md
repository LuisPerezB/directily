# Data Quality Engine (V2.5 §5)

Decisión: `docs/architecture/adr/V25-ADRS.md` (ADR-V25-05 y ADR-V25-24). Migración:
`backend/database/migrations/2026_03_03_000100_create_data_quality_lineage_and_identity_tables.php`.

Estados de cada apartado: **IMPLEMENTADO/VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO/NO
IMPLEMENTADO**, **BLOQUEADO**, **NO APLICA**.

## 1. Contrato común de hallazgos

Un hallazgo tiene clase, código estable, entidad, referencia del registro sin PII, fila de origen, campo,
valor original acotado (el registro íntegro queda en Bronze), propuesta, si se aplicó y motivo. Clases:

| Clase | Significado | Efecto |
|---|---|---|
| `AUTO_FIX_SAFE` | normalización segura y reversible | se aplica y se registra (agregado) |
| `REVIEW_REQUIRED` | requiere decisión humana o un mapeo de valores | el registro va a cuarentena |
| `QUARANTINED` | dato inválido que no se puede corregir con seguridad | el registro va a cuarentena |
| `WARNING` | anomalía que no impide usar el dato | se informa |
| `BLOCKER` | impide la carga completa | la carga se bloquea |

<!-- Sección 2 a 4: paquete W2-A (ingestión). Sección 5 a 7: paquete W2-C (métricas y gates del motor). -->

## 2. Catálogo de códigos de ingestión y su clase (W2-A)

DISEÑADO/NO IMPLEMENTADO.

## 3. Cuarentena y reprocesamiento (W2-A)

DISEÑADO/NO IMPLEMENTADO.

## 4. Informes de ingestión, mapeos de valores y deriva de esquema (W2-A)

DISEÑADO/NO IMPLEMENTADO.

## 5. Métricas de dataset (W2-C)

DISEÑADO/NO IMPLEMENTADO.

## 6. Gates por módulo: PASS, WARN, DEGRADE, BLOCK (W2-C)

DISEÑADO/NO IMPLEMENTADO. Los umbrales iniciales se documentarán con su justificación y quedarán marcados
«a aprobar en el piloto»; no son universales.

## 7. Mapa feature → datos requeridos (W2-C)

DISEÑADO/NO IMPLEMENTADO.
