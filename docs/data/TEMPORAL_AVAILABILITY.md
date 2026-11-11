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
| **Vigente** | el llamador lo declara con el parámetro `live=True` de `load_tenant_data` / `load_customer_data`: ninguna petición de corte histórico (producción sin corte explícito, entrenamiento y predicción del Customer sin fecha pedida, preparación de datos). Si no se declara (`live=None`) se infiere: el corte pedido es ≥ el último dato | **HOY** (día local de la empresa): lo recibido después de la medianoche del último día —la carga nocturna— cuenta |
| **Reconstrucción histórica** | se pidió un corte anterior al último dato (o `live=False`) | el **propio corte**: lo que llegó después no puede cambiar ni el período final ni la huella |

**La señal es explícita porque la cobertura declarada puede limitar el corte de una ejecución vigente.** `forecast_run` recorta el corte a `data_coverage.complete_through` y el
entrenamiento del Customer limita `through` al último día completo (`cap_to_complete`): el corte queda por debajo del último dato sin ser una reconstrucción histórica. Inferir «vigente» del
corte ya recortado trataba esa ejecución como histórica y perdía la carga nocturna de su último día. Ahora `execute_forecast_run` calcula `live` ANTES de aplicar la cobertura (`cutoff ≥ último dato`
con el corte pedido, sin pedido ⇒ vigente) y lo pasa a la carga; `customer/service.py` pasa `live=True` cuando el run no trae `as_of` (None = inferir de la fecha pedida, no de la limitada).
En la **predicción** del Customer, cuyo corte efectivo es el último día completo aunque se carga hasta la última venta, las variables de ESE corte (`FeatureBuilder.events_at(last_complete)`) también conocen
hasta hoy; las de los cortes anteriores, solo lo suyo.

Solo cambia la «verdad» del corte final. Las vistas de los cortes **anteriores** (las ventanas del backtest y las observaciones del Customer Core) siguen siendo estrictas: cada una usa
lo conocido en su propio corte, y lo que llegó después de él no se les cuela (`AsOfWindows` lo trata como tardío). API: `daily_sales(cutoff, knowledge=None)`, `daily_returns`, `sales_stats`,
`last_sale_date` y `sales_versions(upto, knowledge=None)` (y `purchases`, `returns`, `purchase_versions`, `excluded_sales_stats` del Customer Core) aceptan el día de conocimiento; sin él son
as-of estrictas del corte, como siempre. `load_tenant_data(repo, cutoff, live=None)` y `load_customer_data(repo, cfg, through, cap_to_complete, live=None)` lo fijan con la regla de la tabla. Queda registrado en `knowledge.source.knowledge_date` del
manifiesto y del resumen. La huella de datos **no depende del día de ejecución**, solo de qué versiones se conocieron: dos ejecuciones vigentes de días distintos sin cargas nuevas
entre ellas comparten huella.

IMPLEMENTADO / VERIFICADO (corte limitado por la cobertura): `test_asof_integration.py::test_a_live_run_whose_cutoff_is_limited_by_coverage_keeps_the_nightly_load_of_its_last_day` (el escenario de la
revisión: histórico hasta el día 9, carga nocturna del día 9 recibida el 10 a las 03:00, ventas parciales de hoy y cobertura hasta el día 9: `live=True` ⇒ 10 u. el día 9; sin la señal, 4 u.),
`::test_the_forecast_run_passes_live_to_the_loader_so_a_coverage_limited_run_knows_the_nightly_load` (de extremo a extremo por `execute_forecast_run`: la ejecución de producción limitada por la cobertura conoce hasta hoy y
una histórica con corte explícito solo hasta su corte; falla si se quita el parámetro), `test_asof_customer_integration.py::test_a_live_customer_run_limited_by_coverage_keeps_the_nightly_load_in_training_and_prediction`
(entrenamiento con `cap_to_complete` y variables del corte efectivo de la predicción: recencia 0 con la carga nocturna, 10 sin ella).

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
| Datos de la demo-ferretería (14 281 líneas, carga histórica) | 0,11–0,20 s | 0,15–0,28 s | 7–11 ms |
| 714 050 líneas, 12 % incrementales tardíos (54 364 filas con ventana) | 0,83 s | 4,1 s (PG 2,6–3,5 s + 0,6 s Python) | 10–20 ms (4 ventanas + final: 0,06 s) |

La primera versión del lector transfería una fila por línea (12,9 s a 714 k líneas); agregar en la base por celda y ventana redujo la transferencia a una
fila por celda y ventana. El costo restante a gran volumen es la agregación de la vista en PostgreSQL (unión de vigentes y revisadas, conversión de zona y
agrupación con ocho claves). La huella de las versiones tarda < 10 ms. Para las cifras de la demo real el arquitecto compara antes/después al integrar:
**no se midió contra la base de desarrollo**.

