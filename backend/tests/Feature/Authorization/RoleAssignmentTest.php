<?php

declare(strict_types=1);

use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Permissions as Perms;
use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Aislamiento Multi-Tenant en Autorización (Bloque 1.3)
|--------------------------------------------------------------------------
|
| Tests críticos de seguridad: el sistema de roles/permisos respeta tenant.
|
*/

beforeEach(function () {
    $this->provisioner = app(RoleProvisioner::class);
});

it('provisiona los 6 roles default + permisos para un tenant nuevo', function () {
    $tenant = Company::factory()->create();
    $this->provisioner->provisionDefaultRoles($tenant);

    TenantContext::set($tenant);

    expect(Role::query()->count())->toBe(6)
        ->and(Role::query()->where('name', Roles::ADMIN)->exists())->toBeTrue()
        ->and(Role::query()->where('name', Roles::CAJERO)->exists())->toBeTrue()
        ->and(Permission::query()->count())->toBe(count(Perms::all()));
});

it('roles de tenant A no son visibles desde tenant B', function () {
    $tenantA = Company::factory()->create();
    $tenantB = Company::factory()->create();

    $this->provisioner->provisionDefaultRoles($tenantA);
    $this->provisioner->provisionDefaultRoles($tenantB);

    TenantContext::set($tenantA);
    $rolesInA = Role::query()->pluck('name')->all();

    TenantContext::set($tenantB);
    $rolesInB = Role::query()->pluck('name')->all();

    // Mismos nombres pero registros distintos (un admin de A no es el de B)
    expect($rolesInA)->toEqualCanonicalizing($rolesInB);

    // El registro DB es distinto: cada uno tiene su propio company_id
    TenantContext::set($tenantA);
    $adminA = Role::where('name', Roles::ADMIN)->first();

    TenantContext::set($tenantB);
    $adminB = Role::where('name', Roles::ADMIN)->first();

    expect($adminA->id)->not->toBe($adminB->id)
        ->and($adminA->company_id)->toBe($tenantA->id)
        ->and($adminB->company_id)->toBe($tenantB->id);
});

it('asignar un rol a un usuario respeta el tenant del usuario', function () {
    $tenant = Company::factory()->create();
    $this->provisioner->provisionDefaultRoles($tenant);
    TenantContext::set($tenant);

    $user = User::factory()->create(['company_id' => $tenant->id]);
    $user->assignRole(Roles::ADMIN);

    expect($user->hasRole(Roles::ADMIN))->toBeTrue()
        ->and($user->roles->count())->toBe(1)
        ->and($user->roles->first()->company_id)->toBe($tenant->id);
});

it('un usuario con rol admin de tenant A NO tiene permisos en tenant B', function () {
    $tenantA = Company::factory()->create();
    $tenantB = Company::factory()->create();

    $this->provisioner->provisionDefaultRoles($tenantA);
    $this->provisioner->provisionDefaultRoles($tenantB);

    TenantContext::set($tenantA);
    $userA = User::factory()->create(['company_id' => $tenantA->id]);
    $userA->assignRole(Roles::ADMIN);

    // Verificar que en su propio tenant tiene permisos
    expect($userA->can(Perms::PRODUCT_CREATE))->toBeTrue();

    // Cambiar de contexto al tenant B
    TenantContext::set($tenantB);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // Importante: refrescar el user para que `can` use el contexto nuevo
    $userARefreshed = User::withoutGlobalScopes()->find($userA->id);

    // Bajo contexto B, no debe tener permisos (su rol admin existe sólo en A)
    expect($userARefreshed->can(Perms::PRODUCT_CREATE))->toBeFalse();
});

it('un cajero NO tiene permiso de borrar productos', function () {
    $tenant = Company::factory()->create();
    $this->provisioner->provisionDefaultRoles($tenant);
    TenantContext::set($tenant);

    $cashier = User::factory()->create(['company_id' => $tenant->id]);
    $cashier->assignRole(Roles::CAJERO);

    expect($cashier->can(Perms::SALE_CREATE))->toBeTrue()
        ->and($cashier->can(Perms::PRODUCT_VIEW))->toBeTrue()
        ->and($cashier->can(Perms::PRODUCT_DELETE))->toBeFalse()
        ->and($cashier->can(Perms::USER_DELETE))->toBeFalse();
});

