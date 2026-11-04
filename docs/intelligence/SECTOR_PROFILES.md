# Perfiles sectoriales y casos sectoriales de V2.1 (V2.5 §24.11 y §24.12)

> **Alcance y estado.** Qué existe hoy para los perfiles de relevancia sectorial por empresa y qué es solo diseño. Decisión: `docs/architecture/adr/V25-ADRS.md` (ADR-V25-16 y ADR-V25-25). Documentos hermanos:
> `FACTOR_INTELLIGENCE.md`, `PRODUCT_TAXONOMY.md`, `EXTERNAL_SOURCES.md`.
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO** o **NO APLICA**.
>
> **Aviso de honestidad.** Los perfiles de V2.1 (Manufacturing/Industry, Retail, Agro, Export) y los **seis casos sectoriales de §24.12 son P2/P3 y NO están cubiertos ni aprobados**: están **`P3_PENDIENTE`**
> y sus datos reales **BLOQUEADO** hasta un piloto. Hoy solo existe la infraestructura para asociar a una empresa uno o varios perfiles con prioridad y alcance, el contrato de extensión del motor (que se
> **conserva**) y, desde W6-E, el pipeline de factores con su ablation (`FACTOR_PIPELINE.md`). Ninguna prueba sectorial se declara aprobada por tener datos sintéticos.
> Criterios de aceptación: `docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md` (T15/T17) y, por sector, `docs/product/{RETAIL,EXPORT,INDUSTRY,AGRO}_SECTOR_ACCEPTANCE.md`.

## 1. Tres conceptos que no deben confundirse

| Concepto | Dónde vive | Estado |
|---|---|---|
| **Rubro de la empresa** (`tenants.sector`: ferretería, distribuidora, repuestos automotrices, repuestos agrícolas, retail multisucursal, importadora, consumo recurrente, otro) | `tenants` (etapa 1) | existe; no define factores por sí mismo |
| **Perfil sectorial del motor** (`SectorProfile` en `engine/predictily_engine/platform/extensions.py`: configuración + proveedores de calendario/variables/restricciones) | registro del motor | **solo hay dos perfiles registrados y ambos son SINTÉTICOS de prueba** (`synthetic_retail_a`, `synthetic_process_b`); ninguno representa un rubro real ni un entregable sectorial |
| **Perfil de relevancia de la empresa** (`tenant_sector_profiles`: qué perfil(es) aplican a la empresa, con prioridad y alcance) | backend (este paquete) | **IMPLEMENTADO / VERIFICADO** |

## 2. Perfiles de relevancia de la empresa (`tenant_sector_profiles`)

Una empresa puede tener **varios perfiles activos a la vez** (un fabricante de bebidas, uno de productos sin gluten y uno de insumos agrícolas usan factores distintos; V2.1 §24.2).

| Campo | Significado |
|---|---|
| `profile_key` | código del perfil registrado en el motor (`^[a-z][a-z0-9_]*$`) |
| `priority` | entero 0–65535; **menor = mayor prioridad** (por defecto 100) |
| `scope` | `tenant` (toda la empresa), `branch`, `category` o `product` |
| `scope_ref` | id numérico del elemento propio (sucursal, categoría o producto); vacío en el alcance `tenant` |
| `is_active` | permite apagar un perfil sin borrarlo |

* **Resolución** (`SectorProfileService::applicable`): el alcance **más específico** gana (producto › categoría › sucursal › empresa) y, a igual alcance, la prioridad numérica menor. Los perfiles inactivos no
  aplican. *IMPLEMENTADO / VERIFICADO:* `TaxonomyTest::test_multiple_sector_profiles_with_priority_and_scope`.
* **API** (`/api/v1/intelligence/sector-profiles`): lectura `data.view`; crear, cambiar prioridad/estado y borrar, `settings.manage` (administrador o propietario). Un duplicado (mismo perfil y alcance), un
  `scope_ref` que no existe en la empresa y un alcance `tenant` con `scope_ref` se rechazan. Aislamiento entre empresas: `TaxonomyTest::test_taxonomy_is_isolated_between_companies`.