**Paridad y tiempo de la ejecución completa** (ferretería sintética `seed=42`, 10 productos, 14 281 líneas de carga histórica, cargada en `predictily_engine_test_w1b`; el motor anterior es el commit `5eae968`):
con la regla inferida apagada (`day_status_infer_gaps = false`) los 5 164 `forecast_values` (11 columnas numéricas, diferencia absoluta máxima 0,0) y las métricas (WAPE 0,4377, sesgo −0,0067, cobertura 80 % 0,870 y 95 % 0,984, 2 558 observaciones) son **idénticos**
a los del motor anterior; `load_tenant_data` completo tarda 0,35–0,45 s frente a 0,58–0,89 s (el JIT apagado ahorra más de lo que cuesta la lectura de versiones) y el pipeline completo no muestra diferencia fuera del ruido de la máquina (13,7 s el motor anterior, 14,4 s el nuevo con la regla apagada y 14,0 s
con la regla activa, medidos con la máquina compartida; no se crea ninguna ventana as-of cuando la carga es solo histórica). Con la regla inferida activa (por defecto) se marcan 140 días-serie (0,86 % del panel; 14 sucursal-días inferidos) como `missing_source`, cambian 4 881 de las 5 164 filas de pronóstico
(el total diario a 90 días **sube** un 0,23 %: 21 457 → 21 506 unidades) y mejora levemente el WAPE seleccionado (0,4377 → 0,4358; 2 548 observaciones evaluables), porque los feriados cerrados de los datos sintéticos dejan de entrenarse como ceros observados; es el efecto que debe aprobarse en el piloto (§12.4).
Verificado en esta ejecución del paquete con un script de paridad que corre ambos motores sobre la misma empresa, semilla 42 y `n_jobs = 2` (no forma parte de la suite).

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
| **Variables** de la observación (comprador × producto × corte `c`): recencia, frecuencia, intervalos, cantidades, precios, devoluciones, comprador, producto, actividad | lo conocido el día `c`; excepción: en una ejecución vigente (`live`) el corte EFECTIVO (último día completo, que puede quedar por debajo de lo cargado por la cobertura declarada) conoce hasta hoy | `FeatureBuilder.events_at(c)` |
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
`campaign`, `promotion`, `sector_event`, `agricultural_season`, `international_peak`, `exceptional`). El backend lo acepta desde el 10/11/2026 (`CalendarEvent::CATEGORIES`, importador y API de
`calendar-events`; solo con alcance `tenant` o `branch`, y sin la advertencia de `known_at` desconocido: un cierre es un hecho de operación) y la SPA lo ofrece como «Cierre».
También son `closed` (base `declared`) los días de la semana que la empresa declara sin operación con `operating_weekdays` (ver la tabla de claves): en todas las sucursales y en
toda la historia, salvo que en los últimos 56 días más del 1 % de las unidades se haya vendido en esos días (entonces la declaración no se aplica y la ejecución emite
`operating_weekdays_not_applied`). Un día `closed` que cae en el HORIZONTE se pronostica en cero (valor e intervalos; etiqueta `closed_day`).

**Tratamiento** (IMPLEMENTADO / VERIFICADO):

- `missing_source` y `closed` se **excluyen** de WAPE, MASE, sesgo, cobertura, selección de modelos y calibración de intervalos: las filas del backtest los marcan como no evaluables
  (`stockout = True`, `day_status` dice cuál) y las métricas reportan `exclusions.{stockout_days, missing_source_days, closed_days}`. Se excluyen también de la proporción de ceros del perfilado
  (ADI sobre los últimos 365 días limpios) y reciben **peso 0** en LightGBM.
- Para los modelos que exigen series contiguas se **imputan** con la misma regla de los quiebres: `y = max(venta, media móvil de 28 días de los días limpios)` (mínimo 7 días previos). **Huecos largos**: un hueco o cierre
  de más de 21 días agota la ventana (menos de 7 días limpios) y la media móvil queda nula; en vez de dejar el valor observado (0), los días `missing_source` / `closed` **propagan el último nivel disponible** (`ffill` dentro de la serie), de modo que
  60 días declarados ya no dejan 38 con `y = 0` visibles a las familias estadísticas. Solo queda sin imputar un día sin ningún nivel previo (el hueco abre la serie): conserva el valor observado y se cuenta aparte. Los **quiebres de stock conservan
  la regla de siempre** (un quiebre de más de 28 días vuelve a dejar el valor observado: limitación §12.16).
