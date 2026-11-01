# Contratos de features y modelo de disponibilidad temporal (V2.5 §24.5)

> **Alcance y estado.** Este documento describe el registro declarativo en el que **cada feature que produce el motor de demanda**
> (`engine/predictily_engine/features/builder.py` y las que MLForecast deriva de ellas) declara su clase de disponibilidad, fuente,
> regla temporal y **datos requeridos**, y el modelo de disponibilidad único (`engine/predictily_engine/availability/`) que decide
> qué valor de un factor era visible en un corte. Implementa el ADR-V25-06 y el ADR-V25-25 de `docs/architecture/adr/V25-ADRS.md`
> sobre el contrato de la migración `2026_03_04_000100_create_factor_intelligence_and_taxonomy_contracts`. El método de validación y los
> casos T02/T11 están en `docs/ml/POINT_IN_TIME_VALIDATION.md`.
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**,
> **BLOQUEADO** o **NO APLICA**. Las pruebas están en `engine/tests/` y se ejecutan con
> `cd engine && ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<bd_de_pruebas> .venv/bin/python -m pytest -q <ruta>`
> (las de integración necesitan la BD migrada con `cd backend && DB_DATABASE=<bd_de_pruebas> php artisan migrate:fresh --force`; ejecútelas
> desde un directorio sin `.env`).

## 1. Por qué existe

La auditoría del 31-oct-2026 (`docs/roadmap/audit/2026-10-31/`) halló que la disponibilidad temporal de los factores externos se expresaba
con reglas locales y distintas, sin estados y sin contrato por feature:

| Defecto (evidencia) | Efecto | Corrección |
|---|---|---|
| No existía un validador único con estados (V25-AVL-004, V25-FAC-033, V25-FAC-061) | Tres reglas distintas para `known_at` nulo y para la frontera del día | `FeatureAvailabilityValidator` (§2) compone las reglas de W1 y de supply |
| `as_of_series` propaga el último valor indefinidamente (R-169) | Un dato de hace 22 meses se usaba sin marca | `max_staleness_days` y estado `STALE` con la antigüedad etiquetada (§2.3) |
| `as_of_value` falla con `published_at` con zona; el cuarto criterio de corte (R-031, R-170) | Error o fuga de hasta un día según la zona | Instantes con zona → UTC; frontera = medianoche **local** de C+1 (§2.2) |
| `load_observations`/`as_of_series` mezclan el indicador público y el privado del mismo código (R-178) | Una serie con valores de dos fuentes | Resolución por identificador con precedencia explícita (§6) |
| Ninguna feature declaraba su clase de disponibilidad; ninguna prueba fallaba si se añadía una sin declararla (V25-AVL-001) | Fuga silenciosa al añadir features | Registro de contratos; una feature sin contrato hace fallar la construcción (§3) |
| `demand.exceptional_events_flag` no tenía prueba del estado apagado (R-179) | Interruptor sin garantía | `test_exceptional_events_switch.py` (§7) |
| Pronóstico de un tercero sin historial de emisiones usado en un backtest (V25-AVL-006); escenarios sin garantía de quedar fuera de la precisión (V25-AVL-005) | Fuga: usar el pronóstico publicado después como si hubiera existido | Estado `INVALID` y backtest prohibido (§2.4) |

## 2. Modelo de disponibilidad: clases, estados y reglas

### 2.1 Clases (`AvailabilityClass`) y estados (`AvailabilityStatus`)

| Clase | Qué es | Regla de visibilidad al corte `C` |
|---|---|---|
| `OBSERVED_HISTORICAL` | Hecho observado (ventas, precio observado, macro) | Visible si su disponibilidad es anterior a la frontera; el período más reciente y, dentro de él, la **última revisión visible a esa fecha** |
| `STATIC` | Atributo estable (sucursal, categoría) | Igual, sin caducidad |
| `KNOWN_IN_ADVANCE` | Feriados, campañas, promociones y precios futuros confirmados | Valores **futuros** permitidos si se conocían antes del corte; se elige por la vigencia que cubre el día y la última revisión conocida |
| `THIRD_PARTY_FORECAST` | Pronóstico de un tercero | Solo pronósticos **emitidos** (`issued_at`) y disponibles antes del corte; el más reciente es el visible |
| `USER_SCENARIO` | Supuesto del usuario | Siempre `SCENARIO_ONLY`: nunca entra en la medición de precisión |
| `UNAVAILABLE_FOR_HORIZON` | No se conoce para el horizonte | `NOT_YET_AVAILABLE` con motivo `unavailable_for_horizon` |

| Estado | Significado | Ejemplos de motivo (código estable) |
|---|---|---|
| `AVAILABLE` | Hay un valor visible y vigente | `ok` |
| `NOT_YET_AVAILABLE` | El valor existe o existirá, pero no era conocido al corte (o la fuente no ha entregado nada) | `after_cutoff`, `issued_after_cutoff`, `known_after_cutoff`, `published_after_cutoff`, `verified_after_cutoff`, `no_observations`, `no_value_for_date`, `unavailable_for_horizon` |
| `STALE` | Hay un último valor visible, pero su antigüedad supera `max_staleness_days` o su vigencia terminó; se etiqueta con `age_days` | `stale`, `validity_expired` |
| `INVALID` | El dato no puede usarse como feature | `missing_published_at`, `missing_value`, `period_after_availability`, `available_before_observed`, `no_issuance_history`, `unknown_availability`, `event_not_verified`, `event_rejected`, `verification_date_unknown` |
| `SCENARIO_ONLY` | Supuesto del usuario | `user_scenario` |

