# Supply Core: disponibilidad, perecibilidad (FEFO), política de reposición y simulación as-of

Paquete W5-D · V2.5 §10 y §25.5 · requisitos `V25-SUP-*` de `docs/roadmap/audit/2026-10-31/supply_decision.json` · ADR-V25-05 (clases de calidad) y ADR-V25-23 (tiempo de conocimiento).

Estados de cada apartado: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO**, **NO APLICA**. Ningún umbral de este documento es universal: cada valor es un
parámetro inicial **a aprobar en el piloto** con datos reales de la empresa (§8). El motor **sugiere**: no emite compras, no escribe en el ERP y no envía mensajes (V25-SUP-024, V25-DEC-033).

## 1. Posición de inventario: disponible, comprometido, tránsito y ajustes, separados

**IMPLEMENTADO / VERIFICADO.** Código: `supply/position.py`, `supply/inputs.py` (`build_problem`), `supply/balance.py` (recepciones fechadas). Cada serie sucursal × producto expone en `details.report.availability`
(`GET /supply/plans/{run}`, `?include=inventory_position` para el detalle por serie) sus componentes por separado:

| Componente | Regla | Prueba |
|---|---|---|
| Existencia física válida | `on_hand` del snapshot + ajustes **posteriores** al snapshot y ≤ corte | `test_supply_position.py::test_adjustments_after_the_snapshot_are_a_separate_component_and_do_not_touch_the_forecast` |
| Reservado | unidades que el ERP aparta para pedidos; ninguna predicción lo incrementa | `test_balance_reports_committed_and_residual_and_reserved_stock_is_not_discounted_twice` |
| Disponible hoy | físico válido − reservado **no respaldado por pedidos** (nunca negativo; el recorte se declara) | idem |
| Comprometido | pedidos/contratos **confirmados** con entrega en el horizonte, tal como se conocían al corte (regla `CommitmentAsOf` de P3: `customer.commitments`), por período y sucursal de cabecera | `test_commitments_are_attributed_by_branch_and_period_and_unattributed_ones_are_reported` |
| Residual probabilístico | pronóstico − comprometido (≥ 0) | `test_confirmed_orders_are_inside_the_forecast_never_added_on_top` |
| En tránsito | órdenes de compra abiertas, cada una en su fecha (solo el saldo pendiente si es parcial); el tránsito del snapshot no se suma si hay órdenes con fecha | `test_supply_balance.py` (heredado de P4) |
| Ajustes | mermas, conteos y correcciones (`stock_movements.movement_type = 'adjustment'`) con fecha y motivo; ajustan la existencia, **no** la demanda ni el pronóstico | idem ajustes |

**Reconciliación sin doble conteo (V25-SUP-003/025/037).** Los pedidos confirmados SON ventas futuras y ya están dentro del pronóstico. Por período τ: `demanda neta = max(pronóstico_τ, comprometido_τ) =
comprometido + residual probabilístico`, **nunca** pronóstico + comprometido (con 40 u. comprometidas dentro de un pronóstico de 70 la demanda neta es 70 = 40 + 30, no 110). Lo reservado que respalda pedidos del horizonte
**no se descuenta** de la existencia (esas unidades ya salen como demanda); solo se descuenta el reservado sin pedido que lo respalde. Un comprometido que supera al pronóstico se planifica como demanda firme y se
informa. La merma FEFO proyectada y el tope por vida útil de los perecibles usan esa **misma demanda neta** (`net_daily`: dentro de cada período el total pasa a `max(pronóstico, comprometido)` conservando la forma diaria),
no el pronóstico crudo. Sin pedidos cargados se conserva la regla anterior (todo lo reservado se descuenta) y el plan lo declara en **un solo supuesto** («Reservado sin pedidos confirmados que lo respalden»), no como una limitación por
plan ni por serie. Pruebas: `test_supply_position.py` (compromisos, frontera de períodos), `test_the_expiry_loss_and_the_shelf_cap_use_the_commitments_netted_demand_not_the_raw_forecast` y
`test_supply_w5d_integration.py::test_plan_reconciles_confirmed_orders_with_the_forecast_and_does_not_count_them_twice` (BD real). `commitments_mode = ignore` restituye la conducta anterior.

> **Nota de versión (contrato y efecto medido).** El contrato queda así: **los planes de productos no perecibles no cambian cuando no hay pedidos confirmados cargados** (comprobado antes/después, §9). `commitments_mode = reconcile`
> es el valor por defecto porque ignorar pedidos reales sería un defecto (doble conteo); por eso una empresa que SÍ tiene pedidos cargados verá planes distintos. Efecto medido sobre la demo ficticia (revisión W5-D, `exp/e14_demo_reserved.py`):
> con el **30 % de las existencias reservado y cubierto por pedidos confirmados**, el plan compra **−13,4 %** (andina) y **−10,3 %** (costa) de unidades frente a la regla anterior, que descontaba lo reservado Y mantenía esos pedidos en el pronóstico
> (el resultado coincide con el de una demo sin reservas); con **pedidos por encima del pronóstico**, el plan compra **+6,8 %** (demanda firme). Reproducir: `engine/tests/supply_before_after.py` con `commitments_mode` por defecto e `ignore`.

