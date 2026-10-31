# Manual básico de uso — Predictily

> **Anticipa tus ventas. Optimiza tus decisiones.**
> Este manual explica, pantalla por pantalla y sin tecnicismos, cómo usar Predictily.

---

## 1. Qué es Predictily (y qué no es)

Predictily toma las ventas que usted ya registra en su sistema de facturación, POS o ERP y calcula:

- cuánto se estima que venderá de cada producto, en cada sucursal, en los próximos 7, 14, 30, 60 o 90 días (según su plan);
- qué productos podrían agotarse y cuánto convendría comprar;
- qué compradores probablemente volverán a comprar, qué productos y cuánto;
- un plan de compras sugerido que respeta presupuesto, capacidad de almacén y condiciones de sus proveedores;
- qué tan acertados han sido los pronósticos anteriores.

**No reemplaza** su sistema de facturación ni su contabilidad: solo lee sus datos. Tampoco hace pedidos ni
compromete dinero por usted: todas las compras sugeridas son recomendaciones que usted decide.
Las cifras de ejemplo que verá en la aplicación de demostración son **ficticias**.

Los pronósticos se calculan con modelos estadísticos y de *machine learning* que se prueban contra su propio
historial. Predictily siempre los compara con un método sencillo de referencia y solo usa un modelo más complejo
si demostró equivocarse menos.

---

## 2. Crear su cuenta y su empresa

1. En la página principal pulse **Crear cuenta gratis**, complete nombre, correo y contraseña (mínimo 8
   caracteres) y acepte los términos.
2. Luego cree su **empresa**: nombre comercial, razón social y RUC (opcionales), sector y zona horaria
   (Ecuador continental por defecto). La moneda es el dólar (USD).
3. Usted queda como **Propietario** de la empresa. Puede pertenecer a varias empresas y cambiar entre ellas con
   el selector de la parte superior.

---

## 3. Primeros pasos

La pantalla **Primeros pasos** muestra una lista de verificación:

| Paso | Para qué sirve |
|---|---|
| Registrar sucursales | Use el mismo código de establecimiento de sus facturas (por ejemplo `001`). |
| Importar ventas | Es lo único indispensable. Idealmente 12 meses o más. |
| Importar productos | Opcional si sus ventas ya traen código y nombre del producto. |
| Importar inventario | Opcional, pero necesario para alertas de quiebre de stock y compras sugeridas. |
| Generar el primer pronóstico | Entrena y evalúa los modelos con sus datos. |

**¿Quiere probar antes?** Si su empresa aún no tiene ventas, el botón **Cargar datos de demostración
(ficticios)** carga datos de un comercio inventado. Esos datos **no corresponden a ninguna empresa real**.

---

## 4. Importar datos (asistente)

Menú **Importar datos**. El asistente tiene cuatro pasos:

1. **Archivo**: elija qué datos importa (ventas, productos, inventario, plazos de proveedores, sucursales,
   proveedores, promociones, compromisos comerciales, calendario de eventos, conversiones de unidades o almacenes) y suba un archivo **CSV o Excel (.xlsx)** de hasta 20 MB. Puede descargar una
   plantilla con las columnas esperadas.
2. **Columnas**: Predictily sugiere qué columna de su archivo corresponde a cada dato (por ejemplo, su columna
   «PVP» → *Precio unitario*). Revise las sugerencias, corrija lo necesario y ajuste las opciones de lectura
   (formato de fecha, separador decimal, sucursal por defecto, crear productos nuevos automáticamente…).
   Los campos con asterisco son obligatorios.
3. **Revisión**: Predictily valida todo el archivo y muestra:
   - filas leídas, válidas, con error y duplicadas;
   - documentos nuevos, que se actualizarán y sin cambios;
   - facturas anuladas (se guardan pero no cuentan como venta) y devoluciones (se registran aparte);
   - un **reporte de calidad** y la lista de errores por fila, descargable en CSV.

   Si hay errores puede **corregir el mapeo u opciones** y volver a validar, corregir el archivo y subirlo de
   nuevo, o activar *Importar solo filas válidas*. Si algo no le convence, **Rechazar** descarta todo.
4. **Importado**: confirmación con el resumen.

