# ADR-0013: Cliente PWA fuera del alcance backend

- Estado: aceptado
- Fecha: 2026-07-24

## Contexto

El cliente PWA (frontend offline-first, secciones 38.x del maestro
del lado consumidor) es una aplicacion independiente. Todo el trabajo
de este ciclo fue backend; el CI marca Frontend como skipped de forma
permanente y ningun PR lo toca.

## Decision

Se declara FUERA DE ALCANCE de este ciclo de cierre. El backend deja
el contrato completo del lado servidor: batch idempotente con cola de
conflictos (39.1/39.3), snapshot con cursor keyset (38.6), changes
(38.x), folios por rango (ADR-0009), heartbeat y registro de
dispositivos.

## Criterio de reapertura

Ciclo propio de frontend con su propio plan. El contrato servidor
esta documentado en los endpoints y tests de tests/Feature/Sync/.
