# P4 · Decisiones comunes: abastecimiento, costos y escenarios

Paquete P4 de la etapa 2 (`instrucctions.md` §6.F y §6.G; ADR-V2-02, 03, 09 y 11 de
`docs/architecture/PREDICTILY_V2_GAP_ANALYSIS.md`). Todo lo descrito está implementado y probado con datos
**sintéticos**; nada se ha validado con datos reales de una empresa (ver §13).

**Reglas que gobiernan todo el paquete.** Las recomendaciones son *sugerencias*: Predictily no envía pedidos ni
compromete fondos. Lo desconocido queda `null` (plazo, mínimo, múltiplo, capacidad, vigencia, `known_at`, costo) y
nunca se rellena con ceros. Nada se convierte en silencio: una oferta sin conversión de unidades o sin tasa de cambio
explícita es *no comparable* y se declara. Ninguna regla sectorial vive en el core. No interviene ningún LLM.

## 1. Mapa de componentes

| Capacidad | Motor (`engine/predictily_engine/`) | Backend (`backend/app/`) |
|---|---|---|
| Balance fechado | `supply/balance.py`, `supply/inputs.py` | — (se lee en el plan) |
| Ofertas, unidades, monedas | `supply/offers.py`, `units.py`, `fx.py`, `availability.py` | `Http/Controllers/Api/Supply/{Offer,ExchangeRate}Controller` |
| Planificador MILP | `supply/{model,planner,verify,heuristic,diagnosis,service,present,plan_runner}.py` | `Services/Supply/*`, `Jobs/Supply/RunSupplyPlan`, `PlanController` |
| Producción genérica | `supply/inputs.py` (`_build_production`), `planner.py` | `ProductionResourceController`, `ProductionRecipeController` |
| Pronóstico de costos | `costs/{forecast,runner}.py` | `CostForecastController`, `Jobs/Supply/RunCostForecast` |
| Evaluador de escenarios | `scenarios/{evaluator,runner}.py` | `ScenarioEvaluationController` |
| Contrato causal | `causal/{contract,runner}.py` | `CausalController` |
| Simulación histórica | `supply/{simulation,simulation_runner,simulation_demo}.py` | `SimulationController`, `Jobs/Supply/RunSupplySimulation` |
| Ejecución y trazabilidad | `supply/{runs,dispatch}.py`, `db/supply_repository.py`, `api/routers/supply.py` | `core_runs`, `training_jobs`, `data_snapshots` |

Migración propia: `backend/database/migrations/2026_02_04_000100_create_supply_decision_tables.php` (§11).

## 2. Convenciones temporales

* `as_of` es el último día con información (la fecha de corte del pronóstico de demanda usado). El día 1 es el siguiente.
* El período `τ` (0, 1, …) cubre los días `τ·R + 1 … (τ+1)·R` posteriores a `as_of`, con `R` = días del período
  (por defecto el período de revisión, 7).
* Una orden se emite al cierre del día `as_of + t·R` (`t` = período de emisión; `t = 0` es la decisión de hoy). Con plazo
  `L ≥ 1` días llega el día `t·R + L`, utilizable para la demanda de ese día.
* **Regla conservadora de disponibilidad por períodos:** una recepción que llega a mitad de un período se considera
  disponible desde el período siguiente: `τ_a = t + ⌈(L − 1)/R⌉`. Nunca adelanta una recepción; puede adelantar
  ligeramente una compra. Con `R = 1` es exacto.
* Solo la emisión de hoy (`t = 0`, `actionable_now = true`) es accionable; las demás se replanifican en cada revisión.
* Sin valor terminal: cerca del final del horizonte el plan consume el stock de seguridad. Es un efecto de horizonte
  finito, no una recomendación.

### Disponibilidad de la información (`known_at`)

Regla única (`supply/availability.py`), usada por ofertas, tasas de cambio y costos:

1. `known_at` presente: el dato existe para el corte solo si `known_at` (hora local de la empresa) ≤ corte.
2. `known_at` NULL en una ejecución **vigente**: se acepta (es información actual de la empresa).
3. `known_at` NULL en una ejecución **retroactiva** (corte histórico, backtest, simulación): se usa la fecha de respaldo
   *solo* si el llamador aporta evidencia propia (el costo de una compra real se conoce en `valid_from`); si no, el dato
   se **excluye** y se cuenta como exclusión por disponibilidad desconocida. La ingestión actual de un histórico no
   demuestra que algo se conociera antes. El pronóstico de costos admite la política `assume_valid_from`, que incluye
   esos datos pero degrada la calidad a `degraded` y lo declara.

