# 08 · Backlog priorizado

Estimación relativa en puntos (1 = trivial, 2 = pequeño, 3 = medio, 5 = grande, 8 = muy grande).
**MVP** = entregado al cierre de la Fase 3. Estado real de cada ítem: `docs/00-registro-avance.md`.
Prioridad: P0 imprescindible para el recorrido MVP · P1 importante · P2 deseable.

## Fase 1 — Fundamentos funcionales (MVP)

| ID | Historia | Criterios de aceptación | Pts | Prio | Depende |
|---|---|---|---|---|---|
| F1-01 | Como equipo, quiero un repositorio con backend, motor, frontend, docs e infra | Estructura `backend/ engine/ frontend/ docs/ infra/ data/`; README de instalación | 2 | P0 | — |
| F1-02 | Esquema de datos multi-tenant versionado | Migraciones de las ~49 tablas aplican en PostgreSQL 16; FKs compuestas por tenant | 5 | P0 | F1-01 |
| F1-03 | Entorno con Docker Compose | `docker compose up` levanta postgres, redis, backend, colas, scheduler, engine y web; healthchecks | 3 | P0 | F1-01 |
| F1-04 | Como empresario quiero registrarme e iniciar sesión | Registro/login/logout con Sanctum SPA; rate limiting; auditoría `auth.*` | 3 | P0 | F1-02 |
| F1-05 | Como propietario quiero crear mi empresa | `POST /tenants` crea empresa (uuid, plan trial) y membresía owner | 2 | P0 | F1-04 |
| F1-06 | Aislamiento multi-tenant | Middleware `X-Tenant-ID`, global scope fail-closed, abilities por rol; pruebas: A no ve datos de B (404) | 5 | P0 | F1-05 |
| F1-07 | Como administrador quiero gestionar sucursales y usuarios | CRUD de sucursales; agregar usuario con rol; no dejar la empresa sin owner | 3 | P0 | F1-06 |
| F1-08 | Catálogo de productos y categorías | Listado filtrable/paginado con estadísticas 90 días; alta/edición | 3 | P0 | F1-06 |
| F1-09 | Como analista quiero subir un CSV/Excel y mapear columnas | Detección de delimitador/codificación/encabezados; mapeo sugerido por sinónimos; vista previa | 5 | P0 | F1-08 |
| F1-10 | Validación asíncrona y reporte de calidad | Errores por fila con código; duplicados; devoluciones; anuladas; monedas; fechas; días faltantes; puntaje | 5 | P0 | F1-09 |
| F1-11 | Confirmar/rechazar y reimportar sin duplicados | Upsert por (tipo, número) + hash; segunda importación del mismo archivo = 0 documentos nuevos | 5 | P0 | F1-10 |
| F1-12 | Importar inventario y plazos de proveedor | Upsert por (sucursal, producto, fecha); plazos con prioridad de resolución | 3 | P0 | F1-09 |
| F1-13 | Datos de demostración ficticios reproducibles | Generador con semilla; 2 empresas demo; marcados como ficticios; carga por comando y botón | 3 | P0 | F1-11 |
| F1-14 | Ingesta REST por lotes | `POST /sales/batch` con token ligado a empresa, idempotente | 3 | P1 | F1-11 |
| F1-15 | Auditoría y planes | `audit_logs` en acciones sensibles; límites de plan con respuesta 402; consumo mensual | 3 | P1 | F1-06 |

## Fase 2 — Primer motor predictivo real (MVP)

| ID | Historia | Criterios de aceptación | Pts | Prio | Depende |
|---|---|---|---|---|---|
| F2-01 | Servicio FastAPI interno con autenticación | `/health`, token Bearer, OpenAPI exportada; sin puertos públicos | 2 | P0 | F1-03 |
| F2-02 | Carga y transformación de datos por tenant | Series diarias (sucursal × producto) solo de ventas válidas; todas las consultas filtran `tenant_id` | 3 | P0 | F2-01 |
| F2-03 | Perfilado de series y ventas censuradas | ADI/CV², clase de demanda, días sin stock; `forecasting_series` y reporte de aptitud | 3 | P0 | F2-02 |
| F2-04 | Calendario y características de Ecuador | Feriados (incl. trasladados), quincenas, fin de mes, décimos por región, temporadas; rezagos y ventanas móviles sin fuga | 5 | P0 | F2-02 |
| F2-05 | Modelos estadísticos base (Nivel A) | Seasonal Naive, promedio móvil, ETS, Croston/TSB para intermitentes | 3 | P0 | F2-03 |
| F2-06 | Modelo global LightGBM/MLForecast (Nivel B) | Entrenado por empresa con variables temporales y comerciales; semilla fija | 5 | P0 | F2-04 |
| F2-07 | Backtesting rolling-origin y métricas | WAPE, MAE, RMSE, sesgo, cobertura; por horizonte, producto, sucursal; días censurados excluidos; prueba de no-fuga | 5 | P0 | F2-05 |
| F2-08 | Selección automática con baseline y respaldo | Gana el candidato solo si mejora al baseline; respaldo registrado | 3 | P0 | F2-07 |
| F2-09 | Pronóstico 7/30/90 con intervalos y reconciliación | Intervalos 80/95 conformales; bottom-up coherente en 6 niveles; granularidad día/semana/mes | 5 | P0 | F2-08 |
| F2-10 | Persistencia versionada | `forecast_runs.version`, `model_registry` con huella de datos y artefacto, `model_evaluations` | 3 | P0 | F2-09 |
| F2-11 | Orquestación desde Laravel | `POST /forecast-runs` → Job → motor; progreso consultable; reintentos; fallo marcado con alerta | 3 | P0 | F2-10 |
| F2-12 | Recomendaciones básicas de abastecimiento | Stock de seguridad, punto de reorden, cantidad sugerida, inversión, riesgo de quiebre (si hay inventario) | 3 | P0 | F2-09 |

