# Retail MVP · criterios de aceptación (Radar de Reposición, planes Starter y Growth)

Fecha: 3-nov-2026 · Rama `v25/w1-cierre-y-w2` · Fuentes: V2.5 §1 (caso «Retail/tienda sin compradores identificados»), §16 (planes Starter y Growth), §21, §25.6 (Retail) y §27
(«Retail vs Enterprise»); criterios del MVP heredado en [`docs/01-requisitos.md`](../01-requisitos.md) §3; planes en [`RETAIL_STARTER_GROWTH.md`](RETAIL_STARTER_GROWTH.md);
contrato sectorial en [`RETAIL_SECTOR_ACCEPTANCE.md`](RETAIL_SECTOR_ACCEPTANCE.md).

**Qué es este documento.** Fija qué tiene que hacer el **producto Retail MVP** (demanda por SKU × sucursal, riesgo de agotamiento y sobrestock, reposición sugerida dentro de presupuesto,
sin inventar datos de clientes, con los planes Starter y Growth como capacidad heredada opcional) y cómo se comprueba, con los resultados **exactos** de la última evidencia de
integración. Es distinto de:

- [`RETAIL_SECTOR_ACCEPTANCE.md`](RETAIL_SECTOR_ACCEPTANCE.md): el **perfil sectorial Retail de Factor Intelligence** (T15.04), `P3_PENDIENTE`;
- [`ENTERPRISE_MVP_ACCEPTANCE.md`](ENTERPRISE_MVP_ACCEPTANCE.md): las vistas Enterprise (Revenue Radar B2B, Decision Center, ROI).

Según V2.5 §27, el lanzamiento prioriza el **Revenue Radar B2B**; el Radar de Reposición se **conserva** como vista y capacidad sectorial, y Starter/Growth son hipótesis
comerciales heredadas, no la estrategia obligatoria.

**Leyenda de estado.** **IMPLEMENTADO / VERIFICADO** (código + la prueba o el E2E que lo demuestra) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** · **NO APLICA**. **Toda cifra de este
documento es de datos FICTICIOS** (empresas de demostración «Ferretería Andina» y «Distribuidora Costa», y las empresas que crea el E2E con esos archivos): demuestran capacidad técnica,
**no precisión ni beneficio** (V2.5 §18). Ningún cliente retail real ha usado el producto.

## Contenido

