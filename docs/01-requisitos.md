# 01 · Requisitos funcionales y no funcionales

**Producto:** Predictily — *Anticipa tus ventas. Optimiza tus decisiones.*
**Alcance inicial:** pymes de Ecuador (distribuidoras, ferreterías, repuestos automotrices y
agrícolas, comercios multisucursal, importadoras, consumo recurrente). Moneda USD, zona horaria
`America/Guayaquil`, idioma español con arquitectura preparada para i18n.

**Principio rector:** Predictily no reemplaza el ERP, POS ni el sistema contable; es una capa
independiente de inteligencia predictiva que se integra con ellos.

Prioridad: **MVP** = se entrega al cerrar la Fase 3 · **F4** = Fase 4 (diseño extensible,
implementación progresiva) · **Post** = posterior.

---

## 1. Requisitos funcionales

### RF-A · Cuenta, empresa y acceso
| ID | Requisito | Prioridad |
|---|---|---|
| RF-A1 | Registro e inicio de sesión con correo y contraseña; cierre de sesión; limitación de intentos. | MVP |
| RF-A2 | Un usuario crea una empresa (tenant) y queda como **Propietario**; puede pertenecer a varias empresas y cambiar entre ellas. | MVP |
| RF-A3 | Roles por empresa: Propietario, Administrador, Analista comercial, Usuario de consulta; Superadministrador de plataforma (sin acceso a datos de negocio salvo membresía). | MVP |
| RF-A4 | Gestión de usuarios de la empresa (agregar, cambiar rol, quitar), sin dejar la empresa sin propietario. | MVP |
| RF-A5 | Onboarding guiado: datos de la empresa, sucursales, primera importación, primer pronóstico; opción de cargar datos de demostración **ficticios**. | MVP |
| RF-A6 | Configuración de empresa: sector, zona horaria, horizonte por defecto, nivel de servicio, período de revisión, plazo de entrega por defecto, frecuencia de pronóstico. | MVP |
| RF-A7 | Planes SaaS con límites (sucursales, productos activos, filas importadas/mes, ejecuciones/día, usuarios, horizontes, funciones avanzadas); asignación manual por superadmin; seguimiento de consumo. | MVP (manual) / Post (pasarela de pagos) |

### RF-B · Datos e integración
| ID | Requisito | Objetivo del prompt | Prioridad |
|---|---|---|---|
| RF-B1 | Importación de CSV y Excel (xlsx) de ventas, productos, inventario y plazos de proveedor. | — | MVP |
| RF-B2 | Detección de columnas, formatos (fecha, separador decimal, delimitador, codificación) y mapeo sugerido por sinónimos; el usuario confirma el mapeo. | — | MVP |
| RF-B3 | Vista previa, validación asíncrona y reporte de calidad: duplicados, valores negativos o inconsistentes, devoluciones, facturas anuladas, monedas, unidades, productos y fechas inválidas, días faltantes. | — | MVP |
| RF-B4 | Confirmar o rechazar la importación; corregir mapeo/opciones y revalidar; importar solo filas válidas si el usuario lo decide; descargar errores en CSV. | — | MVP |
| RF-B5 | Importación **idempotente**: documento identificado por (tipo, número); reimportar actualiza o ignora, nunca duplica. | — | MVP |
| RF-B6 | Trazabilidad: archivo original (hash SHA-256), import que creó/actualizó cada documento, errores por fila. | — | MVP |
| RF-B7 | Ingesta por API REST (lotes de documentos) con token ligado a la empresa, idempotente. | — | MVP |
| RF-B8 | Interfaz de conectores configurables para ERP/POS de Ecuador, sin depender de API pública. | — | MVP (interfaz) / F4 (conectores concretos) |
| RF-B9 | Precios, descuentos y costos **históricos** por línea de venta; no reconstruir el pasado con precios actuales. | 5, 6 | MVP |
| RF-B10 | Gestión de fuentes de datos con credenciales cifradas que nunca se devuelven por API. | — | MVP |

