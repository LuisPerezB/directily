# 00 · Registro de avance

Estado por componente. Leyenda: ✅ implementado y probado · 🟡 implementado sin pruebas completas ·
⏳ pendiente · 🔌 interfaz desacoplada (requiere credenciales/servicio externo).

| Fecha | Componente | Estado | Notas |
|---|---|---|---|
| 2026-10-27 | Esquema de datos (49 tablas, migraciones Laravel) | ✅ | Migra en PostgreSQL 16; FKs compuestas por tenant |
| 2026-10-27 | Núcleo multi-tenant (TenantContext, BelongsToTenant, ResolveTenant, roles) | 🟡 | Pruebas a cargo de backend-core |
| 2026-10-27 | Contratos (docs/06) y arquitectura (docs/02) | ✅ | |

## Notas de los agentes
(Cada agente agrega aquí decisiones o cambios de contrato relevantes.)
- [backend-analytics] Interfaces disponibles para backend-core: `App\Jobs\Forecasting\RefreshReadiness(int $tenantId, ?int $trainingJobId = null)` y `App\Services\Forecasting\ForecastRunService::request(Tenant, ?User, int $horizonDays = 90, ?string $cutoffDate = null, string $triggeredBy = 'user'): ForecastRun` (lanza `ForecastRunConflictException` → 409 si hay un run activo). Registrado `App\Providers\AnalyticsServiceProvider` en bootstrap/providers.php (binding de `EngineClient`).
- [backend-analytics] **Requisito de cola**: `RunForecastPipeline` tiene timeout `ENGINE_TIMEOUT + 60` s. La conexión Redis de colas debe usar `retry_after` mayor (p. ej. `REDIS_QUEUE_RETRY_AFTER=1020` en `.env`/`.env.example`), si no, Redis reentrega el Job mientras aún corre. El motor responde 409 a ejecuciones concurrentes del mismo run y es idempotente para runs completados.
- [engine] 2026-10-27: **Datos demo listos** en `data/demo/` (ficticios, semilla 42, reproducibles). `ferreteria_andina`: 3 sucursales, 62 productos, 99 334 líneas de venta (2024-10-27→2026-10-26, 18.9 MB). `distribuidora_costa`: 2 sucursales, 40 productos, 42 035 líneas (2025-07-27→2026-10-26). Valores en archivos de ventas: `document_type` = `factura` | `nota de crédito` (cantidades positivas); `status` vacío (= completada) o `anulada`; `channel` canónico (store, wholesale, field_sales); `customer_code` 9999999999999 = consumidor final (código genérico SRI). Extras sin campos en el contrato: `{slug}_suppliers.csv` (`code,name,country,default_lead_time_days`) y `{slug}_promotions.csv` (`name,promo_type,discount_pct,starts_on,ends_on,branch_code,product_sku,category`; incluye una promoción futura). `*_sales_with_errors.csv` trae una columna extra `nota_calidad` (no mapear). Regenerar: `cd engine && .venv/bin/python -m predictily_engine.cli generate-demo --out ../data/demo --seed 42`.
