# 05 · Flujos principales

Diagramas de secuencia de los recorridos clave. Los nombres de endpoints, Jobs y estados
corresponden a `docs/06-contratos-integracion.md`.

Participantes: **U** = usuario (navegador), **SPA** = frontend Vue, **API** = Laravel,
**Q** = worker de cola Laravel (Redis), **ENG** = motor Python (FastAPI), **DB** = PostgreSQL,
**FS** = almacenamiento privado.

---

## 1. Registro → empresa → onboarding

```mermaid
sequenceDiagram
    autonumber
    actor U as Empresario
    participant SPA
    participant API as Laravel API
    participant DB as PostgreSQL

    U->>SPA: Completa registro
    SPA->>API: GET /sanctum/csrf-cookie
    SPA->>API: POST /api/v1/auth/register
    API->>DB: INSERT users (hash de contraseña)
    API->>DB: INSERT audit_logs (auth.registered)
    API-->>SPA: 201 {user} + cookie de sesión
    U->>SPA: Crea empresa (nombre, RUC, sector)
    SPA->>API: POST /api/v1/tenants
    API->>DB: INSERT tenants (uuid, plan trial, settings por defecto)
    API->>DB: INSERT tenant_users (role = owner)
    API-->>SPA: 201 {tenant.uuid}
    Note over SPA: Desde aquí todas las peticiones llevan X-Tenant-ID
    U->>SPA: Agrega sucursales y configura negocio
    SPA->>API: POST /branches · PATCH /tenant (settings)
    API->>API: ResolveTenant (membresía) + ability (branches.manage)
    API->>DB: INSERT branches (tenant_id del contexto)
    SPA->>API: GET /tenant → onboarding {has_branches, has_sales, has_forecast...}
    alt Quiere probar sin datos propios
        SPA->>API: POST /tenant/demo-data (datos FICTICIOS)
    else Importa sus datos
        Note over SPA: continúa en flujo 2
    end
```

## 2. Importación de ventas (CSV/Excel) con validación asíncrona e idempotencia

```mermaid
sequenceDiagram
    autonumber
    actor U
    participant SPA
    participant API
    participant Q as Worker (cola imports)
    participant FS as Almacenamiento privado
    participant DB

    U->>SPA: Selecciona archivo + tipo "ventas"
    SPA->>API: POST /imports (multipart)
    API->>API: Valida tamaño (≤20 MB), extensión, plan (filas/mes)
    API->>FS: Guarda en tenants/{id}/imports/{uuid}.csv (hash SHA-256)
    API->>API: Detecta delimitador, codificación, encabezados, sugiere mapeo por sinónimos
    API->>DB: INSERT import_jobs (awaiting_mapping, preview, suggested_mapping)
    API-->>SPA: 201 ImportJob
    U->>SPA: Revisa vista previa y ajusta mapeo/opciones
    SPA->>API: PUT /imports/{id}/mapping
    API->>Q: ValidateImport(tenantId, importId)
    API-->>SPA: 202 (validating)
    loop Polling cada 2 s
        SPA->>API: GET /imports/{id}
    end
    Q->>FS: Lee archivo completo (streaming)
    Q->>Q: Normaliza fechas (zona horaria de la empresa), números, tipos de documento, estados
    Q->>Q: Detecta duplicados en archivo, negativos (devoluciones), anuladas, monedas, faltantes
    Q->>DB: Compara (document_type, document_number) + source_hash con ventas existentes
    Q->>DB: INSERT import_errors · data_quality_reports · UPDATE import_jobs (validated, stats)
    SPA-->>U: Reporte: válidas, errores, advertencias, nuevas / actualizadas / sin cambios
    alt Hay errores
        U->>SPA: Corrige mapeo u opciones (o descarga errors.csv, corrige y re-sube)
        SPA->>API: PUT /imports/{id}/mapping (revalida)
    end
    U->>SPA: Confirmar (o Rechazar → rejected, se elimina el archivo)
    SPA->>API: POST /imports/{id}/confirm
    API->>Q: CommitImport
    Q->>DB: Por bloques, en transacción: upsert productos/categorías/sucursales (si se permite),<br/>upsert sales por clave natural, si cambió el hash → reemplaza líneas, si no → omite
    Q->>DB: UPDATE import_jobs (completed) · tenant_usage · audit_logs
    Q->>Q: Despacha RefreshReadiness (cola forecasting)
    Note over Q,DB: Reimportar el mismo archivo ⇒ documentos "sin cambios": 0 duplicados
```

