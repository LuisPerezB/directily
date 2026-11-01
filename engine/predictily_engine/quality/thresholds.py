"""Justificación escrita de los umbrales de los gates (`platform.config.CORE_DEFAULTS["quality_gates"]`).

Los VALORES viven en `platform/config.py` (una sola fuente, con la precedencia core → perfil → empresa). Aquí se documenta, para cada
clave, el módulo, el efecto que produce, la regla y por qué se eligió ese valor. Todos son valores INICIALES de diseño, sin
calibración empírica: quedan marcados «a aprobar en el piloto» con datos reales y ninguno es universal (V2.5 §5: «no usar umbrales
inventados como universales»). Una prueba (`tests/unit/test_quality_gates.py`) verifica que cada clave tiene su justificación y que
`docs/data/DATA_QUALITY_ENGINE.md` §6 lista cada clave con su valor por defecto.
"""

from __future__ import annotations

from dataclasses import dataclass

PILOT_MARK = "a aprobar en el piloto"


@dataclass(frozen=True)
class Threshold:
    key: str
    module: str       # shared | demand | customer | supply
    effect: str       # BLOCK | DEGRADE | WARN | WINDOW (ventana de lectura, no un gate)
    rule: str
    justification: str


def _t(key: str, module: str, effect: str, rule: str, why: str) -> Threshold:
    return Threshold(key, module, effect, rule, f"{why} Valor inicial de diseño, {PILOT_MARK}; no es universal.")


