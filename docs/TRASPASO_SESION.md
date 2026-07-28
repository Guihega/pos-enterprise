# Traspaso de sesion — POS Enterprise

Documento para arrancar la siguiente sesion de trabajo. **Escrito al cierre; puede estar
desactualizado en detalles. El repo manda sobre este documento, siempre.**

## REGLA CERO

El primer paso de cualquier sesion es pedir:

```
cd ~/Proyectos/pos-enterprise && git log --oneline -5 ; git status -sb
```

No ejecutar ningun plan heredado hasta ver esa salida. Si contradice este documento,
detenerse y reconstruir desde el repo real.

## Estado al cierre

- `main` = **a3adfab**, sincronizado con origin, working tree limpio, sin ramas colgando.
- Suite: **572 passed (1807 assertions)**.
- Ultima migracion: **000045**.
- Historia reciente: a3adfab (#20 reset password) <- e6ed9f8 (#19 docs cierre) <-
  24d34e4 (#18 devoluciones) <- 00dec9c (#17 cierre documental).

### PRs de esta sesion

- **#18** devoluciones basicas CU-CAJ-010: migracion 000044, `SaleReturnService`,
  endpoints `POST/GET /sales/{uuid}/returns`, 7 tests.
- **#19** fila de devoluciones en la matriz de cierre + nota de decision sobre creditos.
- **#20** recuperacion y cambio de password (flujo 57.6): migracion 000045,
  `PasswordResetService`, 3 endpoints, permiso `USER_PASSWORD_RESET`, 6 tests.

## Sin frente abierto

No hay trabajo en curso. Ver `docs/COBERTURA_ALCANCE.md` para los tres huecos pendientes
y el orden sugerido. Ninguno es urgente por decision del usuario.

## METODO DE TRABAJO (innegociable)

- El maestro (`docs/POS_MAESTRO_v3.md`) manda en el QUE; el codigo real manda en el COMO.
  **NUNCA inferir** firmas, columnas, permisos, rutas ni convenciones: pedir grep/sed/cat
  del archivo real antes de escribir codigo que lo consuma.
- El usuario ejecuta los comandos y pega la salida. **Un comando por mensaje**, con orden
  y momento explicitos (si espera salida o no). Combos solo para pasos de bajo riesgo
  donde un fallo intermedio sea inocuo y diagnosticable.
- Creacion de archivos: heredoc Python (`cat > /tmp/x.py << 'PYEOF' ... PYEOF && python3`)
  con `assert not p.exists()` como guard. Contenido PHP como lista de lineas unidas con
  `'\n'.join(lineas)`; lineas con comillas simples internas van en comillas DOBLES de Python.
- Patches: `src.replace` con anclas copiadas byte a byte del archivo real, con
  `assert src.count(ancla) == 1` y guard de 'ya aplicado'. **Validar TODAS las anclas de
  todos los archivos ANTES de escribir cualquiera.** Si un ancla truena por indentacion,
  derivarla del archivo (`len(linea) - len(linea.lstrip())`), no contar espacios a ojo.
- Si un guard dice 'ya aplicado', casi siempre es doble ejecucion: verificar con
  `git status` + `grep` antes de asumir error.
- Flujo por slice: leer maestro -> verificar firmas reales -> migracion (migrate +
  rollback + migrate) -> modelo/service -> test (correr de inmediato) -> Pint -> re-test
  si Pint toca algo -> suite completa -> commit vertical con paths especificos
  (NUNCA `git add .`).
- En cadenas de greps de evidencia usar `;` en vez de `&&`: **un grep vacio es dato**,
  y con `&&` corta la cadena. Agregar `echo EXIT:$?` cuando el vacio sea ambiguo.
  Ojo: tras un pipe a `head`, el exit code es el de `head`.

## LECCIONES NUEVAS DE ESTA SESION

Cinco bugs, todos por inferir en vez de verificar. Corregir estos habitos:

1. **`pint --test` COMPLETO antes de cada push**, no solo sobre los archivos tocados.
   El CI corre `./vendor/bin/pint --test` sobre los 361 archivos, incluyendo `routes/`,
   que queda fuera si solo se pasa `app/` y `tests/`. Fallo el CI del PR #20 por esto.
2. **Despues del terminador `MSGEOF` va comando aparte.** Ni `&&` ni `;` en la linea
   siguiente: bash lo lee como comando nuevo y da error de sintaxis. Paso dos veces.
3. **Agregar un permiso son 3 pasos**, y el docblock de `Permissions.php` los lista:
   (a) declarar la constante, (b) **agregarla a `Permissions::all()`**, que es el catalogo
   que `RoleProvisioner` materializa, (c) asignarla al rol en `Roles.php`. Saltarse (b)
   hace tronar el provisioner con `Undefined array key`.
4. **`password_reset_tokens` tiene PK compuesta `(company_id, email)` sin columna `id`.**
   `$model->save()` falla con `column id does not exist`: Eloquent no puede construir el
   WHERE. Usar query builder con la clave real.
5. **`app.timezone` es `America/Mexico_City`.** `Carbon::now()` escrito en columna
   `timestampTz` guarda el reloj local como si fuera UTC, desfasando 6 horas. Para
   vigencias usar `Carbon::now()->utc()` explicito.
6. **`TenantContext::get()` NO existe**; el metodo es `current(): ?Company`. El lint no
   detecta llamadas estaticas a metodos inexistentes: solo verificar contra el archivo real.

### Diagnostico en tests

- **`storage/logs/laravel.log` NO recibe logs en el entorno de testing.** Insertar
  `\Log::warning` para depurar no sirve. Lo que si funciona: `withoutExceptionHandling()`
  en el test (la excepcion sube cruda en vez de volverse 500), o meter el diagnostico
  temporalmente en el mensaje de la excepcion, que viaja al JSON.
- Para un 500 en tests con handler activo: `dump($resp->json())` muestra el envoltorio
  de error con su `code`.

### gh CLI

- **Tras un push, `gh pr checks N --watch` devuelve el run VIEJO** si el nuevo aun no
  arranco. Verificar con `gh run list --limit 2` que el ID cambio, o esperar ~45s.
  Sintoma: tiempos identicos a la corrida anterior.
- `gh run view` **no acepta `--branch`**; requiere el run-id, que da `gh run list`.
- `gh pr create --title` con placeholder tipo `N` se toma literal: usar el numero real.
- `artisan route:list` **no tiene `--columns`** en esta version.

## Convenciones verificadas

### Permisos y roles

- Constantes en `app/Domain/Authorization/Permissions.php`; catalogo en `all()` (~linea 144).
- Asignacion rol->permisos en `app/Domain/Authorization/Roles.php`, metodo `defaultMatrix()`.
  Los roles componen helpers privados: `tenantWideManagement()` (~linea 131, **solo la usa
  ADMIN**), `operations()`, `reports()`.
- GERENTE = `operations() + reports() + USER_VIEW`, deliberadamente sin gestion de usuarios.
- Convencion de nombres: singular con puntos (`user.password.reset`), aunque el maestro
  use plural con guion (`users.reset-password`). El codigo manda; documentar la divergencia
  en el commit.
- Patron en controllers: `abort_unless((bool) $request->user()?->can(Permissions::X), 403)`
  o `Gate::authorize(Permissions::X)`.

### Tenancy

- `TenantContext`: `set`, `forget`, `current(): ?Company`, `id(): int`, `has(): bool`,
  `enableSuperAdminMode`, `isSuperAdmin`, `runAs(Company, callable)`.
- `Company::setting(string $key, mixed $default = null): mixed` lee el jsonb `settings`.
- `TenantTable` en `app/Support/`: `companyColumn`, `enableRls`, `enableStrictRls`, `disableRls`.

### Tests

- `User::factory()` SIEMPRE con `['company_id' => $this->tenant->id]`.
- `beforeEach` tipico: `Company::factory()` con slug propio -> `TenantContext::set` ->
  `RoleProvisioner::provisionDefaultRoles` -> `PermissionRegistrar::forgetCachedPermissions`
  -> `Branch::factory()->default()` (**solo una vez por tenant**).
- **Re-setear `TenantContext::set($this->tenant)` tras cada `postJson`** antes de asserts
  o helpers que consulten modelos. Un helper que hace `firstOrFail()` llamado despues de
  un post truena con `ModelNotFoundException` si falta el reset. Paso en #18.
- `Sanctum::actingAs` + headers `['X-Tenant' => 'slug']`.
- Unit/Tax explicitos por tenant (la cadena de factories cruza tenant y truena
  `CrossTenantAccessException`).
- Nombres de datos de relleno deterministas (flaky de Faker ya pagado).
- jsonb no preserva orden: `toEqualCanonicalizing`.

### Comandos

- Tests: `2>&1 | grep -v "USER DEPRECATED\|BigNumber\|Cannot load Xdebug"`
- Suite completa: `timeout 900 docker compose exec -T app php vendor/bin/pest 2>&1 |
  tee /tmp/suite.log | grep -E "Tests:|Total:|failed|FAIL"` seguido de la limpieza
  incondicional con `pkill`. Tarda minutos SIN output: no matar por impaciencia.
- Rutas en Pint y `php -l`: SIN prefijo `backend/` (contenedor). En git y scripts Python
  del host: CON prefijo `backend/`.
- El contenedor usa BusyBox grep (sin `--include`); greps GNU en el HOST.

### PR flow

`git push -u origin rama` -> `gh pr create --base main --head rama --title "..."
--body-file /tmp/pr_body.md` -> esperar ~45s -> `gh pr checks N --watch` ->
`gh pr view N --web` (**insistir en que el usuario mire Files changed**) ->
`gh pr merge N --squash --subject "Titulo (#N)"` -> limpieza (`checkout main`, `pull`,
`branch -D`, `push origin --delete`, `log`, `status`).

CI: Frontend siempre skipped. Si el PR no toca `backend/`, Lint y Tests tambien salen
skipped por el job `Detect changes`, y eso es correcto.

**Gitleaks**: `.gitleaks.toml` en la raiz con `[allowlist] paths`. Las passwords de prueba
en tests disparan la regla `generic-api-key` por entropia; el archivo de test va a la
allowlist, no se cambian los literales.

## Commits

Español sin acentos: `tipo(scope): titulo` + cuerpo con contexto del maestro + bullets de
decisiones. Si el maestro no define algo: estandar defendible DOCUMENTADO en docblock o
commit. Si falta especificacion: DIFERIDO documentado (ADR si es alcance, docblock si es
detalle).