- **Trazabilidad**: limitaciones `days_missing_source`, `days_missing_source_inferred`, `days_closed` y —si quedan días sin imputar— `days_not_imputable`, con su conteo (series-día, imputados, de ellos propagados por hueco largo, y no imputables); `summary.day_status` y
  `evaluation_manifest.day_status`/`knowledge` con el desglose por estado y base (`excluded_days_imputed`, `excluded_days_carried_forward`, `excluded_days_not_imputable`); problemas de calidad `missing_source_days` y `closed_days` en el reporte de preparación; el `training_data_hash` incluye `day_status`.
- **Sin cobertura declarada** se conserva el comportamiento anterior (los días sin venta son `zero_confirmed`) y la limitación `coverage_undeclared`.

Huecos largos (IMPLEMENTADO / VERIFICADO), `test_day_status.py`: `::test_a_long_declared_gap_carries_the_last_level_instead_of_feeding_zeros_to_the_statistical_models` (60 días declarados: ningún `y = 0`,
76 días-serie propagados), `::test_the_carried_level_is_the_last_one_before_the_gap_and_stockouts_keep_the_legacy_rule` y `::test_days_that_cannot_be_imputed_emit_their_own_limitation_code`.

### Evaluación posterior de pronósticos y deriva (`evaluation/actuals.py`)

La exactitud realizada de los pronósticos ya publicados (`forecast_actuals`, métricas `realized` y la alerta `forecast_drift`) tomaba `actual = units.fillna(0)` y solo censuraba los quiebres: una caída de la integración, un día fuera de
la cobertura declarada o un cierre se leían como demanda cero, inflando el WAPE/sesgo realizados y disparando una deriva falsa. **Ahora** los días-serie `missing_source` (cobertura, huecos declarados o inferidos, regla inferida) y `closed` se clasifican con la
MISMA función del entrenamiento (`profiling.days.classify_days`, con la regla de la configuración efectiva de la empresa y el conocimiento más reciente de las declaraciones) y:

- se marcan censurados en `forecast_actuals.is_censored` (todos los niveles: un nodo agregado queda marcado si alguna de sus series lo está ese día);
- no entran a las métricas realizadas de serie (`overall`/`horizon`), a las de nivel total (se omiten los días excluidos) ni a la detección de deriva (que usa solo series evaluables); los quiebres siguen como antes (fuera de la serie, dentro del total);
- se cuentan por clase en `model_evaluations.extra.exclusions` (`series_days`, `evaluated_series_days`, `stockout_days`, `missing_source_days`, `missing_source_inferred_days`, `closed_days`) y en la respuesta de `evaluate-actuals` (`evaluated_runs[].exclusions` y `exclusions` total).

IMPLEMENTADO / VERIFICADO (`test_asof_evaluation_integration.py`, pronósticos publicados fabricados con la demanda real, de modo que todo error proviene de contar ceros falsos): `::test_an_undeclared_outage_is_read_as_zero_demand_and_triggers_a_false_drift_alert`
(línea base: sin declarar ni poder inferir, la deriva salta), `::test_a_declared_outage_is_missing_source_not_zero_demand_and_does_not_produce_drift` (declarada: WAPE y sesgo 0, 20 series-día excluidos, total sin esos días, reevaluación idempotente),
`::test_an_outage_in_a_regular_branch_is_inferred_with_the_training_rule`, `::test_declared_closures_are_closed_days_and_are_not_zero_demand`, `::test_days_outside_the_declared_coverage_of_a_branch_are_missing_source_in_the_evaluation` y
`::test_stockouts_and_excluded_days_are_counted_apart_and_a_real_deterioration_is_still_detected` (la exclusión no enmascara un deterioro real).

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

**Clasificación de días en la huella (ADR-V25-03).** Dos paneles con las mismas ventas pueden diferir en qué días son `missing_source` o `closed`, y eso cambia el entrenamiento y las métricas; antes la huella solo hasheaba la cobertura, así que
declarar un cierre o un hueco no la cambiaba y `persist_snapshot` reutilizaba la fila por `(empresa, core, hash)`. Ahora lleva dos componentes: `day_inputs` (las ENTRADAS declaradas, ordenadas: huecos de cobertura, cierres y el mapa fuente → sucursales
que expande los huecos por fuente) y `day_status` (el RESULTADO: los días-serie excluidos con su estado y base, que recoge también la regla inferida; sus parámetros `day_status_*` viajan además en el componente `config`). El Customer Core NO clasifica
días (no usa huecos ni cierres), así que su huella no necesita esos componentes.

