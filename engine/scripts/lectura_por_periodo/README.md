# Lectura por período: ¿a qué granularidad es fiable el pronóstico de cada producto?

Scripts de **análisis** (no forman parte del motor ni cambian lo que se sirve). Miden cómo cae el error del pronóstico cuando se lee
por períodos más largos (día → semana → 4 semanas), por tramo de venta diaria, y prueban una regla automática que elige el período de
lectura de cada producto. Corren sobre las empresas demo (sintéticas) y sobre una base pública real.

## La ley que se comprueba

Para un producto con venta media diaria `μ` leído en períodos de `T` días (`N = μ·T` unidades esperadas por período):

```
E[e_T²] = φ·μ·T  +  c²·μ²·T  +  δ²·μ²·T²
          conteo     ruido diario   error de nivel

error relativo²:  r(T)² = φ/(μT) + c²/T + δ²        WAPE(T) ≈ √(2/π) · r(T)
```

- Los dos primeros términos son ruido independiente entre días: bajan con `1/T` (el error, con `1/√T`).
- El tercero es el error de nivel del pronóstico (sesgo persistente): **no baja** por alargar el período; es el piso `√(2/π)·δ`.
- `dr²/dT = −(φ/μ + c²)/T² < 0`: alargar el período siempre baja el error, con rendimientos decrecientes (reducirlo a la mitad pide 4 veces más días).
- Período mínimo para una tolerancia `τ`: `T* = (φ/μ + c²) / (π·τ²/2 − δ²)`; solo existe si el piso queda por debajo de `τ`.
- Con conteo puro (φ = 1, c = δ = 0): `N* = 2/(π·τ²)` → 16 unidades por período para 20 %, 10 para 25 %, 64 para 10 %.

## Datos

- **Demo**: `predictily_engine.synthetic` (semilla 42). No tiene productos de más de ~50 unidades diarias.
- **Real**: ventas diarias de Corporación Favorita (Ecuador, 2013–2017, 54 tiendas, ~4 000 productos), publicada para una competencia de
  Kaggle. Copia pública usada por `datasetsforecast`:
  `https://nixtla-public.s3.amazonaws.com/datasetsforecast/favorita-grocery-sales-forecasting2.zip` (930 MB; `train.csv` pesa 5 GB).
  **No se versiona**: se descarga fuera del repositorio. Sus condiciones de uso son las de la competencia; revisarlas antes de cualquier
  uso que no sea prueba interna. No trae precios ni inventario; sí una marca de promoción por día, sin porcentaje de descuento.

## Cómo se ejecuta

```bash
# demo
python demo_run.py <dir_salida> ferreteria_andina distribuidora_costa
# real: 4 tiendas (Quito, Guayaquil, Cuenca, Puyo), 160 productos estratificados por volumen
python -I favorita_extract.py <dir_favorita> <subset.parquet> [desde AAAA-MM-DD]      # por defecto 2016-06-01
python favorita_run.py <subset.parquet> <dir_favorita> <salida.pkl> [todo|base][+quiebres] [none|known|<días>] [semilla de quiebres simulados]
# análisis (cualquiera de los .pkl)
python curve.py <archivo.pkl>          # curva de error por período, ley ajustada y regla automática
python extra.py <archivo.pkl>          # horizonte operativo, distancia del pronóstico, promociones, regla encogida por tramo
python weekday.py <archivo.pkl>        # picos entre semana: de dónde tomar el reparto a días
python weekly.py <archivo.pkl>         # ¿modelo semanal directo o suma del diario?
python greedy.py <archivo.pkl>         # selección codiciosa de ensamble (Caruana 2004, AutoGluon) frente al promedio simple
python compare.py etiqueta=a.pkl ...   # compara corridas: familias, tramos, sesgo con y sin promoción, regla de lectura
python diagnose.py <archivo.pkl>       # error de nivel frente a ruido, por categoría y sucursal, series que más aportan
python promo_adjust.py <archivo.pkl>   # ajuste de promociones para AutoETS (prueba) y productos de pocos días de venta
```

`todo` = los cuatro interruptores de precisión encendidos (selección por el total diario, familia del mismo día de la semana, ajuste de
feriados y combinación AutoETS + LightGBM); `base` = configuración por defecto. Promociones: `known` = el negocio conoce cada promoción
con un año de anticipación (techo del beneficio); `<días>` = la conoce ese número de días antes de que empiece. El modelo analizado es
el promedio de AutoETS y LightGBM (`ets_lightgbm_mean`).

## Resultados (2026-11-12)

Error (WAPE por producto) del mismo pronóstico según el período de lectura. Primeros 28 días de cada una de las 4 ventanas del backtest.

**Real (Favorita, 550 series):**

| Venta media diaria | Series | Día | Semana | 2 semanas | 4 semanas |
|---|---|---|---|---|---|
| Menos de 1 | 143 | 1,22 | 0,71 | 0,60 | 0,51 |
| 1 a 5 | 252 | 0,72 | 0,39 | 0,33 | 0,27 |
| 5 a 10 | 137 | 0,58 | 0,35 | 0,31 | 0,26 |
| 10 a 50 | 124 | 0,46 | 0,29 | 0,25 | 0,23 |
| 50 a 200 | 42 | 0,29 | 0,18 | 0,16 | 0,15 |
| 200 o más | 15 | 0,25 | 0,16 | 0,15 | 0,09 |
| Todas | 550 | 0,35 | 0,21 | 0,19 | 0,15 |

