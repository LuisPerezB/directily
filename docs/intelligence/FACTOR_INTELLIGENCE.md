# Factor Intelligence dentro de Data Core (V2.5 §24 · P0)

> **Alcance y estado.** Este documento describe el **backend de Factor Intelligence P0**: el catálogo de factores, su activación por empresa, los eventos externos
> manuales con procedencia, el contrato de pronósticos compartidos entre empresas (desactivado) y el aislamiento de todo ello. Implementa
> `docs/architecture/adr/V25-ADRS.md` (ADR-V25-16 y ADR-V25-25) sobre el contrato de la migración
> `backend/database/migrations/2026_03_04_000100_create_factor_intelligence_and_taxonomy_contracts.php`.
>
> **P0 = contratos, disponibilidad temporal y aislamiento, con el pipeline de factores desactivable.** No hay ninguna integración externa, ningún conector de datos
> de terceros y ningún LLM en este paquete. Las fuentes externas reales, la ablation sectorial y las pruebas sectoriales son **P2** (ver §10 y `EXTERNAL_SOURCES.md`).
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO** o **NO APLICA**.
> Las pruebas están en `backend/tests/Feature/Intelligence/` y se ejecutan con
> `cd backend && DB_DATABASE=<bd_de_pruebas> php artisan test --filter=Intelligence` (más `OpenApiRoutesTest` y `TenantDataExporterStage2Test`).
>
> Documentos hermanos: `FACTOR_PIPELINE.md` (W6-E: pipeline de factores en la demanda, activación, competencia y ablation), `PRODUCT_TAXONOMY.md` (taxonomía), `EXTERNAL_SOURCES.md` (estado legal y técnico de cada fuente), `SECTOR_PROFILES.md` (perfiles sectoriales y
> los seis escenarios de V2.1 §24.12). Disponibilidad temporal: `docs/data/TEMPORAL_AVAILABILITY.md` (ventas) y los documentos del paquete W2-D del motor
> (`docs/data/FEATURE_CONTRACTS.md`, `docs/ml/POINT_IN_TIME_VALIDATION.md`).

## 1. Principios

1. **Sin segundo almacén de features** (V2.5 §24.1; ADR-V25-25). `factor_definitions` es el **catálogo**: dice qué factor existe, qué clase de disponibilidad tiene y **dónde vive el dato**
   (tabla nativa de ventas, precios, promociones, calendario, compromisos, inventario; la serie externa `external_indicator_observations`; los eventos `external_events`; o el contrato de
   concesiones). El backend no copia datos a un almacén paralelo. *IMPLEMENTADO / VERIFICADO:* `FactorCatalogTest::test_every_company_role_reads_the_public_catalog_with_state_and_where_the_data_lives`.
2. **Ninguna familia se activa por defecto** (V2.1 §24.4). Las 17 familias nacen con `is_active = false` y, además, la empresa debe elegirlas. *IMPLEMENTADO / VERIFICADO:*
   `FactorCatalogTest::test_seeder_catalogs_the_17_families_all_inactive_with_complete_metadata`.
3. **El pipeline de factores es desactivable por empresa y gobernado por el motor.** Activar un factor en la API solo **registra la elección** de la empresa; no cambia nada del motor
   mientras el pipeline de factores de la empresa esté apagado (apagado por defecto: W2-D). El backend no consulta ni modifica ese interruptor.
4. **Catálogos públicos de solo lectura.** Las filas con `tenant_id` NULL son de la plataforma: toda empresa las lee, solo el propietario de la plataforma (superadministrador) las escribe.
5. **Aislamiento fail-closed.** Los modelos de empresa usan un *global scope* que falla sin empresa en contexto; las tablas mixtas (pública + empresa) lo hacen con el scope «propia o pública».
6. **Sin integraciones externas ni LLM.** Nada de este paquete llama a un servicio de terceros. La URL de la fuente de un evento solo se guarda (esquema http/https); jamás se descarga.

## 2. Contratos de datos

Tablas de la migración `2026_03_04_000100` (contrato del arquitecto, sin modificar) y su modelo:

| Tabla | Modelo | Propiedad (`tenant_id`) | Aislamiento |
|---|---|---|---|
| `factor_definitions` | `FactorDefinition` | NULL = catálogo público; con valor = definición propia | `BelongsToTenantOrPublic`: lectura propia + pública; escritura pública solo `PlatformWrite` |
| `source_versions` | `SourceVersion` | NULL = fuente pública | ídem |
| `external_events` | `ExternalEvent` | NULL = evento público (solo lectura) | ídem |
| `taxonomy_nodes` | `TaxonomyNode` | NULL = taxonomía pública | ídem |
| `factor_applicability_rules` | `FactorApplicabilityRule` | NULL = regla del catálogo | ídem; las FK a definición y nodo se validan en el modelo (no son compuestas) |
| `product_taxonomy_assignments` | `ProductTaxonomyAssignment` | siempre de la empresa | `BelongsToTenant` + FK compuesta (tenant, producto) en la BD |
| `tenant_sector_profiles` | `TenantSectorProfile` | siempre de la empresa | `BelongsToTenant` |
| `factor_evaluations` | `FactorEvaluation` | siempre de la empresa | `BelongsToTenant`; «promote» exige holdout intacto |
| `shared_forecast_grants` | `SharedForecastGrant` | **sin `tenant_id`** (dos partes) | scope de partes: solo emisor o receptor |
| `shared_forecast_grant_accesses` | `SharedForecastGrantAccess` | **sin `tenant_id`** | scope por acceso propio o concesión emitida; bitácora de solo inserción |

