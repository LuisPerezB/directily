# Validación de Predictily frente a «Revisar Predictily Etapa 1»

Fecha: 28 de octubre de 2026. Proyecto examinado: `C:\Users\Luis.perez\Documents\prueba expalsa\backend\directily`. Revisión HEAD identificada: `ebd0d5a361d1ee8fba093efb88e08727511c5db3`. Se revisaron los archivos presentes en esa carpeta; no se certifica que el árbol local coincida íntegramente con HEAD.

**Dictamen: la base de pronóstico por producto y sucursal existe y funciona con datos sintéticos. La solución todavía requiere correcciones de abastecimiento y evaluación temporal antes de usar sus recomendaciones para decisiones reales. El caso completo de recompra de cubetas, respuesta al precio y planificación de alimento aún necesita desarrollo.**

La revisión del chat era esencialmente documental. Esta validación añade lectura del código, ejecución de las 34 pruebas unitarias originales del motor, cinco reproducciones de problemas y una ejecución completa de la demo en memoria. No se cambió la lógica del producto.

## 1. Lo que está implementado

| Capacidad planteada en el chat | Evidencia encontrada | Conclusión |
|---|---|---|
| Importación de ventas y datos comerciales | Normalización, validación, ensamblado de documentos, clasificación nuevo/actualizado/sin cambios, CSV/XLSX y REST en backend | Implementación presente; no se ejecutó aquí la integración Laravel/PostgreSQL |
| Aislamiento por empresa | Scope de modelos que falla sin empresa y consultas parametrizadas del motor con `tenant_id` | Controles presentes; no se certifica su seguridad mediante esta revisión parcial |
| Pronóstico diario por producto y sucursal | `daily_sales` agrega por sucursal/producto/fecha; `LightGBMGlobal` utiliza `LGBMRegressor` y `MLForecast` | Implementado y ejecutado |
| Métodos de referencia | Naive, Seasonal Naive, WindowAverage, AutoETS, Croston-SBA, ADIDA y TSB | Implementados y ejecutados |
| Selección de modelos | Selección por clase, mejora mínima relativa del 3 % y estabilidad entre ventanas para LightGBM | Implementada; la evaluación final requiere las correcciones descritas abajo |
| Incertidumbre y seguimiento | Cuantiles de residuos, intervalos por niveles y horizontes, evaluación contra ventas posteriores y alertas de deterioro | Implementado; validación de cobertura con limitaciones temporales |
| Abastecimiento | Punto de reorden, stock de seguridad, compra sugerida, riesgo y costo estimado | Implementado con defectos reproducidos |
| Recompra individual | Hay clientes y segmentos en el esquema, pero no se conservan como dimensión de la entrada del motor | No existe el modelo que estima quién comprará en siete días y cuánto |
| Efecto causal del precio | El simulador multiplica por `demand_change_pct`, introducido por el usuario | No estima elasticidad ni adaptación posterior a un aumento de precio |
| Variables externas | Funciones de consulta por fecha de publicación, desactivadas en el conjunto de características | Infraestructura preparatoria; no hay alimentación activa de clima/maíz/INEC/BCE en este motor |
| Optimización de compra y producción | El presupuesto se compara con una necesidad de compra; no asigna recursos mediante un optimizador | No existe planificación conjunta de alimento, capacidad, almacenamiento y margen |
| Soyoda | Conector genérico ODBC marcado `planned` | No se validó una conexión ni un histórico real de Soyoda |

Referencias de implementación: [modelo ML](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/models/ml.py:59>), [agregación de ventas](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/db/repository.py:112>), [simulador](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/backend/app/Services/Scenarios/ScenarioSimulator.php:16>), [indicadores externos](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/features/external.py:1>), [conector ODBC](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/backend/app/Services/Connectors/Connectors/GenericOdbcConnector.php:24>).

Hay una restricción relevante para el ejemplo de un negocio pequeño: LightGBM exige al menos cinco series y 1.500 filas de entrenamiento para participar en el backtesting. Una sola combinación producto/sucursal no usa ese modelo; puede utilizar los estadísticos. Tener tres años de compras de un cliente no activa automáticamente una predicción individual.

