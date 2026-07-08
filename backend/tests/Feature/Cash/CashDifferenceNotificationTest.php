<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->create([
        'company_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->gerente = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->gerente->assignRole(Roles::GERENTE);
    $this->gerente->syncBranches([$this->branch]);

    $this->cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cajero->assignRole(Roles::CAJERO);
    $this->cajero->syncBranches([$this->branch]);
});

function cashDifferenceCount(Company $tenant): int
{
    TenantContext::set($tenant);

    return Notification::query()->where('type', 'cash.difference')->count();
}

it('notifica al gerente cuando la diferencia de caja es significativa', function (): void {
    $cash = app(CashService::class);
    $session = $cash->openSession($this->register, $this->cajero, 1000);
    $cash->closeSession($session, $this->cajero, 950); // -50 = 5% > 2%

    TenantContext::set($this->tenant);
    $notifications = Notification::query()->where('type', 'cash.difference')->get();

    expect($notifications)->toHaveCount(1);
    expect($notifications->first()->notifiable_id)->toBe($this->gerente->getKey());
    expect($notifications->first()->severity)->toBe(Notification::SEVERITY_WARNING);
});

it('no notifica cuando la diferencia esta bajo el umbral', function (): void {
    $cash = app(CashService::class);
    $session = $cash->openSession($this->register, $this->cajero, 1000);
    $cash->closeSession($session, $this->cajero, 990); // -10 = 1% < 2%

    expect(cashDifferenceCount($this->tenant))->toBe(0);
});

it('no notifica cuando la caja cuadra exacto', function (): void {
    $cash = app(CashService::class);
    $session = $cash->openSession($this->register, $this->cajero, 1000);
    $cash->closeSession($session, $this->cajero, 1000); // diff 0

    expect(cashDifferenceCount($this->tenant))->toBe(0);
});

it('notifica con fondo cero y diferencia real', function (): void {
    $cash = app(CashService::class);
    $session = $cash->openSession($this->register, $this->cajero, 0);
    $cash->closeSession($session, $this->cajero, 30); // expected 0, diff 30

    expect(cashDifferenceCount($this->tenant))->toBe(1);
});

it('el gerente ve la alerta de diferencia via el endpoint de notificaciones', function (): void {
    $cash = app(CashService::class);
    $session = $cash->openSession($this->register, $this->cajero, 1000);
    $cash->closeSession($session, $this->cajero, 900); // -100 = 10%

    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->gerente);
    $response = $this->withHeader('X-Tenant', $this->tenant->slug)
        ->getJson('/api/v1/notifications');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.type'))->toBe('cash.difference');
});
