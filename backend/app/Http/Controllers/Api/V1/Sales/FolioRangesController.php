<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Cash\Models\CashRegister;
use App\Domain\Sales\Services\FolioRangeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sale\ReserveFolioRangeRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/folio-ranges/reserve
 *
 * Reserva (o devuelve el activo) un rango de folios para un dispositivo PWA.
 * ADR-0009.
 */
final class FolioRangesController extends Controller
{
    public function __construct(
        private readonly FolioRangeService $service,
    ) {}

    public function reserve(ReserveFolioRangeRequest $request): JsonResponse
    {
        $register = CashRegister::where('uuid', $request->validated('cash_register_uuid'))->firstOrFail();

        $result = $this->service->reserve(
            register: $register,
            series:   $request->validated('series', 'A'),
            deviceId: $request->validated('device_id'),
            size:     (int) $request->validated('size', 50),
        );

        return response()->json($result, 201);
    }
}