**Migración nueva (justificada):** `2026_03_09_000100_add_proposer_and_review_note_to_external_events`. El contrato guarda quién *verificó* un evento pero no quién lo *propuso*, y sin ese
dato no se puede exigir segregación de funciones. Agrega `proposed_by` (NULL = propuesto por conector/LLM, sin usuario) y `review_note`. `verified_by`/`verified_at` guardan al revisor de
VERIFIED **y** de REJECTED. Aditiva y reversible.

*IMPLEMENTADO / VERIFICADO:* `IntelligenceModelsTest::test_every_table_of_the_contract_migration_has_its_model`, `…::test_every_model_with_a_tenant_id_refuses_to_be_queried_without_a_company`.

### 2.1 Escritura del catálogo público

`PlatformWrite::run()` es la única forma de crear, modificar o borrar una fila con `tenant_id` NULL y **solo vale sin empresa en contexto** (seeder, comandos, rutas `/admin`). Con una empresa
en contexto: una fila nueva queda siempre a nombre de esa empresa (jamás pública), no se puede crear para otra empresa, no se puede cambiar el `tenant_id` de una fila existente y modificar o
borrar una fila pública lanza `PublicCatalogReadOnlyException` (HTTP 403 `public_catalog_read_only`).
*IMPLEMENTADO / VERIFICADO:* `FactorCatalogTest::test_a_company_can_never_modify_delete_or_publish_catalog_rows`, `…::test_a_company_cannot_create_rows_for_another_company`,
`…::test_only_the_platform_owner_writes_the_catalog_through_the_admin_routes`, `ExternalEventsTest::test_events_are_isolated_between_companies_and_public_events_are_read_only`.

**Operaciones masivas.** Los eventos de modelo (`creating`, `updating`, `deleting`) solo cubren instancias; `Modelo::query()->update(...)`, `->delete()`, `->forceDelete()`, `->increment()`, `->insert(...)` o
`->upsert(...)` no los disparan. Por eso cada modelo con catálogo público usa un *Eloquent Builder* propio (`App\Models\Builders\TenantOrPublicBuilder`, vía `newEloquentBuilder`) que aplica las mismas reglas:

| Operación (con empresa en contexto) | Efecto |
|---|---|
| `update`, `delete`, `forceDelete`, `increment`, `decrement`, `incrementEach/decrementEach`, `touch` | se restringen a `tenant_id` = la empresa: las filas públicas **no se alcanzan** (0 filas afectadas); `forceDelete` además aplica los *scopes* que Eloquent omite |
| `insert`, `insertGetId`, `insertOrIgnore` | cada fila debe traer `tenant_id` = la empresa; con `tenant_id` NULL (pública), sin él o de otra empresa se rechaza todo el lote |
| `upsert` | las mismas reglas de inserción, no puede reasignar `tenant_id` ni «caer» (por la clave única) sobre una fila pública o de otra empresa |
| `truncate`, `updateOrInsert`, `insertUsing`, `insertOrIgnoreUsing` | no se admiten (saltan los filtros y las reglas) |

Sin empresa en contexto, las filas públicas solo se tocan dentro de `PlatformWrite::run()`. Misma garantía para el resto: `SharedForecastGrantAccess` usa `AppendOnlyBuilder` (la bitácora **no admite ninguna
actualización ni borrado, ni fila a fila ni en masa**, y solo se inserta a nombre de la empresa que accede) y `SharedForecastGrant` usa `PartyOwnedBuilder` (el receptor lee pero no modifica, borra ni crea
concesiones; solo el emisor escribe las suyas). *Alcance honesto:* esto protege el acceso por modelos Eloquent, el único que usa la aplicación; `DB::table(...)` es acceso directo a la BD y lo usan solo el
exportador, los comandos de mantenimiento y las pruebas. *IMPLEMENTADO / VERIFICADO:* `MassOperationsTest` (8 pruebas: update, delete, forceDelete, increment/decrement, touch, insert, upsert y truncate sobre
filas públicas desde una empresa; positivos de control sobre filas propias; `PlatformWrite`; bitácora; concesiones).

## 3. Catálogo: las 17 familias de V2.1 §24.4

El seeder `FactorCatalogSeeder` (idempotente; **crea las que faltan y nunca modifica una existente**, ni su metadato ni su `is_active`) cataloga **20 definiciones públicas** en las 17 familias. Todas
con `is_active = false`; ninguna declara `source_key`, licencia de fuente ni `external_indicator_id` (no hay integraciones). Los umbrales `max_staleness_days` son **valores iniciales
orientativos, a aprobar en el piloto; no son universales** (cada `sector_justification` lo declara).

Leyenda de «estado del dato»: **nativo** = Predictily ya guarda el dato; **sin fuente** = el contrato existe pero no hay ninguna fuente ni dato externo; **eventos manuales** = solo se registran a mano;
**contrato desactivado** = diseñado y apagado por bandera.