IMPLEMENTADO / VERIFICADO: `test_day_status.py::test_the_data_fingerprint_changes_with_gaps_closures_the_source_map_and_the_rules` (con y sin cierre/hueco ⇒ distinto hash, solo se mueven `day_inputs` y `day_status`; el orden de las filas declaradas no influye; el mapa de fuente
cambia la huella aunque el panel no cambie) y `test_asof_integration.py::test_declaring_a_closure_or_a_gap_changes_the_persisted_fingerprint_and_removing_it_reuses_the_old_row` (contra `persist_snapshot`: tres filas distintas y, al retirar las declaraciones, la original).

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
| `day_status_infer_same_weekday` | `true` | booleano | Exige además que ese mismo día de la semana sea habitual en las `lookback // 7` semanas previas (un día que la sucursal no suele vender es un cero observado) |
| `operating_weekdays` | `null` (los 7 días) | lista de enteros 0–6 sin repetir, al menos 3 | Días de la semana en que la empresa opera (0 = lunes). Los demás son `closed`/`declared` y se pronostican en cero. Decisión de la empresa: el motor solo lo sugiere (`weekday_without_sales` en la preparación de datos) |

Se configuran por empresa en `tenants.settings.cores.demand` (validación en `validate_overrides`: claves desconocidas, tipos y rangos). `build_panel(data)` sin reglas solo clasifica lo declarado;
`run_pipeline` aplica `DayRules.from_config(...)` de la configuración efectiva, que queda registrada en el run.

## 11. Dónde queda registrado

| Qué | Dónde |
|---|---|
| Política de conocimiento, hasta qué día conoció el corte final (`source.knowledge_date`) y lo que usó cada ventana | `forecast_runs.evaluation_manifest.knowledge` y `forecast_runs.summary.knowledge` |
| Conteos por estado y base, imputados y no imputables, reglas aplicadas | `…evaluation_manifest.day_status`, `…summary.day_status`, `data_quality_reports.summary.day_status` |
| Limitaciones con conteo | `forecast_runs.summary.limitations` (`days_missing_source`, `days_missing_source_inferred`, `days_closed`, `coverage_undeclared`) |
| Exclusiones por métrica (backtest) | `model_evaluations.metrics.sample.exclusions` |
| Exclusiones de la evaluación posterior (realizada) y de la deriva | `model_evaluations.extra.exclusions` (`evaluation_type = 'realized'`), `forecast_actuals.is_censored` y la respuesta de `evaluate-actuals` |
| Huella | `data_snapshots.components` (`sales`, `costs`, `purchases`, `day_inputs`, `day_status`) |

El perfil por serie (`forecasting_series`) no tiene columnas para los días `missing_source`/`closed`: se calculan en memoria (`missing_source_days`, `closed_days` de `profile_at`) y se reportan en el
manifiesto; añadir columnas requeriría una migración (no incluida).

**Persistencia día a día: DISEÑADO / NO IMPLEMENTADO.** El ADR-V25-03 pide que la clasificación se persista en el dataset Gold y su resumen en el informe de calidad. Hoy se persiste el **resumen** (informe de calidad, manifiesto y resumen del run, limitaciones con su
conteo) y la clasificación día a día vive en el panel en memoria de cada ejecución (`day_status`, `day_basis`; entra al `training_data_hash`). El dataset Gold pertenece al Data Core P0 (ADR-V25-24) y no existe todavía en el esquema.

## 12. Limitaciones y supuestos declarados

1. **`backfill` asume el estado final desde el día de negocio**: una anulación o corrección posterior cargada como histórico no se puede reconstruir. **BLOQUEADO** por el origen (la fuente no entrega el historial de estados).
2. **Inventario, catálogo (`products.is_active`, atributos), costos y calendario no están versionados**: el motor usa su estado vigente (DISEÑADO / NO IMPLEMENTADO fuera de este paquete: `datos.json` MEDIUM «Inventario y atributos de catálogo se sobrescriben sin versión»). Una corrección tardía del inventario aún cambia los quiebres de cortes pasados.
3. **Las declaraciones de calidad (cobertura, huecos, cierres) se aplican con su estado vigente en TODAS las ventanas**: no tienen historial de versiones. Una declaración posterior reclasifica ceros de días ≤ corte de ventanas anteriores
   como ausentes o cerrados; es información sobre el pasado, no sobre la demanda futura, y no revela ventas.
4. **Regla inferida**: confunde un cierre sin declarar (feriado, inventario, corte de energía) con una caída de la fuente; un cierre semanal recurrente sin declarar se marcaría cada semana; no detecta caídas de más de la mitad de la
   ventana (≈ 14 días) ni sucursales con poca actividad (mediana < umbral) o sin historia suficiente. Con datos sintéticos (días cerrados por feriado) la regla marca esos días; **requiere aprobación en el piloto**. Declarar los cierres en el calendario evita el falso positivo (`closed` manda sobre lo inferido).
