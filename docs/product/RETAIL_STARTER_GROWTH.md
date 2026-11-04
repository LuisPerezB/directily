# Planes Retail (Starter · Growth), Básico y Enterprise — catálogo de horizontes y límites configurables

Decisiones: `docs/architecture/adr/V25-ADRS.md` (ADR-V25-02 · catálogo de horizontes y ADR-V25-18 · planes comerciales
configurables por tenant). Fuente comercial: `docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md`
(§Modelo comercial). Paquete: W2-E.

**Qué es este documento.** Starter (USD 49) y Growth (USD 119) son **hipótesis heredadas y opcionales**: se conservan como
capacidad configurable, **no** como estrategia obligatoria de lanzamiento. El foco comercial es Enterprise (pocos clientes,
alta integración, contrato personalizado). Nada aquí obliga a vender Starter/Growth ni condiciona el producto a un precio.

Estados de cada apartado: **IMPLEMENTADO/VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO/NO IMPLEMENTADO**,
**BLOQUEADO**, **NO APLICA**. Los topes numéricos que la fuente no fija son supuestos: se marcan «**a aprobar en el piloto**».

## 1. Regla de oro: un precio nunca es un límite

- Los precios de referencia viven en `plans.monthly_price_usd` y en `plans.metadata.reference_price_usd` (columna `jsonb`
  agregada por `2026_03_10_000100_add_metadata_to_plans.php`). Son datos **informativos**.
- `plans.limits` y `tenants.plan_overrides` solo aceptan claves de capacidad (topes, horizontes, capacidades). El
  superadministrador no puede guardar un precio como límite: `PATCH /admin/tenants/{uuid}` rechaza (422) cualquier clave fuera
  de `predictily.plan_limit_keys`.
- **IMPLEMENTADO/VERIFICADO**: `CommercialPlansTest::test_no_price_is_ever_a_limit` (ninguna clave de límite contiene un precio y
  cambiar el precio informativo no cambia ningún límite efectivo) y `::test_the_superadmin_sets_contract_limits_and_invalid_overrides_are_rejected`.
- **NO APLICA**: pasarela de pagos, facturación y cobro. Los planes se asignan manualmente por el superadministrador.

## 2. Catálogo único de horizontes

`[7, 14, 15, 30, 60, 90]`, declarado una sola vez en cada lado y verificado por pruebas:

| Dónde | Declaración |
|---|---|
| Backend | `predictily.horizons.catalog` (`backend/config/predictily.php`); acceso por `App\Support\Horizons\HorizonCatalog`. `config/engine.php` ya no tiene lista propia. |
| Motor | `predictily_engine.platform.config.HORIZONS` (bloque `horizons`); de él derivan `demand.horizons.SUPPORTED_HORIZONS`, `customer.SUPPORTED_HORIZONS` y los valores por defecto y restricciones de `resolve_config`. |
| Contrato | `docs/openapi/*.yaml`: todas las enumeraciones de horizonte son el catálogo (demanda) o el subconjunto de Customer. |
| Fuente compartida | `docs/product/horizons.contract.json`: backend y motor se comparan **cada uno contra este archivo** sin depender del otro entorno de ejecución. |

- **Demanda** acepta todo el catálogo. **Customer** acepta `7/14/15/30/90`. **60 queda fuera de Customer** (decisión): ninguna fuente lo
  pide para compra (V2.2: recompra 7/14/30/90; V2.3/Enterprise: 7/15/30) y cada horizonte añade un modelo por objetivo (más costo
  de entrenamiento). Habilitarlo exige agregarlo en `HORIZONS["customer"]` y en `predictily.horizons.customer`; la prueba de
  coincidencia lo obliga a hacerlo en ambos lados.
- El catálogo dice qué horizontes **existen**; el plan dice cuáles puede **usar** cada empresa. Un horizonte fuera del catálogo nunca
  se autoriza aunque un plan lo traiga por error (`PlanLimitService::limits()` lo descarta).
- **IMPLEMENTADO/VERIFICADO**: `HorizonCatalogTest` (backend: catálogo, subconjunto, coincidencia con el motor leyendo
  `engine/.venv`, y ninguna lista literal de horizontes fuera de la config) y `engine/tests/unit/test_horizon_catalog.py` (motor:
  derivaciones, validación de configuración, coincidencia con `backend/config/predictily.php` leída con PHP y OpenAPI sin el
  catálogo antiguo). Además, `HorizonCatalogTest::test_the_backend_matches_the_shared_horizon_contract_without_needing_the_engine` y
  `test_horizon_catalog.py::test_the_engine_matches_the_shared_horizon_contract` comparan con `horizons.contract.json` y **nunca se omiten**; la
  comparación cruzada directa (backend ↔ motor, leyendo el otro runtime) se conserva y solo se omite si ese runtime no está instalado.