| # | Familia | Definición (`key`) | Clase de disponibilidad | Almacenamiento | Frec. | Ausencia | Frescura máx. (a aprobar) | Estado del dato |
|---|---|---|---|---|---|---|---|---|
| 1 | `sales_history` | `sales_history.units_sold` | OBSERVED_HISTORICAL | `sales` | D | disable_feature | 7 d | nativo |
| 2 | `prices_discounts` | `prices_discounts.price_history` | OBSERVED_HISTORICAL | `product_price_history` | evento | last_valid_with_age | 30 d | nativo |
| 2 | `prices_discounts` | `prices_discounts.promotions` | KNOWN_IN_ADVANCE | `promotions` | evento | disable_feature | — | nativo |
| 3 | `competition` | `competition.competitor_price` | OBSERVED_HISTORICAL | `external_indicator_observations` | S | last_valid_with_age | 14 d | sin fuente |
| 4 | `search_trends` | `search_trends.search_interest` | OBSERVED_HISTORICAL | `external_indicator_observations` | S | last_valid_with_age | 14 d | sin fuente |
| 5 | `consumer_preferences` | `consumer_preferences.preference_index` | OBSERVED_HISTORICAL | `external_indicator_observations` | M | last_valid_with_age | 120 d | sin fuente |
| 6 | `weather` | `weather.observed` | OBSERVED_HISTORICAL | `external_indicator_observations` | D | last_valid_with_age | 3 d | sin fuente |
| 6 | `weather` | `weather.forecast` | THIRD_PARTY_FORECAST | `external_indicator_observations` | D | unavailable | 2 d | sin fuente; exige historial de emisiones |
| 7 | `epidemiology` | `epidemiology.surveillance_alert` | OBSERVED_HISTORICAL | `external_events` | evento | disable_feature | — | eventos manuales; alerta informativa |
| 8 | `animal_plant_health` | `animal_plant_health.sanitary_alert` | OBSERVED_HISTORICAL | `external_events` | evento | disable_feature | — | eventos manuales; alerta informativa |
| 9 | `food_safety_alerts` | `food_safety_alerts.safety_alert` | OBSERVED_HISTORICAL | `external_events` | evento | disable_feature | — | eventos manuales; alerta informativa |
| 10 | `trade_regulatory` | `trade_regulatory.regulatory_change` | KNOWN_IN_ADVANCE | `external_events` | evento | disable_feature | — | eventos manuales; alerta informativa |
| 11 | `export_statistics` | `export_statistics.export_volume` | OBSERVED_HISTORICAL | `external_indicator_observations` | M | last_valid_with_age | 120 d | sin fuente |
| 12 | `orders_contracts` | `orders_contracts.confirmed_commitments` | KNOWN_IN_ADVANCE | `commercial_commitments` | evento | disable_feature | — | nativo |
| 13 | `shared_projections` | `shared_projections.authorized_forecast` | THIRD_PARTY_FORECAST | `shared_forecast_grants` | evento | unavailable | 14 d | contrato desactivado |
| 14 | `production_estimates` | `production_estimates.crop_estimate` | THIRD_PARTY_FORECAST | `external_indicator_observations` | M | unavailable | 120 d | sin fuente; exige historial de emisiones |
| 15 | `reviews_satisfaction` | `reviews_satisfaction.sentiment_index` | OBSERVED_HISTORICAL | `external_indicator_observations` | S | last_valid_with_age | 30 d | sin fuente |
| 16 | `capacity_inventory` | `capacity_inventory.inventory_on_hand` | OBSERVED_HISTORICAL | `inventory_snapshots` | D | last_valid_with_age | 7 d | nativo |
| 16 | `capacity_inventory` | `capacity_inventory.location_capacity` | STATIC | `location_capacities` | evento | disable_feature | — | nativo |
| 17 | `calendar_events` | `calendar_events.holidays_and_events` | KNOWN_IN_ADVANCE | `calendar_events` | evento | disable_feature | — | nativo |

(La columna «Frec.» usa D = diaria, S = semanal, M = mensual.) Cada definición lleva, además: unidad, cobertura geográfica (`geo_scope`), justificación sectorial y un `schema` con
identificadores, fechas que se registran (evento, observación, publicación, disponibilidad, vigencia, emisión, revisión), `data_status`, `source_status` y `retention_note`.

Decisiones de catálogo que conviene revisar:

* `storage` admite, además de las tablas nativas listadas en el comentario del contrato, `location_capacities` (capacidad: la tabla nativa del Supply Core) y `shared_forecast_grants` (el contrato de
  concesiones). El campo es una cadena sin restricción en la BD; la lista cerrada vive en `FactorDefinition::STORAGES`.
* `trade_regulatory` se cataloga como KNOWN_IN_ADVANCE porque una regulación se publica antes de entrar en vigor; es conocible desde su publicación (`published_at`/`available_at`), no antes.
* `production_estimates` se cataloga como THIRD_PARTY_FORECAST porque son estimaciones de terceros sobre producción futura: exigen fecha de emisión archivada para cualquier backtest.
* Las retenciones (`retention_days`) quedan en NULL: «a definir con la licencia de la fuente cuando exista» (las fuentes nativas siguen la retención de la empresa).

*IMPLEMENTADO / VERIFICADO:* `FactorCatalogTest::test_seeder_catalogs_the_17_families_all_inactive_with_complete_metadata`, `…::test_seeder_is_idempotent_and_never_overwrites_platform_owner_changes`.

### 3.1 API del catálogo

* `GET /api/v1/intelligence/factors` (`data.view`): definiciones públicas + propias, con `state` (`inactive | activated_by_company | active`), `catalog_is_active`, `company_activation`, `data_location`
  (`kind`, `table`, `status`, `note`) y `engine_effect = none_until_pipeline_enabled`. Filtros: `family`, `availability_class`, `state`, `is_informative_alert`.
* `GET/POST/PATCH /api/v1/admin/intelligence/factors[/{key}]` (solo superadministrador, sin empresa): lectura y escritura del catálogo público. Reglas de coherencia: no se puede declarar activa en el
  catálogo una familia externa sin `source_key` (`factor_without_source`: no se inventan fuentes) ni `shared_projections` con la bandera de concesiones apagada (`factor_feature_disabled`); un
  `external_indicator_id` solo puede apuntar a un indicador público y con almacenamiento de series.
  *IMPLEMENTADO / VERIFICADO:* `FactorCatalogTest::test_the_platform_owner_cannot_declare_active_an_external_factor_without_source`.