**Escenarios y precisión.** `USER_SCENARIO` nunca es utilizable como feature ni elegible para medir precisión. Lo que está garantizado de forma **estructural** es que un escenario no llega a las columnas
del modelo (`FactorPipeline` lo deja en `scenario_only`), de modo que el entrenamiento, el backtest y la precisión medida sobre ellos no pueden usarlo. `filter_for_accuracy` y `assert_accuracy_eligible`
son **guardas** para el código que mezcle resultados del validador con una medición; **no están enganchadas** a `evaluation/` porque hoy ninguna métrica consume resultados de factores (DISEÑADO / NO IMPLEMENTADO su
uso en la medición cuando exista una ablation, P2).

**Diagnósticos fuera del manifiesto.** `AvailabilityResult.detail['hidden_after_cutoff']` cuenta filas POSTERIORES al corte (sirve para depurar «¿por qué no veo la revisión 1?»); `as_dict()` lo omite
(`DIAGNOSTIC_DETAIL`) para que el manifiesto de una reconstrucción retroactiva no cambie al llegar datos nuevos; nunca debe usarse como feature.
IMPLEMENTADO / VERIFICADO: `test_availability_validator.py::test_diagnostics_about_rows_after_the_cutoff_stay_out_of_serialized_results`.

Son **exactamente** cinco estados (ADR-V25-06). «UNAVAILABLE» del enunciado de T11 no es un sexto estado: una fuente que no ha entregado
nada es `NOT_YET_AVAILABLE` (`no_observations`); una que dejó de entregar es `STALE` con su antigüedad.

IMPLEMENTADO / VERIFICADO: `test_availability_validator.py` (reglas puras por clase) y `test_factors_t11_integration.py` (contra PostgreSQL).

### 2.2 Una sola regla de visibilidad (reutilizada, no duplicada)

`engine/predictily_engine/availability/visibility.py` **compone** las dos reglas que ya existían; no define otra:

1. **Instantes** (`available_at`, `published_at`, `issued_at`, `known_at`…): visible si `instante < medianoche local de (C + 1)`
   (`data.asof.knowledge_boundary`, ADR-V25-23) ⇔ «visible si es ≤ fin del día local del corte» de ADR-V25-06.
2. **Disponibilidad desconocida** (sin fecha): `supply.availability.known_by` — una ejecución **vigente** la acepta; una reconstrucción
   **retroactiva** (backtest, corte histórico) la **excluye** (la ingestión actual de un histórico no demuestra que se conociera antes).

La **disponibilidad** de una observación es `COALESCE(available_at, GREATEST(published_at, ingested_at))`; la de un pronóstico de tercero,
además, nunca es anterior a su emisión (`issued_at`). Los instantes con zona se convierten a UTC; los **sin zona se interpretan como UTC**
(igual que `data.asof`); el día de negocio es el día local de `tenants.timezone`.

IMPLEMENTADO / VERIFICADO: `test_availability_validator.py::test_visibility_is_strict_at_the_local_midnight_of_the_next_day_and_independent_of_the_storage_timezone`,
`::test_the_rule_is_exactly_the_w1_boundary_and_the_supply_day_rule` (instantes a 0 y ±1 ns de la frontera en 5 zonas, incluidos cambios de
horario, contra `knowledge_boundary` **y** contra `known_by`), `::test_unknown_availability_follows_the_supply_rule_live_accepts_retroactive_excludes`;
en BD: `test_factors_t11_integration.py::test_timezone_aware_instants_from_postgres_are_read_and_judged_in_the_company_timezone`.

**Reglas de corte que NO se han unificado** (V25-AVL-014; fuera del alcance de este paquete; DISEÑADO / NO IMPLEMENTADO): `extensions/data_core.py::load_calendar_events`
corta en **fin del día UTC** (más conservador que la medianoche local de Guayaquil en 5 h: un `known_at` entre las 19:00 y las 24:00 locales
del día C no es visible para ese lector pero sí para el validador) y `features/builder.py` interpreta el `known_at` **sin zona** de las promociones
como hora local de la empresa. Ambos son anteriores a este paquete y las pruebas existentes los fijan; unificarlos exige decidirlo con el propietario.

### 2.3 Antigüedad (`STALE`) y estrategia de ausencia

`STALE` se decide por `factor_definitions.max_staleness_days`; si es nula, por la frecuencia del factor
(`factors.staleness_days_by_frequency`) y, si tampoco, por `factors.default_max_staleness_days` — **valores iniciales de diseño, «a aprobar en el
piloto»**: un múltiplo del período de la fuente más su rezago habitual de publicación (diario 7, semanal 21, mensual 75, trimestral 150, anual 400,
general 90 días). No son universales y no se han validado con datos reales. La antigüedad se mide desde el día de observación (`observed_at`, o el
período) hasta la fecha de uso; para un día del horizonte el último valor visible se **arrastra** (`carried_forward`) y su antigüedad crece.