## 3. Modelo matemático del planificador (`supply/planner.py`)

Resuelto con `scipy.optimize.milp` (HiGHS, ADR-V2-09). OR-Tools no es necesario.

### 3.1 Conjuntos y parámetros

* `N` nodos `n = (l, p)`: producto `p` en ubicación (sucursal) `l`, con demanda pronosticada conocida durante `T_n`
  períodos. Un insumo sin pronóstico propio entra como nodo *derivado* con demanda independiente 0.
* `D[n,τ]` demanda media del pronóstico (suma de los días del período). `I⁰[n]` existencias físicas utilizables
  (existencias − reservas). `K[n,τ]` recepciones de órdenes abiertas por período (según §4).
* `SS[n]` stock de seguridad (de las recomendaciones de la etapa 1; si la incertidumbre es desconocida es la política
  provisional de esa etapa, y el plan lo declara). `s[n]` espacio por unidad (conversión a la unidad de capacidad).
* Ofertas `o` normalizadas (§5): costo puesto en bodega `c_o` por unidad del producto, lote `q_o` (múltiplo), mínimo
  `m_o`, costo fijo por línea `F_o`, capacidad por período `C_o`, plazo `L_o(l)`, vigencia `[v_o⁻, v_o⁺]`.
* Presupuestos `B_j` (sucursal o toda la empresa, ventana `[a_j, b_j]` de **fechas de emisión**), capacidad de almacén
  `S_l`, recursos `R_r`, recetas `r` con rendimiento `y_r`, producción nominal `u_r`, insumos `a_{r,i}`, tiempo `d_r`.
* Costos del objetivo: mantenimiento `h_n` por unidad-período (`tasa anual × costo × R/365`, **supuesto**, 20 % por
  defecto); penalización por unidad no atendida `π_n` (**supuesto explícito**: `k × costo puesto en bodega`, `k > 1`,
  `k = 2` por defecto, ponderada por la prioridad configurada; o el precio de venta en `max_margin`; o un valor
  explícito por producto).

### 3.2 Variables

| Variable | Dominio | Significado |
|---|---|---|
| `k[o,l,t]` | ℤ≥0 | lotes comprados de la oferta `o` para `l`, emitidos en `t` (cantidad = `q_o · k`) |
| `y[o,l,t]` | {0,1} | la línea se emite (solo si hay mínimo o costo fijo) |
| `x[r,l,t]` | ℤ≥0 | lotes de producción de la receta `r` iniciados en `t` |
| `I[n,τ]` | ≥ 0 | existencias al cierre del período |
| `U[n,τ]` | [0, D] | demanda no atendida (ventas perdidas); fija en 0 en modo `hard` |
| `V[n,τ]` | [0, SS] | déficit respecto del stock de seguridad (solo `τ ≥ τ_ss`, primer período alcanzable por una compra nueva) |

### 3.3 Restricciones

1. **Balance** (ventas perdidas): `I[n,τ] = I[n,τ−1] + K[n,τ] + Σ q_o·k[o,l,t]·1(τ_a=τ) + Σ y_r·u_r·x[r,l,t]·1(arrib.=τ)
   − (D[n,τ] − U[n,τ]) − Σ a_{r,n}·x[r,l,τ]`, con `I[n,−1] = I⁰[n]` e `I ≥ 0` (los insumos deben existir).
2. **Mínimo y lote:** `k ≤ k̄·y`, `k ≥ ⌈m_o/q_o⌉·y` (si `y = 0` no hay compra; si `y = 1` se respeta el mínimo). Las
   cantidades son múltiplos enteros del lote.
3. **Capacidad de proveedor:** `Σ_l q_o·k[o,l,t] ≤ C_o` por período (capacidad declarada prorrateada al período).
4. **Presupuesto:** `Σ (c_o·q_o·k[o,l,t] + F_o·y[o,l,t]) ≤ B_j` sobre las líneas cuya fecha de emisión cae en la ventana
   del presupuesto `j` y su sucursal. Se aplican todos los presupuestos solapados.