### Subconjuntos por plan

| Plan | Demanda (`limits.horizons`) | Customer (plan ∩ 7/14/15/30/90) |
|---|---|---|
| Prueba (`trial`), Profesional, Empresarial (heredados) | 7/14/30/60/90 | 7/14/30/90 |
| **Básico** (heredado, **intacto**) | 7/14/30 | 7/14/30 |
| **Starter** | 7/30 | no incluido (`features.customer = false`) |
| **Growth** | 7/15/30 | 7/15/30 |
| **Enterprise** | 7/15/30 por defecto; catálogo completo habilitable con `plan_overrides.horizons` | 7/15/30 (con el catálogo completo: 7/14/15/30/90) |

Los planes heredados **conservan los suyos** (ADR-V25-02): habilitar 15 en ellos es un dato (`plan_overrides`), no un cambio de código.
Efecto a tener en cuenta: como el catálogo de Customer ahora incluye 14, los planes heredados que ya tenían 14 en demanda
(Básico, Prueba, Profesional, Empresarial) lo obtienen también en Customer; los modelos de Customer ya entrenados no tienen 14, así
que la siguiente predicción diaria se abstiene de 14 (`no_model`, calidad `degraded`) hasta el siguiente entrenamiento (§6).

## 3. Planes

Los planes son filas de `plans` sembradas desde `config/predictily.php` por `PlanSeeder` (`php artisan db:seed --class=PlanSeeder`).
Las claves ausentes de un plan **heredan las del plan por defecto** (`trial`, `PlanLimitService::limits`); por eso los planes nuevos
declaran todas sus claves (`null` = sin tope del plan).

| Límite | Básico | Starter | Growth | Enterprise (techo de partida) |
|---|---|---|---|---|
| `max_branches` | 2 | **1** | **5** | 200 |
| `max_series` (sucursal × producto con ventas en 90 días) | — | **750** | **5.000** | 100.000 |
| `max_active_products` | 1.500 | 750 | sin tope | 200.000 |
| `max_import_rows_per_month` | 300.000 | 300.000 (*) | 3.000.000 (*) | 30.000.000 |
| `max_forecast_runs_per_day` | 2 | 2 (*) | 10 (*) | 50 |
| `max_users` | 3 | 2 (*) | 10 (*) | 200 |
| `features.api_access` | no | no | sí | sí |
| `features.customer` | (incluido) | **no** | **sí** | sí |
| `features.roles` | — | no | sí | sí |
| `features.scenarios` | no | no | sí | sí |
| `features.custom_models` | no | no | no | sí |

**Serie activa y su conteo (aproximación documentada).** `max_series` cuenta pares sucursal × producto con ventas válidas en los últimos 90 días (huso de
la empresa). Es un `DISTINCT` sobre las ventas, así que se acota: (1) **caché por empresa y día** (`predictily.series_count.cache_seconds`, 600 s): una serie
nueva puede tardar hasta ese tiempo en contarse, salvo que termine una importación de ventas (`recordUsage(import_rows)` invalida la entrada del día);
(2) `SET LOCAL statement_timeout` (`predictily.series_count.timeout_ms`, 5 s) dentro de la transacción de la consulta; (3) si se excede el tiempo se usa el último valor
conocido, y si no lo hay **el tope no se exige en esa petición** (falla abierto, con un aviso en el log): una consulta lenta nunca bloquea un pronóstico. El listado de
administración **no** mide series (`usage.series = null`; evita hasta 100 consultas por página); `GET /tenant/usage` y el detalle tras editar una empresa sí, con caché.
Pruebas: `SeriesCountTest` (7: caché, invalidación, timeout local, SQLSTATE, respaldo, listado sin consultas de ventas, uso).

**Capacidades (`features`).** Una capacidad ausente se interpreta como incluida (`customer`, `assistant`), pero los planes heredados ahora **declaran
`customer => true` de forma explícita** (mismo comportamiento; el Básico no cambia en horizontes, topes ni precio). Un override vacío (`features: {}` o `[]`) significa
«sin cambios» (`PlanLimitService::mergeLimits`) y el admin rechaza una lista con elementos: no se puede recuperar una capacidad negada borrando el mapa. Para quitar una
capacidad se pone en `false`.

