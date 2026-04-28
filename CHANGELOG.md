# Changelog

Todos los cambios notables del proyecto se documentan aquí.

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado: [Semantic Versioning](https://semver.org/lang/es/).

---

## [Unreleased]

### Fase 0 — Discovery y Setup

#### Added

- Estructura de monorepo (`backend/`, `frontend/`, `docs/`, `docker/`, `scripts/`, `.github/`).
- `docker-compose.yml` para entorno de desarrollo local con: PHP 8.3 FPM, Nginx, Postgres 16, Redis 7, Reverb (WebSockets), worker de queue, scheduler, frontend Vite, MailHog.
- `Dockerfile` multi-stage para backend (development y production).
- Makefile con comandos comunes (`make help`).
- `.env.example` con todas las variables necesarias.
- CI/CD en GitHub Actions: lint, tests backend, tests frontend, security scan.
- Pipeline separado para build y push de imágenes Docker.
- Plantillas de PR y de issues (bug, feature).
- ADRs iniciales:
  - ADR-0001: Stack tecnológico base.
  - ADR-0002: Monorepo.
  - ADR-0003: Multi-tenancy pool por defecto, silo opcional.
  - ADR-0004: Cliente offline-first como PWA.
  - ADR-0005: UUIDs como identificadores externos.
  - ADR-0006: Row Level Security en Postgres.
- Documento maestro v3.0 (`docs/POS_MAESTRO_v3.md`).
- `CONTRIBUTING.md` con convenciones del proyecto.
- `.editorconfig` para uniformidad entre editores.
- Script de init de Postgres con extensiones, schemas auxiliares y función `current_tenant_id()`.
- Configuración hardened de Redis (RDB + AOF, comandos peligrosos renombrados).
- Configuración de Nginx con logging JSON y headers de seguridad.

---

## Convenciones de versionado del producto

- **MAJOR**: cambios breaking en API pública o en formato de datos persistido.
- **MINOR**: nueva funcionalidad backwards-compatible.
- **PATCH**: bug fixes y mejoras menores backwards-compatible.

Cada release de fase termina con una versión numerada (`v0.1.0` para Fase 0, `v0.2.0` para Fase 1, etc.). La versión `v1.0.0` se reserva para la primera Fase considerada GA pública.
