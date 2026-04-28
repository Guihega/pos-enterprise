# ==============================================================
# POS Enterprise - Makefile
# ==============================================================
# Uso: make <target>
#      make help    para ver todos los comandos disponibles
# ==============================================================

.DEFAULT_GOAL := help
.PHONY: help

# --- Colores para output ---
CYAN   := \033[0;36m
GREEN  := \033[0;32m
YELLOW := \033[0;33m
RED    := \033[0;31m
NC     := \033[0m

DC     := docker compose
APP    := $(DC) exec app
APP_T  := $(DC) exec -T app

# ==============================================================
# Help
# ==============================================================

help: ## Muestra este mensaje de ayuda
	@echo ""
	@echo "$(CYAN)POS Enterprise - Comandos disponibles$(NC)"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""

# ==============================================================
# Setup inicial
# ==============================================================

.PHONY: init
init: ## Setup inicial completo (primer arranque)
	@echo "$(CYAN)→ Verificando .env...$(NC)"
	@test -f .env || (cp .env.example .env && echo "$(GREEN)✓ .env creado desde .env.example$(NC)")
	@echo "$(CYAN)→ Construyendo imágenes...$(NC)"
	$(DC) build
	@echo "$(CYAN)→ Levantando servicios...$(NC)"
	$(DC) up -d
	@echo "$(CYAN)→ Esperando a Postgres...$(NC)"
	@until $(DC) exec -T postgres pg_isready -U pos > /dev/null 2>&1; do sleep 1; done
	@echo "$(CYAN)→ Instalando dependencias del backend...$(NC)"
	$(APP_T) composer install --no-interaction
	@echo "$(CYAN)→ Generando APP_KEY...$(NC)"
	$(APP_T) php artisan key:generate --force
	@echo "$(CYAN)→ Ejecutando migraciones y seeders...$(NC)"
	$(APP_T) php artisan migrate:fresh --seed --force
	@echo ""
	@echo "$(GREEN)✓ Setup completo.$(NC)"
	@echo ""
	@echo "  API:      http://localhost:8080"
	@echo "  Frontend: http://localhost:5173"
	@echo "  MailHog:  http://localhost:8025"
	@echo ""

# ==============================================================
# Stack lifecycle
# ==============================================================

.PHONY: up
up: ## Levanta todos los servicios
	$(DC) up -d
	@echo "$(GREEN)✓ Servicios arriba.$(NC) Logs: make logs"

.PHONY: down
down: ## Detiene todos los servicios
	$(DC) down

.PHONY: restart
restart: ## Reinicia todos los servicios
	$(DC) restart

.PHONY: rebuild
rebuild: ## Reconstruye imágenes desde cero
	$(DC) build --no-cache
	$(DC) up -d

.PHONY: status
status: ## Muestra el estado de los servicios
	$(DC) ps

.PHONY: logs
logs: ## Sigue logs de todos los servicios
	$(DC) logs -f --tail=100

.PHONY: logs-app
logs-app: ## Logs sólo del backend
	$(DC) logs -f --tail=100 app nginx

.PHONY: logs-frontend
logs-frontend: ## Logs sólo del frontend
	$(DC) logs -f --tail=100 frontend

# ==============================================================
# Acceso interactivo a contenedores
# ==============================================================

.PHONY: shell
shell: ## Bash dentro del contenedor de la app
	$(APP) bash

.PHONY: shell-frontend
shell-frontend: ## Shell del contenedor frontend
	$(DC) exec frontend sh

.PHONY: psql
psql: ## Cliente Postgres conectado a la BD
	$(DC) exec postgres psql -U pos -d pos

.PHONY: redis-cli
redis-cli: ## Cliente Redis
	$(DC) exec redis redis-cli -a redis_dev_secret_change_me

# ==============================================================
# Backend: Composer & Artisan
# ==============================================================

.PHONY: composer
composer: ## Composer dentro del contenedor (uso: make composer cmd="require pkg")
	$(APP) composer $(cmd)

.PHONY: artisan
artisan: ## Artisan dentro del contenedor (uso: make artisan cmd="route:list")
	$(APP) php artisan $(cmd)

.PHONY: tinker
tinker: ## Tinker (REPL de Laravel)
	$(APP) php artisan tinker

# ==============================================================
# Base de datos
# ==============================================================

.PHONY: migrate
migrate: ## Ejecuta migraciones pendientes
	$(APP_T) php artisan migrate

.PHONY: migrate-fresh
migrate-fresh: ## Recrea la BD desde cero (DESTRUCTIVO)
	$(APP_T) php artisan migrate:fresh

.PHONY: fresh
fresh: ## Recrea BD + seeders (DESTRUCTIVO)
	$(APP_T) php artisan migrate:fresh --seed --force
	@echo "$(GREEN)✓ Base de datos recreada con seed data.$(NC)"

.PHONY: rollback
rollback: ## Rollback de la última migración
	$(APP_T) php artisan migrate:rollback

.PHONY: seed
seed: ## Ejecuta seeders
	$(APP_T) php artisan db:seed

.PHONY: db-dump
db-dump: ## Genera dump de la BD a backups/
	@mkdir -p backups
	@TIMESTAMP=$$(date +%Y%m%d_%H%M%S); \
	$(DC) exec -T postgres pg_dump -U pos pos | gzip > backups/pos_$$TIMESTAMP.sql.gz; \
	echo "$(GREEN)✓ Dump guardado en backups/pos_$$TIMESTAMP.sql.gz$(NC)"

# ==============================================================
# Calidad: tests, lint, análisis
# ==============================================================

.PHONY: test
test: test-backend test-frontend ## Suite completa de tests

.PHONY: test-backend
test-backend: ## Tests del backend (PHPUnit/Pest)
	$(APP_T) php artisan test --parallel

.PHONY: test-coverage
test-coverage: ## Tests del backend con coverage
	$(APP_T) php artisan test --coverage --min=70

.PHONY: test-frontend
test-frontend: ## Tests del frontend (Vitest)
	$(DC) exec -T frontend npm run test:unit

.PHONY: lint
lint: lint-backend lint-frontend ## Linters de todo el proyecto

.PHONY: lint-backend
lint-backend: ## PHP Pint + PHPStan
	$(APP_T) ./vendor/bin/pint --test
	$(APP_T) ./vendor/bin/phpstan analyze --memory-limit=1G

.PHONY: lint-fix
lint-fix: ## PHP Pint con auto-fix
	$(APP_T) ./vendor/bin/pint

.PHONY: lint-frontend
lint-frontend: ## ESLint + TypeScript check
	$(DC) exec -T frontend npm run lint
	$(DC) exec -T frontend npm run typecheck

.PHONY: lint-frontend-fix
lint-frontend-fix: ## ESLint + Prettier auto-fix
	$(DC) exec -T frontend npm run lint:fix
	$(DC) exec -T frontend npm run format

.PHONY: stan
stan: ## Sólo PHPStan
	$(APP_T) ./vendor/bin/phpstan analyze --memory-limit=1G

.PHONY: security
security: ## Auditoría de dependencias
	$(APP_T) composer audit
	$(DC) exec -T frontend npm audit --audit-level=high

# ==============================================================
# Cache y limpieza
# ==============================================================

.PHONY: cache-clear
cache-clear: ## Limpia todos los caches
	$(APP_T) php artisan optimize:clear

.PHONY: cache-build
cache-build: ## Construye caches (config, route, view)
	$(APP_T) php artisan config:cache
	$(APP_T) php artisan route:cache
	$(APP_T) php artisan view:cache

# ==============================================================
# Limpieza profunda
# ==============================================================

.PHONY: clean
clean: ## Detiene servicios y elimina volúmenes (DESTRUCTIVO)
	@echo "$(RED)⚠  Esto eliminará volúmenes (BD, Redis, node_modules).$(NC)"
	@read -p "¿Continuar? [y/N] " confirm && [ "$$confirm" = "y" ] || exit 1
	$(DC) down -v
	@echo "$(GREEN)✓ Limpieza completa.$(NC)"

.PHONY: clean-all
clean-all: clean ## clean + elimina imágenes
	$(DC) down --rmi local

# ==============================================================
# Workers y servicios auxiliares
# ==============================================================

.PHONY: queue-restart
queue-restart: ## Reinicia el worker de queue (para refrescar código)
	$(DC) restart queue

.PHONY: queue-failed
queue-failed: ## Lista jobs fallidos
	$(APP_T) php artisan queue:failed

.PHONY: queue-retry
queue-retry: ## Reintenta todos los jobs fallidos
	$(APP_T) php artisan queue:retry all

.PHONY: schedule-list
schedule-list: ## Lista las tareas programadas
	$(APP_T) php artisan schedule:list

# ==============================================================
# Documentación API
# ==============================================================

.PHONY: openapi
openapi: ## Genera/regenera el archivo openapi.yaml
	$(APP_T) php artisan l5-swagger:generate
	@echo "$(GREEN)✓ openapi.yaml regenerado.$(NC)"

# ==============================================================
# Producción (placeholder por ahora)
# ==============================================================

.PHONY: build-prod
build-prod: ## Build de imagen de producción
	docker build -f docker/php/Dockerfile --target production -t pos-api:latest .
