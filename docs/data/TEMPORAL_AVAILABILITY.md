# Disponibilidad temporal de las ventas en el motor (lectura as-of y semántica de días)

> **Alcance y estado.** Este documento describe cómo el motor (`engine/`) lee las ventas **tal como se conocían en cada corte** y cómo clasifica
> cada día de una serie. Implementa el ADR-V25-23 (tiempo de conocimiento y versiones de documentos) y el ADR-V25-03 (semántica de días sin
> transacciones) de `docs/architecture/adr/V25-ADRS.md`, sobre el contrato de la migración
> `2026_03_01_000100_add_sales_knowledge_time_and_revisions`. La **escritura** de las versiones (modos de ingestión, orden de versiones, huecos
> declarados por API) está en `docs/data/LINEAGE_AND_RECONCILIATION.md`; aquí solo se lee.
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**,
> **BLOQUEADO** o **NO APLICA**. Las pruebas están en `engine/tests/` y se ejecutan con
> `cd engine && ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<bd_de_pruebas> .venv/bin/python -m pytest -q <ruta>`
> (las de integración necesitan la BD migrada con `cd backend && DB_DATABASE=<bd_de_pruebas> php artisan migrate:fresh --force`).

## 1. Por qué existe

La auditoría del 31-oct-2026 (`docs/roadmap/audit/2026-10-31/`) halló defectos HIGH de fuga temporal en la lectura del motor:

| Defecto (evidencia) | Efecto | Corrección en el motor |
|---|---|---|
| `daily_sales(cutoff)` filtraba por fecha de evento y por el estado ACTUAL del documento (`demanda.json`, `datos.json`) | Un documento cargado tarde o una anulación posterior reescribían los cortes pasados y el backtest | Lectura as-of desde `sales_line_versions` (§5) |
| `customer_repository.purchases()/returns()` igual (`cliente.json`) | Variables y etiquetas del Customer Core con sesgo de retrospectiva | Variables as-of por corte y etiquetas con la verdad al corte final (§7) |
| `build_panel` rellenaba con cero todo día entre la primera venta y el corte (`demanda.json`, `datos.json`) | Una caída de la integración o un cierre se entrenaba y se medía como ceros observados | Clasificación `day_status` (§8) |
| La huella de ventas solo hasheaba cifras | Dos cortes con distinto conocimiento compartían huella | Huella con las ventanas de conocimiento (§9) |

## 2. Las tres fechas de una venta

| Fecha | Dónde | Significado | Quién la usa |
|---|---|---|---|
| **Evento** | `sale_items.sale_date` (fecha local de la empresa) | Cuándo ocurrió la venta para el negocio | La serie diaria y el corte `C` |
| **Conocimiento** | `known_from` … `known_until` (vista `sales_line_versions`) | Desde cuándo Predictily conoce **esa versión** del documento y hasta cuándo fue la vigente | La regla de visibilidad (§3) |
| **Ingestión** | `sales.first_seen_at`, `created_at` | Cuándo llegó el documento por primera vez | Auditoría; no entra a la lectura |

## 3. Ventana de conocimiento y regla de visibilidad

Cada versión de un documento tiene una ventana `[known_from, known_until)`. La vigente vive en `sales`/`sale_items`
(`known_from = COALESCE(version_known_from, inicio del día local de sale_date)`, `known_until` abierta); las reemplazadas, en
`sales_document_revisions`/`sale_item_revisions`. **Regla** (ADR-V25-23): al corte `C` (fecha local de la empresa, `tenants.timezone`) una versión es visible si

```
known_from < medianoche local de (C + 1)   y   (known_until IS NULL  o  known_until >= medianoche local de (C + 1))
```

**Equivalencia por días (cómo se implementa).** Como los cortes son fechas, la regla solo depende del *día local* de cada extremo:
`known_from < M(C+1)` ⇔ `día_local(known_from) ≤ C`, y `known_until ≥ M(C+1)` ⇔ `día_local(known_until) ≥ C+1`. Una versión es visible en los cortes
`C ∈ [max(sale_date, día_local(known_from)), día_local(known_until) − 1]` (sin extremo superior si está vigente). La base calcula esa ventana en
días (`c_from`, `c_to`) y **agrega las líneas que comparten celda y ventana** (§5). IMPLEMENTADO / VERIFICADO:
`test_asof_visibility.py::test_the_day_window_is_equivalent_to_the_literal_instant_rule` compara la ventana en días con la regla literal sobre instantes
(1 500 versiones aleatorias × 51 cortes por zona, con instantes a 0 y ±1 ns de la frontera) en `America/Guayaquil`, `UTC`, `Europe/Madrid`,
`Asia/Tokyo`, `America/Santiago` y `America/New_York` (incluye cambios de horario), y `::test_visibility_rule_is_strict_on_known_from_and_inclusive_on_known_until`
fija el sentido de cada comparación (`<` estricto, `>=` inclusivo).

### Ejemplo (empresa en America/Guayaquil, UTC-5; la medianoche local de C+1 es 05:00 UTC)