## 2. Hallazgos que requieren corrección

### F1 · P1 · Los pedidos futuros ocultan faltantes antes de su llegada

**Reproducido.** Con 10 unidades disponibles y demanda de 10 por día, añadir una orden de 1.000 unidades que llegará dentro de 30 días hace que el motor declare 1.010 unidades disponibles, riesgo bajo, compra sugerida cero y ninguna fecha de agotamiento. Sin esa orden detecta faltante el segundo día.

La consulta obtiene `expected_at`, pero la recomendación suma todas las compras abiertas y utiliza ese total como disponibilidad inmediata, incluso para estimar cuándo se agota el producto. Esto puede ocultar semanas sin suministro.

Corrección: separar existencias físicas, posición de inventario y recepciones por fecha. Simular el saldo diario con las llegadas previstas y su incertidumbre; preservar la alerta cuando el stock se agota antes de una recepción.

Código: [recommendations.py:124](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/inventory/recommendations.py:124>) y [repository.py:214](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/db/repository.py:214>).

### F2 · P1 · El horizonte elegido puede reducir indebidamente la compra

**Reproducido.** Demanda constante de 10 unidades/día, proveedor de 30 días, revisión cada 7 días y stock cero: al recibir un pronóstico de 7 días, el motor calcula una demanda de abastecimiento de 70 unidades y sugiere 84. Con un pronóstico de 90 días calcula los 37 días necesarios, 370 unidades de demanda, y sugiere 402 con los mismos intervalos diarios.

La causa es `min(lt_days + review, len(fc))`. La necesidad real de cobertura no se reduce porque la pantalla o el plan permitan un horizonte menor. El campo `horizon_days_covered` registra el recorte, pero no impide publicar una recomendación insuficiente.

Corrección: generar internamente un horizonte que cubra el plazo más la revisión, o declarar que no hay base suficiente para calcular esa recomendación. No extrapolar silenciosamente la cantidad.

Código: [recommendations.py:139](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/inventory/recommendations.py:139>).

### F3 · P1 · Ausencia de incertidumbre se convierte en certeza

**Reproducido.** Con errores históricos vacíos e intervalos `NaN`, el motor sustituye la desviación desconocida por cero. Devuelve stock de seguridad cero y probabilidad de quiebre exactamente 0 o 1, según las existencias. La falta de datos termina presentada como una estimación categórica.

La regla de Laplace que se menciona en la documentación solo protege la rama empírica. No protege la aproximación desde intervalos, que es la utilizada cuando falta evidencia.

Corrección: distinguir incertidumbre desconocida de variabilidad verdaderamente nula. Conservar valores nulos, emitir una limitación visible y aplicar una política conservadora explícita si el negocio necesita una sugerencia provisional.

Código: [recommendations.py:154](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/inventory/recommendations.py:154>).

### F4 · P2 · El perfil de las series usa el futuro del backtest

**Reproducido.** Al corte del 1 de abril una serie tenía solo dos días con ventas y debía clasificarse como insuficiente. Al añadir ventas de mayo, junio y julio, el perfil completo la clasifica como intermitente y `eligible_ids` la incluye para entrenar en aquel corte histórico. La elegibilidad pasada cambia por compras que todavía no habían sucedido.

El panel se perfila al corte final y se reutiliza para todas las ventanas. Las pruebas anti-fuga existentes modifican valores posteriores, pero conservan el mismo `panel.profile`; por eso no detectan este caso.

Corrección: recalcular elegibilidad, actividad y clasificación con información disponible en cada corte. Añadir una prueba que reconstruya el perfil tras perturbar el futuro, además de las pruebas actuales de características.

Código: [rolling.py:67](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/backtest/rolling.py:67>) y [series.py:122](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/profiling/series.py:122>).

### F5 · P2 · No se verifica cuándo se conocía una promoción

**Reproducido en la construcción de variables.** Una promoción registrada el 4 de abril se utiliza en el pronóstico con corte 1 de abril. El constructor acepta sus fechas de vigencia y no considera la fecha en la que el negocio la conoció. La lectura de base de datos tampoco recupera esa fecha ni versiones anteriores.

