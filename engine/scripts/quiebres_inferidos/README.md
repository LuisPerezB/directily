# Quiebres de stock inferidos de las ventas: ¿acierta y sirve?

Scripts de **análisis** del interruptor `demand.stockout_inference` (`predictily_engine/profiling/stockouts.py`; apagado por defecto).
No cambian lo que se sirve. La regla y sus límites están en `docs/roadmap/cambios-2026-11/12-motor-quiebres-inferidos-de-las-ventas.md`.

Sin inventario no hay verdad contra la cual medir, así que se usan tres pruebas con verdad conocida y una sin ella:

```bash
python validar_demo.py <empresa demo>... [stockout_inference_alpha=0.01 ...]   # ¿lo marcado fue quiebre real? (inventario de la demo)
python efecto_demo.py <empresa demo>...                                        # pronóstico frente a la demanda REAL de la demo
# Favorita (datos en ../lectura_por_periodo/README.md): cuatro corridas y su comparación
python ../lectura_por_periodo/favorita_run.py <subset> <dir> a.pkl todo known
python ../lectura_por_periodo/favorita_run.py <subset> <dir> b.pkl todo+quiebres known
python ../lectura_por_periodo/favorita_run.py <subset> <dir> c.pkl todo known 7            # 7 = semilla de los quiebres simulados
python ../lectura_por_periodo/favorita_run.py <subset> <dir> d.pkl todo+quiebres known 7
python inyeccion.py a.pkl b.pkl c.pkl d.pkl      # con quiebres simulados: daño y recuperación, y cuántos se detectan
python comparar.py a.pkl b.pkl                   # datos tal cual: efecto de encender el interruptor
```

## Resultados (2026-11-12, reglas por defecto)

**1. Empresas demo: lo marcado frente al inventario real** (el motor no ve el inventario). Precisión = de los días marcados, cuántos eran
quiebre real; cobertura = de los días de quiebre real en cero, cuántos se marcan.

| Venta media diaria | Ferretería: precisión | Ferretería: cobertura | Distribuidora: precisión | Distribuidora: cobertura |
|---|---|---|---|---|
| Menos de 1 | 69 % | 4 % | — (3 días) | 0 % |
| 1 a 5 | 86 % | 34 % | 98 % | 41 % |
| 5 a 10 | 100 % | 62 % | 100 % | 62 % |
| 10 a 50 | 100 % | 63 % | 100 % | 62 % |
| Todas | 88 % | 20 % | 99 % | 35 % |

- En la ferretería, 111 de los 175 días marcados sin quiebre real son productos que no volvieron a venderse (se marcan hasta el tope de
  42 días).
- La cobertura tiene techo: los dos primeros días de cada racha nunca se marcan y los productos de venta esporádica quedan fuera.
- Más sensible (`alpha = 0,01`, `min_days = 2`): cobertura 75–77 % desde 5 unidades diarias, precisión total 80 % y 94 %.

**2. Favorita con quiebres simulados** (554 series reales; se borran las ventas de 4 351 días en 157 series de venta regular, en rachas
de 4 a 21 días antes del período final; 43 series siguen sin stock el día en que se pronostica). Error de lo servido en el período final
de 90 días, contra la venta real:

| Corrida | Por día | Por semana | Total diario | Sesgo |
|---|---|---|---|---|
| Datos originales, sin inferencia | 0,339 | 0,221 | 0,069 | +2,6 % |
| Con quiebres simulados, sin inferencia | 0,372 | 0,258 | 0,070 | −3,3 % |
| Con quiebres simulados, con inferencia | 0,346 | 0,233 | 0,065 | +1,2 % |

| Solo las 157 series con quiebres simulados | Por día | Por semana | Sesgo |
|---|---|---|---|
| Datos originales, sin inferencia | 0,346 | 0,217 | +6,8 % |
| Con quiebres simulados, sin inferencia | 0,408 | 0,303 | −10,0 % |
| Con quiebres simulados, con inferencia | 0,371 | 0,252 | +3,2 % |
| … las 43 sin stock en el origen, sin inferencia | 0,640 | 0,585 | −42,4 % |
| … las 43 sin stock en el origen, con inferencia | 0,521 | 0,430 | −0,8 % |

- **Detección**: 74 % de los días simulados (48 % en rachas de 4 a 7 días, 69 % de 8 a 14, 79 % de 15 a 21); 99 % de las marcas nuevas
  caen sobre días simulados.
- **Recupera 79 % del daño en el error por día y 67 % en el semanal.** No lo recupera todo: los primeros días de cada racha siguen
  siendo ceros y la imputación es una media, no la venta que hubo.

**3. Favorita tal cual** (sin verdad conocida). Marca 2 793 días (1,1 %) en 213 de 555 series; tras una racha marcada la venta vuelve a
0,9 veces su nivel previo (en la demo, 1,02): parecen interrupciones de abasto, pero no se puede comprobar.

| Corrida | Por día | Por semana | Total diario | Sesgo |
|---|---|---|---|---|
| Sin inferencia | 0,339 | 0,221 | 0,069 | +2,6 % |
| Con inferencia (mismos días) | 0,339 | 0,224 | 0,068 | +2,3 % |

- Efecto dentro del ruido. En las 26 series con una racha marcada en los 28 días previos al origen el pronóstico sube: error por día
  0,829 → 0,794, sesgo +3 % → +10 % (el motor supone que vuelven a su nivel).
- Una versión anterior de la salvaguarda de sucursal dio en esta misma comparación +1,1 % por día y +8,5 % en el total diario: el
  resultado es sensible a qué modelo gana la selección; no debe leerse como mejora ni como daño.

**4. Demo, contra la demanda real** (lo servido en el período final frente a la demanda latente, también en los días de quiebre):

| Empresa | Corrida | Por semana | Sesgo | Sesgo en series con quiebre real reciente |
|---|---|---|---|---|
| Distribuidora | Con inventario (techo) | 0,191 | −2,0 % | −2,1 % |
| Distribuidora | Sin inventario | 0,194 | −3,7 % | −5,6 % |
| Distribuidora | Sin inventario, con inferencia | 0,191 | −2,7 % | −3,0 % |
| Ferretería | Con inventario (techo) | 0,208 | −2,7 % | −2,9 % |
| Ferretería | Sin inventario | 0,206 | −2,4 % | −3,9 % |
| Ferretería | Sin inventario, con inferencia | 0,205 | −3,5 % | −4,1 % |

- En la distribuidora recupera cerca de la mitad del sesgo; en la ferretería no (ahí ni el inventario real mejora el pronóstico: sus
  quiebres pesan poco).

## Lectura

La regla acierta cuando marca (88–99 % en la demo, 99 % en lo simulado) y, donde hay quiebres, recupera entre la mitad y tres cuartas
partes del daño. Donde no los hay, o son pocos, no aporta. No se puede comprobar con los datos de una empresa sin inventario: se aprueba
revisando las rachas que marca (`summary["day_status"]["stockout_inference"]`) con quien conoce el negocio.

## Límites

Una sola base real, de supermercado; los quiebres simulados son rachas limpias de ceros totales en productos de venta regular (el caso
favorable a la regla); la demo es sintética con demanda independiente entre días.
