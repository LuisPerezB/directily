# ─────────────────────────────────────────────────────────────────────────────
# Predictily · tareas comunes
#   make help
# Docker:  make setup && make up && make demo
# Nativo:  make native-setup && (en terminales separadas) make dev-backend dev-queue dev-engine dev-frontend
# ─────────────────────────────────────────────────────────────────────────────
SHELL := /bin/bash
.DEFAULT_GOAL := help

COMPOSE      ?= docker compose
COMPOSE_PROD ?= docker compose -f docker-compose.yml -f docker-compose.prod.yml
PHP          ?= php
PYTHON       ?= python3.12
ENGINE_VENV  ?= engine/.venv
ENGINE_PY    := $(ENGINE_VENV)/bin/python

.PHONY: help
help: ## Muestra esta ayuda
	@grep -hE '^[a-zA-Z0-9_.-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

# ── Docker Compose ───────────────────────────────────────────────────────────
.PHONY: setup
setup: ## Crea .env desde .env.example y genera APP_KEY y tokens aleatorios
	@test -f .env || { cp .env.example .env; echo "creado .env"; }
	@grep -q '^APP_KEY=base64:' .env || sed -i "s|^APP_KEY=.*|APP_KEY=base64:$$(openssl rand -base64 32)|" .env
	@grep -q '^ENGINE_API_TOKEN=cambiar' .env && sed -i "s|^ENGINE_API_TOKEN=.*|ENGINE_API_TOKEN=$$(openssl rand -hex 32)|" .env || true
	@grep -q '^DB_PASSWORD=cambiar' .env && sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$$(openssl rand -hex 16)|" .env || true
	@grep -q '^REDIS_PASSWORD=cambiar' .env && sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$$(openssl rand -hex 16)|" .env || true
	@echo "Listo. Revise .env antes de 'make up'."

.PHONY: build
build: ## Construye las imágenes
	$(COMPOSE) build

.PHONY: up
up: ## Levanta todos los servicios (http://localhost:8080)
	$(COMPOSE) up -d --build
	@echo "Predictily: $$(grep -E '^APP_URL=' .env | cut -d= -f2-)"

.PHONY: up-prod
up-prod: ## Levanta en producción con TLS automático (Caddy)
	$(COMPOSE_PROD) up -d --build

.PHONY: down
down: ## Detiene los servicios (conserva volúmenes)
	$(COMPOSE) down

.PHONY: ps
ps: ## Estado de los servicios
	$(COMPOSE) ps

.PHONY: logs
logs: ## Logs en vivo (make logs S=engine para un servicio)
	$(COMPOSE) logs -f --tail=200 $(S)

.PHONY: migrate
migrate: ## Ejecuta migraciones en el contenedor backend
	$(COMPOSE) exec backend php artisan migrate --force

.PHONY: seed
seed: ## Carga planes y datos base (seeders)
	$(COMPOSE) exec backend php artisan db:seed --force

.PHONY: demo
demo: ## Crea las 2 empresas DEMO (datos ficticios), importa sus datos y solicita pronósticos
	$(COMPOSE) exec backend php artisan predictily:demo --forecast

.PHONY: shell
shell: ## Shell en el contenedor backend
	$(COMPOSE) exec backend sh

.PHONY: backup
backup: ## Respaldo de PostgreSQL (infra/scripts/backup.sh)
	infra/scripts/backup.sh

.PHONY: restore-verify
restore-verify: ## Prueba de restauración del último respaldo en una base temporal
	infra/scripts/restore.sh --verify "$$(ls -1t $${BACKUP_DIR:-backups}/*.dump* | grep -v sha256 | head -1)"

# ── Desarrollo nativo (PostgreSQL y Redis locales) ───────────────────────────
.PHONY: native-setup
native-setup: ## Instala dependencias de backend, motor y frontend en modo nativo
	cd backend && composer install && (test -f .env || cp .env.example .env) && $(PHP) artisan key:generate --no-interaction && $(PHP) artisan migrate --force && $(PHP) artisan db:seed --force
	test -d $(ENGINE_VENV) || $(PYTHON) -m venv $(ENGINE_VENV)
	$(ENGINE_PY) -m pip install --upgrade pip && $(ENGINE_PY) -m pip install -e './engine[dev]'
	cd frontend && npm ci

.PHONY: dev-backend
dev-backend: ## API Laravel en :8000
	cd backend && $(PHP) artisan serve --host=127.0.0.1 --port=8000

.PHONY: dev-queue
dev-queue: ## Worker de colas (imports, forecasting, default)
	cd backend && $(PHP) artisan queue:work redis --queue=imports,forecasting,default --tries=2 --timeout=1000 --memory=1024

.PHONY: dev-scheduler
dev-scheduler: ## Programador de tareas
	cd backend && $(PHP) artisan schedule:work

.PHONY: dev-engine
dev-engine: ## Motor FastAPI en :8100 (recarga automática)
	cd engine && .venv/bin/uvicorn predictily_engine.api.main:app --host 127.0.0.1 --port 8100 --reload

.PHONY: dev-frontend
dev-frontend: ## SPA Vite en :5173 (proxy /api → :8000)
	cd frontend && npm run dev

.PHONY: demo-native
demo-native: ## Datos DEMO ficticios en modo nativo
	cd backend && $(PHP) artisan predictily:demo --forecast

.PHONY: demo-data
demo-data: ## Regenera los CSV ficticios de data/demo (semilla fija)
	cd engine && .venv/bin/python -m predictily_engine.cli generate-demo --out ../data/demo

# ── Pruebas y calidad ────────────────────────────────────────────────────────
.PHONY: test
test: test-backend test-engine test-frontend ## Ejecuta todas las pruebas (nativo)

.PHONY: test-backend
test-backend: ## Pruebas Laravel (BD predictily_test)
	cd backend && $(PHP) artisan test

.PHONY: test-engine
test-engine: ## Pruebas del motor (pytest)
	cd engine && .venv/bin/python -m pytest -q

.PHONY: test-frontend
test-frontend: ## Pruebas del frontend (vitest) y verificación de tipos
	cd frontend && npx vitest run && npm run type-check

.PHONY: lint
lint: ## Linters: pint (PHP), ruff (Python), eslint (TS/Vue)
	cd backend && vendor/bin/pint --test
	cd engine && .venv/bin/python -m ruff check . || echo "ruff no instalado: pip install ruff"
	cd frontend && npm run lint

.PHONY: compose-check
compose-check: ## Valida la sintaxis de los archivos compose
	APP_KEY=base64:verificacion $(COMPOSE) --env-file .env.example config -q
	APP_KEY=base64:verificacion PREDICTILY_DOMAIN=app.example.com ACME_EMAIL=ops@example.com $(COMPOSE_PROD) --env-file .env.example config -q
	@echo "docker-compose.yml y docker-compose.prod.yml OK"