| Versión | `known_from` (UTC) | `known_until` (UTC) | Visible en los cortes |
|---|---|---|---|
| Factura del día 1 cargada como histórico (`backfill`) | inicio del día 1 | — | del día 1 en adelante |
| Factura del día 1 recibida el día 5 a las 10:00 UTC (`incremental`) | día 5, 10:00 | — | del día 5 en adelante (no existía en los cortes 1 a 4) |
| Factura del día 2 anulada el día 6 a las 12:00 UTC | inicio del día 2 | día 6, 12:00 | del día 2 al día 5 (la anulación la cierra desde el día 6) |

IMPLEMENTADO / VERIFICADO: `test_asof_visibility.py::test_incremental_document_loaded_late_is_invisible_before_its_ingestion_and_visible_after`,
`::test_a_voided_document_is_counted_before_the_voiding_and_excluded_after`, `::test_backfill_document_is_visible_from_its_business_day`,
`::test_a_correction_replaces_the_quantity_from_its_knowledge_date`; contra PostgreSQL (filas insertadas directamente en las cuatro tablas del contrato):
`test_asof_integration.py::test_incremental_document_loaded_late_is_invisible_before_its_ingestion_and_visible_after`,
`::test_a_later_voiding_is_counted_before_it_and_excluded_after`, `::test_a_later_correction_changes_the_quantity_only_from_its_knowledge_date`,
`::test_backfill_document_is_visible_from_its_business_day`.

**Reglas de saneamiento de las versiones leídas** (IMPLEMENTADO / VERIFICADO):

- Una versión de **ventana vacía** (la que deja una re-carga `backfill` al colapsar versiones) nunca fue visible y se descarta
  (`test_asof_visibility.py::test_empty_knowledge_windows_are_dropped`).
- Lo ocurrido **después de lo conocido por el corte final** (`knowledge`, ver la subsección siguiente) no puede cambiar ninguna vista ≤ final: una versión conocida
  o con fecha de evento posterior se descarta y una reemplazada después se trata como vigente. Así una reconstrucción histórica es reproducible aunque luego lleguen
  correcciones (`::test_what_happens_after_the_final_cutoff_cannot_change_a_past_view`; en BD:
  `test_asof_integration.py::test_what_happens_after_the_final_cutoff_cannot_change_a_past_cutoff_or_its_fingerprint`).
- Solo se leen versiones con `status = 'completed'`: una anulación aparece como el cierre de la ventana de la versión anterior.

### Conocimiento del corte final: ejecución vigente y reconstrucción histórica

La regla del ADR-V25-23 responde «¿qué se conocía el día C?». Aplicada también al **corte final de una ejecución vigente** daría un resultado absurdo con carga incremental:
los documentos del último día D llegan la madrugada de D+1 (03:00 locales, después de la medianoche que cierra el corte D), así que el pronóstico de hoy vería el día D **sin
ventas**. El motor distingue dos casos, con la misma convención que ya usaban las promociones (`promotions_known_on`) y las órdenes de compra abiertas:

| Ejecución | Cómo se reconoce | Hasta qué día llega lo conocido para el período final y los reales de las ventanas (`knowledge`) |
|---|---|---|
| **Vigente** | el corte es el último dato (`cutoff ≥ last_sale_date()`) | **HOY** (día local de la empresa): lo recibido después de la medianoche del último día —la carga nocturna— cuenta |
| **Reconstrucción histórica** | existen ventas posteriores al corte | el **propio corte**: lo que llegó después no puede cambiar ni el período final ni la huella |

Solo cambia la «verdad» del corte final. Las vistas de los cortes **anteriores** (las ventanas del backtest y las observaciones del Customer Core) siguen siendo estrictas: cada una usa
lo conocido en su propio corte, y lo que llegó después de él no se les cuela (`AsOfWindows` lo trata como tardío). API: `daily_sales(cutoff, knowledge=None)`, `daily_returns`, `sales_stats`,
`last_sale_date` y `sales_versions(upto, knowledge=None)` (y `purchases`, `returns`, `purchase_versions`, `excluded_sales_stats` del Customer Core) aceptan el día de conocimiento; sin él son
as-of estrictas del corte, como siempre. `load_tenant_data` y `load_customer_data` lo fijan con la regla de la tabla. Queda registrado en `knowledge.source.knowledge_date` del
manifiesto y del resumen. La huella de datos **no depende del día de ejecución**, solo de qué versiones se conocieron: dos ejecuciones vigentes de días distintos sin cargas nuevas
entre ellas comparten huella.

IMPLEMENTADO / VERIFICADO: `test_asof_visibility.py::test_a_live_final_view_knows_what_arrived_after_the_midnight_of_the_last_day`,
`::test_a_live_view_applies_a_correction_received_after_the_final_cutoff_but_windows_keep_the_version_in_force`,
`::test_the_live_view_matches_the_literal_rule_applied_at_the_knowledge_day` (regla literal en 4 zonas, con instantes en la frontera y cambio de horario en Europa),
`::test_knowledge_arguments_are_validated_against_what_was_read`, `::test_the_digest_depends_on_what_was_known_not_on_the_day_the_run_happens`,
`::test_purchase_versions_accept_the_same_knowledge_limit`; en BD: `test_asof_integration.py::test_a_live_run_knows_the_nightly_load_of_its_last_day_and_a_historical_reconstruction_of_that_cutoff_does_not`,
`::test_a_live_run_applies_a_correction_received_after_its_last_day_but_the_window_keeps_the_version_it_knew`;
`test_asof_customer_integration.py::test_a_live_customer_run_knows_the_nightly_load_of_its_last_day_and_a_historical_one_does_not`.

