# ADR-0001: Stack tecnológico base

- **Estado**: Accepted
- **Fecha**: 2026-04-28
- **Fase**: Fase 0 — Discovery y Setup

## Contexto

Necesitamos un stack que soporte:

- API multi-tenant con miles de tenants.
- Operación **offline-first** crítica (terminales POS deben vender 8+ horas sin red).
- Latencia P95 < 300ms en endpoints de venta.
- Despliegue desde un solo desarrollador hasta equipos grandes.
- Costo de hosting predecible y bajo en planes pequeños.
- Ecosistema maduro con librerías para facturación electrónica LATAM, pasarelas de pago, hardware POS.
- Curva de aprendizaje razonable y disponibilidad de talento.

## Decisión

Adoptamos el siguiente stack como columna vertebral del producto:

| Capa            | Tecnología                          |
|-----------------|-------------------------------------|
| Backend lenguaje | PHP 8.3                            |
| Backend framework | Laravel 11                        |
| Base de datos   | PostgreSQL 16                       |
| Cache / queues / sessions | Redis 7                   |
| WebSockets      | Laravel Reverb                      |
| Frontend lenguaje | TypeScript 5+                     |
| Frontend framework | Vue 3.4+                         |
| Frontend bundler | Vite 5                             |
| Frontend storage offline | IndexedDB (vía Dexie 4)    |
| Service Worker  | Workbox 7                           |
| App móvil gerencial | Flutter (Fase 8)                |
| Containers      | Docker + Docker Compose             |
| Web server      | Nginx 1.27                          |
| Hosting         | DigitalOcean (Droplets, Managed DB, Spaces) |
| CI/CD           | GitHub Actions                      |
| Observabilidad  | Prometheus + Grafana + Loki         |

## Consecuencias

### Positivas

- Laravel + Postgres es un stack probado para SaaS multi-tenant a escala (Forge, Vapor, Fathom, etc.).
- PHP 8.3 + OPcache + JIT da rendimiento competitivo con Node.js para nuestros patrones de carga.
- Postgres 16 ofrece RLS, particionamiento, JSONB, trigram, FTS — todo lo que necesitamos sin extensiones exóticas.
- Vue 3 + Vite + TypeScript es una combinación productiva y bien documentada para PWAs.
- Reverb integra de forma nativa con Laravel para websockets sin necesidad de servicios externos (Pusher, Ably).
- DigitalOcean da costo predecible y servicios managed suficientes para nuestra escala objetivo de los primeros años.
- El stack es accesible a la mayoría de desarrolladores hispanohablantes, lo que facilita contratación.

### Negativas / costos

- PHP no tiene paralelismo "real" (sin extensiones); workloads CPU-intensive requieren delegación a colas o lenguajes auxiliares (Go/Rust en el futuro si fuera necesario).
- Laravel tiene "magia" que puede ser difícil de razonar; mitigamos con convenciones estrictas y PHPStan nivel 8.
- IndexedDB tiene limitaciones (cuota variable por navegador, API verbose); mitigamos con Dexie como wrapper.
- DigitalOcean tiene menor variedad de servicios que AWS/GCP/Azure; aceptable porque queremos simplicidad.

### Neutras

- Decisión revisable en la Fase 14 (white label) si algún cliente exige otra plataforma.

## Alternativas consideradas

### Node.js + NestJS + Postgres

- Ecosistema enorme.
- TypeScript end-to-end (mismo lenguaje en backend y frontend).
- **Descartado**: complejidad operacional mayor (build, despliegue, dependencias), menor productividad para CRUD y reglas de negocio típicas de un POS, ecosistema menos maduro para CFDI/facturación LATAM.

### Django + Postgres

- Excelente ORM y admin out-of-the-box.
- **Descartado**: menor talento disponible localmente, menor cantidad de paquetes para integraciones POS específicas (CFDI, PACs), ASGI todavía menos estandarizado para producción que PHP-FPM.

### Ruby on Rails + Postgres

- Productividad muy alta.
- **Descartado**: hosting más caro, deploys más complejos, talento escaso en LATAM hispanohablante.

### Java/Spring + Postgres

- Excelente para sistemas críticos.
- **Descartado**: overhead de desarrollo y operación demasiado alto para un POS de retail mediano, costo de hosting mayor.

## Referencias

- Documento maestro: sección 1 (visión), sección 15+ (arquitectura técnica).
