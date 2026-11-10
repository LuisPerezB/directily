# 07 · Importación: unidad de venta y empaque, bandera de regalo, promociones «N + 1», costo e inventario por el conector SQL

**Área:** backend (importador, contrato canónico, conector SQL) + motor · **Prioridad:** alta · **Esfuerzo:** medio.

## Problema

Lo que los pilotos mostraron del modelo canónico de ventas:

1. **Unidades regaladas como demanda normal.** En el piloto A, desde que las promociones pasaron de «% de descuento» a «N + 1 gratis»,
   una parte relevante de las unidades importadas son líneas con descuento del 100 % y neto 0 (unidades regaladas). El motor las
   cuenta como demanda vendida; el ingreso estimado las valora con un precio medio diluido, y el crecimiento aparente de unidades
   mezcla ventas pagadas con regalos.
2. **La unidad comercial se pierde.** El piloto A vende por empaque (paquete de 6/9/12/15/24: el precio unitario guardado es el
   precio del paquete dividido entre sus unidades); el piloto B vende en galones, litros, canecas y tanques, uno por producto. El
   mapeo de ventas no lleva unidad y `products.unit` queda en «unidad»; el «total diario» suma unidades no comparables y el cliente
   decide en cajas.
3. **El contrato `promotions` no representa la promoción real.** Solo admite un `% de descuento` por fechas y alcance
   (`PROMO_COLUMNS`); las promociones de los pilotos son escalones de compra con regalo (cantidad comprada → cantidad regalada), por
   vendedor, cliente y lista de precios; la tabla queda vacía y `promo_discount` vale siempre 0, aunque la promoción es la causa
   del crecimiento que el modelo no explica.
4. **Costo e inventario existen en el origen y no se cargan.** Los dos orígenes tienen costo promedio en el maestro de artículos y
   una foto de existencias por bodega; el conector SQL ya admite las entidades `products`, `inventory` y `branches`, pero el runbook
   solo mapeó ventas. Sin costo no hay margen ni valor de la compra sugerida; sin inventario no hay quiebres ni recomendaciones.

## Evidencia en el código

- Contrato de ventas: `backend/app/Services/Imports/Fields/ImportFieldRegistry.php::sales()` (campos `quantity`, `unit_price`,
  `discount`, `net_amount`, `channel`, sin unidad ni bandera de regalo); normalización en
  `backend/app/Services/Sales/SalesLineNormalizer.php:110-303` (neto calculado 242-244; `is_return` solo por nota de crédito o cantidad
  negativa, 157-168).
- Precio en el motor: `profiling/series.py:148-151` (`price = net_amount / units` del día) y `features/builder.py:359-369` (precio neto
  medio ponderado de 28 días para el ingreso).
- Promociones: `features/builder.py:45` (`COMMERCIAL_FEATURES`), `:78` (`PROMO_COLUMNS`), `156-185` (`promo_discounts`);
  `ImportFieldRegistry::promotions()`; modelo `Promotion` y migración de `promotions`.
- Conector SQL: `backend/app/Services/Connectors/Connectors/AbstractSqlConnector.php:27` (entidades admitidas).

## Qué cambiar (pasos)

### A · Bandera de regalo (bajo)

1. Nuevo campo canónico opcional en `sales()`: `is_gift` (booleano). Regla por defecto en `SalesLineNormalizer`: si no viene, la
   línea es regalo cuando `net_amount == 0` y `discount ≥ quantity × unit_price − 0,01` (descuento del 100 %); opción de mapeo
   `import.gift_rule: 'discount_100' | 'none'`.
2. Persistir en `sale_items.is_gift` (migración) y contar en el resumen de la importación (`gift_lines`, `gift_units`).
3. En el motor: el panel carga `units_paid` y `units_gift` por celda (`db/repository.py` ventas diarias); la variable objetivo
   sigue siendo el total despachado (`y = pagadas + regaladas`, porque es lo que hay que tener en bodega), pero el ingreso y el
   precio se calculan solo con unidades pagadas, y `units_gift` entra como regresor comercial en `COMMERCIAL_FEATURES`
   (`gift_share` por serie-día). El dashboard muestra «unidades estimadas, de las cuales N en promoción» cuando `gift_share` > 1 %.

### B · Unidad de venta y empaque (bajo)

4. Campos canónicos opcionales en `sales()`: `unit` (texto: unidad de venta de la línea) y `units_per_pack` (entero: unidades por
   empaque). El importador guarda `unit` en `products.unit` si el producto no la tenía y el empaque en `products.pack_size` (nuevo).
5. Permitir elegir en Configuración la unidad de presentación del dashboard (`unidades | empaques`) cuando `pack_size` exista;
   el motor sigue en unidades y la conversión es de presentación (`yhat / pack_size`), redondeada al entero superior en las
   recomendaciones de compra.
6. Mapeos de ejemplo en los runbooks privados de cada piloto (origen: columna de empaque del detalle en A; unidad del artículo en B).

### C · Promociones «N + 1» (medio)

7. Ampliar el contrato `promotions` con `mechanic` (`percent | amount | buy_x_get_y`), `buy_qty`, `free_qty`, `scope` (producto,
   categoría, sucursal, lista de precios o cliente seudonimizado) y `source_promotion_code`; migración y `ImportFieldRegistry::promotions()`.
8. En el motor, `promo_discounts` (builder 156-185) produce dos regresores: `promo_discount` (equivalente en % del valor:
   `free_qty / (buy_qty + free_qty)` para `buy_x_get_y`) y `promo_active` (0/1) por serie-día, respetando la regla as-of ya existente
   (`promotions_as_of`, 118-153).
9. Importar el calendario de promociones del origen con el conector SQL (entidad `promotions`, una consulta por mapeo) en los dos
   pilotos (instrucciones en `reports/private/pilot/*/CAMBIOS_DATOS_CLIENTE.md`).

### D · Costo e inventario por el conector (bajo)

10. Mapeo `products` (sku, name, category, unit, current_cost, current_price, is_active) desde el maestro de artículos del origen
    para que `dq_demand_cost_coverage_low` deje de dispararse; documentar que es costo promedio actual, no histórico.
11. Mapeo `inventory` (foto diaria por bodega → sucursal) con sincronización incremental; documentar que una foto única no censura
    quiebres pasados.

## Pruebas

- Backend: `tests/Feature/Core/SalesImportTest.php`: líneas con descuento 100 % quedan `is_gift = true`, cuentan en el resumen y no
  alteran `net_amount`; importación con `unit` y `units_per_pack`; promociones `buy_x_get_y` válidas e inválidas.
- Motor: `tests/unit/test_promotions_as_of.py` ampliado con `buy_x_get_y`; prueba de que `gift_share` existe como regresor y el
  ingreso usa solo unidades pagadas; `tests/unit/test_feature_contracts.py` (`COMMERCIAL_FEATURES` documentadas).

## Criterio de aceptación

- Piloto A: el ingreso estimado deja de incluir regalos; «unidades estimadas» muestra la parte promocional; con `promo_active` como
  regresor, LightGBM entra al menos en una ventana con mejora sobre el estadístico (si no, se documenta).
- Piloto B: `products.unit` real para todos los SKU y costo en la mayoría; recomendaciones de abastecimiento visibles con la foto de
  inventario.

## Riesgos

- `is_gift` por regla de descuento 100 % puede marcar bonificaciones comerciales que no son promoción: la regla es configurable por
  mapeo y el resumen la hace visible.
- Cambiar `y` (incluir o no regalos) altera las métricas históricas: por eso `y` no cambia (total despachado) y lo que cambia es el
  precio, el ingreso y los regresores.