### RF-C · Pronóstico (motor predictivo)
| ID | Requisito | Objetivo | Prioridad |
|---|---|---|---|
| RF-C1 | Pronóstico de demanda (unidades) por producto, categoría, sucursal y empresa. | 1 | MVP |
| RF-C2 | Vistas diaria, semanal y mensual. | 2 | MVP |
| RF-C3 | Horizontes configurables 7, 30 y 90 días. | 3 | MVP |
| RF-C4 | Tendencia, estacionalidad (semanal, quincenas, fin de mes, feriados de Ecuador incluidos los trasladados, décimos, temporadas) y comportamiento histórico. | 4 | MVP |
| RF-C5 | Uso de precio, descuentos y promociones como variables. | 5 | MVP |
| RF-C6 | Estrategia híbrida: Nivel A estadístico (Seasonal Naive, promedios móviles, ETS, métodos intermitentes), Nivel B global LightGBM/MLForecast dentro de la empresa. | — | MVP |
| RF-C7 | Validación temporal rolling-origin; métricas WAPE, MAE, RMSE, sesgo, cobertura de intervalos, por producto, sucursal y horizonte; comparación obligatoria con baseline. | 11 | MVP |
| RF-C8 | Selección automática del modelo ganador por clase de demanda, con modelo de respaldo; ningún reemplazo de producción sin evaluación. | 14 | MVP |
| RF-C9 | Intervalos de predicción 80 % y 95 % calibrados con residuos de backtesting. | 13 | MVP |
| RF-C10 | Coherencia jerárquica (reconciliación bottom-up) entre serie, producto, categoría, sucursal y total. | 1 | MVP |
| RF-C11 | Pronóstico de ingresos = unidades × supuesto explícito de precio; nunca confundir unidades con ingresos. | 1 | MVP |
| RF-C12 | Versionado: cada predicción asociada a ejecución, versión de modelo, fecha de generación, horizonte y versión del conjunto de características. | — | MVP |
| RF-C13 | Identificar ventas censuradas por quiebre de stock y comunicar la limitación cuando no hay inventario. | — | MVP |
| RF-C14 | Funcionamiento sin modelo específico entrenado (baseline como respaldo). | — | MVP |
| RF-C15 | Nivel C sectorial, Nivel D personalizado por empresa (cuando el backtest lo justifique), Nivel E fundacional (TimesFM 2.5 tras verificar licencia). | 14 | F4 |
| RF-C16 | Indicadores macroeconómicos (INEC, BCE, combustibles, materias primas, clima) con fecha de publicación y prevención de fuga temporal. | 8 | F4 |
| RF-C17 | Segmentación de clientes y análisis geográfico (provincia, cantón, zona). | 7 | F4 (datos ya modelados) |

### RF-D · Decisiones, análisis y reportes
| ID | Requisito | Objetivo | Prioridad |
|---|---|---|---|
| RF-D1 | Dashboard: ¿cuánto venderé?, ¿qué sube/baja?, ¿qué podría agotarse?, ¿cuánto inventario necesito?, ¿cuánto dinero inmovilizo en compras?, ¿qué tan confiables fueron las predicciones? | 1, 9, 10, 11 | MVP |
| RF-D2 | Riesgo de quiebre de stock (probabilidad aproximada, días de cobertura, fecha estimada). | 9 | MVP |
| RF-D3 | Recomendación básica de abastecimiento: demanda en plazo de entrega, stock de seguridad, punto de reorden, cantidad sugerida, inversión estimada; considerar reservado y en tránsito. **Nunca** ejecuta pedidos. | 10 | MVP (básica) / F4 (avanzada) |
| RF-D4 | Historial de precisión: backtest y precisión realizada frente a ventas reales posteriores. | 11 | MVP |
| RF-D5 | Explicaciones comprensibles y deterministas (plantillas basadas en métricas calculadas) del modelo, la incertidumbre y las limitaciones. | 13 | MVP |
| RF-D6 | Alertas (riesgo de quiebre, deterioro de precisión, calidad de datos, fallos de importación/ejecución). | 15 | MVP |
| RF-D7 | Tablas filtrables y exportación CSV; reporte resumen imprimible. | 15 | MVP |
| RF-D8 | Simulador de escenarios con supuestos explícitos (precio, descuento, costo, presupuesto, demanda, plazo, supuestos macro), distinguiendo simulación vs predicción vs estimación causal. | 12 | F4 (versión determinista incluida en MVP) |
| RF-D9 | Detección de drift y reentrenamiento programado; automatización: sincronizar, validar, actualizar características, pronosticar, evaluar, entrenar candidatos, publicar aprobados. | 14 | MVP (evaluación + alerta) / F4 (reentrenamiento automático) |
| RF-D10 | Elasticidad precio-demanda y efectos causales. | 12 | Post |
| RF-D11 | Explicaciones en lenguaje natural con LLM a partir de datos calculados, sin inventar cifras. | 13 | Post |

### RF-E · Privacidad y cumplimiento
| ID | Requisito | Prioridad |
|---|---|---|
| RF-E1 | Exportación completa de los datos de la empresa (portabilidad). | MVP |
| RF-E2 | Solicitud de eliminación con período de gracia y purga definitiva; políticas de retención configurables. | MVP |
| RF-E3 | Consentimiento explícito y revocable para entrenamiento compartido entre empresas (desactivado por defecto). | MVP (registro) / F4 (uso) |
| RF-E4 | Auditoría de accesos y acciones sensibles (inicio de sesión, importaciones, exportaciones, cambios de rol, ejecuciones). | MVP |

---

## 2. Requisitos no funcionales

