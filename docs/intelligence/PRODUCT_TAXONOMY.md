# Taxonomía de productos y contexto (V2.5 §24.2 · T13)

> **Alcance y estado.** Modelo, API y reglas de la **taxonomía básica** de la empresa (jerarquía versionada, asignación producto → nodo con procedencia y perfiles de relevancia).
> Decisión: `docs/architecture/adr/V25-ADRS.md` (ADR-V25-16 y ADR-V25-25). Contrato de datos: migración
> `backend/database/migrations/2026_03_04_000100_create_factor_intelligence_and_taxonomy_contracts.php`. Documentos hermanos: `FACTOR_INTELLIGENCE.md`, `SECTOR_PROFILES.md`, `EXTERNAL_SOURCES.md`.
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO** o **NO APLICA**.
> Pruebas: `backend/tests/Feature/Intelligence/TaxonomyTest.php` (`cd backend && DB_DATABASE=<bd_de_pruebas> php artisan test --filter=TaxonomyTest`).
>
> **Regla de oro (V2.1 §24.2):** el identificador canónico del producto (`products.id`, `sku`) **nunca se sustituye** por una taxonomía. El nodo agrega contexto; no reemplaza ni reescribe el SKU.

## 1. Modelo

```
Sector ─► Industria ─► Categoría ─► Subcategoría        (taxonomy_nodes: jerarquía versionada)
                                         ▲
Producto (SKU canónico) ──── product_taxonomy_assignments ────┘   (procedencia manual | llm_proposed | verified)
Categoría del ERP (product_categories) ◄──── taxonomy_nodes.erp_category_id   (vínculo opcional)
Empresa ──── tenant_sector_profiles (perfiles de relevancia con prioridad y alcance)
```

La jerarquía V2.1 llega hasta *Producto → SKU*; en el modelo, **Producto y SKU son `products`** (ya existentes, con su `sku` canónico), y los niveles de `taxonomy_nodes` son los cuatro de arriba
(`sector`, `industry`, `category`, `subcategory`).

| Tabla | Modelo | Aislamiento |
|---|---|---|
| `taxonomy_nodes` | `TaxonomyNode` | `tenant_id` NULL = taxonomía **pública** (lectura para toda empresa, escritura solo de la plataforma); con valor = de la empresa |
| `product_taxonomy_assignments` | `ProductTaxonomyAssignment` | siempre de la empresa (`BelongsToTenant`); FK compuesta `(tenant_id, product_id)` en la BD |
| `tenant_sector_profiles` | `TenantSectorProfile` | siempre de la empresa |

No se siembra ninguna taxonomía pública: no se inventa el catálogo de rubros. El modelo la admite (lectura) y no existe todavía contenido.
*DISEÑADO / NO IMPLEMENTADO:* un catálogo público inicial por rubro (P2) y la API de plataforma para escribirlo (hoy solo por el modelo/seeders de plataforma).

## 2. Nodos y jerarquía válida

* El padre de un nodo es **siempre del nivel inmediatamente superior** y un `sector` no lleva padre. La regla vive en el modelo (`TaxonomyNode`), por lo que vale por cualquier vía, no solo por la API.
  Una categoría directamente bajo un sector, una subcategoría bajo otra o un padre inexistente se rechazan (`taxonomy_invalid_hierarchy`, `taxonomy_parent_required`, `taxonomy_parent_not_found`).
* El `code` es único por empresa y versión; el mismo código puede existir en dos empresas.
* Un nodo de la empresa puede colgar de un nodo **público** (referencia de solo lectura). Nunca de un nodo de otra empresa (ni por la API ni saltándose la API).
* `erp_category_id` vincula (opcional) con una categoría de `product_categories` **de la misma empresa**.
* `attributes` (JSON) guarda presentaciones, usos, regiones y mercados declarados por la empresa. **Las certificaciones no se corroboran aquí**: un atributo es una afirmación de la empresa, no un hecho verificado
  (DISEÑADO: certificaciones verificadas contra su fuente, P2).

