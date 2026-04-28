# Guía de contribución

Este documento es para cualquiera que vaya a tocar este código: equipo interno, contratistas, futuros tú.

## Índice

1. [Setup local](#setup-local)
2. [Branching strategy](#branching-strategy)
3. [Convenciones de commits](#convenciones-de-commits)
4. [Pull requests](#pull-requests)
5. [Estilo de código](#estilo-de-código)
6. [Tests](#tests)
7. [Migraciones](#migraciones)
8. [ADRs](#adrs)
9. [Seguridad](#seguridad)

---

## Setup local

Ver [`README.md`](README.md). En resumen:

```bash
cp .env.example .env
make init
```

Si todo va bien tendrás la stack arriba en menos de 5 minutos.

---

## Branching strategy

Usamos un Git Flow simplificado:

- `main` — código de producción. Solo recibe merges desde `release/*` o `hotfix/*`. Cada commit en `main` es una versión.
- `develop` — integración continua. PRs de features apuntan aquí.
- `feature/<scope>-<descripcion-corta>` — nueva funcionalidad. Branchea de `develop`.
- `fix/<issue>-<descripcion>` — bug fix no urgente. Branchea de `develop`.
- `hotfix/<descripcion>` — bug crítico en producción. Branchea de `main`, mergea a `main` y a `develop`.
- `release/<version>` — preparación de release. Branchea de `develop`.
- `chore/<descripcion>` — tareas de mantenimiento sin cambio funcional.

Ramas se eliminan tras merge.

---

## Convenciones de commits

[Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope opcional>): <descripción imperativa, en minúscula, sin punto>

[cuerpo opcional explicando qué y por qué, no cómo]

[footer opcional: BREAKING CHANGE, refs #N, etc]
```

Tipos permitidos: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `chore`, `ci`, `build`, `revert`.

### Ejemplos buenos

```
feat(catalog): agregar búsqueda por código de barras alterno

fix(sales): evitar duplicación al sincronizar ventas con misma idempotency key

refactor(inventory): extraer cálculo de costo promedio a service

docs(adr): agregar ADR-0007 sobre estrategia de cache de catálogo

chore(deps): actualizar laravel/framework a 11.36
```

### Ejemplos malos

```
WIP                          # no descriptivo
arregle el bug               # no usa formato
update                       # vacío
feat: muchas cosas a la vez  # mezcla varios temas
```

Un commit = un cambio coherente. Si tu commit tiene "y", probablemente son dos.

---

## Pull requests

### Tamaño

- **Pequeños** (< 400 líneas modificadas): se revisan en minutos.
- **Medianos** (400–800): tolerable si son inevitables.
- **Grandes** (> 800): partir si es posible. Requieren contexto extra del autor.

### Flujo

1. Crear branch desde `develop` (o `main` para hotfix).
2. Trabajar, commitear con mensajes claros.
3. Antes de abrir PR: rebase contra base, ejecutar `make lint test` localmente.
4. Abrir PR contra `develop`. Llenar la plantilla (`.github/PULL_REQUEST_TEMPLATE.md`) completa.
5. Esperar CI verde.
6. Pedir review. Mínimo 1 aprobación.
7. Merge: **squash** por defecto (un commit limpio en `develop`); merge commit solo para releases.
8. Eliminar branch.

### Code review

Como reviewer:

- Foco en: lógica, seguridad, multi-tenancy, casos límite, tests.
- Aprueba si: el código resuelve el problema, no introduce regresión, tiene tests, es razonablemente claro.
- Bloquea si: hay bugs lógicos, riesgos de seguridad, falta de tests críticos, código incomprensible.
- Comenta sin bloquear si: hay opiniones de estilo o mejoras posibles pero no esenciales.

Como autor:

- Responde a cada comentario (resuelto, agregado, "no de acuerdo porque…").
- No te lo tomes personal. La revisión mejora el producto.

---

## Estilo de código

### PHP / Laravel

- **PSR-12**, enforced por [Laravel Pint](https://laravel.com/docs/pint).
- **PHPStan nivel 8** obligatorio.
- Strict types: `declare(strict_types=1);` en cada archivo nuevo.
- Type hints siempre, return types siempre.
- Sin `mixed` salvo excepciones justificadas.
- DocBlocks solo cuando agregan información que el tipo no expresa.
- Una clase por archivo.
- Naming:
  - Clases: `PascalCase`.
  - Métodos / variables: `camelCase`.
  - Constantes: `SCREAMING_SNAKE_CASE`.
  - Tablas / columnas: `snake_case` plural / singular respectivamente.
  - UUIDs en URL/API; `id` interno solo en BD.
- Estructura preferida (DDD ligero):
  ```
  app/
  ├── Domain/<Context>/
  │   ├── Models/
  │   ├── Services/
  │   ├── Events/
  │   ├── Exceptions/
  │   └── ValueObjects/
  ├── Http/
  │   ├── Controllers/
  │   ├── Requests/
  │   ├── Resources/
  │   └── Middleware/
  ├── Jobs/
  └── Policies/
  ```

### TypeScript / Vue

- **Strict mode** sin excepciones.
- Composition API con `<script setup>`.
- Stores con Pinia.
- ESLint + Prettier configurados; CI verifica.
- Naming:
  - Componentes: `PascalCase.vue`.
  - Composables: `useXxx.ts`.
  - Stores: `xxxStore.ts` o por slice: `auth.ts`, `cart.ts`.
  - Tipos: `PascalCase` (`Product`, `SaleItem`).
- Sin lógica de negocio en componentes; va a stores o servicios.

### SQL / migraciones

- `snake_case`.
- Plurales para tablas (`products`, no `product`).
- Singular para columnas FK (`product_id`).
- Cada FK con índice.
- Cada tabla tenant-aware con `company_id` + RLS (ver ADR-0006).

---

## Tests

> Sin tests, sin merge. Sin excepciones.

### Coverage mínimo

- Lógica de negocio (Services, Domain): 90%.
- Modelos: 70%.
- Controllers: 60% (resto cubierto en feature tests).
- Frontend stores/composables: 80%.

### Cómo correrlos

```bash
make test                # todo
make test-backend
make test-frontend
make test-coverage       # con reporte de coverage
```

### Tipos de tests

- **Unit**: clase aislada, dependencias mockeadas.
- **Feature** (Laravel): endpoint completo con BD real (en transacción).
- **E2E** (Cypress): flujo de usuario completo en un browser real.

### Multi-tenant

Cada feature tiene un test de aislamiento explícito que verifica que el tenant A no puede ver/tocar datos del tenant B.

---

## Migraciones

- **Forward-only** salvo durante desarrollo.
- **Backwards compatible**: nunca rompas la versión anterior con una migración.
- Estrategia "expand and contract" para cambios destructivos:
  1. Migración agrega columna nueva (sin breaking).
  2. Deploy de código que escribe en ambas (vieja y nueva).
  3. Backfill de datos.
  4. Deploy de código que solo usa la nueva.
  5. Migración elimina la vieja (en release siguiente).
- Tablas tenant-aware: agregar `company_id` + índice + política RLS (ver ADR-0006).
- Probadas en CI sobre BD limpia.

---

## ADRs

Cuando tomes una decisión arquitectónica significativa, documéntala como ADR (Architecture Decision Record).

- Plantilla: [`docs/adr/_template.md`](docs/adr/_template.md).
- Numeración secuencial.
- Estado `Proposed` → review → `Accepted`.
- Cuando una ADR cambia, no se modifica: se crea una nueva que `Supersede` la anterior.

¿Cuándo escribir una?

- Cambio de stack o framework.
- Decisión de arquitectura cross-componente.
- Patrón nuevo que el resto del equipo debe seguir.
- Trade-off importante con alternativas reales.

---

## Seguridad

- **Nunca** secretos en código. Si encuentras uno, rotalo y elimínalo del histórico (`git filter-repo`).
- `.env` jamás en git.
- Pre-commit con [`gitleaks`](https://github.com/gitleaks/gitleaks) recomendado.
- Reportes de vulnerabilidades: por canal privado al equipo de seguridad.
- Auditoría de dependencias en cada CI run (`composer audit`, `npm audit`).
- PRs que tocan auth, autorización, criptografía o pagos requieren review adicional de un senior.

---

## Cuando dudes

- ¿Cómo se hace X? → revisa el documento maestro: [`docs/POS_MAESTRO_v3.md`](docs/POS_MAESTRO_v3.md).
- ¿Por qué se decidió Y? → revisa los ADRs: [`docs/adr/`](docs/adr/).
- Si no está documentado, pregunta. Y luego documenta.
