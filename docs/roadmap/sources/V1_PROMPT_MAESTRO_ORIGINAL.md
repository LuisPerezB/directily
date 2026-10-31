# Predictily — Prompt maestro original (V1 / etapa 1) — texto literal recuperado

**Procedencia:** primer mensaje del propietario en la sesión de Claude Code que construyó la etapa 1 (27-oct-2026 16:10 UTC), recuperado literalmente de la transcripción de esa sesión el 31-oct-2026. SHA-256 del texto original: `f4a8a9a6056c0012a9c6f69c25b40cf9df82aa6641cfdd162613efb2ae9ada6a` (24 482 caracteres).

**Notas:** se conserva sin edición, incluida la primera línea («Usa subagentes.»), que era una instrucción de ejecución al agente y no parte del producto. Este archivo es una fuente histórica para la trazabilidad V2.5 (`docs/roadmap/V25_SOURCE_TRACEABILITY.md`); no es la instrucción vigente.

---

```text
Usa subagentes. 
PROMPT MAESTRO — PREDICTILY
1. Identidad y objetivo del proyecto
Actúa como un equipo multidisciplinario de ingeniería de software, ciencia de datos, arquitectura de machine learning, MLOps, diseño de productos SaaS, UX/UI, seguridad informática y análisis comercial.
Necesito diseñar y desarrollar Predictily, una plataforma SaaS B2B de inteligencia comercial predictiva, dirigida inicialmente a pequeñas y medianas empresas de Ecuador, con capacidad de expansión hacia Latinoamérica.
Nombre del producto: Predictily
Dominio de referencia: predictily.com (su disponibilidad y titularidad deberán verificarse antes del lanzamiento).
Eslogan: Anticipa tus ventas. Optimiza tus decisiones.
Moneda inicial: USD.
Zona horaria inicial: America/Guayaquil.
Idiomas: Español inicialmente, con arquitectura preparada para internacionalización.
Problema que queremos resolver
Las pymes toman decisiones de compra, inventario, precios y expansión comercial basándose principalmente en su experiencia, ventas anteriores y hojas de cálculo.
No suelen contar con equipos especializados en ciencia de datos, y los sistemas tradicionales de facturación o ERP no siempre incorporan pronósticos avanzados adaptados al negocio.
Predictily debe convertir la información comercial existente en predicciones útiles, recomendaciones verificables y decisiones mejor fundamentadas.
Propuesta de valor
La empresa conecta o importa su información comercial y obtiene pronósticos de demanda, estimaciones de ingresos, alertas de inventario y sugerencias de abastecimiento.
El sistema debe adaptarse gradualmente a cada negocio sin requerir que todos dispongan de amplios históricos de información.
Principio fundamental: Predictily no debe reemplazar al ERP, POS o sistema contable existente. Debe integrarse con ellos y actuar como una capa independiente de inteligencia predictiva.
2. Objetivos funcionales
El producto debe proporcionar:

1. Pronóstico de ventas por producto, categoría, sucursal y empresa.
2. Predicciones diarias, semanales y mensuales.
3. Horizontes de predicción configurables, inicialmente 7, 30 y 90 días.
4. Análisis de tendencia, estacionalidad y comportamiento histórico.
5. Incorporación de precios de venta, descuentos y promociones.
6. Incorporación de costos de compra y variaciones de proveedores.
7. Segmentación de clientes y análisis comercial geográfico.
8. Inclusión controlada de indicadores macroeconómicos nacionales e internacionales.
9. Estimación de riesgo de agotamiento de inventario.
10. Recomendaciones de abastecimiento basadas en demanda, inventario y plazos de proveedores.
11. Comparaciones entre ventas previstas y ventas reales.
12. Simulación de escenarios comerciales.
13. Explicaciones comprensibles de los pronósticos y su incertidumbre.
14. Adaptación progresiva de los modelos al comportamiento de cada empresa.
15. Generación de reportes y alertas.

El MVP debe concentrarse en los puntos 1, 2, 3, 4, 5, 9, 11, 13 y en una versión básica del 10.
Las funciones restantes deberán diseñarse de manera extensible sin impedir la entrega inicial.
3. Público objetivo
Priorizar:

* Distribuidoras mayoristas y minoristas.
* Ferreterías.
* Comercios de repuestos automotrices y agrícolas.
* Tiendas con varias sucursales.
* Empresas importadoras y comercializadoras.
* Negocios de productos de consumo recurrente.

La arquitectura debe permitir incorporar posteriormente manufactura, agroindustria, comercio electrónico, alimentos y servicios.
No intentar desarrollar modelos sectoriales completamente independientes durante el MVP.
4. Arquitectura tecnológica
Diseñar una arquitectura modular, mantenible y multicliente.
Backend principal

* Laravel, utilizando una versión estable con soporte vigente.
* API REST.
* Arquitectura basada en servicios y responsabilidades separadas.
* Validación estricta mediante Form Requests.
* Autenticación y autorización mediante políticas y roles.
* Procesamiento asincrónico.
* Contratos OpenAPI.
* Servicios reutilizables para importación, integración, configuración y consulta.

Motor predictivo

* Python 3.12 o versión estable compatible.
* FastAPI para exponer servicios internos.
* Pandas o Polars según volumen y necesidades.
* MLForecast.
* LightGBM.
* StatsForecast para modelos de referencia.
* Scikit-learn para preprocesamiento, evaluación y utilidades.
* TimesFM 2.5 como motor preentrenado experimental, únicamente después de verificar compatibilidad técnica y licencia comercial.

No implementar dependencia obligatoria de LLM para calcular pronósticos.
Los modelos estadísticos y de machine learning deben generar las predicciones. Un LLM podrá utilizarse posteriormente para explicar resultados en lenguaje natural, siempre basado en datos calculados y sin inventar cifras.
Frontend

* Vue 3 con TypeScript.
* Componentes reutilizables.
* Diseño responsive.
* Dashboard profesional con tablas y gráficos interactivos.
* Estados de carga, error, ausencia de datos y calidad insuficiente.
* Accesibilidad y navegación sencilla.

Infraestructura

* PostgreSQL.
* Redis.
* Workers para tareas asincrónicas.
* Docker Compose para desarrollo.
* Almacenamiento de archivos y artefactos de modelos.
* Migraciones versionadas.
* Configuración mediante variables de entorno.
* Despliegue inicial económico en VPS, sin requerir GPU.

Evitar microservicios innecesarios, Kubernetes, Kafka y otras dependencias de elevada complejidad durante el MVP.
Laravel actuará como backend comercial y API pública; Python será el motor interno de predicción.
5. Arquitectura de datos multicliente
El sistema funcionará como un SaaS multi-tenant.
Cada empresa tendrá sus propios usuarios, sucursales, productos, clientes, ventas, fuentes de datos, configuraciones y pronósticos.
Diseñar un aislamiento estricto mediante `tenant_id`, políticas de acceso y controles en consultas, servicios y tareas asincrónicas.
Un usuario de la Empresa A no podrá consultar, entrenar sobre datos privados no autorizados ni acceder a modelos, archivos o información sensible de la Empresa B.
Definir las siguientes entidades iniciales:

* tenants
* users
* tenant_users
* branches
* products
* product_categories
* customers
* customer_segments
* suppliers
* sales
* sale_items
* product_price_history
* purchase_cost_history
* inventory_snapshots
* stock_movements
* purchase_orders
* supplier_lead_times
* promotions
* external_indicators
* external_indicator_observations
* data_sources
* import_jobs
* import_errors
* data_quality_reports
* forecasting_series
* forecast_features
* training_jobs
* model_registry
* model_evaluations
* forecast_runs
* forecast_values
* forecast_actuals
* recommendations
* alerts
* audit_logs

Para cada entidad definir:

* Campos, tipos y restricciones.
* Relaciones.
* Índices.
* Llaves primarias y foráneas.
* Control de tenant.
* Reglas de integridad.
* Estrategia de versionado y auditoría.

Utilizar índices compuestos para las consultas de ventas por empresa, fecha, producto y sucursal.
No crear índices indiscriminadamente. Justificar los relevantes según las consultas esperadas.
6. Obtención, importación y validación de datos
Fuentes iniciales
Permitir:

1. Importación de Excel.
2. Importación de CSV.
3. Conexión mediante API REST.
4. Importadores configurables de otros sistemas.
5. Incorporación posterior de conectores ERP/POS.

Diseñar una interfaz de conectores que permita integrar sistemas de facturación usados en Ecuador sin depender de que todos tengan una API pública.
Proceso de incorporación
Cuando una empresa cargue un archivo:

1. Detectar columnas y formatos.
2. Permitir mapear las columnas con el esquema de Predictily.
3. Mostrar una vista previa.
4. Identificar duplicados.
5. Detectar valores negativos o inconsistentes.
6. Distinguir devoluciones y facturas anuladas.
7. Validar monedas, unidades, productos y fechas.
8. Detectar registros faltantes.
9. Permitir confirmar o rechazar la importación.
10. Mostrar un reporte de calidad.

Cada importación deberá ser idempotente cuando se disponga de identificadores externos confiables.
No crear ventas duplicadas al reimportar archivos.
Conservar trazabilidad de los datos originales y las transformaciones efectuadas.
Datos históricos mínimos
Cada venta debe permitir identificar:

* Empresa y sucursal.
* Fecha y hora.
* Documento de origen.
* Producto y cantidad.
* Precio unitario original.
* Descuento aplicado.
* Importe neto.
* Impuestos cuando corresponda.
* Costo de compra histórico, cuando exista.
* Cliente o segmento.
* Canal de venta.
* Estado de la transacción.

No utilizar precios actuales para reconstruir incorrectamente condiciones comerciales pasadas.
7. Motor de predicción
Estrategia híbrida
Implementar un motor que pueda seleccionar estrategias diferentes según la cantidad y calidad de información disponible.
Nivel A: Modelo estadístico básico
Para negocios con poco historial:

* Seasonal Naive cuando exista estacionalidad suficiente.
* Promedios móviles.
* Modelos ETS cuando sean apropiados.
* Métodos para demanda intermitente según el patrón observado.

Nivel B: Modelo global reutilizable

* LightGBM y MLForecast.
* Series identificadas por empresa, sucursal y producto.
* Variables temporales y comerciales.
* Características estáticas y dinámicas.
* Entrenamiento compartido únicamente con datos cuyo uso esté autorizado.

Nivel C: Modelo especializado por sector
Diseñar la capacidad futura de especializar modelos por sector cuando exista una cantidad suficiente de datos apropiados.
Nivel D: Modelo personalizado por empresa
Entrenar modelos exclusivos por empresa cuando las pruebas temporales demuestren que superan las alternativas generales.
Nivel E: Modelo fundacional experimental
Evaluar TimesFM 2.5 para pronósticos sin entrenamiento específico y posibles ajustes finos, siempre comparándolo contra modelos más simples y verificando su licencia.
No asumir que un modelo más complejo siempre tendrá mejores resultados.
Selección automática
El motor deberá evaluar varios candidatos y seleccionar una versión ganadora basándose en pruebas históricas, estabilidad, costo computacional y calidad de predicción.
El sistema debe conservar un modelo de respaldo.
No reemplazar un modelo de producción sin evaluación.
8. Ingeniería de características
Implementar un pipeline de generación de variables.
Variables temporales

* Día de semana.
* Semana y mes.
* Fin de mes.
* Quincenas.
* Feriados de Ecuador, incluidos los trasladados conforme al calendario correspondiente.
* Temporadas comerciales.
* Estacionalidad.
* Rezagos de ventas.
* Promedios y desviaciones móviles.
* Eventos comerciales relevantes.

Variables comerciales

* Precio histórico.
* Descuentos.
* Promociones.
* Categoría comercial.
* Canal de ventas.
* Frecuencia de compra.
* Segmentación de clientes.
* Historial de rotación.
* Estado de disponibilidad.

Variables geográficas

* Provincia.
* Cantón.
* Zona comercial.
* Ubicación de sucursal.
* Agregaciones geográficas de clientes.

No requerir direcciones personales exactas si una agregación geográfica es suficiente para el pronóstico.
Variables económicas
Diseñar conectores independientes para:

* IPC e inflación del INEC.
* Indicadores del Banco Central del Ecuador.
* Tasas de interés relevantes.
* Indicadores de actividad económica.
* Costos de combustibles.
* Precios de materias primas.
* Indicadores internacionales relevantes.
* Condiciones climáticas cuando aporten valor comprobable.

Guardar para cada indicador:

* Fuente.
* Fecha del período observado.
* Fecha real de publicación.
* Fecha de incorporación.
* Frecuencia.
* Unidad.
* Revisión o versión.
* Sector al que podría afectar.

Prevenir filtraciones temporales: en un backtesting solo se podrá utilizar la información disponible en la fecha histórica correspondiente.
Los indicadores futuros desconocidos deberán manejarse mediante escenarios explícitos, pronósticos independientes o exclusión de la variable.
9. Diferenciar ventas y demanda
El sistema no debe asumir que las unidades vendidas equivalen siempre a la demanda del mercado.
Considerar:

* Productos agotados.
* Días sin inventario.
* Suspensión de operaciones.
* Horarios comerciales.
* Problemas de abastecimiento.
* Ventas canceladas.
* Devoluciones.

Crear indicadores que permitan identificar ventas potencialmente censuradas por falta de stock.
Cuando no exista suficiente información para estimar la demanda no satisfecha, informar expresamente la limitación.
10. Validación de los modelos
Implementar validación temporal mediante rolling-origin backtesting.
No dividir aleatoriamente los registros históricos para evaluar un modelo de pronóstico.
Métricas principales:

* WAPE.
* MAE.
* RMSE como métrica complementaria.
* Sesgo de predicción.
* Error por producto.
* Error por sucursal.
* Error por horizonte.
* Cobertura de intervalos predictivos.

Tratar cuidadosamente las series con demanda cero o intermitente.
Siempre comparar con al menos un modelo estadístico sencillo.
Incluir intervalos de predicción calibrados cuando sea posible.
No anunciar porcentajes de precisión arbitrarios ni convertir R² en un porcentaje de confianza comercial.
Cada predicción debe asociarse con una versión de modelo, fecha de generación, horizonte y conjunto de características utilizado.
11. Predicciones disponibles
Generar inicialmente:
Pronóstico de demanda
Unidades esperadas por producto, categoría, sucursal y empresa.
Pronóstico de ingresos
Calcular ingresos proyectados a partir de las cantidades estimadas y de escenarios explícitos de precios futuros.
No confundir unidades demandadas con ingresos.
Inventario y abastecimiento
Estimar:

* Inventario disponible.
* Demanda durante plazo de entrega.
* Riesgo de quiebre de stock.
* Punto de reorden.
* Stock de seguridad.
* Cantidad sugerida de compra.

Considerar existencias reservadas y compras en tránsito cuando esos datos estén disponibles.
No ejecutar pedidos ni comprometer fondos automáticamente.
Jerarquías
Garantizar coherencia entre pronósticos por producto, categoría, sucursal y total general mediante mecanismos adecuados de reconciliación.
12. Simulador de escenarios
Diseñar una interfaz donde el usuario pueda modificar:

* Precio de venta.
* Descuentos.
* Costo de proveedor.
* Presupuesto de compras.
* Demanda esperada.
* Plazo de abastecimiento.
* Supuestos macroeconómicos.

Mostrar:

* Ingresos estimados.
* Margen bruto.
* Unidades proyectadas.
* Diferencias frente al escenario base.
* Riesgos comerciales.

Importante:
Distinguir entre una simulación matemática basada en supuestos, una predicción estadística y una estimación causal.
No afirmar que bajar el precio incrementará las ventas en un porcentaje exacto sin evidencia que permita estimar ese efecto.
Durante el MVP utilizar escenarios con supuestos claramente identificados.
La estimación avanzada de elasticidad y efectos causales será una funcionalidad posterior.
13. Experiencia de usuario
Diseñar una interfaz moderna, profesional y sencilla, orientada a empresarios sin conocimientos de ciencia de datos.
No convertir la plataforma en una herramienta exclusivamente técnica para científicos de datos.
Pantallas principales

1. Landing page de Predictily.
2. Registro e inicio de sesión.
3. Creación de empresa.
4. Configuración y onboarding.
5. Asistente de importación.
6. Dashboard comercial.
7. Pronósticos de demanda.
8. Análisis de productos y categorías.
9. Inventario y abastecimiento.
10. Historial de precisión.
11. Simulador de escenarios.
12. Gestión de fuentes de datos.
13. Configuración y usuarios.

Dashboard principal
Debe responder visualmente:

* ¿Cuánto se estima que venderé?
* ¿Qué productos aumentarán o disminuirán su demanda?
* ¿Qué productos podrían agotarse?
* ¿Cuánto inventario necesito?
* ¿Cuánto dinero podría inmovilizar en compras?
* ¿Qué tan confiables han sido las predicciones anteriores?

Los gráficos deben poder visualizarse por día, semana y mes.
Utilizar tablas filtrables y exportaciones CSV.
Diseño
Desarrollar una identidad visual propia para Predictily:

* Estética SaaS moderna.
* Interfaz clara y espaciosa.
* Buen contraste.
* Tipografía legible.
* Navegación lateral.
* Tarjetas de indicadores.
* Gráficos profesionales.
* Responsive para escritorio, tablet y móvil.
* Soporte de modo claro y oscuro si no retrasa el MVP.

No copiar interfaces ni identidades visuales de competidores.
14. Automatización de entrenamientos
Diseñar procesos programados para:

1. Sincronizar ventas.
2. Validar datos nuevos.
3. Actualizar características.
4. Generar nuevos pronósticos.
5. Evaluar pronósticos anteriores.
6. Detectar deterioro del rendimiento.
7. Entrenar modelos candidatos cuando corresponda.
8. Publicar versiones aprobadas.

El entrenamiento no debe ejecutarse obligatoriamente después de cada factura.
Utilizar colas y tareas asincrónicas.
Los trabajos deben permitir consultar:

* Estado.
* Progreso.
* Tiempo de ejecución.
* Resultado.
* Error.
* Reintentos.
* Modelo generado.

No bloquear solicitudes HTTP mientras se entrenan modelos.
15. Privacidad y cumplimiento
Tomar en consideración la Ley Orgánica de Protección de Datos Personales de Ecuador y su reglamentación vigente.
Implementar:

* Aislamiento estricto de datos entre empresas.
* Autenticación segura.
* Autorización por roles.
* Cifrado en tránsito.
* Protección de datos sensibles almacenados.
* Auditoría de accesos y acciones.
* Políticas de conservación y eliminación.
* Exportación de información.
* Gestión de credenciales de conectores.
* Restricción de datos personales en logs.
* Prevención de inyección SQL y acceso no autorizado.
* Copias de seguridad y restauración.

Distinguir los datos necesarios para prestar el servicio de aquellos que podrían utilizarse para entrenar modelos globales.
El entrenamiento compartido requerirá autorización y base jurídica apropiadas.
No asumir que anonimizar identificadores convierte automáticamente cualquier conjunto de datos en información anónima.
Diseñar el sistema con privacidad desde el inicio.
16. Administración y comercialización
Implementar roles:

* Superadministrador de Predictily.
* Propietario de empresa.
* Administrador de empresa.
* Analista comercial.
* Usuario de consulta.

Preparar la arquitectura para planes SaaS según:

* Número de empresas.
* Sucursales.
* Productos activos.
* Volumen de registros.
* Frecuencia de actualización.
* Pronósticos disponibles.
* Capacidades avanzadas.

Inicialmente permitir habilitar planes manualmente, sin integrar obligatoriamente una pasarela de pagos.
Diseñar un sistema de límites de uso y seguimiento de consumo.
17. Pruebas y calidad
Implementar pruebas automatizadas para:

* Aislamiento multicliente.
* Inicio de sesión y permisos.
* Importación de datos.
* Deduplicación.
* Validación de registros.
* Entrenamientos.
* Generación de pronósticos.
* Manejo de errores.
* Reproducibilidad de modelos.
* Prevención de filtraciones temporales.
* Comparación contra baselines.
* Procesamiento asincrónico.
* Seguridad de endpoints.

Crear datasets sintéticos reproducibles con características de comercios ecuatorianos.
Los datos de demostración deben estar identificados como ficticios.
El sistema debe funcionar aun cuando todavía no exista un modelo entrenado específico para una empresa.
18. Desarrollo por fases
Fase 1 — Fundamentos funcionales
Entregar:

* Repositorio organizado.
* Backend Laravel.
* Servicio predictivo Python.
* PostgreSQL.
* Redis.
* Docker Compose.
* Autenticación.
* Multi-tenancy.
* Empresas y sucursales.
* Productos y ventas.
* Importador CSV/Excel.
* Datos de demostración.

Fase 2 — Primer motor predictivo real
Entregar:

* Transformación de datos.
* Generación de características.
* Modelos estadísticos básicos.
* Entrenamiento LightGBM.
* Validación temporal.
* Registro de métricas.
* Pronóstico de 7, 30 y 90 días.
* Persistencia de predicciones.

Fase 3 — Interfaz analítica
Entregar:

* Dashboard funcional.
* Gráficos de históricos.
* Gráficos de pronósticos.
* Filtros por producto y sucursal.
* Comparación entre valores reales y pronosticados.
* Alertas básicas de inventario.
* Reportes.

Fase 4 — Inteligencia comercial avanzada
Preparar o implementar progresivamente:

* Modelos personalizados por empresa.
* Indicadores económicos externos.
* Modelos sectoriales.
* Simulador de escenarios.
* Recomendaciones avanzadas de abastecimiento.
* Evaluación de modelos fundacionales.
* Detección de drift.
* Automatización de reentrenamiento.
* Motor de explicaciones comerciales.

La Fase 4 no debe impedir disponer de un MVP utilizable al finalizar la Fase 3.
19. Documentación y entregables
Antes de comenzar la implementación, elaborar brevemente:

1. Documento de requisitos funcionales y no funcionales.
2. Arquitectura general.
3. Modelo entidad-relación.
4. Estrategia de entrenamiento y validación.
5. Diagrama de flujos principales.
6. Contratos de integración entre Laravel y Python.
7. Plan de seguridad y aislamiento multi-tenant.
8. Backlog priorizado de implementación.

Después, construir el código funcional de manera incremental.
Entregar:

* Código fuente completo.
* Migraciones.
* Seeders.
* Servicios backend.
* Servicios Python.
* Endpoints documentados.
* Componentes frontend.
* Pruebas automatizadas.
* Docker Compose.
* Archivo `.env.example`.
* README de instalación.
* Instrucciones para entrenamiento.
* Datos de demostración.
* Manual básico de uso.
* Lista de funcionalidades pendientes.

No generar únicamente mockups o pantallas estáticas que aparenten operar con inteligencia artificial.
Los pronósticos del MVP deben proceder de un pipeline real y verificable.
No simular procesos de entrenamiento ni respuestas de APIs como si fueran resultados productivos.
20. Criterios de aceptación del MVP
El MVP se considera funcional cuando puede completarse este recorrido:

1. Un empresario registra su empresa.
2. Configura su negocio y sucursales.
3. Importa un archivo de ventas históricas.
4. Revisa y corrige posibles errores de importación.
5. Predictily procesa sus datos.
6. Se genera un pronóstico con un modelo estadístico o de machine learning real.
7. El usuario visualiza las ventas previstas.
8. Consulta productos de mayor y menor demanda.
9. Revisa la evaluación histórica del modelo.
10. Consulta una recomendación básica de abastecimiento si existen datos de inventario suficientes.
11. Puede volver a importar ventas sin generar duplicados.
12. Puede generar nuevamente el pronóstico y consultar su versión.
13. Una segunda empresa puede realizar el mismo proceso sin acceder a datos de la primera.

El sistema debe estar preparado para servir varios clientes desde una misma infraestructura.
21. Reglas de implementación

* Priorizar funcionalidad real y arquitectura mantenible.
* Evitar sobreingeniería.
* Aplicar DRY, SOLID cuando corresponda y separación de responsabilidades.
* Utilizar código tipado donde sea posible.
* Mantener convenciones de nombres consistentes.
* No duplicar lógica de negocio.
* Optimizar consultas SQL.
* Prevenir consultas N+1.
* Utilizar colas para tareas costosas.
* Evitar entrenamientos innecesarios.
* No inventar datos para completar fuentes faltantes.
* No prometer niveles de precisión sin evaluación.
* No incluir dependencias de IA generativa donde basten cálculos deterministas.
* Documentar decisiones y supuestos importantes.
* Diseñar un producto preparado para crecer sin necesidad de reconstruirlo por completo.

22. Instrucción final de ejecución
Comienza evaluando el proyecto como un arquitecto de software y científico de datos.
Define la estructura técnica mínima necesaria para entregar un SaaS funcional.
Después implementa la Fase 1 y continúa progresivamente con las siguientes fases cuando el entorno lo permita.
No te limites a proporcionar pseudocódigo o descripciones generales.
Genera archivos, migraciones, componentes, servicios, pruebas y configuraciones reales.
Cuando una funcionalidad requiera credenciales externas o servicios no disponibles, implementa una interfaz desacoplada y documenta claramente esa dependencia, sin fingir que la integración está activa.
Mantén un registro del avance, indicando qué componentes están implementados, cuáles están probados y cuáles permanecen pendientes.
Resultado esperado: una primera versión real y demostrable de Predictily, capaz de importar ventas empresariales, procesarlas, entrenar o seleccionar un modelo predictivo, generar pronósticos y presentarlos de manera útil para tomar decisiones comerciales.
```