5. **Almacenamiento (pico tras recepciones):** `Σ_p s_p·(I[p,l,τ−1] + K + recepciones del plan) ≤ S_l^ef`. La capacidad
   efectiva es `max(S_l, espacio ya comprometido sin compras nuevas)`: si las existencias y órdenes abiertas ya exceden
   la capacidad, ninguna compra lo corrige y el exceso preexistente se informa (no vuelve infactible el modelo).
6. **Recursos:** `Σ uso_r·x[r,l,t] ≤ R_r` por período (la capacidad declarada se prorratea al período).
7. **Stock de seguridad (blando):** `I[n,τ] + V[n,τ] ≥ SS[n]` para `τ ≥ τ_ss`.
8. **Vigencia:** solo existen variables `k[o,l,t]` con fecha de emisión dentro de la vigencia de la oferta.
9. **Cobertura obligatoria (modo `hard`):** `U = 0`.

### 3.4 Objetivo

`min  Σ c_o·q_o·k + Σ F_o·y + Σ costo_lote_r·x + Σ h_n·I[n,τ] + Σ ω[n,τ]·π_n·U[n,τ] + Σ (w·π_n/T_n)·V[n,τ]`

* `min_cost` (por defecto): como arriba. `max_margin`: `π_n` = precio de venta actual (es equivalente a maximizar
  ingresos − costos); sin precio se usa el múltiplo de costo y se anota como supuesto.
* **Peso del stock de seguridad `w` (0,9 por defecto, `< 1`):** la demanda tiene prioridad sobre el stock de seguridad.
  El déficit se divide por `T_n` para que mantener una unidad durante todo el horizonte valga menos que una venta.
* **Ponderación de urgencia `ω[n,τ] = 1 + (T_n−1−τ)/T_n`** (solo en nodos con stock de seguridad). Con ventas perdidas
  y un premio por tener existencias, el modelo podría «no vender» para proteger el stock de seguridad. Trasladar una
  venta del período `τ` al `τ'` cuesta ahora `(τ'−τ)/T_n` por unidad, más que cualquier reducción de déficit
  (`≤ w/T_n` por período), por lo que atender antes siempre conviene. La re-simulación independiente (§3.6) confirma que
  el costo del modelo coincide con el costo del plan simulado.
* Objetivos alternativos y prioridades: `priorities` (peso por producto) y `shortage_penalties` (valor por producto) en
  la solicitud; `objective` y `coverage_mode` configurables.

### 3.5 Estados del solver (nunca se llama «óptimo» a lo que no lo es)

| Estado | Significado | Plan |
|---|---|---|
| `optimal` | brecha probada ≤ min(tolerancia pedida, 0,01 %) | sí |
| `feasible_with_gap` | solución factible con brecha > 0,01 % dentro de la tolerancia pedida; se informa la brecha | sí |
| `time_limit` | se agotó el tiempo; mejor solución factible, **no probada óptima** | sí, con aviso |
| `infeasible` | con cobertura obligatoria no existe plan; se explican causa y alternativas | **no** (alternativas calculadas) |
| `heuristic_fallback` | el solver no entregó una solución utilizable; plan del respaldo heurístico factible | sí, con aviso |
| `not_solved` | ni el solver ni el respaldo produjeron un plan verificable | no |

* **Descomposición:** productos/ubicaciones sin restricción en común (presupuesto, almacén de una misma sucursal,
  capacidad de una misma oferta, recetas y recursos) forman componentes independientes que se resuelven por separado
  (exacto). La brecha global `(Σ objetivo − Σ cota)/Σ objetivo` es una garantía válida; si está dentro de la tolerancia
  el estado global es `optimal` aunque algún componente no haya cerrado su brecha individual. Dos pasadas: el tiempo
  sobrante se reparte entre los componentes que no llegaron a la tolerancia.
* **Respaldo heurístico explícito** (`supply/heuristic.py`): regla voraz por producto (oferta más barata que llega a
  tiempo, lo más tarde posible, lotes, mínimo, capacidad, presupuesto y almacén). No optimiza el costo conjunto y no
  planifica producción. Es factible por construcción y se verifica igual que el MILP.

### 3.6 Verificación independiente y activas

