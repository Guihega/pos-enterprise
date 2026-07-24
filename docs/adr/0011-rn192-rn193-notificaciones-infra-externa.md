# ADR-0011: RN-192 (login sospechoso) y RN-193 (backup fallido) diferidas

- Estado: aceptado
- Fecha: 2026-07-24

## Contexto

De la tabla de notificaciones del maestro (RN-190..RN-198, ~linea 1879),
dos reglas dependen de infraestructura que el sistema no posee:

- **RN-192** "Login sospechoso (IP/pais nuevo) notifica al usuario":
  detectar pais nuevo requiere resolucion geografica de IP (servicio
  externo tipo MaxMind GeoIP, con licencia, base de datos actualizable
  y politica de precision). No existe integracion geoip alguna en el
  backend. La mitad detectable sin geoip (IP nueva a secas) generaria
  ruido alto en redes moviles/CGNAT sin la señal de pais que la regla
  pide explicitamente.
- **RN-193** "Backup fallido notifica a admin": no existe job de
  backup en la aplicacion cuyo fallo observar; el respaldo de
  PostgreSQL es responsabilidad de infraestructura (fuera del
  monolito Laravel). Cablear la notificacion sin productor del evento
  seria codigo muerto.

## Decision

Se DIFIEREN ambas. Son las unicas reglas de la tabla RN-190..198 cuya
implementacion exige servicios/infra externos; el resto opera sobre
eventos internos del dominio y sigue su curso normal.

Nota: el insumo parcial de RN-192 ya existe hoy — el login exitoso
registra last_login_ip y el fallido deja rastro en activity_log con
ip_address (RN-176, PR #15). La deteccion y notificacion quedan
pendientes de la pieza geoip.

## Criterio de reapertura

- RN-192: cuando se contrate/decida el proveedor de geolocalizacion.
  El slice seria: tabla de ubicaciones conocidas por usuario, hook en
  AuthService::login (donde ya se emite auditoria), notificacion por
  el canal existente de NotificationService.
- RN-193: cuando exista el job de backup (spatie/laravel-backup o
  responsabilidad de infra con webhook). El slice es el listener del
  evento de fallo + notificacion a admin por el canal existente.