## 3. Ejecución de pronóstico (Laravel Job → motor → BD → frontend)

```mermaid
sequenceDiagram
    autonumber
    actor U
    participant SPA
    participant API
    participant Q as Worker (cola forecasting)
    participant ENG as Motor Python
    participant DB

    U->>SPA: "Generar pronóstico"
    SPA->>API: POST /forecast-runs {horizon_days: 90}
    API->>API: ability forecast.run · límite de plan · sin otra ejecución en curso (409)
    API->>DB: INSERT forecast_runs (version = max+1, queued) · training_jobs (queued)
    API->>Q: RunForecastPipeline(tenantId, runId, jobId)
    API-->>SPA: 202 {run, training_job}
    Q->>ENG: POST /v1/forecast-runs/{run}/execute (Bearer, timeout ENGINE_TIMEOUT)
    ENG->>DB: Verifica run/job ∈ tenant, marca running
    ENG->>DB: SELECT ventas válidas WHERE tenant_id = :t AND sale_date ≤ corte
    ENG->>ENG: Perfil de series (ADI/CV², clase de demanda, días censurados por stock)
    ENG->>ENG: Características (calendario EC, quincenas, décimos, rezagos, precio, promociones)
    ENG->>ENG: Backtesting rolling-origin de candidatos + baseline (SeasonalNaive)
    ENG->>ENG: Selección por clase de demanda + modelo de respaldo
    ENG->>ENG: Entrenamiento final · predicción 90 días · intervalos 80/95 · reconciliación bottom-up
    ENG->>ENG: Recomendaciones de abastecimiento (si hay inventario)
    loop Cada etapa
        ENG->>DB: UPDATE training_jobs (stage, progress)
    end
    ENG->>DB: INSERT model_registry · model_evaluations · forecast_values · recommendations · alerts
    ENG->>DB: UPDATE forecast_runs (completed, is_current) · training_jobs (succeeded)
    ENG-->>Q: 200 {selection, metrics, warnings}
    loop Polling
        SPA->>API: GET /jobs/{id}
        API-->>SPA: {status, stage, progress}
    end
    SPA->>API: GET /dashboard/summary · /forecasts/series · /forecasts/products
    API->>DB: Lee forecast_values del run vigente (tenant del contexto)
    API-->>SPA: Pronóstico versionado (run.version, modelo, corte)
    alt Motor no responde / error
        Q->>Q: Reintento (tries=2, backoff 60 s)
        Q->>DB: failed(): run/job failed + alerta run_failed
    end
```

## 4. Evaluación diaria contra ventas reales y detección de deterioro (drift)

```mermaid
sequenceDiagram
    autonumber
    participant S as Scheduler (02:00 America/Guayaquil)
    participant Q as Worker
    participant ENG as Motor
    participant DB

    S->>Q: predictily:evaluate-actuals → EvaluateActuals por empresa activa
    Q->>DB: INSERT training_jobs (evaluate_actuals)
    Q->>ENG: POST /v1/tenants/{t}/evaluate-actuals
    ENG->>DB: Runs completados con fechas objetivo ya observadas
    ENG->>DB: Ventas reales del período (+ marcas de quiebre de stock)
    ENG->>DB: UPSERT forecast_actuals · INSERT model_evaluations (realized)
    alt WAPE realizado > max(1.3 × backtest, backtest + 0.10) con ≥ 14 días observados
        ENG->>DB: INSERT alerts (forecast_drift, dedup_key)
        Note over S,DB: Según settings.forecast_schedule se programa un nuevo entrenamiento,<br/>el nuevo modelo solo se publica si supera al vigente en backtest
    end
    ENG-->>Q: {realized_wape, drift_detected}
```

## 5. Ingesta por API REST (conector de sistemas de facturación)

```mermaid
sequenceDiagram
    autonumber
    participant ERP as ERP/POS del cliente
    participant API
    participant DB

    Note over ERP,API: El administrador creó un token en Fuentes de datos (ligado a la empresa)
    ERP->>API: POST /api/v1/sales/batch (Bearer token, ≤ 500 documentos)
    API->>API: Token → tenant (ignora X-Tenant-ID) · ability imports.manage · rate limit
    API->>API: Form Request: valida cada documento y línea
    API->>DB: Upsert por (document_type, document_number) + source_hash (misma lógica que CommitImport)
    API-->>ERP: 200 {created, updated, unchanged, errors[]}
    Note over ERP,API: Reenviar el mismo lote es seguro (idempotente)
```