## 4. Activación por empresa

`PUT /api/v1/intelligence/factors/{key}/activation` con `{ "active": true|false }` (`settings.manage`: propietario o administrador). Guarda en `tenants.settings.factor_activation.<key>` =
`{active, updated_by, updated_at}`, con bloqueo de la fila de la empresa y auditoría (`intelligence.factor_activation_changed`). No modifica la definición del catálogo.

| Estado (`state`) | Significado |
|---|---|
| `inactive` | la empresa no lo eligió (estado de las 17 familias al inicio) |
| `activated_by_company` | la empresa lo eligió pero el catálogo no lo habilita (`catalog_is_active = false`): intención registrada, **sin fuente ni dato** |
| `active` | habilitado por la plataforma **y** elegido por la empresa |

Lo que la activación **no** hace: no enciende el pipeline de factores del motor, no descarga datos y no crea conectores. Con el pipeline apagado ningún factor externo entra al modelo.

* *IMPLEMENTADO / VERIFICADO:* roles (`FactorActivationTest::test_owner_and_admin_can_activate_and_the_choice_is_stored_in_tenant_settings_without_touching_the_catalog`,
  `…::test_analyst_and_viewer_cannot_activate`, `…::test_a_read_only_api_token_without_the_ability_cannot_activate`), aislamiento (`…::test_activation_is_isolated_per_company_and_rejects_unknown_or_foreign_keys`),
  no falsificable por `PATCH /tenant` (`…::test_activation_cannot_be_forged_through_the_tenant_settings_endpoint`) y persistencia (`…::test_activation_persists_when_other_settings_are_updated_afterwards`).
* *IMPLEMENTADO / VERIFICADO (W6-E):* el motor lee `factor_activation` y la exige junto con `factors.enabled` (bloque «factors» de `platform/config.py`, `enabled=false` por defecto): un factor cuenta solo con las dos
  condiciones (`FACTOR_PIPELINE.md` §2). **Aun así, activar no cambia lo servido por sí solo:** el modelo con factores debe ganar la competencia con el LightGBM sin factores en las ventanas de selección
  (`FACTOR_PIPELINE.md` §4); si no gana, la ejecución sirve exactamente lo mismo que sin factores. Con el pipeline de la empresa apagado, la activación no tiene efecto alguno (`engine_effect = none_until_pipeline_enabled`);
  el backend sigue sin consultar ni modificar el interruptor del motor.

## 5. Disponibilidad temporal

Cada factor declara su clase (ADR-V25-06): `OBSERVED_HISTORICAL`, `STATIC`, `KNOWN_IN_ADVANCE`, `THIRD_PARTY_FORECAST`, `USER_SCENARIO`, `UNAVAILABLE_FOR_HORIZON`. El `FeatureAvailabilityValidator` del
motor (W2-D, `engine/predictily_engine/availability/`) decide para un corte `C` el valor visible y su estado (`AVAILABLE`, `NOT_YET_AVAILABLE`, `STALE`, `INVALID`, `SCENARIO_ONLY`); este documento
**no lo duplica**. Lo que sí entrega el backend para que el validador funcione:

| Dato | Dónde se registra | Estado |
|---|---|---|
| Fechas de una observación externa (`observed_at`, `published_at`, `available_at`, vigencia, `issued_at`, revisión) | columnas de `external_indicator_observations` (contrato del arquitecto) | contrato; **no hay datos** (sin fuente) |
| `published_at` de un evento y `available_at` (cuándo pudo usarlo el sistema) | `external_events` | **IMPLEMENTADO / VERIFICADO**: `available_at` se fija al *verificar* —el mayor entre ese instante y `published_at`, que puede estar unos minutos en el futuro por desfase de reloj— (un evento tecleado hoy no existía para un backtest de ayer): `ExternalEventsTest::test_the_proposer_cannot_verify_or_reject_their_own_event_but_another_user_can`, `…::test_available_at_is_never_earlier_than_the_publication_date` |
| Evento sin `published_at` ⇒ INVALID como feature | `validity_state = INVALID` y verificación rechazada (`published_at_required`) | **IMPLEMENTADO / VERIFICADO**: `ExternalEventsTest::test_without_published_at_the_event_cannot_be_verified_for_predictive_use` |
| Pronóstico de terceros sin historial de emisiones ⇒ backtest INVALID y prohibido | `schema.requires_issue_history = true` en `weather.forecast` y `production_estimates.crop_estimate` | **catálogo declarado**; la prohibición la aplica el validador del motor (W2-D) |
| Pronóstico compartido (concesión) | `issued_at` de la concesión y versión de pronóstico fijada | **IMPLEMENTADO / VERIFICADO** en el contrato de concesiones (§8) |

## 6. Eventos externos manuales

`/api/v1/intelligence/events` (CRUD de la empresa; los públicos se leen y no se escriben). Estados `PROPOSED → VERIFIED | REJECTED`; `EXPIRED` al vencer la vigencia. `extracted_by` es siempre `manual`.

