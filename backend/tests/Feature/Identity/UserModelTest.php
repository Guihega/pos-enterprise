<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Exceptions\CrossTenantAccessException;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
});

it('crea un usuario con UUID automático y password hasheado', function () {
    $user = User::factory()->create([
        'company_id' => $this->tenant->id,
        'email' => 'test@example.com',
        'password' => 'plain-password',
    ]);

    expect($user->uuid)->toBeUuid()
        ->and($user->company_id)->toBe($this->tenant->id)
        ->and(Hash::check('plain-password', $user->password))->toBeTrue();
});

it('aplica scope multi-tenant en queries de usuarios', function () {
    $tenantB = Company::factory()->create();

    User::factory()->count(3)->create(['company_id' => $this->tenant->id]);

    // Cambiar contexto antes de crear los del tenant B
    TenantContext::set($tenantB);
    User::factory()->count(2)->create(['company_id' => $tenantB->id]);

    // Verificar aislamiento desde A
    TenantContext::set($this->tenant);
    expect(User::query()->count())->toBe(3);

    // Verificar aislamiento desde B
    TenantContext::set($tenantB);
    expect(User::query()->count())->toBe(2);
});

it('rechaza crear un usuario con company_id de otro tenant', function () {
    $tenantB = Company::factory()->create();

    expect(fn () => User::factory()->create(['company_id' => $tenantB->id]))
        ->toThrow(CrossTenantAccessException::class);
});

it('soporta múltiples sucursales por usuario', function () {
    $branchA = Branch::factory()->create(['company_id' => $this->tenant->id]);
    $branchB = Branch::factory()->create(['company_id' => $this->tenant->id]);

    $user = User::factory()->create(['company_id' => $this->tenant->id]);
    $user->syncBranches([$branchA->id, $branchB->id]);

    expect($user->branches)->toHaveCount(2);
});

it('isLocked() devuelve true si locked_until está en el futuro', function () {
    $user = User::factory()->locked()->create(['company_id' => $this->tenant->id]);

    expect($user->isLocked())->toBeTrue()
        ->and($user->isOperational())->toBeFalse();
});

it('isLocked() devuelve false si locked_until ya pasó', function () {
    $user = User::factory()->create([
        'company_id' => $this->tenant->id,
        'locked_until' => now()->subMinute(),
    ]);

    expect($user->isLocked())->toBeFalse();
});

it('registerFailedLogin() incrementa contador y bloquea tras 5 intentos', function () {
    $user = User::factory()->create(['company_id' => $this->tenant->id]);

    for ($i = 1; $i <= 4; $i++) {
        $user->registerFailedLogin();
        expect($user->isLocked())->toBeFalse();
    }

    $user->registerFailedLogin();  // 5to intento

    expect($user->failed_login_attempts)->toBe(5)
        ->and($user->isLocked())->toBeTrue()
        ->and($user->locked_until)->not->toBeNull()
        ->and($user->locked_until->isFuture())->toBeTrue()
        ->and(now()->diffInMinutes($user->locked_until, false))->toBeGreaterThan(10);
});

it('registerSuccessfulLogin() limpia contadores y registra metadatos', function () {
    $user = User::factory()->create([
        'company_id' => $this->tenant->id,
        'failed_login_attempts' => 3,
    ]);

    $user->registerSuccessfulLogin('203.0.113.42', 'Mozilla/5.0', 'device-uuid-123');

    expect($user->failed_login_attempts)->toBe(0)
        ->and($user->locked_until)->toBeNull()
        ->and($user->last_login_ip)->toBe('203.0.113.42')
        ->and($user->last_login_user_agent)->toBe('Mozilla/5.0')
        ->and($user->last_login_device_id)->toBe('device-uuid-123')
        ->and($user->last_login_at)->not->toBeNull();
});

it('setPin() acepta PIN válido de 4-8 dígitos', function () {
    $user = User::factory()->create(['company_id' => $this->tenant->id]);

    $user->setPin('5872');

    expect($user->pin_hash)->not->toBeNull()
        ->and($user->pin_set_at)->not->toBeNull();
});

it('setPin() rechaza PIN no numérico o longitud incorrecta', function () {
    $user = User::factory()->create(['company_id' => $this->tenant->id]);

    expect(fn () => $user->setPin('abc'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $user->setPin('123'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $user->setPin('123456789'))
        ->toThrow(InvalidArgumentException::class);
});

it('setPin() rechaza PINs triviales', function () {
    $user = User::factory()->create(['company_id' => $this->tenant->id]);

    foreach (['1111', '0000', '1234', '4321', '9999'] as $trivial) {
        expect(fn () => $user->setPin($trivial))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('verifyPin() valida correctamente y limpia contadores', function () {
    $user = User::factory()->withPin('5872')->create(['company_id' => $this->tenant->id]);

    expect($user->verifyPin('5872'))->toBeTrue()
        ->and($user->verifyPin('0000'))->toBeFalse()
        ->and($user->fresh()->pin_failed_attempts)->toBe(1);
});

it('verifyPin() bloquea tras 10 intentos fallidos', function () {
    $user = User::factory()->withPin('5872')->create(['company_id' => $this->tenant->id]);

    for ($i = 0; $i < 10; $i++) {
        $user->verifyPin('0000');
    }

    $fresh = $user->fresh();
    expect($fresh->pin_locked_until)->not->toBeNull()
        ->and($fresh->pin_locked_until->isFuture())->toBeTrue();

    // PIN correcto debe ser rechazado durante el bloqueo
    expect($fresh->verifyPin('5872'))->toBeFalse();
});

it('hidden attributes no se serializan en JSON', function () {
    $user = User::factory()->withPin()->create(['company_id' => $this->tenant->id]);

    $json = $user->toArray();

    expect($json)->not->toHaveKey('password')
        ->and($json)->not->toHaveKey('pin_hash')
        ->and($json)->not->toHaveKey('two_factor_secret')
        ->and($json)->not->toHaveKey('remember_token');
});
