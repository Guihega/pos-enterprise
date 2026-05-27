# 0007 — TenantContext con estado estático y compatibilidad con Laravel Octane

- **Status:** Accepted
- **Fecha:** 2026-05-27
- **Supersedes:** —
- **Referencias:** `App\Domain\Tenancy\Services\TenantContext`, `App\Domain\Tenancy\Middleware\EnsureTenantContext`, ADR-0006 (RLS — pendiente de redactar)

## Contexto

`TenantContext` es el punto único de verdad sobre el tenant activo durante un
request o un job. Su API (`set`, `forget`, `current`, `id`, `has`,
`enableSuperAdminMode`, `runAs`) la consumen el middleware
`EnsureTenantContext`, el `TenantScope` (que filtra Eloquent), el trait
`BelongsToTenant` (que valida al crear) y la integración con RLS de Postgres
(cada `set` ejecuta `set_config('app.current_tenant_id', ?)`).

La implementación guarda el tenant actual en dos propiedades **estáticas** de
la clase:

    private static ?Company $current = null;
    private static bool $superAdminMode = false;

Bajo el modelo de ejecución actual del proyecto (**PHP-FPM** detrás de Nginx),
esto es seguro: cada request HTTP es un proceso PHP fresco que carga las
clases desde cero, así que `self::$current` arranca como `null` en todo
request. Cuando termina, el proceso se recicla y el estado estático muere
con él. El middleware llama `TenantContext::forget()` en `terminate()` como
defensa en profundidad, pero no es lo que aísla un request del siguiente —
lo que aísla es el modelo de proceso de PHP-FPM.

Existe sin embargo un riesgo declarado: si en algún momento el proyecto
adopta **Laravel Octane** (Swoole, RoadRunner o FrankenPHP) para ganar
performance, el modelo cambia radicalmente. Octane mantiene el proceso PHP
vivo entre requests para evitar el coste de boot, y **las propiedades
estáticas de las clases persisten entre requests**. Sin medidas adicionales,
el request N+1 vería el `TenantContext::$current` del request N. Esto sería
un fallo de aislamiento catastrófico: un usuario del tenant B podría
empezar su request y, antes de que el middleware corra, ya tendría visible
el tenant A del request anterior.

El proyecto **no usa Octane hoy** (verificado: no hay paquete instalado,
ni configuración en `config/`, ni listeners de eventos de Octane). Pero la
decisión sobre cómo se va a comportar `TenantContext` cuando llegue ese día
debe quedar tomada y escrita ahora, mientras el contexto técnico está
fresco tras el cierre del hallazgo M3 (ver `TenantHttpIsolationTest`).

## Decisión

Se mantiene `TenantContext` con estado estático tal como está hoy. **No** se
migra a un binding por-request en el contenedor de Laravel (`Container` con
scoping de request), por dos razones:

1. La API estática (`TenantContext::id()`) es consumida desde puntos donde
   inyectar dependencias es engorroso o imposible: dentro de un global scope
   de Eloquent (`TenantScope::apply`), dentro de un evento `creating` en un
   trait Eloquent (`BelongsToTenant::bootBelongsToTenant`), dentro del
   callback de Sanctum en `AppServiceProvider`. Forzar un binding por-request
   en todos esos puntos rompería el modelo.
2. La pérdida de seguridad bajo Octane no proviene del estado estático en sí,
   sino de **no haberlo limpiado entre requests**. La limpieza explícita es
   barata y suficiente.

La compatibilidad con Octane se garantiza mediante **limpieza explícita en
los tres bordes** del estado estático:

1. **HTTP (ya implementado).** `EnsureTenantContext::terminate()` llama
   `TenantContext::forget()` al final de cada request, gane o pierda. Esto
   ya cubre el caso HTTP bajo PHP-FPM y bajo Octane sin cambios.

2. **Octane request lifecycle (a implementar el día que se instale Octane).**
   Registrar un listener del evento `Laravel\Octane\Events\RequestReceived`
   que llame `TenantContext::forget()` antes de que cualquier middleware
   corra. Es defensa en profundidad: si por cualquier motivo `terminate()`
   no se ejecutó (excepción no manejada, kill del worker, etc.) el siguiente
   request empieza con contexto limpio garantizado.

3. **Jobs en cola (pendiente).** Los jobs ejecutados por un worker `queue:work`
   también necesitan establecer y limpiar el contexto. Hoy el `TenantContext`
   menciona en su docblock un trait `TenantAwareJob`, pero **ese trait no
   existe en el repositorio**. Esta deuda queda declarada explícitamente
   como pendiente: cuando se aborde la cola de jobs (Etapa 4 según el plan
   de trabajo), debe crearse el trait y todo job tenant-aware debe usarlo.
   El trait deberá: aceptar el `Company` en su constructor, llamar `set()`
   antes de `handle()` y `forget()` en un `finally`, y probablemente usar
   `TenantContext::runAs()` que ya existe y está diseñado para ese patrón.

## Consecuencias

### Positivas

- El API actual de `TenantContext` se preserva. Ningún consumidor
  (`TenantScope`, `BelongsToTenant`, callback de Sanctum, controladores) se
  ve obligado a cambiar el día que se adopte Octane.
- La limpieza en tres bordes deja un modelo claro de a quién le toca limpiar
  qué: el middleware para HTTP, el listener de Octane como salvaguarda, el
  trait `TenantAwareJob` para colas.
- El estado estático sigue siendo el más rápido posible (ni resolución del
  contenedor, ni lookup en un store por-request).

### Negativas

- La adopción de Octane queda condicionada a implementar el listener de
  `RequestReceived` previamente. Hacerlo en el orden contrario (Octane
  primero, listener después) abriría una ventana de fuga entre tenants.
  Este ADR debe consultarse antes de aprobar el PR que añada Octane.
- El estado estático sigue siendo difícil de mockear en tests. El proyecto
  ya convive con esto: los tests llaman `TenantContext::set()` / `forget()`
  en `beforeEach` / `afterEach`.
- La deuda del trait `TenantAwareJob` queda como bloqueo silencioso: los
  jobs encolados que toquen modelos tenant-aware **fallarán** hoy en cuanto
  se agreguen (porque el `TenantScope` aplica `WHERE FALSE` sin contexto).
  Esto no es un riesgo de Octane sino preexistente; queda anotado aquí
  porque resolverlo es parte del mismo problema de "limpieza en bordes".

### Pendientes (no parte de este ADR)

- Implementar el trait `TenantAwareJob` cuando se aborde la Etapa 4.
- Implementar el listener `RequestReceived` cuando se instale Octane.
- Escribir el ADR-0006 retroactivo sobre RLS, al que este documento hace
  referencia.

## Cómo verificar la decisión

El día que se adopte Octane, esta decisión se considera correctamente
aplicada si y solo si:

1. Existe un listener registrado para `Laravel\Octane\Events\RequestReceived`
   que llama `TenantContext::forget()`.
2. Existe un test de integración que arranca dos requests consecutivos en el
   mismo worker, el primero con `X-Tenant: tenant-a` y el segundo sin header,
   y verifica que el segundo NO ve a `tenant-a` (debe dar 400
   `TENANT_NOT_RESOLVED`).
3. La suite completa pasa bajo Octane.

Mientras esos tres puntos no estén satisfechos, **Octane no debe habilitarse
en producción**.