**Reimportar no duplica.** Cada documento se identifica por su tipo y número (por ejemplo factura
`001-001-000012345`). Si vuelve a subir el mismo archivo, o uno actualizado, los documentos existentes no se
duplican; los que cambiaron (por ejemplo una factura anulada después) se actualizan.

El historial de importaciones está en **Fuentes de datos**.

---

## 5. Cómo leer el estado de un resultado

Cada resultado nuevo (pronóstico, predicción de compra, plan de compras, simulación) trae una **etiqueta de calidad**
y una sección de **trazabilidad** con sus limitaciones y supuestos. Predictily nunca muestra una cifra como completa
si no lo es:

| Etiqueta | Qué significa para usted |
|---|---|
| **Resultado completo** | Hay datos y pruebas suficientes. Aun así, es una estimación, no una garantía. |
| **Con reservas** | Hay cifras, pero con una limitación importante (por ejemplo, las pruebas no tuvieron un período final independiente). Lea las limitaciones. |
| **Datos insuficientes** | No hay datos suficientes. Se muestra solo lo que existe, con el motivo; no se rellena con ceros ni se inventa un número. |
| **Sin configurar** | Falta cargar o configurar algo (por ejemplo, recetas de producción). |
| **Evidencia insuficiente** | La evidencia no alcanza para publicar el resultado (por ejemplo, un efecto causal). |

La etiqueta de calidad no es lo mismo que el estado del cálculo («Completado» o «Falló»): un cálculo puede terminar
bien y aun así dar un resultado con reservas o con datos insuficientes.

En la trazabilidad también verá de dónde viene la **incertidumbre** (medida en un período de prueba, rangos calibrados
con errores pasados, o *desconocida*; cuando es desconocida no se dibuja un rango como si se conociera), la **fecha de
corte**, el período cubierto y la **versión de los datos y de la configuración** con que se calculó.

---

## 6. Dashboard comercial

Responde seis preguntas para el horizonte elegido (7, 14, 30, 60 o 90 días, según su plan) y la sucursal seleccionada:

| Pregunta | Dónde verlo |
|---|---|
| ¿Cuánto se estima que venderé? | Tarjeta de unidades estimadas, con su **rango probable (80 %)** y la variación frente al período anterior. |
| ¿Cuánto podría facturar? | Ingresos estimados, suponiendo los precios recientes. |
| ¿Qué productos cambiarán su demanda? | Listas de mayor aumento y mayor disminución esperada. |
| ¿Qué productos podrían agotarse? | Tarjeta de riesgo y tabla de productos con días de cobertura y probabilidad de quiebre. |
| ¿Cuánto inventario necesito? | Cobertura mediana del inventario y cantidad sugerida por producto. |
| ¿Cuánto dinero inmovilizaría en compras? | Compra sugerida estimada (cantidad sugerida × último costo conocido). |
| ¿Qué tan confiables han sido las predicciones? | Error histórico del modelo frente al método de referencia. |

Desde el dashboard también puede **actualizar el pronóstico** cuando haya cargado ventas nuevas.

**Horizonte sin respaldo suficiente.** Un horizonte solo se considera respaldado si su historia es al menos el doble
del horizonte y las pruebas incluyeron ventanas de esa duración. Si no, el panel muestra un aviso, las tarjetas
quedan en color de advertencia («cifra provisional»), no se muestra la variación frente al período anterior y el
gráfico se dibuja punteado y sin rangos. La cifra existe, pero **no debe usarse como un pronóstico completo**.

---

## 7. Pronósticos de demanda

Elija el **horizonte** (7, 14, 30, 60 o 90 días; solo aparecen los incluidos en su plan). Muestra el **histórico de ventas** (línea continua) y el **pronóstico** (línea punteada) para la empresa, una
sucursal, una categoría o un producto. Puede ver por día, semana o mes, en unidades o en ingresos.

**Cómo leer las bandas:**

- La línea punteada es el **valor más probable**.
- La **banda oscura (80 %)**: según la evaluación histórica, la venta real cae dentro de ella aproximadamente
  8 de cada 10 veces.
- La **banda clara (95 %)**: aproximadamente 95 de cada 100 veces.
- Una banda ancha significa más incertidumbre (por ejemplo, productos de venta esporádica). No es un error:
  es información útil para decidir cuánto stock de seguridad tener.