Corrección: incorporar una fecha de disponibilidad y, para promociones modificadas, versiones históricas. Una promoción futura sí puede ser válida cuando ya estaba planificada y registrada al corte; no basta su fecha de inicio para demostrarlo.

Código: [builder.py:126](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/features/builder.py:126>) y [repository.py:227](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/db/repository.py:227>).

### F6 · P2 · Las métricas de selección no son una prueba final independiente

**Verificado por lectura del flujo.** Los mismos resultados `bt` eligen al ganador y alimentan sus métricas reportadas. La cobertura excluye una ventana, pero conserva las demás, incluso ventanas posteriores y fechas objetivo compartidas. En la configuración de 90 días con paso 28, dos ventanas consecutivas comparten 62 fechas objetivo.

La superposición puede servir para comparar pronósticos desde distintos orígenes. No constituye por sí misma una evaluación prospectiva independiente. Tampoco queda aislado de la ventana evaluada el proceso que eligió al modelo ganador.

Corrección: reservar un período final posterior que no intervenga en selección ni calibración; calibrar con residuos ya observables al origen que se evalúa. Para evaluar todo el procedimiento mediante múltiples cortes, repetir también la selección dentro de cada corte externo. No se ha medido cuánto cambian las métricas actuales al hacer esta corrección.