| Regla | Implementación | Verificación |
|---|---|---|
| Un evento nace `PROPOSED` y a nombre de la empresa; el estado, el proponente y `extracted_by` no se pueden forzar | `ExternalEventService::propose` | `ExternalEventsTest::test_an_event_is_born_proposed_with_full_provenance_and_cannot_forge_its_state` |
| Procedencia mínima al proponer: URL **o** referencia de la fuente; URL solo http/https (nunca se descarga) | validación del controlador | `…::test_incomplete_provenance_blocks_verification_and_a_future_publication_is_rejected` |
| Procedencia completa para verificar: `published_at`, `source_url`, `authority`, `jurisdiction` | `ExternalEventService::verify` | ídem y `…::test_without_published_at_the_event_cannot_be_verified_for_predictive_use` |
| **Segregación**: quien propone no verifica ni rechaza (403 `segregation_of_duties`); verificar/rechazar exige `settings.manage` (administrador o propietario) | `assertSegregation` | `…::test_the_proposer_cannot_verify_or_reject_their_own_event_but_another_user_can`, `…::test_only_admin_or_owner_verify_and_analyst_or_viewer_cannot` |
| Rechazo con motivo obligatorio | `reject` | `…::test_reject_requires_a_reason_a_different_user_and_keeps_the_event_out_of_use` |
| Un evento resuelto es inmutable; solo se borra uno `PROPOSED` o `REJECTED` | `assertStatus` | `…::test_the_proposer_cannot_verify_or_reject_their_own_event_but_another_user_can` |
| **Solo el proponente edita o retira** su evento mientras espera revisión (403 `only_proposer_can_change`): si otro pudiera alterar el contenido y luego verificarlo, la segregación sería ilusoria; la corrección ajena se hace proponiendo otro evento. Uno rechazado sale del flujo y cualquier usuario con permiso de catálogo puede limpiarlo | `assertProposerOwnsProposal` | `…::test_only_the_proposer_edits_or_withdraws_a_pending_event_so_segregation_cannot_be_bypassed` |
| **Verificar/rechazar se atan a la versión revisada.** El evento expone `review_token` (huella de su contenido revisable y estado); `verify` y `reject` exigen devolverlo (cuerpo o `If-Match`): sin él 422 `review_token_required`; si el contenido cambió tras la lectura del revisor 409 `event_changed_since_review`. La comparación se hace dentro de una transacción con la fila bloqueada (`lockForUpdate`), y `update`/`delete` toman el mismo bloqueo, de modo que una edición queda antes o después, nunca en medio | `ExternalEventService::verify/reject/reviewToken` | `…::test_verify_and_reject_are_bound_to_the_version_the_reviewer_read`, `…::test_verify_compares_against_the_locked_row_not_against_a_stale_copy`, `…::test_the_review_token_ignores_saves_without_changes_but_tracks_every_reviewable_field` |
| La URL de la fuente no puede traer usuario ni clave incrustados (`https://usuario:clave@host`): se mostraría en claro a todos | validación del controlador | `…::test_source_url_cannot_carry_embedded_credentials` |
| `EXPIRED` al vencer `effective_to` (día local de la empresa): estado efectivo en la API y proceso `predictily:intelligence-expire` (diario 01:30, idempotente) | `expireDue`, `present` | `…::test_events_expire_when_their_validity_ends` |
| Alertas informativas (`is_informative_alert`) se listan **aparte** (`/events/informative-alerts`), se muestran aunque no mejoren el error y no son features predictivas (`usable_for_prediction = false`) | `ExternalEventController`, `present` | `…::test_informative_alerts_are_listed_apart_and_are_never_predictive_features` |
| `affects` (productos, categorías, nodos) solo con identificadores visibles para la empresa | `assertAffectsVisible` | `…::test_affects_cannot_reference_products_of_another_company` |
| Aislamiento entre empresas y eventos públicos de solo lectura | `BelongsToTenantOrPublic` | `…::test_events_are_isolated_between_companies_and_public_events_are_read_only` |
| Sin integración ni LLM en este paquete | no hay clientes HTTP ni proveedores | `…::test_this_package_has_no_external_integration_or_llm_extraction` |

**Cómo leer `EXPIRED` (para el motor y los consumidores).** `EXPIRED` significa «su vigencia terminó», **no** «fue verificado»: el proceso de vencimiento marca tanto los `VERIFIED` como los `PROPOSED` que nunca se
revisaron. Un evento `EXPIRED` con `verified_at` NULL **nunca fue verificado y no es usable en ningún corte**. Quien reconstruya el valor visible a un corte (el `FeatureAvailabilityValidator`, W2-D) debe
exigir `verified_at` no nulo y `verified_at <= corte` (y `available_at <= corte`) además del estado, y tratar un `EXPIRED` como vigente solo para cortes anteriores a su `effective_to`. La API ya lo separa:
`status` es el estado guardado, `effective_status` el real y `usable_for_prediction` solo es verdadero para un evento verificado, vigente y no informativo.

`usable_for_prediction` = verificado + con `published_at` + vigente + **no** informativo. *DISEÑADO / NO IMPLEMENTADO:* extracción automática de eventos por conector o LLM con salida validada por JSON Schema
(`extracted_by = connector|llm`, `extraction`): las columnas existen, nada las escribe todavía (P2).

## 7. Ablation y evaluación de factores

`factor_evaluations` (baseline vs candidato con holdout intacto y decisión `promote | reject | inconclusive`) existe como modelo aislado por empresa. **IMPLEMENTADO / VERIFICADO:** una evaluación
`promote` con `holdout_untouched = false` se rechaza (`IntelligenceModelsTest::test_factor_evaluations_are_isolated_and_never_promote_with_a_used_holdout`).