5. **`closure` aún no lo acepta el resto del producto**: el motor lo lee de `calendar_events` (la tabla no restringe la categoría) pero no figura en `CalendarEvent::CATEGORIES`, `ImportFieldRegistry` ni `CalendarEventImportProcessor` (backend),
   en `CalendarPanel.vue` y `locales/es.json` (frontend), en `CALENDAR_CATEGORIES` de `engine/predictily_engine/platform/extensions.py` (validación de eventos aportados por extensiones), en `docs/openapi/*.yaml` ni en
   `docs/architecture/v2/P3-datos.md`. Hasta entonces los cierres solo pueden existir si se cargan por otra vía. DISEÑADO / NO IMPLEMENTADO en este paquete (esos archivos son de otros paquetes).
6. **La vista `sales_line_versions` no expone `currency` ni `data_source_id`**: el Customer Core toma la moneda de la cabecera vigente (`sales.currency`) y los huecos por fuente usan `sales.data_source_id` actual. Un cambio de moneda entre versiones de un mismo documento no se modela.
7. **Compradores (`customers`)**: los filtros de genéricos y eliminados usan su estado vigente (no versionado).
8. **Un día `closed` o `missing_source` con ventas registradas** se excluye de la medición aunque la venta sea real (decisión conservadora: la fuente o el calendario dicen que el día no es representativo).
9. **Zonas con cambio de horario a medianoche**: la equivalencia por días se verificó con Santiago y Nueva York en las fechas de cambio; una medianoche inexistente se adelanta y una ambigua usa la primera ocurrencia. Ecuador no tiene horario de verano.
   La lectura de PostgreSQL (`CAST(known_from AT TIME ZONE tz AS date)`) se verifica además **contra la base de datos** con documentos cuyo `known_from` / `known_until` caen en la frontera `M(C+1)` (−1 s, 0, +1 s y ±3 h) en `America/Guayaquil`,
   `Europe/Madrid` (cambio de primavera y de otoño) y `America/Santiago` (cambio A MEDIANOCHE, primavera y otoño), día por día frente a la regla literal calculada con `zoneinfo`: IMPLEMENTADO / VERIFICADO,
   `test_asof_timezones_integration.py::test_the_database_asof_read_matches_the_literal_rule_at_the_boundaries_of_dst_and_non_dst_zones` (5 casos). Dos precisiones medidas por esa verificación: (a) las columnas del contrato (`sales.version_known_from`,
   `sales_document_revisions.known_from/known_until`) son `timestamptz(0)`: **la precisión del conocimiento es de un segundo** (un instante a ±1 µs de la frontera se redondea al segundo más cercano; un documento recibido a menos de medio segundo antes de la
   medianoche local cuenta como del día siguiente); la comparación a ±1 ns se comprueba sin base de datos en `test_asof_visibility.py`. (b) PostgreSQL y Python llevan **copias distintas de la base de zonas horarias**: en zonas cuyas reglas cambiaron
   recientemente pueden discrepar (la sonda de la revisión lo observó con `America/Havana` tras el 1-nov-2026, donde `zoneinfo` seguía en horario de verano). La lectura de ventas usa solo la conversión de PostgreSQL; Python interviene en `local_today` (el día
   hasta el que llega lo conocido de una ejecución vigente) y en las rutinas de las pruebas, de modo que una discrepancia afectaría como máximo a ese día. Mantener actualizada la base de zonas (paquete `tzdata` del motor y la de PostgreSQL) en producción.