* **Limitación conocida:** `profile_key` se valida solo por formato. Contrastarlo con el registro del motor es **DISEÑADO / NO IMPLEMENTADO**: una clave que el motor no conoce se guarda, y el motor la ignorará.
* **El motor aún no lee esta tabla**: asociar un perfil a una empresa **no cambia ninguna predicción**. *DISEÑADO / NO IMPLEMENTADO:* que el motor resuelva los perfiles de `tenant_sector_profiles` al calcular
  la configuración efectiva (hoy usa `tenants.sector`). Lo que el motor **sí** lee desde W6-E de `tenants.settings` es la **activación de factores** (`factor_activation.<clave>.active` junto con
  `cores.factors.enabled`), ver `FACTOR_PIPELINE.md` §2: es independiente de los perfiles sectoriales.

## 3. Perfiles de V2.1 §24.11

| Perfil | Qué debe cubrir (V2.1) | Estado |
|---|---|---|
| **Manufacturing / Industry** | ventas a distribuidores, demanda retail autorizada, capacidad, materias primas, tendencias y riesgos por producto | **`P3_PENDIENTE`** (DISEÑADO / NO IMPLEMENTADO como perfil del motor). Existen los insumos nativos (ventas, capacidad `location_capacities`, recetas con rendimiento, compromisos) y el contrato de demanda retail autorizada (desactivado). Contrato de aceptación: `INDUSTRY_SECTOR_ACCEPTANCE.md` |
| **Retail** | venta final, promociones, inventario, competencia, clima, tendencias y perfiles de compradores si existen | **`P3_PENDIENTE`** (DISEÑADO / NO IMPLEMENTADO como perfil; solo existe `synthetic_retail_a`, sintético). Ventas, promociones e inventario son nativos; competencia, clima y tendencias **no tienen fuente** (`EXTERNAL_SOURCES.md`). Contrato de aceptación: `RETAIL_SECTOR_ACCEPTANCE.md` |
| **Agro** | oferta agrícola (siembra, rendimiento, cosecha, plagas, clima) diferenciada de la demanda comercial y de la demanda autorizada de fabricantes/exportadores | **`P3_PENDIENTE`** (DISEÑADO / NO IMPLEMENTADO). Sin fuentes de siembra/rendimiento; la sanidad vegetal solo admite eventos manuales; lo único específico es la categoría de calendario `agricultural_season`. Contrato de aceptación: `AGRO_SECTOR_ACCEPTANCE.md` |
| **Export** | compradores, pedidos, destinos, países, presentaciones, estadísticas, regulaciones, oferta de proveedores, precios internacionales y restricciones | **`P3_PENDIENTE`** (DISEÑADO / NO IMPLEMENTADO; no existe módulo Export). Pedidos (compromisos) son nativos; estadísticas de exportación y precios internacionales **sin fuente**; regulaciones solo como eventos manuales. Contrato de aceptación: `EXPORT_SECTOR_ACCEPTANCE.md` |

## 4. Los seis casos de V2.1 §24.12 (T15)

Para cada caso V2.1 exige: taxonomía/perfil, factores aplicables y no aplicables, disponibilidad temporal, procedencia de eventos, APIs caídas, costos, seguridad, mejora real frente al baseline y reconciliación
sin dobles. **Ninguno está cubierto** (T15.01–T15.06: `P3_PENDIENTE`; hay una prueba omitida por caso en `engine/tests/unit/test_sector_contracts.py`, que aparece en cada ejecución como pendiente). Son gate de release de Factor Intelligence por sector (P2/P3), no un requisito del primer piloto B2B P0/P1 (V2.5 §26, T15).

| # | Caso | Taxonomía y perfil | Factores y fuentes | Disponibilidad temporal | APIs caídas y costos | Mejora vs baseline | Estado |
|---|---|---|---|---|---|---|---|
| 1 | Fabricante de agua embotellada | modelo disponible; sin datos reales | clima, búsqueda y calendario: clima/búsqueda **sin fuente** | validador del motor (W2-D) sin fuente real | no aplica: no hay API | no medida | **`P3_PENDIENTE` — sin cobertura; datos BLOQUEADO** |
| 2 | Fabricante de alimentos sin gluten | idem | preferencias del consumidor, inocuidad (manual): **sin fuente** | idem | no aplica | no medida | **`P3_PENDIENTE` — sin cobertura; datos BLOQUEADO** |
| 3 | Fabricante de insumos agrícolas | idem | clima, sanidad vegetal (manual), ventas a distribuidores | idem | no aplica | no medida | **`P3_PENDIENTE` — sin cobertura; datos BLOQUEADO** |
| 4 | Retail multisucursal | idem | promociones, inventario, competencia, clima: competencia/clima **sin fuente** | idem | no aplica | no medida | **`P3_PENDIENTE` — sin cobertura; datos BLOQUEADO** |
| 5 | Productor agrícola | idem | clima, siembra/rendimiento, plagas (manual), demanda autorizada | idem | no aplica | no medida | **`P3_PENDIENTE` — sin cobertura; datos BLOQUEADO** |
| 6 | Exportadora de camarón | idem | estadísticas de exportación, regulaciones (manual), precios internacionales: **sin fuente** | idem | no aplica | no medida | **`P3_PENDIENTE` — sin cobertura; datos BLOQUEADO** |

