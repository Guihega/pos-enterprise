# ADR-0004: Cliente offline-first como PWA con IndexedDB

- **Estado**: Accepted
- **Fecha**: 2026-04-28
- **Fase**: Fase 0 (decisión); implementación en Fase 2.

## Contexto

Las terminales POS deben operar continuamente. La conectividad a internet en sucursales del mercado objetivo (LATAM retail mediano) es inestable: cortes de varios minutos a varias horas son comunes. Un POS que se "cae" cuando el wifi se cae es inaceptable.

Requerimiento de negocio: **vender 8+ horas sin red**, con sincronización automática al recuperar conectividad, **sin pérdida de datos** y **sin duplicación de operaciones**.

## Decisión

El cliente POS se construye como **Progressive Web App (PWA) offline-first**:

- Toda la lógica de venta corre localmente en el navegador.
- IndexedDB (vía Dexie) almacena catálogo, ventas, sesiones de caja, cola de sincronización.
- Service Worker (Workbox) gestiona cache de la app shell y de la API.
- El servidor **no es la fuente de verdad operacional**: cada terminal opera autónoma con su réplica.
- El servidor consolida y arbitra conflictos al sincronizar.
- IDs distribuidos (UUIDs v4) generados en cliente; folios fiscales reservados en rangos.
- Idempotencia obligatoria en sync (clientes pueden reenviar la misma operación N veces).
- Eventual consistency entre sucursales y entre terminales.
- **No hay rollback de hechos comerciales**: si un sync detecta inconsistencia, se compensa (devolución, ajuste, anotación) pero la venta ya hecha no se "deshace".

## Consecuencias

### Positivas

- Cliente sigue vendiendo aunque caiga internet, el servidor o ambos.
- Latencia de UI casi cero (no espera al servidor).
- Reduce la carga sobre el servidor (lecturas locales).
- PWA no requiere stores de aplicaciones, instalación con un click.

### Negativas / costos

- Complejidad significativa: cola de sync, resolución de conflictos, IDs distribuidos, reserva de folios.
- Stock puede quedar desactualizado offline; necesitamos políticas claras de oversell (ver doc maestro 42.1).
- Requiere disciplina arquitectónica en cada feature nueva: ¿funciona offline? ¿cómo sincroniza?
- Pruebas más complejas (chaos, escenarios offline).
- Versiones mínimas de browsers limitadas a IndexedDB + Service Worker estables (Chrome 90+, Safari 16+, Firefox 90+).

### Neutras

- App nativa (Flutter) en Fase 8 sigue la misma filosofía pero con SQLite local.

## Alternativas consideradas

### App online-only con buen UX de error

- Mucho más simple.
- **Descartado**: incumple el requerimiento de negocio fundamental.

### App nativa desde el día 1

- Mejor experiencia offline en algunos casos.
- **Descartado**: tiempo de desarrollo y costo de mantenimiento (iOS + Android + actualizaciones manuales) demasiado altos para Fase 1. PWA da el 80% del valor con el 20% del esfuerzo.

### App de Electron

- App de escritorio "real".
- **Descartado**: complica el despliegue, sigue siendo un navegador embebido. PWA puede instalarse como app y obtiene mismo resultado con menos overhead.

## Referencias

- Documento maestro, Parte VI completa (secciones 34–44).