10. **Otros lectores de ventas** siguen leyendo el estado vigente: `supply/simulation_runner.py` (`repo.daily_sales(as_of)`), `db/supply_repository.py` (`recent_net_prices`, `sales_cost_history`; los usan `supply/simulation_runner.py`, `scenarios/runner.py` y
    `costs/runner.py`) y `causal/runner.py`. Quedan **fuera del alcance de este paquete y pendientes para el paquete Supply/Causal** (DISEÑADO / NO IMPLEMENTADO). `evaluation/actuals.py` (exactitud posterior de pronósticos ya publicados)
    usa `daily_actuals` = verdad más reciente, que es lo deseado para esa medición; su clasificación de días usa el conocimiento más reciente de las declaraciones (§8).
    `supply/simulation_runner.py` llama a `repo.daily_sales(as_of)`: con la firma pública conservada recibe ahora la vista as-of **estricta** de `as_of` (es lo pedido: «los llamadores existentes la obtienen sin cambios»). En una simulación vigente
    (`as_of` = última venta) los documentos de ese último día recibidos tras su medianoche local con `ingestion_mode = incremental` no entran a esa corrida; afecta solo al último día del re-juego. Pasarle `knowledge` (hoy) es un cambio de una línea
    en el paquete de Supply: **pendiente de decisión del arquitecto**.
    **El plan del backtest depende de la historia del panel FINAL.** `plan_backtest` (`backtest/rolling.py`) elige el nivel de ventanas (`TIERS`) y los orígenes con `n_days` = días entre la primera venta del panel final y el corte final. Esa longitud se calcula con la
    verdad disponible, no con lo que se conocía en el origen de cada ventana: si la historia más antigua llegó tarde (carga incremental con fechas de evento viejas), el panel as-of de una ventana puede ser más corto que el que el plan supuso. Cada ventana
    se arma con sus propios datos y se omite si no tiene ventas visibles (`knowledge.windows[]`), pero la elección del nivel y de los orígenes no es as-of. Efecto acotado: solo ocurre con historia que llegó tarde; si hiciera falta, el plan podría
    calcularse con la historia visible en el primer origen (cambio de la política de ventanas, no incluido).
11. **Costo**: la lectura transfiere una fila por celda y ventana (no por línea) pero la agregación de la vista en PostgreSQL crece con las líneas (§5). Con `enable_nestloop = off` (lecturas analíticas) el costo estimado dispara la compilación JIT (0,6–0,9 s por consulta medidos): `analytic_reads()` y las lecturas de versiones la apagan (`SET LOCAL jit = off`).
12. **Qué es una «ejecución vigente»** (§3): la que no pidió un corte histórico. **RESUELTO / VERIFICADO**: el llamador lo declara con `live=True` (`load_tenant_data`, `load_customer_data`), de modo que un corte que la cobertura declarada limita por
    debajo del último dato —p. ej. hay ventas parciales de hoy y `complete_through` es ayer— sigue siendo vigente y conoce la carga nocturna de ayer (recibida tras la medianoche con `ingestion_mode = incremental`). `execute_forecast_run` y
    `customer/service.py` (entrenamiento y predicción) lo pasan; sin la señal (`live=None`) se infiere del corte pedido (`cutoff ≥ último dato`), que sigue siendo correcto para quien pide un corte histórico. Las pruebas están en §3. Queda una **diferencia
    deliberada**: las órdenes de compra abiertas (`open_purchase_orders`) y el inventario siguen siendo las del corte limitado (coherentes con `latest_inventory(corte)`); solo las ventas y las promociones conocen hasta hoy.
    Reconstruir después un corte que fue vigente NO reproduce al bit esa ejecución (ve menos: no conoce lo recibido tras su medianoche); lo que conoció queda en `knowledge.source.knowledge_date` y en la huella de datos.
13. **Reconstrucción por ventana**: cada ventana con diferencias vuelve a construir su panel y sus variables (≈ el costo de un `build_panel` por ventana); en el Customer Core, una vista de eventos por corte con diferencias (se conservan dos). Sin diferencias no hay costo adicional.
    **Costo medido del Customer Core con documentos tardíos** (`test_customer_asof_cost.py::test_the_cost_of_late_documents_in_the_customer_dataset_is_reported`, mundo sintético de 400 compradores × 12 productos × 540 días = 28 860 compras-celda, 32 cortes
    de entrenamiento, `FeatureBuilder` + `build_dataset`; la prueba lo reporta en `-s` y como propiedades): **0,6–1,5 s con todo conocido desde su día (`backfill`) frente a 1,9–4,9 s con el 10 % de las compras recibidas tarde (×3–4)**; la sonda de la
    revisión midió 0,79 s → 2,59 s en otro arranque (el rango es amplio porque la máquina se compartía con otras suites). El sobrecosto es ≈ 40 ms por vista de corte —la reconstrucción de los eventos del corte: consulta de celdas visibles, agregación por
    comprador × producto × día y armado de los índices— y crece de forma lineal con las compras-celda; con un millón de celdas serían del orden de 1,4 s por corte distinto (≈ 45 s con 32 cortes). Solo se paga cuando hay documentos tardíos o reemplazados
    que alteran el corte (`differs_from_truth`); con todo conocido a tiempo hay un solo índice. **Decisión: no se optimizó** (actualización incremental o caché de las vistas): los cortes se piden en orden ascendente y la vista de un corte no sirve al
    siguiente (añade eventos nuevos y cambia la visibilidad de las filas dinámicas), de modo que una actualización incremental exigiría modificar en sitio los índices de eventos (riesgo de diferencias en las sumas frente a la referencia exacta que hoy fijan
    las pruebas) para ahorrar segundos frente a minutos de entrenamiento de los modelos. Si el piloto con datos reales muestra un costo relevante, la vía es agregar las filas estáticas una sola vez y recombinar solo las dinámicas por corte.
