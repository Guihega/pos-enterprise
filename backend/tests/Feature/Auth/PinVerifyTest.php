<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant']);
    TenantContext::set($this->tenant);
});

it('POST /auth/pin-verify con PIN correcto devuelve 200 valid:true', function () {
    $user = User::factory()->withPin('5872')->create([
        'company_id' => $this->tenant->id,
    ]);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/pin-verify',
        ['pin' => '5872'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk()->assertJsonPath('data.valid', true);
});

it('POST /auth/pin-verify con PIN incorrecto devuelve 401', function () {
    $user = User::factory()->withPin('5872')->create([
        'company_id' => $this->tenant->id,
    ]);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/pin-verify',
        ['pin' => '0000'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(401)->assertJsonPath('error.code', 'PIN_INVALID');
});

it('POST /auth/pin-verify sin PIN configurado devuelve 401', function () {
    $user = User::factory()->create(['company_id' => $this->tenant->id]);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/pin-verify',
        ['pin' => '5872'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(401)->assertJsonPath('error.code', 'PIN_INVALID');
});

it('POST /auth/pin-verify con formato inválido devuelve 422', function () {
    $user = User::factory()->withPin()->create(['company_id' => $this->tenant->id]);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/pin-verify',
        ['pin' => 'abcd'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422);
});

it('POST /auth/pin-verify sin token devuelve 401', function () {
    $response = $this->postJson('/api/v1/auth/pin-verify',
        ['pin' => '5872'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(401);
});