- Los puntos rojos en el histórico marcan días con **quiebre de stock**: ese día la venta pudo ser menor que
  la demanda real porque no había producto.

Cada pronóstico indica su **versión**, la **fecha de generación**, la **fecha de corte** (hasta qué día de
datos se usó), el **modelo** y el **supuesto de precio**. Las versiones anteriores se conservan; puede elegir
cualquiera en el selector *Versión*.

**Generar un nuevo pronóstico:** pulse *Generar nuevo pronóstico*. El proceso corre en segundo plano (puede
tardar algunos minutos) y puede seguir usando Predictily mientras tanto; verá el avance por etapas.

**Avisos del horizonte.** Bajo el selector verá, según el caso:

- **Sin respaldo suficiente**: la historia o las pruebas no alcanzan para ese horizonte. Verá los motivos, la cifra
  como *provisional* (línea fina punteada, sin banda de rango) y el total marcado como tal.
- **Con métricas optimistas**: no hubo un período final reservado para probar; la precisión mostrada proviene de
  las mismas pruebas que eligieron el modelo.
- **No calculado**: la última ejecución se hizo a un horizonte menor; genere un pronóstico con el horizonte que necesita.
- **Respaldo desconocido**: el pronóstico es anterior a esta verificación.

**Casos especiales.** Algunas series llevan una insignia que explica cómo se calculó su cifra: *producto nuevo con
respaldo por categoría* (menos de 28 días de historia: se usa la mediana de productos parecidos), *producto nuevo con
promedio propio*, *historia escasa*, *descontinuado* (demanda cero declarada), *sucursal cerrada o en apertura* y
*evento extraordinario* (solo se marca; no cambia el valor).

**Mediana (P50).** La línea de la mediana solo aparece si Predictily comprobó, en una prueba, que mejora a usar el
pronóstico central como mediana. Si no la ve, es que no se publica: no se sustituye por otro valor.

**Trazabilidad.** Al pie de cada pronóstico verá su calidad, limitaciones, supuestos, el origen de la incertidumbre y
las versiones de datos y configuración.

---

## 8. Productos y categorías

Tabla con, para cada producto o categoría: unidades vendidas en el período previo, unidades pronosticadas
(con su rango del 80 %), el cambio esperado, ingresos estimados, el modelo usado, el **patrón de demanda**
(regular, errática, intermitente…) y su error histórico. Puede filtrar, ordenar, buscar y **exportar a CSV**.
Si el horizonte elegido no tiene respaldo suficiente, las cifras se marcan como *sin respaldo* y no se muestran sus
rangos. La columna **Caso especial** muestra las insignias descritas en «Pronósticos de demanda».

---

## 9. Compradores (comportamiento de compra)

Menú **Compradores**. Responde, para cada comprador identificado y cada producto que ya compró, en los próximos
**7, 30 o 90 días** (los que incluya su plan): ¿volverá a comprar?, ¿cuánto?, ¿cuánto se espera?, ¿cuánto sería en
dinero? y ¿a quién conviene contactar?

**Quiénes entran.** Solo compradores identificados que ya compraron ese producto. Las ventas sin comprador y las de
**consumidor final** no se convierten en una persona recurrente: no tienen predicción individual y su demanda queda en el
*residual* de la conciliación. Los documentos de identidad no se muestran.

### Predicciones

La tabla muestra, por comprador y producto:

| Columna | Qué significa |
|---|---|
| **Probabilidad de comprar** | Probabilidad de al menos una compra en el horizonte. Nunca se muestra como 0 % ni 100 % exactos (verá «menos de 1 %» o «más de 99 %»). Si no hay predicción, dice **Sin predicción**; no hay un cero. |
| **Cantidad si compra** | Suma de lo que compraría en la ventana, **suponiendo que compra**, con su rango del 80 %. |
| **Cantidad esperada** | Probabilidad × cantidad si compra. Incluye los casos en que no compra. |
| **Monto estimado** | Cantidad esperada × último precio neto. Es una **estimación con supuesto de precio**: no es una venta confirmada ni una factura futura. |
| **Comprometido** | Lo que ya tiene pedido o contrato confirmado y pendiente. La demanda del comprador es lo mayor entre lo esperado y lo comprometido; **no se suman**. |
| **Modelo** | Qué modelo calculó la cifra; *respaldo* indica la referencia simple. |
| **Avisos** | Poca historia, sin existencias hoy, poca disponibilidad reciente, precio desconocido, calibración degradada... |

