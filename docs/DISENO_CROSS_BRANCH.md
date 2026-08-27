# Diseno: cross-branch (ADR-0010 reabierto y superado)

- Estado: DISENADO 2026-08-26, pendiente de implementar (PR 1 y PR 2).
- Origen: maestro 46.4-46.7, RN-232/233; ADR-0010 (diferido 2026-07-24).
- Decisiones del usuario marcadas como D1/D2 (2026-08-26).

## 1. Auditoria verificada (archivo:linea, sesion 2026-08-26)

Lo que el ADR-0010 daba por inexistente YA EXISTE y se aplica:

- Modelo de alcance (gap b del ADR): users.branch_id (default) + pivot
  user_branches (migracion 000004) con User::branches() y syncBranches().
  "Que sucursales supervisa quien" = filas del pivot. Sin DDL nueva.
- Permisos (gap c): inventory.view.cross-branch y report.consolidated
  existen en Permissions.php (34, 96) y en all().
- Roles (gap a): 7 roles provisionados, confirmados por el usuario
  (2026-08-21). El "gerente regional" del maestro es un GERENTE con
  varias sucursales en user_branches. No se crea rol nuevo.
- RN-233 aplicada: InventoryController:48-51 y BatchController:167-186
  filtran por user->branches() salvo inventory.view.cross-branch.
- 46.6 aplicada: ReportsController:58/75/87 exigen report.consolidated;
  ConsolidatedReportService (por servicio, no vistas materializadas).
- 46.4 pasos 1-5 aplicados: Transfer draft->sent->received (#44 folios);
  merma automatica transfer_loss en TransferService:139-193 (RN-049);
  TransferRequest notifica a GERENTE de origen (TransferRequestService:165).

## 2. Gaps reales

G1 (el slice): TransferController (78/126/150/188) y
TransferRequestController (79/118/138) comprueban el PERMISO pero nunca
la SUCURSAL del usuario. Un usuario con transfers.create de la sucursal
A crea/envia desde B o recibe en C sin pertenecer a ninguna. Es la fila
"Iniciar transferencia desde otra: No, salvo regional" de 46.7 sin
aplicar. Que se rompe si no se hace: cualquier usuario con permiso de
transferencias vacia cualquier sucursal del tenant.

G2 (menor): 46.4 paso 6, alerta si el destino no recibe en N dias. No
existe en el codigo.

Fuera de alcance (no es autorizacion cross-branch): 46.5 settings JSONB
por sucursal; vistas materializadas de 46.6. Si el usuario los pide,
diseno aparte.

## 3. Decisiones del usuario

D1 = (a). Regla de pertenencia en transferencias con bypass SOLO por
permiso nuevo `transfers.cross-branch`, que entra en all() y por tanto
lo tiene ADMIN por diseno. GERENTE NO lo recibe por defecto: un gerente
que supervisa varias sucursales se resuelve asignandoselas en
user_branches, no con el bypass. Descartada (b) "GERENTE por defecto":
reabre el agujero para cualquier gerente de una sola sucursal.

D2 = (a) condicionada: alerta diaria de transferencias en `sent` con mas
de N dias (N=7, en config), a ADMIN del tenant y GERENTE de la sucursal
destino, via NotificationService. Condicion: que exista scheduler en el
proyecto (routes/console.php o Console/Kernel). Si no existe, D2 pasa a
DIFERIDO documentado sin discusion.

## 4. Regla de pertenencia (G1)

Un usuario "pertenece" a una sucursal si esta en user->branches()
(mismo criterio que InventoryController:49, clonado, no reinterpretado).

| Accion                     | Sucursal exigida | Motivo                        |
|----------------------------|------------------|-------------------------------|
| transfers.create           | ORIGEN           | saca stock de ahi             |
| transfers.send             | ORIGEN           | confirma la salida            |
| transfers.cancel           | ORIGEN           | deshace la salida             |
| transfers.receive          | DESTINO          | captura lo que llega          |
| transfer-requests.create   | DESTINO          | pide para su sucursal         |
| transfer-requests.approve  | ORIGEN           | quien entrega decide          |
| transfer-requests.reject   | ORIGEN           | idem                          |
| transfer-requests.cancel   | (ya: requester)  | sin cambio                    |

Bypass: user->can(transfers.cross-branch) salta la tabla entera.
Fallo: 403 (es autorizacion, no validacion de datos). Lectura (index/
show) NO se restringe en este slice: la lista de transferencias del
tenant sigue siendo visible con transfers.view (coherente con 46.7, que
solo restringe INICIAR); se anota como posible endurecimiento futuro.

## 5. Diseno tecnico PR 1 (G1)

- Permiso: 3 pasos (constante TRANSFERS_CROSS_BRANCH =
  'transfers.cross-branch', all(), defaultMatrix: NINGUN rol explicito;
  ADMIN lo hereda de all()). all() pasa de 69 a 70.
  RoleAssignmentTest sigue auditando.
- Helper puro `App\Domain\Authorization\BranchAccess` con
  `allows(User $user, int $branchId): bool` = can(cross-branch) ||
  in_array($branchId, user->branches()->pluck('branches.id')).
  Clona BatchController:183-186. Sin cambios de firma en servicios.
- Controllers: `abort_unless(BranchAccess::allows($user, $x->from_branch_id), 403);`
  justo despues del abort_unless de permiso existente, en las 7 acciones
  de la tabla. Clonar la linea hermana de permiso; anclar a la FIRMA del
  metodo (leccion 34).
- Tests Pest nuevos (prefijo `cb`): usuario de A crea desde A (201) y
  desde B (403); send/cancel desde origen ajeno (403); receive en
  destino ajeno (403); approve/reject de solicitud con origen ajeno
  (403); con transfers.cross-branch todo pasa; ADMIN pasa (all()).
  Tests con beforeEach propio + syncBranches().
- RIESGO CONOCIDO: los tests existentes de Transfer/TransferRequest
  pueden crear usuarios sin filas en user_branches y pasaran a 403.
  Se actualizan con syncBranches() y justificacion en el commit, no se
  fuerza el gate.
- Sin migracion. Sin rutas nuevas. Docs: COBERTURA_ALCANCE (46.7
  aplicada), este doc a IMPLEMENTADO con SHA.

## 6. Diseno tecnico PR 2 (G2, solo si D2 aplica)

- Comando `transfers:alert-stale` (dias por config, default 7):
  Transfer status=sent con sent_at < now - N dias, no notificados aun
  (columna alerted_at nullable, migracion 000053) -> NotificationService
  a ADMIN del tenant + GERENTE de to_branch (usersWithRolesForBranch).
- Registrado en el scheduler existente, daily.
- Tests: transferencia vieja notifica una vez; joven no; idempotente.
- Si no hay scheduler: DIFERIDO aqui mismo, una linea, sin PR.

## 7. Orden

1. PR 1 (G1). 2. Docs. 3. Verificar scheduler; PR 2 o diferir.
4. Siguiente alcance del usuario: reportes 4.1.9.
