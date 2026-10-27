# Ferretería Andina (Demo ficticia)

> **DATOS FICTICIOS.** Empresa, sucursales, clientes, proveedores y transacciones son
> inventados y generados por `predictily_engine.synthetic` con semilla fija. No
> representan a ninguna empresa real. Úsense solo para demostración y pruebas.

- Sector: `ferreteria` · Región: sierra · Moneda: USD
- Período: 2024-10-27 → 2026-10-26
- Sucursales: 3 · Productos: 62
- Líneas de venta: 99,334 · Documentos: 44,269
- Facturas anuladas: 206 · Notas de crédito: 796
- Días producto-sucursal con stock ≤ 0: 7,131 (ventas censuradas; demanda no atendida simulada: 18,911 unidades)

## Archivos

| Archivo | Filas | Tipo de importación |
|---|---|---|
| `ferreteria_andina_branches.csv` | 3 | sucursales (alta manual o importación) |
| `ferreteria_andina_products.csv` | 62 | products |
| `ferreteria_andina_sales.csv` | 99,334 | sales |
| `ferreteria_andina_sales_with_errors.csv` | 142 | sales (demostración de validación) |
| `ferreteria_andina_inventory.csv` | 133,302 | inventory |
| `ferreteria_andina_lead_times.csv` | 79 | lead_times |
| `ferreteria_andina_suppliers.csv` | 7 | proveedores |
| `ferreteria_andina_promotions.csv` | 7 | promociones |

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