**Antigüedad × horizonte** (decisión documentada): la antigüedad se mide hasta cada día de uso, no solo hasta el corte. Un factor con antigüedad `a` al corte y límite `L` es `STALE`
en los días del horizonte con `a + h > L`. Con `disable_feature` basta UN día así para que el factor salga entero del modelo (el conjunto de features debe ser el mismo en entrenamiento y
predicción); con `last_valid_with_age` entra etiquetado. Por eso el límite de un factor debe cubrir «antigüedad al corte + horizonte máximo» o su estrategia ser `last_valid_with_age`; el informe
(`dropped[].first_unusable_date`, `unusable_days`, `age_days`) dice desde cuándo. Se eligió esto frente a medir la antigüedad solo contra el corte porque un valor de hace 80 días no deja de serlo
por pronosticar a 30 días. IMPLEMENTADO / VERIFICADO: `test_factors_t11_integration.py::test_staleness_and_horizon_interact_the_carried_value_ages_until_it_turns_stale`.

`factor_definitions.missing_strategy` decide qué hacer sin un valor utilizable: `disable_feature` (el factor sale del modelo; respaldo = modelo sin el
factor), `last_valid_with_age` (se usa el último valor válido etiquetado con su antigüedad) o `unavailable`.

### 2.4 Pronósticos de terceros: emisión y prohibición del backtest

Un pronóstico emitido mañana pero registrado hoy es `NOT_YET_AVAILABLE` (`issued_after_cutoff`, marca `registered_before_issue`). Si la fuente **no
tiene historial archivado de emisiones** (ninguna fila con `issued_at`), en una reconstrucción retroactiva el factor es `INVALID`
(`no_issuance_history`) y `assert_backtest_allowed` lanza `ForecastBacktestForbidden`: usar el pronóstico publicado después como si hubiera existido
sería fuga. Alternativas: histórico archivado real, desactivar el factor o limitar la conclusión. Un archivo parcial no se prohíbe, pero las filas sin
emisión no cuentan. **BLOQUEADO**: la demostración con un proveedor real (sin credenciales ni licencias); lo verificado usa filas ficticias.

IMPLEMENTADO / VERIFICADO: `test_availability_validator.py::test_a_source_without_archived_issuances_is_invalid_for_a_backtest_and_the_backtest_is_forbidden`,
`::test_the_visible_forecast_is_the_latest_issuance_up_to_the_cutoff_and_it_ages_from_its_issue_date`;
`test_factors_t11_integration.py::test_t11_forecast_source_without_issuance_history_is_invalid_and_its_backtest_forbidden`.

## 3. Registro de contratos de features

Fuente única: `engine/predictily_engine/availability/contracts.py` (`FEATURE_CONTRACTS`, versión **`fc-2026.11-v1`**, con huella SHA-256 fijada por
`test_feature_contracts.py::test_the_contract_registry_is_pinned_by_version_and_digest`: cambiar un contrato sin subir la versión hace fallar la prueba).
`feature_registry()` (filas de `forecast_features`) se **deriva** de este registro: mismos nombres, tipos y descripciones; la configuración de cada fila
añade `availability_class`, `source`, `required_data` y `future_mode`
(`test_feature_registry_keeps_the_rows_it_always_had_and_adds_the_contract_to_their_config`).

Campos de un contrato: `name`, `kind`, `availability_class` (fechas ≤ corte), `source`, `known_at` (cómo se determina cuándo se conocía), `future_mode`
(qué ocurre en días > corte), `description`, `required_data`, `optional_data`, `produced_as` (columnas que genera MLForecast), `on_missing`, `enabled_by_default`,
`assumption` y, si el futuro cambia con una condición, `future_availability_class` y `future_condition`.

