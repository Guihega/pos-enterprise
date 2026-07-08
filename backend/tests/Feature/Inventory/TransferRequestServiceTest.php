<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Exceptions\InvalidTransferRequestTransitionException;
use App\Domain\Inventory\Models\Transfer;
use App\Domain\Inventory\Models\TransferRequest;
use App\Domain\Inventory\Services\TransferRequestService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
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
        'sku' => 'SKU-TRQ-1',
        'price' => 100,
        'track_inventory' => true,
    ]);

    // Gerente de la sucursal ORIGEN (vinculado por pivot: usersWithRolesForBranch).
    $this->originManager = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->originManager->assignRole(Roles::GERENTE);
    $this->originManager->syncBranches([$this->fromBranch]);

    // Solicitante: gerente de la sucursal DESTINO.
    $this->requester = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->requester->assignRole(Roles::GERENTE);
    $this->requester->syncBranches([$this->toBranch]);

    $this->service = app(TransferRequestService::class);
});

function trqMakePending(): TransferRequest
{
    $test = test();

    return $test->service->create(
        $test->fromBranch,
        $test->toBranch,
        [['product' => $test->product, 'quantity' => 5.0]],
        $test->requester,
    );
}

function trqNotificationCount(Company $tenant, string $type): int
{
    TenantContext::set($tenant);

    return Notification::query()->where('type', $type)->count();
}

it('crea la solicitud pending con folio e items y notifica al gerente de origen', function (): void {
    $request = trqMakePending();

    expect($request->status)->toBe(TransferRequest::STATUS_PENDING)
        ->and($request->folio)->toStartWith('TRQ-')
        ->and($request->items)->toHaveCount(1)
        ->and((float) $request->items->first()->quantity)->toBe(5.0)
        ->and($request->requested_by_user_id)->toBe($this->requester->id);

    expect(trqNotificationCount($this->tenant, 'transfer_request.created'))->toBe(1);

    $notification = Notification::query()->where('type', 'transfer_request.created')->firstOrFail();
    expect($notification->notifiable_id)->toBe($this->originManager->id)
        ->and($notification->data['transfer_request_uuid'])->toBe($request->uuid);
});

it('rechaza crear solicitud con origen igual a destino', function (): void {
    $this->service->create(
        $this->fromBranch,
        $this->fromBranch,
        [['product' => $this->product, 'quantity' => 1.0]],
        $this->requester,
    );
})->throws(InvalidArgumentException::class);

it('rechaza crear solicitud sin lineas', function (): void {
    $this->service->create($this->fromBranch, $this->toBranch, [], $this->requester);
})->throws(InvalidArgumentException::class);

it('al aprobar crea el Transfer draft con las lineas y liga la solicitud', function (): void {
    $request = trqMakePending();

    $request = $this->service->approve($request, $this->originManager);

    TenantContext::set($this->tenant);

    expect($request->status)->toBe(TransferRequest::STATUS_APPROVED)
        ->and($request->resolved_by_user_id)->toBe($this->originManager->id)
        ->and($request->resolved_at)->not->toBeNull()
        ->and($request->transfer_id)->not->toBeNull();

    $transfer = Transfer::query()->findOrFail($request->transfer_id);
    expect($transfer->status)->toBe(Transfer::STATUS_DRAFT)
        ->and($transfer->from_branch_id)->toBe($this->fromBranch->id)
        ->and($transfer->to_branch_id)->toBe($this->toBranch->id)
        ->and($transfer->items)->toHaveCount(1)
        ->and((float) $transfer->items->first()->quantity_sent)->toBe(5.0);

    expect(trqNotificationCount($this->tenant, 'transfer_request.approved'))->toBe(1);

    $notification = Notification::query()->where('type', 'transfer_request.approved')->firstOrFail();
    expect($notification->notifiable_id)->toBe($this->requester->id);
});

it('al rechazar guarda el motivo y notifica al solicitante', function (): void {
    $request = trqMakePending();

    $request = $this->service->reject($request, $this->originManager, 'Sin stock disponible');

    TenantContext::set($this->tenant);

    expect($request->status)->toBe(TransferRequest::STATUS_REJECTED)
        ->and($request->rejection_reason)->toBe('Sin stock disponible')
        ->and($request->transfer_id)->toBeNull();

    expect(trqNotificationCount($this->tenant, 'transfer_request.rejected'))->toBe(1);

    $notification = Notification::query()->where('type', 'transfer_request.rejected')->firstOrFail();
    expect($notification->notifiable_id)->toBe($this->requester->id)
        ->and($notification->severity)->toBe(Notification::SEVERITY_WARNING);
});

it('el solicitante puede cancelar mientras este pending', function (): void {
    $request = trqMakePending();

    $request = $this->service->cancel($request, $this->requester);

    expect($request->status)->toBe(TransferRequest::STATUS_CANCELLED)
        ->and($request->resolved_by_user_id)->toBe($this->requester->id);
});

it('no permite aprobar una solicitud ya rechazada', function (): void {
    $request = trqMakePending();
    $request = $this->service->reject($request, $this->originManager, 'No procede');

    $this->service->approve($request, $this->originManager);
})->throws(InvalidTransferRequestTransitionException::class);

it('no permite cancelar una solicitud ya aprobada', function (): void {
    $request = trqMakePending();
    $request = $this->service->approve($request, $this->originManager);

    $this->service->cancel($request, $this->requester);
})->throws(InvalidTransferRequestTransitionException::class);
