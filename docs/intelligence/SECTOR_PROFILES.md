# Perfiles sectoriales y casos sectoriales de V2.1 (V2.5 §24.11 y §24.12)

> **Alcance y estado.** Qué existe hoy para los perfiles de relevancia sectorial por empresa y qué es solo diseño. Decisión: `docs/architecture/adr/V25-ADRS.md` (ADR-V25-16 y ADR-V25-25). Documentos hermanos:
> `FACTOR_INTELLIGENCE.md`, `PRODUCT_TAXONOMY.md`, `EXTERNAL_SOURCES.md`.
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO** o **NO APLICA**.
>
> **Aviso de honestidad.** Los perfiles de V2.1 (Manufacturing, Retail, Agro, Export) y los **seis casos sectoriales de §24.12 son P2/P3 y NO están cubiertos ni aprobados**. Hoy solo existe la
> infraestructura para asociar a una empresa uno o varios perfiles con prioridad y alcance. Ninguna prueba sectorial se declara aprobada por tener datos sintéticos.

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
  la configuración efectiva (hoy usa `tenants.sector`).

## 3. Perfiles de V2.1 §24.11

| Perfil | Qué debe cubrir (V2.1) | Estado |
|---|---|---|
| **Manufacturing** | ventas a distribuidores, demanda retail autorizada, capacidad, materias primas, tendencias y riesgos por producto | **DISEÑADO / NO IMPLEMENTADO** como perfil del motor. Existen los insumos nativos (ventas, capacidad `location_capacities`, recetas, compromisos) y el contrato de demanda retail autorizada (§4, desactivado) |
| **Retail** | venta final, promociones, inventario, competencia, clima, tendencias y perfiles de compradores si existen | **DISEÑADO / NO IMPLEMENTADO** como perfil. Ventas, promociones e inventario son nativos; competencia, clima y tendencias **no tienen fuente** (`EXTERNAL_SOURCES.md`) |
| **Agro** | oferta agrícola (siembra, rendimiento, cosecha, plagas, clima) diferenciada de la demanda comercial y de la demanda autorizada de fabricantes/exportadores | **DISEÑADO / NO IMPLEMENTADO**. Sin fuentes de siembra/rendimiento; la sanidad vegetal solo admite eventos manuales |
| **Export** | compradores, pedidos, destinos, países, presentaciones, estadísticas, regulaciones, oferta de proveedores, precios internacionales y restricciones | **DISEÑADO / NO IMPLEMENTADO**. Pedidos (compromisos) son nativos; estadísticas de exportación y precios internacionales **sin fuente**; regulaciones solo como eventos manuales |

## 4. Los seis casos de V2.1 §24.12 (T15)

Para cada caso V2.1 exige: taxonomía/perfil, factores aplicables y no aplicables, disponibilidad temporal, procedencia de eventos, APIs caídas, costos, seguridad, mejora real frente al baseline y reconciliación
sin dobles. **Ninguno está cubierto.** Son gate de release de Factor Intelligence por sector (P2/P3), no un requisito del primer piloto B2B P0/P1 (V2.5 §26, T15).

| # | Caso | Taxonomía y perfil | Factores y fuentes | Disponibilidad temporal | APIs caídas y costos | Mejora vs baseline | Estado |
|---|---|---|---|---|---|---|---|
| 1 | Fabricante de agua embotellada | modelo disponible; sin datos reales | clima, búsqueda y calendario: clima/búsqueda **sin fuente** | validador del motor (W2-D) sin fuente real | no aplica: no hay API | no medida | **P2/P3 PENDIENTE — no aprobado** |
| 2 | Fabricante de alimentos sin gluten | idem | preferencias del consumidor, inocuidad (manual): **sin fuente** | idem | no aplica | no medida | **P2/P3 PENDIENTE — no aprobado** |
| 3 | Fabricante de insumos agrícolas | idem | clima, sanidad vegetal (manual), ventas a distribuidores | idem | no aplica | no medida | **P2/P3 PENDIENTE — no aprobado** |
| 4 | Retail multisucursal | idem | promociones, inventario, competencia, clima: competencia/clima **sin fuente** | idem | no aplica | no medida | **P2/P3 PENDIENTE — no aprobado** |
| 5 | Productor agrícola | idem | clima, siembra/rendimiento, plagas (manual), demanda autorizada | idem | no aplica | no medida | **P2/P3 PENDIENTE — no aprobado** |
| 6 | Exportadora de camarón | idem | estadísticas de exportación, regulaciones (manual), precios internacionales: **sin fuente** | idem | no aplica | no medida | **P2/P3 PENDIENTE — no aprobado** |

Qué sí existe y puede servir de base cuando se aborden: la taxonomía y los perfiles de relevancia por empresa (esta carpeta), el catálogo de factores con su disponibilidad temporal declarada (`FACTOR_INTELLIGENCE.md`), el
registro de eventos con procedencia y verificación humana y el contrato de aislamiento de concesiones (desactivado). Qué falta: datos y permisos reales de cada sector, las fuentes y su historial de emisiones, la ablation
con holdout intacto y los reportes de mejora o ausencia de mejora (que se reproducirán sin inventar porcentajes). No se declara cubierto ningún caso por contar con fixtures sintéticas.
