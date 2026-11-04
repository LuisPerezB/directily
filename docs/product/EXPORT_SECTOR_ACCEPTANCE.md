# Sector Export · contrato de aceptación sectorial (T15.06, T17)

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · V2.5 · W6-E · Fuentes: V2.5 §25.6 (Export) y §24.11, §26 (T15.06 y T17), ADR-V25-16 (taxonomía y Factor Intelligence) y los requisitos `V25-SCT-004, V25-SCT-015, V25-SCT-031, V25-SCT-032, V25-SCT-033` de `docs/roadmap/audit/2026-10-31/factores_sector.json`.

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + la prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **P3_PENDIENTE** (sin módulo ni fixture; no se declara cobertura) · **BLOQUEADO** (no se puede construir o verificar sin datos o decisiones del propietario) · **NO APLICA**.

**Aviso de honestidad.** Este documento es el **contrato de aceptación** del sector, no un informe de cobertura. **No existe módulo Export**: lo único que existe son bloques genéricos (conversión de unidades, compromisos de pedidos, recetas con rendimiento) que sirven de base. Ningún dato de este documento es de un cliente real y ninguna cifra es una mejora medida. Documentos hermanos: `docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md` (casos T15/T17), `docs/intelligence/SECTOR_PROFILES.md` y `docs/intelligence/FACTOR_PIPELINE.md`.

## 1. Alcance (V2.5 §25.6 y §24.11)

Export (camarón, banano, cacao, flores, etc.): comprador, país y mercado; contrato y orden de exportación; categoría, presentación, talla, calidad y grado; materia prima y producto terminado; rendimiento por proceso;
lotes; inventario comprometido; capacidad; contenedores, embarques y zarpe; flete; Incoterms; FOB/CIF; divisas; costos; cotización y cambios de mercado. Conversiones kg/lb/cajas/toneladas precisas y versionadas;
distinguir materia prima de producto terminado. **Flujo mínimo: demanda → pedidos confirmados → inventario → materia prima**; logística y puertos avanzados son expansión.
Perfil de factores: compradores, pedidos, destinos, países, presentaciones, estadísticas, regulaciones, oferta de proveedores, precios internacionales y restricciones.

## 2. Qué existe (base reutilizable)

| Capacidad | Estado | Evidencia |
|---|---|---|
| Conversión de unidades declarada y «no comparable» sin conversión (ofertas, capacidad) | **IMPLEMENTADO / VERIFICADO** | `backend/tests/Feature/DataCore/CanonicalDataTest.php::test_converter_resolves_only_declared_conversions`; `engine/tests/unit/test_supply_offers.py::test_inverse_conversion_and_missing_conversion`, `::test_no_conversion_or_no_fx_means_not_comparable_and_is_declared` |
| Rendimiento por receta, capacidad e insumos (planificador genérico de producción) | **IMPLEMENTADO / VERIFICADO** | `engine/tests/unit/test_supply_planner.py::test_generic_production_respects_yield_time_inputs_and_capacity`, `::test_production_is_limited_by_input_stock` |
| Compromisos (pedidos confirmados) como demanda comprometida | **IMPLEMENTADO / VERIFICADO** para pedidos propios | `engine/tests/unit/test_customer_commitments.py::test_components_add_up_to_the_aggregate_without_double_counting` |
| Lotes y vida útil (FEFO) | **IMPLEMENTADO / VERIFICADO** | `engine/tests/unit/test_lots_fefo.py`, `engine/tests/unit/test_supply_perishability.py` |
| Módulo Export (comprador, país, presentación, embarque, Incoterms, FOB/CIF) | **P3_PENDIENTE** | no existe |
| Estadísticas de exportación y precios internacionales | **BLOQUEADO / P2**: sin fuente | `docs/intelligence/EXTERNAL_SOURCES.md` |
| Regulaciones del mercado destino | eventos manuales con procedencia (alerta informativa); sin conector | `docs/intelligence/FACTOR_INTELLIGENCE.md` §6 |
| Perfil Export registrado | **P3_PENDIENTE** | `engine/tests/unit/test_sector_contracts.py` |

## 3. Criterios de aceptación del sector (todos pendientes)

| # | Criterio | Estado |
|---|---|---|
| E1 | T15.06: pedido de exportación → conversión por presentación y rendimiento → materia prima, con la prueba de caso completa | `P3_PENDIENTE` |
| E2 | T17.01: conversión por presentación de exportación (1 caja = N kg; 1 lb = 0,45359237 kg; sin factor → «no comparable») aplicada a recetas | parcial: **IMPLEMENTADO / VERIFICADO** para ofertas y capacidad; el resto `P3_PENDIENTE` |
| E3 | T17.03: no mezclar peso terminado y peso bruto (100 kg terminados con rendimiento 0,6 → 166,7 kg de materia prima bruta) | `P3_PENDIENTE` |
| E4 | T17.04: flujo mínimo demanda → pedidos confirmados → inventario → materia prima | `P3_PENDIENTE` |
| E5 | Datos reales autorizados de un exportador piloto | **BLOQUEADO** |
| E6 | Factores del perfil (estadísticas, regulaciones, precios internacionales) con fuente, licencia y ablation con y sin factor | **BLOQUEADO / P2** |
| E7 | Logística avanzada (contenedores, puertos, flete, Incoterms) | **DISEÑADO / NO IMPLEMENTADO** (expansión) |

## 4. Gate

Requisito de release del **vertical Export (P3)**, no del primer piloto B2B. La prueba mínima de Export se conserva como objetivo del módulo; **no se declara completa por una pantalla ni por una conversión de unidades**.
