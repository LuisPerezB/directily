# Sector Agro · contrato de aceptación sectorial (T15.03, T15.05)

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · V2.5 · W6-E · Fuentes: V2.5 §25.6 (Agro) y §24.11, §26 (T15.03, T15.05 y T17), ADR-V25-16 (taxonomía y Factor Intelligence) y los requisitos `V25-SCT-003, V25-SCT-023, V25-SCT-024, V25-SCT-028, V25-SCT-030` de `docs/roadmap/audit/2026-10-31/factores_sector.json`.

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + la prueba que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **P3_PENDIENTE** (sin módulo ni fixture; no se declara cobertura) · **BLOQUEADO** (no se puede construir o verificar sin datos o decisiones del propietario) · **NO APLICA**.

**Aviso de honestidad.** Este documento es el **contrato de aceptación** del sector, no un informe de cobertura. **No existe módulo Agro**: lo único específico es la categoría de calendario `agricultural_season` (y sus alias de importación) y los eventos manuales de sanidad vegetal. Ningún dato de este documento es de un cliente real y ninguna cifra es una mejora medida. Documentos hermanos: `docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md` (casos T15/T17), `docs/intelligence/SECTOR_PROFILES.md` y `docs/intelligence/FACTOR_PIPELINE.md`.

## 1. Alcance (V2.5 §25.6 y §24.11)

Agro: cultivos, siembra y cosecha, rendimientos, centros de acopio, oferta estimada, clima y plagas, calidad y precios, temporadas, abastecimiento y clientes. **Separar la predicción de producción agrícola de la
demanda comercial.** Perfil de factores: oferta agrícola (siembra, rendimiento, cosecha, plagas, clima) diferenciada de la demanda comercial y de la demanda autorizada de fabricantes y exportadores.

## 2. Qué existe (base reutilizable)

| Capacidad | Estado | Evidencia |
|---|---|---|
| Categoría de calendario `agricultural_season` (siembra, cosecha, ciclo agrícola) con alias de importación | **IMPLEMENTADO** como dato de calendario; **no es un módulo Agro** | `backend/app/Models/CalendarEvent.php`, `backend/app/Services/Imports/Processors/CalendarEventImportProcessor.php` |
| Eventos de sanidad vegetal y animal con procedencia, segregación de funciones y alerta informativa | **IMPLEMENTADO / VERIFICADO** (solo manuales; sin conector) | `docs/intelligence/FACTOR_INTELLIGENCE.md` §6 |
| Estimaciones de cosecha de terceros (`production_estimates.crop_estimate`) | **DISEÑADO**: catalogado como pronóstico de tercero que exige historial archivado de emisiones; **sin fuente** | `docs/intelligence/FACTOR_INTELLIGENCE.md` §3 |
| Pipeline de factores con ablation (clima, calendario) | **IMPLEMENTADO / VERIFICADO** con datos ficticios; sin fuentes conectadas | `docs/intelligence/FACTOR_PIPELINE.md` |
| Oferta agrícola separada de la demanda comercial, rendimientos, acopio | **P3_PENDIENTE** | `engine/tests/unit/test_sector_contracts.py` |
| Perfil Agro registrado | **P3_PENDIENTE** | — |

## 3. Criterios de aceptación del sector (todos pendientes)

| # | Criterio | Estado |
|---|---|---|
| A1 | T15.03: fabricante de insumos agrícolas (clima, sanidad vegetal, ventas a distribuidores) con API caída y ablation con y sin factor | `P3_PENDIENTE` · datos **BLOQUEADO** |
| A2 | T15.05: productor agrícola con la estimación de cosecha **separada** de la demanda comercial y la demanda autorizada | `P3_PENDIENTE` · datos **BLOQUEADO** |
| A3 | Fuentes de clima, siembra y rendimiento con licencia, retención y historial de emisiones | **BLOQUEADO / P2** |
| A4 | T17.05: el mismo flujo productivo para Agro en su fixture | `P3_PENDIENTE` |
| A5 | No extrapolar causalidad ni capacidad productiva sin datos (V2.5: señales versionadas o escenarios **supuestos**, con diagnóstico de disponibilidad) | regla de diseño vigente; sin módulo que la ejerza |

## 4. Gate

Requisito de release del **vertical Agro (P3)**. Mientras no exista oferta agrícola separada de la demanda comercial, ninguna cifra de este sector se presenta como pronóstico de producción.
