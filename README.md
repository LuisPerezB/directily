# Predictily

**Anticipa tus ventas. Optimiza tus decisiones.**

Predictily es una plataforma SaaS B2B de inteligencia comercial predictiva para pequeñas y
medianas empresas de Ecuador (con arquitectura lista para Latinoamérica). Convierte la
información comercial existente —ventas, precios, inventario, plazos de proveedores— en
pronósticos de demanda e ingresos, alertas de inventario y sugerencias de abastecimiento
verificables.

> Predictily **no reemplaza** el ERP, POS ni el sistema contable: se integra con ellos (CSV/Excel,
> API REST, conectores) como una capa independiente de inteligencia predictiva.

Moneda USD · zona horaria `America/Guayaquil` · interfaz en español (preparada para i18n).
El dominio `predictily.com` es solo una referencia: su disponibilidad debe verificarse.

---

## Arquitectura en breve

```
Navegador ─► nginx (web) ─► SPA Vue 3 + TypeScript
                 └─ /api, /sanctum ─► Laravel 13 (API pública, auth, multi-tenant, importación)
                                          │  colas Redis (imports · forecasting · default)
                                          ▼
                                   Workers Laravel ──HTTP interno (Bearer)──► Motor Python (FastAPI)
                                          │                                       │
                                          └──────────────► PostgreSQL 16 ◄────────┘
```

| Carpeta | Componente | Tecnología |
|---|---|---|
| `backend/` | API pública, autenticación, roles, aislamiento multi-tenant, importación y validación, orquestación, simulador, exportaciones, auditoría | Laravel 13 · PHP 8.3 · Sanctum · OpenSpout |
| `engine/` | Motor predictivo interno: perfilado, características (calendario de Ecuador), backtesting rolling-origin, selección de modelos, intervalos, reconciliación, inventario, precisión | Python 3.12 · FastAPI · StatsForecast · MLForecast + LightGBM · scikit-learn |
| `frontend/` | SPA: landing, onboarding, asistente de importación, dashboard, pronósticos, inventario, precisión, simulador | Vue 3 · TypeScript · Vite · Pinia · ECharts · Tailwind |
| `data/demo/` | Datasets **ficticios** reproducibles (semilla fija) de dos empresas demo | CSV |
| `docs/` | Requisitos, arquitectura, modelo de datos, flujos, contratos, seguridad, backlog, manuales | Markdown + Mermaid |
| `infra/` | nginx, Caddy (TLS), inicialización de PostgreSQL, respaldos y despliegue | — |

Los pronósticos provienen de un pipeline real (modelos estadísticos y de machine learning
evaluados contra un baseline). No se usa IA generativa para calcularlos.

---

## Requisitos

**Con Docker (recomendado):** Docker Engine 24+ y Docker Compose v2.24+. Servidor sugerido:
4 vCPU, 8–16 GB RAM, 40 GB de disco. **No requiere GPU.**

**Nativo (desarrollo):** PHP 8.3 (extensiones `pdo_pgsql`, `redis`, `intl`, `zip`, `gd`,
`mbstring`, `xml`, `bcmath`, `pcntl`), Composer 2, PostgreSQL 16, Redis 7, Python 3.12,
Node.js 22.

---

## Instalación con Docker

```bash
git clone <repositorio> predictily && cd predictily
make setup          # crea .env con APP_KEY, contraseñas y token interno aleatorios
make up             # construye y levanta: postgres, redis, backend, queue, queue-forecasting,
                    # scheduler, engine y web
make seed           # planes y datos base
make demo           # (opcional) 2 empresas DEMO con datos ficticios + primer pronóstico
```

Abrir **http://localhost:8080**. El servicio `backend` ejecuta las migraciones al arrancar.

Sin `make`: `cp .env.example .env`, completar `APP_KEY` (`openssl rand -base64 32`, con prefijo
`base64:`), `DB_PASSWORD`, `REDIS_PASSWORD`, `ENGINE_API_TOKEN` y luego `docker compose up -d --build`.

Servicios y red:

| Servicio | Rol | Expuesto |
|---|---|---|
| `web` | nginx: SPA + proxy FastCGI a php-fpm | `:8080` |
| `backend` | php-fpm (API) + migraciones al iniciar | interno |
| `queue` | worker de importaciones y tareas generales | interno |
| `queue-forecasting` | worker de pronósticos (llama al motor) | interno |
| `scheduler` | evaluación diaria vs reales, pronósticos programados, purgas | interno |
| `engine` | motor FastAPI | **solo red interna** |
| `postgres`, `redis` | datos, colas, caché, sesiones | **solo red interna** |

### Producción en un VPS (TLS automático)

```bash
# .env: APP_ENV=production, PREDICTILY_DOMAIN=app.su-dominio.com, ACME_EMAIL=...
make up-prod        # docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Caddy obtiene certificados de Let's Encrypt y fuerza HTTPS. Guía completa, firewall, respaldos
y actualización: [`infra/scripts/deploy.md`](infra/scripts/deploy.md).

---

## Instalación nativa (desarrollo)

```bash
# 1) Base de datos y Redis locales
sudo -u postgres psql -c "CREATE ROLE predictily LOGIN PASSWORD 'secret' CREATEDB;"
sudo -u postgres createdb -O predictily predictily
sudo -u postgres createdb -O predictily predictily_test          # pruebas backend
sudo -u postgres createdb -O predictily predictily_test_b2       # pruebas backend (analytics)
sudo -u postgres createdb -O predictily predictily_engine_test   # pruebas del motor

# 2) Todo de una vez (composer, migraciones, venv del motor, npm)
make native-setup
```

Por componente:

```bash
# Backend
cd backend && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed

# Motor
cd engine && python3.12 -m venv .venv && .venv/bin/pip install -e '.[dev]'
export ENGINE_DATABASE_URL=postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily
export ENGINE_API_TOKEN=<mismo valor que backend/.env>   # ver engine/README.md

# Frontend
cd frontend && npm ci
```

Levantar en terminales separadas:

```bash
make dev-backend     # Laravel   → http://127.0.0.1:8000
make dev-queue       # worker de colas
make dev-engine      # motor     → http://127.0.0.1:8100/health
make dev-frontend    # SPA Vite  → http://localhost:5173 (proxy /api y /sanctum → :8000)
make dev-scheduler   # opcional
```

`ENGINE_API_TOKEN` debe ser el mismo en `backend/.env` y en el entorno del motor.

---

## Datos de demostración y primer pronóstico

Los datos de `data/demo/` son **completamente ficticios**, generados con semilla fija
(`make demo-data` los regenera): *Ferretería Andina (Demo ficticia)* y *Distribuidora Costa
(Demo ficticia)*, con sucursales en distintas provincias, estacionalidad semanal, quincenas,
feriados de Ecuador, décimos, promociones, quiebres de stock, devoluciones y facturas anuladas.

```bash
make demo           # Docker   (o: make demo-native)
# equivalente: php artisan predictily:demo --forecast   (--fresh recrea, --only=ferreteria_andina)
```

Crea ambas empresas con su usuario demo (ver `backend/config/predictily.php`), importa los
archivos mediante el mismo asistente de importación que usaría un cliente y solicita el
pronóstico. También puede hacerse desde la interfaz: *Onboarding → Cargar datos de demostración*.

Recorrido manual sugerido:
1. Registrarse y crear una empresa; agregar sucursales.
2. *Importar datos* → subir `data/demo/ferreteria_andina_sales.csv` → revisar mapeo → validar
   → revisar errores (`*_sales_with_errors.csv` sirve para probar la corrección) → confirmar.
3. Importar inventario y plazos (`*_inventory.csv`, `*_lead_times.csv`).
4. *Pronósticos → Generar pronóstico* y seguir el progreso.
5. Revisar Dashboard, Productos, Inventario y abastecimiento, Historial de precisión y Simulador.
6. Reimportar el mismo archivo: el resumen debe indicar 0 documentos nuevos (sin duplicados).

Ejecutar un pronóstico por consola: `php artisan predictily:forecast <tenant-uuid>`.

---

## Pruebas

```bash
make test            # backend + motor + frontend (nativo)
make test-backend    # cd backend && php artisan test            (BD predictily_test)
make test-engine     # cd engine && .venv/bin/python -m pytest
make test-frontend   # cd frontend && npx vitest run && npm run type-check
make lint
make compose-check   # valida docker-compose.yml y docker-compose.prod.yml
```

Las pruebas cubren aislamiento multi-tenant, permisos, importación y deduplicación, validación,
entrenamiento y pronóstico, prevención de fuga temporal, comparación contra baselines,
reproducibilidad, procesamiento asíncrono y seguridad de endpoints. Detalle por componente en
`docs/00-registro-avance.md`.

---

## Operación

| Tarea | Comando |
|---|---|
| Respaldo de PostgreSQL | `make backup` (cron diario sugerido; rotación `BACKUP_RETENTION_DAYS`) |
| Prueba de restauración | `make restore-verify` |
| Logs | `make logs S=engine` |
| Migraciones | `make migrate` |

---

## Documentación

| Documento | Contenido |
|---|---|
| [00 · Registro de avance](docs/00-registro-avance.md) | Qué está implementado, probado y pendiente |
| [01 · Requisitos](docs/01-requisitos.md) | Funcionales, no funcionales, criterios de aceptación del MVP |
| [02 · Arquitectura](docs/02-arquitectura.md) | Componentes y decisiones (ADR) |
| [03 · Modelo de datos](docs/03-modelo-datos.md) | Diagramas ER, índices, integridad multi-tenant |
| [04 · Estrategia de modelado](docs/04-estrategia-modelado.md) | Entrenamiento, validación y selección de modelos |
| [05 · Flujos](docs/05-flujos.md) | Diagramas de secuencia de los recorridos clave |
| [06 · Contratos](docs/06-contratos-integracion.md) | API pública, API interna del motor, formatos de importación |
| [07 · Seguridad y privacidad](docs/07-seguridad-multitenant.md) | Aislamiento, roles, cifrado, LOPDP, respaldos |
| [08 · Backlog](docs/08-backlog.md) | Historias priorizadas por fase |
| [Pendientes](docs/pendientes.md) | Dependencias externas y funcionalidades futuras |
| [OpenAPI](docs/openapi/) | Especificaciones de la API pública y del motor |
| [Despliegue](infra/scripts/deploy.md) | VPS, TLS, firewall, respaldos |

---

## Estructura del repositorio

```
predictily/
├── backend/              Laravel 13 (app/, database/migrations, routes/api/*, tests/)
│   ├── Dockerfile        imagen única para api, colas y scheduler
│   └── docker/           php.ini, php-fpm, entrypoint
├── engine/               motor Python (predictily_engine/, tests/, Dockerfile)
├── frontend/             SPA Vue 3 (src/, Dockerfile → nginx)
├── data/demo/            datasets ficticios
├── docs/                 documentación y OpenAPI
├── infra/
│   ├── nginx/            configuración del servicio web y cabeceras de seguridad
│   ├── caddy/            proxy TLS de producción
│   ├── postgres/init/    creación de bases adicionales
│   └── scripts/          backup.sh, restore.sh, deploy.md
├── docker-compose.yml
├── docker-compose.prod.yml
├── .env.example
└── Makefile
```

## Licencia

Software propietario. Todos los derechos reservados.