`supply/verify.py` re-simula cada plan sin usar las matrices del modelo: balance, lotes, mínimos, vigencia, plazos,
capacidad de proveedor, presupuesto, almacén, recursos e insumos. Todo plan devuelto pasa por ahí; si el del solver no la
supera, se usa el respaldo. De la re-simulación salen las **restricciones activas** (`used ≥ límite − tol`), las
líneas con su `binding_constraints` y la causa de cada faltante (`lead_time`, `budget`, `supplier_capacity`,
`storage_capacity`, `resource_capacity`, `economic`, `no_offer`, `mixed`).

### 3.7 Infactibilidad y alternativas (`supply/diagnosis.py`)

Con `coverage_mode = hard` (o con un plan `soft` que deja demanda sin atender y tiene restricciones acopladoras) se
resuelve el mismo modelo relajando **una** familia a la vez (presupuesto, capacidad de proveedor, almacenamiento,
recursos) y todas (causa estructural: plazos, falta de ofertas, costo mayor que la penalización). La diferencia de
demanda no atendida atribuye la causa; la solución relajada da el requisito (presupuesto o capacidad necesarios).
Alternativas devueltas: *plan de máxima cobertura factible* (respeta todas las restricciones y declara lo no atendido) y
las cifras de lo que habría que relajar. **Ningún plan relajado se devuelve como plan.** Las atribuciones no son
aditivas (interacciones entre familias).

## 4. Balance fechado (`supply/balance.py`; E2-12)

* Existencias utilizables hoy = existencias − reservas. Las compras abiertas **no** se suman al stock de hoy: cada
  orden llega en su fecha. Una orden `partially_received` aporta solo su saldo pendiente. `cancelled` y `draft` no aportan.
* Fecha desconocida o vencida (esperada ≤ corte y sin recibir): política explícita y configurable
  (`undated_receipt_policy`, `overdue_receipt_policy`): `lead_time_end` (llega al final del plazo del proveedor, como en la
  etapa 1), `observed_lead_time` (emisión + plazo observado del proveedor) o `exclude` (el componente afectado no se calcula
  y se informa la cantidad excluida). Nunca queda disponible hoy.
* **Sin doble conteo:** el tránsito del snapshot de inventario solo se usa si la serie no tiene órdenes abiertas; si las
  tiene, se ignoran y se informa `snapshot_in_transit_ignored`.
* **Evidencia de retrasos:** plazo observado (recibido − emitido) y retraso (recibido − esperado) por proveedor, solo con
  órdenes recibidas hasta el corte y con al menos 5 órdenes. Alimenta la desviación del plazo de la oferta cuando no hay una
  declarada.
* Identidad (probada): `apertura + recepciones − ventas = cierre` y `demanda = ventas + no atendida` por período.
* **Vencimiento (capacidad opcional):** `expiry_projection` proyecta mermas con salida FEFO si se aportan lotes con fecha de
  vencimiento; sin lotes responde `not_configured` (no se inventa). El planificador no optimiza vida útil (limitación).
* Limitación: `purchase_orders` no tiene fecha de cancelación; una orden cancelada se excluye siempre, también en cortes
  anteriores a la cancelación.

## 5. Proveedores y ofertas (`supply/offers.py`; E2-26)

* Tabla de ofertas por proveedor y producto: costo, **moneda y unidad obligatorias**, mínimo, múltiplo, plazo (y su
  desviación), capacidad (con su período), vigencia, `known_at` y gastos de adquisición explícitos (porcentaje no
  recuperable, costo por unidad de la oferta y costo fijo por línea de orden). El IVA recuperable no es costo; si no lo
  es, se declara en `acquisition_cost_pct`.
* **Unidades:** `unit_conversions` (`cantidad_to = cantidad_from × factor`; específica del producto o general; directa o
  inversa; sin encadenar). Sin conversión ⇒ oferta no comparable. El mínimo, el múltiplo y la capacidad se convierten a
  unidades del producto.
* **Moneda:** `exchange_rates` explícitas con fecha y fuente (directa o inversa, sin triangular). Sin tasa ⇒ no
  comparable. Una tasa de más de `fx_max_age_days` se usa pero se marca `stale`. Un presupuesto en una moneda sin tasa
  **bloquea el plan** (`insufficient_data`): no se genera un plan que pueda exceder el presupuesto.