Conectores sin API pública (sistemas locales): el contrato `Connector` permite implementaciones
que lean exportaciones programadas (CSV en carpeta/SFTP) o bases de datos de solo lectura. Hasta
contar con credenciales reales, el conector se muestra como `requires_credentials` / `planned`.

## 6. Recomendación de abastecimiento

```mermaid
flowchart TD
    A["Pronóstico diario por serie<br/>sucursal × producto"] --> B{"¿Existe inventario<br/>reciente?"}
    B -- No --> X["Sin recomendación:<br/>se informa la limitación"]
    B -- Sí --> C["Disponible = on_hand − reservado + en tránsito"]
    D["Plazo de entrega L<br/>producto+sucursal › producto › proveedor › default"] --> E
    R["Período de revisión R<br/>settings.review_period_days"] --> E
    E["Demanda en L+R = Σ yhat<br/>σ desde intervalos / residuos de backtest"]
    E --> F["Stock de seguridad = z · σ<br/>(z según nivel de servicio)"]
    F --> G["Punto de reorden = demanda en L + SS"]
    C --> H{"¿Disponible ≤ punto de reorden?"}
    G --> H
    H -- Sí --> I["Cantidad sugerida = demanda L+R + SS − disponible<br/>Inversión = cantidad × último costo"]
    H -- No --> J["Sin compra · revisar sobrestock si la cobertura supera ampliamente L+R"]
    I --> K["Riesgo: P(demanda en L &gt; disponible), días de cobertura,<br/>fecha estimada de quiebre → low / medium / high / critical"]
    K --> L[("recommendations + alerts stockout_risk")]
    L --> M["Usuario revisa: acknowledged / dismissed / ordered<br/>Predictily NO crea pedidos ni compromete fondos"]
```

## 7. Simulación de escenarios (determinista)

```mermaid
sequenceDiagram
    autonumber
    actor U
    participant SPA
    participant API
    participant DB

    U->>SPA: Ajusta supuestos (precio, descuento, costo, demanda %, plazo, presupuesto)
    SPA->>API: POST /scenarios/simulate
    API->>DB: Pronóstico vigente (yhat, precio supuesto) + últimos costos + recomendaciones
    API->>API: Base: unidades, ingresos, costo, margen, necesidad de compra
    API->>API: Escenario: unidades × (1 + demanda%) · precio × (1 + Δprecio) × (1 − desc.) · costo × (1 + Δcosto)
    API->>API: Riesgos: margen negativo, presupuesto insuficiente, quiebre, costo desconocido
    API-->>SPA: {type: "simulation", disclaimer, base, scenario, delta, budget, risks}
    SPA-->>U: Muestra diferencias y aviso:<br/>"Simulación basada en supuestos, no es predicción ni estimación causal"
```

## 8. Exportación y eliminación de datos (LOPDP)

```mermaid
sequenceDiagram
    autonumber
    actor O as Propietario
    participant SPA
    participant API
    participant Q as Worker
    participant FS
    participant DB

    O->>SPA: Exportar todos los datos
    SPA->>API: POST /tenant/export (ability tenant.export_all)
    API->>DB: audit_logs (data.export_requested)
    API->>Q: ExportTenantData
    Q->>DB: Lee todas las tablas del tenant (por bloques)
    Q->>FS: ZIP de CSV + manifiesto en tenants/{id}/exports/ (enlace temporal firmado)
    SPA->>API: GET /tenant/export/{id} → download_url

    O->>SPA: Eliminar empresa (confirma nombre)
    SPA->>API: DELETE /tenant (ability tenant.delete)
    API->>DB: tenants.status = pending_deletion, deletion_requested_at
    API->>DB: audit_logs (tenant.deletion_requested)
    Note over API,DB: Período de gracia de 30 días (acceso bloqueado, 423)
    participant S as Scheduler
    S->>Q: predictily:purge (diario)
    Q->>FS: Borra archivos de importación, exportaciones y artefactos de modelos del tenant
    Q->>DB: DELETE tenants → cascada sobre todas las tablas con tenant_id
    Note over Q,DB: Las copias de seguridad expiran por rotación (≤ BACKUP_RETENTION_DAYS)
```
