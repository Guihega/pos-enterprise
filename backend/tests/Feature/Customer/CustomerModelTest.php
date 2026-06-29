<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
});

it('crea un cliente con UUID y respeta tenant', function () {
    $c = Customer::factory()->create();

    expect($c->uuid)->toBeUuid()
        ->and($c->company_id)->toBe($this->tenant->id);
});

it('aplica TenantScope al listar customers', function () {
    Customer::factory()->count(3)->create();

    $tenantB = Company::factory()->create();
    TenantContext::set($tenantB);
    Customer::factory()->count(2)->create(['company_id' => $tenantB->id]);

    expect(Customer::query()->count())->toBe(2);

    TenantContext::set($this->tenant);
    expect(Customer::query()->count())->toBe(3);
});

it('rechaza dos clientes con mismo email en mismo tenant', function () {
    Customer::factory()->create(['email' => 'a@b.com']);

    expectQueryException(function () {
        Customer::factory()->create(['email' => 'a@b.com']);
    });
});

it('permite múltiples clientes sin email (NULL)', function () {
    Customer::factory()->create(['email' => null]);
    Customer::factory()->create(['email' => null]);
    Customer::factory()->create(['email' => null]);

    expect(Customer::query()->count())->toBe(3);
});

it('rechaza tax_id duplicado en mismo tenant', function () {
    Customer::factory()->create(['tax_id' => 'XAXX010101000']);

    expectQueryException(function () {
        Customer::factory()->create(['tax_id' => 'XAXX010101000']);
    });
});

it('permite múltiples clientes sin tax_id', function () {
    Customer::factory()->count(5)->create(['tax_id' => null]);

    expect(Customer::query()->count())->toBe(5);
});

it('permite mismo email/tax_id en tenants distintos', function () {
    Customer::factory()->create(['email' => 'a@b.com', 'tax_id' => 'X1']);

    $tenantB = Company::factory()->create();
    TenantContext::set($tenantB);
    $c = Customer::factory()->create([
        'company_id' => $tenantB->id,
        'email' => 'a@b.com',
        'tax_id' => 'X1',
    ]);

    expect($c->id)->toBeGreaterThan(0);
});

it('rechaza credit_limit negativo (check constraint)', function () {
    expectQueryException(function () {
        Customer::factory()->create(['credit_limit' => -10]);
    });
});

it('credit_balance puede ser negativo (anticipos del cliente)', function () {
    $c = Customer::factory()->create(['credit_balance' => -500]);

    expect((float) $c->credit_balance)->toBe(-500.0);
});

it('canBuyOnCredit verifica límite y bloqueos', function () {
    $c = Customer::factory()->withCredit(1000)->create([
        'credit_balance' => 600,
    ]);

    expect($c->canBuyOnCredit(300))->toBeTrue()  // 600 + 300 = 900 ≤ 1000
        ->and($c->canBuyOnCredit(500))->toBeFalse()  // 600 + 500 = 1100 > 1000
        ->and($c->availableCredit())->toBe(400.0);
});

it('canBuyOnCredit devuelve false si está bloqueado', function () {
    $c = Customer::factory()->withCredit(10000)->blocked()->create();

    expect($c->canBuyOnCredit(1))->toBeFalse();
});

it('factory business setea type=business y legal_name', function () {
    $c = Customer::factory()->business()->create();

    expect($c->type)->toBe(Customer::TYPE_BUSINESS)
        ->and($c->legal_name)->not->toBeNull()
        ->and($c->isBusiness())->toBeTrue();
});

it('soft delete oculta pero conserva el registro', function () {
    $c = Customer::factory()->create();
    $id = $c->id;

    $c->delete();

    expect(Customer::query()->find($id))->toBeNull();
    expect(Customer::query()->withTrashed()->find($id))->not->toBeNull();
});

it('scope search encuentra por name, email, phone, tax_id', function () {
    Customer::factory()->create(['name' => 'Juan Pérez', 'email' => 'juan@x.com']);
    Customer::factory()->create(['name' => 'María López', 'email' => 'maria@x.com']);
    Customer::factory()->create(['name' => 'Pedro Sánchez', 'tax_id' => 'PESJ800101']);

    expect(Customer::query()->search('Juan')->count())->toBe(1)
        ->and(Customer::query()->search('PESJ800101')->count())->toBe(1)
        ->and(Customer::query()->search('maria@')->count())->toBe(1);
});

it('schema sentinel: customers tiene índices únicos parciales', function () {
    $idx = DB::select("
        SELECT indexname FROM pg_indexes
        WHERE tablename = 'customers' AND indexname LIKE 'customers_%_unique'
    ");

    $names = array_map(fn ($r) => $r->indexname, $idx);

    expect($names)->toContain('customers_company_code_unique');
    expect($names)->toContain('customers_company_email_unique');
    expect($names)->toContain('customers_company_tax_id_unique');
});