* Costo puesto en bodega por unidad del producto: `(precio·(1+pct) + por_unidad) / factor × tasa`.
* **Ofertas implícitas:** un producto sin ninguna oferta explícita usa su último costo conocido (compras con
  disponibilidad conocida; catálogo en ejecuciones vigentes) y el plazo de la etapa 1, sin mínimo, múltiplo ni capacidad
  (desconocidos). Se marcan `implicit_offer` y se declara en los supuestos.
* **Una oferta barata que llega tarde no oculta faltantes previos.** El MILP carga la demanda no atendida con su
  penalización, y el plan la muestra por período y causa. Cada línea trae una `justification` contrafactual exacta
  (se sustituye la oferta por cada alternativa y se compara el objetivo y la demanda no atendida). El informe incluye por
  producto `offer_diagnostics`: ranking por precio, primer período disponible y demanda sin cubrir antes de la llegada.
* **Cobertura de la comparación** (`comparison_coverage`): ofertas recibidas/comparables/excluidas con su motivo,
  productos sin oferta comparable, con una sola fuente y con alternativas.

## 6. Producción genérica (E2-14)

Recursos con capacidad por período y recetas insumo → producto: cantidad nominal por lote, **rendimiento** `(0,1]`,
tiempo de producción, recurso y consumo del recurso, costo de transformación (desconocido ⇒ se trata como 0 con aviso) y
máximo de lotes. La producción entra al mismo MILP: consume insumos al iniciar el lote, entrega `u_r·y_r` unidades útiles
tras `d_r` días (misma regla de disponibilidad por períodos) y respeta la capacidad del recurso. Lotes enteros por
defecto. Sin recetas, la producción queda `not_configured` y **no bloquea** la reposición. Limitaciones: recetas de un
nivel (un insumo no puede ser salida de otra receta; se omite con aviso); la receta aporta coeficientes como datos, sin
leyes del proceso (esas pertenecen al adaptador del rubro).

## 7. Pronóstico de costos (`costs/`; E2-15)

* Se pronostican **costos unitarios**, nunca cantidades. Fuentes: `purchase_cost_history` por producto, proveedor,
  moneda y unidad (series separadas) y, solo para productos sin historial de compras, el costo registrado en las ventas
  (ponderado por cantidad dentro del mes; no es costo de reposición).
* Agregación mensual (promedio de las observaciones disponibles). Un mes sin observación conserva el último costo
  (se declara; no es una observación). Disponibilidad por `known_at` (§2).
* Métodos: referencia simple **último valor**, media de 3 meses, deriva (tendencia) y Holt amortiguado. Protocolo:
  objetivos de selección anteriores al holdout, holdout temporal en los últimos 3 períodos (no participa en la
  elección). Solo se sirve otro método si mejora el MAE de selección en ≥ 3 % frente a la referencia; si no, se sirve la
  referencia y se dice. Menos de 6 períodos observados ⇒ abstención (`insufficient_data`).
* Intervalos empíricos solo con ≥ 8 errores históricos por paso; si no, quedan desconocidos (`null`).
* Salida por serie: método servido y referencia, puntos (`yhat`, `reference`, `lo`, `hi`), métricas de selección y de
  holdout por método, disponibilidad usada/excluida y limitaciones. Resumen: MAE medio del método servido frente a la
  referencia en el holdout.

## 8. Evaluador de escenarios (`scenarios/`; E2-15)

Compara estrategias sobre el **mismo corte, pronóstico base, unidades y horizonte**. Por producto × sucursal y período:
`ventas atendibles = min(demanda, existencias + recepciones)`, `cierre = disponible − ventas ≥ 0` (ventas perdidas) y
`margen bruto = Σ ventas atendibles × (precio − costo)` (nunca sobre lo no atendido). Entradas de una estrategia:
`price_change_pct`, `discount_pct`, `cost_change_pct`, `demand_change_pct` (escalar o lista por período),
`use_cost_forecast`, `plan_run_id` (se supone que el plan se ejecuta; `supply_fill_rate`, `lead_time_delta_days`),
`extra_supply`. Cada entrada se clasifica `observed` / `forecasted` / `assumed`; el resultado es una **simulación
condicionada** (`result_type = conditional_simulation`) y el cambio de demanda es un supuesto: no se deriva del precio.
Sin costo ⇒ margen desconocido (y `revenue_without_known_cost`); productos con unidades distintas ⇒ no se suman unidades.
El simulador de supuestos de la etapa 1 (`/scenarios/simulate`) se conserva sin cambios.