it('un auditor solo tiene permisos de vista', function () {
    $tenant = Company::factory()->create();
    $this->provisioner->provisionDefaultRoles($tenant);
    TenantContext::set($tenant);

    $auditor = User::factory()->create(['company_id' => $tenant->id]);
    $auditor->assignRole(Roles::AUDITOR);

    expect($auditor->can(Perms::PRODUCT_VIEW))->toBeTrue()
        ->and($auditor->can(Perms::SALE_VIEW))->toBeTrue()
        ->and($auditor->can(Perms::AUDIT_VIEW))->toBeTrue()
        ->and($auditor->can(Perms::PRODUCT_CREATE))->toBeFalse()
        ->and($auditor->can(Perms::SALE_CREATE))->toBeFalse()
        ->and($auditor->can(Perms::CASH_OPEN))->toBeFalse();
});

it('provisionar dos veces es idempotente (no duplica roles)', function () {
    $tenant = Company::factory()->create();

    $this->provisioner->provisionDefaultRoles($tenant);
    $this->provisioner->provisionDefaultRoles($tenant);

    TenantContext::set($tenant);
    expect(Role::query()->count())->toBe(6);
});

it('provisionar el admin tiene todos los permisos del catálogo de admin', function () {
    $tenant = Company::factory()->create();
    $this->provisioner->provisionDefaultRoles($tenant);
    TenantContext::set($tenant);

    $admin = User::factory()->create(['company_id' => $tenant->id]);
    $admin->assignRole(Roles::ADMIN);

    // El admin debe tener TODOS los permisos del matrix excepto super_admin
    foreach (Roles::defaultMatrix()[Roles::ADMIN] as $perm) {
        expect($admin->can($perm))->toBeTrue()
            ->and($admin->can($perm))
            ->toBeTrue("Admin debe tener '$perm'");
    }
});

// ====================================================================
//  Auditoria: permisos usados en controllers vs defaultMatrix()
// ====================================================================
//
// Objetivo: si un controller hace abort_unless(...->can(Permissions::X))
// y NINGUN rol del defaultMatrix() otorga ese permiso, el endpoint queda
// inalcanzable para TODOS los usuarios (403 perpetuo) sin que ningun
// test de feature lo detecte necesariamente. Este test escanea los
// controllers reales y lo verifica de forma permanente.

it('todo permiso usado en controllers Api/V1 esta otorgado por al menos un rol del defaultMatrix', function () {
    // glob con ** no recursa en PHP nativo; recorrer recursivamente.
    $controllerFiles = [];
    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Http/Controllers/Api/V1'))
    );
    foreach ($dir as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $controllerFiles[] = $file->getPathname();
        }
    }

    expect($controllerFiles)->not->toBeEmpty();

    $usedPermissions = [];
    foreach ($controllerFiles as $file) {
        $contents = file_get_contents($file);
        preg_match_all('/Permissions::([A-Z_]+)/', $contents, $matches);
        foreach ($matches[1] as $constName) {
            $usedPermissions[$constName] = true;
        }
    }

    expect($usedPermissions)->not->toBeEmpty();

    // Union de todos los permisos otorgados por algun rol.
    $grantedPermissions = [];
    foreach (Roles::defaultMatrix() as $perms) {
        foreach ($perms as $perm) {
            $grantedPermissions[$perm] = true;
        }
    }

    foreach (array_keys($usedPermissions) as $constName) {
        $value = constant(Perms::class.'::'.$constName);

        expect($grantedPermissions)
            ->toHaveKey($value, "Permission '{$value}' (Permissions::{$constName}) se usa en un controller pero ningun rol del defaultMatrix lo otorga: el endpoint quedaria inalcanzable para todos.");
    }
});
