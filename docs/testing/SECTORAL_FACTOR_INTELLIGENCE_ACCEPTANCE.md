# Aceptación sectorial de Factor Intelligence (T15) y conversión productiva (T17) · V2.5 §26 · W6-E

> **Estado honesto.** Este documento fija **qué haría falta** para aceptar los seis casos sectoriales de V2.1 y la conversión productiva de V2, y declara **qué existe hoy**. Los seis casos de T15 y los casos T17.03–T17.05
> están **`P3_PENDIENTE`**: no tienen fixture, ni módulo, ni piloto, y **no se declara cobertura** por contar con datos sintéticos (V25-SCT-007). Los datos reales de cada sector están **BLOQUEADO** hasta un
> piloto autorizado por el propietario y por el cliente de cada sector (V25-SCT-006). Ninguna cifra de este documento es una mejora medida.
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **P3_PENDIENTE**, **BLOQUEADO** o **NO APLICA**.
> Las pruebas marcadas viven en `engine/tests/unit/test_sector_contracts.py` (nueve pruebas omitidas con el motivo `P3_PENDIENTE`: aparecen en cada ejecución como pendientes, nunca como aprobadas) y una prueba de
> coherencia verifica que cada caso pendiente figure aquí con su marca.
>
> Documentos hermanos: `docs/intelligence/SECTOR_PROFILES.md` (perfiles), `docs/intelligence/FACTOR_INTELLIGENCE.md` (catálogo y contratos), `docs/intelligence/FACTOR_PIPELINE.md` (pipeline de factores y ablation),
> `docs/product/{RETAIL,EXPORT,INDUSTRY,AGRO}_SECTOR_ACCEPTANCE.md` (criterios por sector) y `docs/testing/V25_TEST_MATRIX.md` (matriz T01–T17).

## 1. Qué se exige y para qué release

V2.5 §26 T15 pide reproducir, para cada caso, los datos y fuentes, los factores elegidos y excluidos, el supuesto de disponibilidad, la prueba de API caída, el baseline con y sin factores, los contratos entre
cores, las métricas por horizonte, los costos y los controles de aislamiento. **Es un gate de release de Factor Intelligence / del sector correspondiente (P2/P3), no un requisito del primer piloto B2B P0/P1.**
T17 (conversión productiva) es gate del release vertical Industry / Agro / Export (P3): si no hay módulo productivo implementado se marca `P3_PENDIENTE` y no se declara cobertura.

## 2. Qué existe hoy y puede servir de base (sin sostener ningún caso)

| Bloque | Estado | Evidencia |
|---|---|---|
| Contrato de extensión sectorial (`SectorProfile`, `ExtensionProvider`, `register_profile`): un perfil real se agrega sin tocar el core | **IMPLEMENTADO / VERIFICADO** | `engine/tests/unit/test_platform_extensions.py::test_two_profiles_resolve_differently_with_the_same_interfaces`, `::test_every_provider_of_every_profile_honours_the_contract`; `engine/tests/unit/test_sector_contracts.py::test_the_sector_extension_contract_is_preserved` |
| Perfiles registrados | solo **dos SINTÉTICOS** de prueba (`synthetic_retail_a`, `synthetic_process_b`); ninguno es un rubro real | `test_sector_contracts.py::test_the_registry_of_sector_profiles_only_holds_synthetic_profiles_so_no_sector_case_can_be_claimed` |
| Perfiles de relevancia por empresa (`tenant_sector_profiles`) | **IMPLEMENTADO / VERIFICADO** en el backend; el motor aún no los lee | `SECTOR_PROFILES.md` §2 |
| Catálogo de 17 familias de factores, disponibilidad temporal, eventos manuales con procedencia | **IMPLEMENTADO / VERIFICADO** | `FACTOR_INTELLIGENCE.md` |
| Pipeline de factores en la demanda con competencia frente al modelo sin factores, ablation y paridad apagado | **IMPLEMENTADO / VERIFICADO** (datos ficticios y base de pruebas) | `FACTOR_PIPELINE.md` |
| Conversión de unidades declarada (caja ↔ kg ↔ lb) y «no comparable» sin conversión | **IMPLEMENTADO / VERIFICADO** para ofertas y capacidad | `backend/tests/Feature/DataCore/CanonicalDataTest.php::test_converter_resolves_only_declared_conversions`; `engine/tests/unit/test_supply_offers.py::test_inverse_conversion_and_missing_conversion`, `::test_no_conversion_or_no_fx_means_not_comparable_and_is_declared` |
| Rendimiento por receta, capacidad e insumos en el planificador genérico | **IMPLEMENTADO / VERIFICADO** | `engine/tests/unit/test_supply_planner.py::test_generic_production_respects_yield_time_inputs_and_capacity`, `::test_production_is_capped_by_resource_capacity`, `::test_production_is_limited_by_input_stock`; `backend/tests/Feature/Supply/SupplyConfigApiTest.php::test_recipes_validate_yield_and_lines_and_replace_lines_on_update` |
| Empresas ficticias | dos (`ferreteria_andina`, `distribuidora_costa`): **no son ninguno de los seis casos** | `test_sector_contracts.py::test_there_is_no_export_industry_or_agro_module_and_the_fictional_companies_are_not_a_t15_case` |
| Demo ficticia con factores de efecto sembrado | **IMPLEMENTADO / VERIFICADO** como fixture de la herramienta (`engine/predictily_engine/synthetic/factors.py`); **no representa ningún sector** | `engine/tests/unit/test_factor_ablation.py` |

