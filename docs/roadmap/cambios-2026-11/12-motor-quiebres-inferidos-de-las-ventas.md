# 12 · Quiebres de stock inferidos de las ventas

**Área:** motor (`profiling/stockouts.py`, `profiling/series.py`, `profiling/days.py`, `pipeline/core.py`, `platform/config.py`) ·
**Prioridad:** alta · **Esfuerzo:** medio · **Interruptor:** `demand.stockout_inference` (apagado por defecto).

**Estado: implementado el 12/11/2026** (rama `claude/optimistic-ritchie-snsv8i`). Apagado en todas las empresas. Pendiente: medirlo en
los pilotos y decidir si entra al modo de precisión automático (11).

## Problema

Muchas empresas no tienen historia de inventario. Sin ella el motor no sabe que un producto se quedó sin stock: ve una racha de días en
cero, la aprende como demanda y pronostica de menos justo cuando el producto vuelve; además mide su error contra ventas que no reflejan
la demanda. Con inventario esos días ya se tratan como quiebre (`stockout_censored`): se imputan para entrenar y no se evalúan.

## Qué se hizo

Con `demand.stockout_inference = true`, al armar el panel se marca como quiebre (`stockout_censored`, `day_basis = inferred`) cada día
de una racha de ceros que la demanda habitual del producto no explica. Un día marcado recibe el trato de cualquier quiebre. La regla
(detalle en el docstring de `profiling/stockouts.py`):

- **Probabilidad de la racha si hubiera stock**: producto de la frecuencia de ceros de cada día de la semana en las 12 semanas previas
  al inicio de la racha (`stockout_inference_lookback_days = 84`), suavizada. Un día se marca cuando la racha ya es improbable
  (≤ `stockout_inference_alpha = 0,001`) y lleva al menos `stockout_inference_min_days = 3` días en cero.
- **Solo con lo sabido hasta ese día**: la marca de un día nunca depende de lo que pasó después, así que una ventana de backtest no
  aprende nada posterior a su corte. Consecuencia: los dos primeros días de cada racha quedan como ceros observados.
- **Solo productos de venta regular**: a lo sumo `stockout_inference_max_zero_share = 0,5` de días en cero en la historia previa. En
  productos de venta esporádica las rachas de ceros son parte de su demanda.
- **Tope**: pasados `stockout_inference_max_days = 42` días en cero deja de suponerse quiebre (producto descontinuado o demanda que
  cambió): los días siguientes vuelven a ser ceros y el pronóstico baja.
- **Salvaguarda de sucursal**: no se marca si los demás productos de la sucursal venden menos de
  `stockout_inference_min_branch_activity = 0,5` veces lo habitual (la caída es de toda la sucursal).
- **El inventario manda**: un día con existencias confirmadas nunca se marca y corta la racha; los quiebres conocidos y los ya inferidos
  no cuentan como ceros habituales de las rachas siguientes.
- **Imputación**: como los quiebres de inventario (máximo entre la venta y la media móvil de 28 días de los días limpios); en una racha
  de más de 21 días se propaga el último nivel conocido.
- **Trazabilidad**: `summary["day_status"]["stockout_inference"]` (reglas, días y series marcados, series aún en quiebre al corte,
  rachas descartadas) y la limitación `stockouts_inferred` de la ejecución. Apagado, el panel y el resumen son los de siempre.

## Evidencia (12/11/2026; scripts y tablas en `engine/scripts/quiebres_inferidos/`)

- **Empresas demo, con el inventario real como verdad** (el motor no lo ve): de lo marcado, 88 % y 99 % fueron quiebres reales; en
  productos de 5 o más unidades diarias detecta 62 % de los días de quiebre en cero, entre 1 y 5 unidades 34–41 %, y casi nada por
  debajo de 1.
- **Favorita con quiebres simulados** (ventas reales borradas en rachas de 4 a 21 días en 157 series): detecta 74 % de los días
  simulados y 99 % de las marcas nuevas caen sobre ellos. El quiebre sube el error por día de 0,339 a 0,372 y el semanal de 0,221 a
  0,258; con la inferencia quedan en 0,346 y 0,233 (recupera 79 % y 67 % del daño). En los productos sin stock el día en que se
  pronostica, el sesgo pasa de −42 % a −1 %.
- **Favorita tal cual** (sin verdad conocida; marca 1,1 % de los días): efecto dentro del ruido (por día +0,1 %, por semana +1,4 %,
  total diario −1,3 %).
- **Demo, contra la demanda real**: el sesgo a la baja por no tener inventario se reduce en una empresa (−3,7 % → −2,7 %; con
  inventario −2,0 %) y no mejora en la otra; el error por producto casi no cambia (los quiebres son pocos).

## Pruebas

`engine/tests/unit/test_stockout_inference.py` (17): valores por defecto y validación; la regla (racha de un producto regular, ceros
aislados, venta esporádica, día de la semana sin venta, tope de días, racha abierta al corte, **la marca de un día no depende del
futuro**, días sin fuente, existencias confirmadas, quiebres repetidos, producto nuevo, caída de toda la sucursal); el panel (apagado
no cambia; encendido marca, imputa y resume; el inventario manda; precisión y cobertura mínimas en la empresa demo); el motor (resumen,
manifiesto y limitación solo con el interruptor encendido; los días inferidos del período final no se evalúan).

## Limitaciones y riesgos

- Solo detecta quiebres totales (días en cero), no ventas reducidas por poco stock, ni quiebres de 1 o 2 días.
- No distingue un quiebre de un producto descontinuado hasta pasados 42 días: mientras tanto pronostica que vuelve. En las series que
  estaban en racha al momento de pronosticar, el sesgo en Favorita tal cual subió de +3 % a +10 % (26 series).
- Sin verdad de inventario no se puede comprobar en los datos de una empresa; se aprueba revisando qué rachas marca.
- Cambia con qué datos se entrena y qué días se evalúan: las métricas con y sin el interruptor no son comparables día a día.
- La evaluación posterior de pronósticos ya servidos (`evaluation/actuals.py`) aún no excluye los días inferidos.
- No forma parte del modo de precisión automático: no hay una comparación de backtest que lo decida sin verdad de inventario.