## 4. Modos de ingestión: lo que el motor asume

| `ingestion_mode` | Conocimiento de la versión | Efecto en la lectura |
|---|---|---|
| `backfill` (también el valor por defecto de filas que no lo fijan: fixtures) | Desde el inicio de su día de negocio | Visible en todo corte ≥ `sale_date`; coincide con la verdad |
| `incremental` | Desde que Predictily lo recibió (`version_known_from`) | Un documento que llega tarde NO es visible en cortes anteriores a su llegada |

IMPLEMENTADO / VERIFICADO: los fixtures existentes (inserciones directas sin las columnas nuevas, `tests/loader.py`) se leen como antes: la vista as-of coincide
con la consulta SQL anterior en todos los cortes, con las mismas filas y diferencias de coma flotante ≤ 1e-9 (`test_asof_integration.py::test_documents_with_the_default_column_values_behave_as_before`)
y no se activa ninguna reconstrucción por ventana (`windows is None`, mismo costo). La aproximación de `backfill` (se asume el estado final desde el día de negocio)
es una **limitación declarada** (§12), no un dato inventado.

## 5. Lectura: una sola vez, vistas derivadas en pandas

`TenantRepository.sales_versions(upto)` lee **una vez** la vista `sales_line_versions` hasta el corte final y la deja en el repositorio. Desde ahí:

| Método (firma pública) | Semántica |
|---|---|
| `daily_sales(cutoff, knowledge=None)` | Ventas válidas diarias sucursal × producto **tal como se conocían al corte** (mismas columnas que antes). Con `knowledge` (día posterior al corte): lo conocido hasta ese día (ejecución vigente, §3) |
| `daily_returns(cutoff, knowledge=None)` | Devoluciones conocidas al corte (o a `knowledge`) |
| `last_sale_date(cutoff=None, knowledge=None)` | Sin corte: la conocida ahora (estado vigente); con corte: la conocida a esa fecha |
| `sales_stats(cutoff=None, knowledge=None)` | Líneas válidas, con costo y rango de fechas, as-of |
| `daily_actuals(from, to)` | Sin cambio: reales con el conocimiento más reciente (evaluación posterior de pronósticos publicados) |
| `sales_versions(upto, knowledge=None).sales_cells(c)` / `.difference(c)` | Vista as-of de cualquier `c ≤ upto` y cuánto difiere de la verdad (la conocida hasta `knowledge`) |

Tampoco se vuelve a leer para el Customer Core: `CustomerRepository.purchase_versions(through, …)` hace su propia lectura (una consulta de compras y otra de devoluciones, cada una una sola vez y
conservadas mientras vive el repositorio) y de ellas salen `purchases(through)`, `returns(through)` y las vistas de cada corte; `excluded_sales_stats` es una tercera consulta, acotada a los últimos 180 días. IMPLEMENTADO / VERIFICADO: `test_asof_integration.py::test_one_read_of_the_versions_serves_every_cutoff` cuenta las
sentencias que tocan `sales_line_versions` mientras se piden 4 cortes de cada función (una sola), `::test_versions_are_scoped_by_tenant` comprueba el aislamiento por empresa y
`::test_the_asof_read_cost_is_reported` mide el costo frente a la consulta agregada anterior (lo deja en las propiedades de la prueba y en `-s`; el umbral es holgado).

**Representación.** `engine/predictily_engine/data/asof.py` (`SalesVersions`, `PurchaseVersions`): celdas con ventana de visibilidad en días y sus sumas
(unidades, importes, líneas, costos). `visible(c, knowledge=None)` es una comparación vectorizada de enteros; `truth` = lo visible al terminar `knowledge` con fecha de evento ≤ corte final (el propio corte final en una reconstrucción histórica, hoy en una ejecución vigente); una fila es **dinámica** si
llegó después de su día o fue reemplazada (el resto, la gran mayoría en una carga histórica, coincide con la verdad en todo corte). Las sumas son
deterministas: la base suma en `numeric` (exacto) y pandas ordena las celdas antes de combinar ventanas distintas de una misma celda.

**Costo medido** (BD de pruebas `predictily_engine_test_w1b`, una conexión local, JIT apagado; ver §12 para la nota del JIT):

| Escenario | SQL anterior (celdas agregadas) | Lectura de versiones | Vista as-of adicional |
|---|---|---|---|
| Datos de la demo-ferretería (14 281 líneas, carga histórica) | 0,11–0,20 s | 0,19–0,28 s | 8–11 ms |
| 714 050 líneas, 12 % incrementales tardíos (54 364 filas con ventana) | 0,83 s | 4,1 s (PG 2,6–3,5 s + 0,6 s Python) | 10–20 ms (4 ventanas + final: 0,06 s) |