## 3. T15 · los seis casos de V2.1

Estado de cada caso (todas las filas: **`P3_PENDIENTE`**; datos reales **BLOQUEADO**). La columna «Qué falta» es lo mínimo para empezar; el detalle por caso está en §4.

| Caso | Requisitos | Estado | Qué falta |
|---|---|---|---|
| T15.01 · Fabricante de agua embotellada | V25-SCT-026, V25-SCT-001 | `P3_PENDIENTE` · datos **BLOQUEADO** | fixture sintético etiquetado y piloto; clima y búsqueda sin fuente conectada |
| T15.02 · Fabricante de alimentos sin gluten (certificación verificada frente a no corroborada) | V25-SCT-027, V25-SCT-001 | `P3_PENDIENTE` · datos **BLOQUEADO** | fixture y piloto; flujo de certificaciones con corroboración; preferencias del consumidor sin fuente |
| T15.03 · Fabricante de insumos agrícolas | V25-SCT-028, V25-SCT-003 | `P3_PENDIENTE` · datos **BLOQUEADO** | fixture y piloto; sanidad vegetal solo como eventos manuales; ventas a distribuidores como cartera |
| T15.04 · Retail multisucursal (factores del perfil Retail, ablation con y sin factor, API caída) | V25-SCT-029, V25-SCT-002 | `P3_PENDIENTE` · datos **BLOQUEADO** | perfil Retail registrado (no sintético); competencia, clima y tendencias sin fuente; solo existe la base ficticia multisucursal |
| T15.05 · Productor agrícola (estimación de cosecha separada de la demanda) | V25-SCT-030, V25-SCT-003 | `P3_PENDIENTE` · datos **BLOQUEADO** | módulo de oferta agrícola (siembra, rendimiento, cosecha) separado de la demanda comercial; sin fuentes |
| T15.06 · Exportadora de camarón (pedido → conversión por presentación y rendimiento → materia prima) | V25-SCT-031, V25-SCT-004 | `P3_PENDIENTE` · datos **BLOQUEADO** | módulo Export (comprador, país, presentación, embarque); estadísticas y precios internacionales sin fuente |
| (no es un caso de V2.5 §26) Base reutilizable: dos perfiles sintéticos que resuelven distinto con las mismas interfaces | V25-SCT-007, V25-FAC-016 | **IMPLEMENTADO / VERIFICADO** como base · no sostiene ningún caso real | ver §2 |

## 4. T15 · detalle por caso (lo que cada uno deberá demostrar)

Para **todos** los casos se exige, antes de aceptarlos: (a) taxonomía y perfil de relevancia de la empresa piloto; (b) factores aplicables y no aplicables **elegidos con el propietario**, con su fuente, licencia y
retención; (c) el supuesto de disponibilidad de cada factor (clase y rezago de publicación) y su prueba as-of; (d) la prueba de **API caída** (la fuente se cae y el modelo queda con el respaldo sin el factor,
`STALE`/`unavailable`); (e) el **baseline con y sin factores** con `engine/scripts/factor_ablation.py` sobre una **copia de pruebas** de los datos del piloto, con el período final intacto y la decisión de la regla de
`FACTOR_PIPELINE.md` §4 (sin evidencia no se promueve); (f) métricas por horizonte y segmento con los denominadores de W3-A; (g) el costo real de cómputo y de APIs; (h) los controles de aislamiento entre empresas
y la reconciliación sin doble conteo con pedidos confirmados. Lo que cada caso añade:

