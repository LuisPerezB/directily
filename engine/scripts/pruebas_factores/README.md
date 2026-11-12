# Pruebas de factores: ¿qué variables ayudan a anticipar la demanda?

Scripts de **análisis** (no forman parte del motor ni cambian lo que se sirve). Miden, sobre bases públicas con datos reales, cuánto baja
el error de un LightGBM global configurado como el del motor (Tweedie, rezagos 1/7/14/28, medias móviles, día de la semana, mes y día)
al agregarle cada grupo de variables. Validación con origen móvil: 9 cortes, 28 días de horizonte, sin usar datos posteriores al corte.

## Qué contempla hoy el motor

| Factor | En el motor | Notas |
|---|---|---|
| Precio | Sí (`price_ratio`) | El precio futuro se congela en el último observado, salvo un precio futuro confirmado |
| Promociones | Sí (`promo_discount`) | Solo las que el negocio ya conocía al corte |
| Feriados y fechas festivas | Sí | Nacionales trasladados, locales por cantón, víspera y día siguiente, Carnaval, Día de la Madre y del Padre, Black Friday, Navidad |
| Quincena, fin de mes, décimos | Sí | Décimo cuarto según región |
| Temporada escolar | Sí (`is_school_season`) | Regreso a clases por régimen: Sierra 15 ago–10 sep, Costa abril–10 may |
| Clima | No activo | Declarado en el catálogo de factores (`weather.observed`, `weather.forecast`), sin fuente conectada |
| Indicadores económicos | No activo | Interfaz lista (`features/external.py`), sin conectores |

Estas variables las usa LightGBM. Los modelos estadísticos solo ven el día de la semana y, con su interruptor, los feriados.

## Datos (no se versionan)

| Base | Qué es | Factores que permite probar | Origen usado |
|---|---|---|---|
| Favorita | Supermercado, Ecuador, producto × tienda, diario | Feriados, quincena, escuela, promociones, clima | Ver `../lectura_por_periodo/README.md`; clima diario de NOAA GSOD (`noaa-gsod-pds.s3.amazonaws.com/<año>/<estación>.csv`; Quito 84072599999, Guayaquil 84203099999, Cuenca 84239099999, Puyo 84179099999) |
| M5 | Walmart, EE. UU., producto × tienda, diario | Precio semanal real, eventos y feriados, días de subsidio | `raw.githubusercontent.com/Nixtla/m5-forecasts/main/datasets/m5.zip` |
| Rossmann | Farmacias, Alemania, venta diaria por tienda | Promociones, feriados, vacaciones escolares | Copia pública de `train.csv` y `store.csv` de la competencia de Kaggle |

Favorita, M5 y Rossmann vienen de competencias de Kaggle: revisar sus condiciones antes de cualquier uso que no sea prueba interna.

## Cómo se ejecuta

```bash
python run_tests.py favorita <corrida_favorita_desde_2015.pkl> <dir_noaa>
python run_tests.py m5 <dir_m5>
python run_tests.py rossmann <dir_rossmann>
```

## Resultados (2026-11-12)

Cambio del error frente al modelo sin el factor (negativo = mejora). «Ruido» = diferencia entre dos semillas del mismo modelo: un cambio
menor que eso no es evidencia.

**Rossmann — venta por tienda (250 tiendas). Base: 0,136 por día, 0,104 por semana. Ruido ±2 %.**

| Factor agregado | Por día | Por semana | Total diario |
|---|---|---|---|
| Promociones | −20 % | −29 % | −33 % |
| Feriados | −8 % | −9 % | −12 % |
| Vacaciones escolares | +1 % | +2 % | +1 % |
| Todo | −27 % | −38 % | −45 % |

**M5 — producto × tienda (600 series). Base: 0,525 por día, 0,308 por semana. Ruido ±0,4 %.**

| Factor agregado | Por día | Por semana | Total diario |
|---|---|---|---|
| Precio (futuro conocido) | 0 % | −0,5 % | −1,9 % |
| Precio (congelado al corte, como el motor) | 0 % | −0,5 % | −1,9 % |
| Eventos y feriados | −0,1 % | −0,2 % | −3,0 % |
| Días de subsidio | +0,2 % | +0,7 % | +1,1 % |
| Todo | −0,3 % | −0,7 % | −5,6 % |

**Favorita — producto × tienda (556 series). Base: 0,349 por día, 0,215 por semana. Ruido ±0,8 %.**

| Factor agregado | Por día | Por semana | Total diario |
|---|---|---|---|
| Feriados y fechas festivas | −1,0 % | +0,3 % | −7,2 % |
| Promociones | −0,8 % | −1,7 % | +0,7 % |
| Quincena, fin de mes y décimos | +0,4 % | +0,4 % | −0,1 % |
| Temporada escolar | +0,7 % | +1,1 % | +2,2 % |
| Clima observado (techo: como si se conociera) | +0,5 % | +1,0 % | +0,3 % |
| Todo | −1,8 % | −0,8 % | −9,7 % |

Lectura:

- **Promociones y feriados son los factores que más aportan**, y aportan sobre todo en los totales (tienda o empresa). En el producto
  individual el ruido diario tapa casi todo el efecto.
- En los días afectados el efecto sí se ve: en Favorita el error en feriados y vísperas baja de 0,44 a 0,40 y el sesgo en días de
  promoción de −14 % a −5 %; en Rossmann el sesgo en días de promoción baja de −12 % a −4 %.
- **Precio**: en M5 casi no aporta porque el precio casi no cambia (1,8 % de los días tienen precio distinto al de 28 días antes). Por lo
  mismo, congelar el precio futuro —lo que hace el motor— no cuesta nada ahí. En esos pocos días el pronóstico queda 20 % por debajo
  con o sin la variable: el modelo no aprende la sensibilidad al precio con tan pocos cambios.
- **Temporada escolar, quincena y clima no mejoraron** en estas bases, ni solos ni con el clima entregado como conocido. La quincena y
  los días de subsidio ya quedan cubiertos por la variable «día del mes».
- El efecto de las vacaciones escolares no se puede aislar en Rossmann: sola no aporta, y dentro de «todo» no se distingue.

## Límites

Supermercado y farmacia, no distribución mayorista; una configuración de modelo; el clima es de una estación por ciudad y con 22 % de
días sin dato de lluvia; ninguna base trae categorías sensibles al clima o a la escuela identificadas como tales (útiles escolares,
bebidas, ropa), donde el efecto podría existir aunque no se vea en el agregado.