## 9. Contrato causal (`causal/`; E2-16, ADR-V2-11)

Verifica: definición del efecto, variación de precios (semanas, niveles, rango relativo, cambios), soporte por nivel y sin
extrapolar, confusores (observables sin controlar, cambios de precio que coinciden con promociones, quiebres de stock,
no observables sin reconocer), disponibilidad (`known_at` de precios y promociones), supuestos de identificación
(declarados; no verificables) y estimador. **Ningún estimador está habilitado:** la respuesta siempre es
`insufficient_evidence` con `effect = null`, aunque todos los requisitos de datos se cumplan
(`eligible_for_estimator_evaluation` lo indica). La simulación condicionada sigue disponible. La importancia de una
variable en LightGBM no es un efecto causal.

## 10. Simulación histórica de operación (E2-20)

`supply/simulation.py`. En cada revisión el pronóstico (ligero: nivel de 28 días × factor de día de semana, σ de los
errores recientes) usa solo ventas observadas hasta esa fecha, **bajo la propia trayectoria de la política** (si una
política se queda sin stock observa ventas censuradas). Mismas existencias iniciales, misma demanda y mismos retrasos
aleatorios de proveedor (`N(0, σ_plazo)` redondeado, números comunes) para todas las políticas. Políticas: `current`
(revisión periódica de la etapa 1 vía `build_recommendations`), `reference` (mín-máx con 7 días de seguridad) y `planner`
(MILP; solo se ejecuta la emisión de hoy; stock de seguridad `z·σ_L`); opcionalmente `planner` con tope de gasto por
revisión.

Medidas: demanda no atendida y nivel de servicio (por unidades y por días con faltante), sobrestock (existencias por
encima de la demanda **realizada** de los siguientes 60 días, con vista completa), días de inventario, costos de
mantenimiento (tasa anual × valor, supuesto) y de adquisición (órdenes y costo por orden, supuesto 0), margen perdido
(unidades no atendidas × (precio − costo) del catálogo) y capital comprometido (inventario + órdenes en camino).

**No reemplaza la validación con datos reales.** Con datos sintéticos la demanda de evaluación es la demanda latente del
generador (`true_demand`, semilla 42); sobre los datos de una empresa (`POST /supply/simulations`) la demanda es la venta
observada, censurada por quiebres, y el faltante queda subestimado.

### Resultado en la demo (FICTICIA: Ferretería Andina 186 series y Distribuidora Costa 80 series, ventana de 120 días, revisión semanal)

**Ferretería Andina** (186 series, 2026-06-29 a 2026-10-26):

| Política | Nivel de servicio (unidades) | Unidades no atendidas | Inventario medio ($) | Días de inventario | Mantenimiento ($) | Margen perdido ($) | Costo operativo proxy ($) | Capital comprometido medio ($) | Compras emitidas ($) |
|---|---|---|---|---|---|---|---|---|---|
| actual (etapa 1) | 96.76 % | 2,956 | 23,467 | 12.6 | 1,543 | 5,895 | 7,438 | 36,624 | 226,268 |
| referencia mín-máx | 94.66 % | 4,867 | 21,447 | 11.8 | 1,410 | 8,973 | 10,383 | 33,382 | 216,656 |
| planificador MILP | 97.19 % | 2,564 | 25,267 | 13.5 | 1,661 | 5,871 | 7,532 | 38,113 | 228,501 |
| planificador con tope de gasto (85 % del gasto medio de la actual) | 86.39 % | 12,399 | 14,689 | 8.7 | 966 | 19,704 | 20,670 | 23,951 | 192,303 |

**Distribuidora Costa** (80 series, 2026-06-29 a 2026-10-26):

| Política | Nivel de servicio (unidades) | Unidades no atendidas | Inventario medio ($) | Días de inventario | Mantenimiento ($) | Margen perdido ($) | Costo operativo proxy ($) | Capital comprometido medio ($) | Compras emitidas ($) |
|---|---|---|---|---|---|---|---|---|---|
| actual (etapa 1) | 99.34 % | 487 | 7,475 | 8.4 | 492 | 332 | 824 | 9,617 | 99,489 |
| referencia mín-máx | 98.34 % | 1,227 | 8,340 | 9.5 | 548 | 796 | 1,345 | 10,432 | 99,234 |
| planificador MILP | 99.72 % | 206 | 10,227 | 11.5 | 672 | 206 | 878 | 12,409 | 102,974 |

