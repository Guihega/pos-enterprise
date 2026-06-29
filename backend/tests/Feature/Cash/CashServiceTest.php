<?php

declare(strict_types=1);

use App\Domain\Cash\Exceptions\CashSessionAlreadyOpenException;
use App\Domain\Cash\Exceptions\CashSessionNotOpenException;
use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Cash\Services\CashService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-01']);
    $this->user = User::factory()->create(['company_id' => $this->tenant->id]);

    $this->service = app(CashService::class);
});

// ====================================================================
//  openSession
// ====================================================================

it('openSession crea una sesión con status open', function () {
    $session = $this->service->openSession($this->register, $this->user, 1000);

    expect($session->status)->toBe(CashSession::STATUS_OPEN)
        ->and((float) $session->opening_amount)->toBe(1000.0)
        ->and($session->opened_by)->toBe($this->user->id)
        ->and($session->cash_register_id)->toBe($this->register->id);
});

it('openSession con caja ya abierta lanza CashSessionAlreadyOpenException', function () {
    $this->service->openSession($this->register, $this->user, 0);

    expect(fn () => $this->service->openSession($this->register, $this->user, 0))
        ->toThrow(CashSessionAlreadyOpenException::class);
});

it('openSession en distinta caja del mismo tenant funciona', function () {
    $register2 = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-02']);

    $s1 = $this->service->openSession($this->register, $this->user, 0);
    $s2 = $this->service->openSession($register2, $this->user, 0);

    expect(CashSession::query()->where('status', 'open')->count())->toBe(2);
});

// ====================================================================
//  Movements
// ====================================================================

it('addMovement cash_in registra delta_signed positivo', function () {
    $session = $this->service->openSession($this->register, $this->user, 0);

    $m = $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_CASH_IN,
        amount: 200, reason: 'Fondo extra'
    );

    expect((float) $m->amount)->toBe(200.0)
        ->and((float) $m->delta_signed)->toBe(200.0);
});

it('addMovement cash_out registra delta_signed negativo', function () {
    $session = $this->service->openSession($this->register, $this->user, 0);

    $m = $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_CASH_OUT,
        amount: 50, reason: 'Pago a proveedor'
    );

    expect((float) $m->delta_signed)->toBe(-50.0);
});

it('addMovement adjustment requiere signOverride', function () {
    $session = $this->service->openSession($this->register, $this->user, 0);

    expect(fn () => $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_ADJUSTMENT,
        amount: 10, reason: 'X'
    ))->toThrow(InvalidArgumentException::class);
});

it('addMovement adjustment con sign=-1 va negativo', function () {
    $session = $this->service->openSession($this->register, $this->user, 0);

    $m = $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_ADJUSTMENT,
        amount: 5, reason: 'Diferencia menor', signOverride: -1
    );

    expect((float) $m->delta_signed)->toBe(-5.0);
});

it('addMovement en sesión cerrada lanza CashSessionNotOpenException', function () {
    $session = $this->service->openSession($this->register, $this->user, 0);
    $this->service->closeSession($session, $this->user, 0);

    expect(fn () => $this->service->addMovement(
        session: $session->fresh(), user: $this->user,
        type: CashMovement::TYPE_CASH_IN, amount: 10, reason: 'X'
    ))->toThrow(CashSessionNotOpenException::class);
});

it('addMovement con amount <= 0 lanza error', function () {
    $session = $this->service->openSession($this->register, $this->user, 0);

    expect(fn () => $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_CASH_IN, amount: 0, reason: 'X'
    ))->toThrow(InvalidArgumentException::class);
});

// ====================================================================
//  closeSession + cálculo expected
// ====================================================================

