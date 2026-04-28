# POS Enterprise

> Sistema POS multi-tenant, multi-sucursal, **offline-first**.
>
> Stack: Laravel 11 · PostgreSQL 16 · Vue 3 + TypeScript · Redis 7 · Docker.

Documento maestro de arquitectura y fases: [`docs/POS_MAESTRO_v3.md`](docs/POS_MAESTRO_v3.md).

---

## Estructura del repositorio

```
pos-enterprise/
├── backend/              # API Laravel 11
├── frontend/             # PWA Vue 3 + TypeScript (PrintBridge cliente, etc.)
├── docker/               # Dockerfiles y configs por servicio
│   ├── nginx/            # Reverse proxy + sirviendo PHP-FPM
│   ├── php/              # PHP 8.3 FPM con extensiones POS
│   ├── postgres/         # Init scripts (schemas, RLS)
│   └── redis/            # Configuración hardened
├── docs/                 # Documentación viva
│   ├── POS_MAESTRO_v3.md # Documento maestro (referencia obligada)
│   ├── adr/              # Architecture Decision Records
│   └── runbooks/         # Procedimientos operativos
├── scripts/              # Utilidades de desarrollo y operación
├── .github/              # CI/CD, plantillas de issues y PRs
├── docker-compose.yml    # Orquestación de desarrollo local
├── Makefile              # Comandos comunes (make help)
├── .env.example          # Variables de entorno de ejemplo
└── README.md
```

---

## Requisitos del entorno de desarrollo

Validados en Linux nativo (Ubuntu 22.04+ / Fedora 39+ / Arch).

| Componente   | Versión mínima | Comando de verificación      |
|--------------|----------------|------------------------------|
| Docker       | 24.0           | `docker --version`           |
| Docker Compose plugin | 2.20  | `docker compose version`     |
| Make         | 4.x            | `make --version`             |
| Git          | 2.40           | `git --version`              |
| jq           | 1.6            | `jq --version`               |
| (opcional) Node | 22          | `node --version`             |
| (opcional) PHP  | 8.3         | `php --version`              |

> Node y PHP locales solo se requieren si se ejecutan herramientas fuera de Docker (linters de IDE, generación de tipos, etc.). Todo el desarrollo puede correr 100% dentro de los contenedores.

---

## Setup inicial (primer arranque)

```bash
git clone <repo-url> pos-enterprise
cd pos-enterprise

# 1. Copiar variables de entorno
cp .env.example .env

# 2. Levantar la stack completa
make up

# 3. Inicializar el backend (composer + key + migraciones + seeders)
make init

# 4. Verificar
curl http://localhost:8080/api/health/live
# → {"status":"ok"}
```

URLs locales después del setup:

| Servicio          | URL                          | Notas                         |
|-------------------|------------------------------|-------------------------------|
| API Laravel       | http://localhost:8080        | Backend principal             |
| Frontend Vue      | http://localhost:5173        | Dev server con HMR            |
| MailHog           | http://localhost:8025        | Captura emails en desarrollo  |
| Reverb (WS)       | ws://localhost:8081          | Eventos en tiempo real        |
| Postgres          | localhost:5432               | usuario/pass en .env          |
| Redis             | localhost:6379               | password en .env              |

---

## Comandos frecuentes

Todo está en el Makefile, ejecuta `make help` para verlo.

```bash
make up              # Levanta toda la stack
make down            # Detiene toda la stack
make logs            # Sigue los logs
make shell           # Bash dentro del contenedor de la app
make psql            # Cliente de Postgres
make redis-cli       # Cliente de Redis
make test            # Suite completa de tests
make lint            # Linters + análisis estático
make fresh           # Re-crea la BD desde cero con seeders
```

---

## Convenciones del proyecto

- **Idioma de código**: inglés (identificadores, comentarios técnicos).
- **Idioma de UI / mensajes de usuario**: español como default (i18n soportado).
- **Branching**: `main` (estable) ← `develop` ← `feature/*`, `fix/*`, `chore/*`.
- **Conventional Commits**: `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`, `ci:`.
- **PRs**: requieren CI verde + 1 aprobación + checklist completo.
- **PHP**: PSR-12 con Laravel Pint. PHPStan nivel 8 obligatorio.
- **TypeScript**: strict mode. ESLint + Prettier.
- **Tests**: nuevo código viene con tests. Sin excepciones.

Detalle completo en [`CONTRIBUTING.md`](CONTRIBUTING.md).

---

## Estado del proyecto

| Fase | Nombre                          | Estado        |
|------|---------------------------------|---------------|
| 0    | Discovery y Setup               | 🚧 En curso   |
| 1    | MVP Core                        | ⏳ Pendiente  |
| 2    | Offline-first                   | ⏳ Pendiente  |
| 3    | Multi-sucursal y multi-caja     | ⏳ Pendiente  |
| ...  | (ver documento maestro)         | ⏳ Pendiente  |

---

## Licencia

Propietaria. Todos los derechos reservados.