Lectura (solo sintética): (1) la referencia mín-máx es peor que la política actual en servicio y en costo operativo proxy; (2) el planificador reduce la demanda no atendida (−13 % en Ferretería y −58 % en Costa frente a la política actual) a cambio de más inventario (+8 % y +37 %) y de un capital comprometido mayor, y su costo operativo proxy queda prácticamente igual (+1 %) o algo mayor (+7 %): **no se demuestra** que supere a la política actual en costo; su valor está en incorporar restricciones (presupuesto, capacidad, mínimos, lotes) que la política actual ignora; (3) con un tope de gasto de 85 % del gasto medio de la política actual el servicio cae a 86 % y el capital baja 35 %: el tope se aplica por revisión sobre un gasto irregular, de modo que recorta las revisiones grandes; muestra el efecto de un presupuesto activo, no una comparación a igual gasto. El tiempo del planificador es de ~29 s por 17 revisiones de 186 series (MILP por componente).

Evidencia: `docs/architecture/v2/evidence/p4-simulation-*.json`. Reproducir (desde `engine/`):
`python -m predictily_engine.supply.simulation_demo --company ferreteria_andina --days 120 --budget`.

## 11. Contratos, tablas y API

### Migración `2026_02_04_000100_create_supply_decision_tables`

`exchange_rates`, `supplier_offers`, `location_capacities`, `purchase_budgets`, `production_resources`,
`production_recipes` (+ `production_recipe_lines`), `supply_plans`, `supply_plan_lines`, `supply_plan_shortfalls`,
`cost_forecasts`, `scenario_evaluations`. Todas con FKs compuestas `(tenant_id, x_id)`. Las ejecuciones usan `core_runs`
(`core` ∈ supply | cost | scenario; `operation` ∈ plan | simulate | forecast | evaluate) y `training_jobs.core_run_id`.
La huella (`data_snapshots`) cubre pronóstico, inventario, órdenes abiertas, ofertas, tasas, presupuestos, capacidades,
recetas y configuración efectiva (`config_version`).

### API pública `/api/v1/supply` (abilities: `forecast.view` leer, `forecast.run` calcular, `catalog.manage` configuración)

| Método | Ruta | Detalle |
|---|---|---|
| POST | `/plans` | 202; crea `core_run` + job en `forecasting`. `objective`, `coverage_mode`, `forecast_run_id`, `horizon_days`, `priorities`, `shortage_penalties`, `config{…}`. 409 si hay un plan activo |
| GET | `/plans`, `/plans/{id}` | corridas; detalle con `lines`, `shortfalls`, `constraints`, `solver`, `diagnosis`, `infeasibility`, `alternative_plan`, `offers`, `comparison_coverage`, `production`, `balance` y `envelope` (`result_type = optimized_plan`) |
| CRUD | `/offers`, `/exchange-rates`, `/capacities`, `/budgets`, `/production/resources`, `/production/recipes` | configuración (`catalog.manage` para escribir) |
| POST/GET | `/cost-forecasts`, `/cost-forecasts/runs` | solicitar y consultar pronósticos de costos (con `envelope`) |
| POST | `/scenarios/evaluate` | síncrono; GET `/scenarios/evaluations[/{id}]` |
| POST | `/causal/check` | `insufficient_evidence`; sin persistencia |
| POST/GET | `/simulations`, `/simulations/{id}` | simulación histórica (asíncrona) |

Respuesta de resultados: `{"data": …, "envelope": {tenant_id, run_id, core, as_of, horizon_*, target_definition,
entity_keys, unit, currency, result_type, model_version, data_snapshot_id, config_version, assumptions, limitations,
quality_status, uncertainty_source}}` (ADR-V2-03). `status` (técnico) ≠ `quality_status` (aptitud).

### API interna del motor `/v1/supply`

`POST /runs/{run_id}/execute` (`supply/plan`, `supply/simulate`, `cost/forecast`, `scenario/evaluate`; idempotente: una
corrida completada se devuelve sin recalcular y el plan se reescribe por corrida), `POST /causal/check`,
`GET /capabilities`.

## 12. Configuración (`resolve_config('supply'|'cost'|'scenario')`)

