<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\TransferRequest;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->fromBranch = Branch::factory()->default()->create(['company_id' => $this->tenant->id, 'code' => 'ORI']);
    $this->toBranch = Branch::factory()->create(['company_id' => $this->tenant->id, 'code' => 'DES']);

    $unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id,
        'sku' => 'SKU-TRQH-1',
        'price' => 100,
        'track_inventory' => true,
    ]);

    $this->gerente = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->gerente->assignRole(Roles::GERENTE);
    $this->gerente->syncBranches([$this->toBranch]);

    $this->originManager = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->originManager->assignRole(Roles::GERENTE);
    $this->originManager->syncBranches([$this->fromBranch]);

    $this->cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cajero->assignRole(Roles::CAJERO);

    $this->auditor = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->auditor->assignRole(Roles::AUDITOR);

    $this->headers = ['X-Tenant' => $this->tenant->slug];
});

function trqHttpPayload(): array
{
    $test = test();

    return [
        'from_branch_uuid' => $test->fromBranch->uuid,
        'to_branch_uuid' => $test->toBranch->uuid,
        'items' => [
            ['product_uuid' => $test->product->uuid, 'quantity' => 5],
        ],
    ];
}

function trqHttpCreatePending(): TransferRequest
{
    $test = test();

    Sanctum::actingAs($test->gerente);
    $response = $test->postJson('/api/v1/transfer-requests', trqHttpPayload(), $test->headers);
    $response->assertCreated();

    TenantContext::set($test->tenant);

    return TransferRequest::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
}

it('el gerente crea una solicitud y queda pending', function (): void {
    Sanctum::actingAs($this->gerente);

    $response = $this->postJson('/api/v1/transfer-requests', trqHttpPayload(), $this->headers);

    $response->assertCreated()
        ->assertJsonPath('data.status', TransferRequest::STATUS_PENDING)
        ->assertJsonPath('data.from_branch.code', 'ORI')
        ->assertJsonPath('data.to_branch.code', 'DES');

    expect($response->json('data.folio'))->toStartWith('TRQ-');
    expect($response->json('data.items.0.quantity'))->toBe(5);
});

it('el cajero no puede crear solicitudes', function (): void {
    Sanctum::actingAs($this->cajero);

    $this->postJson('/api/v1/transfer-requests', trqHttpPayload(), $this->headers)
        ->assertStatus(403);
});

it('valida origen distinto de destino y lineas presentes', function (): void {
    Sanctum::actingAs($this->gerente);

    $payload = trqHttpPayload();
    $payload['to_branch_uuid'] = $this->fromBranch->uuid;
    $payload['items'] = [];

    $this->postJson('/api/v1/transfer-requests', $payload, $this->headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to_branch_uuid', 'items']);
});

it('el auditor puede listar pero no crear', function (): void {
    trqHttpCreatePending();

    Sanctum::actingAs($this->auditor);

    $response = $this->getJson('/api/v1/transfer-requests', $this->headers);
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);

    $this->postJson('/api/v1/transfer-requests', trqHttpPayload(), $this->headers)
        ->assertStatus(403);
});

it('el gerente de origen aprueba y la respuesta liga el transfer creado', function (): void {
    $request = trqHttpCreatePending();

    Sanctum::actingAs($this->originManager);

    $response = $this->postJson("/api/v1/transfer-requests/{$request->uuid}/approve", [], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.status', TransferRequest::STATUS_APPROVED);

    expect($response->json('data.transfer.uuid'))->not->toBeNull()
        ->and($response->json('data.transfer.status'))->toBe('draft');
});

it('el cajero no puede aprobar', function (): void {
    $request = trqHttpCreatePending();

    Sanctum::actingAs($this->cajero);

    $this->postJson("/api/v1/transfer-requests/{$request->uuid}/approve", [], $this->headers)
        ->assertStatus(403);
});

it('rechazar requiere motivo y lo persiste', function (): void {
    $request = trqHttpCreatePending();

    Sanctum::actingAs($this->originManager);

    $this->postJson("/api/v1/transfer-requests/{$request->uuid}/reject", [], $this->headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->postJson("/api/v1/transfer-requests/{$request->uuid}/reject", ['reason' => 'Sin stock'], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.status', TransferRequest::STATUS_REJECTED)
        ->assertJsonPath('data.rejection_reason', 'Sin stock');
});

it('solo el solicitante puede cancelar su solicitud', function (): void {
    $request = trqHttpCreatePending();

    Sanctum::actingAs($this->originManager);
    $this->postJson("/api/v1/transfer-requests/{$request->uuid}/cancel", [], $this->headers)
        ->assertStatus(403);

    Sanctum::actingAs($this->gerente);
    $this->postJson("/api/v1/transfer-requests/{$request->uuid}/cancel", [], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.status', TransferRequest::STATUS_CANCELLED);
});

it('aprobar una solicitud rechazada devuelve 409', function (): void {
    $request = trqHttpCreatePending();

    Sanctum::actingAs($this->originManager);
    $this->postJson("/api/v1/transfer-requests/{$request->uuid}/reject", ['reason' => 'No procede'], $this->headers)
        ->assertOk();

    $this->postJson("/api/v1/transfer-requests/{$request->uuid}/approve", [], $this->headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_TRANSITION');
});
