# ADR-0010: Permisos cross-branch y gerente regional (46.7) diferidos

- Estado: aceptado
- Fecha: 2026-07-24

## Contexto

El maestro sec. 46.7 define una tabla de capacidades cross-branch
(ver stock de otras sucursales, transferencias, reportes consolidados)
condicionadas a un rol "gerente regional". Al auditar su implementabilidad
se encontraron tres carencias:

1. **Sin mecanismo**: la tabla marca capacidades como "configurable"
   sin definir donde se configura (por rol, por usuario), ni DDL de
   alcance usuario-sucursales (que sucursales supervisa un regional).
2. **Taxonomia de roles divergente**: el maestro (~linea 1420) define 9
   roles (super_admin, tenant_owner, admin, regional_manager,
   branch_manager, shift_supervisor, cashier, senior_cashier,
   salesperson); el sistema provisiona 8 distintos (SUPER_ADMIN, ADMIN,
   GERENTE, SUPERVISOR, CAJERO, ALMACEN, AUDITOR, COBRANZA), mapeo
   heredado de epics previos sin regional_manager ni tenant_owner.
3. **Permisos contextuales inexistentes**: sales.view-others y
   cash.view-others-sessions (maestro ~linea 1692, "salvo gerente
   regional o admin") no existen en Permissions.php; la excepcion
   regional no tiene sobre que aplicar.

## Decision

Se DIFIERE la implementacion completa de 46.7. Implementarla exige:
reconciliar la taxonomia de roles maestro vs codigo (decision de
producto, no tecnica), DDL nueva de alcance regional (tabla
usuario-sucursales o equivalente), y crear los permisos contextuales
con su semantica de evaluacion por sucursal. Ninguna de las tres piezas
tiene definicion suficiente en el maestro para un estandar defendible.

## Criterio de reapertura

Reabrir cuando producto defina: (a) mapeo canonico de roles, (b) modelo
de asignacion regional (que sucursales supervisa quien), (c) lista
cerrada de permisos cross-branch con su default. Con eso el slice es
mecanico: rol nuevo en RoleProvisioner + tabla de alcance + permisos
contextuales + gates en los endpoints de stock/transferencias/reportes.

## Consecuencias

- El sistema opera con el modelo actual: permisos por rol sin
  dimension de sucursal (salvo los scopes ya existentes por tenant).
- La tabla 46.7 del maestro queda como especificacion aspiracional
  hasta la reapertura.