Supuestos configurables por empresa (`settings.cores.<core>`): `holding_cost_rate_annual` (0,20), `shortage_penalty_multiplier`
(2,0; debe ser > 1), `safety_stock_weight` (0,9; < 1), `undated_receipt_policy`/`overdue_receipt_policy`,
`planning_horizon_days` (84), `solver_time_limit_seconds` (30), `solver_mip_gap` (1e-4), `use_implicit_offers`, `diagnose_unmet`.
Costos: `min_observations`, `holdout_periods`, `min_improvement`, `null_known_at_policy`. La configuración efectiva y su
hash se guardan en cada corrida.

## 13. Pruebas y evidencia (E2-12 … E2-16, E2-20, E2-26)

| ID | Evidencia |
|---|---|
| E2-12 | `engine/tests/unit/test_supply_balance.py` (fechas, parciales, política de fechas desconocidas, sin doble conteo, reservas, identidad del balance, evidencia de retrasos, vencimiento FEFO) |
| E2-13 | `engine/tests/unit/test_supply_planner.py`: casos a mano (940, mínimo, lote, presupuesto, capacidades, almacén), oráculo de enumeración (25 casos aleatorios), 12 instancias aleatorias sin violaciones y ≤ heurística, infactibilidad explicada, estado `time_limit`, respaldo |
| E2-14 | `test_supply_planner.py::test_generic_production_*` (consumo, rendimiento, tiempo, capacidad, insumos) |
| E2-15 | `test_costs_forecast.py`, `test_scenarios_evaluator.py`, `test_supply_integration.py` |
| E2-16 | `test_causal_contract.py` y `/causal/check` |
| E2-20 | `test_supply_simulation.py` (cálculo a mano, información disponible, mismas condiciones) y la tabla de §10 |
| E2-26 | `test_supply_offers.py` y `test_supply_integration.py::test_plan_respects_budget_lots_minimums…` |
| E2-17 | `test_supply_integration.py` (aislamiento, 404 entre empresas, FK compuestas) y `backend/tests/Feature/Supply/*` |

## 14. Limitaciones y pendientes (honestas)

* Todo se comprobó con datos sintéticos; **no hay validación con datos reales** (Soyoda/Resgasa pendientes de acceso).
* El MILP usa la **media** pronosticada; la incertidumbre entra solo por el stock de seguridad aportado. No es un modelo
  estocástico ni garantiza un nivel de servicio.
* Ventas perdidas (no hay pedidos pendientes de clientes). Sin transferencias entre sucursales. Costo fijo por línea de
  orden (sin consolidar productos del mismo proveedor ni mínimo monetario por proveedor).
* Aproximación por períodos (§2); el horizonte finito no tiene valor terminal.
* Mantenimiento, penalización por faltante y costo por orden son **supuestos** declarados, no costos observados.
* El tiempo del solver crece con restricciones acopladoras (presupuesto/almacén juntan a todos los productos): en una
  instancia sintética de 183 series aleatoria con mínimos y costos fijos el estado puede ser `feasible_with_gap` o `time_limit`.
* Cancelaciones sin fecha; producción de un nivel; la vida útil no entra al optimizador.
* El pronóstico de costos no incorpora variables externas (se tratan como trayectorias declaradas en escenarios).
* Sin programación automática de planes; sin entrada en OpenAPI (`docs/openapi`) ni en la UI: ver necesidades abajo.

### Necesidades para la UI (P5)

Pantalla de plan: líneas con `actionable_now`, proveedor, cantidad (producto y oferta), fechas de emisión/recepción,
costo, `binding_constraints` y `justification`; tabla de demanda no atendida con su `reason`; estado del solver con
brecha y aviso cuando no es `optimal`; bloque de infactibilidad con causas, requisitos y plan de máxima cobertura;
cobertura de la comparación y ofertas excluidas con su motivo; `production.status = not_configured`. Formularios para
ofertas, tasas, capacidades, presupuestos, recursos y recetas. Pronóstico de costos: referencia vs método servido y
métricas de holdout. Escenarios: tabla de estrategias con las entradas clasificadas (observada / pronosticada /
supuesta) y el aviso de que no es causal. Causalidad: lista de requisitos con estado y cómo corregirlos. Simulación:
tabla de políticas y las advertencias de `caveats`.
