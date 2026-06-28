<?php

declare(strict_types=1);
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Services\TenantTeamResolver;

return [

    'models' => [
        // Modelos custom para que apliquen BelongsToTenant + RLS
        'permission' => Permission::class,
        'role' => Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        // Renombrado: en vez de 'team_id' usamos 'company_id' (consistencia)
        'team_foreign_key' => 'company_id',
    ],

    /*
     * Habilita teams. Cada role/permission lleva company_id, los pivots
     * model_has_roles y model_has_permissions también.
     *
     * El team_id activo se obtiene del PermissionsTeamResolver
     * (configurado en app('config')->set abajo o vía bind en el provider).
     */
    'teams' => true,

    /*
     * Resolver custom: lee el team_id desde nuestro TenantContext en lugar
     * de la sesión (que es el default). Garantiza que jobs, comandos y
     * controllers siempre usen el mismo origen de verdad.
     */
    'team_resolver' => TenantTeamResolver::class,

    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,
    'enable_wildcard_permission' => false,

    'cache' => [
        // 24 horas. El cache se invalida automáticamente al asignar/revocar
        // roles, así que esto es solo el ceiling.
        'expiration_time' => DateInterval::createFromDateString('24 hours'),

        // Distinguir cache por tenant: el key es 'spatie.permission.cache.{tenant_id}'
        'key' => 'spatie.permission.cache',

        'store' => 'default',
    ],
];