(\*) Valor operativo **supuesto** (la fuente no lo fija): **a aprobar en el piloto**. Las cifras de la fuente son: Starter 1 sucursal,
≤ 750 series, CSV/Excel, 7/30; Growth ≤ 5 sucursales, ≤ 5.000 series, 7/15/30, Customer si IDs confiables, API, usuarios/roles. Los
techos de Enterprise son una referencia técnica de partida (los mismos del plan Empresarial), no una oferta: cada contrato los fija.

### Precios de referencia (informativos)

| Plan | `monthly_price_usd` | `metadata.reference_price_usd` |
|---|---|---|
| Starter | 49 | `monthly: 49` |
| Growth | 119 | `monthly: 119` |
| Enterprise | `null` | implementación única USD 1.500–8.000 · suscripción mensual USD 1.000–3.000 (rangos orientativos) |

### Estado por requisito

| Requisito | Estado | Evidencia |
|---|---|---|
| Básico intacto (7/14/30, mismos topes y precio; solo declara `features.customer = true`, que ya tenía por omisión) | IMPLEMENTADO/VERIFICADO | `CommercialPlansTest::test_the_basic_plan_is_exactly_what_it_was`; `PlanHorizonSchedulingTest` (3 pruebas) |
| Starter, Growth y Enterprise como planes configurables | IMPLEMENTADO/VERIFICADO | `CommercialPlansTest::test_starter_growth_and_enterprise_are_configurable_plans_with_the_documented_hypotheses` |
| El programador usa el mayor horizonte del plan | IMPLEMENTADO/VERIFICADO | `CommercialPlansTest::test_the_largest_allowed_horizon_of_each_plan_is_what_the_scheduler_asks_for` (Básico/Starter/Growth/Enterprise → 30; Enterprise con catálogo → 90; sin horizontes → null) |
| 15 aceptado solo por los planes que lo incluyen | IMPLEMENTADO/VERIFICADO | `CommercialPlansTest::test_horizon_15_is_accepted_only_by_the_plans_that_include_it` |
| `max_branches`, `max_users`, `max_active_products`, `max_import_rows_per_month`, `max_forecast_runs_per_day`, `horizons` | IMPLEMENTADO (previo) | `PlanLimitTest`, `CatalogTest`, `SalesImportTest`, `ForecastRunApiTest` |
| `max_series` | IMPLEMENTADO/VERIFICADO | se exige al solicitar un pronóstico (402 `max_series`, documentado en `analytics.yaml`); conteo cacheado y con tiempo acotado (ver arriba); `CommercialPlansTest::test_active_series_over_the_plan_cap_block_a_new_forecast_run`, `::test_series_outside_the_window_do_not_count_and_plans_without_a_cap_never_query` y `SeriesCountTest`. La definición de «serie activa» (ventas válidas en 90 días) es un supuesto **a aprobar en el piloto**. No bloquea importaciones ni la creación de productos. |
| `features.customer` (Starter sin Customer) | IMPLEMENTADO/VERIFICADO | todo el Customer Core responde 402 `features.customer`; la interfaz oculta la sección: `CommercialPlansTest::test_starter_has_no_customer_core_and_every_customer_endpoint_says_so`, `CustomersView.spec.ts` |
| «Customer si IDs confiables» (Growth) | DISEÑADO (condición de datos, no del plan) | el plan lo habilita; el motor se abstiene si no hay compradores identificados (`no_identified_buyers`) y excluye los códigos genéricos. No existe una puntuación de «confiabilidad de IDs» propia: **a definir en el piloto**. |
| `features.roles` | DISEÑADO/NO IMPLEMENTADO | se declara y se muestra, pero los roles (owner/admin/analyst/viewer) existen en todos los planes: ninguna regla los condiciona por plan |
| Starter solo CSV/Excel | PARCIAL | `features.api_access = false` impide crear tokens de API (IMPLEMENTADO, `DataSourceController::createToken`); el resto de conectores (SQL) **no** se restringe por plan: DISEÑADO/NO IMPLEMENTADO |
| «Bridge si compatible», agenda, comparación (Growth) | NO APLICA a este paquete | no existen como módulos; no se declaran capacidades inexistentes |
| Límites Enterprise por contrato: series, usuarios, sucursales, horizontes, capacidades | IMPLEMENTADO/VERIFICADO | `plan_overrides` validado y aplicado: `CommercialPlansTest::test_the_superadmin_sets_contract_limits_and_invalid_overrides_are_rejected` |
| Límites Enterprise por contrato: módulos, frecuencias, retención, conectores, cómputo, LLM | DISEÑADO/NO IMPLEMENTADO | `plan_overrides` los acepta y guarda (`predictily.plan_limit_keys.declared`), pero **ningún módulo los consulta todavía** |
| Procesamiento privado | DISEÑADO/NO IMPLEMENTADO | se puede declarar como capacidad (`features.private_processing`); ver ADR-V25-19 para el CLI privado |
| Pasarela de pagos | NO APLICA | fuera de alcance (ADR-V25-18) |

