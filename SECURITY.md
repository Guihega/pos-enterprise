# Política de seguridad

## Reportar una vulnerabilidad

Si encuentras una vulnerabilidad de seguridad en POS Enterprise, **no la reportes públicamente** (no abras un issue público en GitHub).

Reportala de forma privada a través de:

- **Email**: `security@<dominio-del-proyecto>`
- **PGP**: clave pública disponible en `<URL>` (futuro).

Incluye:

- Descripción del problema.
- Pasos para reproducir.
- Impacto estimado.
- Versión afectada.
- Tu información de contacto.

## Compromiso de respuesta

- **Acuse de recibo**: en menos de 48 horas hábiles.
- **Evaluación inicial y severidad**: en menos de 5 días hábiles.
- **Plan de remediación comunicado**: dependiendo de severidad (ver tabla).

| Severidad | Tiempo de remediación objetivo |
|-----------|---------------------------------|
| Crítica   | 7 días                          |
| Alta      | 30 días                         |
| Media     | 90 días                         |
| Baja      | Próximo release planeado        |

## Divulgación coordinada

Después de remediar la vulnerabilidad, coordinaremos contigo la divulgación pública. Reconoceremos tu contribución (si lo deseas) en el CHANGELOG y en el security advisory correspondiente.

## Versiones soportadas

Soportamos con parches de seguridad las dos versiones MAYOR más recientes. Versiones anteriores reciben fixes solo bajo contrato Enterprise activo.

## Alcance

Esta política aplica a:

- Código fuente del repositorio.
- Imágenes Docker oficiales.
- SDKs oficiales.
- Despliegues administrados por el equipo (`*.pos.example.com`).

**No** aplica a:

- Forks no oficiales.
- Despliegues self-hosted modificados.
- Integraciones de terceros.