Las predicciones asumen que el producto **está disponible** en la sucursal habitual del comprador. Abajo verá la
calidad del resultado, sus limitaciones y supuestos, y la **fuente del ranking** de productos probables (popularidad,
compra más reciente o probabilidad del modelo, según lo que funcionó mejor en las pruebas).

Si la última ejecución **se abstuvo** de un horizonte (datos insuficientes o sin modelo en producción), la pantalla lo
dice y ofrece los horizontes que sí tienen predicción; no muestra cifras.

### Ficha del comprador

Pulse el código de un comprador. Verá su **frecuencia** de compra, su intervalo habitual entre compras, los
**productos más probables** y dos señales:

- **Riesgo de inactividad**: compara su pausa actual con sus propias pausas anteriores. **No es abandono definitivo**:
  solo indica que lleva más tiempo sin comprar de lo que suele. Con pocos intervalos propios no se calcula.
- **Cambio de comportamiento**: si compra con más o menos frecuencia que en los 90 días previos. Se necesita historia suficiente.

### Evaluación

Muestra cómo se probó el modelo, con fechas: bloques de **entrenamiento, calibración, selección y prueba final**,
separados por el horizonte para que ninguna ventana de resultado se solape con el bloque siguiente, y hasta qué
fecha se conocen los resultados. Después compara el modelo con la referencia:

- **Brier** y **log loss**: menores son mejores. **PR-AUC**: mayor es mejor; se compara con la referencia y con la tasa base.
- **Curva de calibración**: si, cuando el modelo dice «20 %», realmente compran el 20 % de las veces. Cada punto indica
  cuántas observaciones tiene; los grupos con **menos de 30** se marcan como *muestra pequeña*. Si el modelo predice casi
  lo mismo para todos, la curva sale plana y se avisa.
- **Precisión y cobertura en los primeros k** del ranking, frente a popularidad y a la compra más reciente.
- Error de **cantidad** y de **monto** (este último, siempre una estimación).

El **nivel** del protocolo puede ser *completo*, *reducido*, *solo referencia* (no hubo datos para entrenar un modelo) o
*sin entrenar* (no hay modelo ni probabilidad para ese horizonte). Los datos de demostración comprueban el procedimiento,
**no validan la precisión comercial**.

### Conciliación

Compara, por producto, la **demanda agregada** del pronóstico con la suma de lo que se espera de los compradores:
*agregado = compradores modelados + comprometido fuera del modelo + residual*. Las dos vistas nunca se suman. Si los
compradores superan al agregado, la fila se marca en rojo como **inconsistente** con el exceso (no se altera ninguna
probabilidad). Sin demanda agregada, o con una incompleta, no se calcula el residual.

### Modelos y ejecuciones

- **Entrenar** reconstruye y evalúa los modelos. **Actualizar predicciones** usa los modelos en producción y recalcula
  con la fecha de hoy: la recencia cambia cada día aunque no haya ventas nuevas. Ambos corren en segundo plano con avance.
- **Modelos**: cada versión tiene un estado (candidato, validado, en producción, retirado, fallido) y un papel. Una
  versión validada o retirada se puede **volver a servir** (permiso de generar pronósticos), con confirmación: la versión
  en producción del mismo horizonte y objetivo se retira.

---

## 10. Inventario y abastecimiento

Para cada producto y sucursal muestra existencias disponibles (existencias − reservado + en tránsito), días de
cobertura, probabilidad de quiebre, punto de reorden, **compra sugerida** e inversión estimada.

Al abrir un producto verá **cómo se calculó**: demanda esperada durante el plazo de entrega más el período de
revisión, stock de seguridad según su nivel de servicio, y compra sugerida = punto de reorden − disponible
(nunca negativa). Los parámetros (nivel de servicio, período de revisión, plazo por defecto) se ajustan en
**Configuración › Parámetros**.

Puede marcar cada recomendación como *revisada*, *pedido registrado* o *descartada*. Esto solo deja registro:
**Predictily no envía pedidos a proveedores**.

---

## 11. Plan de compras