La primera versión del lector transfería una fila por línea (12,9 s a 714 k líneas); agregar en la base por celda y ventana redujo la transferencia a una
fila por celda y ventana. El costo restante a gran volumen es la agregación de la vista en PostgreSQL (unión de vigentes y revisadas, conversión de zona y
agrupación con ocho claves). La huella de las versiones tarda < 10 ms. Para las cifras de la demo real el arquitecto compara antes/después al integrar:
**no se midió contra la base de desarrollo**.

**Paridad y tiempo de la ejecución completa** (ferretería sintética `seed=42`, 10 productos, 14 281 líneas de carga histórica, cargada en `predictily_engine_test_w1b`; el motor anterior es el commit `5eae968`):
con la regla inferida apagada (`day_status_infer_gaps = false`) los 5 164 `forecast_values` y las métricas (WAPE 0,4377, sesgo −0,0067, cobertura 80 % 0,870 y 95 % 0,984) son **idénticos** (rtol 1e-9)
a los del motor anterior; `load_tenant_data` tarda 0,4–0,6 s frente a 0,9–1,0 s (el JIT apagado ahorra más de lo que cuesta la lectura de versiones) y el pipeline completo no muestra diferencia fuera del ruido de la máquina (12–35 s según la carga:
no se crea ninguna ventana as-of cuando la carga es solo histórica). Con la regla inferida activa (por defecto) cambian 4 881 de las 5 164 filas de pronóstico (total diario −0,23 %) y mejora levemente el WAPE seleccionado
(0,4377 → 0,4358), porque los feriados cerrados de los datos sintéticos dejan de entrenarse como ceros observados; es el efecto que debe aprobarse en el piloto (§12.4).

## 6. Demanda: backtest as-of por ventana

Cada ventana del rolling-origin (y el holdout final) **entrena con los datos tal como se conocían en SU corte**: panel diario, perfil de demanda
(`profile_at`), escalas (`series_scale`, `seasonal_naive_scales`), precio supuesto (`last_known_price`) y variables de LightGBM. Los **reales** con que se evalúa cada
ventana son la **verdad disponible al corte final** (en una ejecución vigente, lo conocido hasta hoy; §3), documentado en el manifiesto: `knowledge.policy`.

| Qué | Conocimiento | Dónde |
|---|---|---|
| Panel, perfil, clase, escalas y precio de la ventana `k` | as-of su origen `c_k` | `backtest.rolling.AsOfWindows.at(c_k)` |
| Variables de LightGBM de la ventana `k` | as-of `c_k` (promociones: las conocidas ese día) | `FeatureBuilder` propio de la ventana |
| `actual`, `price_act`, marca de día no evaluable (`stockout`, `day_status`) | verdad al corte final | panel final |
| Modelo final de producción | corte final | panel final |

- Si la vista as-of del origen **coincide** con la verdad restringida a ese origen (nada tardío ni reemplazado le afecta), la ventana reutiliza el panel final: mismo
  resultado y sin costo. Solo se reconstruye el panel de una ventana cuando hay diferencia (`difference(c)`); el manifiesto lo registra por ventana
  (`knowledge.windows[]`: `knowledge = as_of_origin | equals_final_truth`, `panel_rebuilt`, `lines_not_yet_known`, `lines_later_replaced`).
- Sin versiones dinámicas (carga histórica sin cambios o datos sintéticos sin historial) no se crea `AsOfWindows` (`knowledge.as_of_windows_active = false`).
- Una ventana sin ventas visibles ese día se omite; nunca se rellena con la verdad final.

IMPLEMENTADO / VERIFICADO: `test_asof_integration.py::test_a_backtest_window_does_not_change_when_data_known_after_its_cutoff_is_perturbed` (generaliza
`test_leakage.py`): sobre la empresa de pruebas se añaden un documento tardío, una anulación posterior y una corrección posterior, todos con fecha de evento anterior al corte
de la ventana 0 y conocidos después. La ventana 0 conserva pronósticos (4 familias, LightGBM incluido), clases y escalas **idénticos** a los del estado sin perturbar; sin conocimiento
as-of (el comportamiento anterior) esa misma ventana SÍ cambiaba, así que la prueba discrimina; la ventana 1, que ya conocía los tres hechos, cambia en las series afectadas
y no en las demás; los `actual` de la ventana 0 incluyen un documento tardío con fecha dentro de su período evaluado (verdad al corte final); y el registro de
auditoría reporta exactamente qué difería. `::test_windows_that_equal_the_final_truth_reuse_it` fija la reutilización, y
`::test_the_full_pipeline_records_the_knowledge_it_used_and_window_zero_ignores_what_was_known_later` repite el escenario con `run_pipeline` completo (equivalente por fecha de conocimiento de
`test_pipeline.py::test_future_after_a_backtest_cutoff_does_not_change_that_window`): la ventana 0 conserva series, clases y pronósticos; las ventanas posteriores cambian; el manifiesto y el resumen
registran la política, la huella de las versiones y lo que usó cada ventana.

## 7. Customer Core: variables al corte, etiquetas con la verdad

