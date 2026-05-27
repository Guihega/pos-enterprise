# Contrato OpenAPI

Este directorio guarda el contrato HTTP del backend en formato
[OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.0). El archivo de referencia
es `openapi.yaml`.

## Autoridad del documento

El `openapi.yaml` es el **contrato declarativo** que el backend promete a sus
consumidores (front-end, integradores futuros). Cuando el código y el YAML
discrepan, **el YAML manda como contrato público** y el código debe alinearse,
o bien el cambio debe documentarse en el YAML primero y luego implementarse.

No es generado automáticamente desde anotaciones ni desde tests: se escribe a
mano y se versiona en el repo. La razón está documentada en este README: la
API aún evoluciona, y un contrato escrito describe **lo que el equipo promete**,
no lo que casualmente devuelve el código hoy. Esa diferencia es lo que
captura un contrato útil.

## Versión

La versión del contrato vive en el campo `info.version` del propio
`openapi.yaml` y sigue [SemVer](https://semver.org/lang/es/):

- **PATCH (0.1.0 → 0.1.1):** correcciones de descripción, ejemplos, ortografía.
  No cambia el contrato.
- **MINOR (0.1.0 → 0.2.0):** se añaden endpoints o campos opcionales. Los
  consumidores existentes siguen funcionando.
- **MAJOR (0.x.x → 1.0.0, 1.x.x → 2.0.0):** cambios que rompen consumidores
  existentes (renombrar campos, cambiar tipos, eliminar endpoints). Requiere
  coordinación.

La versión del contrato es **independiente** de la versión de la aplicación
(`config('app.version')`).

## Alcance de la versión 0.1.0

Esta primera versión NO documenta toda la API. Su propósito es:

1. Establecer las piezas estables y reutilizables del contrato: esquemas de
   error, esquemas de seguridad, convenciones de headers (`X-Tenant`,
   `X-Request-Id`).
2. Documentar los endpoints **menos volátiles**, que el resto de la API
   reutiliza: salud y autenticación.

Cubre:

- `GET /health`
- `POST /auth/login`
- `GET /auth/me`
- `POST /auth/logout`
- `POST /auth/logout-all`
- `POST /auth/pin-verify`

Queda **pendiente** (no documentado todavía):

- `GET /tenant` (info pública del tenant).
- Catálogo: products, categories, brands, units, taxes.
- Inventario: warehouses, stocks, movements, adjust, transfer.
- Caja: registers, sessions, movements.
- Clientes: customers.
- Ventas: sales (index, show, store, cancel).
- Admin: users, roles.

Cada módulo se incorporará en commits incrementales del tipo
`docs(api): documenta endpoints de <modulo>` cuando su contrato se estabilice.

## Cómo añadir un nuevo endpoint al contrato

1. Verificar que el endpoint ya está en `routes/api.php` y tiene un
   `FormRequest` con reglas estables (no en cambio activo).
2. Añadir la ruta bajo `paths:` en `openapi.yaml`. Reutilizar los esquemas de
   `components.schemas` (errores, security) en lugar de duplicar.
3. Si el endpoint introduce un nuevo código de error, añadirlo al esquema
   `ErrorEnvelope` y a la lista de `error.code` documentada.
4. Bumpear `info.version` según SemVer.
5. Validar el YAML antes de comitear (ver siguiente sección).

## Validación

Antes de comitear cambios en `openapi.yaml` se debe validar que el documento
sigue siendo OpenAPI válido. Opciones (sin instalar nada permanente):

    # Con npx (requiere Node.js disponible)
    npx @redocly/cli@latest lint backend/docs/openapi/openapi.yaml

    # Con Docker (alternativa sin Node)
    docker run --rm -v "$PWD:/work" redocly/cli lint /work/backend/docs/openapi/openapi.yaml

Ambos comandos deben terminar sin errores.

## Convenciones del proyecto reflejadas en el contrato

- **Envoltura de éxito:** `{ "data": ... }`.
- **Envoltura de error:** `{ "error": { "code", "message", "details", "request_id", "timestamp" } }`.
  Excepción: `ValidationException` (HTTP 422) usa el formato nativo de Laravel
  `{ "message", "errors": {...} }` por compatibilidad con la suite de tests
  existente (ver `bootstrap/app.php`).
- **Tenant:** todo endpoint bajo `/api/v1` (excepto `/health`) requiere el
  header `X-Tenant` con un slug o UUID que resuelva a un Company activo. Si
  no resuelve, el middleware responde `400 TENANT_NOT_RESOLVED` antes de
  cualquier otra capa.
- **Autenticación:** Bearer token de Sanctum (`Authorization: Bearer <token>`).
  El callback `Sanctum::authenticateAccessTokensUsing()` en `AppServiceProvider`
  exige que el `company_id` del dueño del token coincida con el tenant del
  header `X-Tenant`; si no coincide, `401 UNAUTHENTICATED`. Ver ADR-0007 y
  `TenantHttpIsolationTest`.
- **Identificadores en URLs:** UUID, no IDs internos. Las rutas con parámetros
  usan `{uuid}` (ej. `/products/{uuid}`).
- **Trace de requests:** el header opcional `X-Request-Id` enviado por el
  cliente se devuelve en `error.request_id` para correlacionar logs.