**IMPLEMENTADO / VERIFICADO (W6-E, herramienta P2):** `engine/scripts/factor_ablation.py` corre el backtest as-of sin factores, con cada factor y con todos juntos sobre las ventanas de selección —entrenado como se sirve y con el mismo control de
placebos con semilla y la misma selección hacia delante que el pipeline: una combinación solo se promueve si cada miembro se promovió solo— y entrega las diferencias de métricas por
horizonte y segmento con los denominadores de W3-A y filas con la forma de `factor_evaluations`. Es de **solo lectura** (no escribe esta tabla ni ninguna otra; un proceso autorizado registraría las filas), no toca el período final
reservado y solo corre sobre datos ficticios o una base de pruebas. Detalle, salida de ejemplo y límites: `FACTOR_PIPELINE.md` §7. **DISEÑADO / NO IMPLEMENTADO (P2):** el control de comparaciones múltiples más allá de la selección hacia delante con placebos, los reportes por
familia completa y la escritura de `factor_evaluations` por el proceso autorizado. **No hay ninguna evaluación real ni porcentaje de mejora de ningún factor en datos reales; no se inventa ninguno** (los números del ejemplo de
`FACTOR_PIPELINE.md` salen de una empresa ficticia con un efecto sembrado). `factor_applicability_rules` (qué factor aplica a qué perfil/categoría/región) también es solo contrato y modelo: sin API ni motor de
aplicabilidad (DISEÑADO).

## 8. Pronósticos compartidos entre empresas (`SharedForecastGrant`, V2.1 §10)

**Estado: DISEÑADO Y DESACTIVADO** (V2.5 §24.10: no se habilita la red colaborativa en P0; sí se mantiene el contrato y las pruebas negativas de aislamiento). Bandera:
`config/intelligence.php` → `shared_forecast_grants.enabled` (env `PREDICTILY_SHARED_FORECAST_GRANTS_ENABLED`), **por defecto `false`**.

### 8.1 Con la bandera apagada no existe ruta funcional

El primer middleware de las rutas (`EnsureSharedForecastGrantsEnabled`, con prioridad sobre la autenticación en `bootstrap/app.php`) responde el mismo 404 que una ruta inexistente, **incluso sin sesión**; el
servicio además se niega a operar. *IMPLEMENTADO / VERIFICADO:* `SharedForecastGrantTest::test_the_feature_is_disabled_by_default`,
`…::test_with_the_flag_off_no_route_is_functional_even_for_unauthenticated_callers`, `…::test_an_existing_grant_stops_being_readable_if_the_feature_is_turned_off_again`.

* **Todos los verbos, no solo los registrados.** Con la bandera apagada, `GET/POST/PUT/PATCH/DELETE/OPTIONS/HEAD` sobre cada URI de concesiones responden el mismo 404 (mismo cuerpo, sin cabecera `Allow`) que una ruta
  inexistente: los verbos registrados pasan por el middleware; `OPTIONS` tiene una ruta explícita detrás del mismo middleware (sin ella Laravel respondería 200 con `Allow`); y un verbo no registrado
  (`PUT`, `PATCH`, `DELETE`), que Laravel resolvería con un 405, se convierte en el 404 estándar en `bootstrap/app.php` mientras la bandera esté apagada. Con la bandera encendida: `OPTIONS` 204 con `Allow`
  y los verbos no registrados 405. *Verificado:* `…::test_every_http_verb_on_the_grant_urls_is_a_plain_404_with_the_flag_off`, `…::test_with_the_flag_on_unsupported_verbs_are_405_and_options_lists_the_allowed_ones`.
* **La bandera es fail-closed.** Solo un booleano reconocible y verdadero (`true`, `1`, `on`, `yes`) la enciende: `filter_var(…, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true`, tanto en
  `config/intelligence.php` (para la variable de entorno) como en `SharedForecastGrantService::isEnabled()` (para el valor ya cargado). `no`, `off`, `0`, vacío, `null`, un texto irreconocible o un número distinto de 1
  la dejan apagada (con un `(bool)` directo, `"no"` sería verdadero). *Verificado:* `…::test_the_flag_is_fail_closed_for_any_value_that_is_not_a_recognizable_true` y
  `…::test_the_config_file_parses_the_environment_variable_fail_closed` (17 valores de configuración y 14 de entorno).

### 8.2 Reglas del contrato

| Regla | Dónde se impone | Verificación |
|---|---|---|
| Emisor ≠ receptor; receptor = empresa activa | validación + `CHECK` de la BD | `…::test_grant_validation_rules`, `…::test_the_database_enforces_distinct_parties_and_valid_period_and_expiry` |
| El emisor solo concede productos o categorías **suyos** | `Product`/`ProductCategory` con `BelongsToTenant` en el contexto del emisor | `…::test_grant_validation_rules` |
| Periodo (`period_from ≤ period_to`), granularidad (`day|week|month`) | validación + `CHECK` | ídem |
| **Solo periodos totalmente contenidos.** Un pronóstico semanal o mensual se sirve únicamente si **todo** su periodo (`target_date` … `target_date + period_days − 1`) cae dentro del periodo concedido **y** de la ventana pedida; una semana o un mes que empieza antes o termina después del periodo concedido no se sirve (incluiría días no concedidos). Puede resultar vacío si los límites no se alinean con la granularidad (`meta.period_rule` lo declara) | `forecastRows` | `…::test_weekly_and_monthly_periods_are_served_only_when_fully_inside_the_granted_period`, `…::test_the_requested_window_is_also_applied_to_whole_periods` |
| Contenido permitido: solo `point_forecast`, `interval_80`, `interval_95`; **nunca** ventas, clientes, precios, ingresos ni costos (422 `content_not_allowed`) | lista en `config/intelligence.php` y `assertContentAllowed` | `…::test_grant_validation_rules` |
| Versión de pronóstico fijada a una ejecución completada del emisor al activar | `resolveForecastVersion` | `…::test_the_forecast_version_is_pinned_when_the_grant_is_activated` |
| Vencimiento (`expires_at > issued_at`, posterior a hoy al crear) | validación + `CHECK` + estado efectivo + proceso diario | `…::test_an_expired_grant_denies_the_recipient_and_logs_it` |
| Revocación inmediata (`revoked_at`, `revoked_by`) | `revoke` | `…::test_a_revoked_grant_denies_the_recipient_immediately_and_logs_it` |
| Solo propietario/administrador del emisor administra (`settings.manage`); el receptor lee con `forecast.view` | rutas | `…::test_only_owner_or_admin_manage_grants_and_a_viewer_of_the_issuer_cannot`, `…::test_viewers_of_the_recipient_can_read_but_not_manage_and_unauthenticated_calls_are_rejected` |
| Un borrador es invisible para el receptor | `read` | `…::test_a_draft_is_invisible_to_the_recipient` |
| Si la empresa emisora deja de estar activa (suspendida o con eliminación en curso) no se sirve su pronóstico (403 `issuer_unavailable`, `denied_scope`) | `read` | `…::test_a_grant_is_not_served_when_the_issuer_company_is_no_longer_active` |

