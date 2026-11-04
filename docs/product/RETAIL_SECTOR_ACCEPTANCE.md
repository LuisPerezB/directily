# Sector Retail · contrato de aceptación sectorial (T15.04)

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · V2.5 · W6-E · Fuentes: V2.5 §25.6 (Retail) y §24.11, §26 (T15.04), ADR-V25-16 (taxonomía y Factor Intelligence) y los requisitos `V25-SCT-002, V25-SCT-011, V25-SCT-013, V25-SCT-029` de `docs/roadmap/audit/2026-10-31/factores_sector.json`.

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + la prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **P3_PENDIENTE** (sin módulo ni fixture; no se declara cobertura) · **BLOQUEADO** (no se puede construir o verificar sin datos o decisiones del propietario) · **NO APLICA**.

**Aviso de honestidad.** Este documento es el **contrato de aceptación** del sector, no un informe de cobertura. El producto Retail MVP (planes Starter y Growth) tiene su propio documento, `RETAIL_STARTER_GROWTH.md`; éste fija lo que haría falta para aceptar el **perfil sectorial Retail de Factor Intelligence**, que **no está registrado**. Ningún dato de este documento es de un cliente real y ninguna cifra es una mejora medida. Documentos hermanos: `docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md` (casos T15/T17), `docs/intelligence/SECTOR_PROFILES.md` y `docs/intelligence/FACTOR_PIPELINE.md`.

## 1. Alcance (V2.5 §25.6 y §24.11)

Retail: SKU/sucursal, inventario multibodega, clasificación ABC/XYZ, rotación, sustitutos y complementarios, promociones, descuentos, calendario y recompra; perfil de factores: venta final, promociones,
inventario, competencia, clima, tendencias y perfiles de compradores si existen. El primer flujo sectorial comercial antiguo sigue disponible, pero el marketing Enterprise prioriza B2B.

## 2. Qué existe (base reutilizable)

| Capacidad | Estado | Evidencia |
|---|---|---|
| Pronóstico por sucursal × producto con baseline, selección y período final reservado | **IMPLEMENTADO / VERIFICADO** (empresas ficticias multisucursal) | `engine/tests/unit/test_pipeline.py::test_compares_against_baseline`, `::test_holdout_is_disjoint_from_selection_windows` |
| Promociones y calendario como features as-of | **IMPLEMENTADO / VERIFICADO** | `engine/tests/unit/test_promotions_as_of.py::test_audit_case_promotion_registered_after_cutoff_is_not_used`, `::test_past_dates_use_the_promotion_as_it_occurred` |
| Inventario por sucursal y recomendaciones de abastecimiento | **IMPLEMENTADO / VERIFICADO** | `docs/supply/SUPPLY_CORE.md` |
| Recompra de clientes (Customer Core) | **IMPLEMENTADO / VERIFICADO** | `docs/ml/CUSTOMER_REPURCHASE.md` |
| Pipeline de factores con competencia frente al modelo sin factores y ablation (con y sin factor) | **IMPLEMENTADO / VERIFICADO** con datos ficticios; sin fuentes conectadas | `docs/intelligence/FACTOR_PIPELINE.md` |
| Perfil Retail registrado en el motor | **P3_PENDIENTE**: solo existe `synthetic_retail_a`, SINTÉTICO de prueba | `engine/tests/unit/test_sector_contracts.py` |
| Competencia, clima y tendencias como fuentes | **BLOQUEADO / P2**: sin conectores, licencias ni historial de emisiones | `docs/intelligence/EXTERNAL_SOURCES.md` |
| Clasificación ABC/XYZ, rotación, sustitutos y complementarios | **DISEÑADO / NO IMPLEMENTADO** (V25-SCT-011, V25-SCT-013) | — |

## 3. Criterios de aceptación del sector (todos pendientes de un piloto)

| # | Criterio | Estado |
|---|---|---|
| R1 | Perfil Retail registrado (no sintético) con las familias que aplican (promociones, inventario, competencia, clima, tendencias) y sus reglas de aplicabilidad y disponibilidad temporal | `P3_PENDIENTE` |
| R2 | Datos reales autorizados de una cadena piloto (ventas, promociones, inventario por sucursal) | **BLOQUEADO** |
| R3 | Baseline con y sin factores por sucursal y tramo de volumen, con `engine/scripts/factor_ablation.py` sobre una copia de pruebas, período final intacto y decisión por la regla de `FACTOR_PIPELINE.md` §4 | `P3_PENDIENTE` |
| R4 | Prueba de API caída de cada fuente (el modelo queda con el respaldo sin el factor, con la antigüedad etiquetada) | `P3_PENDIENTE` |
| R5 | Métricas por horizonte (7/14/15/30/60/90) y segmento con los denominadores de W3-A y costo real de cómputo y de APIs | `P3_PENDIENTE` |
| R6 | Aislamiento entre empresas de los datos y de los factores del piloto | **IMPLEMENTADO / VERIFICADO** en general (`FACTOR_PIPELINE.md` §3); falta con los datos del piloto |
| R7 | ABC/XYZ, sustitutos y complementarios | **DISEÑADO / NO IMPLEMENTADO** |

## 4. Gate

Requisito de release de **Factor Intelligence / Retail (P2)**, no del primer piloto B2B P0/P1. Hasta cumplir R1–R5 con datos reales, el caso T15.04 permanece `P3_PENDIENTE` y no se declara cobertura.
