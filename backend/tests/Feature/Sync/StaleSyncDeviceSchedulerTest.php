<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Scheduler RN-194: sync caida >2h notifica a admin (sync:detect-stale)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'STL',
    ]);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);
});

it('notifica device_stale WARNING al admin por dispositivo caido', function (): void {
    $stale = SyncDevice::factory()->ofBranch($this->branch)->stale(3)->create(['device_id' => 'dev-stl-caido']);
    SyncDevice::factory()->ofBranch($this->branch)->stale(1)->create(['device_id' => 'dev-stl-vivo']);
    SyncDevice::factory()->ofBranch($this->branch)->create(['device_id' => 'dev-stl-nunca', 'last_seen_at' => null]);

    $this->artisan('sync:detect-stale')->assertSuccessful();

    TenantContext::set($this->tenant);
    $notes = Notification::query()->forNotifiable($this->admin)->where('type', 'sync.device_stale')->get();

    expect($notes)->toHaveCount(1);
    expect($notes->first()->severity)->toBe(Notification::SEVERITY_WARNING);
    expect($notes->first()->data['device_id'])->toBe('dev-stl-caido');

    $fresh = SyncDevice::query()->where('device_id', 'dev-stl-caido')->firstOrFail();
    expect($fresh->stale_alerted_at)->not->toBeNull();
});

it('es idempotente y el heartbeat rearma la alerta (patron EX-042)', function (): void {
    $device = SyncDevice::factory()->ofBranch($this->branch)->stale(3)->create(['device_id' => 'dev-stl-idem']);

    $this->artisan('sync:detect-stale')->assertSuccessful();
    $this->artisan('sync:detect-stale')->assertSuccessful();

    TenantContext::set($this->tenant);
    expect(Notification::query()->forNotifiable($this->admin)->count())->toBe(1);

    // El dispositivo vuelve: el heartbeat limpia la marca...
    $device->refresh();
    SyncDevice::query()->where('id', $device->id)->update([
        'last_seen_at' => now(),
        'stale_alerted_at' => null,
    ]);

    // ...y una caida futura vuelve a alertar.
    SyncDevice::query()->where('id', $device->id)->update(['last_seen_at' => now()->subHours(5)]);
    $this->artisan('sync:detect-stale')->assertSuccessful();

    TenantContext::set($this->tenant);
    expect(Notification::query()->forNotifiable($this->admin)->count())->toBe(2);
});

it('ignora dispositivos inactivos y respeta --hours configurable', function (): void {
    $inactive = SyncDevice::factory()->ofBranch($this->branch)->stale(10)->create([
        'device_id' => 'dev-stl-off',
        'is_active' => false,
    ]);
    SyncDevice::factory()->ofBranch($this->branch)->stale(5)->create(['device_id' => 'dev-stl-5h']);

    $this->artisan('sync:detect-stale', ['--hours' => 8])->assertSuccessful();
    TenantContext::set($this->tenant);
    expect(Notification::query()->forNotifiable($this->admin)->count())->toBe(0);

    $this->artisan('sync:detect-stale', ['--hours' => 4])->assertSuccessful();
    TenantContext::set($this->tenant);
    $notes = Notification::query()->forNotifiable($this->admin)->get();
    expect($notes)->toHaveCount(1);
    expect($notes->first()->data['device_id'])->toBe('dev-stl-5h');
});