- [1. Qué exige el plan Retail](#1-qué-exige-el-plan-retail)
- [2. Criterios de aceptación y cómo se verifican](#2-criterios-de-aceptación-y-cómo-se-verifican)
- [3. Cómo ejecutar la verificación](#3-cómo-ejecutar-la-verificación)
- [4. Resultados de la última evidencia de integración](#4-resultados-de-la-última-evidencia-de-integración)
- [5. Bloqueado, diseñado y no aplica](#5-bloqueado-diseñado-y-no-aplica)
- [6. Tabla final por módulo](#6-tabla-final-por-módulo)

## 1. Qué exige el plan Retail

| Fuente | Exigencia |
|---|---|
| V2.5 §1 | demanda `SKU × sucursal`, categoría y total; riesgo de agotamiento; sobrestock; presupuesto; orden de reposición sugerida; **sin inventar datos de clientes**; conservar Starter/Growth |
| V2.5 §16 · Starter (USD 49/mes, informativo) | 1 sucursal, ≤ 750 series SKU × sucursal, CSV/Excel, horizontes 7/30, alertas, pronóstico y reposición |
| V2.5 §16 · Growth (USD 119/mes, informativo) | hasta 5 sucursales y 5 000 series, Bridge si es compatible, horizontes 7/15/30, Customer/Radar Comercial **si los IDs son confiables**, agenda, comparación, API, usuarios y roles |
| V2.5 §16 (todas las vistas) | claridad de fecha as-of, unidad, incertidumbre, cobertura y si el dato es real o simulado |
| `docs/01-requisitos.md` §3 | los 13 pasos del MVP de punta a punta con datos del pipeline real (registro → sucursales → importación con errores y corrección → pronóstico → productos → evaluación → reposición → reimportación sin duplicados → nueva versión → segunda empresa aislada) |
| V2.5 §25.6 · Retail | inventario multibodega, ABC/XYZ, rotación, sustitutos y complementarios, promociones, descuentos, calendario y recompra |

## 2. Criterios de aceptación y cómo se verifican

### 2.1 Flujo del MVP (13 pasos heredados)

Lo verifica `e2e/mvp_acceptance.py` (32 verificaciones) contra la API real, el motor y la cola, con dos empresas **nuevas** creadas a partir de `data/demo/`.

| # | Paso (`docs/01-requisitos.md` §3) | Verificación del E2E (texto de `docs/roadmap/evidence/2026-11-03-w6-integration/e2e-mvp-acceptance.log`) | Pruebas de suite que lo sostienen | Estado |
|---|---|---|---|---|
| 1 | Registro de la empresa | «[A] 1. Registro de empresario», «[A] 1. Empresa creada» | `backend/tests/Feature/Core/TenantManagementTest.php` | IMPLEMENTADO / VERIFICADO |
| 2 | Negocio y sucursales | «[A] 2. Configura negocio y sucursales — 3 sucursales» | `backend/tests/Feature/Core/CatalogImportsTest.php::test_branches_suppliers_products_with_price_history` | IMPLEMENTADO / VERIFICADO |
| 3 | Importar ventas históricas, inventario y plazos | «[A] 3. Importa ventas históricas — 44269 documentos nuevos, 796 líneas de devolución, 206 anulados»; inventario 133302 filas; plazos 79 filas | `backend/tests/Feature/Core/SalesImportTest.php::test_semicolon_decimal_comma_windows1252_and_day_first_dates`, `::test_xlsx_import_with_excel_dates` | IMPLEMENTADO / VERIFICADO |
| 4 | Revisar y corregir errores de importación | «[A] 4. Validación detecta errores reales del archivo — 8 filas con error» (8 códigos: `currency_mismatch`, `duplicate_in_file`, `future_date`, `invalid_date`, `invalid_number`, `negative_price`, `negative_quantity`, `net_amount_mismatch`); no se confirma con errores sin `skip_invalid_rows` | `SalesImportTest.php::test_validation_reports_errors_and_blocks_confirmation` | IMPLEMENTADO / VERIFICADO |
| 5 | Procesar los datos | trabajo asíncrono que pasa por `loading_data → backtesting → selecting → training → evaluating → persisting` | `engine/tests/integration/test_quality_gates_integration.py::test_a_normal_forecast_persists_its_report_with_the_right_tenant_and_the_defaults_do_not_block` | IMPLEMENTADO / VERIFICADO |
| 6 | Pronóstico con un modelo estadístico o de ML real | «[A] 6. Pronóstico generado por modelo estadístico/ML real — versión 1» (por clase: `auto_ets`, `lightgbm_global` para intermitentes, `window_average` de respaldo) | `engine/tests/unit/test_pipeline.py::test_compares_against_baseline` | IMPLEMENTADO / VERIFICADO |
| 7 | Ver las ventas previstas | «[A] 7. Visualiza ventas previstas — 14 periodos históricos, 5 pronosticados»; «Dashboard responde con KPIs reales — 30 días: 22169 u. (80%: 18873.1152–25465.5368)» | `backend/tests/Feature/Analytics/ForecastQueryApiTest.php::test_series_node_selection_and_stockout_flags` | IMPLEMENTADO / VERIFICADO |
| 8 | Productos de mayor y menor demanda | «[A] 8. Productos de mayor/menor demanda» | `backend/tests/Feature/Analytics/ForecastQueryApiTest.php::test_products_ranking_trend_sort_search_and_pagination` | IMPLEMENTADO / VERIFICADO |
| 9 | Evaluación histórica del modelo | «[A] 9. Evaluación histórica (backtesting) disponible — WAPE seleccionado 0.491 vs baseline 0.660, cobertura 80% 0.856» | `engine/tests/unit/test_pipeline.py::test_holdout_is_disjoint_from_selection_windows` | IMPLEMENTADO / VERIFICADO (cifras ficticias) |
| 10 | Recomendación básica de abastecimiento | «[A] 10. Recomendación básica de abastecimiento — 183 recomendaciones» | `engine/tests/unit/test_inventory_validation.py::test_required_coverage_days_uses_lead_time_plus_review` | IMPLEMENTADO / VERIFICADO |
| 11 | Reimportar sin duplicados | «[A] 11. Reimportar no genera duplicados — nuevos 0, sin cambios 44269, documentos 43267→43267» | `SalesImportTest.php::test_full_flow_validate_confirm_and_reimport_is_idempotent` | IMPLEMENTADO / VERIFICADO |
| 12 | Regenerar el pronóstico y consultar su versión | «[A] 12. Regenera pronóstico y consulta su versión — versiones [2, 1], vigente v2» | `backend/tests/Feature/Analytics/ForecastRunApiTest.php::test_versions_increment_per_tenant_and_cutoff_creates_historical_run` | IMPLEMENTADO / VERIFICADO |
| 13 | Una segunda empresa sin acceso a la primera | 7 verificaciones «[B] 13. No accede a …» (run, producto, importación, trabajo, pronósticos por `run_id`, `X-Tenant-ID` de A, solo ve su empresa) | `backend/tests/Feature/Core/TenantIsolationTest.php::test_guessing_ids_of_another_tenant_returns_404_for_every_resource` | IMPLEMENTADO / VERIFICADO |

### 2.2 Exigencias propias de Retail (V2.5 §1 y §16)

| ID | Criterio | Cómo se verifica | Estado |
|---|---|---|---|
| RMVP-01 | Demanda por SKU × sucursal coherente con la categoría y el total | `engine/tests/unit/test_pipeline.py::test_hierarchy_is_coherent` (suma diaria serie/producto/sucursal = total) | IMPLEMENTADO / VERIFICADO; la suma por categoría y las filas semanales/mensuales **no** tienen aserción (T03.13 🟡) |
| RMVP-02 | Riesgo de agotamiento y quiebre proyectado | `engine/tests/unit/test_inventory_validation.py::test_projected_risk_rules`, `engine/tests/unit/test_inventory.py::test_risk_levels`, `::test_f1_late_purchase_order_does_not_hide_stockout` (en `test_inventory_validation.py`) | IMPLEMENTADO / VERIFICADO |
| RMVP-03 | Sobrestock medido contra la demanda realizada | `engine/tests/unit/test_supply_simulation.py::test_overstock_is_measured_against_realized_future_demand` | IMPLEMENTADO / VERIFICADO |
| RMVP-04 | Reposición sugerida que nunca excede el presupuesto ni sugiere cantidades negativas | `engine/tests/unit/test_supply_planner.py::test_budget_per_issue_period_is_never_exceeded`, `engine/tests/unit/test_scenarios_evaluator.py::test_inventory_never_negative_and_balance_identity_in_random_cases` | IMPLEMENTADO / VERIFICADO |
| RMVP-05 | La recomendación se **sugiere**, nunca se ordena ni se escribe en el ERP | `backend/tests/Feature/Analytics/InventoryAlertsScenariosExportsTest.php::test_recommendation_status_update_is_registered_but_never_orders` | IMPLEMENTADO / VERIFICADO |
| RMVP-06 | Sin compradores identificados no se inventan datos de clientes | `engine/tests/integration/test_customer_integration.py::test_generic_and_anonymous_buyers_get_no_individual_prediction`; Starter sin Customer Core: `backend/tests/Feature/Plans/CommercialPlansTest.php::test_starter_has_no_customer_core_and_every_customer_endpoint_says_so` | IMPLEMENTADO / VERIFICADO |
| RMVP-07 | Horizontes por plan (Starter 7/30; Growth 7/15/30) dentro del catálogo único | `CommercialPlansTest.php::test_starter_growth_and_enterprise_are_configurable_plans_with_the_documented_hypotheses`, `::test_horizon_15_is_accepted_only_by_the_plans_that_include_it` | IMPLEMENTADO / VERIFICADO |
| RMVP-08 | Tope de series activas del plan (Starter 750, Growth 5 000) | `CommercialPlansTest.php::test_active_series_over_the_plan_cap_block_a_new_forecast_run`, `::test_series_outside_the_window_do_not_count_and_plans_without_a_cap_never_query` | IMPLEMENTADO / VERIFICADO; la definición de «serie activa» (90 días) es un supuesto **a aprobar en el piloto** |
| RMVP-09 | Un precio nunca es un límite | `CommercialPlansTest.php::test_no_price_is_ever_a_limit` | IMPLEMENTADO / VERIFICADO |
| RMVP-10 | Starter «solo CSV/Excel» | `features.api_access = false` impide crear tokens de API | **PARCIAL**: los conectores SQL **no** se restringen por plan (DISEÑADO / NO IMPLEMENTADO) |
| RMVP-11 | Datos insuficientes → abstención explícita, nunca una cifra inventada | `engine/tests/unit/test_pipeline.py::test_short_history_abstains_explicitly_instead_of_failing`; gate BLOCK: `engine/tests/integration/test_quality_gates_integration.py::test_a_block_gate_stops_the_forecast_with_a_stable_code_and_leaves_its_report` | IMPLEMENTADO / VERIFICADO |
| RMVP-12 | Días con quiebre de stock censurados (no contaminan el entrenamiento ni el error) | `engine/tests/unit/test_forecast_validation_scenarios.py::test_the_stockout_does_not_contaminate_the_training_of_the_global_model_or_the_forecast_level` | IMPLEMENTADO / VERIFICADO |
| RMVP-13 | Promociones as-of (una promoción registrada después del corte no se usa) | `engine/tests/unit/test_promotions_as_of.py::test_audit_case_promotion_registered_after_cutoff_is_not_used` | IMPLEMENTADO / VERIFICADO |
| RMVP-14 | Growth: Customer/Radar Comercial solo si los IDs son confiables | el motor degrada a agregado con IDs inestables (`engine/tests/integration/test_customer_s05_integration.py::test_unstable_ids_disable_individual_recommendations_and_publish_an_aggregate_ranking`) | IMPLEMENTADO / VERIFICADO el mecanismo; el **criterio de confiabilidad** por empresa: a definir en el piloto |
| RMVP-15 | Growth: Bridge si es compatible | agente Bridge (W6-B): 344 pruebas del agente en la integración de W6; registro y latido firmado en `e2e/v25_acceptance.py` | IMPLEMENTADO / VERIFICADO con PostgreSQL, SQLite y archivos ficticios; MySQL y SQL Server sin servidor; instalador Windows **BLOQUEADO** |
| RMVP-16 | Growth: roles por plan (`features.roles`) | — | **DISEÑADO / NO IMPLEMENTADO**: los roles existen en todos los planes; ninguna regla los condiciona por plan |
| RMVP-17 | Growth: «comparación» como módulo | — | **NO APLICA** hoy: no existe como módulo y no se declara una capacidad inexistente |
| RMVP-18 | Inventario multibodega, ABC/XYZ, rotación, sustitutos y complementarios (§25.6) | — | **DISEÑADO / NO IMPLEMENTADO** (V25-SCT-011, V25-SCT-013); el inventario es por sucursal |
| RMVP-19 | Precisión y beneficio con datos reales de una cadena retail | — | **BLOQUEADO**: sin piloto retail con datos autorizados |

## 3. Cómo ejecutar la verificación

Requisitos: backend, cola (`queue:work`) y motor activos; datos demo en `data/demo/`.

```bash
# Empresas de demostración (datos ficticios)
cd backend && php artisan predictily:demo --fresh --forecast
# E2E heredados (crean empresas NUEVAS con sufijo aleatorio; no tocan las de demostración)
engine/.venv/bin/python e2e/mvp_acceptance.py --api http://127.0.0.1:8000/api/v1
engine/.venv/bin/python e2e/stage2_acceptance.py --api http://127.0.0.1:8000/api/v1 --engine-dir engine
engine/.venv/bin/python e2e/v25_acceptance.py --api http://127.0.0.1:8000/api/v1 --engine-dir engine   # E2E V2.5 (W6-F); crea una base «ERP de origen» ficticia local
# Solo un bloque de la etapa 2: --only demand,customer,commitments,supply,data,assistant,isolation
# Pruebas de los planes
cd backend && DB_DATABASE=predictily_test php artisan test --filter='CommercialPlansTest|SeriesCountTest|PlanLimitTest|HorizonCatalogTest'
```

`--engine-dir` permite que la etapa 2 verifique de forma independiente el SHA-256 de los artefactos (sin él aparece un aviso más, no una regresión: ver la evidencia de W3).

## 4. Resultados de la última evidencia de integración

Evidencia: `docs/roadmap/evidence/2026-11-03-w6-integration/` (W6 integrado; stack aislado en `aa82db1` con `vendor` propio; macOS arm64, PostgreSQL 15, PHP 8.3, Python 3.12).
La integración anterior (W5, `2d1a0b1`) dio los mismos resultados de E2E y de la demo.

| Verificación | Resultado registrado |
|---|---|
| Backend (`php artisan test`) | `{"tests":2583,"passed":2574,"skipped":9}` (61 402 aserciones); `backend_exit=0` |
| Motor (`pytest`) | 1496 aprobadas, 10 omitidas (37 min 06 s) |
| Frontend | type-check 0 · lint 0 · vitest 749 aprobadas (56 archivos) · build correcto |
| Agente Bridge (`bridge/`) | 344 aprobadas |
| `e2e/mvp_acceptance.py` | **32 de 32** verificaciones OK |
| `e2e/stage2_acceptance.py` | **256 de 256** OK · 0 FALLA · 1 AVISO (de diseño: conciliación H = 90 sin producto con agregado completo; «no se inventa residual»). Por bloque: preparación 9, demanda v2 61, compradores 45, compromisos 14, abastecimiento/costos/escenarios/causalidad 43, datos 20, asistente desactivado 5, aislamiento entre empresas 59; 697 s en total |
| `e2e/v25_acceptance.py` (nuevo, W6-F) | **160 de 160** OK · 0 FALLA · 3 AVISOS (outcome con la ventana de la decisión abierta, asistente sin clave de proveedor LLM, AWS BLOQUEADO). Recorre, con datos ficticios, la importación CSV de la empresa A (99 334 filas de ventas, 44 269 documentos), una fuente SQL PostgreSQL ficticia con calidad y cuarentena, entrenamiento con gates, aprobación separada con firma y segundo factor, inferencia, Radar, decisión, fallo de calidad que bloquea (empresa C con 20 días de historia), reversión y cambio de empresa sin filtraciones (33 verificaciones) |

**Demo (datos ficticios, `demo_metrics.json`, corte 2026-10-26, período final reservado; idéntica a W5 según `demo_compare_vs_w5.json`):**

| Empresa demo | WAPE seleccionado | WAPE Seasonal Naive | Cobertura 80 % | Sesgo | Observaciones | Gate de calidad |
|---|---:|---:|---:|---:|---:|---|
| Ferretería Andina (multisucursal, perfil retail) | 0,4908 | 0,6604 | 0,8499 | −0,0403 | 15 400 | PASS |
| Distribuidora Costa | 0,4488 | 0,5983 | 0,8028 | 0,0061 | 6 759 | PASS |

Customer Core en la demo: corrida `completed` con calidad `degraded` en ambas empresas; a 7, 14 y 30 días se sirve la **referencia** de probabilidad (ningún modelo la supera), y
la ventana de recompra no se publica (sin validación). Demanda y Customer Core son idénticos a los de W5 (`demo_compare_vs_w5.json`) y a los de W4.

Cómo leerlo: el WAPE del E2E (0,491 con cobertura 0,856) y el de la demo (0,4908 con 0,8499) son de **empresas distintas** creadas con los mismos archivos ficticios; no se
promedian ni se comparan entre sí. Ninguna de estas cifras es un objetivo ni una promesa: el objetivo de precisión de un cliente se acuerda con él en el piloto (V2.5 §19).

## 5. Bloqueado, diseñado y no aplica

| Tema | Estado | Qué hace falta |
|---|---|---|
| Datos reales de una cadena retail (ventas, promociones, inventario por sucursal) y export CSV/Excel real de un cliente (T01.02) | **BLOQUEADO** | contrato y autorización del cliente ([`docs/operations/OWNER_RESPONSIBILITIES.md`](../operations/OWNER_RESPONSIBILITIES.md) OWN-01) |
| Línea base y validación prospectiva con datos reales | **BLOQUEADO** | ver [`reports/V25_PILOT_BASELINE.md`](../../reports/V25_PILOT_BASELINE.md) y [`reports/V25_PROSPECTIVE_VALIDATION.md`](../../reports/V25_PROSPECTIVE_VALIDATION.md) |
| Topes operativos supuestos de Starter/Growth (filas por mes, ejecuciones por día, usuarios) | VALOR INICIAL | a aprobar en el piloto (OWN-27) |
| Restricción de conectores por plan, roles condicionados por plan, límites Enterprise declarados conectados a los módulos | **DISEÑADO / NO IMPLEMENTADO** | `RETAIL_STARTER_GROWTH.md` §3 y §7 |
| Escalón de backtest de 15 días (con 84–149 días de historia H15 queda `insufficient_data`) | **DISEÑADO / NO IMPLEMENTADO** | decisión del arquitecto: no se cambió para no alterar métricas existentes |
| Perfil sectorial Retail de Factor Intelligence (competencia, clima, tendencias) | `P3_PENDIENTE` / **BLOQUEADO** (sin fuentes) | `RETAIL_SECTOR_ACCEPTANCE.md` |
| Pasarela de pagos y facturación | **NO APLICA** | fuera de alcance (ADR-V25-18) |

## 6. Tabla final por módulo

| Módulo | Evidencia | Estado de pruebas | Costo | Riesgo | Aprobación |
|---|---|---|---|---|---|
| Importación CSV/Excel | `SalesImportTest.php`; E2E pasos 3, 4 y 11; `e2e/v25_acceptance.py` (CSV y cuarentena) | en verde (W6); 32/32 y 160/160 | cómputo local | sin export real de un cliente | mapeo y plantilla: propietario (OWN-06) |
| Pronóstico por SKU × sucursal | `test_pipeline.py`; demo W6 (idéntica a W5) | en verde; métricas ficticias | CPU local | sin evidencia con datos reales | umbrales y política: propietario (OWN-13, OWN-16) |
| Reposición, agotamiento y sobrestock | `test_inventory*.py`, `test_supply_*.py`; E2E paso 10 | en verde | CPU local (MILP con tope de tiempo) | costos no confiables dejan el margen desconocido | parámetros de Supply: propietario con el cliente (OWN-19) |
| Planes Starter y Growth | `CommercialPlansTest.php`, `SeriesCountTest` | en verde (W2-E) | — | topes supuestos (*) | plan y límites por empresa: propietario (OWN-63) |
| Customer en Growth | `test_customer_s05_integration.py` | en verde; sintético | CPU local | criterio de IDs confiables sin definir | propietario en el piloto (OWN-64) |
| Bridge en Growth | `BRIDGE_WINDOWS.md` | en verde en la integración de W6 (agente: 344) | se ejecuta en el equipo del cliente | instalador Windows BLOQUEADO | certificado de firma: propietario (OWN-59) |
| Aislamiento entre empresas | E2E paso 13, bloque E2-17 (59 verificaciones) y cambio de empresa del E2E V2.5 (33) | en verde | — | concurrencia entre procesos no verificada | — |