| Feature (columna que genera MLForecast) | Tipo | Clase (≤ corte) | Futuro (`future_mode`) | Datos requeridos | Sin sus datos (`on_missing`) |
|---|---|---|---|---|---|
| 13 banderas de calendario: `is_holiday`, `is_pre_holiday`, `is_post_holiday`, `is_payday_window`, `is_month_end`, `is_decimo_tercero`, `is_decimo_cuarto`, `is_school_season`, `is_mothers_day_window`, `is_fathers_day_window`, `is_black_friday_window`, `is_christmas_window`, `is_carnival_window` | calendar | KNOWN_IN_ADVANCE | deterministic_from_date | — (opcional: `branch_region`) | block |
| `dayofweek`, `month`, `day` | calendar | KNOWN_IN_ADVANCE | deterministic_from_date | — | block |
| `lag1`, `lag7`, `lag14`, `lag28` | lag | OBSERVED_HISTORICAL | recursive_forecast | `sales_history` | block |
| `rolling_mean_lag1_w7` (`rolling_mean_lag1_window_size7`) | rolling | OBSERVED_HISTORICAL | recursive_forecast | `sales_history` | block |
| `rolling_mean_lag1_w28` (`rolling_mean_lag1_window_size28`) | rolling | OBSERVED_HISTORICAL | recursive_forecast | `sales_history` | block |
| `rolling_std_lag1_w28` (`rolling_std_lag1_window_size28`) | rolling | OBSERVED_HISTORICAL | recursive_forecast | `sales_history` | block |
| `seasonal_rolling_mean_lag7_s7_w4` (`seasonal_rolling_mean_lag7_season_length7_window_size4`) | rolling | OBSERVED_HISTORICAL | recursive_forecast | `sales_history` | block |
| `promo_discount` | commercial | KNOWN_IN_ADVANCE | known_at_cutoff | `promotions` | disable |
| `price_ratio` | commercial | OBSERVED_HISTORICAL (futuro: supuesto `frozen_price`, o KNOWN_IN_ADVANCE si hay precio futuro confirmado) | frozen_price | `price` (opcional: `confirmed_future_price`) | disable |
| `branch_code_num` | static | STATIC | constant | `branches` | block |
| `category_num` | static | STATIC | constant | `product_category` | neutral |
| `region_num` | geographic | STATIC | constant | `branch_region` | neutral |
| `series_level` | static | OBSERVED_HISTORICAL | constant_at_cutoff | `sales_history` | block |
| `weight` (peso de entrenamiento, no es una feature del modelo) | training_weight | OBSERVED_HISTORICAL | not_applicable | `sales_history` | block |
| `external_indicators` (columnas `factor__<clave>`) | external | OBSERVED_HISTORICAL | carried_last_visible_with_staleness | `external_indicators` | disable (**no habilitada por defecto**) |

Reglas de cada clase tal como las declara el registro:

- **Lags y medias móviles**: `OBSERVED_HISTORICAL` *as-of* — ventas leídas de `sales_line_versions` con la versión vigente al corte (`sale_date ≤ C` y
  `known_from < medianoche local de C+1`, ADR-V25-23); los días sin fuente o cerrados no son ceros (ADR-V25-03). MLForecast los recalcula de forma recursiva con sus propias
  predicciones: solo usa el pasado del corte.
- **Calendario y feriados**: `KNOWN_IN_ADVANCE` con `known_at = statutory_rule`: la fecha es función de la ley (Código del Trabajo y LOSEP vía `holidays`)
  o de la convención comercial, no de una publicación posterior. **Límite honesto**: los traslados extraordinarios por decreto («puentes») no son predecibles y no se modelan; entrarían como
  `calendar_events` con su propio `known_at`.
- **Promociones**: `KNOWN_IN_ADVANCE`. Para fechas ≤ corte cuentan tal como ocurrieron; para fechas > corte solo las conocidas al corte (`promotions.known_at`, o `created_at`, y la revisión
  vigente ese día). Sin ninguna fecha, no se usan a futuro. (Regla anterior de W1/promociones: `test_promotions_as_of.py`.)
- **Precio**: ver §4.
- **Indicadores macro**: `OBSERVED_HISTORICAL` con rezago de publicación: `COALESCE(available_at, GREATEST(published_at, ingested_at))`. Desactivado por defecto (§5).

Cobertura del registro — IMPLEMENTADO / VERIFICADO: `test_feature_contracts.py::test_every_feature_declared_by_the_builder_has_a_contract` (todo lo declarado en el builder),
`::test_every_column_the_builder_and_mlforecast_actually_produce_has_a_contract` (columnas reales de `training_frame`, `future_frame` **y las que MLForecast genera al ajustar el modelo**),
`::test_the_contract_classes_follow_the_package_specification`.

**Una feature sin contrato hace fallar la construcción** — IMPLEMENTADO / VERIFICADO. `FeatureBuilder.training_frame` y `future_frame` llaman a `validate_feature_columns` sobre las columnas
que devuelven; una columna sin contrato lanza `FeatureContractError` con su nombre. Pruebas: `test_a_column_without_contract_is_rejected_by_name`,
`test_building_the_frames_fails_when_a_declared_feature_loses_its_contract` (se retira el contrato de `price_ratio`) y
`test_building_the_frames_fails_when_a_new_feature_is_added_without_a_contract` (se añade `precio_competencia` al builder sin contrato).

**Alcance del registro (límites)** — el registro cubre el **motor de demanda** (`features/builder.py` y MLForecast). **NO cubre** las features del Customer Core
(`customer/features.py`, que tienen su propia vista as-of por corte, ADR-V25-23) ni las del módulo de abastecimiento y costos: DISEÑADO / NO IMPLEMENTADO.

## 4. Precio futuro: supuesto `frozen_price` o precio confirmado

El precio futuro **no se conoce**. Por defecto, en la predicción y en cada ventana de backtest se **congela** en el último precio neto observado hasta el corte
(`PRICE_ASSUMPTION`: «último precio neto promedio observado (28 días) hasta el corte»): supuesto explícito, declarado en el contrato (`future_mode = frozen_price`).