| Caso | Datos y fuentes que hay que obtener (hoy: ninguna conectada) | Familias candidatas del catálogo (a confirmar con el propietario) | Particularidad que debe probarse |
|---|---|---|---|
| T15.01 Agua embotellada | ventas por SKU y sucursal; serie de temperatura observada; interés de búsqueda; calendario | `weather` (`weather.observed`; `weather.forecast` solo con historial de emisiones), `search_trends`, `calendar_events`, `prices_discounts` | el pronóstico del clima emitido mañana pero registrado hoy no se ve hoy (T11) |
| T15.02 Alimentos sin gluten | ventas; certificaciones del producto con su corroboración; alertas de inocuidad | `consumer_preferences`, `food_safety_alerts` (eventos manuales, alerta informativa), `calendar_events` | certificación **verificada** frente a **no corroborada** (una propuesta no verificada nunca se usa como verdad); la alerta oficial se muestra aunque no mejore el error |
| T15.03 Insumos agrícolas | ventas a distribuidores; clima; sanidad vegetal; ciclo agrícola | `weather`, `animal_plant_health` (manual), `orders_contracts`, `calendar_events` (categoría `agricultural_season`) | demanda de distribuidores separada de la oferta agrícola; demanda autorizada de fabricantes solo con *grant* vigente |
| T15.04 Retail multisucursal | ventas, promociones e inventario por sucursal; precios de competencia; clima; tendencias | `prices_discounts`, `capacity_inventory`, `competition`, `weather`, `search_trends`, `calendar_events` | baseline con y sin factores por sucursal y por tramo de volumen; API de competencia caída |
| T15.05 Productor agrícola | siembra, rendimiento, cosecha; clima; plagas; demanda autorizada | `weather`, `animal_plant_health`, `production_estimates` (pronóstico de tercero: exige emisiones archivadas), `shared_projections` (contrato desactivado) | la estimación de cosecha **no se mezcla** con la demanda comercial |
| T15.06 Exportadora de camarón | pedidos de exportación; presentaciones, tallas y rendimientos; estadísticas y regulaciones; precios internacionales | `orders_contracts`, `export_statistics`, `trade_regulatory` (eventos manuales), `calendar_events` | pedido → conversión por presentación y rendimiento → materia prima; sin factor de conversión, «no comparable» |

*(Las familias de la tabla son las del catálogo de `FACTOR_INTELLIGENCE.md` §3 que V2.1 §24.11–§24.12 menciona para cada caso; ninguna tiene fuente conectada salvo las nativas: ventas, precios, promociones,
compromisos, inventario y calendario.)*

## 5. T17 · conversión productiva de V2

| Caso | Requisitos | Estado | Evidencia o falta |
|---|---|---|---|
| T17.01 · Conversión de unidades por presentación (1 caja = N kg; 1 lb = 0,45359237 kg; sin factor → «no comparable») | V25-SCT-032 | parcial · **IMPLEMENTADO / VERIFICADO** para ofertas y capacidad | pruebas de §2; falta aplicarlo a presentaciones de exportación y a recetas |
| T17.02 · Mermas y rendimientos por proceso producen requerimientos de materia prima | V25-SCT-033 | parcial · **IMPLEMENTADO / VERIFICADO** el rendimiento por receta | pruebas de §2; falta el requerimiento de materia prima validado de punta a punta |
| T17.03 · No mezclar peso terminado y peso bruto (100 kg terminados con rendimiento 0,6 → 166,7 kg de materia prima bruta) | V25-SCT-033, V25-SCT-015 | `P3_PENDIENTE` | sin módulo productivo validado de punta a punta |
| T17.04 · Flujo mínimo de Export: demanda → pedidos confirmados → inventario → materia prima | V25-APQ-011, V25-SCT-015 | `P3_PENDIENTE` | no existe el módulo Export |
| T17.05 · Mismo flujo para Retail, Industry y Agro en sus fixtures | V25-SCT-034, V25-SCT-001, V25-SCT-003 | `P3_PENDIENTE` | sin fixtures sectoriales ni módulo productivo |

## 6. Cómo se cierra un caso (procedimiento, sin atajos)

1. El propietario autoriza un piloto del sector y entrega datos con permiso (BLOQUEADO hasta entonces); el cliente firma el tratamiento de datos.
2. Se registra el perfil real del sector con `register_profile` (no sintético) y se cargan sus factores y fuentes con licencia y retención declaradas.
3. Se escribe la prueba del caso en `engine/tests/**` (o `backend/tests/**`) y se **quita la marca `P3_PENDIENTE`** de `test_sector_contracts.py`; la prueba de coherencia obliga a actualizar este documento.
4. La ablation (`FACTOR_PIPELINE.md` §7) se corre sobre una copia de pruebas de los datos del piloto; un factor solo se activa si supera T11/T12 para ese factor.
5. Se actualiza `V25_TEST_MATRIX.md` y el informe de gates con la evidencia real. **No se declara cobertura por contar con fixtures sintéticas.**

## 7. Bloqueos que dependen del propietario

| Bloqueo | Quién | Estado |
|---|---|---|
| Datos reales autorizados de cada sector (V25-SCT-006) | propietario y cliente del sector | **BLOQUEADO** |
| Fuentes externas con licencia (clima, tendencias, competencia, estadísticas de exportación) | propietario | **BLOQUEADO / P2** (`EXTERNAL_SOURCES.md`) |
| Elección de factores aplicables y no aplicables y aprobación de los umbrales de la regla de competencia | propietario con el cliente piloto | pendiente (valores iniciales «a aprobar en el piloto») |
| Módulos Export, Industry y Agro operativos | desarrollo posterior a la autorización | `P3_PENDIENTE` |
