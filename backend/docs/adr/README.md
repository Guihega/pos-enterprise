# Architecture Decision Records (ADR)

Este directorio guarda las decisiones de arquitectura del proyecto en formato
MADR-lite. Cada decisión es un archivo Markdown numerado:
`NNNN-titulo-corto-en-kebab.md`.

## Por qué ADRs

Las decisiones técnicas importantes se olvidan o se cuestionan meses después.
Un ADR captura **el contexto del momento**, **la decisión tomada** y **sus
consecuencias** para que el "porqué" sobreviva al "qué".

No documentamos toda decisión: solo aquellas que afectan a más de un módulo,
que son difíciles de revertir, o que el equipo va a redescubrir tarde si no
quedan escritas.

## Formato

Cada ADR sigue esta estructura mínima:

    # NNNN — Título breve

    - **Status:** Proposed | Accepted | Deprecated | Superseded by ADR-NNNN
    - **Fecha:** YYYY-MM-DD

    ## Contexto
    Qué problema o pregunta se resuelve. Restricciones reales.

    ## Decisión
    Qué se decidió, en términos claros y verificables.

    ## Consecuencias
    Qué se gana, qué se pierde, qué queda pendiente.

## Numeración

Los números se asignan en estricto orden cronológico y **nunca se reutilizan**.
Si un ADR queda obsoleto, se marca como `Superseded by ADR-NNNN`, no se borra.

### Hueco 0001-0006: ADRs retroactivos

El proyecto comenzó con decisiones arquitectónicas tomadas pero no escritas.
Varias partes del código (por ejemplo el docblock de `TenantScope`) citan
ADR-0006 (Row Level Security en Postgres) y otros. Esos ADRs se escribirán
de forma retroactiva cuando toque revisarlos formalmente. Mientras tanto,
los números 0001-0006 quedan reservados para no romper esas referencias.

**ADR-0007 es el primer ADR escrito directamente en este repositorio.**

## Índice

| Nº | Título | Status |
|---|---|---|
| 0001 | (Retroactivo — pendiente) Stack y arquitectura general | Accepted |
| 0002 | (Retroactivo — pendiente) Multi-tenancy con `company_id` | Accepted |
| 0003 | (Retroactivo — pendiente) Identificadores: ID interno + UUID público | Accepted |
| 0004 | (Retroactivo — pendiente) Eloquent + TenantScope como primera barrera | Accepted |
| 0005 | (Retroactivo — pendiente) Sanctum para autenticación API | Accepted |
| 0006 | (Retroactivo — pendiente) Row Level Security en Postgres como segunda barrera | Accepted |
| 0007 | TenantContext con estado estático y compatibilidad con Laravel Octane | Accepted |