| Qué | Conocimiento | Dónde |
|---|---|---|
| **Variables** de la observación (comprador × producto × corte `c`): recencia, frecuencia, intervalos, cantidades, precios, devoluciones, comprador, producto, actividad | lo conocido el día `c` | `FeatureBuilder.events_at(c)` |
| **Etiquetas** `purchase`, `qty`, `net`, `returned_qty` de la ventana `(c, c+H]` | el conocimiento más reciente: la verdad disponible (hasta hoy si el corte `through` es la última venta; hasta `through` en una reconstrucción histórica, §3) | `FeatureBuilder.labels` (índice de la verdad) |
| Resultados de la evaluación de inactividad/frecuencia (`evaluate_activity`) | variables as-of `c`; resultado con la verdad | `outputs.evaluate_activity` |
| Calendario, promociones (`promotions_as_of`), disponibilidad, catálogo y compradores genéricos | como antes (no versionados o ya as-of por su regla) | — |

`CustomerRepository.purchases(through)` y `returns(through)` (defecto HIGH de `cliente.json`) devuelven lo conocido a `through`. `load_customer_data` conserva además las
versiones (`CustomerData.purchase_versions`); el constructor de variables arma una **vista de eventos por corte** solo cuando la vista as-of difiere de la verdad (se
conservan las dos últimas) y usa un universo de relaciones común a todas, de modo que los códigos son los mismos en la vista y en la etiqueta.
Sin diferencias, todos los cortes comparten un único índice y el resultado es el de antes.

IMPLEMENTADO / VERIFICADO (`test_asof_customer_integration.py`): `::test_features_are_asof_to_each_cutoff_and_labels_use_the_latest_knowledge` (al corte 105 el estado con una
anulación posterior y dos compras cargadas después se ve idéntico al de referencia en variables base, de horizonte, referencias y actividad; con el estado ACTUAL la recencia
sería 2 y no 4; la etiqueta de `(105, 112]` es positiva con 5 unidades gracias a una compra conocida el día 120; un corte posterior sí las conoce);
`::test_the_dataset_has_asof_features_and_latest_knowledge_labels_per_cutoff` (`build_dataset`: variables iguales y cantidad de la etiqueta distinta, 2 u. frente a 4 u.);
`::test_with_only_backfill_documents_the_builder_matches_the_one_without_versions` (regresión);
`::test_purchases_and_returns_contain_exactly_the_valid_documents` (la prueba que pedía la auditoría: invoice, sales_note, other, credit_note, anulada, pendiente,
dos sucursales el mismo día, comprador genérico y anónimo); `::test_purchases_and_returns_are_asof_to_the_cutoff`;
`::test_the_effective_final_cutoff_defines_what_labels_can_know`.

## 8. Clasificación de días (ADR-V25-03)

Cada celda diaria del panel (sucursal × producto × día) lleva `day_status` ∈ {`sale`, `zero_confirmed`, `missing_source`, `stockout_censored`, `closed`} y `day_basis`
(`declared` | `inferred`, solo para `missing_source` y `closed`):

| Estado | Condición | Entra como 0 al entrenamiento y a las métricas |
|---|---|---|
| `sale` | hubo venta | sí (valor observado) |
| `zero_confirmed` | fuente cubierta, sucursal abierta, sin quiebre y sin venta | **sí** (cero observado) |
| `stockout_censored` | quiebre de stock (la lógica anterior: cierre del día o del día previo con existencias ≤ 0) | no: censurado (peso 0, fuera de métricas) |
| `missing_source` | fuera de la cobertura declarada, dentro de un hueco o inferido por la regla de actividad | **no**: excluido; nunca es un cero |
| `closed` | cierre del calendario (`calendar_events.category = 'closure'`) de la empresa o de la sucursal | **no**: excluido; nunca es un cero |

**Precedencia** si coinciden: `missing_source` > `closed` > `stockout_censored` > `sale` / `zero_confirmed`. Una venta registrada en un día `missing_source` o `closed` se conserva
como cota inferior en la imputación (`y = max(venta, media móvil)`) pero el día no se mide.

**Fuentes de `missing_source`** (`engine/predictily_engine/profiling/days.py`):

1. **Fuera de `data_coverage`** (entidad `sales`) declarada para la empresa o la sucursal: antes de `complete_from` o después de `complete_through`. La cobertura de una sucursal
   cerrada (que no fija el corte) también clasifica los días posteriores de esa sucursal (`SalesCoverage.branch_through`).
2. **Huecos** `data_coverage_gaps` (entidad `sales`; `declared` o `inferred`): se aplican a su sucursal; un hueco de una fuente sin sucursal se expande a las sucursales que esa fuente
   alimenta (según `sales.data_source_id`) y, si no se puede determinar, a toda la empresa (conservador). Los huecos solapados se unen.
3. **Regla inferida** (configurable, §10): un día en que una sucursal no tiene NINGUNA línea (ni devolución) pese a que su mediana de líneas diarias en los 28 días previos es ≥ 5 se marca
   `missing_source` con `day_basis = inferred`. La mediana ignora los días ya declarados y los anteriores a la primera venta de la sucursal y exige al menos la mitad de la ventana con datos.
   **Justificación**: una sucursal que vende de forma regular y de pronto no registra nada es, con mucha más probabilidad, una caída de la fuente que una demanda cero; tratarlo como cero
   sesgaría los modelos a la baja. **Debe aprobarse en el piloto** (§12: confunde un cierre sin declarar con una caída de la fuente).

