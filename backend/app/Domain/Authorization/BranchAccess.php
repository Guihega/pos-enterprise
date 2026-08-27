<?php

declare(strict_types=1);

namespace App\Domain\Authorization;

use App\Domain\Identity\Models\User;

/**
 * Pertenencia de sucursal para acciones de transferencia
 * (docs/DISENO_CROSS_BRANCH.md, sec. 4).
 *
 * Un usuario opera sobre una sucursal si esta en user_branches (mismo
 * criterio que InventoryController y BatchController) o si tiene el bypass
 * TRANSFERS_CROSS_BRANCH, que ADMIN hereda de Permissions::all() y ningun
 * otro rol recibe por defecto (decision D1 = a, usuario 2026-08-26).
 */
final class BranchAccess
{
    public static function allows(?User $user, int $branchId): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->can(Permissions::TRANSFERS_CROSS_BRANCH)) {
            return true;
        }

        $branchIds = $user->branches()->pluck('branches.id')->all();

        return in_array($branchId, $branchIds, true);
    }
}