Menú **Plan de compras**. Predictily **sugiere**; **no envía pedidos ni compromete fondos**. Cada línea es una
recomendación que usted decide emitir.

### Plan

Defina el **objetivo** (minimizar el costo o maximizar el margen), la **cobertura** (flexible: puede dejar demanda sin
atender, que se declara; obligatoria: debe cubrir todo o no hay plan), el horizonte y el período. En *Supuestos del
cálculo* puede cambiar el costo anual de mantener inventario, la penalización por faltante y el peso del stock de
seguridad: son **supuestos**, no costos observados. El cálculo corre en segundo plano.

El resultado muestra:

- **Estado del solver.** Solo se dice «**Óptimo probado**» cuando el cálculo lo demostró. Otros estados se nombran como
  son: *factible con brecha* (se informa la brecha), *límite de tiempo agotado*, *plan de respaldo* (regla simple) y
  *sin plan posible*. Cuando no es óptimo probado, un aviso lo recuerda.
- **Qué comprar**: producto, sucursal, proveedor, cantidad (y en la unidad de la oferta), fecha sugerida de **emisión**
  y de **recepción**, costo, las **restricciones** que la limitan (presupuesto, capacidad del proveedor, almacén...) y
  **por qué** se eligió ese proveedor. Las líneas marcadas **Emitir al corte** tienen como fecha de emisión la fecha de corte del plan (el último día con
  datos). Solo cuando el corte es hoy se muestran como **Emitir ya**; si el plan se calculó con datos de días
  anteriores, la pantalla muestra «Datos hasta …» y sugiere regenerarlo antes de pedir.
- **Demanda no atendida**: cuánto no se cubre, en qué período y **por qué** (plazo de entrega, presupuesto, capacidad,
  almacén, falta de oferta comparable...).
- **Sin plan posible**: con cobertura obligatoria, un bloque explica las causas ordenadas por impacto, cuánto habría que
  relajar (presupuesto o capacidad necesarios) y el **plan de máxima cobertura factible**.
- **Comparación de ofertas**: cuántas fueron comparables y cuáles se excluyeron y por qué (falta una conversión de
  unidades o una tasa de cambio).
- Si no hay recetas, la **producción queda sin configurar**; no bloquea la reposición.

### Datos del plan

Ofertas de proveedores, tasas de cambio, capacidades de almacén, presupuestos, recursos y recetas de producción. Lo que
no se conoce se **deja vacío**; no se rellena con cero. Cada oferta muestra si es **comparable**: sin una conversión de
unidades declarada o sin una tasa de cambio explícita hacia su moneda, el planificador la excluye. Escribir requiere
permiso para administrar el catálogo.

### Costos

Pronostica el **costo unitario de compra** (no cantidades) y lo compara con una referencia simple (el último costo) en un
período de prueba. Si ningún método mejora a la referencia, se usa la referencia y se indica. Con menos de seis meses de
costos observados no se pronostica (*datos insuficientes*). Un rango desconocido se muestra como desconocido.

### Escenarios

Compara hasta ocho estrategias sobre el mismo corte, pronóstico base, unidades y horizonte. Cada entrada se clasifica como
**observada**, **pronosticada** o **supuesta**. Es una **simulación condicionada**: el cambio de demanda es un supuesto y
**no se deriva del precio**; no es una estimación causal. Si falta el costo, el margen se muestra como *desconocido* y la
estrategia no entra en el orden por margen.

### Causalidad

Revisa si hay datos y declaraciones suficientes para publicar el efecto del precio sobre la demanda. Hoy ningún
estimador causal está habilitado: el resultado es siempre **evidencia insuficiente**, sin efecto numérico, con la lista
de requisitos cumplidos y no cumplidos y **cómo corregirlos**. Un buen error de pronóstico no es un efecto causal.

### Simulación histórica

Re-juega distintas políticas de reposición (la actual, una de referencia y el planificador) con la información disponible
en cada fecha. **No reemplaza la validación con datos reales**: la demanda es la venta observada, censurada por quiebres,
así que el faltante queda subestimado. Las advertencias se muestran junto a la tabla.

---

## 12. Historial de precisión

Aquí se mide qué tan acertados han sido los pronósticos, siempre con **sus propios datos**:

