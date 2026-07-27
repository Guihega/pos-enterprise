<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\PasswordResetToken;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Recuperacion y cambio de password (flujo 57.6) via HTTP
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'pwd-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->cajero = User::factory()->create([
        'company_id' => $this->tenant->id,
        'email' => 'cajero@pwd-test.mx',
        'password' => 'password-vieja',
    ]);
    $this->cajero->assignRole(Roles::CAJERO);
});

it('admin emite token de reset y el cajero lo canjea', function () {
    Sanctum::actingAs($this->admin);

    $resp = $this->postJson(
        "/api/v1/admin/users/{$this->cajero->uuid}/reset-password",
        [],
        ['X-Tenant' => 'pwd-test'],
    );

    $resp->assertStatus(201);
    $token = $resp->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty();

    TenantContext::set($this->tenant);
    $row = PasswordResetToken::query()->where('email', $this->cajero->email)->first();
    expect($row)->not->toBeNull();
    expect($row->token)->not->toBe($token);
    expect($row->created_by)->toBe($this->admin->id);
    expect($row->used_at)->toBeNull();

    $resp2 = $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'cajero@pwd-test.mx',
        'token' => $token,
        'password' => 'password-nueva-123',
    ], ['X-Tenant' => 'pwd-test']);
    $resp2->assertStatus(200);

    TenantContext::set($this->tenant);
    $fresh = User::query()->where('email', 'cajero@pwd-test.mx')->first();
    expect(Hash::check('password-nueva-123', $fresh->password))->toBeTrue();
    expect(PasswordResetToken::query()->whereNotNull('used_at')->count())->toBe(1);
});

it('el token no se puede usar dos veces', function () {
    Sanctum::actingAs($this->admin);

    $token = $this->postJson(
        "/api/v1/admin/users/{$this->cajero->uuid}/reset-password",
        [],
        ['X-Tenant' => 'pwd-test'],
    )->json('data.token');

    $payload = [
        'email' => 'cajero@pwd-test.mx',
        'token' => $token,
        'password' => 'primera-vez-123',
    ];

    $this->postJson('/api/v1/auth/reset-password', $payload, ['X-Tenant' => 'pwd-test'])
        ->assertStatus(200);

    $payload['password'] = 'segunda-vez-123';
    $this->postJson('/api/v1/auth/reset-password', $payload, ['X-Tenant' => 'pwd-test'])
        ->assertStatus(422);

    TenantContext::set($this->tenant);
    $fresh = User::query()->where('email', 'cajero@pwd-test.mx')->first();
    expect(Hash::check('primera-vez-123', $fresh->password))->toBeTrue();
});

it('un token vencido se rechaza', function () {
    Sanctum::actingAs($this->admin);

    $token = $this->postJson(
        "/api/v1/admin/users/{$this->cajero->uuid}/reset-password",
        [],
        ['X-Tenant' => 'pwd-test'],
    )->json('data.token');

    TenantContext::set($this->tenant);
    PasswordResetToken::query()
        ->where('email', $this->cajero->email)
        ->update(['expires_at' => Carbon::now()->subMinutes(5)]);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'cajero@pwd-test.mx',
        'token' => $token,
        'password' => 'no-deberia-pasar-123',
    ], ['X-Tenant' => 'pwd-test'])->assertStatus(422);
});

it('cajero sin permiso no puede emitir resets', function () {
    Sanctum::actingAs($this->cajero);

    $this->postJson(
        "/api/v1/admin/users/{$this->admin->uuid}/reset-password",
        [],
        ['X-Tenant' => 'pwd-test'],
    )->assertStatus(403);

    TenantContext::set($this->tenant);
    expect(PasswordResetToken::query()->count())->toBe(0);
});

it('cambio voluntario exige la password actual', function () {
    Sanctum::actingAs($this->cajero);

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'la-que-no-es',
        'new_password' => 'intento-fallido-123',
    ], ['X-Tenant' => 'pwd-test'])->assertStatus(401);

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'password-vieja',
        'new_password' => 'cambio-legitimo-123',
    ], ['X-Tenant' => 'pwd-test'])->assertStatus(200);

    TenantContext::set($this->tenant);
    $fresh = User::query()->where('email', 'cajero@pwd-test.mx')->first();
    expect(Hash::check('cambio-legitimo-123', $fresh->password))->toBeTrue();
});

it('el reset deja rastro en activity_log', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson(
        "/api/v1/admin/users/{$this->cajero->uuid}/reset-password",
        [],
        ['X-Tenant' => 'pwd-test'],
    )->assertStatus(201);

    TenantContext::set($this->tenant);
    $this->assertDatabaseHas('activity_log', [
        'company_id' => $this->tenant->id,
        'log_name' => 'identity',
        'event' => 'password.reset_issued',
    ]);
});
