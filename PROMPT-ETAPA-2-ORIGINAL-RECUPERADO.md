# Prompt maestro V2 original — extracto recuperado

Fuente: [Modelo predictivo de ventas](chatgpt-conversation://6ae0c449-1648-83e9-b553-248a6fe5e7c3). Mensaje de respuesta `ff192a34-c030-4ce4-b42c-f2c5ba6b1082`, al pedido «Ya mande a correr el primero. Genera el segundo prompt considerando que el primero ya esta ejecutado...». Recuperado el 28 de octubre de 2026.

**Este archivo es un anexo histórico, no la instrucción vigente de ejecución.** Conserva literalmente el prompt desde su título hasta el final de la sección 10. La herramienta de lectura limitó el mensaje a 20.000 caracteres; su sección 11 quedó incompleta, por lo que no se incluye. No se recuperaron en esta lectura el resto de ese prompt ni el checklist posterior de instalaciones. No se ha inventado ni completado el fragmento faltante.

Para implementar la continuación, usar la sección 6 de `instrucctions.md`, que incorpora los hallazgos comprobados y la prioridad actual del usuario: core común correcto primero, personalización por rubro o empresa aparte. Las tecnologías experimentales, afirmaciones externas y referencias históricas del texto de abajo no se consideran validadas por transcribirlas.

---

# PREDICTILY — PROMPT MAESTRO V2
## Evolución del proyecto existente: cuatro cores y módulos sectoriales

### CONTEXTO FUNDAMENTAL

Ya se ejecutó una primera instrucción de desarrollo para construir **Predictily**, una plataforma SaaS B2B de inteligencia comercial predictiva.

Esa primera instrucción contemplaba Laravel, Python/FastAPI, PostgreSQL, Vue 3, arquitectura multicliente, importación de datos, pronósticos comerciales y modelos de machine learning.

**ESTA INSTRUCCIÓN NO ES PARA CREAR OTRO PROYECTO DESDE CERO.**

Debes trabajar sobre el repositorio existente, identificar su estado real y continuar el desarrollo, aprovechando todas las funcionalidades, migraciones, componentes, servicios, modelos y pruebas ya construidos.

El nombre del producto continuará siendo **Predictily**.

Su objetivo es evolucionar hacia una plataforma de inteligencia predictiva empresarial modular, independiente del ERP, capaz de atender negocios pequeños, medianos y grandes, incluyendo distribuidores, comercios minoristas, exportadoras, industrias y empresas agroindustriales.

---

# 1. PRIMERA ACCIÓN OBLIGATORIA: AUDITORÍA DEL PROYECTO ACTUAL

Antes de realizar modificaciones:

1. Examina toda la estructura del repositorio.
2. Identifica frameworks, versiones y convenciones existentes.
3. Revisa las migraciones y el esquema de base de datos.
4. Analiza los servicios de importación y normalización.
5. Identifica todos los motores predictivos ya desarrollados.
6. Examina los endpoints existentes y sus contratos.
7. Revisa el sistema de autenticación y aislamiento multicliente.
8. Identifica procesos de entrenamiento, evaluación y predicción.
9. Revisa los componentes del frontend.
10. Ejecuta las pruebas existentes cuando el entorno lo permita.
11. Documenta dependencias faltantes, funcionalidades incompletas y errores encontrados.

Crear el archivo:

`docs/architecture/PREDICTILY_V2_GAP_ANALYSIS.md`

Debe incluir:

- Arquitectura encontrada.
- Componentes funcionales y comprobados.
- Componentes existentes pero incompletos.
- Requisitos nuevos.
- Problemas de seguridad o integridad.
- Modificaciones necesarias.
- Migraciones nuevas requeridas.
- Riesgos de compatibilidad.
- Plan de implementación incremental.

### Reglas de preservación

- No eliminar código funcional sin justificación.
- No regenerar innecesariamente el proyecto Laravel.
- No recrear tablas existentes cuando puedan ampliarse.
- No borrar datos.
- No ejecutar migraciones destructivas automáticamente.
- No reemplazar implementaciones válidas únicamente por preferencia tecnológica.
- No modificar las credenciales existentes.
- No cambiar contratos públicos sin conservar compatibilidad o documentar una migración.
- No asumir que una funcionalidad existe solamente porque hay archivos con nombres similares.
- Identificar el estado de Git y conservar los cambios existentes antes de modificar archivos.

Si se necesita reorganizar componentes, implementar adaptadores o migraciones progresivas.

Realizar cambios reversibles siempre que resulte posible.

---

# 2. NUEVA ORGANIZACIÓN DE PREDICTILY

La arquitectura central constará de cuatro cores:

1. Predictily Data Core.
2. Predictily Demand Core.
3. Predictily Customer Core.
4. Predictily Supply Core.

Sobre ellos se construirán módulos sectoriales:

- Predictily Retail.
- Predictily Export.
- Predictily Industry.
- Predictily Agro.
- Futuras extensiones comerciales.

Los módulos sectoriales deben reutilizar los cores. No duplicar modelos, servicios de autenticación ni lógica genérica de importación.

Establecer interfaces y contratos claros entre cores y módulos.

---

# 3. CORE 01 — PREDICTILY DATA CORE

Responsable de recolectar, transformar, validar, versionar y preparar los datos de todas las empresas.

## 3.1. Integraciones

Implementar una arquitectura extensible de conectores:

- PostgreSQL.
- SQL Server.
- MySQL/MariaDB.
- CSV.
- Excel.
- APIs REST.
- Sistemas ERP mediante adaptadores.

Los conectores de bases de datos deberán funcionar inicialmente en modo de solo lectura.

Permitir configurar consultas, vistas y mapeos autorizados.

Nunca solicitar credenciales de administrador cuando baste un usuario restringido.

No requerir acceso permanente a la base original después de una importación inicial.

## 3.2. Modelo empresarial normalizado

Definir contratos canónicos para:

- Empresas.
- Sucursales.
- Almacenes.
- Clientes.
- Proveedores.
- Productos.
- Categorías.
- Unidades comerciales.
- Ventas y detalles.
- Devoluciones.
- Cotizaciones y pedidos.
- Compras.
- Inventario.
- Movimientos de inventario.
- Precios históricos.
- Descuentos y promociones.
- Calendarios.
- Temporadas.

Mantener el origen y el identificador externo de cada registro.

No modificar ni suponer que todos los ERPs manejan las mismas entidades.

## 3.3. Calidad de datos

Implementar validaciones de:

- Integridad de fechas.
- Documentos anulados.
- Devoluciones.
- Duplicados.
- Cantidades incorrectas.
- Productos sin categoría.
- Clientes sin identificadores estables.
- Unidades incompatibles.
- Registros incompletos.
- Días sin ventas.
- Períodos con ausencia de datos.
- Quiebres de inventario.
- Cambios en códigos de productos.
- Cambios históricos de precios.

Generar reportes de calidad con cobertura temporal, registros utilizables, errores, advertencias y capacidades de predicción disponibles.

Distinguir entre cero ventas y ausencia de registros.

Conservar timestamps de eventos y timestamps de disponibilidad de datos cuando corresponda.

## 3.4. Motor de características

Implementar un Feature Pipeline reutilizable:

- Ventas acumuladas.
- Rezagos.
- Promedios y desviaciones móviles.
- Frecuencia de compra.
- Precios históricos.
- Descuentos.
- Disponibilidad de inventario.
- Tendencias.
- Días de semana.
- Meses.
- Feriados.
- Fechas de pago.
- Temporadas.
- Variables por sector.
- Indicadores económicos externos.

Toda característica utilizada en entrenamiento debe estar disponible en la fecha histórica que se está simulando.

Evitar leakage de datos futuros.

Persistir versiones reproducibles de los datasets de entrenamiento.

---

# 4. CORE 02 — PREDICTILY DEMAND CORE

Objetivo: pronosticar la demanda de productos y servicios utilizando datos comerciales, temporales, operativos y externos.

## 4.1. Objetivos de predicción

Permitir pronósticos:

- Por empresa.
- Por sucursal.
- Por almacén, cuando corresponda.
- Por producto/SKU.
- Por categoría.
- Por mercado.
- Por región.
- Por día.
- Por semana.
- Por mes.
- Por temporada.

Horizontes iniciales: 7, 14, 30, 60 y 90 días.

No ofrecer granularidades cuya información histórica sea insuficiente.

## 4.2. Modelos obligatorios

Implementar una arquitectura de estrategias intercambiables:

- Seasonal Naive.
- ETS u otros métodos estadísticos adecuados.
- Métodos para demanda intermitente.
- LightGBM + MLForecast.

Agregar como candidatos experimentales:

- Chronos-2.
- AutoGluon-TimeSeries.
- TimeGPT mediante API opcional.

No requerir GPU ni TimeGPT para utilizar el MVP.

No reemplazar automáticamente LightGBM por un modelo fundacional si no mejora la evaluación.

## 4.3. Selección del modelo

Crear un sistema de selección automática basado en backtesting temporal.

Comparar los modelos según:

- WAPE.
- MAE.
- MASE o RMSSE cuando corresponda.
- Sesgo.
- Precisión por horizonte.
- Cobertura de intervalos predictivos.
- Estabilidad de resultados.
- Costo de entrenamiento e inferencia.

Utilizar un conjunto de validación temporal y conservar un período final de prueba no utilizado para la selección.

Permitir modelos globales, sectoriales y personalizados por empresa.

Entrenar modelos exclusivos solamente cuando exista suficiente evidencia de una mejora sostenida.

## 4.4. Casos especiales

Contemplar:

- Productos nuevos.
- Productos descontinuados.
- Productos de baja rotación.
- Demanda intermitente.
- Inventario agotado.
- Cambios de precios.
- Promociones.
- Apertura de sucursales.
- Cierres temporales.
- Temporadas extraordinarias.

Implementar estrategias de fallback por categoría, segmento o modelos globales.

## 4.5. Resultados

Generar:

- Predicción central.
- Intervalos P10/P50/P90 cuando estén calibrados.
- Demanda diaria y agregada.
- Modelo seleccionado.
- Error de backtesting.
- Versión del modelo.
- Fecha de generación.
- Fuente y versión de datos.
- Limitaciones de calidad.

La predicción deberá ser consultable mediante API REST, no solamente mediante dashboard.

---

# 5. CORE 03 — PREDICTILY CUSTOMER CORE

Objetivo: anticipar el comportamiento de compra de clientes individuales y segmentos comerciales.

Este core deberá contar con modelos y métricas propias.

## 5.1. Capacidades

Predecir:

1. Probabilidad de recompra.
2. Probabilidad de compra en 7, 30 o 90 días.
3. Próxima ventana probable de compra.
4. Frecuencia de compra.
5. Productos y categorías probables.
6. Cantidad esperada condicionada a una compra.
7. Importe esperado.
8. Variaciones relevantes de comportamiento.
9. Riesgo de inactividad comercial.

Distinguir clientes minoristas identificados de compradores B2B con contratos y pedidos recurrentes.

## 5.2. Características

Implementar:

- Recencia.
- Frecuencia.
- Valor monetario.
- Intervalos históricos entre pedidos.
- Historial por producto y categoría.
- Estacionalidad.
- Descuentos.
- Precios.
- Canal comercial.
- Segmento.
- Cotizaciones.
- Contratos.
- Pedidos confirmados.

No utilizar datos personales innecesarios.

## 5.3. Modelos

Evaluar:

- Reglas de recurrencia como baseline.
- Modelos BG/NBD cuando sean apropiados.
- LightGBM para clasificación de recompra.
- LightGBM para importe y cantidad.
- Métodos de ranking de categorías.
- Modelos de supervivencia cuando se justifique.

Las probabilidades deberán calibrarse.

No establecer porcentajes arbitrarios de certeza.

## 5.4. Evaluación

Incluir:

- PR-AUC.
- Brier Score.
- Log-loss.
- Precision@K.
- Recall@K.
- MAE para cantidad e importe.
- Evaluación temporal.

La salida debe explicar el período y las condiciones evaluadas.

## 5.5. Integración con demanda

Crear un servicio de reconciliación entre demanda agregada y predicciones por cliente.

Evitar sumar dos veces:

- Pedidos confirmados.
- Probabilidades de compra.
- Demanda agregada.
- Compras recurrentes previstas.

El Demand Core continuará funcionando aunque una empresa no disponga de clientes identificados.

---

# 6. CORE 04 — PREDICTILY SUPPLY CORE

Objetivo: transformar los pronósticos de demanda en decisiones de abastecimiento.

No construir este motor como un chatbot.

Utilizar cálculos determinísticos, simulación y optimización matemática.

## 6.1. Variables

- Demanda prevista.
- Pedidos confirmados.
- Inventario físico.
- Inventario disponible.
- Inventario reservado.
- Órdenes pendientes.
- Fechas previstas de recepción.
- Plazos de proveedores.
- Historial de retrasos.
- Costos de compra.
- Capacidad de almacenamiento.
- Compras mínimas.
- Múltiplos de empaque.
- Vida útil y caducidad.
- Presupuesto disponible.

## 6.2. Funciones

Desarrollar:

- Posición de inventario.
- Demanda prevista durante plazo de entrega.
- Stock de seguridad.
- Punto de reorden.
- Fecha sugerida de compra.
- Cantidad sugerida de compra.
- Proyección futura de existencias.
- Riesgo de desabastecimiento.
- Riesgo de sobreinventario.
- Presupuesto sugerido.
- Comparación entre proveedores.

Cuando falten datos de inventario o proveedor, indicar que no es posible ofrecer una recomendación completa.

## 6.3. Motor de optimización

Primera implementación:

- Python.
- Fórmulas y reglas verificables.
- Simulaciones cronológicas de inventario.

Segunda implementación:

- Google OR-Tools.
- Optimización de compras con restricciones.
- Presupuesto máximo.
- Mínimos de compra.
- Capacidad de almacenamiento.
- Prioridad de productos.
- Costos de adquisición.
- Múltiples proveedores.

Las órdenes de compra reales requieren autorización humana.

## 6.4. Evaluación

Comparar recomendaciones históricas mediante simulaciones que utilicen información disponible hasta cada fecha de decisión.

Medir:

- Faltantes.
- Exceso de inventario.
- Nivel de servicio.
- Días de inventario.
- Costos de almacenamiento.
- Costos de adquisición.
- Capital comprometido.

No asumir que menor error de demanda equivale automáticamente a mejor abastecimiento.

---

# 7. MÓDULOS SECTORIALES

Crear una arquitectura basada en contratos de extensión, no duplicando los cuatro cores.

Cada módulo podrá registrar:

- Tipos de producto.
- Unidades de medida.
- Calendarios.
- Características adicionales.
- Reglas comerciales.
- Restricciones de abastecimiento.
- Métricas sectoriales.
- Visualizaciones especializadas.

## 7.1. PREDICTILY RETAIL

Dirigido a:

- Tiendas.
- Supermercados.
- Ferreterías.
- Distribuidoras.
- Comercios de repuestos.
- Mayoristas.

Capacidades:

- Pronósticos por SKU.
- Reabastecimiento por sucursal.
- Temporadas comerciales.
- Promociones.
- Precios y descuentos.
- Recompra de clientes.
- Inventarios multibodega.
- Clasificación ABC/XYZ.
- Rotación de productos.
- Productos sustitutos y complementarios.

Debe ser el primer módulo sectorial completamente funcional.

## 7.2. PREDICTILY EXPORT

Dirigido a exportadoras de camarón, banano, cacao, flores y otros productos.

Extender las entidades para representar:

- Compradores internacionales.
- Países de destino.
- Mercados comerciales.
- Contratos.
- Órdenes de exportación.
- Productos y presentaciones.
- Calidad, grado, talla y especificaciones.
- Materia prima.
- Rendimientos de transformación.
- Lotes.
- Inventario comprometido.
- Capacidad productiva.
- Contenedores y embarques.
- Fechas de zarpe.
- Costos de flete.
- Incoterms.
- Precios FOB/CIF cuando corresponda.
- Monedas y tipos de cambio.
- Temporadas de mercado.

Capacidades:

1. Pronóstico de demanda por producto, presentación, comprador y país.
2. Estimación de pedidos adicionales, separada de contratos confirmados.
3. Requerimiento de materia prima.
4. Necesidades de producción.
5. Planificación de abastecimiento.
6. Capacidad comprometida.
7. Estimación de márgenes.
8. Escenarios de variación de precios y costos.

Implementar conversiones de unidades exactas y configurables.

Ejemplo: kilogramos, libras, cajas, toneladas y unidades comerciales.

Los rendimientos deberán definirse por producto y proceso; no utilizar un rendimiento global arbitrario.

No confundir volumen de materia prima con peso de producto terminado.

Para el MVP de Export, desarrollar un flujo mínimo de demanda → pedidos confirmados → inventario → requerimiento de materia prima.

Los procesos portuarios avanzados y la optimización de contenedores pueden quedar preparados como extensiones posteriores.

## 7.3. PREDICTILY INDUSTRY

Dirigido a fabricantes y plantas productivas.

Extender con:

- Listas de materiales (BOM).
- Materias primas.
- Productos terminados.
- Rendimientos.
- Órdenes de producción.
- Capacidad por planta.
- Tiempos de fabricación.
- Mermas.
- Restricciones de producción.
- Requerimientos de insumos.

Capacidades principales:

- Demanda de productos terminados.
- Planificación de materiales.
- Necesidades de compras.
- Riesgos de insuficiencia de capacidad.
- Proyección de producción.

No crear un ERP industrial completo.

## 7.4. PREDICTILY AGRO

Dirigido a agricultores, centros de acopio y comercializadoras.

Extender con:

- Cultivos.
- Temporadas de siembra y cosecha.
- Proveedores agrícolas.
- Volúmenes de cosecha.
- Calidad y clasificación.
- Rendimientos.
- Calendarios agrícolas.
- Centros de acopio.
- Precios históricos.
- Clima y condiciones externas, cuando sean relevantes.

Capacidades principales:

- Demanda comercial.
- Necesidades de abastecimiento.
- Estacionalidad de compras.
- Disponibilidad prevista.
- Proyección de compras de clientes recurrentes.

Mantener separados los pronósticos de demanda comercial y los pronósticos de producción agrícola.

---

# 8. SISTEMA UNIFICADO DE CALENDARIOS Y TEMPORADAS

Crear un módulo reutilizable para representar:

- Feriados nacionales.
- Feriados locales.
- Temporadas comerciales.
- Campañas.
- Promociones.
- Eventos sectoriales.
- Temporadas agrícolas.
- Períodos de alta demanda internacional.
- Eventos excepcionales.

Cada calendario debe asociarse con su ámbito: país, región, empresa, sucursal, sector o producto.

Los eventos deberán incluir inicio, fin, categoría y estado de conocimiento.

No permitir que un evento futuro desconocido sea tratado como información histórica disponible.

---

# 9. ENTRENAMIENTO Y SELECCIÓN DE MODELOS

Crear una arquitectura de experimentación independiente del modelo específico.

Definir contratos para:

- ModelTrainer.
- FeatureProvider.
- ForecastProvider.
- ModelEvaluator.
- ModelSelector.
- ModelRegistry.
- ModelInferenceService.

Cada entrenamiento debe registrar:

- Empresa.
- Tipo de predicción.
- Dataset utilizado.
- Versión de características.
- Algoritmo.
- Hiperparámetros.
- Período de entrenamiento.
- Ventanas de validación.
- Métricas.
- Tiempo consumido.
- Recursos utilizados.
- Artefactos generados.

Los estados de los modelos deben permitir:

- Candidate.
- Validated.
- Production.
- Retired.
- Failed.

No sustituir modelos en producción si el candidato no demuestra una mejora o ventaja operacional justificable.

Permitir modelos globales, sectoriales y específicos por empresa, sin compartir información confidencial sin autorización.

---

# 10. SEPARACIÓN DE ENTRENAMIENTO, INFERENCIA Y CHATBOT

Establecer tres procesos independientes.

### Training Service

- Entrenamiento.
- Backtesting.
- AutoML.
- Evaluación.
- Fine-tuning cuando corresponda.
- Registro de artefactos.

### Prediction Service

- Carga de modelos aprobados.
- Inferencia.
- Pronósticos programados.
- Intervalos.
- Consulta de resultados.
- Consumo por otros cores.

### Conversational Service

- Interpretación de solicitudes mediante proveedor LLM externo.
- Selección de herramientas autorizadas.
- Explicación de resultados.
- Generación de resúmenes comerciales.

La capa conversacional debe integrarse mediante un proveedor configurable.

Priorizar Ollama Cloud API, pero permitir incorporar otros proveedores en el futuro.

El chatbot no realizará cálculos financieros complejos ni reemplazará los motores predictivos.

La ausencia del proveedor LLM no debe impedir utilizar los cuatro cores.

---

**Fin del extracto literal recuperado. El mensaje original continúa.**