14. **Sesgo conservador en el borde de cada ventana con carga nocturna**: las ventanas del backtest y las observaciones del Customer Core aplican la regla estricta del ADR-V25-23, así que con documentos `incremental` que llegan tras la
    medianoche (03:00 del día siguiente) cada ventana entrena sin las ventas de su último día, mientras que el pronóstico vigente sí las conoce (§3). Por eso el backtest es, en ese borde, ligeramente más pesimista que la producción. Es la consecuencia
    literal de la regla (lo que se conocía el día `C`); una alternativa de diseño —un rezago de ingestión típico por fuente— no está implementada y requeriría decisión del arquitecto.
15. **Preparación de datos (`pipeline/readiness.py`)**: aplica la cobertura declarada igual que `execute_forecast_run` (`SalesCoverage.for_repository`: el corte no la supera, se descarta lo anterior a `complete_from`, los días fuera de ella son
    `missing_source`) y añade el problema `cutoff_limited_by_coverage` y la cobertura aplicada al informe. IMPLEMENTADO / VERIFICADO: `test_asof_integration.py::test_readiness_applies_the_declared_coverage_like_the_forecast_run`. Diferencia que se
    conserva: el informe no emite la limitación `coverage_undeclared` (la ejecución sí) porque su lista de problemas es la de calidad de datos, no la del pronóstico; y el contador `missing_days` del informe (días hábiles sin ventas) sigue siendo un
    aviso de revisión que no distingue días declarados.
16. **Quiebres largos**: un quiebre de stock de más de 28 días conserva la imputación de siempre (la media móvil se agota y el día queda con su valor observado); el cambio de huecos largos (§8) solo alcanza a `missing_source` y `closed`. Extenderlo a los
    quiebres cambiaría los resultados del motor anterior; DISEÑADO / NO IMPLEMENTADO (decisión del arquitecto).
17. **Evaluación posterior**: los días excluidos conservan `forecast_actuals.actual` = 0 (no hay ventas registradas) con `is_censored = true`; quien lea esa tabla debe respetar la marca. El nivel total omite los días excluidos pero conserva los de quiebre
    (comportamiento anterior). Las declaraciones de calidad se aplican con su estado vigente a pronósticos de cualquier fecha (§12.3).
18. **Huella del Customer Core**: no incluye la cobertura declarada (`data.coverage`, p. ej. `complete_from`, que sí condiciona el dataset de entrenamiento); su componente `config`/`eligibility` y la fecha efectiva (`as_of`) sí. Observación para el paquete del
    Customer (el guardado/armado de su huella está en `customer/service.py`, fuera del alcance de este paquete).

## 13. Pruebas y estado

