# POS Enterprise - Frontend (PWA)

Cliente web del POS Enterprise. SPA tipada con Vue 3, offline-first
preparada con Dexie (aun no usada), enrutado con Vue Router, estado con
Pinia.

Forma parte de Etapa 3 del plan de trabajo (Frontend PWA - POS online) y
cierra la Fase 1 del roadmap (MVP Core).

## Stack

- **Vue 3.5** + **TypeScript 6** en modo estricto (`strict`, `noUncheckedIndexedAccess`).
- **Vite 8** como bundler / dev server.
- **Vue Router 5** para enrutado.
- **Pinia 3** para estado.
- **Axios** + **Zod** (cliente HTTP tipado, pendiente - pieza 1c).
- **Dexie 4** para almacenamiento local IndexedDB (offline-first, pendiente - Fase 2).
- **Laravel Echo** + **Pusher.js** para realtime sobre Reverb.
- **Vitest** + **@vue/test-utils** + **jsdom** para tests unitarios.
- **oxlint** + **ESLint** + **Prettier** para calidad de codigo.

## Ejecucion

El frontend corre dentro de Docker en el servicio `frontend` del
`docker-compose.yml` del repo. Todos los comandos `npm` se ejecutan dentro
del contenedor.

### Levantar el servicio

Desde la raiz del repo:

    cd ~/Proyectos/pos-enterprise
    docker compose up -d frontend

Dev server en http://localhost:5173.

### Comandos npm dentro del contenedor

    # Dev server (ya corre solo cuando el contenedor esta arriba)
    docker compose exec frontend npm run dev

    # Type-check (vue-tsc en modo build con project references)
    docker compose exec frontend npm run type-check

    # Lint (oxlint primero, luego eslint --fix)
    docker compose exec frontend npm run lint

    # Tests unitarios (vitest --run --passWithNoTests)
    docker compose exec frontend npm run test:unit

    # Build de produccion
    docker compose exec frontend npm run build

    # Format con Prettier
    docker compose exec frontend npm run format

## Estructura

    frontend/src/
    |-- App.vue              # Esqueleto raiz (solo <RouterView />)
    |-- main.ts              # Bootstrap: createApp, Pinia, router
    |-- assets/              # CSS base y recursos estaticos
    |-- router/              # Configuracion de rutas
    |-- views/               # Vistas montadas por el router
    |-- stores/              # Stores de Pinia (auth, sesion, carrito...)
    |-- composables/         # Funciones reutilizables (use*)
    \-- lib/                 # Codigo no-Vue (cliente HTTP, helpers, adapters)

## TypeScript estricto

`tsconfig.app.json` aplica `noUncheckedIndexedAccess` ademas del `strict`
heredado de `@vue/tsconfig/tsconfig.dom.json`. Esto significa:

- `obj[key]` devuelve `T | undefined`, hay que verificar.
- Reglas de `strictNullChecks`, `noImplicitAny`, etc., todas activas.

## Cliente HTTP tipado

Pendiente (pieza 1c). El contrato OpenAPI vive en
`backend/docs/openapi/openapi.yaml` (v0.1.0, validado con Redocly). El
plan es generar tipos TS desde ese contrato y consumirlos con axios.
