# Predictily V2 · Matriz de aceptación (requisito → código → prueba → evidencia → estado)

Fuente de los casos: `instrucctions.md` §6.J. Estados: ✅ comprobado (prueba automatizada ejecutada) ·
🧪 comprobado solo con datos sintéticos · 🟡 parcial · ⏳ pendiente · 🔌 requiere acceso externo.
"Comprobado" nunca significa validado comercialmente: la validación con datos reales se registra aparte
(columna *Datos reales*).

| ID | Caso | Paquete | Código | Prueba | Evidencia | Estado | Datos reales |
|---|---|---|---|---|---|---|---|
| E2-01 | Compatibilidad etapa 1 | todos | — | suites completas + `e2e/mvp_acceptance.py` | — | ⏳ | — |
| E2-02 | Recepción tardía | PR #1 | `inventory/recommendations.py` | `test_inventory_validation.py` | `docs/validation/2026-10-29/probe-results-after-fixes.json` | ✅ | — |
| E2-03 | Horizonte físico | PR #1 | `inventory/recommendations.py`, `pipeline/core.py` | `test_inventory_validation.py` | ídem | ✅ | — |
| E2-04 | Incertidumbre ausente | PR #1 | `inventory/recommendations.py` | `test_inventory_validation.py` | ídem | ✅ | — |
| E2-05 | Temporalidad (ventas, perfiles, promociones, costos, revisiones) | PR #1 + P1/P3/P4 | | | | 🟡 | — |
| E2-06 | Protocolo independiente (manifiesto) | P1 (+P2) | | | | ⏳ | — |
| E2-07 | Plan Básico en ejecución programada | PR #1 | `ScheduleForecastsCommand`, `ForecastRunService` | `PlanHorizonSchedulingTest` | — | ✅ | — |
| E2-08 | Recompra: suma de compras y etiquetas censuradas | P2 | | | | ⏳ | — |
| E2-09 | Compradores anónimos y nuevos | P2 | | | | ⏳ | — |
| E2-10 | Calibración | P2 | | | | ⏳ | — |
| E2-11 | Reconciliación demanda ↔ comprador | P2 | | | | ⏳ | — |
| E2-12 | Inventario: tránsito, parciales, fechas desconocidas, reservas | P4 (+PR #1) | | | | 🟡 | — |
| E2-13 | Planificador: casos de solución conocida | P4 | | | | ⏳ | — |
| E2-14 | Producción genérica | P4 | | | | ⏳ | — |
| E2-15 | Costos y escenarios | P4 | | | | ⏳ | — |
| E2-16 | Causalidad | P4 | | | | ⏳ | — |
| E2-17 | Aislamiento (datos, features, modelos, cachés, resultados, jobs, extensiones) | todos | | | | ⏳ | — |
| E2-18 | Reintentos y actualización sin venta nueva | P2 (+P1) | | | | ⏳ | — |
| E2-19 | Fallo del ganador | P1 | | | | ⏳ | — |
| E2-20 | Operación real | P4 (simulación) | | | | 🔌 | pendiente de acceso autorizado (Soyoda/Resgasa) |
| E2-21 | Horizontes del core | P1 + P2 | | | | ⏳ | — |
| E2-22 | Salidas Customer (monto, frecuencia, ranking, inactividad) | P2 | | | | ⏳ | — |
| E2-23 | Compromisos comerciales sin doble conteo | P2 + P3 | | | | ⏳ | — |
| E2-24 | Registro, reversión y autonomía sin LLM | P1 + P2 + P5 | | | | ⏳ | — |
| E2-25 | Conector SQL solo lectura | P3 | | | | ⏳ | — |
| E2-26 | Proveedores y fechas | P4 | | | | ⏳ | — |

## Capacidades por nivel de validación

Se completa al cierre: implementadas · comprobadas solo con sintéticos · validadas con datos reales ·
dependencias externas pendientes.
