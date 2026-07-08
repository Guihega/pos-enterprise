<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Authorization\Roles;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Exceptions\InvalidTransferRequestTransitionException;
use App\Domain\Inventory\Models\Transfer;
use App\Domain\Inventory\Models\TransferRequest;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solicitudes de transferencia inter-sucursal (doc maestro CU-GER-003).
 *
 * Flujo: gerente destino ve stock cruzado (RN-233) -> crea solicitud ->
 * se notifica a gerentes de la sucursal ORIGEN -> origen aprueba o rechaza ->
 * al aprobar se crea el Transfer (draft) via TransferService y la FSM 14.5
 * manda desde ahi; la cancelacion es del solicitante mientras este pending.
 */
final class TransferRequestService
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Crea la solicitud en pending y notifica a los gerentes de origen.
     *
     * @param  list<array{product: Product, quantity: float, notes?: string|null}>  $lines
     */
    public function create(
        Branch $fromBranch,
        Branch $toBranch,
        array $lines,
        User $requester,
        ?string $notes = null,
    ): TransferRequest {
        if ($fromBranch->id === $toBranch->id) {
            throw new \InvalidArgumentException('Origen y destino deben ser sucursales distintas.');
        }

        if ($lines === []) {
            throw new \InvalidArgumentException('La solicitud requiere al menos una linea.');
        }

        $request = DB::transaction(function () use ($fromBranch, $toBranch, $lines, $requester, $notes): TransferRequest {
            $request = TransferRequest::create([
                'uuid' => (string) Str::uuid(),
                'folio' => $this->nextFolio(),
                'from_branch_id' => $fromBranch->id,
                'to_branch_id' => $toBranch->id,
                'status' => TransferRequest::STATUS_PENDING,
                'requested_by_user_id' => $requester->id,
                'notes' => $notes,
            ]);

            foreach ($lines as $line) {
                $request->items()->create([
                    'company_id' => TenantContext::id(),
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $request->fresh(['items']);
        });

        $this->notifyOriginManagers($request, $fromBranch);

        return $request;
    }

    /**
     * pending -> approved: crea el Transfer (draft) con las lineas solicitadas.
     */
    public function approve(TransferRequest $request, User $approver): TransferRequest
    {
        $this->assertTransition($request, TransferRequest::STATUS_APPROVED);

        $request = DB::transaction(function () use ($request, $approver): TransferRequest {
            $request->loadMissing(['items.product', 'fromBranch', 'toBranch']);

            $lines = $request->items->map(fn ($item): array => [
                'product' => $item->product,
                'quantity' => (float) $item->quantity,
                'notes' => $item->notes,
            ])->all();

            $transfer = $this->transfers->create(
                $request->fromBranch,
                $request->toBranch,
                $lines,
                notes: "Generada desde solicitud {$request->folio}",
            );

            $request->update([
                'status' => TransferRequest::STATUS_APPROVED,
                'resolved_by_user_id' => $approver->id,
                'resolved_at' => now(),
                'transfer_id' => $transfer->id,
            ]);

            return $request->fresh(['items', 'transfer']);
        });

        $this->notifyRequester($request, 'transfer_request.approved', Notification::SEVERITY_INFO);

        return $request;
    }

    /**
     * pending -> rejected: requiere motivo.
     */
    public function reject(TransferRequest $request, User $resolver, string $reason): TransferRequest
    {
        $this->assertTransition($request, TransferRequest::STATUS_REJECTED);

        $request->update([
            'status' => TransferRequest::STATUS_REJECTED,
            'resolved_by_user_id' => $resolver->id,
            'resolved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $request = $request->fresh(['items']);

        $this->notifyRequester($request, 'transfer_request.rejected', Notification::SEVERITY_WARNING);

        return $request;
    }

    /**
     * pending -> cancelled: la retira el propio solicitante.
     */
    public function cancel(TransferRequest $request, User $user): TransferRequest
    {
        $this->assertTransition($request, TransferRequest::STATUS_CANCELLED);

        $request->update([
            'status' => TransferRequest::STATUS_CANCELLED,
            'resolved_by_user_id' => $user->id,
            'resolved_at' => now(),
        ]);

        return $request->fresh(['items']);
    }

    private function assertTransition(TransferRequest $request, string $target): void
    {
        if (! $request->canTransitionTo($target)) {
            throw InvalidTransferRequestTransitionException::between($request->status, $target);
        }
    }

    private function notifyOriginManagers(TransferRequest $request, Branch $fromBranch): void
    {
        $recipients = $this->notifications->usersWithRolesForBranch([Roles::GERENTE], $fromBranch);

        foreach ($recipients as $recipient) {
            $this->notifications->notify(
                $recipient,
                'transfer_request.created',
                $this->payload($request),
                Notification::SEVERITY_INFO,
            );
        }
    }

    private function notifyRequester(TransferRequest $request, string $type, string $severity): void
    {
        $requester = $request->requester;

        if ($requester === null) {
            return;
        }

        $this->notifications->notify($requester, $type, $this->payload($request), $severity);
    }

    /** @return array<string, mixed> */
    private function payload(TransferRequest $request): array
    {
        return [
            'transfer_request_id' => $request->id,
            'transfer_request_uuid' => $request->uuid,
            'folio' => $request->folio,
            'from_branch_id' => $request->from_branch_id,
            'to_branch_id' => $request->to_branch_id,
            'status' => $request->status,
            'rejection_reason' => $request->rejection_reason,
            'transfer_id' => $request->transfer_id,
        ];
    }

    /**
     * Folio simple por tenant: TRQ-{YYYYMMDD}-{correlativo del dia}.
     * Paralelo a TransferService::nextFolio.
     */
    private function nextFolio(): string
    {
        $prefix = 'TRQ-'.now()->format('Ymd').'-';
        $count = TransferRequest::query()
            ->where('folio', 'like', $prefix.'%')
            ->count();

        return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