**Ajustes (`adjustments_mode = apply`, por defecto).** Los ajustes de `stock_movements` posteriores al snapshot ajustan la existencia como un componente separado. Está **activo por defecto, pero sin efecto mientras no exista un importador de
movimientos** (hoy ninguna vía productiva carga `stock_movements`; §10): con la tabla vacía el plan es el de antes. Una carga futura los hará efectivos sin cambios de código; `adjustments_mode = ignore` los desactiva.

**Validez del físico (V25-SUP-002/032).** Una **sobrerreserva** (reservado > físico), un **físico negativo** y un **snapshot obsoleto** (más de `inventory_stale_days` antes del corte) se **declaran** (limitación con la
cantidad recortada, `details.report.availability.validity`) y degradan la calidad del plan a `degraded`; la existencia utilizable sigue acotada en 0 (nunca un inventario negativo silencioso).
Pruebas: `test_over_reserved_stock_is_declared_with_the_clipped_quantity_and_degrades_quality`, `test_negative_on_hand_and_stale_snapshot_are_declared_not_accepted_silently`. Límite: la simulación y los escenarios
(`scenarios/`) conservan su recorte propio (no declaran la sobrerreserva todavía: **PENDIENTE**, fuera de los archivos de este paquete).

## 2. Perecibilidad y FEFO

**IMPLEMENTADO / VERIFICADO.** Código: `inventory/lots.py` (funciones puras), `supply/perishability.py`, integración en `supply/inputs.py`, `supply/planner.py`, `supply/verify.py`, `supply/heuristic.py`.

**Datos.** `products.is_perishable` y `products.shelf_life_days` (NULL = no declarada) y `inventory_lots` (sucursal, producto, lote, `snapshot_date`, `expiry_date` nullable, cantidad), contrato de la migración
`2026_03_13_000100`. Se cargan por la importación (§6). El vencimiento es el **último día vendible** (inclusive): el lote se pierde al cierre de ese día.

**Clases de serie** (visibles en `details.perishability.by_status`):

| Clase | Condición | Tratamiento |
|---|---|---|
| `durable` | sin perecibilidad declarada ni lotes con vencimiento | **exactamente como antes** (sin texto nuevo en el plan) |
| `perishable_unquantified` | marcado perecible, sin vida útil ni lotes | **como un durable**; el plan lo declara («no se inventan vencimientos») |
| `shelf_life` | vida útil declarada, sin lotes utilizables | tope por vida útil sobre las compras nuevas (§2.3) |
| `lots` / `lots_and_shelf_life` | lotes con vencimiento (+ vida útil) | FEFO sobre los lotes existentes (§2.1), + tope (§2.3) |

Un producto con lotes que traen vencimiento recibe FEFO aunque no esté marcado perecible (el vencimiento del lote es evidencia); una vida útil declarada en un producto marcado NO perecible no se usa (el importador lo avisa).

### 2.1 Lotes existentes: método FEFO y mermas proyectadas

1. **Lectura as-of.** Se usa la **última foto de lotes con `snapshot_date` ≤ corte**, elegida **en la base** (`inventory_lots(as_of)`: la foto máxima ≤ corte por sucursal × producto; no se transfiere el historial de fotos);
   una foto posterior al corte no existe para ese corte. Una foto más de `perishability_lots_max_age_days` anterior al snapshot de existencias se **ignora por obsoleta** (sus cantidades ya no describen el stock). Pruebas:
   `test_lots_asof_takes_the_last_snapshot_not_after_the_cutoff`, `test_lots_are_read_as_of_the_cutoff_and_stale_lots_are_ignored`,
   `test_supply_w5d_integration.py::test_a_historical_cutoff_ignores_a_lots_snapshot_taken_after_it` y `test_w5d_repository_queries_are_scoped_to_the_company_and_the_cutoff` (solo la última foto, solo la empresa).
2. **La existencia manda.** La cantidad física es `on_hand`; los lotes aportan el vencimiento. Si los lotes suman más que la existencia se descarta, por defecto (`perishability_excess_drop = latest_first`), lo que vence **después**:
   es la elección **conservadora** (queda lo que vence antes, de modo que la merma proyectada es MAYOR). `earliest_first` descarta lo que vence antes, que es lo coherente con un consumo FEFO desde la foto (lo vendido salió de
   los primeros) y proyecta una merma MENOR; elegir uno u otro es un parámetro a aprobar en el piloto con datos de la empresa. Si los lotes suman menos, el resto es existencia **sin vencimiento declarado**, sale después de los lotes con fecha
   y nunca vence. Lo reservado fuera de la demanda sale primero de los lotes que vencen antes.
