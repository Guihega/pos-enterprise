<?php

declare(strict_types=1);

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'audit-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('registra una entrada con subject y properties', function () {
    $user = User::factory()->create(['company_id' => $this->tenant->id]);

    $log = app(ActivityLogger::class)->log(
        logName: 'test',
        event: 'created',
        description: 'entrada de prueba',
        subject: $user,
        properties: ['clave' => 'valor'],
    );

    $log->refresh();
    expect($log->company_id)->toBe($this->tenant->id)
        ->and($log->subject_type)->toBe($user->getMorphClass())
        ->and($log->subject_id)->toBe($user->id)
        ->and($log->event)->toBe('created')
        ->and($log->severity)->toBe('info')
        ->and($log->properties)->toBe(['clave' => 'valor'])
        ->and($log->created_at)->not->toBeNull();
});

it('la BD aborta UPDATE sobre activity_log (RN-171)', function () {
    app(ActivityLogger::class)->log('test', 'created', 'inmutable');

    expectQueryException(function () {
        DB::table('activity_log')->update(['description' => 'mutada']);
    });
});

it('la BD aborta DELETE sobre activity_log (RN-171)', function () {
    app(ActivityLogger::class)->log('test', 'created', 'inmutable');

    expectQueryException(function () {
        DB::table('activity_log')->delete();
    });
});
