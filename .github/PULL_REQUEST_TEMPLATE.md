# Descripción

<!-- ¿Qué hace este PR? Sé conciso pero completo. -->

## Tipo de cambio

- [ ] feat: nueva funcionalidad
- [ ] fix: corrección de bug
- [ ] refactor: refactor sin cambio funcional
- [ ] docs: solo documentación
- [ ] chore: tareas de mantenimiento
- [ ] test: solo cambios en tests
- [ ] ci: cambios en CI/CD

## Fase del proyecto

<!-- Marca la fase a la que pertenece este cambio (ver POS_MAESTRO_v3.md). -->

- [ ] Fase 0 — Setup
- [ ] Fase 1 — MVP Core
- [ ] Fase 2 — Offline-first
- [ ] Otra: _____

## Checklist

### Funcional
- [ ] El cambio cumple con la regla de negocio especificada (RN-XXX si aplica).
- [ ] No rompe funcionalidad existente.
- [ ] Casos límite considerados.

### Calidad
- [ ] Tests unitarios para la lógica de negocio.
- [ ] Tests de integración si toca endpoints.
- [ ] Coverage no bajó.
- [ ] PHPStan pasa sin nuevos errores.
- [ ] Pint / ESLint sin errores.

### Multi-tenant
- [ ] Si hay nuevo modelo: tiene `company_id` y scope.
- [ ] Si hay nueva ruta: middleware tenant aplicado.
- [ ] Verificación manual de aislamiento entre tenants.

### Offline (si aplica)
- [ ] Operación funciona offline.
- [ ] Sincronización idempotente.
- [ ] Sin conflictos no resueltos posibles.

### Seguridad
- [ ] Sin secretos en código.
- [ ] Validación de input en backend.
- [ ] Autorización verificada por endpoint (Policy o middleware).
- [ ] Logs no contienen PII innecesario.

### Documentación
- [ ] OpenAPI actualizado si cambió la API.
- [ ] CHANGELOG.md actualizado.
- [ ] Documento maestro actualizado si cambió arquitectura.
- [ ] ADR creado si decisión arquitectónica.

## Plan de rollback

<!-- ¿Cómo se revierte si algo sale mal? -->

## Screenshots / videos

<!-- UI o flujos relevantes. -->

## Referencias

<!-- Links a issues, RFCs, ADRs. -->