- **Backtesting**: Predictily simula pronósticos en fechas pasadas, usando solo la información disponible en
  ese momento, y los compara con lo que realmente se vendió.
- **Error real**: cuando pasan los días, compara los pronósticos ya emitidos con las ventas que ocurrieron.

**Cómo interpretar las métricas:**

| Métrica | Qué significa | ¿Mejor? |
|---|---|---|
| **WAPE** | Error total en relación con lo vendido. 20 % = en conjunto, el pronóstico se desvió el 20 % de lo vendido. | Menor |
| **MAE** | Diferencia promedio en unidades entre lo pronosticado y lo vendido. | Menor |
| **Sesgo** | Positivo: tiende a pronosticar de más. Negativo: de menos. | Cercano a 0 |
| **Cobertura 80 %** | Veces que la venta real cayó dentro del rango del 80 %. | Cercano a 80 % |
| **Método de referencia** | Método simple (por ejemplo repetir la misma semana anterior). El modelo elegido debería equivocarse menos. | — |

Predictily **no** muestra un «porcentaje de precisión» comercial: muestra errores medidos. Es normal que el
error aumente cuanto más lejos se pronostica y que los productos de venta esporádica tengan errores mayores.

La sección *Comparar real vs. pronosticado* permite elegir una versión pasada y ver, día a día, el pronóstico
frente a la venta real.

### Métricas ampliadas, protocolo y modelos

Además de las métricas anteriores, la pantalla muestra:

| Elemento | Qué significa |
|---|---|
| **Error del total de cada horizonte** | Al comprar importa el total de los próximos 7, 14, 30, 60 o 90 días, no el error día a día. Se compara con el método simple. |
| **MASE / RMSSE** | Error comparado con repetir lo vendido la semana anterior (calculado solo con datos previos a cada prueba). **Menor que 1** significa que mejora ese método simple. |
| **Cuantiles P10, P50, P90** | Límites del rango probable y mediana. La mediana dice «no publicada» si no mejoró a la cifra central. Sin período final independiente no se evalúan. |
| **Amplitud de los rangos** | Qué tan ancho es el rango de 80 % y 95 %, en unidades por serie y día. |
| **Dinero aparte de unidades** | El error en dinero se mide por separado, en la moneda de la empresa. |
| **Muestra y exclusiones** | Cuántas observaciones y series se usaron y cuántas se excluyeron (por ejemplo, días con quiebre de stock). |

El **manifiesto del protocolo** responde en fechas: de qué a qué fecha se entrenó, se eligió el modelo, se calibraron los
rangos y se hizo la **prueba final**, que no participa en ninguna decisión. Si no hubo período final independiente, o las
ventanas de prueba se solapan, la pantalla lo advierte: esas métricas son optimistas.

La tabla **Respaldo por horizonte** indica qué horizontes tienen historia y pruebas suficientes y, si no, por qué.

**Modelos y versiones.** El registro muestra cada modelo con su estado y papel (principal o respaldo). Si el último
pronóstico no es confiable, quien tenga permiso de generar pronósticos puede **volver a la versión anterior**: la
ejecución elegida pasa a ser la vigente y se restauran sus modelos, tras una **confirmación**. No se reentrena nada: la
versión elegida conserva su fecha de corte, y para actualizarla basta generar un pronóstico nuevo. La operación queda en
la auditoría.

---

## 13. Simulador de escenarios

Permite explorar preguntas del tipo **«¿qué pasaría si…?»**: cambiar el precio, aplicar un descuento, un
aumento de costo del proveedor, un cambio de demanda esperado, el plazo de abastecimiento o el presupuesto de
compras. Muestra la base y el escenario lado a lado (unidades, ingresos, costo, margen bruto, compra necesaria),
las diferencias y los riesgos (margen negativo, presupuesto insuficiente, costos desconocidos…).

**Importante:** es una **simulación con sus supuestos**, no una predicción ni una estimación causal.
Si baja el precio, el simulador **no supone** que venderá más; si usted espera ese efecto, ingréselo en
*Cambio de demanda esperado*. La medición del efecto real de los precios sobre la demanda (elasticidad) es una
función futura que requiere más evidencia.

Puede **guardar** escenarios para revisarlos después. Para comparar varias estrategias con costos pronosticados y un plan de compras,
vea *Plan de compras › Escenarios*.

---

