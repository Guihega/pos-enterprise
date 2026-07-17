<?php

declare(strict_types=1);

namespace App\Domain\Authorization;

/**
 * Catálogo único y estable de permisos del sistema.
 *
 * Convención: '{recurso}.{accion}' — todo en kebab-case, sin guiones.
 *
 * Cualquier permiso usado en el código DEBE estar declarado aquí. Los
 * tests verifican que no haya permisos colgados (sin seeder) ni typos.
 *
 * Para agregar uno nuevo:
 *   1. Declararlo aquí como constante.
 *   2. Asignarlo en RolesAndPermissionsSeeder al rol(es) correspondiente(s).
 *   3. Usarlo en código vía Permissions::PRODUCT_CREATE (no string literal).
 */
final class Permissions
{
    // Catálogo / Productos
    public const PRODUCT_VIEW = 'product.view';

    public const PRODUCT_CREATE = 'product.create';

    public const PRODUCT_UPDATE = 'product.update';

    public const PRODUCT_DELETE = 'product.delete';

    // Inventario
    public const INVENTORY_VIEW = 'inventory.view';

    public const INVENTORY_VIEW_CROSS_BRANCH = 'inventory.view.cross-branch';

    public const INVENTORY_ADJUST = 'inventory.adjust';

    public const INVENTORY_TRANSFER = 'inventory.transfer';

    public const INVENTORY_COUNT = 'inventory.count';

    public const TRANSFERS_VIEW = 'transfers.view';

    public const TRANSFERS_CREATE = 'transfers.create';

    public const TRANSFERS_SEND = 'transfers.send';

    public const TRANSFERS_RECEIVE = 'transfers.receive';

    public const TRANSFERS_CANCEL = 'transfers.cancel';

    public const TRANSFER_REQUESTS_VIEW = 'transfer-requests.view';

    public const TRANSFER_REQUESTS_CREATE = 'transfer-requests.create';

    public const TRANSFER_REQUESTS_APPROVE = 'transfer-requests.approve';

    // Caja
    public const CASH_OPEN = 'cash.open';

    public const CASH_CLOSE = 'cash.close';

    public const CASH_MOVEMENT = 'cash.movement';

    public const CASH_VIEW = 'cash.view';

    // Ventas
    public const SALE_CREATE = 'sale.create';

    public const SALE_VIEW = 'sale.view';

    public const SALE_VOID = 'sale.void';

    public const SALE_REFUND = 'sale.refund';

    public const SALE_DISCOUNT_AUTHORIZE = 'sale.discount.authorize';

    // Clientes
    public const CUSTOMER_VIEW = 'customer.view';

    public const CUSTOMER_CREATE = 'customer.create';

    public const CUSTOMER_UPDATE = 'customer.update';

    public const CUSTOMER_DELETE = 'customer.delete';

    // Reportes
    public const REPORT_SALES = 'report.sales';

    public const REPORT_INVENTORY = 'report.inventory';

    public const REPORT_FINANCE = 'report.finance';

    public const REPORT_AUDIT = 'report.audit';

    public const REPORT_CONSOLIDATED = 'report.consolidated';

    // Administración (gestión de usuarios, roles, sucursales)
    public const USER_VIEW = 'user.view';

    public const USER_CREATE = 'user.create';

    public const USER_UPDATE = 'user.update';

    public const USER_DELETE = 'user.delete';

    public const USER_ROLE_ASSIGN = 'user.role.assign';

    public const ROLE_VIEW = 'role.view';

    public const ROLE_CREATE = 'role.create';

    public const ROLE_UPDATE = 'role.update';

    public const ROLE_DELETE = 'role.delete';

    public const BRANCH_VIEW = 'branch.view';

    public const BRANCH_CREATE = 'branch.create';

    public const BRANCH_UPDATE = 'branch.update';

    public const BRANCH_DELETE = 'branch.delete';

    public const SETTINGS_UPDATE = 'settings.update';

    public const AUDIT_VIEW = 'audit.view';

    public const DEVICE_VIEW = 'device.view';

    public const DEVICE_REVOKE = 'device.revoke';

    public const SYNC_CONFLICT_VIEW = 'sync-conflict.view';

    public const SYNC_CONFLICT_RESOLVE = 'sync-conflict.resolve';

    /**
     * Devuelve todos los permisos como array plano.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            // Catálogo
            self::PRODUCT_VIEW, self::PRODUCT_CREATE, self::PRODUCT_UPDATE, self::PRODUCT_DELETE,
            // Inventario
            self::INVENTORY_VIEW, self::INVENTORY_ADJUST, self::INVENTORY_TRANSFER, self::INVENTORY_COUNT,
            self::INVENTORY_VIEW_CROSS_BRANCH,
            // Transferencias inter-sucursal
            self::TRANSFERS_VIEW, self::TRANSFERS_CREATE, self::TRANSFERS_SEND,
            self::TRANSFERS_RECEIVE, self::TRANSFERS_CANCEL,
            // Solicitudes de transferencia (CU-GER-003)
            self::TRANSFER_REQUESTS_VIEW, self::TRANSFER_REQUESTS_CREATE, self::TRANSFER_REQUESTS_APPROVE,
            // Caja
            self::CASH_OPEN, self::CASH_CLOSE, self::CASH_MOVEMENT, self::CASH_VIEW,
            // Ventas
            self::SALE_CREATE, self::SALE_VIEW, self::SALE_VOID, self::SALE_REFUND, self::SALE_DISCOUNT_AUTHORIZE,
            // Clientes
            self::CUSTOMER_VIEW, self::CUSTOMER_CREATE, self::CUSTOMER_UPDATE, self::CUSTOMER_DELETE,
            // Reportes
            self::REPORT_SALES, self::REPORT_INVENTORY, self::REPORT_FINANCE, self::REPORT_AUDIT,
            self::REPORT_CONSOLIDATED,
            // Admin
            self::USER_VIEW, self::USER_CREATE, self::USER_UPDATE, self::USER_DELETE, self::USER_ROLE_ASSIGN,
            self::ROLE_VIEW, self::ROLE_CREATE, self::ROLE_UPDATE, self::ROLE_DELETE,
            self::BRANCH_VIEW, self::BRANCH_CREATE, self::BRANCH_UPDATE, self::BRANCH_DELETE,
            self::SETTINGS_UPDATE, self::AUDIT_VIEW,
            // Dispositivos (maestro 29.1: listar/desautorizar)
            self::DEVICE_VIEW, self::DEVICE_REVOKE,
            // Conflictos de sync (maestro 39.3: cola humana)
            self::SYNC_CONFLICT_VIEW, self::SYNC_CONFLICT_RESOLVE,
        ];
    }
}