- El error baja con el período en todos los tramos, pero de día a semana se divide entre 1,6 (no entre √7 = 2,65): en datos reales
  manda el error de nivel. Piso estimado al alargar el período: ~0,30 entre 1 y 50 unidades diarias, ~0,14 desde 50.
- En la demo la división es 2,3 y el piso 0,08–0,12: su ruido es independiente entre días por construcción.
- El error semanal crece con la distancia: 0,18 la primera semana, 0,22 las semanas 2–4, 0,25 las semanas 9–12.
- Promociones (no entregadas al motor): 9 % de los días y 22 % de las unidades; en esos días el pronóstico queda 11 % por debajo y en
  los demás 6 % por encima.
- Regla por serie (período más corto cuyo error en las ventanas de selección ≤ 25 %, error encogido hacia su tramo), comprobada en el
  período final: lectura diaria 28 series y 41 % de las unidades (error 0,17; cumplen 100 %); semanal 82 series y 23 % (0,24; 78 %);
  4 semanas 86 series y 12 % (0,21; 78 %); ninguna 352 series y 24 % de las unidades.
- Picos entre semana (error por día repartiendo el total semanal): plano 0,46, perfil común de la empresa 0,43, perfil propio encogido
  0,40, perfil de la categoría en la sucursal 0,37 (el pronóstico diario del motor: 0,36).
- Pronosticar directamente la serie semanal no mejora a sumar el pronóstico diario (demo).
- La selección codiciosa de ensamble no mejora al promedio simple AutoETS + LightGBM (demo).

## Pruebas para subir el acierto con datos reales (2026-11-12)

Mismas 550 series de Favorita. «Servido» = lo que el motor entrega, medido en el período final de 90 días. «Promedio» = AutoETS +
LightGBM en las 4 ventanas, primeros 28 días.

| Corrida | Servido: por día | Servido: total diario | Promedio: por día | Promedio: por semana | Sesgo en días de promoción |
|---|---|---|---|---|---|
| Base, sin promociones | 0,361 | 0,086 | 0,345 | 0,213 | −11 % |
| Todo, sin promociones | 0,341 | 0,064 | 0,345 | 0,213 | −11 % |
| Todo, promociones conocidas | 0,339 | 0,069 | 0,340 | 0,209 | −7 % |
| Todo, promociones conocidas 14 días antes | 0,347 | 0,070 | 0,343 | 0,211 | −12 % |
| Todo, promociones conocidas, historia desde 2015 | 0,354 | 0,086 | 0,342 | 0,211 | −7 % |

- **Los cuatro interruptores sí mejoran en datos reales**: el error del total diario baja de 0,086 a 0,064 y el error por producto y
  día de 0,361 a 0,341.
- **Las promociones ayudan poco.** LightGBM (el único que las ve) baja su error semanal de 0,213 a 0,204 y su sesgo en días de promoción
  de −14 % a −6 %; el promedio apenas se mueve porque AutoETS no las ve.
- **Promociones conocidas solo 14 días antes: sin ganancia a 28 días y peor a 90** (LightGBM queda 7 % por debajo): aprende el alza y
  después del día 14 no recibe promociones. El calendario de promociones debe cubrir el horizonte que se lee.
- **Ajuste de promociones para AutoETS** (alza por serie estimada como cociente entre días con y sin promoción): **empeora** (error por
  día de 0,360 a 0,428; sobrestima el alza, ×2,3 de mediana). No sirve así.
- **Más historia (desde 2015) no mejora** el promedio y empeora lo servido: incluye el terremoto de abril de 2016 y otro régimen de ventas.
- **Regla de lectura** (tolerancia 25 %): con promociones conocidas, 78 % de las unidades tienen lectura fiable (76 % sin ellas) y 93 %
  de esas unidades cumplieron en el período final.
- **Dónde está el error que queda** (`diagnose.py`): con el nivel de 28 días perfecto el error semanal bajaría de 0,213 a 0,150 y el
  diario de 0,345 a 0,306. El resto del error diario es ruido. Los casos que más pesan son cambios bruscos de nivel de un mes a otro
  (productos que caen a la quinta parte o vuelven), que ninguna familia anticipa con la historia de ventas; la base no trae inventario
  para saber si fueron quiebres de stock.
- Los productos que venden en uno o dos días de la semana son 3 % de las unidades y su error semanal es igual al diario: también es
  error de nivel, no de reparto.

## Límites

Una sola base real, de supermercado (no de distribuidora), un solo corte final de 90 días, sin precios ni inventario, y con las
promociones como marca de sí o no (descuento nominal de 10 %). La
ley con un único `δ` para toda la empresa no describe bien los datos reales (el piso cambia con el volumen): la regla que funciona es la
empírica por serie.
