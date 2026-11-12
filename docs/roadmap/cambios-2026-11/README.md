# Cambios propuestos tras los pilotos con datos reales (noviembre de 2026)

Origen: dos pilotos con bases reales de clientes (piloto A: distribuidora de bebidas por rutas con ~3 años de histórico;
piloto B: fabricante con venta mayorista y solo ~3 meses de histórico en su espejo de ventas), una revisión de diseño del
pronosticador y experimentos fuera del repositorio sobre ambos. Las cifras de los clientes viven en `reports/private/` (no
versionado); aquí solo hay mecanismos, referencias de código y criterios de aceptación.

Cada documento sigue la misma estructura: problema → evidencia (código) → qué cambiar, paso a paso → pruebas → criterio de
aceptación → riesgos → esfuerzo. Las rutas y líneas se verificaron el 10/11/2026 sobre la rama
`feat/adr-v26-01-aislamiento-de-fallos` (commits `6d4de51` y `be40935`); si el archivo cambió, buscar por el nombre de la función.

## Orden recomendado

| # | Cambio | Área | Prioridad | Esfuerzo | Depende de |
|---|---|---|---|---|---|
| [01](01-motor-perfil-semanal-series-cortas.md) ✅ implementado | Perfil semanal del negocio para series de historia corta y respaldo | motor | alta | bajo | — |
| [04](04-motor-cierres-y-dias-sin-operacion.md) ✅ implementado | Cierres declarables (`closure`) y días de la semana sin operación | backend + motor | alta | bajo–medio | — |
| [08](08-plataforma-cobertura-fuente-indicadores.md) | Cobertura inferida real, fuente que se reactiva, signo del neto, indicadores anclados al corte, driver SQL Server en Docker | plataforma | alta | bajo | — |
| [07](07-importacion-unidad-regalo-costo-inventario.md) | Unidad de venta y empaque, bandera de regalo, promociones «N + 1», costo e inventario por el conector SQL | importación | alta | medio | — |
| [03](03-motor-intervalos-nodos-agregados.md) | Intervalos de los nodos agregados con pocas ventanas | motor | media | medio | — |
| [02](02-motor-perfil-semanal-dias-limpios.md) | Perfil semanal estimado solo con días limpios | motor | media | medio | 04 |
| [05](05-motor-plan-de-ventanas-y-horizonte.md) | Plan de ventanas: tope de 3, periodo final «ligero», presentación de horizontes no sostenidos, readiness sin sábados | motor + SPA | media | medio | — |
| [06](06-motor-seleccion-con-margen-y-weekday-average.md) ✅ implementado en parte | Selección por el total diario con salvaguardas de sesgo y de error por serie (regla v2); familia «mismo día de la semana» como candidata | motor | media | medio | 05 |
| [09](09-motor-ajuste-de-feriados-estadisticos.md) ✅ implementado | Ajuste de feriados de los modelos estadísticos | motor | alta | medio | — |
| [10](10-motor-combinacion-ets-lightgbm.md) ✅ implementado | Combinación AutoETS + LightGBM como familia candidata | motor | media | bajo | — |
| [11](11-motor-modo-de-precision-automatico.md) ✅ implementado | Modo de precisión automático: el motor ajusta los interruptores con el backtest de cada empresa | motor | alta | medio | 06, 09, 10 |
| [12](12-motor-quiebres-inferidos-de-las-ventas.md) ✅ implementado | Quiebres de stock inferidos de las ventas, para empresas sin historia de inventario | motor | alta | medio | — |

## Reglas para quien implemente

- Un cambio por rama o por commit; cada uno con sus pruebas (unitarias en `engine/tests/unit`, feature en `backend/tests`).
- Ningún cambio debe alterar lo servido a una empresa que no tenga la opción correspondiente activada, salvo que el documento
  lo diga explícitamente (04 y 08 corrigen defectos y sí cambian el comportamiento por defecto).
- Validar siempre contra los dos pilotos con el arnés en memoria (`reports/private/pilot/.../` tiene las instrucciones;
  el arnés carga los datos de la empresa y ejecuta `run_pipeline` sin escribir en la base). Una mejora en B no puede
  empeorar A: A tiene periodo final independiente; B no (solo 2 ventanas de selección), así que en B solo son evidencia las
  tablas por día de la semana y el total, no el WAPE del selector.
- Métrica de decisión: WAPE del total diario y sesgo semanal además del WAPE serie-día; el WAPE serie-día premia pronosticar de
  menos en series intermitentes (lo demostró el experimento E2 de `06`).