Excepción: un **precio futuro confirmado** en `product_price_history` cuyo `known_at` sea visible al corte (`known_at < medianoche local de C+1`, regla de W1) es
`KNOWN_IN_ADVANCE` y reemplaza al supuesto en esos días (`FeatureBuilder(confirmed_prices=...)`). Se usa el **cociente** entre el precio confirmado del día y el precio vigente al
corte **del mismo historial**, no el precio absoluto, para no mezclar precio de lista y precio neto observado; sin precio vigente al corte no hay base y rige `frozen_price`. Un `known_at`
nulo o posterior al corte excluye el precio. `future_price_info` informa cuántas series usan un precio confirmado distinto del vigente.

IMPLEMENTADO / VERIFICADO: `test_feature_contracts.py::test_a_confirmed_future_price_replaces_frozen_price_only_when_it_was_known_at_the_cutoff` (conocido → cambia; conocido después,
`known_at` nulo, sin precio base o de otra sucursal → no cambia; el pasado y las demás features no cambian),
`::test_the_known_at_of_a_confirmed_price_follows_the_local_midnight_boundary` (23:59:59 locales del día del corte sí; medianoche siguiente no) y
`test_leakage.py::test_features_do_not_use_future_data` (el precio posterior al corte no influye).
`FactorsRepository.price_history()` lee la tabla con su `known_at`
(`test_factors_t11_integration.py::test_the_price_history_of_the_company_is_read_with_its_known_at`).

**NO CONECTADO**: `run_pipeline` aún no lee `product_price_history` ni pasa `confirmed_prices` al builder; hoy rige siempre `frozen_price` en producción. Es una conexión de dos
líneas en `pipeline/data.py` y `pipeline/core.py` (fuera del alcance de este paquete; ver «Pendientes»).

## 5. Datos requeridos y DEGRADE

`required_data` usa un **vocabulario cerrado** (`DATASETS`); es el mapa feature → datos que consume el gate de calidad (W2-C, `docs/data/DATA_QUALITY_ENGINE.md` §6–§7):

| Token | Qué mide el gate | Features que lo exigen |
|---|---|---|
| `sales_history` | historia diaria de ventas as-of por serie | lags, medias móviles, `series_level`, `weight` |
| `price` | cobertura de precio neto observado | `price_ratio` |
| `promotions` | promociones con `known_at` y revisiones | `promo_discount` |
| `branches` | sucursales | `branch_code_num` |
| `branch_region` | región/cantón de la sucursal | `region_num` (y, opcional, el calendario) |
| `product_category` | categoría del producto | `category_num` |
| `external_indicators` | observaciones de indicadores con fechas de disponibilidad | `external_indicators` / `factor__*` |
| `confirmed_future_price` | precios futuros confirmados con `known_at` | opcional de `price_ratio` |

`degrade_plan(datasets_no_disponibles)` devuelve, para los datasets que el gate declare insuficientes: `disable` (features **opcionales** a omitir: `price_ratio`, `promo_discount`, factores),
`neutral` (se conservan con el valor neutro documentado, p. ej. `region_num = 0`, y se registran como limitación) y `blocked` (imprescindibles: sin ellas no puede construirse el modelo).
`FeatureBuilder(disabled_features=[...])` omite las columnas de las features opcionales y **falla** si se pide omitir una imprescindible; `FeatureBuilder.options()` reenvía la política a cada
ventana del backtest.

IMPLEMENTADO / VERIFICADO: `test_feature_contracts.py::test_required_data_uses_a_closed_vocabulary_and_maps_every_feature`,
`::test_degrade_plan_separates_optional_neutral_and_indispensable_features`, `::test_disabling_optional_features_drops_their_columns_and_leaves_the_rest_intact`,
`::test_default_options_leave_the_frames_exactly_as_they_were`.
**DISEÑADO / NO IMPLEMENTADO en este paquete**: la decisión (qué dataset es insuficiente, con qué umbral) y el registro en el manifiesto y en `limitations` son de W2-C; el motor
no deshabilita features por sí solo.

## 6. Indicadores externos, eventos y la separación público/privado

`engine/predictily_engine/db/factors_repository.py` (`FactorsRepository`, accesible desde `TenantRepository.factors()`) lee `external_indicator_observations` con las columnas
nuevas, `external_events`, `factor_definitions`, las campañas de `calendar_events` y `product_price_history`. Toda consulta incluye `:tenant_id` (`_read` rechaza SQL sin él).

**Resolución por identificador con precedencia explícita** (corrige R-178). `external_indicators` admite un indicador público y otro de la empresa con el mismo código. Dado un
`indicator_id` se lee exactamente ese indicador (el de otra empresa no existe para la actual). Dado un código: un solo candidato → ese; **dos** (público y privado) → hace falta
`precedence = 'tenant' | 'public'` declarada (`factors.indicator_precedence`); sin ella se lanza `AmbiguousIndicatorError`. **Nunca se combinan en una serie.**
`features.external.load_observations` (adaptador heredado) delega en este lector y ya no mezcla.

IMPLEMENTADO / VERIFICADO: `test_factors_t11_integration.py::test_public_and_private_indicators_with_the_same_code_are_never_combined` (publicaciones intercaladas),
`::test_inactive_indicators_and_foreign_definitions_are_not_visible`, `::test_an_ambiguous_indicator_is_dropped_unless_the_precedence_is_declared`.