3. **Vencidas al corte** (`expiry ≤ corte`): están en el estante pero no son vendibles; se excluyen de la apertura utilizable y se informan (`expired_on_shelf`). **Si el ERP ya dio de baja los vencidos de `on_hand`** no deben restarse dos
   veces: `perishability_expired_stock_mode = written_off` los ignora; por defecto (`in_on_hand`) se restan, y el plan **detecta la sospecha** (excedente de lotes igual a lo vencido) y la avisa en las notas de la serie.
4. **Merma proyectada.** Simulación diaria FEFO con la demanda **ya persistida** del pronóstico (`yhat` por defecto; **nunca se recalcula**), neta de pedidos confirmados (§1). Lo que no se vende antes del último día vendible de su
   lote es la merma esperada y se **descuenta de la existencia de apertura** del plan (las compras nuevas, más frescas, salen después y no la alteran). El escenario de **demanda baja** usa los cuantiles diarios `lo_80` persistidos
   (`forecast_values`); sumar cuantiles diarios no es el cuantil de la suma: es una **cota conservadora**, no un P10 del acumulado. `perishability_loss_demand_basis = lo_80` descuenta esa merma en vez de la esperada.
   Un lote que vence más allá de los días cubiertos por el pronóstico queda `unresolved` (no se extrapola). Pruebas: `test_lots_fefo.py`, `test_expired_units_and_projected_fefo_loss_reduce_the_opening_stock_and_are_reported`,
   `test_loss_uses_the_persisted_quantiles_for_the_low_demand_scenario_and_never_recomputes_the_forecast`, `test_lot_reconciliation_policies_are_configurable_and_declared`.

### 2.2 Riesgo de vencimiento en el plan (auditoría independiente)

Tras resolver, `audit_plan` **re-simula el plan día a día con FEFO** (lotes existentes + recepciones abiertas + compras del plan) y entrega la merma esperada y la de demanda baja por serie y total, en unidades y en
valor (`details.perishability.audit`; `?include=perishability_detail`). Es la verificación del tope (§2.3), no su supuesto: separa lo perdido de los lotes existentes de lo perdido por las compras del plan. La auditoría en
demanda baja evalúa el **plan completo** (incluye compras dimensionadas con la media), por eso puede superar la merma de los lotes solos.

- **Supuesto de llegada (se declara en el informe, `arrival_assumption`).** Una recepción o compra queda disponible desde el **inicio del período** en que el modelo la hace disponible y con la **vida útil completa**
  (fracción `perishability_receipt_life_fraction`). La recepción física puede ocurrir antes dentro del período, y entonces la vida útil corre desde allí: la merma real puede ser algo MAYOR cuando el período es largo frente a la vida útil.
- **Valoración (`valuation`).** El valor de la merma y el margen perdido se calculan por **(sucursal, producto)** y solo con el **costo de una oferta explícita** del proveedor para esa serie (`cost_source = supplier_offer`). Una oferta
  **implícita** (último costo conocido, sin tabla de ofertas) no es un costo confiable: la serie **no se valora** (valor `null`) y el informe dice cuántas series se valoraron y cuántas no (`series_valued`, `series_not_valued`); las
  unidades se informan siempre. (Criterio propio de Supply: «oferta explícita vigente»; la noción de `cost_unreliable` del Decision Engine se refiere al costo de las ventas.)

Pruebas: `test_plan_reports_fefo_expiry_losses_the_no_purchase_scenario_and_ignores_other_companies_lots`, `test_expiry_loss_is_valued_per_branch_and_only_with_a_reliable_cost`.

### 2.3 Compras nuevas: topes por vida útil

Con vida útil declarada `S` (efectiva: `S·fracción`) y período de revisión `R`, el plan aplica **dos topes**, ambos como restricciones del MILP, verificados de forma independiente (`shelf_life_cap` en `verify.evaluate`) y respetados por el respaldo heurístico:

1. **Arrastre (`S ≥ R`).** El stock al **cierre de cada período** se limita a lo vendible dentro de la vida útil (`shelf_cap_by_period`): una unidad disponible desde el inicio del período `a` vence el día `a·R + S`; la que sigue en el estante
   al cierre de `a` (día `(a+1)·R`) debe venderse en los días `(a+1)·R+1 … a·R+S`. Si el stock de cada cierre no supera la demanda de esa ventana, ninguna unidad nueva vence sin venderse (cola FEFO).