## 4. Configurar una empresa

```text
PATCH /api/v1/admin/tenants/{uuid}
{ "plan_code": "enterprise",
  "plan_overrides": { "horizons": [7, 14, 15, 30, 60, 90], "max_series": 20000, "max_users": 40,
                      "max_branches": null, "features": { "private_processing": true },
                      "modules": ["demand", "supply"], "retention_months": 48 } }
```

Las listas (`horizons`, `modules`...) se **reemplazan**; los mapas (`features`) se combinan clave a clave; `null` en un tope = sin tope.
Solo el superadministrador lo hace y queda en auditoría (`admin.tenant_updated`). La interfaz (Configuración → Plan) muestra
horizontes, topes, series activas y capacidades.

## 5. Abstención por horizonte

Un horizonte sin evidencia suficiente devuelve **`quality_status = insufficient_data`**, nunca un número fabricado (ADR-V25-02):

- Demanda: el número se calcula pero se marca `insufficient_data` fila por fila, sin intervalos, y la interfaz marca el horizonte en
  el selector con su motivo. Detalle en `docs/04-estrategia-modelado.md` §4.
- Customer: un horizonte sin modelo utilizable no tiene filas; la API responde 422 `horizon_not_available` con
  `quality_status = insufficient_data` y `reason_code`; la corrida registra `summary.horizon_support`. **Compatibilidad**: en el
  *entrenamiento* el estado del horizonte sigue siendo `abstained` (manifiesto y resumen, con `reason_code`); `insufficient_data` es el
  estado en la predicción y la API. Una corrida anterior a W2-E no guardó el motivo: la API informa `unknown_legacy_run`, no `no_model`.
- Vocabulario unificado: `insufficient_data` es el **estado**; `coverage_insufficient`, `insufficient_horizon`, `no_model`,
  `model_unusable`, `no_identified_buyers` son **códigos de motivo** (`reason_code`). Los códigos históricos (`history_lt_2h`,
  `no_backtest`, `backtest_horizon_lt_h`, `no_evaluated_observations`) se conservan en `code`.
- `coverage_insufficient` en `supply` y los escenarios (`inventory/recommendations.py`, `ScenarioSimulator`) es el mismo concepto de
  cobertura insuficiente del pronóstico y no cambia.

## 6. Operación y migración

1. `php artisan migrate` (agrega `plans.metadata`) y `php artisan db:seed --class=PlanSeeder` (crea Starter/Growth/Enterprise y
   deja intactos los planes heredados salvo `metadata = null`).
2. Reentrenar Customer después de actualizar si la empresa ahora incluye 14 o 15 (`POST /customer/runs` con `operation = train`). Hasta
   entonces la predicción se abstiene de esos horizontes con `no_model`.
3. Los E2E (`e2e/*.py`) **no se editaron**. Ver el informe del paquete para el análisis de cuáles pueden verse afectados.

## 7. Pendientes honestos

- **Escalón de backtest de 15 días**: H15 exige ventanas de backtest de ≥ 15 días. Con 84–149 días de historia el backtest es de 14
  días y H15 queda `insufficient_data` (`insufficient_horizon`) aunque H14 esté `ok`. Agregar un escalón de 15 días a `TIERS`
  cambiaría las métricas y las salvaguardas de las empresas existentes; **no se hizo**, decisión del arquitecto.
- Alta y edición de planes desde la interfaz de administración: no existe; se hace por datos (`PlanSeeder`) y por la API del superadministrador.
- Conexión de los límites declarados de Enterprise (módulos, frecuencias, retención, conectores, cómputo, LLM) a los módulos que
  correspondan.