**`closed`**: `calendar_events` con `category = 'closure'`, `scope = 'tenant'` (todas las sucursales) o `scope = 'branch'` con `scope_ref = 'branch:<id>'`; se toma la última revisión de cada
evento y se ignoran los `cancelled` y las referencias de sucursal mal formadas (se cuentan). **`closure` es un valor NUEVO de categoría** (las demás: `holiday`, `local_holiday`, `commercial_season`,
`campaign`, `promotion`, `sector_event`, `agricultural_season`, `international_peak`, `exceptional`). El motor ya lo lee; el backend (`CalendarEvent::CATEGORIES` y el validador de importación) aún no lo
acepta: **pendiente fuera de este paquete** (§12).

**Tratamiento** (IMPLEMENTADO / VERIFICADO):

- `missing_source` y `closed` se **excluyen** de WAPE, MASE, sesgo, cobertura, selección de modelos y calibración de intervalos: las filas del backtest los marcan como no evaluables
  (`stockout = True`, `day_status` dice cuál) y las métricas reportan `exclusions.{stockout_days, missing_source_days, closed_days}`. Se excluyen también de la proporción de ceros del perfilado
  (ADI sobre los últimos 365 días limpios) y reciben **peso 0** en LightGBM.
- Para los modelos que exigen series contiguas se **imputan** con la misma regla de los quiebres: `y = max(venta, media móvil de 28 días de los días limpios)` (mínimo 7 días previos; sin historia
  suficiente queda el valor observado y se cuenta aparte).
- **Trazabilidad**: limitaciones `days_missing_source`, `days_missing_source_inferred` y `days_closed` con su conteo (series-día, imputados y no imputables); `summary.day_status` y
  `evaluation_manifest.day_status`/`knowledge` con el desglose por estado y base; problemas de calidad `missing_source_days` y `closed_days` en el reporte de preparación; el `training_data_hash` incluye `day_status`.
- **Sin cobertura declarada** se conserva el comportamiento anterior (los días sin venta son `zero_confirmed`) y la limitación `coverage_undeclared`.

Pruebas (IMPLEMENTADO / VERIFICADO), `test_day_status.py`: `::test_without_any_declaration_the_panel_behaves_as_before`, `::test_a_declared_gap_is_missing_source_never_a_zero_and_is_imputed`,
`::test_zero_proportion_of_the_profile_ignores_missing_source_and_closed_days`, `::test_closures_by_scope_and_precedence_between_the_statuses`,
`::test_days_outside_the_declared_coverage_of_a_branch_are_missing_source`, `::test_a_source_gap_expands_to_the_branches_that_source_feeds_or_to_all_of_them`,
`::test_the_inferred_rule_marks_a_day_without_any_line_in_a_regular_branch`, `::test_the_inferred_rule_needs_regular_history_and_ignores_irregular_branches`,
`::test_declared_closures_have_priority_over_the_inferred_rule_and_do_not_count_as_a_baseline_gap`, `::test_excluded_days_are_imputed_with_the_stockout_rule_and_counted_when_not_imputable`,
`::test_lightgbm_weight_is_zero_on_excluded_days`, `::test_backtest_rows_flag_excluded_days_so_metrics_never_score_them`,
`::test_the_pipeline_reports_excluded_days_in_limitations_manifest_summary_and_metrics`, `::test_the_inferred_rule_is_configurable_and_active_by_default`; y contra PostgreSQL
(`test_asof_integration.py`): `::test_declared_gaps_closures_and_coverage_come_from_the_database_scoped_by_tenant`, `::test_a_source_outage_is_missing_source_when_declared_and_when_inferred_but_never_a_zero`,
`::test_declared_closures_are_closed_and_data_coverage_limits_the_days_of_each_branch`, `::test_undeclared_coverage_keeps_the_old_zero_semantics_and_the_limitation`.

## 9. Huella de datos

`platform.snapshot.frame_hash` mezcla en el hash de un marco la **huella de las ventanas de conocimiento** de las versiones de las que salió (`DataFrame.attrs['knowledge_digest']`:
contenido y ventana de visibilidad de toda versión que pueda ser visible en algún corte ≤ final). Los componentes `sales` y `costs` de la huella de demanda y `purchases` de la del Customer Core
se calculan así sobre los datos as-of EFECTIVAMENTE usados (después de aplicar la cobertura). Un marco sin esa marca se hashea como siempre (las huellas de datos sintéticos no cambian). La huella depende de lo que se conoció, no del día en que corre la ejecución (§3).

IMPLEMENTADO / VERIFICADO: `test_asof_visibility.py::test_the_digest_covers_knowledge_windows_not_only_values` y `::test_frame_hash_mixes_the_knowledge_digest_and_keeps_unmarked_frames_unchanged`;
`test_asof_integration.py::test_the_sales_fingerprint_changes_with_the_knowledge_windows_even_if_the_values_are_equal` (mismas cifras, un documento histórico frente a uno recibido tarde: cambian
`sales` y `costs`, no `inventory`, `config` ni `calendar`) y `::test_what_happens_after_the_final_cutoff_cannot_change_a_past_cutoff_or_its_fingerprint` (lo posterior al corte final no la altera);
`test_asof_customer_integration.py::test_the_customer_fingerprint_covers_the_knowledge_windows`.