Código: [core.py:143](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/pipeline/core.py:143>), [core.py:202](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/pipeline/core.py:202>), [conformal.py:109](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/engine/predictily_engine/intervals/conformal.py:109>). La separación entre selección y prueba final coincide con la [documentación de evaluación de scikit-learn](https://scikit-learn.org/stable/modules/cross_validation.html).

### F7 · P2 · El programador pide un horizonte prohibido en el plan Básico

**Verificado estáticamente; no ejecutado con Laravel.** La tarea programada solicita siempre 90 días. El plan Básico permite 7 y 30, y el servicio valida esa restricción. Por tanto, una empresa Básico que deba actualizarse recibirá el rechazo del plan y no generará el pronóstico programado.

Corrección: escoger el mayor horizonte permitido para esa empresa. Añadir cobertura del programador para el plan Básico.

Código: [ScheduleForecastsCommand.php:44](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/backend/app/Console/Commands/Analytics/ScheduleForecastsCommand.php:44>), [configuración de planes:38](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/backend/config/predictily.php:38>), [validación del horizonte:122](<C:/Users/Luis.perez/Documents/prueba expalsa/backend/directily/backend/app/Services/Forecasting/ForecastRunService.php:122>).

## 3. Comprobaciones ejecutadas y límites

Se creó un entorno Python 3.12 separado y se instalaron las dependencias fijadas en `engine/pyproject.toml`. Los scripts de validación importan el código de la carpeta indicada por el usuario.

| Comprobación ejecutada | Resultado |
|---|---|
| Suite original `engine/tests/unit` | **34 aprobadas en 19,98 s** |
| Reproducciones F1–F5 | **Los cinco comportamientos se confirmaron** con entradas pequeñas y controladas |
| Demo completa Ferretería Andina, semilla 42, sin base de datos | 99.334 filas de ventas sintéticas, 183 series pronosticadas, 90 días |
| WAPE diario por serie, modelo seleccionado / Seasonal Naive | **0,487725 / 0,645117** |
| WAPE del total diario, seleccionado / Seasonal Naive | **0,105502 / 0,113171** |
| Sesgo diario por serie | **−7,20 %** |
| Cobertura reportada por el procedimiento actual, intervalos 80 % / 95 % | **79,80 % / 94,90 %** |
| Modelos elegidos en esta demo | LightGBM en 94 series; AutoETS en 89 |
| Tiempo del pipeline en memoria / generación y pipeline | 19,7 s / 24,95 s; no comparable directamente con HTTP + BD |

Estos resultados reproducen aproximadamente los valores principales documentados. Son resultados sobre datos ficticios y bajo el procedimiento actual, con las limitaciones F4–F6. WAPE 0,488 no debe anunciarse como «51,2 % de precisión» ni la cobertura como probabilidad de compra de un cliente.

No se ejecutaron las nueve pruebas de integración PostgreSQL, las 155 pruebas backend, las 68 frontend ni las 32 verificaciones E2E citadas en el chat. En esta instalación no estaban disponibles PHP/Composer ni las dependencias backend/frontend; Docker no tenía el servicio iniciado. Tampoco se conectó Soyoda. Esos conteos permanecen como resultados documentados de otra ejecución.

Evidencia archivada dentro del repositorio: [resultado de pruebas](docs/validation/2026-10-28/unit-results.xml), [reproducciones](docs/validation/2026-10-28/probe-results.json), [demo ejecutada](docs/validation/2026-10-28/benchmark-results.json). Los resultados originales se conservaron sin cambios; [el README de evidencia](docs/validation/2026-10-28/README.md) explica cómo repetir las comprobaciones. Para preparar y ejecutar el proyecto, consultar [EJECUCION-PREDICTILY.md](EJECUCION-PREDICTILY.md).

## 4. Qué implica para el ejemplo de las cubetas

Predictily puede ejecutar un pronóstico de unidades por producto y sucursal. No puede atribuir ese volumen a un cliente ni entregar todavía una probabilidad calibrada de recompra para los próximos siete días. El motor agrega las ventas antes del modelado y pierde esa dimensión.

Para completar el caso se necesitan ejemplos de entrenamiento por cliente/producto/fecha de corte, dos objetivos —alguna compra en siete días y cantidad total durante esos siete días condicionada a comprar— y variables de recencia, intervalos, cantidades, precio, promociones y disponibilidad. Los códigos genéricos de consumidor final no deben tratarse como una persona recurrente.

Como primera comparación mantendría la propuesta del chat: frecuencia histórica o regresión logística frente a un clasificador LightGBM, y cantidad histórica frente a un regresor LightGBM. Son candidatos por validar, no un requisito de precisión. La calibración debe usar observaciones separadas del ajuste y respetar el orden temporal, como explica la [documentación de calibración](https://scikit-learn.org/stable/modules/calibration.html). Supervivencia puede evaluarse después si se requiere una ventana más detallada hasta la próxima compra.

Para demanda agregada conviene conservar el conjunto actual y dejar competir a sus modelos. La ejecución confirmó que AutoETS gana en parte de las series. MLForecast organiza variables y pronósticos para los estimadores; no sustituye los objetivos específicos del modelo de recompra. Véase la [documentación de MLForecast](https://nixtlaverse.nixtla.io/mlforecast/index.html).

La respuesta a cambios de precio, los costos del alimento y la capacidad de producción siguen siendo problemas adicionales. Antes de escoger un estimador causal o un optimizador deben existir datos de variaciones de precio, disponibilidad, proveedores, recepciones, consumo de alimento, capacidad y restricciones. Los valores 7/10, 8,75/10 y 6/10 del ejemplo continúan siendo supuestos, no probabilidades estimadas por este repositorio.

## 5. Orden de trabajo recomendado

1. Corregir F1–F3 para que las recomendaciones respeten fechas, cobertura e incertidumbre; corregir F7 para la ejecución programada del plan Básico.
2. Corregir F4–F6 y añadir pruebas de regresión que cubran estos casos. Recalcular las métricas de referencia.
3. Incorporar un histórico real autorizado, como Soyoda, validar unidades, descuentos, devoluciones, anulaciones y cobertura del inventario, y reservar el período final de evaluación. Los días sin ventas requieren distinguir cero real, cierre y ausencia de carga.
4. Medir error, sesgo y cobertura por producto/sucursal/horizonte, además de faltantes, sobrestock y capital en una simulación histórica de operación.
5. Añadir recompra y cantidad por cliente; después conectar costos, suministro y restricciones de producción, y evaluar respuesta al precio cuando exista evidencia suficiente.

**Estado final de esta revisión: base técnica comprobada en memoria con datos sintéticos; exactitud comercial y operación integral pendientes de validación.**