THRESHOLDS: dict[str, Threshold] = {t.key: t for t in (
    _t("invalid_lookback_days", "shared", "WINDOW", "Informes de ingestión (importaciones y sincronizaciones `completed`, lotes con documentos escritos; sin reprocesos) creados en los N días anteriores al corte entran al % inválido.",
       "180 días cubren dos trimestres de cargas: un error ya corregido no pesa para siempre y una importación aislada no domina."),
    _t("lag_lookback_days", "shared", "WINDOW", "Documentos incrementales con fecha de venta en los N días anteriores al corte entran a la distribución del desfase.",
       "90 días es un trimestre: refleja el comportamiento reciente de la integración sin mezclar cargas históricas."),
    _t("id_lookback_days", "shared", "WINDOW", "Alias de cambio de código (vigencia en el intervalo (corte − N, corte]) y primeras ventas dentro de los N días anteriores al corte cuentan como «recientes»; los códigos nuevos solo se evalúan con ≥ 2 × N días de historia.",
       "90 días: un trimestre de operación, suficiente para ver una reasignación de códigos sin tratar todo el catálogo como nuevo."),
    _t("identity_window_days", "shared", "WINDOW", "Ventana de la proporción de unidades de compradores identificados, genéricos y anónimos.",
       "180 días: la misma ventana con que el Customer Core ya contabiliza lo excluido de la predicción individual."),
    _t("demand_min_history_days", "demand", "BLOCK", "Historia de la empresa (primera venta → corte) < N días ⇒ BLOCK.",
       "28 días es el mínimo del perfilado de series (profiling.series.MIN_HISTORY_DAYS): con menos de cuatro semanas completas ninguna serie "
       "es elegible y no se observa un ciclo semanal completo varias veces."),
    _t("demand_min_source_coverage_pct", "demand", "BLOCK", "Fracción de sucursal-días con cobertura de fuente < N ⇒ BLOCK.",
       "Si más de la mitad de los sucursal-días no tiene fuente (missing_source) la mayor parte de la señal de entrenamiento sería "
       "imputación por media móvil y no ventas observadas; el pronóstico extrapolaría valores inventados."),
    _t("demand_warn_source_coverage_pct", "demand", "WARN", "Fracción de sucursal-días con cobertura de fuente < N ⇒ WARN.",
       "Con un 10 % de días sin fuente ya se imputan huecos suficientes para sesgar la estacionalidad semanal; se avisa antes de bloquear."),
    _t("demand_block_invalid_pct", "demand", "BLOCK", "Filas inválidas / filas leídas (informes de ingestión de la ventana) > N ⇒ BLOCK.",
       "Si más de la mitad de las filas recibidas se rechaza, lo que quedó en Silver no es una muestra representativa del negocio."),
    _t("demand_warn_invalid_pct", "demand", "WARN", "Filas inválidas / filas leídas > N ⇒ WARN.",
       "5 % de filas rechazadas es la tasa a partir de la cual el puntaje de calidad de la importación ya descuenta (QualityReportBuilder) "
       "y conviene revisar la cuarentena."),
    _t("demand_warn_key_issue_pct", "demand", "WARN", "Líneas con producto o sucursal inexistente o dado de baja / líneas > N ⇒ WARN.",
       "Esas líneas se pronostican sin catálogo vigente: 2 % es un nivel de ruido tolerable; por encima indica un catálogo desalineado de las ventas."),
    _t("demand_min_price_coverage", "demand", "DEGRADE", "Unidades con precio neto positivo / unidades vendidas < N ⇒ DEGRADE: se neutraliza price_ratio.",
       "price_ratio compara el precio observado con el precio mediano de la serie; con menos del 80 % de las unidades con precio, la razón se "
       "calcula sobre una minoría y el modelo aprendería un efecto precio que no existe. Sin la variable el modelo sigue siendo válido."),
    _t("demand_warn_min_cost_coverage", "demand", "WARN", "Líneas con costo / líneas < N ⇒ WARN.",
       "Misma fracción que usa el puntaje de preparación (profiling.readiness: has_costs ≥ 50 %): por debajo, el margen y la inversión estimada quedan incompletos."),
    _t("demand_warn_min_inventory_coverage", "demand", "WARN", "Series activas con inventario / series activas < N ⇒ WARN.",
       "Sin inventario no se detectan quiebres (ventas censuradas) ni se generan recomendaciones de abastecimiento; con la mitad sin él, "
       "la mitad del catálogo queda sin esa protección."),
    _t("demand_warn_max_stockout_pct", "demand", "WARN", "Series-día con quiebre / series-día > N ⇒ WARN.",
       "El perfilado de preparación avisa desde 5 % de quiebres; 20 % es el nivel en que la censura domina la historia y el WAPE se vuelve optimista."),
    _t("demand_warn_max_closed_pct", "demand", "WARN", "Series-día cerradas / series-día > N ⇒ WARN.",
       "Un 30 % de cierres declarados (feriados, vacaciones, sucursales cerradas) deja poca historia efectiva por serie; puede ser real pero merece revisión."),
    _t("demand_warn_max_stale_days", "demand", "WARN", "Días entre la fecha de referencia y la última venta conocida > N ⇒ WARN.",
       "Dos semanas sin ventas nuevas indican que la carga se detuvo; en ejecución vigente la recencia ya no representa el negocio."),
    _t("demand_warn_max_lag_p90_days", "demand", "WARN", "p90 del desfase evento→ingestión de los documentos incrementales > N ⇒ WARN.",
       "Si el 10 % de los documentos llega más de una semana tarde, los últimos días del corte se corrigen después y las métricas recientes son provisionales."),
    _t("demand_warn_max_short_series_pct", "demand", "WARN", "Series activas con < 28 días de historia / series activas > N ⇒ WARN.",
       "Esas series usan un respaldo etiquetado sin evaluación; si son la mayoría el pronóstico del catálogo es en gran parte un promedio."),
    _t("customer_min_history_days", "customer", "BLOCK", "Historia de compras identificadas (primera compra → corte) < N días ⇒ BLOCK.",
       "28 días: cuatro semanas es el mínimo para observar siquiera una recompra semanal; el dataset de cortes del Customer Core exige además "
       "su propio mínimo (min_history_days del core), de modo que este gate solo evita trabajo evidentemente vacío."),
    _t("customer_min_buyers", "customer", "BLOCK", "Compradores identificados con compras < N ⇒ BLOCK.",
       "Con menos de cinco compradores no hay población para estimar probabilidades por comprador ni para separar entrenamiento y evaluación."),
    _t("customer_min_identified_share", "customer", "DEGRADE", "Unidades de compradores identificados / unidades totales (ventana) < N ⇒ DEGRADE a agregado.",
       "Si menos del 20 % de las unidades se atribuye a compradores identificados y no genéricos, las recomendaciones individuales cubrirían una "
       "minoría y el resto sería residual no atribuible; V2.5 §25.2 pide conservar el Demand Core general."),
    _t("customer_max_id_change_share", "customer", "DEGRADE", "Productos (o clientes) con alias de cambio de código/fusión/división reciente / con ventas > N ⇒ DEGRADE a agregado.",
       "Cuando el 20 % de las identidades cambió de código en el trimestre, las relaciones comprador×producto se rompen y la recompra individual "
       "se estima sobre historias partidas (V2.5 §25.2: IDs inestables ⇒ deshabilitar recomendaciones individualizadas)."),
    _t("customer_max_new_product_code_share", "customer", "DEGRADE", "Productos cuya primera venta cae en la ventana reciente / productos con ventas > N ⇒ DEGRADE a agregado.",
       "Código nuevo reciente sin alias es el síntoma de una reasignación no registrada; si la mitad del catálogo vendido es «nuevo» (con historia "
       "previa suficiente para que no sea simple arranque) no hay relación comprador×producto estable. Solo se evalúa con al menos DOS ventanas de "
       "historia (2 × 90 = 180 días): con menos, el catálogo es «nuevo» porque el negocio es joven."),
    _t("customer_warn_max_new_customer_share", "customer", "WARN", "Compradores identificados cuya primera compra cae en la ventana / compradores > N ⇒ WARN.",
       "Los compradores nuevos son normales en un negocio que crece; solo se avisa cuando son la mitad (posible recodificación de clientes) y con al "
       "menos dos ventanas de historia."),
    _t("customer_warn_min_repeat_buyers_pct", "customer", "WARN", "Compradores con ≥ 2 días distintos de compra / compradores < N ⇒ WARN.",
       "Sin recompra no hay patrón individual que aprender; con menos del 30 % de compradores recurrentes la probabilidad individual es poco informativa."),
    _t("customer_warn_min_availability_pct", "customer", "WARN", "Productos comprados con inventario conocido / productos comprados < N ⇒ WARN.",
       "La censura por disponibilidad necesita inventario: sin él no se distingue «no compró» de «no había stock»."),
    _t("customer_warn_max_stale_days", "customer", "WARN", "Días entre la fecha de referencia y la última venta conocida > N ⇒ WARN.",
       "Dos semanas sin ventas nuevas: la recencia de cada comprador se sobreestima (el core ya marca «stale» a los 7 días como degradación propia)."),
    _t("customer_warn_invalid_pct", "customer", "WARN", "Filas inválidas / filas leídas (informes de ingestión) > N ⇒ WARN.",
       "5 %: misma tasa que en demanda; las filas rechazadas son compras que el modelo no ve."),
    _t("supply_min_inventory_coverage", "supply", "BLOCK", "Series pronosticadas con inventario al corte / series pronosticadas < N ⇒ BLOCK (0 = desactivado).",
       "Desactivado por defecto (0): sin inventario el plan ya se excluye serie por serie con el motivo `no_inventory` y la calidad queda "
       "`insufficient_data`, con la explicación por nodo que consumen el plan y las pruebas existentes; un BLOCK la reemplazaría por una corrida vacía. "
       "La empresa o el piloto pueden activarlo (p. ej. 0,05)."),
    _t("supply_warn_min_inventory_coverage", "supply", "WARN", "Series pronosticadas con inventario / series pronosticadas < N ⇒ WARN.",
       "Con menos de la mitad de las series con existencias conocidas, la mayor parte del catálogo queda fuera del plan; el aviso lo hace visible "
       "sin detener la ejecución."),
    _t("supply_inventory_max_age_days", "supply", "WARN", "Mediana de antigüedad del último inventario > N días ⇒ WARN.",
       "30 días es el umbral del informe de preparación (stale_inventory): más allá, las existencias de partida del plan no son vigentes."),
    _t("supply_min_lead_time_coverage", "supply", "DEGRADE",
       "Series con plazo explícito (supplier_lead_times del producto u oferta con plazo) / series planificables < N ⇒ DEGRADE: se deshabilitan las ofertas implícitas y la política de plazo observado.",
       "Si menos de la mitad de las series tiene un plazo conocido, el plan usaría el plazo por defecto de la empresa como si fuera un dato; "
       "las ofertas implícitas (último costo + plazo por defecto) y la fecha supuesta de recepciones sin fecha dejan de ser aceptables. EFECTO en una "
       "empresa SIN plazos ni ofertas: pierde las ofertas implícitas y su plan queda sin nodos (`insufficient_data`, motivo `no_offer`) hasta que cargue "
       "plazos u ofertas; con el umbral en 0 recupera el comportamiento anterior."),
    _t("supply_warn_min_cost_coverage", "supply", "WARN", "Productos pronosticados con costo conocido / productos pronosticados < N ⇒ WARN.",
       "Sin costo la inversión y el costo del plan quedan incompletos; la mitad es el mismo nivel que usa el puntaje de preparación."),
)}