Ciclo de vida: `draft → active → revoked | expired`. API (todas tras la bandera): `GET|POST /intelligence/shared-forecast-grants`, `GET /{id}`, `POST /{id}/activate`, `POST /{id}/revoke`,
`GET /{id}/accesses` (emisor); `GET /intelligence/received-forecast-grants` y `GET /{id}/forecast` (receptor). Ver `docs/openapi/v2.yaml`.

### 8.3 Pruebas negativas P0 de aislamiento (T14)

| Caso | Resultado esperado | Verificación |
|---|---|---|
| El receptor pide otro producto del emisor, fechas fuera del periodo, otra granularidad o contenido no concedido (incl. `sales`, `customers`) | 403 `outside_grant_scope`; fila `denied_scope` por intento | `…::test_requests_outside_the_granted_scope_are_denied_and_logged` |
| El receptor nunca recibe ventas, clientes, precios, ingresos, ramas, costos ni el nombre del modelo del emisor; la concesión que ve no incluye los ids internos del emisor | la respuesta contiene solo `point_forecast`/`interval_*` del nivel y los sujetos concedidos | `…::test_the_recipient_reads_exactly_the_granted_scope_and_nothing_else`, `…::test_the_recipient_never_receives_sales_customers_prices_revenue_or_other_issuer_data` |
| Tras el vencimiento el receptor no lee nada | 403 `grant_expired`; `denied_expired` | `…::test_an_expired_grant_denies_the_recipient_and_logs_it` |
| Tras la revocación el receptor no lee nada | 403 `grant_revoked`; `denied_revoked` | `…::test_a_revoked_grant_denies_the_recipient_immediately_and_logs_it` |
| Un **tercero** nunca lee ni administra | 404 idéntico al de una concesión inexistente; intento en la bitácora (`denied_scope`, con su empresa) y en la auditoría de plataforma | `…::test_a_third_company_can_never_read_or_manage_a_grant_and_the_attempt_is_logged` |
| El receptor no administra y el emisor no lee por la vía del receptor | 404; intento registrado | `…::test_the_recipient_cannot_manage_the_grant_it_received_and_the_issuer_cannot_read_through_the_recipient_path` |
| Aislamiento a nivel de modelo, fail-closed sin empresa | un tercero ve 0 concesiones; sin contexto, excepción | `…::test_models_only_expose_a_grant_to_its_two_parties_and_fail_closed_without_a_company` |
| La bitácora es de solo inserción | actualizar o borrar lanza excepción | `…::test_the_access_log_is_append_only` |
| Alcance por categoría | solo filas de nivel categoría de las categorías concedidas | `…::test_a_category_scope_grant_serves_only_category_level_rows_of_the_granted_categories` |

**Qué sí ve el receptor del emisor (declarado, aceptado):** al leer el pronóstico cada fila lleva `subject` con el **identificador interno** del producto o categoría del emisor (`subject.id`), su `sku` (productos) y
su `name`; esos son el contenido concedido —sin ellos el receptor no sabría qué pronóstico recibe— y por eso el emisor solo concede lo que está dispuesto a identificar. El listado de concesiones recibidas,
en cambio, no incluye los ids (`subjects_count`), y la lectura nunca devuelve ventas, clientes, sucursales, precios, ingresos, costos ni el modelo usado.

Para el emisor la bitácora identifica al actor solo como `recipient`, `issuer` u `other_company`: no recibe el usuario ni el identificador de la empresa que intentó acceder.

### 8.4 Un pronóstico compartido NO es un pedido confirmado

La respuesta lo declara en `meta` (`is_confirmed_order = false`, `counts_as_committed_demand = false`, `kind = shared_projection`, más un aviso de reconciliación) y en cada fila (`kind =
shared_projection`). La lectura **no escribe nada** en las tablas del receptor (compromisos, ventas, pronósticos, recomendaciones) y ningún código de compromisos, Customer, Supply, Demand ni Analytics
referencia las concesiones, de modo que la suma doble de demanda se evita por construcción. *IMPLEMENTADO / VERIFICADO:* `…::test_a_shared_forecast_is_not_a_confirmed_order_and_writes_nothing_in_the_recipient`,
`…::test_no_demand_or_commitment_code_reads_the_shared_forecast_tables`. *DISEÑADO / NO IMPLEMENTADO:* la reconciliación completa «proyección del retailer + pedidos efectivamente confirmados +
pronóstico del fabricante» (V2.1 §24.10): es una función del consumidor que no existe mientras la red colaborativa esté apagada.

## 9. Aislamiento, auditoría y exportación LOPDP

**Auditoría** (`audit_logs`, metadatos sin PII): `intelligence.factor_activation_changed`, `factor_definition_created|updated`, `event_proposed|updated|deleted|verified|rejected`, `taxonomy_node_created|
updated|versioned|deleted`, `taxonomy_assignment_created|verified|rejected|updated`, `sector_profile_created|updated|deleted`, `shared_grant_created|activated|revoked|denied`.