2. **Disponibilidad (`S < R`).** Con vida útil menor que el período, el tope de arrastre es 0 y **no basta**: lo que llega al inicio de un período también vence DENTRO de él si no se vende en sus primeros `S` días. Por eso lo
   **disponible al inicio de cada período** (arrastre + recepciones) se limita a la demanda de sus primeros `S` días (`shelf_avail_cap_by_period`; fila `shelf_avail` del MILP). El plan por períodos no puede cubrir los `R − S` días
   restantes de cada período: esa demanda se declara **sin atender por vida útil** (`reason = shelf_life`), la serie queda marcada (`perishability_issues = shelf_life_shorter_than_period`, limitación explícita) y la calidad del plan baja a
   `degraded`. La salida correcta es **acortar el período de revisión** a un valor ≤ la vida útil (o modelar subperíodos, **DISEÑADO / NO IMPLEMENTADO**). Antes de esta corrección el tope valía 0 sin acotar lo disponible: con `S = 3`, `R = 7`
   el plan compraba 840 u. y su propia auditoría perdía 480 por vencimiento (57 %); ahora compra la demanda de 3 días por semana y la auditoría no encuentra merma.

Reglas comunes: la ventana debe estar **cubierta por el pronóstico** (si no, sin tope, no se extrapola); los topes **nunca fuerzan el stock por debajo del piso ya comprometido** (existencias y recepciones abiertas; ese exceso
preexistente se informa); la vida útil se cuenta desde el inicio del período en que la recepción queda disponible (supuesto de §2.2). Si ningún lote de la oferta cabe bajo el tope, **no se compra** y la demanda sin atender se
declara. Con cobertura obligatoria (`hard`) y un tope activo el modelo puede ser infactible: la relajación `shelf_life` forma parte del diagnóstico (causa `shelf_life`, mensaje «vida útil de los productos perecibles»).

Pruebas: `test_a_plan_never_buys_more_than_can_be_sold_before_expiry_whatever_the_shelf_life_versus_the_review_period` (S ∈ {1,2,3,5,6,7,10,13} con R ∈ {2,3,7,14}: la merma esperada de la auditoría es ≈ 0),
`test_the_reviewers_case_shelf_life_3_with_a_weekly_review_no_longer_buys_480_units_that_expire`, `test_hard_coverage_with_a_shelf_life_cap_diagnoses_the_shelf_life_as_the_cause`,
`test_shelf_life_caps_period_end_stock_so_the_plan_never_buys_more_than_it_can_sell_before_expiry`, `test_with_fine_lots_the_plan_covers_demand_and_the_independent_audit_finds_no_loss_from_new_receipts`,
`test_the_cap_never_forces_stock_below_what_is_already_on_hand_and_the_excess_is_reported`, `test_verifier_and_heuristic_respect_the_shelf_cap`, `test_the_heuristic_fallback_alone_never_returns_a_plan_that_violates_the_shelf_caps`,
`test_shelf_life_declared_on_a_product_not_marked_perishable_is_ignored_even_when_it_has_lots`.

### 2.4 Durables sin cambios (prueba)

`test_a_product_without_shelf_life_or_lots_is_planned_exactly_as_before_and_the_plan_says_so` (nodos, compras y costo idénticos; sin texto de perecibilidad para un durable) y
`test_perishability_can_be_switched_off_and_then_lots_and_shelf_life_have_no_effect` (`perishability_enabled = false`). Comparación antes/después sobre datos ficticios: §9.

## 3. Política de reposición explícita, restricciones duras y escenario «sin compra»

**IMPLEMENTADO / VERIFICADO (exposición); el cálculo es el de la etapa 1.** `supply/policy.py`; `details.replenishment_policy` (`?include=replenishment_policy`).

- **Stock de seguridad (SS) y punto de reorden (ROP)** por serie, tal como los calculó y **persistió** la etapa 1 (`recommendations`); el plan los expone con su fórmula, nivel de servicio (`z`), plazo, variabilidad del
  plazo y método de incertidumbre, sin recalcularlos (una sola fuente). Fórmulas: `normal_z_sigma` → `SS = z(NS)·σ_L`; `empirical_quantile` → cuantil NS de los errores de pronóstico acumulados en `L = plazo + revisión`;
  `fraction_of_coverage_demand` → política provisional explícita (incertidumbre desconocida); con variabilidad del plazo `SS = √(SS² + (z·d̄·σ_plazo)²)`; `ROP = demanda en L + SS`. Prueba:
  `test_replenishment_policy_is_exposed_with_its_formula_service_level_and_lead_time_variability`. La prueba del SS de la ruta empírica con valor exacto (V25-SUP-007) sigue **PENDIENTE** (vive en `inventory/`, sin cambios aquí).