Qué sí existe y puede servir de base cuando se aborden: la taxonomía y los perfiles de relevancia por empresa (esta carpeta), el catálogo de factores con su disponibilidad temporal declarada (`FACTOR_INTELLIGENCE.md`), el
registro de eventos con procedencia y verificación humana, el contrato de aislamiento de concesiones (desactivado) y, desde W6-E, el **pipeline de factores conectado a la demanda** y la **herramienta de ablation** con y sin
cada factor (`FACTOR_PIPELINE.md`; **IMPLEMENTADO / VERIFICADO** con datos ficticios y una base de pruebas; sin ninguna fuente externa conectada). Qué falta: datos y permisos reales de cada sector, las fuentes y su historial de
emisiones y los reportes de mejora o ausencia de mejora sobre datos reales (que se reproducirán con la herramienta, sin inventar porcentajes). No se declara cubierto ningún caso por contar con fixtures sintéticas.

## 5. Conversión productiva de V2 (T17)

| Caso | Estado |
|---|---|
| T17.01 conversión de unidades por presentación | parcial: **IMPLEMENTADO / VERIFICADO** para ofertas y capacidad (`CanonicalDataTest::test_converter_resolves_only_declared_conversions`, `test_supply_offers.py`); falta aplicarlo a presentaciones de exportación y a recetas |
| T17.02 mermas y rendimientos por proceso → materia prima | parcial: **IMPLEMENTADO / VERIFICADO** el rendimiento por receta y el planificador genérico (`test_supply_planner.py::test_generic_production_respects_yield_time_inputs_and_capacity`); falta el requerimiento de materia prima validado de punta a punta |
| T17.03 no mezclar peso terminado y peso bruto | **`P3_PENDIENTE`** |
| T17.04 flujo mínimo de Export (demanda → pedidos confirmados → inventario → materia prima) | **`P3_PENDIENTE`** (no existe el módulo Export) |
| T17.05 mismo flujo para Retail, Industry y Agro en sus fixtures | **`P3_PENDIENTE`** |

Si no hay módulo productivo implementado se marca `P3_PENDIENTE` y **no se declara cobertura** (V2.5 §26 T17). Detalle y criterios: `docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md` §5.

## 6. Contratos sectoriales preservados (V2.5 §25.6; ADR-V25-16) y su prueba

V2.5 manda **preservar** los contratos de Retail, Export, Industry y Agro y no borrar código funcional aunque sea P3. Lo que se conserva y se prueba (`engine/tests/unit/test_sector_contracts.py`):

- **Contrato de extensión:** `SectorProfile`, `ExtensionProvider` y `register_profile` (un perfil real se agrega sin tocar el core): *IMPLEMENTADO / VERIFICADO*, `test_the_sector_extension_contract_is_preserved` y
  `test_platform_extensions.py::test_two_profiles_resolve_differently_with_the_same_interfaces`.
- **Sin cobertura falsa:** el registro solo trae los dos perfiles SINTÉTICOS y no existe ningún módulo Export, Industry ni Agro; si aparece uno, la prueba falla y obliga a actualizar el estado:
  `test_the_registry_of_sector_profiles_only_holds_synthetic_profiles_so_no_sector_case_can_be_claimed`, `test_there_is_no_export_industry_or_agro_module_and_the_fictional_companies_are_not_a_t15_case`.
- **Casos pendientes visibles:** nueve pruebas omitidas con el motivo `P3_PENDIENTE` (T15.01–T15.06, T17.03–T17.05) y una prueba que verifica que `SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md` los liste con su marca.
- **Backend:** las tablas y rutas de taxonomía, perfiles de relevancia y concesiones (desactivadas) se conservan tal como las entregó W2-F.
