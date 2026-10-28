# Predictily — Instrucciones y prompt complementario de la etapa 2

Fecha: 28 de octubre de 2026.

**Objetivo: complementar la etapa 1 y construir correctamente el core común de Predictily. La personalización por rubro y por empresa usuaria se desarrolla encima de ese core, con contratos y configuración, sin duplicarlo.**

Este archivo registra lo revisado en el chat y en el proyecto, las correcciones pendientes y un prompt de implementación reutilizable. Su creación no significa que las correcciones o la etapa 2 ya estén implementadas.

Para preparar el entorno, arrancar la aplicación y seguir el orden de implementación, comenzar por [EJECUCION-PREDICTILY.md](EJECUCION-PREDICTILY.md). Las evidencias y scripts se conservan dentro del repositorio en [docs/validation/2026-10-28](docs/validation/2026-10-28/README.md).

## 1. Procedencia y alcance

- Conversación de referencia: [Revisar Predictily Etapa 1](chatgpt-conversation://6ae15280-a140-83e9-8708-7ad8b560c980).
- Proyecto examinado: `C:\Users\Luis.perez\Documents\prueba expalsa\backend\directily`.
- HEAD identificado durante la revisión: `ebd0d5a361d1ee8fba093efb88e08727511c5db3`. Se revisó el contenido local; no se certificó coincidencia completa con HEAD.
- Informe de evidencia: [VALIDACION-PREDICTILY-2026-10-28.md](VALIDACION-PREDICTILY-2026-10-28.md).
- Evidencia ejecutable: [pruebas del motor](docs/validation/2026-10-28/unit-results.xml), [casos reproducidos](docs/validation/2026-10-28/probe-results.json) y [demo ejecutada](docs/validation/2026-10-28/benchmark-results.json).

**Origen del prompt:** al revisar la continuidad de las conversaciones se encontró el «PROMPT MAESTRO V2» en [Modelo predictivo de ventas](chatgpt-conversation://6ae0c449-1648-83e9-b553-248a6fe5e7c3), como respuesta al pedido de un segundo prompt considerando que el primero ya se había ejecutado. Se conserva un extracto literal en [PROMPT-ETAPA-2-ORIGINAL-RECUPERADO.md](PROMPT-ETAPA-2-ORIGINAL-RECUPERADO.md): título y secciones 1–10. La lectura disponible truncó el mensaje en su sección 11; no se presenta el anexo como transcripción completa ni se reconstruye el fragmento faltante.

La sección 6 de este documento es el **prompt complementario vigente**, redactado a partir del original recuperado, la revisión de etapa 1, la validación del código y la prioridad actual del usuario. No es una cita literal. La instrucción actual de separar la personalización por rubro o empresa reemplaza el requisito histórico de construir módulos sectoriales durante esta misma entrega.

**Nomenclatura:** aquí «etapa 1» significa el proyecto ya construido y «etapa 2» su ampliación actual. El backlog existente utiliza también «Fase 1», «Fase 2», «Fase 3» y «Fase 4» para subdividir el MVP y su evolución. No son equivalentes a las etapas de esta conversación: no hay que volver a construir el primer motor descrito como Fase 2 del backlog.

**Vocabulario:** «empresa usuaria» o `tenant` es quien utiliza Predictily; «comprador» es el cliente comercial de esa empresa. Personalizar para una empresa y pronosticar la recompra de un comprador son responsabilidades distintas.

## 2. Conclusiones del chat que deben conservarse

Predictily es una capa de inteligencia predictiva independiente del ERP/POS. Integra cuatro cores: **Datos, Demanda, Comportamiento de compra y Abastecimiento**. El pronóstico de costos, la evaluación de escenarios y la optimización son capacidades compartidas que conectan esos cores.

Las estimaciones deben proceder de estadística, machine learning y métodos de decisión verificables. Un LLM es opcional para consultar o explicar resultados calculados; no es necesario para construir el core ni debe inventar cantidades, probabilidades o efectos causales.

El ejemplo de las cubetas expresa necesidades generales:

| Pregunta del negocio | Capacidad común requerida |
|---|---|
| ¿Un comprador adquirirá un producto en los próximos siete días? | Probabilidad de compra con evento y horizonte definidos |
| ¿Cuánto comprará durante ese período si compra? | Cantidad total condicionada a la compra |
| ¿Cuándo podría comprar? | Distribución temporal o ventana estimada, cuando exista evidencia |
| ¿Cómo cambia su comportamiento al cambiar el precio? | Variables históricas de precio; estimación causal solo cuando pueda justificarse |
| ¿Cómo podrían cambiar los costos y el suministro? | Pronóstico de costos y escenarios de disponibilidad con fuentes fechadas |
| ¿Cuánto comprar, reservar o producir? | Planificación con recepciones, restricciones, incertidumbre y costos |
| ¿Qué estrategia conviene? | Comparación coherente de demanda, oferta disponible, ventas atendibles y margen |

Comprar 6 unidades cada 12 días y comprar 4 cada 8 días representan aproximadamente el mismo volumen de 15 unidades en 30 días. El core debe distinguir frecuencia, tamaño de compra y volumen por período. No debe asumir que una reducción del tamaño de la transacción reduce el consumo mensual.

El ejemplo `P(compra en 7 días)=0,70` y `E(cantidad total en 7 días | compra)=6` produce una esperanza de `4,2` unidades para ese mismo horizonte. Es una identidad ilustrativa; no describe un resultado real de Predictily. No se obtiene un intervalo válido multiplicando de la misma forma límites de intervalos independientes.

Las puntuaciones 7/10, 8,75/10 y 6/10 mencionadas en el ejemplo no son probabilidades estimadas. Cada probabilidad requiere un evento, una ventana temporal y evaluación de su calibración. Un aumento de precio y dos meses de adaptación no bastan para demostrar una relación causal general.

Las reglas biológicas de producción de huevos y consumo de alimento pertenecen a una extensión del negocio. La representación de recursos, capacidad, costos, plazos, restricciones y resultados de planificación pertenece al core común.

### 2.1 Continuidad con el segundo prompt original

| Requisito del prompt V2 recuperado | Tratamiento en esta continuación |
|---|---|
| Auditar y continuar el repositorio, sin reconstruirlo | Se conserva; partir de la validación y verificar los cambios posteriores |
| Cuatro cores: Data, Demand, Customer y Supply | Se conservan como Datos, Demanda, Comportamiento de compra y Abastecimiento |
| Demanda para 7/14/30/60/90 días y agregaciones de negocio | Contrato configurable; evaluación por horizonte, permisos del plan y datos suficientes |
| Compra a 7/30/90 días, cantidad, monto, recurrencia, productos probables e inactividad | Se conservan dentro de Customer Core, con objetivos explícitos y entregas incrementales |
| Conectores y modelo canónico independientes del ERP | Se conserva el contrato; cada fuente implementa un adaptador comprobado, sin fingir conectores operativos |
| Inventario, proveedores y optimización restringida | Se conserva; primero corregir fechas, horizonte e incertidumbre |
| Calendarios, registro de modelos y separación entrenamiento/predicción/conversación | Se conservan como responsabilidades comunes |
| Retail, exportadores, industria y agro como entregables sectoriales | Se separan del alcance principal por la instrucción actual del usuario; probar contratos con perfiles sintéticos no equivale a entregar esos módulos |
| Chronos, AutoGluon, TimeGPT y alternativas experimentales | Candidatos opcionales que deben demostrar valor; no condicionan la entrega del core |
| LLM conversacional | Capa opcional que consulta resultados; no es requisito para calcular ni validar el core |

El anexo es referencia histórica. Si un punto del anexo contradice esta separación actual o los requisitos de evaluación temporal, prevalece el prompt consolidado de la sección 6. Las capacidades del core conservadas no se consideran terminadas por tener solamente tablas o interfaces.

## 3. Estado de la etapa 1 comprobado

La arquitectura existente es Laravel/PostgreSQL para la API, los datos y las migraciones; Redis y jobs para orquestación; Python/FastAPI para cómputo; Vue/TypeScript para la interfaz. Se conserva esa arquitectura modular.

| Componente | Estado observado |
|---|---|
| Importaciones CSV/XLSX, REST, normalización e idempotencia | Código presente; integración completa no ejecutada en esta revisión |
| Aislamiento por empresa | Controles en backend y repositorio del motor; no equivale a una certificación completa de seguridad |
| Demanda producto × sucursal | Implementada y ejecutada en memoria |
| LightGBM + MLForecast | Implementados, con modelo global dentro de cada empresa |
| Modelos estadísticos | Naive, Seasonal Naive, promedio móvil, AutoETS, Croston-SBA, ADIDA y TSB |
| Selección contra referencias | Implementada; exige mejora y estabilidad para promover LightGBM |
| Backtesting, intervalos, agregaciones y seguimiento | Implementados, con limitaciones temporales descritas en los hallazgos |
| Inventario y recomendaciones | Implementados; requieren correcciones antes de fundamentar decisiones reales |
| Recompra individual | No implementada; el motor agrega por producto/sucursal/fecha y pierde la dimensión comprador |
| Elasticidad causal | No implementada; el simulador usa un cambio de demanda supuesto por el usuario |
| Indicadores externos | Infraestructura de consulta por fecha de publicación, sin integración activa en el motor |
| Optimización restringida de compra y producción | Pendiente; comparar el presupuesto con la compra sugerida no equivale a optimizar |
| Integración Soyoda | No validada; conector ODBC genérico marcado como planificado |

LightGBM requiere en el código actual al menos cinco series y 1.500 filas para participar en el backtesting. Esa condición pertenece al motor agregado actual; no es una regla que deba copiarse sin evaluación al nuevo modelo de recompra.

### 3.1 Evidencia ejecutada el 28 de octubre de 2026

| Comprobación | Resultado |
|---|---|
| Pruebas unitarias originales del motor | **34 aprobadas**, 19,98 segundos |
| Reproducciones adicionales | Cinco problemas confirmados con entradas controladas |
| Demo Ferretería Andina | 99.334 líneas sintéticas, 183 series pronosticadas, horizonte 90 días |
| WAPE diario por serie | Seleccionado **0,487725**; Seasonal Naive **0,645117** |
| WAPE del total diario | Seleccionado **0,105502**; referencia **0,113171** |
| Sesgo diario por serie | **−7,20 %** |
| Cobertura reportada por el procedimiento actual | 80 % nominal: **79,80 %**; 95 % nominal: **94,90 %** |
| Ganadores en esa demo | LightGBM: 94 series; AutoETS: 89 |
| Duración | Pipeline en memoria: 19,7 s; generación más pipeline: 24,95 s |

Los números reproducen aproximadamente la documentación, pero proceden de datos ficticios y del procedimiento actual. No certifican precisión comercial. No transformar `1 − WAPE` en «porcentaje de precisión». La cobertura de un intervalo no equivale a la probabilidad de compra de un comprador.

Los conteos documentados de 155 pruebas backend, 68 frontend, nueve pruebas de integración del motor y 32 verificaciones E2E **no fueron ejecutados en esta revisión**. Tampoco se conectó Soyoda. Deben volver a verificarse cuando esté disponible el entorno correspondiente.

## 4. Correcciones obligatorias de partida

Los identificadores F1–F7 siguientes son los del informe de validación, no los del backlog previo.

| ID | Prioridad | Hallazgo y evidencia | Resultado exigido |
|---|---|---|---|
| F1 | P1 | Un pedido de 1.000 unidades que llega en 30 días oculta el faltante de un stock actual de 10 con consumo de 10/día; el motor declara 1.010 disponibles | Separar stock físico, reservas, posición de inventario y recepciones fechadas; conservar el faltante antes de la llegada |
| F2 | P1 | Proveedor de 30 días + revisión de 7, demanda 10/día: horizonte de 7 días calcula 70 de demanda y sugiere 84; horizonte suficiente calcula 370 y sugiere 402 | Cubrir el plazo completo de 37 días o declarar insuficiencia; no truncar silenciosamente |
| F3 | P1 | Sin errores históricos ni intervalos se convierte incertidumbre desconocida en desviación cero y riesgo 0/1 | Incertidumbre desconocida permanece desconocida; no emitir seguridad estadística inexistente |
| F4 | P2 | Ventas de mayo–julio cambian la elegibilidad de una serie evaluada al 1 de abril | Reconstruir perfil, elegibilidad y características dentro de cada corte temporal |
| F5 | P2 | Una promoción registrada el 4 de abril entra en un pronóstico con corte 1 de abril | Filtrar por conocimiento disponible al corte y conservar versiones históricas |
| F6 | P2 | Selección y métricas usan el mismo backtest; la calibración puede usar ventanas posteriores y fechas objetivo compartidas | Separar selección, calibración y prueba final temporal; evaluar también el procedimiento completo |
| F7 | P2 | El programador pide siempre 90 días, pero el plan Básico permite 7/30 | Elegir el mayor horizonte permitido y verificar el flujo del plan Básico |

F1–F5 fueron reproducidos. F6 se verificó leyendo el flujo de selección/calibración. F7 se verificó estáticamente, sin ejecutar Laravel.

Puntos de entrada del código, relativos al repositorio:

- F1–F3: `engine/predictily_engine/inventory/recommendations.py`; F1 también `db/repository.py`.
- F4: `engine/predictily_engine/backtest/rolling.py` y `profiling/series.py`.
- F5: `engine/predictily_engine/features/builder.py` y `db/repository.py`.
- F6: `engine/predictily_engine/pipeline/core.py`, `selection/selector.py`, `intervals/conformal.py`.
- F7: `backend/app/Console/Commands/Analytics/ScheduleForecastsCommand.php`, `Services/Forecasting/ForecastRunService.php` y `backend/config/predictily.php`.

Las compras 84/402 son resultados de la reproducción actual, no números que deba fijar el algoritmo corregido. El requisito es conservar el horizonte físico de cobertura y calcular correctamente el riesgo y las restricciones.

## 5. Frontera entre core y personalización

| Core común, necesario en etapa 2 | Extensión por rubro o empresa, separada |
|---|---|
| Identidades, unidades, monedas, fechas, calidad, trazabilidad y contratos de integración | Mapeos del ERP, códigos del cliente y formatos particulares |
| Pronóstico, variables temporales, validación, incertidumbre, selección y modelos de respaldo | Variables o modelos adicionales que demuestren aportar valor en ese contexto |
| Compra, cantidad y recurrencia de compradores | Segmentos comerciales y políticas de atención de cada empresa |
| Costos, entradas de escenarios y distinción entre supuestos y estimaciones | Fuentes de clima o materias primas relevantes para un rubro concreto |
| Balance de inventario, recepciones, recursos, capacidad, presupuesto y restricciones | Reglas de consumo de alimento, producción avícola, tratamientos agrícolas u otros procesos específicos |
| Contratos de planificación y comparación de escenarios | Objetivos, límites y preferencias acordadas con una empresa |
| Autorización, configuración versionada, aislamiento y auditoría | Parámetros permitidos dentro del ámbito de esa empresa |

Entrenar con los datos propios de una empresa ya forma parte del funcionamiento normal del core. No requiere crear una rama distinta del producto ni un algoritmo independiente por comprador. Las extensiones deben conectarse mediante interfaces y configuración validada; nunca mediante condiciones dispersas del tipo `si empresa == X`.

## 6. Prompt complementario de implementación — Etapa 2 del core

**Inicio del prompt. Se puede entregar esta sección completa a un agente de implementación.**

### Encargo y continuidad

Actúa como responsable técnico de la evolución de Predictily. Implementa la etapa 2 sobre el proyecto existente, conservando lo que funciona. El objetivo es completar y validar el **core común de Datos, Demanda, Comportamiento de compra y Abastecimiento**, con capacidades comunes de costos, escenarios y planificación. Las personalizaciones de rubro o de empresa usuaria quedan desacopladas mediante contratos de extensión.

No reconstruyas el proyecto desde cero. Inspecciona código, migraciones, pruebas e instrucciones del repositorio antes de editar. Contrasta el estado actual con este documento: una observación histórica no implica que un defecto siga presente si ya fue corregido después. Mantén Laravel como propietario del esquema y API pública, Python como servicio de cálculo, Redis/jobs para trabajo asíncrono y Vue como consumidor. Evita crear una infraestructura nueva sin necesidad demostrada.

Lee `docs/00-registro-avance.md`, `docs/01-requisitos.md`, `docs/02-arquitectura.md`, `docs/03-modelo-datos.md`, `docs/04-estrategia-modelado.md`, `docs/06-contratos-integracion.md`, `docs/08-backlog.md` y el informe de validación disponible. La etapa 1 ya contiene importaciones, autenticación, aislamiento, pronóstico agregado, modelos estadísticos y LightGBM, evaluaciones, inventario, simulador y UI. Esta etapa los complementa y corrige.

Antes de implementar, crear o actualizar `docs/architecture/PREDICTILY_V2_GAP_ANALYSIS.md` con el estado real, componentes reutilizables, brechas, dependencias y orden de ejecución. Consultar el prompt V2 histórico como antecedente; la separación actual de core y personalización indicada aquí gobierna el alcance.

La evidencia anterior fue: 34 pruebas unitarias aprobadas y demo sintética de 183 series, WAPE seleccionado 0,487725 frente a 0,645117 del baseline. No es una garantía comercial ni un objetivo que deba preservarse a costa de corregir la evaluación. Las suites backend/frontend/E2E y Soyoda no se validaron en esa revisión.

Entrega implementación, pruebas y documentación. Avanza por los bloques siguientes con dependencias explícitas. La ausencia de datos reales no impide construir el core y verificarlo con datos sintéticos identificados como tales; sí impide afirmar validación comercial. No sustituir funcionalidades requeridas por interfaces vacías.

### A. Reglas del core

1. Toda operación tiene empresa, fecha de corte, horizonte y versión. Los resultados indican si son pronóstico, simulación condicionada, estimación causal o plan optimizado.
2. No utilizar LLM para calcular probabilidades, cantidades o decisiones. Cualquier explicación consume resultados trazables.
3. Respetar unidades físicas, moneda y zona horaria. No sumar productos incompatibles como si tuvieran la misma unidad; los agregados comerciales deben indicar qué representan. No introducir conversiones silenciosas.
4. Separar demanda, ventas observadas, ventas atendibles, inventario, recepciones y producción posible. Las ventas pueden estar limitadas por suministro.
5. Diferenciar cero observado, negocio cerrado, dato ausente y observación censurada. Un dato ausente no se convierte automáticamente en cero.
6. Usar solo información disponible al corte para predecir. Cambiar observaciones posteriores no puede modificar la entrada o predicción de ese corte.
7. Prohibir acceso cruzado entre empresas en lecturas, features, artefactos, cachés, entrenamiento y resultados. No activar entrenamiento compartido entre empresas como parte implícita de esta etapa.
8. Mantener idempotencia, reintentos seguros, versiones y compatibilidad con el recorrido de etapa 1. Las recomendaciones no envían pedidos ni comprometen fondos.
9. Un valor desconocido conserva ese estado. Calidad de datos, cobertura de intervalos, probabilidad y nivel de servicio son conceptos distintos.

### B. Corregir primero la etapa 1

Reproducir y corregir, con regresiones automatizadas, estos siete puntos:

- **F1:** no sumar pedidos futuros al stock físico inmediato. Con 10 unidades, demanda 10/día y recepción de 1.000 en el día 30, debe detectarse el faltante anterior a esa recepción.
- **F2:** un proveedor de 30 días y revisión de 7 requieren cubrir 37 días. Con demanda constante 10/día, la demanda media de ese período es 370 aunque la vista solicitada sea de 7 días. Calcular internamente el horizonte necesario dentro de límites explícitos o devolver `insufficient_horizon`; no omitir días.
- **F3:** sin residuos ni intervalos, no inferir variabilidad cero, riesgo cero/uno o stock de seguridad estadísticamente validado. Devolver estado de incertidumbre insuficiente y, si se habilita, una política provisional claramente identificada.
- **F4:** reconstruir perfil y elegibilidad en cada corte del backtest. Dos días con ventas al corte no pasan a ser evidencia suficiente porque existan compras posteriores.
- **F5:** una promoción o revisión de precio conocida después del corte no puede aparecer en sus variables. Conservar el momento en que la fuente conocía esa versión.
- **F6:** separar selección, calibración y prueba final cronológica. Dejar una ventana fuera sin excluir información posterior o repetida no basta para evaluar prospectivamente todo el procedimiento.
- **F7:** el programador debe solicitar un horizonte permitido por el plan; comprobar específicamente el Básico 7/30 y su ejecución programada.

El horizonte interno de planificación y el horizonte comercial autorizado deben tener una política explícita. Una restricción del plan no puede provocar una recomendación físicamente incorrecta ni utilizarse para revelar un pronóstico comercial no autorizado.

### C. Core de Datos y contratos comunes

Extender los contratos existentes con lo mínimo necesario para los cuatro cores. Documentar campos obligatorios/opcionales, granularidad, unidades, zona horaria, identidad, cardinalidad, versiones y reglas de nulidad.

La representación común debe cubrir transacciones a nivel de línea, comprador identificable, producto/sucursal/canal, precios y descuentos históricos, costos, promociones, inventario/reservas/movimientos, pedidos y recepciones, plazos de proveedor, calendarios y observaciones externas. Recursos, capacidades y transformaciones productivas genéricas deben poder aportarse al planificador por contrato.

Conservar identidades externas y relaciones de almacenes, proveedores, categorías, unidades, devoluciones, cotizaciones, contratos y pedidos confirmados cuando existan. Diferenciar intención comercial, compromiso confirmado y venta realizada. Los calendarios admiten alcance por país, región, empresa, sucursal y producto, con procedencia y disponibilidad temporal; el catálogo sectorial concreto se aporta desde una extensión. Mantener como conectores comunes previstos PostgreSQL, SQL Server y MySQL/MariaDB de solo lectura, con mapeos autorizados y pruebas de contrato. Entregar al menos un recorrido SQL funcional de extracción, normalización y reimportación idempotente, conservando CSV/Excel/REST; registrar qué adaptadores adicionales se implementaron y cuáles siguen pendientes. No declarar implementados conectores no probados ni exigir todas las marcas de ERP para cerrar el núcleo.

Mantener al menos tres nociones diferenciadas cuando apliquen: fecha efectiva del evento, fecha en que la información estaba disponible en la fuente y fecha de ingestión en Predictily. La ingestión actual de un histórico no demuestra que una promoción era conocida anticipadamente. Cuando no se pueda reconstruir ese conocimiento, declarar la limitación y excluir la variable del backtest que requiera esa evidencia.

Conservar revisiones, anulaciones y correcciones de modo que pueda reconstruirse un snapshot de entrenamiento. Versionar el snapshot o registrar una huella que cubra también variables externas, precios, promociones, configuraciones y reglas de elegibilidad, no solo cantidades vendidas.

Registrar hasta qué fecha está completa cada fuente. Generar negativos de compra o días de cero venta únicamente dentro de cobertura observada suficiente. Normalizar conversiones documentadas de unidades, devoluciones y descuentos; no reconstruir precios o costos históricos con valores actuales.

Mantener contratos de ingesta genéricos CSV/Excel/REST. Soyoda es una posible fuente de validación, no una dependencia codificada en el core. Si existe un acceso autorizado, usar lectura y un mapeo documentado; si falta, preparar el contrato y la validación local sin afirmar conexión exitosa.

Definir una envoltura común de resultados con campos equivalentes a: `tenant_id`, `run_id`, `as_of`, `horizon_start`, `horizon_end`, `target_definition`, `entity_keys`, `unit`, `currency`, `result_type`, `model_version`, `data_snapshot_id`, `config_version`, `assumptions`, `limitations`, `quality_status` y procedencia de la incertidumbre. Distinguir el estado técnico del job de la aptitud estadística del resultado.

### D. Core de Demanda y evaluación temporal

Conservar LightGBM + MLForecast y los estadísticos implementados. Comparar sus resultados con referencias simples por clase, horizonte y escala. No forzar que LightGBM gane ni retirar AutoETS/Croston/TSB solo por ser modelos más simples. Ajustar candidatos y mínimos de datos con evidencia.

Conservar el alcance del prompt V2: horizontes configurables de 7, 14, 30, 60 y 90 días, sujetos a permisos y evidencia suficiente. El dominio puede soportar un horizonte aunque un plan comercial no lo autorice. Definir agregaciones por producto, categoría, sucursal y, cuando los datos lo permitan, almacén, región o mercado, con granularidad diaria, semanal o mensual. No duplicar ventas entre jerarquías. Resolver explícitamente productos nuevos o descontinuados, aperturas/cierres, quiebres de stock y eventos extraordinarios con respaldo o abstención identificados.

El protocolo debe fijarse antes de evaluar el período final:

1. Ventanas históricas para entrenamiento y selección de candidatos, con datos y perfiles reconstruidos en cada corte.
2. Calibración basada en predicciones fuera del ajuste y residuos que ya serían observables al momento de calibrar.
3. Un período posterior reservado que no se use para escoger modelo, hiperparámetros, features, umbrales ni calibrador.
4. Si se necesita evaluar repetidamente el procedimiento completo, cortes externos cronológicos y selección/calibración internas a cada uno. Registrar cuándo una comparación usa orígenes superpuestos; no tratar sus observaciones como independientes para estimar incertidumbre estadística.

Una etiqueta que necesita observar H días solo entra al entrenamiento cuando terminó ese período y la fuente está completa. Aplicar separaciones temporales que eviten compartir desenlaces futuros entre entrenamiento y validación; no usar división aleatoria de filas cliente/fecha.

Reportar MAE, WAPE cuando su denominador sea válido, MASE o RMSSE cuando exista una escala válida calculada con entrenamiento, sesgo, métricas de cuantiles cuando correspondan, cobertura y amplitud de intervalos, tamaño de muestra, exclusiones y horizonte efectivamente evaluado. Desglosar por producto, sucursal, clase y horizonte, preservando las métricas de unidades y de dinero por separado. No ocultar denominadores cero o cobertura insuficiente. Registrar también costo y estabilidad de inferencia al seleccionar modelos. Ofrecer P10/P50/P90 solo con un procedimiento evaluado para esos cuantiles; no equiparar una media a la mediana.

Los intervalos de sumas y de horizonte deben calibrarse o simularse como tales. No sumar cuantiles diarios ni probabilidades de compra como si fueran cantidades. Conservar dependencias relevantes al combinar demanda y suministro; declarar cualquier aproximación.

Registrar promoción de modelos, respaldo y modelo realmente usado. Si falla el ganador y se sirve un respaldo, sus intervalos, métricas y etiquetas deben corresponder al resultado servido. La mejora mínima del 3 % existente puede mantenerse como política inicial; cualquier cambio debe justificarse y versionarse.

### E. Core de Comportamiento de compra

Construir un dataset de cortes históricos por empresa, comprador y producto. La atribución a sucursal/canal debe definirse: no contar la misma compra varias veces al agregar ubicaciones. No entrenar por defecto un modelo independiente por comprador; comenzar con modelos compartidos dentro de la empresa y variables que describan cada historial.

Definir explícitamente los objetivos, comenzando por H=7 días y extendiendo el mismo contrato a H=30 y H=90 con entrenamiento y evaluación propios de cada horizonte:

- `purchase_probability`: probabilidad de al menos una compra válida del producto durante `(corte, corte + 7 días]`.
- `quantity_if_purchase`: cantidad total esperada en esos siete días condicionada a que exista alguna compra. Sumar todas las compras válidas del período; no utilizar solo el siguiente ticket.
- `expected_quantity`: esperanza incondicional coherente con las dos anteriores. Separar devoluciones y aclarar la definición comercial elegida.
- Incertidumbre de cantidad y estado de calibración. Si se ofrece una ventana de próxima compra, obtenerla de un método temporal evaluado; no deducir una fecha de la probabilidad binaria de siete días.

No multiplicar una probabilidad de siete días para obtener la de treinta o noventa. Para cada H, usar el volumen total de esa misma ventana y etiquetas maduras. Los eventos anidados deben ser coherentes: la probabilidad de alguna compra hasta siete días no debe superar la de hasta treinta bajo la misma población, definición y corte; cualquier corrección de coherencia debe evaluarse y registrarse.

Conservar las demás salidas comunes del Customer Core original: monto esperado por moneda, frecuencia de compra, productos/categorías probables y cambios de comportamiento o inactividad. Definir si el monto es neto de descuentos, impuestos y devoluciones; usar un precio futuro conocido, un escenario explícito o un modelo de monto evaluado, sin presentar como ingreso confirmado el producto de dos esperanzas dependientes. Un ranking debe cubrir un conjunto de candidatos conocido al corte, compararse con una referencia de popularidad/recencia y evaluarse con precision@k y recall@k. Para inactividad, definir ventana observable y referencia según el patrón del comprador; no convertir automáticamente ausencia de compras en abandono definitivo. Distinguir compras espontáneas, contratos y pedidos confirmados mediante datos y configuración, sin codificar un rubro específico.

Crear el conjunto de candidatos sin conocer compras futuras: compradores y relaciones elegibles observables al corte. Documentar cómo se consideran productos nunca comprados, compradores nuevos y ventas anónimas. Los códigos genéricos de consumidor final no representan una persona recurrente. Si se muestrean negativos por volumen, registrar la tasa y corregir la estimación/calibración para recuperar probabilidades de la población objetivo.

Utilizar recencia, distribución de intervalos, frecuencia, cantidades recientes, tendencia, precio observado, variación de precio, tiempo desde cambios conocidos, descuentos, calendario y disponibilidad. Cada variable se calcula a partir del pasado disponible. Censura por falta de stock, períodos sin cobertura y cierre no equivalen a decisión observada de no comprar.

Comparar como mínimo:

- Probabilidad: referencia por frecuencia/recencia, regresión logística y `LGBMClassifier`.
- Cantidad condicionada: referencia histórica y `LGBMRegressor`. Evaluar objetivos adecuados a cantidades enteras o continuas; no asumir Poisson para unidades fraccionarias ni escoger Tweedie por defecto sin comparación.
- Calibración: método ajustado con datos separados y orden temporal. Medir Brier score, log loss y curvas de calibración por grupos con muestra suficiente; AUC/accuracy no sustituyen calibración.
- Cantidad: error y sesgo tanto condicionado a compra como sobre el volumen esperado total; incluir compradores que no compraron en la evaluación incondicional.

Añadir PR-AUC cuando exista desbalance, sin sustituir las métricas de calibración, y MAE/sesgo del monto cuando se publique esa salida. BG/NBD puede compararse si sus supuestos encajan con compras no contractuales repetidas; no es obligatorio ni reemplaza la comprobación con los datos de la empresa.

Modelos de supervivencia quedan como evolución evaluable si se necesita estimar tiempo hasta compra y tratar censura temporal explícitamente. No bloquear la primera versión funcional por incorporar todos los algoritmos posibles.

Evitar doble conteo con el core de Demanda. El pronóstico agregado y el volumen esperado por comprador son dos vistas de demanda, no volúmenes que deban sumarse. Elegir y documentar una reconciliación o política de combinación; reservar un componente residual para ventas anónimas, compradores nuevos o población no modelada. Integrar pedidos confirmados de clientes y contratos sin sumarlos nuevamente a la demanda probabilística que ya los contempla; conservar su estado, cancelaciones, fechas y cantidades pendientes. La suma reconciliada debe respetar la cobertura del agregado y no alterar silenciosamente las probabilidades individuales.

### F. Core de Abastecimiento y planificación común

Implementar un balance por producto/ubicación/período con existencias iniciales, reservas, recepciones con fecha, demanda y, cuando se provean, producción y consumo de recursos. No contar simultáneamente una misma recepción en el snapshot de tránsito y en pedidos abiertos. Tratar recepciones parciales, retrasos, cancelaciones, mínimos y múltiplos de compra y fechas desconocidas.

Si una orden no tiene fecha de llegada, no hacerla disponible hoy. Registrar ese desconocimiento y utilizar una política explícita o abstenerse de calcular el componente afectado. El vencimiento de inventario y los productos perecederos deben representarse mediante capacidades opcionales cuando se suministren esos datos; no inventarlos.

Calcular reposición sobre el horizonte físico requerido, independientemente del rango visible del gráfico. Estimar el error acumulado sobre ese horizonte; no reutilizar sin ajuste errores de un horizonte menor. Incorporar variabilidad de plazos cuando exista evidencia. Un nivel de servicio solicitado no demuestra que el plan lo logre.

Implementar un planificador genérico funcional con restricciones aportadas como datos: presupuesto por período, disponibilidad y capacidad de proveedor, plazos, lotes/múltiplos, almacenamiento y capacidad de recursos. Documentar objetivo y prioridades: minimizar costos/faltantes o maximizar margen bajo restricciones. Incluir costos de compra, mantenimiento y faltantes solo cuando estén disponibles o sean supuestos explícitos.

Comparar proveedores con unidades, monedas, impuestos y gastos de adquisición normalizados explícitamente. La recomendación debe identificar proveedor, cantidad, fecha sugerida de emisión y recepción prevista, costo, restricciones activas y demanda que quedaría sin atender. Una oferta más barata que llega tarde no puede ocultar faltantes anteriores; si se elige ese compromiso, justificarlo con el objetivo y las prioridades configuradas. Si faltan opciones comparables, declarar la cobertura de la comparación.

La base requerida es planificación de reposición. Para producción, implementar el contrato y un caso genérico funcional de transformación de insumos a productos con cantidades, rendimientos, tiempos y capacidad aportados; las leyes particulares del proceso pertenecen al adaptador del rubro. La falta de datos de producción debe aparecer como `not_configured`, sin impedir la reposición de una empresa comercial.

Utilizar optimización matemática cuando la combinación de restricciones lo requiera. Elegir un solver mantenible y compatible con el entorno; registrar si la solución es óptima, factible, infactible o interrumpida y la brecha cuando se disponga. Un límite de tiempo no permite llamar óptimo a cualquier resultado. Incluir un respaldo factible explícito cuando resulte necesario.

Evaluar con casos pequeños cuyo resultado pueda comprobarse independientemente y con simulación histórica de operación. Mostrar demanda no atendida, sobrestock, inversión y margen/costo para comparar políticas. Ante restricciones incompatibles devolver la causa y alternativas calculadas, sin producir un plan que exceda presupuesto o capacidad.

### G. Costos, escenarios y límites de la causalidad

Crear una capacidad común de pronóstico de costos por producto/insumo/proveedor usando históricos normalizados y comparando al menos una referencia simple. Reutilizar componentes temporales del motor cuando sea adecuado, sin confundir cantidades demandadas con precios o costos unitarios.

Las variables externas deben llevar fuente, unidad, vigencia, fecha de publicación y revisión. Para el futuro usar valores conocidos o trayectorias declaradas como escenarios. No usar el clima realizado o el precio observado después del corte como si fueran un pronóstico disponible en el pasado. Cada conector se implementa como adaptador; clima/maíz específicos de una empresa no se codifican en el core.

El evaluador de escenarios recibe trayectorias de precio, costo, demanda, suministro y capacidad. Compara las estrategias sobre los mismos cortes, unidades y horizontes. Un resultado debe indicar qué entradas fueron pronosticadas, cuáles observadas y cuáles supuestas. Preservar coherencia entre demanda, oferta, ventas atendibles e inventario al calcular margen.

No identificar la reacción causal al precio con la importancia de una variable en LightGBM. Mantener el simulador de supuestos y un contrato separado para estimadores causales. Para habilitar un estimador exigir definición del efecto, variación y soporte de precios, confusores observables, disponibilidad, supuestos de identificación, intervalos y límites de extrapolación. El buen error predictivo no demuestra causalidad.

LinearDML u otros estimadores pueden evaluarse después de comprobar esos requisitos; no son una dependencia obligatoria para cerrar la primera implementación del core. Si falta evidencia causal, responder `insufficient_evidence` y ofrecer simulación condicionada. La interfaz y sus comprobaciones deben funcionar, pero no declarar validado un efecto real por usar un dataset sintético.

### H. Personalización desacoplada

Definir puntos de extensión para conectores, calendarios, variables adicionales, restricciones y reglas de transformación de recursos. Cada extensión declara versión, entradas, salidas, unidades, disponibilidad temporal y pruebas de contrato.

Resolver configuración validada por precedencia documentada, por ejemplo valores del core → perfil sectorial opcional → configuración de la empresa, respetando permisos y límites. Registrar la configuración efectiva en cada ejecución y permitir reproducirla. Los cambios de parámetros no deben sobrescribir silenciosamente modelos ni resultados históricos.

No incluir reglas avícolas, de ferretería, agrícolas, de exportación o de una empresa específica dentro de los algoritmos comunes. Una extensión puede aportar coeficientes de consumo o capacidad; el core calcula con esos datos y conserva su procedencia. Probar con dos perfiles de negocio sintéticos distintos que utilizan las mismas interfaces y con una empresa sin perfil sectorial.

### I. Integración, operación y trazabilidad

Extender API, esquema y UI de forma compatible y con contratos documentados. Mostrar resultados reales del nuevo core en los flujos existentes, con estados de datos insuficientes, incertidumbre desconocida y modelos de respaldo. No dedicar esta etapa a un rediseño visual general.

Orquestar por jobs con idempotencia, progreso, control de concurrencia y reintentos. Persistir ejecuciones, versiones de dataset/modelo/configuración, métricas, intervalos, supuestos y motivos de abstención. Separar entrenamiento de la actualización de predicciones: la recencia puede cambiar cada día aunque no exista una compra nueva. Incorporar también inventario, recepciones y promociones nuevas como causas de actualización pertinentes.

Mantener responsabilidades equivalentes a proveedores de variables y pronósticos, entrenamiento, evaluación, selección, registro e inferencia de modelos, reutilizando las abstracciones existentes. Registrar artefactos, compatibilidad de esquema, dependencias y estados candidato, validado, producción, retirado o fallido, con reversión a una versión validada. La capa conversacional consume la API de resultados y puede estar desactivada; su disponibilidad o proveedor no afecta entrenamiento, inferencia ni planificación.

Conservar la evaluación contra observaciones posteriores y detectar deterioro por objetivo, horizonte y grupos relevantes. Al recibir nuevas observaciones no reemplazar los pronósticos históricos originales; almacenar una evaluación vinculada a ellos. No promover automáticamente un candidato sin superar el protocolo fijado.

Respetar autorizaciones y privacidad existentes, sin agregar datos personales innecesarios. No leer o escribir una base productiva por defecto en pruebas. Mantener las credenciales fuera de código, documentos de resultados y logs.

### J. Pruebas de aceptación y entrega

Preparar una matriz requisito → código → prueba → evidencia → estado. Como mínimo debe cubrir:

| ID | Caso | Criterio observable |
|---|---|---|
| E2-01 | Compatibilidad etapa 1 | Importación, pronóstico agregado, inventario y UI mantienen su recorrido; migraciones y contratos compatibles |
| E2-02 | Recepción tardía | Pedido del día 30 no oculta faltante desde el día 2 con stock 10 y demanda 10/día |
| E2-03 | Horizonte físico | 30 + 7 días a 10/día implican demanda media 370 o abstención explícita si no se cubre; no 70 presentada como completa |
| E2-04 | Incertidumbre ausente | No devolver riesgo 0/1 o seguridad estadística por reemplazar `NaN` con cero |
| E2-05 | Temporalidad | Perturbar ventas futuras, perfiles, promociones, costos y revisiones no altera resultados de un corte anterior |
| E2-06 | Protocolo independiente | Manifiesto muestra fechas de entrenamiento/selección/calibración/prueba y maduración de etiquetas; el test final no participa en decisiones |
| E2-07 | Plan Básico | Ejecución programada usa 7/30 permitido y se completa en entorno de integración |
| E2-08 | Recompra | Varias compras dentro de siete días se suman; etiquetas incompletas/censuradas no se convierten en negativos |
| E2-09 | Compradores anónimos y nuevos | No inventar identidad recurrente; salida y política de respaldo identificadas |
| E2-10 | Calibración | Probabilidades evaluadas con Brier/log loss y curvas, con fechas y tamaño de muestra; calibración separada del ajuste |
| E2-11 | Reconciliación | No sumar dos veces demanda agregada y por comprador; residual explícito y prueba de coherencia de unidades |
| E2-12 | Inventario | No duplicar tránsito y órdenes; recepciones parciales, fechas desconocidas y reservas preservan el balance |
| E2-13 | Planificador | Casos pequeños de solución conocida; ninguna violación de presupuesto, capacidad, mínimos o balance; infactibilidad explicada |
| E2-14 | Producción genérica | Transformación sintética de insumo a producto respeta consumo, rendimiento, tiempo y capacidad sin reglas sectoriales en el core |
| E2-15 | Costos y escenarios | Fechas de disponibilidad correctas; clasificación de entradas observadas/pronosticadas/supuestas; escenarios comparables |
| E2-16 | Causalidad | Falta de soporte o identificación impide publicar efecto causal; simulación sigue disponible con supuestos |
| E2-17 | Aislamiento | Empresa A no consulta ni contamina datos, features, modelos, cachés o resultados de B, incluidos jobs y extensiones |
| E2-18 | Reintentos y actualización | Un reintento no duplica resultados; recencia y disponibilidad se actualizan sin exigir una venta nueva |
| E2-19 | Fallo del ganador | Respaldo identificado con métricas e incertidumbre que correspondan al resultado servido |
| E2-20 | Operación real | Reporte por fuente y fecha, período final reservado, errores/sesgo/cobertura y evaluación operativa; pendiente explícito si no hay acceso autorizado |
| E2-21 | Horizontes del core | Demanda 7/14/30/60/90 y compra 7/30/90 tienen contratos, permisos y evaluación por ventana; datos insuficientes producen abstención explícita |
| E2-22 | Salidas Customer | Monto, frecuencia, ranking e inactividad tienen definiciones, referencias, métricas y salidas funcionales; no se presentan montos esperados como ventas confirmadas |
| E2-23 | Compromisos comerciales | Contratos/pedidos confirmados y pronósticos no duplican cantidades; cancelaciones y fechas futuras respetan el corte |
| E2-24 | Registro y autonomía | Modelo servido y artefactos son reproducibles; reversión validada funciona y ausencia de LLM no bloquea los cuatro cores |
| E2-25 | Conector común | Al menos un recorrido SQL de solo lectura funciona en una base de pruebas; reimportación no duplica y correcciones conservan procedencia |
| E2-26 | Proveedores y fechas | Resultado incluye proveedor/cantidad/emisión/recepción/costo; una oferta tardía no esconde faltantes y comparación respeta unidades/monedas |

Las pruebas deben verificar resultados y reglas de negocio, no repetir mecánicamente fórmulas de la implementación. Ejecutar suites afectadas, integración y E2E en una base de pruebas aislada; no afirmar que pasaron si no se ejecutaron. Documentar comandos, versiones, hardware, semillas, duración, advertencias y limitaciones.

Entregar por bloques revisables:

1. **Estabilización:** F1–F7, contratos temporales y regresiones.
2. **Demanda y evaluación:** protocolo corregido, incertidumbre y resultados recalculados.
3. **Comportamiento:** recompra/cantidad a 7/30/90, monto, frecuencia, ranking e inactividad; calibración, reconciliación y API/UI mínimas. Entregar por incrementos funcionales sin omitir las salidas comunes conservadas del original.
4. **Decisiones comunes:** costos, balance fechado, optimización de reposición, transformación genérica y escenarios.
5. **Integración y validación:** pruebas entre componentes, trazabilidad, documentación y evaluación con datos reales si están disponibles.

Actualizar `docs/00-registro-avance.md`, el modelo de datos, contratos OpenAPI, estrategia de modelado y operación. Entregar una lista de capacidades implementadas, capacidades comprobadas solo con sintéticos, capacidades validadas con datos reales y dependencias externas pendientes. El core puede quedar técnicamente implementado antes de la validación comercial; ambas situaciones deben constar por separado.

No declarar la etapa 2 completa porque haya nuevas tablas, pantallas o nombres de modelos. Deben funcionar los flujos y las pruebas del core requerido. Las especializaciones sectoriales, modelos por empresa opcionales y estimadores causales sin evidencia permanecen como extensiones o capacidades condicionadas, con estado explícito.

**Fin del prompt complementario.**

## 7. Referencias técnicas para la implementación

Estas fuentes respaldan las capacidades de las herramientas; no garantizan precisión para Predictily ni reemplazan la validación local.

- MLForecast permite organizar pronósticos con modelos de machine learning, variables temporales y exógenas: [documentación oficial](https://nixtlaverse.nixtla.io/mlforecast/index.html).
- LightGBM ofrece distintos objetivos de clasificación y regresión; su elección debe corresponder al objetivo y a los datos: [parámetros oficiales](https://lightgbm.readthedocs.io/en/stable/Parameters.html).
- Las probabilidades requieren evaluar calibración con observaciones separadas del ajuste: [calibración de scikit-learn](https://scikit-learn.org/stable/modules/calibration.html).
- La partición temporal y la separación entre entrenamiento y prueba deben adaptarse al horizonte y a la disponibilidad de etiquetas; `gap` es una herramienta, no una garantía automática contra toda fuga: [TimeSeriesSplit](https://scikit-learn.org/stable/modules/generated/sklearn.model_selection.TimeSeriesSplit.html).

## 8. Uso y mantenimiento

Este documento es la guía de continuidad solicitada. No reemplaza las instrucciones del repositorio ni afirma que se haya implementado su prompt. Al iniciar trabajo, registrar qué hallazgos siguen vigentes. Al cerrar un bloque, actualizar su evidencia y distinguir resuelto, pendiente, no ejecutado y no validado con datos reales.

La personalización puede realizarse por rubro o por empresa usuaria. La prioridad de esta etapa es que todas esas variantes compartan un core correcto, comprobable y reproducible.
