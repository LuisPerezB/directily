# Sector Industry / Manufacturing · contrato de aceptación sectorial (T17)

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · V2.5 · W6-E · Fuentes: V2.5 §25.6 (Industry/Manufacturing) y §24.11, §26 (T15.01–T15.03 y T17), ADR-V25-16 (taxonomía y Factor Intelligence) y los requisitos `V25-SCT-001, V25-SCT-033, V25-SCT-034` de `docs/roadmap/audit/2026-10-31/factores_sector.json`.

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + la prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **P3_PENDIENTE** (sin módulo ni fixture; no se declara cobertura) · **BLOQUEADO** (no se puede construir o verificar sin datos o decisiones del propietario) · **NO APLICA**.

**Aviso de honestidad.** Este documento es el **contrato de aceptación** del sector, no un informe de cobertura. **No existe módulo Industry operativo**: sí existen recetas con rendimiento, capacidad, lotes y un planificador genérico de producción, pero no BOM multinivel, órdenes de producción ni tiempos de planta. Ningún dato de este documento es de un cliente real y ninguna cifra es una mejora medida. Documentos hermanos: `docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md` (casos T15/T17), `docs/intelligence/SECTOR_PROFILES.md` y `docs/intelligence/FACTOR_PIPELINE.md`.

## 1. Alcance (V2.5 §25.6 y §24.11)

Industry / Manufacturing: BOM / listas de materiales, insumos, terminados, rendimientos, órdenes de producción, capacidad de planta, tiempos, mermas, restricciones y necesidades de compras. **No crear otro ERP.**
Perfil de factores: ventas a distribuidores, demanda retail autorizada, capacidad, materias primas, tendencias y riesgos por producto.

## 2. Qué existe (base reutilizable)

| Capacidad | Estado | Evidencia |
|---|---|---|
| Recetas con rendimiento y líneas de insumo (modelo y API) | **IMPLEMENTADO / VERIFICADO** | `backend/tests/Feature/Supply/SupplyConfigApiTest.php::test_recipes_validate_yield_and_lines_and_replace_lines_on_update` |
| Planificación de producción con rendimiento, tiempo, capacidad por recurso e insumos disponibles | **IMPLEMENTADO / VERIFICADO** (planificador genérico) | `engine/tests/unit/test_supply_planner.py::test_generic_production_respects_yield_time_inputs_and_capacity`, `::test_production_is_capped_by_resource_capacity`, `::test_production_is_limited_by_input_stock` |
| Lotes, vida útil y merma proyectada | **IMPLEMENTADO / VERIFICADO** | `engine/tests/unit/test_lots_fefo.py`, `engine/tests/unit/test_supply_perishability.py` |
| Necesidades de compra por plazo y proveedor | **IMPLEMENTADO / VERIFICADO** | `docs/supply/SUPPLY_CORE.md` |
| BOM multinivel, órdenes de producción, tiempos de planta, mermas por proceso con requerimiento de materia prima validado de punta a punta | **P3_PENDIENTE** (T17.02 parcial, T17.03) | `engine/tests/unit/test_sector_contracts.py` |
| Demanda retail autorizada por un cliente (*SharedForecastGrant*) | **DISEÑADO y DESACTIVADO** por bandera (V2.5 §24.10); contrato y pruebas negativas de aislamiento existen | `docs/intelligence/FACTOR_INTELLIGENCE.md` §8 |
| Perfil Manufacturing registrado | **P3_PENDIENTE**: solo existe `synthetic_process_b`, SINTÉTICO de prueba | `engine/tests/unit/test_sector_contracts.py` |

## 3. Criterios de aceptación del sector (todos pendientes)

| # | Criterio | Estado |
|---|---|---|
| I1 | T15.01 / T15.02 / T15.03: casos de fabricante (agua embotellada, alimentos sin gluten, insumos agrícolas) con sus factores, API caída y ablation con y sin factor | `P3_PENDIENTE` · datos **BLOQUEADO** |
| I2 | T17.02: mermas y rendimientos por proceso producen requerimientos de materia prima validados de punta a punta | parcial: rendimiento por receta **IMPLEMENTADO / VERIFICADO**; el resto `P3_PENDIENTE` |
| I3 | T17.03: no mezclar peso terminado y bruto | `P3_PENDIENTE` |
| I4 | T17.05: el mismo flujo para Retail, Industry y Agro en sus fixtures | `P3_PENDIENTE` |
| I5 | Datos reales autorizados de un fabricante piloto | **BLOQUEADO** |
| I6 | Demanda retail autorizada entre empresas con reconciliación sin doble conteo | **DISEÑADO / NO IMPLEMENTADO** (la red colaborativa está desactivada en P0) |

## 4. Gate

Requisito de release del **vertical Industry (P3)**. Un fabricante piloto puede usar hoy el Supply Core genérico (recetas, capacidad, lotes), pero eso **no declara cobertura** de este contrato sectorial.
