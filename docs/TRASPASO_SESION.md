# Traspaso de sesion — POS Enterprise

Documento para arrancar la siguiente sesion de trabajo. **Escrito al cierre; puede estar
desactualizado en detalles. El repo manda sobre este documento, siempre.**

## REGLA CERO

El primer paso de cualquier sesion es pedir:

```
cd ~/Proyectos/pos-enterprise && git --no-pager log --oneline -5 ; git status -sb ; git branch
```

`git branch` NO es opcional. La afirmacion "sin ramas colgando" vivio en este
documento sin que nadie la ejecutara jamas; se corrigio el 2026-07-29 tras
descubrir 48 commits sin fusionar.

No ejecutar ningun plan heredado hasta ver esa salida. Si contradice este documento,
detenerse y reconstruir desde el repo real.

## Estado al cierre

- `main` = **94d9c53**, sincronizado con origin, working tree limpio.
- Rama viva NO fusionada: `feature/etapa3-frontend-cimientos`, local y en `origin`.
  No es residuo. NO se borra. Ver "Rama de frontend aparcada".
- Suite: **612 passed (1965 assertions)**.
- Ultima migracion: **000046**.
- Historia reciente: 94d9c53 (#32 maestro de proveedores) <- fad82cc (#31 traspaso
  al dia) <- f438ab5 (#30 corrige el hallazgo 1 de ADRs) <- a8147f0 (#29
  deuda tecnica volcada) <- 455e935 (#28 matriz y traspaso al dia) <- eedc5e0 (#27
  reportes operativos restantes) <- c7aeb0b (#26 docs
  traspaso y rama aparcada) <- 23749fb (#25 docs traspaso) <- 2eb83c8 (#24 reportes por
  producto/cajero) <- 4940f17 (#23 docs
  traspaso) <- a5bc8d4 (#22 warehouses update/deactivate) <- 6d52270 (#21 docs
  cobertura) <- a3adfab (#20 reset password) <- e6ed9f8 (#19 docs cierre) <-
  24d34e4 (#18 devoluciones) <- 00dec9c (#17 cierre documental).

### PRs de esta sesion

- **#32** maestro de proveedores (4.1.5): crea el dominio `Purchasing`, migracion
  000046, 5 endpoints (`/suppliers` CRUD + `deactivate`), permisos
  SUPPLIER_VIEW/CREATE/UPDATE en `operations()`, 8 tests. Recorte deliberado: 5 de
  los 22 endpoints que define el maestro en 29.7. Sin datos fiscales.
- **#31** actualiza este documento a f438ab5 y desambigua dos encabezados homonimos.
- **#30** corrige el hallazgo vivo 1: la consolidacion de ADRs YA estaba aplicada en
  `main`. `backend/docs/adr/README.md` es puntero deliberado, no residuo. Corrige de
  paso `[deuda-11]`, que el #29 marco `NO` heredando esa afirmacion sin verificarla.
- **#29** vuelca el inventario `[deuda-N]` a `docs/DEUDA_TECNICA.md`. El rango real es
  3-18, no 3-16: faltan 4 y 14, 16 esta duplicada, y 17-18 van en el cuerpo del commit.
- **#28** cierra el hueco 3 en la matriz y pone este documento al dia tras #26 y #27.
- **#27** hueco 3 CERRADO: `GET /reports/products-without-sales` (REPORT_SALES) y
  `GET /reports/cash-differences` (REPORT_FINANCE), 13 tests, sin migracion.
  `SalesRangeRequest` renombrado a `ReportRangeRequest` (sus reglas nunca fueron
  especificas de ventas). Las diferencias de caja NO se calcularon: `closeSession()`
  ya las persiste en `cash_sessions`; el endpoint solo agrega por rango.
- **#26** corrige la afirmacion falsa "sin ramas colgando" y documenta la rama
  `feature/etapa3-frontend-cimientos` (diferida por ADR-0013). Agrega `git branch`
  a la Regla Cero, que era la causa raiz.

### PRs de la sesion de reportes por rango (#21-#24)

- **#24** hueco 3 (parcial): `GET /reports/sales-by-product` y `sales-by-cashier`,
  `SalesReportService`, `SalesRangeRequest`, 9 tests. Sin migracion.
- **#23** traspaso al dia tras #22.
- **#22** hueco 2: `PATCH /warehouses/{uuid}` y `POST /warehouses/{uuid}/deactivate`,
  `UpdateWarehouseRequest`, `WarehousesHttpTest` (10 tests, el endpoint no tenia
  cobertura HTTP previa) y correccion de `COBERTURA_ALCANCE.md`. Sin migracion.
- **#21** docs de cobertura del alcance original + este traspaso.

### PRs de la sesion de devoluciones y password (#17-#20)

- **#18** devoluciones basicas CU-CAJ-010: migracion 000044, `SaleReturnService`,
  endpoints `POST/GET /sales/{uuid}/returns`, 7 tests.
- **#19** fila de devoluciones en la matriz de cierre + nota de decision sobre creditos.
- **#20** recuperacion y cambio de password (flujo 57.6): migracion 000045,
  `PasswordResetService`, 3 endpoints, permiso `USER_PASSWORD_RESET`, 6 tests.

## Frentes: ninguno en curso, uno aparcado

No hay trabajo en curso. Hueco 2 CERRADO (#22). **Hueco 3 CERRADO (#27)**: 4.1.9 pasa
a operable; el bloque analitico y el contable siguen DIFERIBLE por decision del
documento. **Compras (4.1.5) es el UNICO hueco intacto** y es el modulo grande;
ver `docs/COBERTURA_ALCANCE.md` y el orden sugerido. Ninguno es urgente por
decision del usuario.

### Rama de frontend aparcada (verificado 2026-07-29)

`feature/etapa3-frontend-cimientos` — local y en origin.

- 48 commits adelante de main, 25 detras. merge-base f93dc4b (2026-05-27, "cierra
  Etapa 0"). Tip 0f166e3 (2026-06-09). No hay fast-forward posible en ningun sentido.
- 73 archivos, 50 en `frontend/src`: auth con guards e interceptor 401, cliente HTTP
  via Hey API generado desde OpenAPI, POS (catalogo, carrito con IVA, responsive con
  drawer), PaymentModal multi-metodo, apertura y cierre de caja con arqueo a ciegas,
  anulacion con PIN supervisor, tests de stores y componentes.
- **DIFERIDA FORMALMENTE por ADR-0013** (aceptado 2026-07-24). Criterio de reapertura:
  ciclo propio de frontend con su propio plan. NO se fusiona fuera de ese ciclo.
- Por eso el CI marca Frontend skipped: `main` no tiene frontend propio.
- Sus fixes de backend YA estan en main por otra via (verificado con grep, no inferido):
  orden `EnsureTenantContext` -> `SubstituteBindings` en `bootstrap/app.php`, y
  `docker-compose.yml` sin credenciales hardcodeadas.
- Consolidacion de ADRs: **APLICADA en main** (verificado 2026-07-31). `docs/adr`
  tiene 0001-0013, README y _template. `backend/docs/adr/` conserva solo un README
  que es puntero deliberado a `/docs/adr/`, no residuo: lo dice su propio texto.
  NO borrarlo.
- Sus mensajes de commit fueron el registro original de la numeracion
  [deuda-3]...[deuda-18], ya volcada a `docs/DEUDA_TECNICA.md` en el PR #29. La rama
  ya no es punto unico de fallo para ese inventario.
- Pendiente para el ciclo de frontend: ADR-0004 (offline-first) y ADR-0008 (online-only)
  estan ambos "Accepted" y 0008 declara "Supersedes: —". Se contradicen. Los cimientos
  de la rama se escribieron bajo una de las dos.

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

## LECCIONES ACUMULADAS

Todas nacieron de inferir en vez de verificar. Corregir estos habitos.

### De la sesion anterior (#17-#20)

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

### De la sesion anterior (#21-#24)

**LECCION PRINCIPAL: verificar antes de construir ya evito trabajo duplicado tres
veces.** No es preferencia de estilo, es ahorro medible:

- Hueco 2: la matriz decia 'tres CRUD faltantes'. El repo tenia dos completos y al
  tercero le faltaban dos metodos. Se escribieron 2 metodos, no 3 CRUD.
- Hueco 3: de los operativos listados como faltantes, `average_ticket` y el desglose
  por metodo de pago YA se servian dentro de `sales-summary`. No se duplicaron.
- Diferencias de caja (#27): el reporte parecia requerir calculo propio.
  `CashService::closeSession()` YA persiste `expected_amount`,
  `counted_amount` y `difference` en `cash_sessions`. El endpoint solo
  agrega por rango.

La regla operativa: antes de implementar lo que un documento declara ausente, un grep
de rutas y un cat del service. Cuesta dos comandos.

7. **La indentacion NO se cuenta a ojo desde un `sed`.** Se creyeron 16 espacios y
   eran 12: el prefijo `NNN:` del grep y el margen del `sed` desplazan la lectura. El
   ancla trono con `count == 0`. Lo que funciona: derivar la linea del archivo
   (`[l for l in src.split(chr(10)) if MARCA in l]`) y usarla integra, tomando la
   indentacion con `len(l) - len(l.lstrip())`. El assert evito escribir nada.
8. **`git diff` sin `--no-pager` se traga el heredoc siguiente.** El pager quedo
   suspendido (`[1]+ Detenido`) y consumio el bloque pegado: el archivo nunca se creo.
   Sintoma: `ls` dice 'No existe' y `git diff --stat` no cambio. Limpieza con
   `kill %1 ; jobs`. Usar SIEMPRE `git --no-pager diff`.

9. **Un documento no puede afirmar lo que su protocolo no verifica.** "Sin ramas
   colgando" era falso y sobrevivio porque la Regla Cero no pedia `git branch`.
   Tercera vez del mismo patron (los huecos 2 y 3 de la matriz fueron las otras dos).
   Al escribir una afirmacion de estado: o hay comando que la produce en la Regla
   Cero, o la afirmacion no se escribe.

### De la sesion de reportes (#26-#27)

10. **La descripcion de un `it()` debe ser unica DENTRO del archivo.** Dos `it()` con
    el mismo texto hacen que Pest tire `TestAlreadyExist` y rechace el fichero entero,
    no solo el test. Es la misma trampa que "Cannot redeclare" de los helpers, pero
    por descripcion. Al añadir tests a un archivo existente, revisar sus `it()`.
11. **`gh pr merge` puede reportar "Deleted remote branch" sin borrarla.** Verificar
    con `git ls-remote --heads origin`, no con `git branch -a` (que lee la referencia
    local cacheada) ni fiandose del mensaje. `git remote prune` no la quita si la rama
    sigue viva en el remoto: eso mismo es la señal.

### De la sesion de deuda y ADRs (#28-#30)

12. **Heredar una afirmacion de otro documento sin verificarla es la misma falta que
    inventarla.** `DEUDA_TECNICA.md` nacio marcando `[deuda-11]` como `NO` porque este
    documento lo decia. Era falso: la consolidacion estaba aplicada y
    `backend/docs/adr/README.md` es un puntero a proposito. Un `ls` de dos directorios
    lo habria evitado. Quinta aparicion del patron de la leccion 9.

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
`branch -D`, `push origin --delete`, `log`, `status`, `branch`).

CI: Frontend siempre skipped porque `main` no contiene frontend; ese codigo vive en la
rama aparcada. Si el PR no toca `backend/`, Lint y Tests tambien salen
skipped por el job `Detect changes`, y eso es correcto.

**Gitleaks**: `.gitleaks.toml` en la raiz con `[allowlist] paths`. Las passwords de prueba
en tests disparan la regla `generic-api-key` por entropia; el archivo de test va a la
allowlist, no se cambian los literales.

## Commits

Español sin acentos: `tipo(scope): titulo` + cuerpo con contexto del maestro + bullets de
decisiones. Si el maestro no define algo: estandar defendible DOCUMENTADO en docblock o
commit. Si falta especificacion: DIFERIDO documentado (ADR si es alcance, docblock si es
detalle).