- **MOQ, múltiplos, presupuesto, capacidad de almacén/proveedor/recursos y vigencia** son restricciones **duras** del MILP sobre una demanda pronosticada (media); el stock de seguridad entra como déficit penalizado, no como
  restricción. Heredado de P4, `test_supply_planner.py`.
- **Escenario «sin compra»** (V25-SUP-023): `details.no_purchase_baseline` evalúa el plan sin ninguna compra nueva, con las mismas existencias y recepciones abiertas: unidades no atendidas, serie y **primera fecha de
  faltante** y margen perdido solo cuando precio y costo son conocidos (si no, `lost_margin` es nulo para la serie, no 0). Prueba: `test_no_purchase_scenario_reports_what_is_lost_if_nothing_is_ordered`.
- **Nunca inventarios negativos ni planes imposibles.** La apertura se acota en 0 **y se declara** (§1); y un plan que ni el solver ni el respaldo logran hacer factible **no devuelve líneas** (`not_solved` descarta las líneas
  que no pasaron la verificación independiente; V25-SUP-031). Prueba: `test_a_plan_that_neither_solver_nor_fallback_can_make_feasible_returns_no_lines`.
- **Ninguna compra automática**: las líneas son sugerencias (`actionable_now` = emitible en la fecha de corte); ejecutar requiere permisos y aprobación del flujo de decisiones (W5-B), **DISEÑADO**, no de este paquete.

## 4. Simulación cronológica as-of

**IMPLEMENTADO / VERIFICADO.** Código: `supply/simulation.py`, `supply/simulation_runner.py`, `supply/asof_inputs.py`, `db/supply_repository.py`.

Regla (ADR-V25-23): en cada **revisión** la simulación decide con lo conocido **ese día**; la demanda de la ventana (lo ocurrido después de cada corte) es la «verdad» conocida al final.

| Insumo | Antes | Ahora |
|---|---|---|
| Ventas previas a la ventana | una sola lectura final (un documento tardío contaminaba revisiones anteriores) | `pre_history(d)`: lo conocido al cierre del día `d` (`SalesVersions.sales_cells(d, d)`); solo se reconstruye si difiere de la verdad (`differs_from_truth`) |
| Costo de compra | el del fin de la ventana, más `current_cost` del catálogo | `asof_costs`: `known_at ≤ corte` y `valid_from ≤ corte` por revisión. Respaldo: el costo del snapshot de existencias (as-of) y, como último recurso, el **costo del catálogo vigente, declarado «NO VERSIONADO»** en los supuestos (para que una empresa sin historial de compras no pierda sus series; su costo y margen no son as-of). Una serie sin ningún costo se **excluye** y se declara |
| Precio de venta | `recent_net_prices` del fin de la ventana o `current_price` | neto observado ≤ cada revisión (`recent_net_prices(cut, 28, knowledge=cut)`); sin ventas recientes se conserva el anterior y, si nunca hubo, margen 0 |
| Plazos | todas las filas de `supplier_lead_times` | `lead_times_asof(cut)`: `valid_from ≤ corte` (D2) |
| Existencias / lotes iniciales | foto exacta del día previo | última foto **≤ corte** (lotes con `snapshot_date ≤ corte`), con límite de antigüedad; lo vencido al corte se excluye |
| Vencimiento | no se modelaba | FEFO por serie perecible: los lotes iniciales y las compras nuevas (vida útil completa) pierden lo que no se vende; métrica `expired_units`/`expired_value` |

`recent_net_prices` y `sales_cost_history` (también los usan **escenarios** y **costos**) leen ahora de `sales_line_versions` con la misma regla de visibilidad que la demanda (`asof_knowledge`: un corte anterior a la
última venta conocida es una reconstrucción histórica, `K = corte`; uno posterior es vigente, `K = hoy`). Pruebas: `test_supply_w5d_integration.py::test_recent_prices_and_sales_cost_history_are_read_as_of_the_cutoff` (con una
venta tardía y una anulación posterior, y paridad con `SalesVersions` en pandas) y `test_lead_times_are_filtered_by_valid_from_for_a_past_cutoff`.

**Prueba de fuga.** `test_a_late_sales_document_does_not_alter_decisions_taken_before_it_was_known` (BD real): una venta previa a la ventana, entregada por la fuente el día 9 de la ventana, **no cambia** las decisiones de las
revisiones anteriores y sí las de las posteriores. Variante sin BD: `test_supply_asof_simulation.py::test_each_review_sees_the_cost_lead_time_and_prior_sales_known_that_day` (incluye la demostración de que la conducta anterior filtraba el
futuro) y `test_series_tables_decisions_of_a_review_do_not_depend_on_costs_or_lead_times_known_later`.

