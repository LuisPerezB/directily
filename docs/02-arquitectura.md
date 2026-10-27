# 02 · Arquitectura general

## Visión

Predictily es una **capa de inteligencia predictiva** independiente del ERP/POS del cliente.
No reemplaza la facturación ni la contabilidad: importa o recibe sus datos, los valida,
genera pronósticos con modelos estadísticos y de machine learning reales, y entrega
recomendaciones verificables.

```
                 ┌──────────────────────────── VPS (sin GPU) ─────────────────────────────┐
 Navegador ──►   │  nginx ──► SPA Vue 3 (estático)                                         │
 (empresario)    │    │                                                                     │
                 │    └──► /api, /sanctum ──► Laravel 13 (API pública, auth, multi-tenant)  │
 ERP/POS  ──►    │                              │   ▲                                       │
 (REST/CSV)      │                              │   │ estado de jobs / resultados (SQL)     │
                 │                Redis ◄───────┤   │                                       │
                 │          (colas, caché,      ▼   │                                       │
                 │           sesiones)   Workers de cola Laravel ──HTTP interno──► Motor    │
                 │                              │                    (Bearer)     FastAPI  │
                 │                              ▼                                  Python   │
                 │                         PostgreSQL 16  ◄──── lectura/escritura ─────┘   │
                 │                              ▲                  (filtrada por tenant)    │
                 │                    almacenamiento privado: archivos importados,          │
                 │                    artefactos de modelos (volumen compartido)            │
                 └─────────────────────────────────────────────────────────────────────────┘
```

## Componentes

| Componente | Tecnología | Responsabilidad |
|---|---|---|
| `frontend/` | Vue 3 + TypeScript + Vite, Pinia, Vue Router, ECharts, vue-i18n | SPA responsive; dashboard, asistente de importación, pronósticos, inventario, precisión, simulador |
| `backend/` | Laravel 13 (PHP 8.3), Sanctum, OpenSpout | API REST pública, autenticación, roles, aislamiento multi-tenant, importación y validación, orquestación de colas, simulador determinista, exportaciones, auditoría, planes y consumo |
| `engine/` | Python 3.12, FastAPI, pandas, StatsForecast, MLForecast + LightGBM, scikit-learn, holidays | Perfilado de series, ingeniería de características (calendario Ecuador), backtesting rolling-origin, selección de modelos, intervalos, reconciliación jerárquica, recomendaciones de inventario, evaluación de precisión y drift |
| PostgreSQL | 16 | Datos de negocio y resultados del motor; integridad multi-tenant con FKs compuestas |
| Redis | 7 | Colas (`default`, `imports`, `forecasting`), caché, sesiones |

## Decisiones principales (resumen tipo ADR)

1. **Monolito modular + un servicio de cómputo.** Laravel es el backend comercial y la API
   pública; Python es el motor interno. Sin microservicios, Kubernetes ni Kafka en el MVP.
2. **El motor lee y escribe directo en PostgreSQL.** Enviar años de historial por HTTP es
   ineficiente. El motor usa un repositorio que exige `tenant_id` en cada consulta. Laravel es
   dueño del esquema (migraciones); el motor solo escribe en sus tablas (ver docs/06 §4).
3. **Invocación síncrona desde un Job de cola.** El usuario nunca espera un entrenamiento en una
   petición HTTP: la petición crea `forecast_run` + `training_job` (202) y un worker de Laravel
   invoca al motor con timeout amplio. Reintentos, timeouts y fallos los gestiona la cola de
   Laravel; el progreso se escribe en `training_jobs` y el frontend lo consulta.
4. **Multi-tenancy por `tenant_id` con defensa en profundidad:** (a) middleware que resuelve la
   empresa y verifica membresía; (b) global scope "fail-closed" (sin empresa activa la consulta
   falla); (c) policies/abilities por rol; (d) FKs compuestas `(tenant_id, x_id)` en la base de
   datos; (e) Jobs transportan `tenantId` explícito; (f) archivos en `tenants/{id}/...`;
   (g) pruebas automatizadas de aislamiento. Row Level Security de PostgreSQL queda como
   endurecimiento posterior documentado.
5. **Autenticación**: Sanctum en modo SPA (cookies httpOnly + CSRF, mismo dominio vía nginx) y
   tokens Bearer ligados a una empresa para integraciones REST.
6. **Sin LLM para calcular pronósticos.** Las explicaciones del MVP son plantillas deterministas
   basadas en métricas calculadas. Un LLM podría redactar explicaciones en el futuro, siempre a
   partir de datos calculados.
7. **Estrategia de modelado híbrida y honesta**: siempre se evalúa un baseline estadístico; un
   modelo más complejo solo gana si mejora el error en backtesting temporal. Se guarda un modelo
   de respaldo. TimesFM queda como adaptador experimental desactivado hasta verificar licencia y
   compatibilidad.
8. **Ventas ≠ demanda**: se marcan días censurados por falta de stock; se excluyen de la
   evaluación y se informa la limitación cuando no hay inventario para estimarla.
9. **Simulador determinista**: el simulador aplica supuestos explícitos; no afirma efectos
   causales de precio sobre demanda (elasticidad = fase posterior).
10. **Despliegue económico**: Docker Compose en un VPS (4 vCPU / 8–16 GB RAM) sin GPU.

## Estructura del repositorio

```
predictily/
├── backend/        Laravel 13 (API pública, colas, importación)
├── engine/         Motor predictivo Python (FastAPI)
├── frontend/       SPA Vue 3 + TypeScript
├── data/demo/      Datasets ficticios reproducibles
├── docs/           Documentación (requisitos, arquitectura, contratos, seguridad, manuales)
├── infra/          nginx, scripts de despliegue y de base de datos
├── docker-compose.yml
├── .env.example
└── Makefile
```

## Flujo principal (resumen)

1. Registro → creación de empresa (owner) → sucursales.
2. Importación: subir archivo → detección de columnas y mapeo sugerido → validación asíncrona
   (errores, duplicados, devoluciones, anuladas, faltantes) → confirmar → upsert idempotente.
3. Al terminar: `readiness` del motor (perfil de series y calidad).
4. Pronóstico: el usuario (o la programación) solicita una ejecución → Job → motor:
   carga → perfila → características → backtesting → selección → entrenamiento final →
   predicción 90 días + intervalos → reconciliación → recomendaciones → persistencia.
5. Dashboard, pronósticos, inventario y precisión leen resultados versionados.
6. Diariamente: evaluación contra ventas reales y detección de deterioro (drift).
