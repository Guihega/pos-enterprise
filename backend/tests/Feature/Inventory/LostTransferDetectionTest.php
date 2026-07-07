<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Transfer;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->fromBranch = Branch::factory()->default()->create(['company_id' => $this->tenant->id, 'code' => 'ORI']);
    $this->toBranch = Branch::factory()->create(['company_id' => $this->tenant->id, 'code' => 'DES']);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);
});

function makeSentTransfer(int $daysAgo): Transfer
{
    $test = test();

    return Transfer::factory()->sent()->create([
        'company_id' => $test->tenant->id,
        'from_branch_id' => $test->fromBranch->id,
        'to_branch_id' => $test->toBranch->id,
        'sent_at' => now()->subDays($daysAgo),
    ]);
}

function lostTransferCount(Company $tenant): int
{
    TenantContext::set($tenant);

    return Notification::query()->where('type', 'transfer.lost')->count();
}

function runDetectLost(): void
{
    test()->artisan('transfers:detect-lost')->assertSuccessful();
}

it('alerta al admin sobre una transferencia perdida', function (): void {
    $transfer = makeSentTransfer(31);

    runDetectLost();

    TenantContext::set($this->tenant);
    $notifications = Notification::query()->where('type', 'transfer.lost')->get();

    expect($notifications)->toHaveCount(1);
    expect($notifications->first()->notifiable_id)->toBe($this->admin->getKey());
    expect($notifications->first()->severity)->toBe(Notification::SEVERITY_WARNING);
    expect($notifications->first()->data['transfer_uuid'])->toBe($transfer->uuid);

    // La transferencia queda marcada como alertada.
    TenantContext::set($this->tenant);
    expect(Transfer::query()->find($transfer->id)->lost_alerted_at)->not->toBeNull();
});

it('no repite la alerta en corridas posteriores', function (): void {
    makeSentTransfer(31);

    runDetectLost();
    runDetectLost(); // segunda corrida

    expect(lostTransferCount($this->tenant))->toBe(1);
});

it('no alerta sobre transferencias dentro del TTL', function (): void {
    makeSentTransfer(5); // reciente

    runDetectLost();

    expect(lostTransferCount($this->tenant))->toBe(0);
});

it('no alerta sobre transferencias ya recibidas', function (): void {
    $test = test();
    Transfer::factory()->received()->create([
        'company_id' => $this->tenant->id,
        'from_branch_id' => $this->fromBranch->id,
        'to_branch_id' => $this->toBranch->id,
        'sent_at' => now()->subDays(40),
        'received_at' => now()->subDays(39),
    ]);

    runDetectLost();

    expect(lostTransferCount($this->tenant))->toBe(0);
});

it('aisla la deteccion por tenant', function (): void {
    // Transferencia perdida en OTRO tenant no debe alertar al admin de este.
    $otro = Company::factory()->create();
    TenantContext::set($otro);
    app(RoleProvisioner::class)->provisionDefaultRoles($otro);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $otroFrom = Branch::factory()->default()->create(['company_id' => $otro->id, 'code' => 'OB1']);
    $otroTo = Branch::factory()->create(['company_id' => $otro->id, 'code' => 'OB2']);
    $otroAdmin = User::factory()->create(['company_id' => $otro->id]);
    $otroAdmin->assignRole(Roles::ADMIN);
    Transfer::factory()->sent()->create([
        'company_id' => $otro->id,
        'from_branch_id' => $otroFrom->id,
        'to_branch_id' => $otroTo->id,
        'sent_at' => now()->subDays(35),
    ]);

    runDetectLost();

    // El tenant principal no tiene transferencias perdidas -> 0 para su admin.
    expect(lostTransferCount($this->tenant))->toBe(0);
    // El otro tenant si recibe su alerta.
    expect(lostTransferCount($otro))->toBe(1);
});