## Fase 3 — Interfaz analítica (MVP)

| ID | Historia | Criterios de aceptación | Pts | Prio | Depende |
|---|---|---|---|---|---|
| F3-01 | Landing, registro y onboarding guiado | Identidad propia; pasos con estado; carga de demo ficticia | 3 | P0 | F1-05 |
| F3-02 | Asistente de importación | Subir → mapear → validar (polling) → errores → confirmar → resultado | 5 | P0 | F1-11 |
| F3-03 | Dashboard comercial | Responde las 6 preguntas del negocio; estados de carga/vacío/error/calidad insuficiente | 5 | P0 | F2-11 |
| F3-04 | Pronósticos de demanda | Gráfico histórico + pronóstico con bandas; día/semana/mes; 7/30/90; filtros producto/sucursal/categoría | 5 | P0 | F2-11 |
| F3-05 | Productos de mayor y menor demanda | Tabla filtrable con variación, tendencia, ingresos, modelo y error | 3 | P0 | F3-04 |
| F3-06 | Historial de precisión | Backtest vs baseline, por horizonte/clase; real vs pronosticado de ejecuciones pasadas; explicación en lenguaje claro | 5 | P0 | F2-10 |
| F3-07 | Inventario y abastecimiento | Riesgos, cobertura, cantidad sugerida e inversión; marcar como atendida (sin crear pedidos) | 3 | P0 | F2-12 |
| F3-08 | Alertas | Bandeja con severidad; reconocer/resolver | 2 | P1 | F2-12 |
| F3-09 | Exportaciones CSV y reporte | CSV de pronósticos, recomendaciones y precisión; reporte imprimible | 2 | P1 | F3-04 |
| F3-10 | Fuentes de datos y configuración | Conectores con estado real; tokens REST; usuarios; consentimiento | 3 | P1 | F1-14 |
| F3-11 | Evaluación diaria vs reales | Scheduler + `evaluate-actuals`; precisión realizada visible | 3 | P1 | F2-10 |
| F3-12 | Modo oscuro y accesibilidad | Contraste AA, navegación por teclado, etiquetas; modo oscuro si no retrasa | 2 | P2 | F3-03 |

**Fin del MVP:** recorrido de 13 pasos de `docs/01-requisitos.md §3` completo con dos empresas.

## Fase 4 — Inteligencia comercial avanzada

| ID | Épica / historia | Criterios de aceptación | Pts | Prio | Depende |
|---|---|---|---|---|---|
| F4-01 | Simulador de escenarios (determinista) | Supuestos explícitos; base vs escenario; riesgos; aviso de no causalidad | 3 | P1 | F2-11 |
| F4-02 | Modelos personalizados por empresa (Nivel D) | Se publica solo si supera en backtest al global con margen y estabilidad | 5 | P1 | F2-08 |
| F4-03 | Detección de drift y reentrenamiento automático | Alerta + entrenamiento de candidato + publicación condicionada a evaluación | 5 | P1 | F3-11 |
| F4-04 | Indicadores económicos externos | Conectores INEC/BCE/combustibles con fechas de publicación; consultas as-of; prueba de no-fuga; escenarios para valores futuros | 8 | P2 | F2-04 |
| F4-05 | Modelos sectoriales (Nivel C) | Requiere consentimiento de entrenamiento compartido y base jurídica; evaluación contra Nivel B | 8 | P2 | F4-02 |
| F4-06 | Evaluación de modelo fundacional (TimesFM 2.5) | Verificación de licencia y compatibilidad CPU; comparación contra baseline; adaptador opcional | 5 | P2 | F2-07 |
| F4-07 | Recomendaciones avanzadas | Restricciones de presupuesto, lotes mínimos, multi-sucursal (transferencias), sobrestock | 5 | P2 | F2-12 |
| F4-08 | Motor de explicaciones comerciales | Explicaciones por producto (drivers: estacionalidad, promoción, precio) basadas en datos calculados; LLM opcional sin inventar cifras | 5 | P2 | F3-06 |
| F4-09 | Conectores ERP/POS de Ecuador | Al menos un conector real (API o exportación programada) con credenciales de solo lectura | 8 | P1 | F3-10 |
| F4-10 | Segmentación de clientes y análisis geográfico | Pronóstico/ventas por segmento, provincia, cantón | 5 | P2 | F1-08 |
| F4-11 | Elasticidad precio-demanda | Estimación con validación; intervalos; uso en simulador marcado como estimación | 8 | P2 | F4-01 |
| F4-12 | Endurecimiento de seguridad | RLS PostgreSQL, 2FA, escaneo de imágenes, pentest | 5 | P1 | F1-06 |
| F4-13 | Pasarela de pagos y autoservicio de planes | Suscripción, facturación electrónica | 8 | P2 | F1-15 |
| F4-14 | Internacionalización y expansión LatAm | Idioma, moneda, feriados por país | 5 | P2 | F2-04 |

## Riesgos y dependencias del backlog

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Historial corto o de baja calidad en pymes | Pronósticos poco precisos | Nivel A como respaldo, reporte de aptitud, advertencias explícitas |
| Ventas censuradas sin datos de inventario | Subestimación de demanda | Marcado y comunicación de la limitación; solicitar inventario |
| Sistemas de facturación sin API | Integración manual | Importador configurable y conectores por exportación programada |
| Costo de cómputo en VPS sin GPU | Tiempos largos | Límites de series, ventanas de backtest acotadas, colas separadas |
| Requisitos legales LOPDP | Bloqueo comercial | Encargo de tratamiento, consentimiento, asesoría legal (docs/07) |
