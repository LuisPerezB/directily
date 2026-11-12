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
python m5_full.py <dir_m5> [días de historia] [dir_salida]     # las 30 490 series de M5, últimos 28 días

# modelo fundacional dentro del promedio (ver más abajo); el segundo paso necesita torch y chronos-forecasting, que el motor no instala
python fundacional.py preparar <rossmann|favorita|m5> <dir_salida> <argumentos de la base…>
python fundacional.py chronos <dir_salida> <dir_pesos_chronos2> [base,todo] [días de historia] [últimos N cortes]
python fundacional.py comparar <dir_salida>
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

## Modelo fundacional dentro del promedio (2026-11-12)

¿Baja el error si al promedio del motor (AutoETS + LightGBM) se le suma Chronos-2, un modelo preentrenado que se usa tal cual, sin
entrenarlo con los datos de la empresa? Pesos públicos (478 MB, no se versionan):
`https://autogluon.s3.amazonaws.com/chronos-2/config.json` y `…/model.safetensors`; paquete `chronos-forecasting` 2.3.2 (Apache 2.0; la
licencia de los pesos no se pudo leer desde este entorno: confirmarla antes de usarlos en producción). Mismos cortes de origen móvil y
mismos factores que LightGBM. «Mediana» y «media» son los dos puntos que se pueden sacar de sus cuantiles.

Cambio frente al mejor candidato que el motor ya tiene en cada base (negativo = mejora):

| Base | Referencia del motor | Combinación | Por día | Por semana | Total diario | Cortes en que gana (día / semana / total) |
|---|---|---|---|---|---|---|
| Rossmann, 250 tiendas, 9 cortes | LightGBM con factores (0,100 / 0,065 / 0,058) | LightGBM + Chronos-2 con factores (media) | −7,4 % | −7,8 % | +4,7 % | 7 / 7 / 5 de 9 |
| Rossmann | igual | LightGBM + Chronos-2 sin factores (media) | +5,5 % | +14,4 % | +29,7 % | 5 / 4 / 2 de 9 |
| Favorita, 554 series, 6 cortes | AutoETS + LightGBM (0,321 / 0,195 / 0,081) | AutoETS + LightGBM + Chronos-2 sin factores (media) | −0,8 % | −1,9 % | +2,4 % | 5 / 5 / 1 de 6 |
| Favorita | igual | la misma, con la mediana | −2,4 % | −3,3 % | +0,7 % | 6 / 6 / 2 de 6 |
| Favorita | igual | AutoETS + LightGBM + Chronos-2 con factores (media) | −0,8 % | −2,4 % | +2,8 % | 4 / 5 / 1 de 6 |
| M5 completo, 30 490 series, 1 corte | AutoETS + LightGBM (0,724 / 0,368 / 0,045) | AutoETS + LightGBM + Chronos-2 sin factores (media) | −0,8 % | −2,2 % | +10,8 % | — |
| M5 completo | igual | LightGBM + Chronos-2 sin factores (media) | −0,3 % | −1,9 % | −2,5 % | — |
| M5 completo | igual | AutoETS + LightGBM + Chronos-2 sin factores (mediana) | −3,7 % | −0,7 % | +168 % | — |

- **Sumar Chronos-2 baja el error por producto en las tres bases**: entre 2 y 8 % por semana y entre 0,3 y 7 % por día. La mejora
  semanal supera el ruido entre semillas (±2 % en Rossmann, ±0,8 % en Favorita, ±0,4 % en M5); la diaria con la media es del orden del
  ruido en Favorita. **No baja el error del total diario** (queda igual o peor, salvo LightGBM + Chronos-2 en M5).
- **Solo, Chronos-2 no le gana al motor**: en Rossmann queda 13 % por detrás de LightGBM con los mismos factores; en Favorita y M5 (con
  la media) queda por producto a menos de 2 % del promedio actual (algo peor en Favorita, algo mejor en M5) y peor en el total diario.
  La ganancia viene de combinar, porque sus errores no coinciden con los de LightGBM.
- **Los factores importan donde las promociones mandan**: en Rossmann, sin ellos Chronos-2 empeora la combinación; en Favorita no
  cambian nada y cuestan 15 veces más tiempo.
- **La mediana gana el error por día y pierde el total.** En M5 (54 % de los días en cero) Chronos-2 con la mediana tiene el menor
  error por día (0,668) pero pronostica 31 % menos unidades de las vendidas y su error semanal es 23 % peor. Con la media: 0,718 por día
  y sesgo −3 %. Para inventario sirve la media.
- **Esto explica la distancia con los benchmarks publicados.** Con la mediana, Chronos-2 mide aquí 0,775 del error del naive
  estacional (publicado: 0,767); con la media, 0,833. El promedio del motor mide 0,840 y apunta a la media: comparado con el mismo
  criterio queda a 0,8 % de Chronos-2 (0,840 frente a 0,833), no a 9 % (0,840 frente a 0,767).
- **Costo en CPU (4 núcleos)**: 26 ms por serie y corte sin factores (30 490 series en 13 minutos); 0,4 a 0,5 s por serie con 8 a 14
  factores. Requiere PyTorch (varios GB de instalación con la versión por defecto para Linux).

Límites de esta prueba: una sola ventana en M5 completo y sin factores; en Favorita, 512 días de historia y los 6 últimos cortes (por
tiempo de cómputo); no se verificó que estas bases estén fuera de los datos con que se preentrenó Chronos-2; la «media» es una
aproximación con 21 cuantiles. No se probó dentro del motor ni con su regla de selección.

## Límites

Supermercado y farmacia, no distribución mayorista; una configuración de modelo; el clima es de una estación por ciudad y con 22 % de
días sin dato de lluvia; ninguna base trae categorías sensibles al clima o a la escuela identificadas como tales (útiles escolares,
bebidas, ropa), donde el efecto podría existir aunque no se vea en el agregado.