## 14. Fuentes de datos

Menú **Fuentes de datos**, con seis pestañas.

### Fuentes

- **Archivos (Excel/CSV)**: cargas periódicas con el asistente de importación.
- **API REST**: su sistema puede enviar ventas automáticamente. Cree una fuente *API REST* y luego un
  **token**; se muestra **una sola vez**, guárdelo en un lugar seguro.
- **Base de datos SQL (solo lectura)**: PostgreSQL, MySQL/MariaDB y SQL Server. Un asistente de tres pasos
  (administradores):
  1. **Conexión**: servidor, puerto, base, cifrado, zona horaria y credenciales (se guardan cifradas y nunca se muestran
     de nuevo). *Probar conexión* abre una conexión de solo lectura y muestra la versión del servidor y las
     **advertencias** (por ejemplo, si la cuenta podría escribir). Use siempre una cuenta de solo lectura.
  2. **Consultas**: para cada tipo de dato (ventas, productos, inventario, compromisos...) escriba una consulta
     **SELECT** autorizada y diga qué columna corresponde a cada campo. Solo se acepta **una sentencia de solo
     lectura**; si no lo es, se rechaza con el motivo. Cada cambio crea una **versión nueva sin aprobar**: use la
     **vista previa** (no importa nada), y luego **apruebe** la versión para activarla.
  3. **Sincronización**: *incremental* (solo lo nuevo) o *completa*. Usa el mismo flujo de importación que un archivo:
     valida y no duplica. Si hay filas con error queda **por revisar** y un enlace lleva a la importación para
     corregir o rechazar.
- **Conectores**: los marcados **Implementado, sin probar** (MySQL/MariaDB y SQL Server) están programados pero no se han
  probado contra un servidor real: no se presentan como disponibles; pruebe la conexión y valide los resultados antes de
  confiar en ellos. PostgreSQL se probó con una base de pruebas, no con el sistema de un cliente. Los *Planificados* aún
  no existen.

También verá aquí el **historial de importaciones**.

### Cobertura

Indique **hasta qué fecha está completa** cada fuente. Dentro de esa cobertura, un día sin ventas es un **cero real**;
fuera de ella, o si no la declara, es un dato **ausente** y no se convierte en cero. La tabla muestra la cobertura efectiva
(declarada por usted o el mínimo entre las fuentes) y cómo se determinó.

### Calidad

Reporte ampliado: cobertura por fuente, días sin ventas (**ceros observados** frente a **ausentes**), proporción de ventas
sin comprador identificable, posibles cambios de código de producto, cambios de precio y unidades incompatibles. No mide
la precisión de ningún pronóstico.

### Calendario

Feriados, temporadas y campañas con su **alcance** (país, región, empresa, sucursal, categoría, producto), estado y
**desde cuándo se conocían**. Un evento sin esa fecha se marca como *conocimiento desconocido* y no se usa como conocido
de antemano al evaluar el pasado. Cancelar un evento conserva el historial. El calendario público es de solo lectura.

### Compromisos

Cotizaciones, **pedidos confirmados** y contratos. **No son ventas realizadas** y no se suman a la demanda. Cada uno muestra su
estado, lo pendiente por entregar y sus revisiones; quien puede importar datos puede cancelarlo (con confirmación; queda
una revisión nueva).

### Almacenes y unidades

Un almacén por sucursal con su capacidad física (vacía = desconocida) y las **conversiones de unidades** declaradas. Sin
una conversión declarada, una unidad distinta del producto se rechaza: nunca se asume un factor.

---

## 15. Alertas

Avisos sobre riesgo de quiebre de stock, deterioro de la precisión de los pronósticos, calidad de datos e
importaciones o pronósticos fallidos. Puede marcarlas como vistas o resueltas. El contador de la campana muestra
las alertas abiertas.

---

## 16. Configuración