**Exportación LOPDP (portabilidad)** — `TenantDataExporter`:

* Las tablas con `tenant_id` ya estaban clasificadas como exportadas (contrato de W2): se exportan **solo las filas de la empresa**; el catálogo público (`tenant_id` NULL) no es dato de la empresa.
  Con la migración nueva se exportan además `proposed_by` y `review_note` de `external_events`. *VERIFICADO:* `IntelligenceExportTest::test_company_rows_of_factors_events_and_taxonomy_are_exported_and_public_rows_and_other_companies_are_not`.
* **Tratamiento de `shared_forecast_grants` y su bitácora (sin `tenant_id`; pertenecen a dos empresas).** No caben en `TABLES`/`CHILD_TABLES` (exigen un único propietario). Cada empresa exporta lo que le
  corresponde como parte y nada de la otra, **aunque la bandera esté apagada** (portabilidad de lo que exista):
  * **Emisor:** `shared_forecast_grants_issued.csv` (su contrato completo, con el receptor identificado por UUID, no por id interno) y `shared_forecast_grant_accesses_on_issued.csv` (acción, momento y actor
    `recipient`/`other_company`; nunca el usuario ni la empresa que accedió).
  * **Receptor:** `shared_forecast_grants_received.csv` (sin borradores; emisor por UUID y *número* de elementos concedidos, sin los ids internos del emisor ni sus usuarios) y
    `shared_forecast_grant_accesses_made.csv` (los accesos de su propia empresa, con sus usuarios; el `grant_id` solo se informa de las concesiones de las que la empresa es parte: el intento de un tercero
    sobre una concesión ajena conserva acción y momento, no el identificador de ese contrato).
  *VERIFICADO:* `IntelligenceExportTest::test_shared_forecast_grants_are_exported_to_each_party_without_the_data_or_identity_of_the_other`.
* `tenants.settings.factor_activation` viaja en `tenant.json` (ajustes de la empresa).

## 10. Qué es P0 implementado y qué es P2 diseñado

| Elemento | Estado |
|---|---|
| Catálogo de 17 familias (20 definiciones), todas inactivas, con metadato completo | **IMPLEMENTADO / VERIFICADO** |
| Activación por empresa (propietario/administrador), auditada, sin efecto en el motor con el pipeline apagado | **IMPLEMENTADO / VERIFICADO** (el motor la lee desde W6-E y la exige junto con `factors.enabled`; con el pipeline encendido solo cambia lo servido si el modelo con factores gana la competencia) |
| Escritura del catálogo público solo por la plataforma (ruta y modelo) | **IMPLEMENTADO / VERIFICADO** |
| Eventos manuales con procedencia, segregación, vencimiento y alertas informativas | **IMPLEMENTADO / VERIFICADO** |
| Taxonomía básica, perfiles de relevancia y asignaciones con procedencia (T13 parcial) | **IMPLEMENTADO / VERIFICADO** (ver `PRODUCT_TAXONOMY.md`) |
| Contrato `SharedForecastGrant` con pruebas negativas de aislamiento (T14 parcial) | **IMPLEMENTADO / VERIFICADO**, **desactivado** por bandera |
| Exportación LOPDP de todo lo anterior | **IMPLEMENTADO / VERIFICADO** |
| Conectores y fuentes externas (clima, tendencias, competencia, estadísticas, sanidad…) | **BLOQUEADO / P2**: ninguna integración activa; ver `EXTERNAL_SOURCES.md` |
| Extracción de eventos y clasificación de productos por LLM | **DISEÑADO / NO IMPLEMENTADO** (P2; las columnas de procedencia existen) |
| Herramienta de ablation baseline vs +factor (`scripts/factor_ablation.py`, solo lectura) y pipeline de factores conectado a la demanda | **IMPLEMENTADO / VERIFICADO** (W6-E; datos ficticios y base de pruebas) |
| `FactorApplicabilityEngine`, `FeatureSelectionEvaluator`, control de comparaciones múltiples más allá de la selección hacia delante con placebos, escritura de `factor_evaluations` y ablation sobre datos reales | **DISEÑADO / NO IMPLEMENTADO** (P2) |
| Lectura de `factor_activation` por el motor | **IMPLEMENTADO / VERIFICADO** (W6-E) |
| Reconciliación de proyecciones compartidas | **DISEÑADO / NO IMPLEMENTADO** |
| Pruebas sectoriales T15 (seis casos) | **`P3_PENDIENTE`**, sin cobertura declarada; datos reales BLOQUEADO (ver `SECTOR_PROFILES.md` y `docs/testing/SECTORAL_FACTOR_INTELLIGENCE_ACCEPTANCE.md`) |
| Red colaborativa entre empresas | **DESACTIVADA** en P0 por decisión (V2.5 §24.10) |

## 11. Limitaciones conocidas

* `profile_key` de un perfil sectorial se valida solo por formato; contrastarlo con el registro del motor (`platform/extensions.py`) es DISEÑADO.
* El proponente de un evento creado por conector o LLM queda `NULL`; para esos eventos cualquier revisor autorizado puede resolverlos (no hay segregación posible sin usuario proponente). Hoy no se crean.
* Un administrador no puede verificar su propio evento; en una empresa con un solo administrador/propietario, los eventos que ese usuario proponga requieren otro administrador. Es la consecuencia deseada de la segregación.
* Las definiciones propias de una empresa (`factor_definitions` con `tenant_id`) se pueden crear por el modelo y se ven en el catálogo, pero no hay API para crearlas: P2.