## 10. Configuración (`platform/config.py`, core `demand`)

| Clave | Por defecto | Rango | Significado |
|---|---|---|---|
| `day_status_infer_gaps` | `true` | booleano | Activa la regla inferida de días sin fuente; los huecos, coberturas y cierres DECLARADOS se aplican siempre |
| `day_status_infer_min_median_lines` | `5` | 1 – 100 000 | Mediana mínima de líneas diarias de la sucursal en la ventana previa |
| `day_status_infer_lookback_days` | `28` | 7 – 90 | Ventana previa (se exigen al menos la mitad de sus días con datos) |

Se configuran por empresa en `tenants.settings.cores.demand` (validación en `validate_overrides`: claves desconocidas, tipos y rangos). `build_panel(data)` sin reglas solo clasifica lo declarado;
`run_pipeline` aplica `DayRules.from_config(...)` de la configuración efectiva, que queda registrada en el run.

## 11. Dónde queda registrado

| Qué | Dónde |
|---|---|
| Política de conocimiento, hasta qué día conoció el corte final (`source.knowledge_date`) y lo que usó cada ventana | `forecast_runs.evaluation_manifest.knowledge` y `forecast_runs.summary.knowledge` |
| Conteos por estado y base, imputados y no imputables, reglas aplicadas | `…evaluation_manifest.day_status`, `…summary.day_status`, `data_quality_reports.summary.day_status` |
| Limitaciones con conteo | `forecast_runs.summary.limitations` (`days_missing_source`, `days_missing_source_inferred`, `days_closed`, `coverage_undeclared`) |
| Exclusiones por métrica | `model_evaluations.metrics.sample.exclusions` |
| Huella | `data_snapshots.components` (`sales`, `costs`, `purchases`) |

El perfil por serie (`forecasting_series`) no tiene columnas para los días `missing_source`/`closed`: se calculan en memoria (`missing_source_days`, `closed_days` de `profile_at`) y se reportan en el
manifiesto; añadir columnas requeriría una migración (no incluida).

## 12. Limitaciones y supuestos declarados

1. **`backfill` asume el estado final desde el día de negocio**: una anulación o corrección posterior cargada como histórico no se puede reconstruir. **BLOQUEADO** por el origen (la fuente no entrega el historial de estados).
2. **Inventario, catálogo (`products.is_active`, atributos), costos y calendario no están versionados**: el motor usa su estado vigente (DISEÑADO / NO IMPLEMENTADO fuera de este paquete: `datos.json` MEDIUM «Inventario y atributos de catálogo se sobrescriben sin versión»). Una corrección tardía del inventario aún cambia los quiebres de cortes pasados.
3. **Las declaraciones de calidad (cobertura, huecos, cierres) se aplican con su estado vigente en TODAS las ventanas**: no tienen historial de versiones. Una declaración posterior reclasifica ceros de días ≤ corte de ventanas anteriores
   como ausentes o cerrados; es información sobre el pasado, no sobre la demanda futura, y no revela ventas.
4. **Regla inferida**: confunde un cierre sin declarar (feriado, inventario, corte de energía) con una caída de la fuente; un cierre semanal recurrente sin declarar se marcaría cada semana; no detecta caídas de más de la mitad de la
   ventana (≈ 14 días) ni sucursales con poca actividad (mediana < umbral) o sin historia suficiente. Con datos sintéticos (días cerrados por feriado) la regla marca esos días; **requiere aprobación en el piloto**. Declarar los cierres en el calendario evita el falso positivo (`closed` manda sobre lo inferido).