| Prueba | Qué demuestra | Estado |
|---|---|---|
| `tests/unit/test_asof_visibility.py` (31 casos) | Regla de visibilidad (frontera, zonas), backfill, incremental tardío, anulación y corrección posteriores, lo posterior al corte final, ventanas vacías, definiciones de venta/devolución, huella, `at_final`, Customer; conocimiento del corte final (vigente vs histórica, regla literal, validación, huella estable) | IMPLEMENTADO / VERIFICADO |
| `tests/unit/test_day_status.py` (19 casos) | Clasificación y precedencia, huecos, cobertura, cierres, regla inferida, perfilado, imputación (con huecos largos y días no imputables), peso 0, filas del backtest, limitaciones/manifiesto/resumen/métricas, configuración; huella de datos con huecos, cierres, mapa de fuente y reglas | IMPLEMENTADO / VERIFICADO |
| `tests/integration/test_asof_integration.py` (24 casos, PostgreSQL) | Escenarios T02 sobre las tablas del contrato; una sola lectura; aislamiento por empresa; fixtures por defecto = SQL anterior; huella (también con cierres y huecos declarados); huecos/cierres/cobertura desde la BD; ventana de backtest estable ante perturbaciones posteriores (a nivel de `run_backtest` y de `run_pipeline` completo); ejecución vigente frente a reconstrucción histórica, también con el corte limitado por la cobertura (de extremo a extremo por `execute_forecast_run`); preparación de datos con cobertura; costo de la lectura | IMPLEMENTADO / VERIFICADO |
| `tests/integration/test_asof_customer_integration.py` (9 casos, PostgreSQL) | Contenido exacto de `purchases()/returns()`, as-of, variables vs etiquetas, dataset, regresión sin versiones, huella, corte final efectivo, ejecución vigente frente a histórica, ejecución vigente con el corte limitado por la cobertura (entrenamiento y predicción) | IMPLEMENTADO / VERIFICADO |
| `tests/integration/test_asof_evaluation_integration.py` (6 casos, PostgreSQL) | Evaluación posterior y deriva: caída declarada, inferida, cierre y fuera de cobertura no son ceros ni producen deriva falsa; un deterioro real sigue detectándose; conteo por clase | IMPLEMENTADO / VERIFICADO |
| `tests/integration/test_asof_timezones_integration.py` (5 casos, PostgreSQL) | La lectura as-of de la base frente a la regla literal en las fronteras ±1 s de `M(C+1)` en Guayaquil, Madrid (primavera y otoño) y Santiago (cambio a medianoche, primavera y otoño) | IMPLEMENTADO / VERIFICADO |
| `tests/unit/test_customer_asof_cost.py` (1 caso) | Costo medido del dataset del Customer Core con 10 % de compras tardías (§12.13) | IMPLEMENTADO / VERIFICADO |
| `tests/unit/test_leakage.py`, `test_profiling.py`, `test_coverage.py`, `test_pipeline.py`, `test_snapshot_config.py`, `test_customer_dataset.py` | Pruebas de fuga y cobertura existentes, sin modificar ni debilitar | IMPLEMENTADO / VERIFICADO (pasan en la suite completa) |
| Suite completa del motor (`cd engine && ENGINE_TEST_DATABASE_URL=… .venv/bin/python -m pytest -q`, ejecutada desde un directorio sin `.env`) | 548 pruebas: las 524 de la línea base previa a las correcciones de la revisión, **21 nuevas de esas correcciones** (4 en `test_day_status.py`, 4 en `test_asof_integration.py`, 1 en `test_asof_customer_integration.py`, 6 en `test_asof_evaluation_integration.py`, 5 en `test_asof_timezones_integration.py` y 1 en `test_customer_asof_cost.py`) y 3 de `test_hermetic_env.py` (paquete de firma de artefactos, no de este) | IMPLEMENTADO / VERIFICADO: `548 passed, 1 warning in 610.33s (0:10:10)` (31-oct-2026; máquina compartida con otras suites; las 21 pruebas nuevas suman ≈ 13 s de ejecución, 6 s de ellas la de costo del Customer) |
| Lectura as-of de Supply y Causal | — | DISEÑADO / NO IMPLEMENTADO (§12.10) |
| Aceptación de `closure` por el backend | — | DISEÑADO / NO IMPLEMENTADO (§12.5) |

## 14. Estado resumido

| Estado | Elemento |
|---|---|
| IMPLEMENTADO / VERIFICADO | Lectura única de `sales_line_versions` con vistas as-of derivadas (`daily_sales`, `daily_returns`, `last_sale_date`, `sales_stats`); backtest as-of por ventana con reales de la verdad final; conocimiento del corte final (vigente: hasta hoy, también cuando la cobertura declarada limita el corte, con la señal explícita `live`; histórica: hasta el corte); Customer Core con variables al corte y etiquetas con la verdad; clasificación `day_status` (cobertura, huecos, cierres, regla inferida, quiebres); exclusión de métricas y perfilado; imputación (con propagación del último nivel en huecos largos de fuente ausente o cierre) y trazabilidad (`days_not_imputable`); **exclusión de esos días en la evaluación posterior de pronósticos y en la detección de deriva, con conteo por clase**; huella con ventanas de conocimiento y con la clasificación de días (`day_inputs`, `day_status`); preparación de datos con la cobertura declarada; lectura de PostgreSQL verificada en zonas con horario de verano; costo del Customer con documentos tardíos medido; claves de configuración |
| DISEÑADO / NO IMPLEMENTADO | Versionado de inventario, catálogo y calendario; lectores de Supply, Causal, Escenarios y Costos as-of (`supply/simulation_runner.py`, `db/supply_repository.py`, `causal/runner.py`); plan del backtest (`plan_backtest`) con la historia as-of del primer origen; aceptación del valor `closure` en el backend; columnas de perfil para días excluidos; persistencia día a día de `day_status` en el dataset Gold (hoy solo su resumen); propagación del último nivel en quiebres de más de 28 días; actualización incremental de las vistas por corte del Customer Core (§12.13); cobertura declarada en la huella del Customer Core |
| BLOQUEADO | Estado histórico real de una carga `backfill` (la fuente no lo entrega) |
| NO APLICA | Ventanas de conocimiento en entidades que no son documentos de venta; reconstrucción por ventana cuando la carga es solo histórica (se reutiliza la verdad) |
