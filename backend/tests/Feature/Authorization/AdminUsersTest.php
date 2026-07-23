<?php

declare(strict_types=1);

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Tests admin de usuarios + roles vía HTTP
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant']);
    TenantContext::set($this->tenant);

    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('GET /admin/users con un cajero responde 403', function () {
    $cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $cashier->assignRole(Roles::CAJERO);
    Sanctum::actingAs($cashier);

    $response = $this->getJson('/api/v1/admin/users', ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(403);
});

it('GET /admin/users con un admin responde 200 con paginación', function () {
    $admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $admin->assignRole(Roles::ADMIN);

    User::factory()->count(5)->create(['company_id' => $this->tenant->id]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/users', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['uuid', 'name', 'email']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

    expect($response->json('meta.total'))->toBe(6);  // admin + 5 más
});

it('admin puede asignar rol cajero a otro usuario', function () {
    $admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $admin->assignRole(Roles::ADMIN);

    $target = User::factory()->create(['company_id' => $this->tenant->id]);

    Sanctum::actingAs($admin);

    $response = $this->postJson(
        "/api/v1/admin/users/{$target->uuid}/roles",
        ['roles' => [Roles::CAJERO]],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk();

    // Verificar directo en BD evitando cache de Spatie tras el HTTP request.
    // El pivot model_has_roles debe tener una fila con (target.id, role_cajero.id, company_id).
    $this->assertDatabaseHas('model_has_roles', [
        'model_id' => $target->id,
        'company_id' => $this->tenant->id,
    ]);

    // Y el rol asociado debe ser efectivamente "cajero".
    TenantContext::set($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($target->fresh()->hasRole(Roles::CAJERO))->toBeTrue();
});

it('cajero NO puede asignar roles a otros (403)', function () {
    $cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $cashier->assignRole(Roles::CAJERO);

    $target = User::factory()->create(['company_id' => $this->tenant->id]);

    Sanctum::actingAs($cashier);

    $response = $this->postJson(
        "/api/v1/admin/users/{$target->uuid}/roles",
        ['roles' => [Roles::ADMIN]],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(403);
});

it('asignar un rol que no existe devuelve 422', function () {
    $admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $admin->assignRole(Roles::ADMIN);

    $target = User::factory()->create(['company_id' => $this->tenant->id]);

    Sanctum::actingAs($admin);

    $response = $this->postJson(
        "/api/v1/admin/users/{$target->uuid}/roles",
        ['roles' => ['rol_inexistente']],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422);
});

it('asignar roles deja rastro en activity_log con antes y despues (RN-177)', function () {
    $admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $admin->assignRole(Roles::ADMIN);
    $target = User::factory()->create(['company_id' => $this->tenant->id]);
    Sanctum::actingAs($admin);

    $this->postJson(
        "/api/v1/admin/users/{$target->uuid}/roles",
        ['roles' => [Roles::CAJERO]],
        ['X-Tenant' => 'mi-tenant']
    )->assertOk();

    TenantContext::set($this->tenant);
    $log = ActivityLog::query()
        ->where('event', 'role.synced')
        ->where('subject_id', $target->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->log_name)->toBe('security')
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->causer_name)->toBe($admin->name)
        ->and($log->properties['roles_before'])->toBe([])
        ->and($log->properties['roles_after'])->toEqualCanonicalizing([Roles::CAJERO]);
});
