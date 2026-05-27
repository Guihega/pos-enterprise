<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Permissions as PermissionCatalog;
use App\Domain\Authorization\Roles as RoleCatalog;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea los roles + permisos default para un tenant.
 *
 * Idempotente: si el tenant ya tiene los roles, los actualiza para
 * coincidir con el catálogo (sin perder asignaciones a usuarios).
 *
 * Uso típico:
 *   - En el seeder principal (cada tenant demo).
 *   - Como hook al crear un nuevo tenant (registro de cuenta).
 */
final class RoleProvisioner
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * Provisiona TODOS los roles default para el tenant indicado.
     */
    public function provisionDefaultRoles(Company $company, string $guard = 'web'): void
    {
        // Operamos siempre en el contexto del tenant correcto
        TenantContext::runAs($company, function () use ($company, $guard): void {
            DB::transaction(function () use ($company, $guard): void {
                // 1. Crear permisos (idempotente)
                $permissions = [];
                foreach (PermissionCatalog::all() as $permName) {
                    $permissions[$permName] = Permission::firstOrCreate([
                        'company_id' => $company->id,
                        'name' => $permName,
                        'guard_name' => $guard,
                    ]);
                }

                // 2. Crear roles + sincronizar sus permisos
                foreach (RoleCatalog::defaultMatrix() as $roleName => $permList) {
                    $role = Role::firstOrCreate([
                        'company_id' => $company->id,
                        'name' => $roleName,
                        'guard_name' => $guard,
                    ]);

                    $rolePermissions = array_map(
                        fn (string $name) => $permissions[$name],
                        $permList
                    );

                    $role->syncPermissions($rolePermissions);
                }
            });

            $this->registrar->forgetCachedPermissions();
        });
    }
}