**Campañas de calendario** (`calendar_events`, identificadas por `factor_definitions.schema.event_code`). `FactorsRepository.calendar_observations` aplica tres reglas:
- **Origen**: un código puede existir como evento público (tenant NULL) y de la empresa. Con ambos hace falta una precedencia explícita (`factors.calendar_precedence[código]`, o `definition_precedence[clave]` como respaldo);
  sin ella `AmbiguousCalendarError` y el factor sale con el motivo `ambiguous_calendar`. Las revisiones de orígenes distintos **nunca se mezclan**: solo se devuelven las del origen elegido.
- **Alcance**: solo `country`, `tenant` y `sector` (los mismos que acepta `demand.lifecycle.flag_events`). Las filas de `branch`, `product`, `category` o `region` se **descartan con el motivo `scope_unsupported`** (quedan en
  `frame.attrs['discarded']` y en `FactorResolution.discarded` / `report()['discarded']`); si no queda ninguna, el factor sale con `scope_unsupported`. No se aplican en silencio a toda la empresa.
- **Identidad**: dentro del origen elegido debe quedar un único (`scope`, `scope_ref`); con varios, `AmbiguousCalendarError` salvo que `schema.scope` / `schema.scope_ref` de la definición lo acoten.
IMPLEMENTADO / VERIFICADO: `test_factors_t11_integration.py::test_t11_public_and_private_calendar_events_with_the_same_code_are_never_combined`,
`::test_t11_calendar_events_with_an_unsupported_scope_are_discarded_with_a_reason`, `::test_t11_calendar_events_accept_country_tenant_and_sector_but_never_mix_their_identities`.

**Eventos** (`external_events`): sin `published_at` son `INVALID` (`missing_published_at`) en cualquier estado; solo un evento `VERIFIED` (o `EXPIRED` con `verified_at` y `verified_by` registrados: verificado y vencido después), publicado, disponible (`COALESCE(available_at,
GREATEST(published_at, created_at))`) y **verificado** antes del corte es `AVAILABLE`; `REJECTED` y `PROPOSED` son `INVALID`; una vigencia (`effective_to`) vencida antes de la fecha de uso es
`STALE`; una alerta informativa se evalúa en el tiempo pero **no es una feature predictiva** (`usable_as_feature` falso). No hay historial de estados: se usa `verified_at`; un `VERIFIED` sin
`verified_at` es `INVALID` en una reconstrucción retroactiva. Un `EXPIRED` se alcanza también desde `PROPOSED` sin pasar por `VERIFIED` (venció sin revisarse): sin `verified_at` Y `verified_by` es
`INVALID` (`event_not_verified`) **en cualquier modo**, vigente o retroactivo. IMPLEMENTADO / VERIFICADO:
`::test_an_expired_event_never_verified_is_not_available_in_any_run_kind`, `test_availability_validator.py::test_an_event_without_publication_date_is_invalid_whatever_its_status`,
`::test_only_a_verified_event_known_verified_and_published_before_the_cutoff_is_available`, `::test_an_expired_event_is_stale_and_an_informative_alert_is_not_a_predictive_feature`,
`::test_a_verified_event_without_verification_date_depends_on_the_run_kind`.

## 7. Pipeline de factores desactivable

Dos condiciones, ambas necesarias, para que un factor cuente (**decisión del arquitecto**):

1. **Interruptor global del pipeline**: bloque `factors` de `engine/predictily_engine/platform/config.py` (core → perfil → `settings.cores.factors` de la empresa, validado como el resto): `enabled = false` **por defecto**,
   `default_max_staleness_days`, `staleness_days_by_frequency`, `indicator_precedence`, `calendar_precedence`, `definition_precedence`. Los mapas anidados se validan en `validate_overrides` (`factor_config_errors`).
2. **Activación por factor de la empresa**: `tenants.settings.factor_activation.<clave>.active = true`, escrita por el backend con `PUT /api/v1/intelligence/factors/{clave}/activation` (W2-F). Es la **única** fuente de verdad de
   qué factores activa una empresa. La clave `active_factors` del bloque `factors` se **eliminó** (`settings.cores.factors.active_factors` ahora se rechaza como clave desconocida): no hay dos fuentes de verdad. Una entrada mal formada
   (que no sea un objeto con `active` literalmente verdadero) cuenta como no activa; las claves activas se ordenan alfabéticamente.

`factor_definitions.is_active` **no interviene** (decisión): en el catálogo público es el «ninguna familia activa por defecto» y la elección de la empresa vive en `factor_activation`; una definición con `is_active = false` activada por la empresa
entra, y una con `is_active = true` no activada no entra (`test_the_pipeline_follows_the_company_activation_and_not_the_catalog_flag_is_active`).

`FactorPipeline.from_settings(tenants.settings, sector).resolve(repo, fechas, corte)`:

- **Apagado** (o sin factores activos): no consulta ninguna tabla de factores y no entra ninguna columna al modelo; los marcos de entrenamiento y de predicción son idénticos a los de un motor sin factores.
- **Encendido**: por factor activo lee su almacenamiento, valida cada fecha con el validador y solo entra al modelo un factor utilizable en el corte **y en todos los días del horizonte**
  (un día sin valor conocido, `no_value_for_date` —p. ej. un día sin campaña—, queda en NaN y no cuenta como falla de la fuente). Un factor `STALE`
  (API caída) con `disable_feature`, `NOT_YET_AVAILABLE`, `INVALID`, escenario o sin historial de emisiones queda **fuera** (respaldo = modelo sin el factor) y `FactorResolution.dropped` lo explica
  con motivo, antigüedad y primera fecha no utilizable; un `STALE` con `last_valid_with_age` entra etiquetado. Las filas de entrenamiento usan lo conocido **cada día** (una revisión posterior no reescribe el pasado).
  Los escenarios (`USER_SCENARIO`) **nunca** llegan al marco.
- **Cada ventana del backtest usa lo conocido en SU corte**: `FactorResolution.columns_at(corte)` recalcula las columnas con el corte pedido (`FeatureBuilder.training_frame/future_frame(corte)` lo usan); el futuro de una ventana
  anterior no ve publicaciones posteriores a su corte. El conjunto de columnas es el del corte final (un factor aún sin valor utilizable en una ventana temprana queda en NaN en ella). Las columnas viajan al modelo como exógenas dinámicas de MLForecast
  (`test_a_resolved_factor_column_trains_and_predicts_through_mlforecast`). **Solo el corte final puede ser vigente**: todo corte anterior se evalúa como RETROACTIVO (lo de disponibilidad desconocida, o un pronóstico sin historial de emisiones,
  no se usa en una ventana aunque el corte final, vigente, lo aceptara); un corte posterior al final lanza `ValueError`
  (`test_the_windows_before_the_final_cutoff_are_always_retroactive_even_when_the_final_run_is_live`).
- **Una columna sin ningún valor se descarta**: si el factor no tiene un solo valor utilizable en el rango, sale en `dropped` con el motivo `no_values` en lugar de figurar como «usado»
  (`test_a_factor_whose_column_has_no_values_is_dropped_not_reported_as_used`).
- **Definiciones**: una clave presente como definición pública y de la empresa exige `definition_precedence[clave]` (sin ella: `ambiguous_definition`); un almacenamiento sin lectura en el pipeline (`sales`, `promotions`, …) o una definición sin
  indicador/evento salen con `storage_unreadable` (`test_definitions_ambiguity_precedence_and_unreadable_storage_are_reported_not_silently_resolved`).

IMPLEMENTADO / VERIFICADO: `test_factors_config.py` (estado apagado por defecto, precedencia, validación, el resolvedor apagado no toca el repositorio),
`test_factors_t11_integration.py::test_with_the_pipeline_switched_off_no_external_factor_enters_and_nothing_is_queried` (cuenta las sentencias SQL: cero),
`::test_with_the_pipeline_on_only_usable_factors_enter_and_the_rest_fall_back_to_the_model_without_them`,
`::test_a_factor_that_is_stale_at_the_cutoff_leaves_the_frames_identical_to_the_model_without_it`,
`::test_the_training_rows_of_a_factor_use_what_was_known_each_day_not_the_latest_revision`,
`::test_the_future_of_an_earlier_backtest_window_only_sees_what_was_published_before_its_cutoff`, `::test_a_known_in_advance_campaign_enters_with_values_only_on_its_days`,
`test_feature_contracts.py::test_resolved_factor_columns_enter_the_frames_only_with_a_contract`.
El único interruptor previo de una familia (`demand.exceptional_events_flag`) tiene ahora su prueba del estado apagado
(`test_exceptional_events_switch.py`: apagado = pronóstico idéntico al de una empresa sin eventos; encendido = mismas cifras, solo etiquetas y calidad).

**NO CONECTADO a `run_pipeline`** — DISEÑADO / NO IMPLEMENTADO: el pipeline de factores existe como API (`FactorPipeline` → `FeatureBuilder(factors=...)`) pero `run_pipeline` no lo invoca
(`pipeline/data.py` no es de este paquete). Con los valores por defecto ninguna métrica de la demo ni de los E2E cambia porque ningún factor entra al modelo (verificado: las suites existentes de demanda siguen en verde).
**Con `factors.enabled = true` la ejecución de demanda registra la limitación `factor_pipeline_not_connected`** (`factor_pipeline_limitations`, llamada desde `pipeline/core.py`): ningún factor entró al modelo, con independencia de
`factor_activation`; una configuración de factores inválida no detiene la demanda, se registra `factors_config_invalid` y se trata como apagado. IMPLEMENTADO / VERIFICADO:
`test_factors_config.py::test_the_limitation_is_recorded_only_when_the_pipeline_is_enabled_but_not_connected`, `::test_a_demand_run_with_the_pipeline_enabled_reports_that_it_is_not_connected_and_changes_nothing`
(mismos valores de pronóstico con el pipeline encendido que con él apagado). Una vez conectado, la constante `PIPELINE_CONNECTED_TO_RUN_PIPELINE` debe pasar a verdadera. **BLOQUEADO / P2**: conectores
reales (INEC, clima, competencia…), ablation por familia y evaluación sectorial — hoy no hay ninguna integración externa activa.

