# ADR-0002: Monorepo backend + frontend + docs

- **Estado**: Accepted
- **Fecha**: 2026-04-28
- **Fase**: Fase 0

## Contexto

El producto consta de varios componentes que cambian juntos:

- API Laravel (backend).
- PWA Vue (frontend POS).
- Apps móviles (Fase 8+).
- SDKs oficiales (Fase 12).
- Documentación viva (incluido el documento maestro).
- Infraestructura como código.

Necesitamos decidir entre repositorio único (monorepo) o múltiples repos.

## Decisión

Adoptamos un **monorepo** que contiene todos los componentes del producto, organizados en directorios de primer nivel (`backend/`, `frontend/`, `docs/`, `docker/`, etc.).

Más adelante, si el equipo crece y los componentes divergen mucho en su ciclo de release, evaluaremos extraer paquetes a sus propios repos (notablemente: SDKs públicos podrían beneficiarse de repos separados para su versionado independiente).

## Consecuencias

### Positivas

- Cambios atómicos cross-componentes en un solo PR (ej: nueva ruta API + componente Vue que la consume).
- Una sola rama, una sola versión coherente, menos confusión.
- CI/CD unificado, fácil de razonar.
- Documentación junto al código que documenta.
- Setup local más simple (un `git clone`, un `make init`).

### Negativas

- El repo crece más rápido en tamaño. Mitigado con `.gitignore` estricto y sin binarios.
- Build times pueden ser más altos si todo se compila siempre. Mitigado con detección de cambios por ruta (`dorny/paths-filter` en CI).
- Acceso granular más complicado (todo el equipo ve todo). Aceptable inicialmente; revisable en Fase 15 (Enterprise).

## Alternativas consideradas

### Repos separados (`pos-api`, `pos-frontend`, `pos-docs`)

- Permite ciclos de release independientes.
- **Descartado para Fase 0–6**: aumenta fricción para cambios cross-componente, requiere herramientas adicionales (submodules / package registry interno) que no se justifican mientras el equipo es pequeño.

### Monorepo con Nx / Turborepo

- Herramientas avanzadas para monorepos.
- **Descartado por ahora**: nuestro stack PHP+Vue no tiene integración nativa con esas herramientas. Make + Docker Compose es suficiente y más universal.

## Referencias

- README.md (estructura del repo).