**Métricas** (sobre lo ocurrido después de cada corte): `fill_rate`, `unmet_units`, `stockout_days` (días-serie con demanda no atendida) y `stockout_day_share`, `overstock_*`, `avg_inventory_value`, `days_of_inventory`,
`inventory_turnover = 365 / días de inventario` (V25-SUP-041), `avg/peak_capital_committed` (inventario + órdenes en camino), `expired_units/value`, costos de mantenimiento y de compra, `lost_margin` y
`operating_cost_proxy`. Verificadas con casos a mano (`test_days_of_inventory_turnover_and_capital_match_hand_calculations`: inventario medio 30 $ y costo de ventas diario 10 $ ⇒ 3,0 días; orden en camino incluida en el capital).
El horizonte del pronóstico ligero de cada revisión cubre el **mayor plazo de la ventana** (no el del inicio): `test_the_forecast_horizon_covers_the_longest_lead_time_of_the_window_not_the_one_at_its_start`. Una existencia inicial de más de `inventory_stale_days` excluye la serie (`test_a_stale_initial_inventory_snapshot_excludes_the_series_from_the_simulation`). Con insumos estáticos (datos sintéticos) el resultado es idéntico bit a bit (`test_static_inputs_give_the_same_result_with_or_without_the_as_of_machinery`, §9).

**Plan retroactivo (D1/D2).** En un corte pasado el precio de venta es el neto observado ≤ corte (no `products.current_price`; sin ventas recientes queda desconocido) y los plazos se filtran por `valid_from`. El plan lo
declara en sus limitaciones.

## 5. Calidad del plan y degradación

La calidad del plan baja a `degraded` ante sobrerreserva, físico negativo o snapshot obsoleto (§1) y ante una vida útil menor que el período de revisión (§2.3), además de las causas anteriores. **Prueba de que la degradación ocurre de
verdad** (aislada de la causa «incertidumbre desconocida»): `test_over_reserved_stock_is_declared_with_the_clipped_quantity_and_degrades_quality` (`_quality` con incertidumbre empírica, una causa a la vez) y
`test_supply_w5d_integration.py::test_over_reserved_stock_degrades_the_stored_plan_quality_while_the_same_plan_without_it_is_ok` (`core_runs.quality_status`: `ok` sin sobrerreserva, `degraded` con ella). Las limitaciones y supuestos se
guardan en `core_runs` (`assumptions`, `limitations`).

**Huella y versión de configuración.** La configuración efectiva del core `supply` **creció** con las claves de §8, de modo que `config_version` y la huella `data_snapshot` (que incluye la configuración efectiva) **cambian en TODOS los planes**,
también en los de productos no perecibles, aunque su contenido (líneas, cantidades, fechas, costos) sea idéntico. Lo que se mantiene es la **identidad del plan**, no el valor de la huella. Los marcos de datos de W5-D (lotes,
perecibilidad, cuantiles, ajustes, pedidos confirmados) se agregan a la huella **solo cuando existen**.

## 6. Backend: importación y validación

**IMPLEMENTADO / VERIFICADO.** `backend/app/Services/Imports/` (solo código nuevo): entidad `inventory_lots` (`ImportEntity::InventoryLots`, `InventoryLotImportProcessor`, campos en `ImportFieldRegistry`) y las columnas
`is_perishable` / `shelf_life_days` en la importación de productos (`ProductImportProcessor`). `GET /products` devuelve ambos campos. Upsert idempotente por
(empresa, sucursal, producto, lote, fecha de corte); cada empresa solo ve sus lotes (`BelongsToTenant`). Pruebas: `backend/tests/Feature/Supply/InventoryLotsImportTest.php` (13), `SupplyPerishabilityPlanApiTest.php` (2).

**Reglas del importador de lotes.**

- **Reemplazo por foto.** Un archivo reemplaza la foto de cada (sucursal, producto, fecha de corte) que trae: un lote de esa foto que un archivo corregido ya no trae **se elimina** (hallazgo `lots_replaced` con su conteo, ya en la validación; la
  validación no escribe ni elimina; el reemplazo y el upsert van en una sola transacción). Un lote cuya fila fue **rechazada** NO se elimina (una fila inválida nunca borra un lote bueno); las fotos de otras fechas y los productos que
  el archivo no trae no se tocan. Pruebas: `test_a_corrected_snapshot_replaces_the_lots_of_its_branch_product_and_date_without_touching_the_rest`, `test_a_rejected_row_never_deletes_a_good_lot_of_the_snapshot_it_replaces`.