**Una feature sin contrato no se disfraza de fallo de LightGBM**: `pipeline/core.py` (entrenamiento final) y `backtest/rolling.py` (cada ventana) relanzan `FeatureContractError` en lugar de servir el modelo de respaldo; un fallo real de LightGBM
sigue tolerándose. IMPLEMENTADO / VERIFICADO: `test_feature_contracts.py::test_a_feature_contract_error_in_a_backtest_window_is_not_disguised_as_a_lightgbm_failure`,
`::test_a_feature_contract_error_in_the_final_training_stops_the_run_instead_of_serving_the_fallback`.

## 8. Cómo añadir una feature

1. Añádala al builder y **declare su contrato** en `availability/contracts.py` (clase, fuente, `known_at`, `future_mode`, datos requeridos, `on_missing`). Sin contrato, `training_frame` falla.
2. Si MLForecast la genera con otro nombre, indíquelo en `produced_as`.
3. Suba `FEATURE_CONTRACTS_VERSION`, actualice la huella en `test_feature_contracts.py` y esta tabla.
4. Si necesita un dataset nuevo, añádalo a `DATASETS` y avise a W2-C para que el gate lo mida.

## 9. Pendientes y límites declarados

| Pendiente | Estado |
|---|---|
| Conectar `FactorPipeline` y `confirmed_prices` a `run_pipeline`/`load_tenant_data` (y reenviar `FeatureBuilder.options()` en `asof_windows`) | DISEÑADO / NO IMPLEMENTADO (archivos de otros paquetes) |
| Contratos para las features del Customer Core, abastecimiento y costos | DISEÑADO / NO IMPLEMENTADO |
| Unificar la frontera de `extensions/data_core.py::load_calendar_events` (fin del día UTC) con la medianoche local | DISEÑADO / NO IMPLEMENTADO (decisión del propietario) |
| Almacenamientos nativos (`sales`, `promotions`, `commercial_commitments`, `inventory_snapshots`) como factores del pipeline | DISEÑADO / NO IMPLEMENTADO: tienen su propia lectura as-of |
| Antigüedades máximas por frecuencia | Valores iniciales **a aprobar en el piloto** |
| Subir `FEATURE_SET_VERSION` (`fs-2026.10-v2`) para que las filas de `forecast_features` de BDs existentes recojan el contrato | **PENDIENTE, no se sube**: la versión entra en `effective_config.run` (huella `config_version` de TODA ejecución), en los snapshots de demanda (`demand/snapshot.py`), en `compat` del registro de modelos y en los manifiestos; subirla cambiaría esas huellas e invalidaría la compatibilidad de los modelos registrados. Es una decisión del arquitecto con una migración de datos; el contrato vive en el código (`FEATURE_CONTRACTS_VERSION`, huella fijada por prueba) y `ensure_feature_registry` solo escribe las filas de una versión nueva |
| Registrar `contracts_digest()` y los estados de los factores en el manifiesto / snapshot de la ejecución | DISEÑADO / NO IMPLEMENTADO (archivos de otros paquetes) |
| Uso de `filter_for_accuracy` en la medición de precisión | DISEÑADO / NO IMPLEMENTADO: no hay métricas que consuman factores hasta la ablation (P2) |
| Conectores externos, histórico de emisiones real, ablation (T12) | BLOQUEADO / P2 |

## 10. Decisiones y límites adicionales del lector y del validador

- **Alcance de los indicadores (`scope_ref`)**: `FactorsRepository.indicator_observations` lee por defecto solo las filas del alcance del indicador (`scope_ref IS NULL`); un alcance concreto o `ALL_SCOPES` se piden explícitamente. El pipeline
  de factores no distingue alcances regionales dentro de un mismo indicador: DISEÑADO / NO IMPLEMENTADO (un factor regional necesita una definición por alcance).
- **Instantes sin zona**: se interpretan como UTC (igual que `data.asof`). Los `timestamptz` de PostgreSQL llegan con zona y se convierten a UTC; el día de negocio es el día local de `tenants.timezone`.
- **`retroactive` por defecto**: el validador y el pipeline asumen reconstrucción retroactiva (lo que no puede demostrarse conocido no se usa). Una ejecución vigente debe pedirlo (`retroactive=False`) y solo vale para el corte final.
- **Valores**: las observaciones se leen como `float8` (`numeric(20,6)` en la tabla); el valor visible es un escalar por fecha. No hay series multivariantes ni valores textuales.
- **Vigencia (`valid_from` / `valid_to`)**: para `KNOWN_IN_ADVANCE` y `THIRD_PARTY_FORECAST` define las fechas que cubre una fila; para `OBSERVED_HISTORICAL` solo `valid_to` caduca (STALE `validity_expired`), `valid_from` se ignora.
- **Calendario y cancelaciones**: una revisión `cancelled` de un evento vale 0 desde que se conoce; la identidad del evento es (origen, `scope`, `scope_ref`, código) y las revisiones se ordenan por número de revisión.
- **Pronósticos de terceros en entrenamiento**: la fila de entrenamiento de un día usa el pronóstico vigente la víspera (`día − 1`); un entrenamiento por horizonte de emisión (lead) es DISEÑADO / NO IMPLEMENTADO (P2).
