<?php

declare(strict_types=1);

namespace App\Domain\Cash\Services;

use App\Domain\Authorization\Roles;
use App\Domain\Cash\Exceptions\CashSessionAlreadyOpenException;
use App\Domain\Cash\Exceptions\CashSessionNotOpenException;
use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operaciones atómicas de caja.
 *
 * Reglas:
 *   - Solo UNA sesión abierta por register a la vez (constraint BD).
 *   - openSession verifica antes de insertar para devolver excepción
 *     más útil que el QueryException del unique parcial.
 *   - closeSession: calcula expected_amount sumando opening + delta_signed
 *     de todos los movimientos. counted_amount lo provee el cajero.
 *   - addMovement: valida que la sesión esté open. Calcula delta_signed
 *     según el tipo + signo manual (para adjustment).
 */
final class CashService
{
    /**
     * RN-115 / RN-191: una diferencia de caja se considera significativa
     * cuando |difference| supera este porcentaje del monto esperado. El
     * maestro lo define configurable por tenant; hasta que exista settings
     * de tenant se usa este default (punto de configuracion unico).
     */
    public const SIGNIFICANT_DIFFERENCE_PCT = 2.0;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Abre una sesión nueva.
     */
    public function openSession(
        CashRegister $register,
        User $user,
        float $openingAmount = 0,
        ?string $notes = null,
    ): CashSession {
        return DB::transaction(function () use ($register, $user, $openingAmount, $notes) {
            // Verificación lógica (también hay constraint BD por si race)
            if ($register->hasOpenSession()) {
                throw CashSessionAlreadyOpenException::forRegister($register->id);
            }

            return CashSession::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => TenantContext::id(),
                'cash_register_id' => $register->id,
                'branch_id' => $register->branch_id,
                'opened_by' => $user->id,
                'status' => CashSession::STATUS_OPEN,
                'opening_amount' => $openingAmount,
                'opening_notes' => $notes,
                'opened_at' => now(),
            ]);
        });
    }

    /**
     * Cierra la sesión calculando expected vs counted.
     */
    public function closeSession(
        CashSession $session,
        User $user,
        float $countedAmount,
        ?string $notes = null,
    ): CashSession {
        $closed = DB::transaction(function () use ($session, $user, $countedAmount, $notes) {
            // Lock pesimista para evitar doble cierre
            /** @var CashSession $locked */
            $locked = CashSession::query()
                ->where('id', $session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw CashSessionNotOpenException::forSession($locked->id, $locked->status);
            }

            $expected = (float) $locked->opening_amount
                + $this->cashAffectingDelta($locked->id);
            $difference = round($countedAmount - $expected, 2);

            $locked->update([
                'status' => CashSession::STATUS_CLOSED,
                'closed_by' => $user->id,
                'expected_amount' => round($expected, 2),
                'counted_amount' => $countedAmount,
                'difference' => $difference,
                'closing_notes' => $notes,
                'closed_at' => now(),
            ]);

            return $locked;
        });

        // RN-115 / RN-191: si la diferencia es significativa, notificar al
        // GERENTE de la sucursal. Efecto secundario fuera de la transaccion:
        // si la notificacion fallara no debe revertir el cierre.
        if ($this->isDifferenceSignificant($closed)) {
            $this->notifyCashDifference($closed);
        }

        return $closed;
    }

    /**
     * RN-115: |difference| > X% del esperado. Si el esperado es 0 (fondo 0,
     * permitido por RN-112), cualquier diferencia distinta de cero es
     * significativa.
     */
    private function isDifferenceSignificant(CashSession $session): bool
    {
        $difference = abs((float) $session->difference);

        if ($difference === 0.0) {
            return false;
        }

        $expected = abs((float) $session->expected_amount);

        if ($expected === 0.0) {
            return true;
        }

        return ($difference / $expected) * 100 > self::SIGNIFICANT_DIFFERENCE_PCT;
    }

    /**
     * RN-191: notifica la diferencia de caja al GERENTE de la sucursal de la
     * sesion. Reutiliza el resolver por sucursal del NotificationService.
     */
    private function notifyCashDifference(CashSession $session): void
    {
        $branch = $session->branch;

        if ($branch === null) {
            return;
        }

        $recipients = $this->notifications->usersWithRolesForBranch([Roles::GERENTE], $branch);

        foreach ($recipients as $recipient) {
            $this->notifications->notify(
                recipient: $recipient,
                type: 'cash.difference',
                data: [
                    'cash_session_id' => $session->id,
                    'cash_session_uuid' => $session->uuid,
                    'branch_id' => $branch->id,
                    'expected_amount' => (float) $session->expected_amount,
                    'counted_amount' => (float) $session->counted_amount,
                    'difference' => (float) $session->difference,
                ],
                severity: Notification::SEVERITY_WARNING,
            );
        }
    }

    /**
     * Anula una sesión (supervisor en casos de error).
     */
    public function voidSession(CashSession $session, User $user, string $reason): CashSession
    {
        return DB::transaction(function () use ($session, $user, $reason) {
            $session->update([
                'status' => CashSession::STATUS_VOIDED,
                'closed_by' => $user->id,
                'closing_notes' => 'VOIDED: '.$reason,
                'closed_at' => now(),
            ]);

            return $session;
        });
    }

    /**
     * Registra un movimiento manual (cash_in, cash_out, adjustment).
     *
     * Para sale_cash, refund_cash, etc., los registra el módulo de ventas.
     */
    public function addMovement(
        CashSession $session,
        User $user,
        string $type,
        float $amount,
        ?string $reason = null,
        ?string $reference = null,
        ?Model $source = null,
        ?int $signOverride = null,
    ): CashMovement {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        return DB::transaction(function () use (
            $session, $user, $type, $amount, $reason, $reference, $source, $signOverride
        ) {
            /** @var CashSession $locked */
            $locked = CashSession::query()
                ->where('id', $session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw CashSessionNotOpenException::forSession($locked->id, $locked->status);
            }

            $sign = $signOverride ?? CashMovement::signFor($type);
            // Para adjustment, el caller debe pasar signOverride
            if ($type === CashMovement::TYPE_ADJUSTMENT && $signOverride === null) {
                throw new \InvalidArgumentException(
                    'Adjustment requires signOverride (+1 or -1)'
                );
            }
            if (! in_array($sign, [-1, 0, 1], true)) {
                throw new \InvalidArgumentException('Invalid sign');
            }

            $delta = $sign * $amount;

            return CashMovement::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => TenantContext::id(),
                'cash_session_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'delta_signed' => $delta,
                'source_type' => $source !== null ? $source::class : null,
                'source_id' => $source?->getKey(),
                'reason' => $reason,
                'reference' => $reference,
                'user_id' => $user->id,
                'movement_at' => now(),
            ]);
        });
    }

    /**
     * Calcula el delta total de movimientos que afectan al efectivo físico
     * de una sesión (sum de delta_signed de tipos cash-affecting).
     *
     * Publico: tambien lo usa CashSessionReportService para el corte X/Z.
     */
    public function cashAffectingDelta(int $sessionId): float
    {
        return (float) CashMovement::query()
            ->where('cash_session_id', $sessionId)
            ->whereIn('type', CashMovement::CASH_AFFECTING_TYPES)
            ->sum('delta_signed');
    }
}