- **Código de lote en mayúsculas**: `l-001` y `L-001` son el mismo lote (el duplicado dentro del archivo se detecta). Prueba: `test_lot_codes_differing_only_in_case_are_the_same_lot_and_an_oversized_quantity_is_a_row_finding_not_a_job_failure`.
- **Magnitud de la cantidad**: debe ser menor que 10^12 (la columna es decimal(16,4)); mayor es un hallazgo de la fila (`invalid_number`), no un fallo del job al confirmar (misma prueba).
- **Productos: cada columna de perecibilidad se evalúa y escribe solo si el archivo la mapea**; la que no viene no se toca ni se deriva de la otra. Con la columna mapeada, la celda vacía significa «no perecible» (bandera) o «vida útil no declarada»
  (NULL). Pruebas: `test_a_products_file_with_only_one_perishability_column_never_touches_the_other` (ambos casos de columna única) y `test_invalid_perishability_values_are_quarantined_and_unmapped_columns_do_not_overwrite_the_declaration`.

Hallazgos (clase del catálogo `DataQualityCatalog`, documentados en `docs/data/DATA_QUALITY_ENGINE.md` §2). **Nada se descarta en silencio**: cada fila inválida queda en `import_errors` y en `data_quality_issues`.

| Código | Clase | Efecto |
|---|---|---|
| `negative_lot_quantity` | QUARANTINED | cantidad de lote negativa: la fila no se escribe |
| `invalid_date` (vencimiento) | QUARANTINED | vencimiento no interpretable: la fila no se escribe |
| `expiry_implausible` | QUARANTINED | vencimiento a más de 15 años del corte (`MAX_EXPIRY_YEARS`, a aprobar en el piloto) |
| `invalid_number`, `required`, `duplicate_in_file`, `unknown_branch`, `unknown_product`, `future_date` | QUARANTINED / REVIEW_REQUIRED | como el resto de entidades |
| `lot_expired_on_snapshot` | WARNING | lote ya vencido al corte: se escribe; el motor lo excluye de lo vendible |
| `lot_expiry_missing` | WARNING | perecible sin vencimiento: se escribe; el motor no inventa la fecha |
| `expiry_exceeds_shelf_life` | WARNING | vencimiento posterior a la vida útil declarada |
| `lots_exceed_on_hand` | WARNING | los lotes suman más que la existencia del snapshot del día (el motor limita los lotes) |
| `lots_replaced` | WARNING | la foto se reemplaza: los lotes de la misma sucursal × producto × fecha que el archivo no trae se eliminan |
| `invalid_value` (perecible) / `invalid_number` (vida útil 1–3650) | QUARANTINED | bandera no reconocida o vida útil fuera de rango |
| `perishable_without_shelf_life` / `shelf_life_without_perishable` | WARNING | perecible sin vida útil (no se proyectan vencimientos) / vida útil en un no perecible (no se usa) |

Fronteras probadas: un lote con vencimiento igual a la fecha de corte ya está vencido (`lot_expired_on_snapshot`, `test_the_expired_on_snapshot_boundary_is_inclusive_of_the_snapshot_day`); la perecibilidad de una empresa no
contamina a otra con el mismo SKU (`test_perishability_of_another_company_with_the_same_sku_never_leaks_into_a_lot_import`). Nota: el filtro de empresa de la comparación con `inventory_snapshots` es defensa en profundidad
(las FK compuestas ya impiden que un `product_id` ajeno aparezca en esas filas), por lo que no hay prueba que lo distinga.

## 7. API

`GET /api/v1/supply/plans/{run}` añade (aditivo): `availability`, `perishability`, `no_purchase_baseline` y, con `?include=inventory_position,perishability_detail,replenishment_policy`, los detalles por serie y la política. `docs/openapi/v2.yaml`
(y `core.yaml`: `Product.is_perishable`, `Product.shelf_life_days`). No hay rutas nuevas.

## 8. Configuración (`supply`; validada en `platform/config.py`)

| Clave | Por defecto | Rango | Justificación |
|---|---|---|---|
| `perishability_enabled` | `true` | bool | interruptor; `false` restituye el plan anterior |
| `perishability_enforce_shelf_cap` | `true` | bool | limita las compras nuevas a lo vendible dentro de la vida útil |
| `perishability_receipt_life_fraction` | 1,0 | 0,05–1 | SUPUESTO: el proveedor entrega con la vida útil completa; bajar si entrega con menos |
| `perishability_lots_max_age_days` | 7 | 0–365 | una foto de lotes más antigua que el snapshot de existencias deja de describir el stock |
| `perishability_loss_demand_basis` | `yhat` | `yhat` \| `lo_80` | demanda con que se proyecta la merma que reduce la apertura (`lo_80` = conservador) |
| `perishability_excess_drop` | `latest_first` | `latest_first` \| `earliest_first` | qué lote se descarta si los lotes suman más que la existencia (conservador: merma mayor) o coherente con consumo FEFO (merma menor) |
| `perishability_expired_stock_mode` | `in_on_hand` | `in_on_hand` \| `written_off` | `written_off`: el ERP ya dio de baja los vencidos de `on_hand`, no se restan dos veces |
| `commitments_mode` | `reconcile` | `reconcile` \| `ignore` | `ignore` restituye la conducta anterior |
| `adjustments_mode` | `apply` | `apply` \| `ignore` | ajustes posteriores al snapshot; activo por defecto pero **sin efecto hasta que exista un importador de `stock_movements`** |
| `inventory_stale_days` | 30 | 1–3650 | antigüedad desde la cual un snapshot se declara obsoleto |