| Pestaña | Contenido |
|---|---|
| Empresa | Nombre, razón social, RUC, sector, zona horaria. |
| Parámetros | Nivel de servicio, período de revisión, plazo de entrega por defecto, umbral de alerta de quiebre, actualización automática del pronóstico (diaria, semanal o manual) y horizonte por defecto. |
| Módulos y configuración | Para cada módulo (demanda, comportamiento de compra, abastecimiento, costos, escenarios): cada parámetro con su valor y su **origen** (core, perfil sectorial o su empresa). Los administradores pueden definir valores propios; el motor valida y los errores aparecen junto al parámetro. Cambiarlos no altera los resultados ya calculados. Sin perfil sectorial, se usan solo los valores del core y los suyos. |
| Sucursales | Alta, edición y baja de sucursales. |
| Usuarios | Invitar personas y asignar roles. |
| Privacidad y datos | Consentimiento de entrenamiento compartido, exportación completa de datos y solicitud de eliminación de la empresa. |
| Plan y consumo | Plan actual, límites y consumo del mes. |
| Auditoría | Registro de accesos y acciones importantes. |

**Roles:**

| Rol | Puede |
|---|---|
| Propietario | Todo, incluidos consentimientos, exportación completa y eliminación de la empresa. |
| Administrador | Usuarios, sucursales, fuentes de datos (incluidas las consultas SQL) y configuración. |
| Analista comercial | Importar datos, generar pronósticos y planes, entrenar y volver a versiones anteriores de modelos, simular escenarios, administrar el catálogo y exportar reportes. |
| Usuario de consulta | Solo ver dashboards, pronósticos y alertas. |

**Privacidad.** Sus datos están aislados de los de otras empresas. Por defecto solo se usan para sus propios
pronósticos. El *entrenamiento compartido* entre empresas es voluntario, requiere autorización expresa del
propietario y puede revocarse. Predictily aplica la Ley Orgánica de Protección de Datos Personales del Ecuador:
puede exportar todos sus datos y solicitar su eliminación (con un período de gracia de 30 días).

---

## 17. Asistente conversacional (opcional)

Si su empresa lo tiene activo, el menú muestra **Asistente**. Es **opcional**: si no está disponible, no aparece y el
resto de Predictily funciona igual.

- El asistente **solo explica** resultados que Predictily ya calculó. **No calcula, no estima ni decide por usted.**
- Las **cifras** se muestran en tarjetas de resultado, tal como las devolvió cada consulta, con su calidad, fecha de corte,
  horizonte, limitaciones y supuestos. El texto es solo una explicación; si cita una cifra que no está en las tarjetas, se
  descarta y se le avisa.
- Si su pregunta no se puede responder con resultados ya calculados, o su rol no tiene permiso para esa consulta, recibe un
  mensaje fijo, sin cifras inventadas.
- Hay un límite de preguntas por minuto y por día; si se alcanza, se le indica.

---

## 18. Preguntas frecuentes

**¿Cuánta historia necesito?** Con pocas semanas Predictily usa métodos simples (promedios, estacionales). Con
12 meses o más puede capturar temporadas como décimos, regreso a clases o Navidad.

**¿Por qué un producto tiene un rango muy amplio?** Porque su venta es irregular o esporádica. El rango refleja
la incertidumbre real; úselo para decidir el stock de seguridad.

**¿Cada factura nueva reentrena el modelo?** No. Los modelos se actualizan según la programación elegida
(diaria o semanal) o cuando usted lo solicita.

**¿Puedo confiar en el pronóstico?** Revise el **Historial de precisión**: allí ve el error medido con sus
propios datos y la comparación con el método de referencia.

**¿Qué significa «sin respaldo suficiente»?** Que para ese horizonte la historia es menor al doble del horizonte, o las
pruebas no incluyeron ventanas tan largas. La cifra se muestra como provisional, sin rangos, y conviene no usarla como
un pronóstico completo. Elija un horizonte menor o cargue más historia.

**¿Por qué no veo la línea de la mediana?** Porque en las pruebas no mejoró a usar el pronóstico central como mediana. No
se publica ni se sustituye por otro valor.

**¿Por qué una predicción de compra dice «Sin predicción»?** Porque no hay datos suficientes para ese comprador o producto
(poca historia, consumidor final, horizonte sin modelo). Predictily prefiere no mostrar nada antes que inventar un número.

**¿Los montos de Compradores son ventas?** No. Son estimaciones con un supuesto de precio (último precio neto). Una venta
solo existe cuando se factura.

**¿Un plan «factible con brecha» es el mejor posible?** No está probado. Solo el estado «Óptimo probado» garantiza que no
existe un plan mejor dentro de la tolerancia declarada.