it('closeSession calcula expected = opening + cash_in - cash_out', function () {
    $session = $this->service->openSession($this->register, $this->user, 1000);

    $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_CASH_IN, amount: 300, reason: 'Refuerzo'
    );
    $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_CASH_OUT, amount: 100, reason: 'Pago café'
    );

    $closed = $this->service->closeSession($session, $this->user, countedAmount: 1200);

    // expected = 1000 + 300 - 100 = 1200
    expect((float) $closed->expected_amount)->toBe(1200.0)
        ->and((float) $closed->counted_amount)->toBe(1200.0)
        ->and((float) $closed->difference)->toBe(0.0)
        ->and($closed->status)->toBe(CashSession::STATUS_CLOSED);
});

it('closeSession con conteo distinto a expected calcula difference', function () {
    $session = $this->service->openSession($this->register, $this->user, 1000);
    $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_CASH_IN, amount: 100, reason: 'X'
    );

    // Expected: 1000 + 100 = 1100. Cajero contó 1095 (faltante de 5)
    $closed = $this->service->closeSession($session, $this->user, countedAmount: 1095);

    expect((float) $closed->expected_amount)->toBe(1100.0)
        ->and((float) $closed->difference)->toBe(-5.0);
});

it('closeSession sale_other no afecta el efectivo físico', function () {
    $session = $this->service->openSession($this->register, $this->user, 1000);

    $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_SALE_OTHER, amount: 500, reason: 'Tarjeta'
    );
    // sale_other → sign 0 → no afecta delta_signed cash-affecting

    $closed = $this->service->closeSession($session, $this->user, countedAmount: 1000);

    expect((float) $closed->expected_amount)->toBe(1000.0)
        ->and((float) $closed->difference)->toBe(0.0);
});

it('closeSession en sesión ya cerrada lanza CashSessionNotOpenException', function () {
    $session = $this->service->openSession($this->register, $this->user, 0);
    $this->service->closeSession($session, $this->user, 0);

    expect(fn () => $this->service->closeSession($session->fresh(), $this->user, 0))
        ->toThrow(CashSessionNotOpenException::class);
});

it('después de cerrar, se puede abrir una nueva sesión en la misma caja', function () {
    $s1 = $this->service->openSession($this->register, $this->user, 0);
    $this->service->closeSession($s1, $this->user, 0);

    $s2 = $this->service->openSession($this->register, $this->user, 500);

    expect($s2->id)->not->toBe($s1->id)
        ->and($s2->status)->toBe(CashSession::STATUS_OPEN);
});

// ====================================================================
//  Inmutabilidad de cash_movements
// ====================================================================

it('cash_movements es inmutable: UPDATE bloqueado por trigger BD', function () {
    $session = $this->service->openSession($this->register, $this->user, 0);
    $m = $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_CASH_IN, amount: 50, reason: 'X'
    );

    expectQueryException(function () use ($m) {
        DB::table('cash_movements')
            ->where('id', $m->id)
            ->update(['amount' => 9999]);
    });
});

it('cash_movements es inmutable: DELETE bloqueado por trigger BD', function () {
    $session = $this->service->openSession($this->register, $this->user, 0);
    $m = $this->service->addMovement(
        session: $session, user: $this->user,
        type: CashMovement::TYPE_CASH_IN, amount: 50, reason: 'X'
    );

    expectQueryException(function () use ($m) {
        DB::table('cash_movements')
            ->where('id', $m->id)
            ->delete();
    });
});

// ====================================================================
//  Aislamiento
// ====================================================================

it('aísla cash_sessions entre tenants', function () {
    $this->service->openSession($this->register, $this->user, 0);

    $tenantB = Company::factory()->create();
    TenantContext::set($tenantB);
    $branchB = Branch::factory()->default()->create(['company_id' => $tenantB->id]);
    $registerB = CashRegister::factory()->ofBranch($branchB)->create();
    $userB = User::factory()->create(['company_id' => $tenantB->id]);

    $this->service->openSession($registerB, $userB, 0);

    expect(CashSession::query()->count())->toBe(1);  // solo la de B desde su contexto

    TenantContext::set($this->tenant);
    expect(CashSession::query()->count())->toBe(1);
});
