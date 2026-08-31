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

- `main` = **157d663** (#52), sincronizado con origin, working tree limpio.
- Rama viva NO fusionada: `feature/etapa3-frontend-cimientos`, local y en `origin`.
  No es residuo. NO se borra. Ver "Rama de frontend aparcada".
- Suite: **720 passed (2326 assertions)**. Pint: **PASS 423 files**.
- Ultima migracion: **000053** (sale_items.quantity_source).
- Historia reciente (verificada 2026-08-31): 157d663 (#52 antiguedad
  de pagables) <- c472d29 (docs diseno CXP) <- e1af388 (docs #51) <-
  add1d46 (#51 terminal
  modelo A) <- 1ac1dec (docs D1 granel confirmada) <- 8986969 (docs #50)
  <- 1ebc2b8 (#50 venta a
  granel) <- ebab875 (docs diseno granel) <- 1a16754 (docs #49) <-
  f779b95 (#49 margen por
  producto) <- 5591672 (docs #48) <- 5129a00 (#48 cross-branch
  transferencias) <- e4b91a8 (docs diseno cross-branch) <- 9a0384e (docs
  #47) <- e7b6a7d (#47 ADMIN control
  total + factories) <- 1879859 (docs lecciones 34-36) <- 2b9463b (docs
  #46) <- febb336 (#46 apply-prices) <- bd5b4dc (docs #45) <- b8ea0b0
  (#45 modulo de costeo). Lo anterior: `git --no-pager log --oneline -40`;
  cada bloque de lecciones registra el SHA de su squash.

### PRs de esta sesion

- **#42** productos del proveedor (4.1.5): GET /suppliers/{uuid}/products,
  el ultimo endpoint del alcance acordado (19 de 22; los 3 de
  purchase-receipts siguen fuera). Fuente: purchase_order_items de OCs
  no canceladas (draft cuenta). productsForSupplier en
  PurchaseOrderService + supplierProducts en PurchaseOrdersController
  (criterio de la decision 16); permiso SUPPLIER_VIEW existente; 6
  tests. Deja [deuda-19]: la entrada manual de inventario no captura
  proveedor. INCIDENCIA CI: el primer run se colgo 30 min en el job de
  tests con UNA aspa visible en el log de puntos; en local paso todo
  (serie x2, --parallel x2, seeds distintos). El rerun paso en 2m26s.
  Fallo intermitente sin nombre capturado: si reaparece un aspa en CI
  con verde local, dejar COMPLETAR el run para capturar el nombre del
  test antes de cancelar.

- **#41** facturas de proveedor, conciliacion y pagos (4.1.5): migracion
  000050 (supplier_invoices + supplier_payments, decimal 18,4), modelos,
  SupplierInvoiceService, 5 endpoints (listar, crear, match, pay y
  balance por proveedor), permisos SUPPLIER_INVOICE_VIEW/CREATE/PAY
  (PAY explicito en GERENTE y en ADMIN: ADMIN se compone por spread y
  no lo heredaria), SupplierInvoiceTransitionException propia -> 409
  (el code de la respuesta es contrato de API), 12 tests + 1 de
  regresion. match() concilia contra lo RECIBIDO por TOTALES, exceso =
  422 exacto; status derivado de paid_amount; sobrepago en pay() = 422;
  supplier_payments sin SoftDeletes; folio de factura lo emite el
  proveedor (unique company+supplier+folio, validado en servicio con el
  unique como red), folio de pago PAY-{6}. Corrige nextFolio() en AMBOS
  servicios: derivaba de max(id) global y saltaba folios entre tenants.
  Sube a 18 de 22 endpoints de 29.7.

- **#40** PATCH de la OC en draft (4.1.5): cierra el ULTIMO diferido de
  Compras. El maestro lo define en la linea 5968 sin detallar campos ni
  semantica. Modificables items, expected_date y notes; supplier_uuid y
  branch_uuid NO (branch_id decide el almacen de entrada). Lineas por
  reemplazo en bloque. PATCH parcial real: flags touchExpectedDate/
  touchNotes distinguen campo ausente de null. Permiso propio
  PURCHASE_ORDER_UPDATE en operations(). update() es metodo NUEVO:
  create() y receive() no se tocaron; reutiliza calculateLine(). Sin
  ADR (el maestro define el endpoint; decisiones de detalle en
  docblocks). 5 tests nuevos, el archivo pasa de 19 a 24. Sube a 13 de
  22 endpoints de 29.7.

- **#39** lotes y caducidad en la recepcion (4.1.5): cierra el diferido
  del #36. Migracion 000049 (supplier_id y purchase_order_id en
  product_batches, nullable con FK nullOnDelete), captura de lote
  ESTRICTA (tracks_lots sin batch = 422), 4 tests nuevos (el archivo
  pasa de 15 a 19). ADR-0014. Se descarto derivar received_date del
  received_at de la OC y se documento por que. NO agrega endpoints:
  siguen 12 de 22.

- (#33 a #38 sin entrada propia: #34 y #36 tienen seccion de lecciones
  abajo; todos constan en la historia de commits del estado.)
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

No hay trabajo en curso. Hueco 2 CERRADO (#22). **Hueco 3 CERRADO (#27)**:
4.1.9 pasa a operable; el bloque analitico y el contable siguen DIFERIBLE
por decision del documento. **Compras (4.1.5) CERRADO**: el alcance 29.7
esta completo (19 de 22 entregados hasta el #42; los 3 de purchase-receipts
pospuestos, criterio de reapertura revisado tras #41 y cerrado, ver
`docs/COBERTURA_ALCANCE.md`). Ningun hueco queda intacto ni urgente.

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
- ADR-0004 y ADR-0008: **NO se contradicen** (verificado 2026-08-05 leyendo ambos
  completos). 0008 documenta el gap del MVP hacia 0004 y lo dice textual: el
  online-only de Fase 1 "no es un error del MVP"; su decision es mapear el rediseño
  de Fase 2 (folios por rangos, oversell offline), y ADR-0009 ya diseña esos rangos.
  Por eso "Supersedes: -" es correcto: no reemplaza nada. La cadena 0004 -> 0008 ->
  0009 es coherente. El titulo de 0008 induce a error si no se abre el archivo:
  segunda vez que un hallazgo sobre ADRs resulta falso por heredarlo sin leer
  (leccion 12). Sin decision de arquitectura pendiente: el frontend va al FINAL,
  cuando el backend este 100% confirmado y probado (ADR-0013), y su camino tecnico
  ya esta diseñado.

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
5. **CORREGIDA en #41: `now()` en columna `timestampTz` NO desfasa.**
   `now()`, `Carbon::now()` y `->utc()` son el MISMO instante y Postgres
   normaliza timestamptz a UTC (verificado: 22:20-06:00 == 04:20+00:00).
   `PurchaseOrderService` usa `now()` a secas en 4 columnas y es
   correcto. El desfase que esta leccion describia debio tener otra
   causa; la redaccion original lo atribuyo a `app.timezone` sin
   verificarlo.
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

### De la sesion de ordenes de compra (#34)

13. **Arreglar una causa destapa la siguiente.** Los 4 tests rojos del arranque
    escondian TRES causas raiz, no una. Solo la primera estaba diagnosticada en
    este documento; las otras dos solo aparecieron cuando la ejecucion llego mas
    lejos. Corolario: no dar una entrega por diagnosticada porque se explique el
    primer sintoma, y releer la salida COMPLETA despues de cada correccion.
14. **`TenantScopedModel` impone `SoftDeletes`.** Un modelo hijo cuya tabla no
    tiene `deleted_at` revienta con SQLSTATE 42703 al hacer eager load. El patron
    del repo para lineas hijas es `extends Model` + `use BelongsToTenant;`, como
    `SaleItem`, `TransferItem`, `SalePayment` o `SaleTax`. Mantiene RLS y tenancy
    sin el borrado logico.
15. **Postgres NO admite `FOR UPDATE` sobre agregados** (SQLSTATE 0A000). Un
    consecutivo calculado con `max('id')` + `lockForUpdate()` no es viable. El
    patron del repo para folios es la fila contador (`SaleNumberCounter`) con
    `SELECT ... FOR UPDATE`.
16. **`max('id')` sobre tabla con soft-delete recicla consecutivos.** El global
    scope filtra `deleted_at is null`, asi que borrar la ultima fila hace que el
    siguiente folio repita uno ya emitido. Requiere `withTrashed()` explicito.
17. **Pint sobre los archivos tocados significa TODOS los tocados.** Se pintaron 3
    de 15 y `pint --test` global fallo en `bootstrap/app.php` y
    `PurchaseOrderFactory.php`. El conteo real del repo es **386 files**.

### De la sesion de recepcion (#36)

18. **El binding de ruta del repo es `{modelo:uuid}`, no `{modelo}`.** Un ancla
    construida desde el nombre del parametro del controller fallo con
    `count == 0`. La sintaxis de un archivo se lee de ese archivo, no se
    deduce de otro. El assert lo atrapo antes de escribir nada.
19. **Un servicio sin constructor lo gana al inyectar una dependencia.** Añadir
    `receive()` con `$this->inventory` exigio crear el `__construct` completo:
    `grep` de `$this->` solo devolvia la linea recien escrita, señal de que la
    propiedad no existia.
20. **Una columna que falta se añade en migracion propia, no editando la que
    ya esta mergeada.** `received_at` fue la 000048 porque la 000047 ya estaba
    aplicada en entornos.
21. **Un docblock duplicado sobrevive a Pint, a los tests y al CI.**
    receive() arrastraba desde el #36 dos docblocks consecutivos; el
    muerto declaraba un @param con un parametro inexistente
    ($recepciones). Nada automatico lo detecta: los comentarios no se
    compilan ni se ejecutan. Solo aparecio al leer el archivo entero
    para construir un ancla. Corolario: el grep que devuelve una linea
    no dice cuantas veces existe el bloque; contar con count() antes.
22. **Un parametro puede compilar, pasar la suite y no hacer nada.**
    receivedDate derivado de received_at era un no-op: esa columna se
    sella al FINAL de receive(), despues de leerse. Lo delato un grep -n
    que mostro la linea de escritura por debajo de la de lectura, no el
    compilador ni los tests (que pasaban igual). Antes de dar por buena
    una derivacion, verificar el ORDEN de escritura del dato origen.

### De la sesion de terminal bancaria modelo A (#51)

- Estado al cierre: main add1d46 (#51 squash), 716 tests (2302
  assertions), pint 421 files, 71 permisos, 28 endpoints, migracion
  000053, cero deuda abierta.
- Modelo A del maestro 48.6 CERRADO: todo existia (metodos, columnas del
  voucher, persistencia, corte que separa efectivo porque solo
  METHOD_CASH genera sale_cash; tarjeta se registra como sale_other);
  el unico cambio fue required_if de authorization_code en tarjeta.
- DECISION ABIERTA DEL USUARIO: D1 de DISENO_TERMINAL_A.md (solo
  authorization_code obligatorio; card_last4 opcional) aplicada por
  defecto; CONFIRMAR. (b) seria sumar card_last4 al required_if.
- Modelo B (SDK integrado: Mercado Pago Point / Clip / Stripe Terminal):
  fase posterior declarada; exige hardware en mano y es mayormente
  terminal/frontend.

### De la sesion de venta a granel (#50)

39. **Decision binaria sin respuesta tras tres preguntas: aplicar la
    recomendacion, marcarla como default del copiloto y seguir.** D1 de
    granel (quien autoriza captura manual de peso) se pregunto tres
    veces sin respuesta; se aplico (a) y quedo marcada en el diseno y en
    el commit. Bloquear un PR por una linea de matriz es peor que
    corregir una linea despues.
- Estado al cierre: main 1ebc2b8 (#50 squash), 710 tests (2281
  assertions), pint 420 files, 71 permisos, 28 endpoints, ultima
  migracion 000053 (sale_items.quantity_source), cero deuda abierta.
- D1 de DISENO_GRANEL.md CONFIRMADA por el usuario (2026-08-27):
  sale.weight.manual solo GERENTE y SUPERVISOR. Nada abierto.
- DIFERIDOS documentados, NO deuda: gate de fraccion en movimientos de
  inventario (por movimiento, cuando se toque cada uno); neteo de
  devoluciones en reportes de ventas (en bloque).
- Alcance declarado por el usuario para despues: terminales de pago con
  tarjeta (maestro 48.6), fase posterior. La lectura de bascula es de la
  terminal (48.4 ScaleBridge -> WebSocket -> frontend): NO es backend y
  NO se propone frontend.

### De la sesion de margen por producto (#49)

- Estado al cierre: main f779b95 (#49 squash), 702 tests (2256
  assertions), pint 418 files, 70 permisos, 28 endpoints, cero deuda
  abierta. Primer reporte analitico de 4.1.9: margen real por producto
  (docs/DISENO_MARGEN.md IMPLEMENTADO; permiso report.finance).
- DIFERIDO documentado, NO deuda: netear devoluciones en TODOS los
  reportes de ventas a la vez (sales-by-product, by-cashier, margin).
- Candidatos siguientes de 4.1.9 con datos ya existentes, a decision del
  usuario: cuentas por pagar (antiguedad de saldos sobre
  supplier_invoices; GET /suppliers/{uuid}/balance ya existe) y compras
  por proveedor/periodo. Frontend NO se propone.
- Ritmo: 3 PRs mergeados con CI verde en un dia (#47, #48, #49). Ese es
  el ritmo esperado; el metodo (diseno corto comiteado, clonar lineas
  hermanas, numeros a mano, suite x2) no lo frena, lo sostiene.

### De la sesion de cross-branch (#48)

38. **Antes de declarar que algo NO existe, buscar el CONCEPTO, no la
    palabra.** Dos veces en una sesion: un grep por tres nombres de rol
    concluyo "solo hay 3 roles" (hay 7) y un grep por transfer_loss
    concluyo "no hay alerta de no recibidas" (existia como
    transfers:detect-lost). Un `ls tests/Feature/X` o un grep con
    sinonimos cuesta nada y desmiente conclusiones antes de escribirlas.
- Estado al cierre: main 5129a00 (#48 squash), 696 tests (2224
  assertions), pint 417 files, 70 permisos, 27 endpoints (sin rutas
  nuevas), cero deuda abierta. Cross-branch CERRADO: ADR-0010 superado,
  DISENO_CROSS_BRANCH.md IMPLEMENTADO. tenantWideManagement() sigue
  huerfano (no es deuda).
- Endurecimiento futuro anotado, NO deuda: index/show de transferencias
  no filtran por sucursal (46.7 solo limita INICIAR).
- SIGUIENTE ALCANCE por decision (b) del usuario: reportes 4.1.9. Frontend
  NO se propone.

### De la sesion del cierre de ADMIN (#47)

37. **Un aleatorio de rango corto en una factory es un flaky latente.**
    TaxFactory generaba `'TAX'.numerify('##')` (100 valores): dos taxes
    en la misma company colisionaban ~1% por test, y la suite completa
    dio 687 verde y luego 1 failed sobre el MISMO codigo con seeds
    distintos. UnitFactory tenia el mismo patron. Corregido clonando el
    patron de WarehouseFactory (`prefijo + strtoupper(Str::random(6))`)
    y demostrado con 15 corridas consecutivas limpias del archivo.
    Regla: codigos con unique de BD en factories usan Str::random, nunca
    numerify de dos cifras. Correr la suite completa DOS veces antes de
    comitear revela flakies que una sola corrida esconde.
- Estado al cierre: main e7b6a7d (#47 squash: ADMIN => Permissions::all()
  + factories Tax/Unit), 687 tests (2187 assertions), pint 415 files, 27
  endpoints, cero deuda abierta, 69 permisos, ultima migracion 000052.
- DECISIONES DEL USUARIO (al cierre de la sesion del costeo; cierran los
  dos pendientes del bloque siguiente):
  (a) ADMIN tiene control total del sistema por DISENO: `Permissions::all()`,
      sin spread manual que gotee. Sistema de roles confirmado tal como
      existe (admin/gerente/cajero). super_admin es ROL global del SaaS,
      no permiso: no entra en all(). GERENTE y CAJERO intactos.
  (b) PRIORIDAD DE ALCANCE: cross-branch (ADR-0010) PRIMERO, en sesion
      limpia con diseno previo escrito y COMITEADO en docs/ (patron
      DISENO_COSTEO.md); reportes 4.1.9 DESPUES; frontend SOLO cuando el
      usuario declare la funcionalidad al 100% (literal: "sin considerar
      aun el frontend, porque la funcionalidad aun no esta lista al
      100%"). NO proponer frontend.
- Regla operativa vigente: CONSTRUIR PRIMERO. Sin barridos ni auditorias
  proactivas; los bugs entran solo por test fallido o error real en uso.

### De la sesion del costeo (#44-#46)

34. **pint reescribe lo recien mergeado: las anclas de una sesion
    anterior caducan.** Un docblock alineado por pint (@param con doble
    espacio) rompio un patch anclado al texto original. Anclar a FIRMAS
    de metodos, no a docblocks ni comentarios.
35. **El merge va DESPUES del watch en verde, nunca encadenado con `;`.**
    `gh pr checks --watch ; gh pr merge` ejecuta el merge aunque el
    watch termine con checks pendientes; sin branch protection nada lo
    frena. Ocurrio en #46 (acabo verde, pero fue suerte, no metodo).
36. **Los numeros esperados de tests de formulas se derivan con la
    evidencia delante, no de memoria.** Un endurecimiento de asercion
    puso 22.75 donde el prorrateo multi-linea daba 19.5; el propio
    fallo del test dio el valor real. Derivar por escrito en el
    comentario del test.
- Estado al cierre: main 2b9463b, 687 tests (2183 assertions), 27
  endpoints, cero deuda abierta, 69 permisos. Modulo de costeo COMPLETO
  (#45 corridas + #46 apply-prices, docs/DISENO_COSTEO.md). Pendientes
  del usuario: ADMIN aprueba OCs (si/no) y alcance siguiente
  (cross-branch ADR-0010 en sesion limpia / reportes 4.1.9 / frontend,
  cuyo bloqueo documentado ya no existe).

### De la sesion del CI colgado y el cierre de deuda-19 (#43)

29. **`gh run view --job --log` solo sirve para jobs TERMINADOS** (detalle
    en la seccion gh CLI). Un job colgado in_progress no tiene log por CLI.
30. **`gh run cancel` es asincrono**: confirmar completed/cancelled antes
    del rerun; relanzar sobre un run vivo rebota (detalle en gh CLI).
31. **Un run cancelled no admite `rerun --failed`**: rerun completo.
32. **En incidentes de INFRA, ciclo barato de accion antes que diagnostico
    exhaustivo.** Un job de tests colgado 1h40m (normal ~2.5min) se
    resolvio con cancelar+relanzar (1m48s, verde): era flake de runner.
    Cancelar no pierde informacion, la difiere un ciclo: si es codigo, el
    fallo reaparece CON log legible. La exhaustividad, para el codigo.
33. **Dos salidas del mismo fichero que se contradicen = el fichero
    cambio** (rama, edicion paralela): verificar `git status` antes de
    intentar conciliarlas.
- Nota de entorno: los pantallazos AGOTAN el limite de carga de la
  conversacion (15 imagenes mataron una sesion). Pedir salidas como TEXTO
  pegado, explicito en cada peticion.

### De la sesion de productos del proveedor (#42)

27. **En strings de Python `\$` no es escape: el backslash queda
    LITERAL y envenena el PHP generado.** El reflejo viene de los
    heredocs de bash, donde si hace falta. El SyntaxWarning "invalid
    escape sequence" es el aviso: tratarlo como error
    (`python3 -W error::SyntaxWarning`).
28. **El CI corre `php artisan test --parallel --coverage`, no pest en
    serie.** Correr `--parallel` en local antes del push pisa el mismo
    terreno que el CI (12 procesos, misma BD compartida entre ellos).

### De la sesion de facturas (#41)

23. **Una asercion sobre la FORMA da falsa sensacion de cobertura sobre
    el COMPORTAMIENTO.** `toStartWith('OC-')` paso en verde durante
    cuatro PRs mientras el consecutivo estaba roto (saltaba folios
    entre tenants). Buscar este patron en otros tests.
24. **Un `create()` no conoce los DEFAULT de columnas que no son
    fillable.** `status` y `paid_amount` volvian null en la respuesta
    aunque la fila estuviera correcta. Requiere `->refresh()` antes de
    retornar.
25. **El unique de BD protege contra carreras, pero NO es validacion de
    usuario.** Un folio de factura repetido saltaba como QueryException
    y el handler la convertia en 500: error del usuario devuelto como
    fallo del servidor. Si el dato lo teclea una persona, validar en el
    servicio (422) y dejar el unique como red.
26. **`php -l` valida sintaxis, no que una clase exista.** Un
    `use App\Models\User;` inexistente paso el linter; lo delato
    instanciarla con `artisan tinker --execute="new Clase()"`.

### Diagnostico en tests

- **`storage/logs/laravel.log` SI recibe los errores del handler en testing**
  (verificado en #41: asi se diagnostico un 500). La version anterior de esta
  nota lo negaba en absoluto; lo unico que no se re-verifico es que
  `\Log::warning` manual no aparezca. Tambien funciona: `withoutExceptionHandling()`
  en el test (la excepcion sube cruda en vez de volverse 500), o meter el diagnostico
  temporalmente en el mensaje de la excepcion, que viaja al JSON.
- Para un 500 en tests con handler activo: `dump($resp->json())` muestra el envoltorio
  de error con su `code`.

### gh CLI

- **`gh run view --log` NO sirve en jobs vivos** (solo al completar); el log en
  streaming solo existe en la web. `gh run view --job ID` si muestra el step
  en curso.
- **`gh run cancel` es ASINCRONO**: queda "submitted" pero el run sigue vivo un
  rato. Un `gh run rerun` inmediato rebota con "This workflow is already
  running". Confirmar antes `status: completed / conclusion: cancelled` con
  `gh run view ID --json status,conclusion`.
- **`gh run rerun --failed` no aplica a runs cancelled** (no tienen jobs
  failed): rerun COMPLETO sin flag.
- **Tras un push, `gh pr checks N --watch` devuelve el run VIEJO** si el nuevo aun no
  arranco. Verificar con `gh run list --limit 2` que el ID cambio, o esperar ~45s.
  Sintoma: tiempos identicos a la corrida anterior.
- `gh run view` **no acepta `--branch`**; requiere el run-id, que da `gh run list`.
- `gh pr create --title` con placeholder tipo `N` se toma literal: usar el numero real.
- `artisan route:list` **no tiene `--columns`** en esta version.

### De la sesion de cuentas por pagar (#52)

40. **La leccion 39 (decision binaria sin respuesta: aplicar recomendacion
    y seguir) aplica a decisiones DENTRO de un alcance aprobado, NUNCA
    para colar un alcance nuevo.** El silencio del usuario ante greps no
    aprueba alcance. El alcance lo decide el usuario SIEMPRE. (Origen:
    #51 se ejecuto sin aprobacion explicita de alcance.)
- Entregado: GET /api/v1/reports/payables-aging (#52, squash 157d663).
  PayablesReportService::aging() en Purchasing\Services; cubetas
  30/60/90 contra due_date; universo status != cancelled y saldo > 0
  (total - paid_amount al vuelo); una fila por proveedor, orden balance
  desc, totals por cubeta; round 2. Sin migracion, sin permiso nuevo.
  4 tests HTTP (PayablesAgingHttpTest, prefijo pa, facturas y pagos via
  HTTP; cancelada via forceFill: no hay endpoint de cancelacion).
- Estado al cierre: main 157d663 (#52 squash), 720 tests (2326
  assertions), pint 423 files, 71 permisos, 29 endpoints, ultima
  migracion 000053, cero deuda.

## Convenciones verificadas

### Permisos y roles

- Constantes en `app/Domain/Authorization/Permissions.php`; catalogo en `all()` (~linea 144).
- Asignacion rol->permisos en `app/Domain/Authorization/Roles.php`, metodo `defaultMatrix()`.
  Helpers privados: `operations()`, `reports()`, `transfers()`. La matriz tiene 7 roles
  (ADMIN, GERENTE, SUPERVISOR, CAJERO, ALMACEN, COBRANZA, AUDITOR) mas SUPER_ADMIN global;
  ALMACEN = inventario + transfers() (verificado 2026-08-27). Permiso
  `transfers.cross-branch` (#48): bypass del gate de sucursal, solo ADMIN via all().
- ADMIN = `Permissions::all()` (linea 46, #47 e7b6a7d): control total por DISENO, no
  compone helpers. super_admin es ROL global del SaaS, no permiso, no entra en all().
- `tenantWideManagement()` (linea 135) quedo HUERFANO tras #47: nadie lo llama. Se
  retira cuando se vuelva a tocar Roles.php; no es deuda.
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
