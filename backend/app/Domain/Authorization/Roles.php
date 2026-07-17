<?php

declare(strict_types=1);

namespace App\Domain\Authorization;

/**
 * Roles default que se siembran en cada tenant nuevo.
 *
 * Pueden:
 *   - Editarse (renombrarse, agregar/quitar permisos) por el tenant.
 *   - No pueden borrarse directamente si tienen usuarios asociados.
 *
 * super_admin existe SÓLO para el tenant Anthropic (root del sistema).
 * No se siembra en tenants normales.
 */
final class Roles
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const GERENTE = 'gerente';

    public const SUPERVISOR = 'supervisor';

    public const CAJERO = 'cajero';

    public const ALMACEN = 'almacen';

    public const AUDITOR = 'auditor';

    public const COBRANZA = 'cobranza';

    /**
     * Mapping rol → permisos. Define el "menú base".
     *
     * @return array<string, list<string>>
     */
    public static function defaultMatrix(): array
    {
        $P = Permissions::class;

        return [
            // Admin: TODO menos super_admin (que es global del SaaS)
            self::ADMIN => [
                ...self::tenantWideManagement(),
                ...self::operations(),
                ...self::reports(),
                $P::INVENTORY_VIEW_CROSS_BRANCH,
                $P::REPORT_CONSOLIDATED,
                $P::DEVICE_VIEW, $P::DEVICE_REVOKE,
                $P::SYNC_CONFLICT_VIEW, $P::SYNC_CONFLICT_RESOLVE,
            ],

            // Gerente de sucursal: operaciones + reportes, sin tocar settings/usuarios
            self::GERENTE => [
                ...self::operations(),
                ...self::reports(),
                $P::USER_VIEW,
                $P::BRANCH_VIEW,
                $P::INVENTORY_VIEW_CROSS_BRANCH,
                $P::REPORT_CONSOLIDATED,
                // Maestro 29.1: DELETE auth/devices dice "admin"; se otorga
                // tambien a gerente (gestiona dispositivos de su operacion,
                // consistente con USER_VIEW/BRANCH_VIEW). Estandar defendible.
                $P::DEVICE_VIEW, $P::DEVICE_REVOKE,
                // Maestro 39.3: "Solo gerente o admin puede resolver".
                $P::SYNC_CONFLICT_VIEW, $P::SYNC_CONFLICT_RESOLVE,
            ],

            // Supervisor: cobros, autorizaciones, ver reportes operativos
            self::SUPERVISOR => [
                $P::SALE_CREATE, $P::SALE_VIEW, $P::SALE_VOID, $P::SALE_REFUND,
                $P::SALE_DISCOUNT_AUTHORIZE,
                $P::CASH_OPEN, $P::CASH_CLOSE, $P::CASH_MOVEMENT, $P::CASH_VIEW,
                $P::CUSTOMER_VIEW, $P::CUSTOMER_CREATE, $P::CUSTOMER_UPDATE,
                $P::PRODUCT_VIEW,
                $P::INVENTORY_VIEW,
                $P::TRANSFERS_VIEW,
                $P::REPORT_SALES,
            ],

            // Cajero: vender, abrir/cerrar SU caja, ver productos
            self::CAJERO => [
                $P::SALE_CREATE, $P::SALE_VIEW,
                $P::CASH_OPEN, $P::CASH_CLOSE, $P::CASH_VIEW,
                $P::CUSTOMER_VIEW, $P::CUSTOMER_CREATE,
                $P::PRODUCT_VIEW,
            ],

            // Almacén: inventario completo, productos read-only, sin caja ni ventas
            self::ALMACEN => [
                $P::PRODUCT_VIEW,
                $P::INVENTORY_VIEW, $P::INVENTORY_ADJUST, $P::INVENTORY_TRANSFER, $P::INVENTORY_COUNT,
                ...self::transfers(),
                $P::REPORT_INVENTORY,
            ],

            // Cobranza: gestion de cartera CxC (RN-198). Minimo defendible:
            // ve clientes y sus saldos, actualiza datos de contacto para gestion
            // de cobro, ve ventas origen del adeudo y reporte financiero.
            // Sin caja, sin inventario, sin crear ventas.
            self::COBRANZA => [
                $P::CUSTOMER_VIEW, $P::CUSTOMER_UPDATE,
                $P::SALE_VIEW,
                $P::REPORT_FINANCE,
            ],

            // Auditor: read-only de TODO
            self::AUDITOR => [
                $P::PRODUCT_VIEW,
                $P::INVENTORY_VIEW,
                $P::INVENTORY_VIEW_CROSS_BRANCH,
                $P::TRANSFERS_VIEW,
                $P::TRANSFER_REQUESTS_VIEW,
                $P::CASH_VIEW,
                $P::SALE_VIEW,
                $P::CUSTOMER_VIEW,
                $P::USER_VIEW, $P::ROLE_VIEW, $P::BRANCH_VIEW,
                $P::REPORT_SALES, $P::REPORT_INVENTORY, $P::REPORT_FINANCE, $P::REPORT_AUDIT,
                $P::REPORT_CONSOLIDATED,
                $P::AUDIT_VIEW,
                $P::DEVICE_VIEW,
                $P::SYNC_CONFLICT_VIEW,
            ],
        ];
    }

    /** @return list<string> */
    private static function tenantWideManagement(): array
    {
        $P = Permissions::class;

        return [
            $P::USER_VIEW, $P::USER_CREATE, $P::USER_UPDATE, $P::USER_DELETE, $P::USER_ROLE_ASSIGN,
            $P::ROLE_VIEW, $P::ROLE_CREATE, $P::ROLE_UPDATE, $P::ROLE_DELETE,
            $P::BRANCH_VIEW, $P::BRANCH_CREATE, $P::BRANCH_UPDATE, $P::BRANCH_DELETE,
            $P::SETTINGS_UPDATE,
            $P::AUDIT_VIEW,
        ];
    }

    /** @return list<string> */
    private static function operations(): array
    {
        $P = Permissions::class;

        return [
            $P::PRODUCT_VIEW, $P::PRODUCT_CREATE, $P::PRODUCT_UPDATE, $P::PRODUCT_DELETE,
            $P::INVENTORY_VIEW, $P::INVENTORY_ADJUST, $P::INVENTORY_TRANSFER, $P::INVENTORY_COUNT,
            $P::CASH_OPEN, $P::CASH_CLOSE, $P::CASH_MOVEMENT, $P::CASH_VIEW,
            $P::SALE_CREATE, $P::SALE_VIEW, $P::SALE_VOID, $P::SALE_REFUND, $P::SALE_DISCOUNT_AUTHORIZE,
            $P::CUSTOMER_VIEW, $P::CUSTOMER_CREATE, $P::CUSTOMER_UPDATE, $P::CUSTOMER_DELETE,
            ...self::transfers(),
            ...self::transferRequests(),
        ];
    }

    /** @return list<string> */
    private static function transfers(): array
    {
        $P = Permissions::class;

        return [
            $P::TRANSFERS_VIEW, $P::TRANSFERS_CREATE, $P::TRANSFERS_SEND,
            $P::TRANSFERS_RECEIVE, $P::TRANSFERS_CANCEL,
        ];
    }

    /** @return list<string> */
    private static function transferRequests(): array
    {
        $P = Permissions::class;

        return [
            $P::TRANSFER_REQUESTS_VIEW, $P::TRANSFER_REQUESTS_CREATE, $P::TRANSFER_REQUESTS_APPROVE,
        ];
    }

    /** @return list<string> */
    private static function reports(): array
    {
        $P = Permissions::class;

        return [
            $P::REPORT_SALES, $P::REPORT_INVENTORY, $P::REPORT_FINANCE, $P::REPORT_AUDIT,
        ];
    }
}