5. **`closure` aún no lo acepta el backend** (`CalendarEvent::CATEGORIES`, importador de calendario): hasta entonces los cierres solo pueden existir si se cargan por otra vía. DISEÑADO / NO IMPLEMENTADO en este paquete (el backend es de otro paquete).
6. **La vista `sales_line_versions` no expone `currency` ni `data_source_id`**: el Customer Core toma la moneda de la cabecera vigente (`sales.currency`) y los huecos por fuente usan `sales.data_source_id` actual. Un cambio de moneda entre versiones de un mismo documento no se modela.
7. **Compradores (`customers`)**: los filtros de genéricos y eliminados usan su estado vigente (no versionado).
8. **Un día `closed` o `missing_source` con ventas registradas** se excluye de la medición aunque la venta sea real (decisión conservadora: la fuente o el calendario dicen que el día no es representativo).
9. **Zonas con cambio de horario a medianoche**: la equivalencia por días se verificó con Santiago y Nueva York en las fechas de cambio; una medianoche inexistente se adelanta y una ambigua usa la primera ocurrencia. Ecuador no tiene horario de verano.
10. **Otros lectores de ventas** (`db/supply_repository.py`, `causal/runner.py`) siguen leyendo el estado vigente: fuera del alcance de este paquete (Supply y Causal). `evaluation/actuals.py` (exactitud posterior de pronósticos ya publicados) usa `daily_actuals` = verdad más reciente, que es lo deseado para esa medición.
11. **Costo**: la lectura transfiere una fila por celda y ventana (no por línea) pero la agregación de la vista en PostgreSQL crece con las líneas (§5). Con `enable_nestloop = off` (lecturas analíticas) el costo estimado dispara la compilación JIT (0,6–0,9 s por consulta medidos): `analytic_reads()` y las lecturas de versiones la apagan (`SET LOCAL jit = off`).
12. **Qué es una «ejecución vigente»** (§3): el corte es el último dato (`cutoff ≥ last_sale_date()`), la misma convención de `promotions_known_on`. Un corte que la cobertura declarada limita por debajo del último dato
    —p. ej. hay ventas parciales de hoy y `complete_through` es ayer— se trata como **histórico**: la carga nocturna de ayer, recibida tras la medianoche con `ingestion_mode = incremental`, no entra a esa ejecución.
    Pasar una señal explícita de «ejecución vigente» desde `forecast_run.py` / `customer/service.py` lo resolvería, pero esos archivos solo admiten cambios en la carga de datos y no se tocaron: **pendiente de decisión del arquitecto**.
    Además, reconstruir después un corte que fue vigente NO reproduce al bit esa ejecución (ve menos: no conoce lo recibido tras su medianoche); lo que conoció queda en `knowledge.source.knowledge_date` y en la huella de datos.
13. **Reconstrucción por ventana**: cada ventana con diferencias vuelve a construir su panel y sus variables (≈ el costo de un `build_panel` por ventana); en el Customer Core, una vista de eventos por corte con diferencias (se conservan dos). Sin diferencias no hay costo adicional.

## 13. Pruebas y estado

| Prueba | Qué demuestra | Estado |
|---|---|---|
| `tests/unit/test_asof_visibility.py` (31 casos) | Regla de visibilidad (frontera, zonas), backfill, incremental tardío, anulación y corrección posteriores, lo posterior al corte final, ventanas vacías, definiciones de venta/devolución, huella, `at_final`, Customer; conocimiento del corte final (vigente vs histórica, regla literal, validación, huella estable) | IMPLEMENTADO / VERIFICADO |
| `tests/unit/test_day_status.py` (15 casos) | Clasificación y precedencia, huecos, cobertura, cierres, regla inferida, perfilado, imputación, peso 0, filas del backtest, limitaciones/manifiesto/resumen/métricas, configuración | IMPLEMENTADO / VERIFICADO |
| `tests/integration/test_asof_integration.py` (19 casos, PostgreSQL) | Escenarios T02 sobre las tablas del contrato; una sola lectura; aislamiento por empresa; fixtures por defecto = SQL anterior; huella; huecos/cierres/cobertura desde la BD; ventana de backtest estable ante perturbaciones posteriores (a nivel de `run_backtest` y de `run_pipeline` completo); ejecución vigente frente a reconstrucción histórica; costo de la lectura | IMPLEMENTADO / VERIFICADO |
| `tests/integration/test_asof_customer_integration.py` (8 casos, PostgreSQL) | Contenido exacto de `purchases()/returns()`, as-of, variables vs etiquetas, dataset, regresión sin versiones, huella, corte final efectivo, ejecución vigente frente a histórica | IMPLEMENTADO / VERIFICADO |
| `tests/unit/test_leakage.py`, `test_profiling.py`, `test_coverage.py`, `test_pipeline.py`, `test_snapshot_config.py`, `test_customer_dataset.py` | Pruebas de fuga y cobertura existentes, sin debilitar | IMPLEMENTADO / VERIFICADO (la suite completa del motor queda en verde; ver el informe del paquete) |
| Lectura as-of de Supply y Causal | — | DISEÑADO / NO IMPLEMENTADO (§12.10) |
| Aceptación de `closure` por el backend | — | DISEÑADO / NO IMPLEMENTADO (§12.5) |

## 14. Estado resumido

| Estado | Elemento |
|---|---|
| IMPLEMENTADO / VERIFICADO | Lectura única de `sales_line_versions` con vistas as-of derivadas (`daily_sales`, `daily_returns`, `last_sale_date`, `sales_stats`); backtest as-of por ventana con reales de la verdad final; conocimiento del corte final (vigente: hasta hoy; histórica: hasta el corte); Customer Core con variables al corte y etiquetas con la verdad; clasificación `day_status` (cobertura, huecos, cierres, regla inferida, quiebres); exclusión de métricas y perfilado; imputación y trazabilidad; huella con ventanas de conocimiento; claves de configuración |
| DISEÑADO / NO IMPLEMENTADO | Versionado de inventario, catálogo y calendario; lectores de Supply y Causal as-of; aceptación del valor `closure` en el backend; columnas de perfil para días excluidos |
| BLOQUEADO | Estado histórico real de una carga `backfill` (la fuente no lo entrega) |
| NO APLICA | Ventanas de conocimiento en entidades que no son documentos de venta; reconstrucción por ventana cuando la carga es solo histórica (se reutiliza la verdad) |