*IMPLEMENTADO / VERIFICADO:* `test_a_valid_four_level_hierarchy_can_be_built_with_an_erp_category_link`, `test_the_hierarchy_levels_are_enforced`, `test_node_edits_are_validated_and_audited`,
`test_nodes_with_children_or_assigned_products_cannot_be_deleted`.

### 2.1 Versionado

`POST /api/v1/intelligence/taxonomy/nodes/{node}/versions` crea la versión *N+1* del mismo código (con `valid_from`); la anterior queda cerrada el **día previo** (`valid_to`) y no se reescribe. Solo se
versiona la más reciente (`taxonomy_not_latest_version`). Las asignaciones siguen apuntando a la versión que referencian, de modo que el historial no cambia; los hijos de una versión antigua tampoco se mueven
solos (se re-asignan explícitamente). *IMPLEMENTADO / VERIFICADO:* `test_a_node_can_be_versioned_without_rewriting_history`.

## 3. Asignación producto → nodo y procedencia

| Procedencia | Quién la crea | ¿Es verdad usable? |
|---|---|---|
| `manual` | un usuario (analista, administrador o propietario) por la API | **Sí**, desde que se crea |
| `llm_proposed` | una propuesta de clasificación (p. ej. de un LLM) por `TaxonomyService::proposeAssignment` | **No**: candidata con procedencia, pendiente de revisión |
| `verified` | un usuario que confirma una propuesta (`POST …/assignments/{id}/verify`) | **Sí**, con quién y cuándo (`verified_by`, `verified_at`) |

* **Una propuesta del LLM nunca se usa como verdad hasta que un usuario la verifica.** `TaxonomyService::resolveForProduct()` solo considera `manual` y `verified`; el listado muestra `pending_review = true`
  y `usable = false`. Descartar una propuesta incorrecta la elimina (queda en la auditoría). *IMPLEMENTADO / VERIFICADO:*
  `test_an_llm_proposal_is_pending_review_and_never_used_as_truth_until_a_user_verifies_it`, `test_a_wrong_llm_proposal_can_be_rejected_and_leaves_no_truth_behind`.
* Este paquete **no llama a ningún LLM**: la API pública solo crea asignaciones `manual`; la entrada `llm_proposed` está disponible para el futuro clasificador (P2) y para pruebas. *DISEÑADO / NO IMPLEMENTADO:* el
  clasificador por LLM (vía `PredictilyIntelligenceGateway`, ADR-V25-17), su esquema de salida validado y su confianza calibrada. `confidence` es heurística o declarada, **no una probabilidad calibrada**.
* **Historial por vigencia:** `valid_from`/`valid_to`. Un producto tiene como máximo **una asignación primaria usable por fecha**. Una nueva primaria posterior cierra la abierta anterior el día previo; un solapamiento
  que no se puede cerrar se rechaza (`overlapping_primary_assignment`). Las asignaciones no primarias coexisten. Una asignación vigente no se borra: se cierra con `valid_to`. *IMPLEMENTADO / VERIFICADO:*
  `test_assignment_history_is_kept_by_validity_and_overlaps_are_rejected`.
* **El SKU canónico no cambia:** `test_a_manual_assignment_is_usable_and_the_canonical_sku_is_untouched`.

### 3.1 Mapeo de SKUs (alias y cambios de código)

La taxonomía se asigna al **`product_id` canónico**. Los alias de código (SKU heredado de una fuente) y los cambios de código son responsabilidad de los alias de producto del paquete W2-B
(`product_aliases`, ver `docs/data/LINEAGE_AND_RECONCILIATION.md`): un alias resuelve al mismo producto y por tanto al mismo nodo, sin crear un producto nuevo. Aquí no hay tabla de alias ni lógica de
equivalencia.

## 4. API