| ID | Categoría | Requisito | Verificación |
|---|---|---|---|
| RNF-01 | Aislamiento | Un usuario de la Empresa A nunca accede a datos, modelos, archivos ni resultados de la Empresa B. Defensa en profundidad (middleware, global scope fail-closed, policies, FKs compuestas, jobs con tenant explícito, rutas de archivo por tenant). | Pruebas automatizadas de aislamiento en backend y motor |
| RNF-02 | Seguridad | OWASP ASVS nivel 2 como referencia: contraseñas con hash, CSRF, cookies httpOnly/secure/SameSite, rate limiting, validación estricta (Form Requests), consultas parametrizadas, ordenamientos por lista blanca, cabeceras de seguridad, TLS 1.2+. | Pruebas de endpoints, revisión de código |
| RNF-03 | Privacidad (LOPDP) | Minimización de datos personales, cifrado de campos sensibles, logs sin PII, retención y eliminación, registro de consentimiento, encargo de tratamiento documentado. | docs/07 |
| RNF-04 | Rendimiento | API de lectura p95 < 500 ms con 2 años × 200 series; importación de 300 000 líneas < 5 min; pipeline completo del dataset demo (~180 series, 2 años) < 5 min en 4 vCPU sin GPU. Ningún entrenamiento bloquea una petición HTTP. | Medición con dataset demo |
| RNF-05 | Escalabilidad | Varias empresas en la misma infraestructura; colas separadas (`imports`, `forecasting`, `default`); workers escalables horizontalmente; particionamiento de `forecast_values`/`sale_items` previsto si el volumen lo exige. | — |
| RNF-06 | Disponibilidad | Objetivo 99,5 % mensual en VPS único; reinicio automático de contenedores; healthchecks (`/up`, `/health`). | Monitoreo |
| RNF-07 | Respaldo | Copias diarias de PostgreSQL con rotación; **RPO ≤ 24 h, RTO ≤ 4 h**; restauración probada periódicamente. | infra/scripts |
| RNF-08 | Reproducibilidad | Semillas fijas, huella de datos de entrenamiento, versiones de librerías y artefactos con SHA-256 en `model_registry`. | Pruebas del motor |
| RNF-09 | Honestidad analítica | No anunciar precisión sin evaluación; no convertir R² en "confianza"; siempre mostrar baseline e incertidumbre; datos demo marcados como ficticios; sin respuestas simuladas presentadas como productivas. | Revisión de UI y pruebas |
| RNF-10 | Mantenibilidad | Monolito modular, servicios con responsabilidad única, código tipado (PHP strict types, TypeScript, type hints Python), sin duplicar lógica de negocio, migraciones versionadas, contratos OpenAPI. | Linters, pruebas |
| RNF-11 | Usabilidad y accesibilidad | Interfaz en español para empresarios sin conocimientos de ciencia de datos; responsive (móvil, tablet, escritorio); WCAG 2.1 AA como objetivo; estados de carga, error, vacío y calidad insuficiente. | Revisión de accesibilidad |
| RNF-12 | Internacionalización | Textos externalizados (vue-i18n / archivos de idioma), moneda y zona horaria por empresa, formatos locales. | — |
| RNF-13 | Costos | Despliegue en un VPS de 4 vCPU / 8–16 GB RAM (≈ USD 20–60/mes) sin GPU ni servicios administrados obligatorios; sin dependencias de IA generativa para cálculos. | docker-compose |
| RNF-14 | Observabilidad | Logs estructurados por componente, estado y duración de cada trabajo consultables, errores con contexto sin datos personales. | `training_jobs`, `import_jobs` |
| RNF-15 | Portabilidad | Docker Compose para desarrollo y VPS; configuración por variables de entorno. | `docker compose config` |

---

## 3. Criterios de aceptación del MVP

El MVP es funcional cuando este recorrido se completa de punta a punta con datos reales del
pipeline (no maquetas):

| # | Paso | Componentes involucrados |
|---|---|---|
| 1 | Un empresario registra su empresa. | Auth, `POST /tenants` |
| 2 | Configura su negocio y sucursales. | Onboarding, `/branches`, `PATCH /tenant` |
| 3 | Importa un archivo de ventas históricas. | Asistente de importación |
| 4 | Revisa y corrige posibles errores de importación. | Mapeo, validación, errores, revalidación |
| 5 | Predictily procesa sus datos. | `CommitImport`, `readiness` del motor |
| 6 | Se genera un pronóstico con un modelo estadístico o de ML real. | `RunForecastPipeline` → motor |
| 7 | El usuario visualiza las ventas previstas. | Dashboard, Pronósticos |
| 8 | Consulta productos de mayor y menor demanda. | `/forecasts/products` |
| 9 | Revisa la evaluación histórica del modelo. | Precisión (backtest y realizada) |
| 10 | Consulta una recomendación básica de abastecimiento si hay inventario suficiente. | Inventario y abastecimiento |
| 11 | Puede volver a importar ventas sin generar duplicados. | Idempotencia de importación |
| 12 | Puede generar nuevamente el pronóstico y consultar su versión. | `forecast_runs.version` |
| 13 | Una segunda empresa realiza el mismo proceso sin acceder a datos de la primera. | Aislamiento multi-tenant |

## 4. Supuestos y restricciones

- El historial mínimo útil es de ~8 semanas; con menos, se usan métodos de respaldo y se advierte.
- Los indicadores externos y conectores ERP requieren fuentes/credenciales externas: se entregan
  como interfaces desacopladas y se documenta su estado real (ver `docs/pendientes.md`).
- La disponibilidad y titularidad del dominio `predictily.com` debe verificarse antes del lanzamiento.