Todos son valores **iniciales de diseño, a aprobar en el piloto** (con la distribución real de vida útil, antigüedad de fotos y calidad de lotes de la empresa); ninguno es universal. Los valores de punto flotante rechazan NaN e infinito (`validate_overrides`; `test_non_finite_values_are_rejected_in_the_effective_config`).

## 9. Comparación antes/después (productos no perecibles, configuración por defecto)

Herramienta `engine/tests/supply_before_after.py` (datos **ficticios** del generador `ferreteria_andina` y `distribuidora_costa`, semilla 42): construye `PlanInputs` desde la demo, resuelve y presenta tres planes
(base, con presupuesto, con capacidad de almacén) y corre la simulación de 3 políticas, contra el árbol en `d54076e` (copia con `git archive`) y contra el árbol de trabajo. Contrato: **los planes de productos no perecibles no cambian cuando no hay pedidos confirmados cargados** (§1: con pedidos cargados el plan cambia por diseño). Resultado: las **líneas, cantidades, fechas, costos,
faltantes y nodos (apertura, demanda, recepciones, stock de seguridad) son idénticos** en los tres planes (51 + 51 + 37 líneas) y las métricas de las tres políticas de la simulación son idénticas. La base se repitió dos veces
(reproducible). El contenido del plan no cambia; sí cambian `config_version` y la huella `data_snapshot` por la configuración ampliada (§5). Además, los 92 casos unitarios previos (`test_supply_{inputs,balance,planner,offers,simulation}.py`) y los 12 de integración de P4 (`test_supply_integration.py`) pasan sin modificar.

## 10. No implementado, bloqueado o fuera de este paquete

| Tema | Estado |
|---|---|
| Vía productiva de ingesta de **órdenes de compra** y recepciones (V25-SUP-051/004) | **PENDIENTE** (no hay importador; el balance fechado solo se ejerce con datos en `purchase_orders`) |
| Ingesta de **ajustes de inventario** (`stock_movements`; V25-SUP-005) | motor listo (lee y separa); **sin importador**: PENDIENTE |
| Descuento de **ventas posteriores al snapshot** en un snapshot antiguo | **DISEÑADO**: el snapshot obsoleto se declara, no se corrige |
| Presupuesto reconciliado con costo financiero, plazos de pago y compromisos por pagar (V25-SUP-026) | **DISEÑADO / NO IMPLEMENTADO** |
| Compuerta `planner_gate` (MILP «recomendado» solo si supera a la política actual; V25-SUP-029), OR-Tools (SUP-046), costo de servir (SUP-036), capacidad logística (SUP-015) | **DISEÑADO / NO IMPLEMENTADO** |
| Métrica de **almacenamiento** en la simulación (V25-SUP-043) | **NO IMPLEMENTADO** (la simulación no recibe capacidades) |
| Causa del faltante por contrafactual del producto (V25-SUP-018, D21); huella completa y reproducibilidad con límite de tiempo (D5, D9); coherencia recomendación-plan (SUP-022) | **PENDIENTE** |
| Escenarios (`scenarios/`) y recomendaciones de la etapa 1 (`pipeline/`) siguen usando `repo.lead_times()` sin filtrar `valid_from` y recortan la sobrerreserva sin declararla (D2/D4) | **PENDIENTE** (fuera de los archivos de W5-D); `recent_net_prices`/`sales_cost_history` ya son as-of para ellos |
| Umbral de sobrestock por clase de perecibilidad (D8) y `stockout_risk_threshold` de la empresa (D7) | **PENDIENTE** (`inventory/recommendations.py` no cambió) |
| Planificación con **subperíodos** para vida útil menor que el período de revisión (hoy: se acota lo disponible y se degrada, §2.3) | **DISEÑADO / NO IMPLEMENTADO** |
| Costo del catálogo en la simulación sin historial de compras ni costo en el snapshot | se usa y se declara **NO VERSIONADO** (§4); no es as-of |
| Perecibilidad del catálogo en cortes pasados | el atributo es el **declarado hoy** (el catálogo no versiona esos campos); el plan retroactivo lo declara |
| Validación con datos reales de operación (V25-SUP-049) | **BLOQUEADO**: requiere piloto |
| Simulación Monte Carlo (V25-SUP-030) | **NO APLICA** hasta contar con datos y costo-beneficio |
