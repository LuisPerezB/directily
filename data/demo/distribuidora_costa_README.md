# Distribuidora Costa (Demo ficticia)

> **DATOS FICTICIOS.** Empresa, sucursales, clientes, proveedores y transacciones son
> inventados y generados por `predictily_engine.synthetic` con semilla fija. No
> representan a ninguna empresa real. Úsense solo para demostración y pruebas.

- Sector: `distribuidora` · Región: costa · Moneda: USD
- Período: 2025-07-27 → 2026-10-26
- Sucursales: 2 · Productos: 40
- Líneas de venta: 42,035 · Documentos: 18,708
- Facturas anuladas: 71 · Notas de crédito: 324
- Días producto-sucursal con stock ≤ 0: 1,568 (ventas censuradas; demanda no atendida simulada: 9,348 unidades)

## Archivos

| Archivo | Filas | Tipo de importación |
|---|---|---|
| `distribuidora_costa_branches.csv` | 2 | sucursales (alta manual o importación) |
| `distribuidora_costa_products.csv` | 40 | products |
| `distribuidora_costa_sales.csv` | 42,035 | sales |
| `distribuidora_costa_sales_with_errors.csv` | 106 | sales (demostración de validación) |
| `distribuidora_costa_inventory.csv` | 36,560 | inventory |
| `distribuidora_costa_lead_times.csv` | 40 | lead_times |
| `distribuidora_costa_suppliers.csv` | 5 | proveedores |
| `distribuidora_costa_promotions.csv` | 6 | promociones |

## Fenómenos incluidos

Tendencia, estacionalidad semanal y anual, quincenas y fin de mes, feriados de Ecuador
con traslados (y locales de Quito/Guayaquil), décimo tercer sueldo (diciembre), décimo
cuarto (marzo en Costa, agosto en Sierra), temporada escolar regional, Navidad, Black
Friday, cambios escalonados de precio, promociones con descuento (incluida una futura),
demanda intermitente, un producto lanzado recientemente (historia corta), uno
descontinuado, quiebres de stock con retrasos de proveedor, devoluciones (notas de
crédito, cantidades positivas) y facturas anuladas y reemitidas.

`*_sales_with_errors.csv` repite líneas reales de los últimos días (reimportarlas no
debe duplicar ventas) y agrega filas con errores típicos; la columna `nota_calidad`
indica el problema introducido y debe ignorarse en el mapeo.

Regenerar: `cd engine && .venv/bin/python -m predictily_engine.cli generate-demo --out ../data/demo --seed 42`