Prefijo `/api/v1/intelligence/taxonomy`; contratos en `docs/openapi/v2.yaml` (etiqueta «Factor Intelligence»). Lectura: `data.view` (todos los roles). Escritura: `catalog.manage` (analista, administrador, propietario).

| Operación | Ruta |
|---|---|
| Listar nodos (filtros `level`, `parent_id`, `code`, `scope`, `current_only`) | `GET /nodes` |
| Crear / ver / editar / borrar un nodo propio | `POST /nodes`, `GET|PATCH|DELETE /nodes/{node}` |
| Nueva versión de un nodo | `POST /nodes/{node}/versions` |
| Listar asignaciones (filtros `product_id`, `taxonomy_node_id`, `provenance`, `pending_review`, `usable`, `as_of`) | `GET /assignments` |
| Asignar manualmente | `POST /assignments` |
| Cerrar o ajustar la vigencia | `PATCH /assignments/{assignment}` |
| Verificar una propuesta | `POST /assignments/{assignment}/verify` |
| Descartar una propuesta pendiente | `DELETE /assignments/{assignment}` |

Los nodos públicos (`scope = public`) se leen, pero modificarlos, versionarlos o borrarlos responde 403 `public_catalog_read_only`:
`test_public_taxonomy_nodes_are_readable_by_every_company_but_never_written`. Un visor lee y no escribe: `test_viewers_read_but_cannot_write_the_taxonomy`.

## 5. Aislamiento entre empresas

*IMPLEMENTADO / VERIFICADO:* `test_taxonomy_is_isolated_between_companies` (otra empresa no ve, modifica, versiona, verifica ni borra nodos, asignaciones o perfiles; no puede colgar nodos de un padre ajeno, asignar
productos ajenos ni usar nodos o categorías del ERP de otra empresa) y `test_models_block_cross_company_references_even_if_the_api_is_bypassed` (el modelo rechaza referencias cruzadas aunque se omita la API; la
FK compuesta de la BD rechaza asignar el producto de otra empresa). Las FK `taxonomy_node_id` y `parent_id` del contrato no son compuestas; por eso el modelo valida que el nodo sea visible para la empresa.

## 6. Cobertura de T13 (V2.5 §26)

| Verificación de T13 | Estado |
|---|---|
| Jerarquía `sector → industria → categoría → subcategoría` válida (nivel del padre) | **IMPLEMENTADO / VERIFICADO** |
| `→ producto → SKU` | **IMPLEMENTADO / VERIFICADO** por asignación al producto canónico (el SKU no cambia) |
| Perfiles múltiples | **IMPLEMENTADO / VERIFICADO** (`SECTOR_PROFILES.md`) |
| Clasificación equivocada propuesta por el LLM (revisión) | **IMPLEMENTADO / VERIFICADO** con propuestas generadas por el servicio; el LLM real: **DISEÑADO** |
| Aislamiento entre empresas | **IMPLEMENTADO / VERIFICADO** |
| Mapping de SKU heredado y cambios de código | **NO APLICA aquí**: lo entrega W2-B (`product_aliases`); la taxonomía cuelga del `product_id` |
| Cambios de la taxonomía en el tiempo (nodos) | **IMPLEMENTADO / VERIFICADO** (versiones y vigencias) |
| Productos descontinuados | Parcial: una asignación se cierra con `valid_to`; el estado del producto vive en `products` |
| Unidades/presentaciones y precios equivalentes entre productos | **DISEÑADO / NO IMPLEMENTADO** (P2; `attributes` guarda presentaciones declaradas, sin lógica de equivalencia) |
| Disponibilidad regional | **DISEÑADO / NO IMPLEMENTADO** (P2) |
| Reglas de aplicabilidad de factores por empresa | **DISEÑADO**: existe `factor_applicability_rules` y su modelo, sin API ni motor |
| Certificaciones no corroboradas | **DISEÑADO**: no se verifican certificaciones; los atributos son declaraciones de la empresa |
