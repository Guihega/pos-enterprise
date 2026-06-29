<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Exceptions\AccountInactiveException;
use App\Domain\Identity\Exceptions\AccountLockedException;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PinVerifyRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
    ) {}

    /**
     * POST /api/v1/auth/login
     *
     * Body: { email, password, device_id?, token_name? }
     * Header: X-Tenant: {slug|uuid}
     *
     * Respuestas:
     *   200 OK            → { data: { user, token, tenant } }
     *   401 Unauthorized  → INVALID_CREDENTIALS
     *   402 Payment Req.  → TENANT_SUSPENDED  (lo emite el middleware)
     *   403 Forbidden     → ACCOUNT_INACTIVE
     *   423 Locked        → ACCOUNT_LOCKED   (con seconds_remaining)
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->auth->login(
                email: $request->loginEmail(),
                password: $request->loginPassword(),
                context: $request->loginContext(),
            );
        } catch (InvalidCredentialsException $e) {
            return $this->errorResponse(
                code: 'INVALID_CREDENTIALS',
                message: 'Email o contraseña incorrectos.',
                status: 401,
            );
        } catch (AccountInactiveException $e) {
            return $this->errorResponse(
                code: 'ACCOUNT_INACTIVE',
                message: $e->getMessage(),
                status: 403,
            );
        } catch (AccountLockedException $e) {
            return $this->errorResponse(
                code: 'ACCOUNT_LOCKED',
                message: $e->getMessage(),
                status: 423,
                details: [
                    'locked_until' => $e->lockedUntil->toIso8601String(),
                    'seconds_remaining' => $e->secondsRemaining(),
                ],
            );
        }

        $result->user->load('defaultBranch', 'branches', 'roles');

        return response()->json([
            'data' => [
                'user' => new UserResource($result->user),
                'token' => $result->plainTextToken(),
                'token_type' => 'Bearer',
            ],
        ], 200);
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revoca el token actual (no las otras sesiones).
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->auth->logout($user);

        return response()->json(['data' => ['message' => 'Sesión cerrada.']]);
    }

    /**
     * POST /api/v1/auth/logout-all
     *
     * Revoca TODOS los tokens del usuario (todos los dispositivos).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->auth->logoutAll($user);

        return response()->json([
            'data' => [
                'message' => 'Todas las sesiones cerradas.',
                'tokens_revoked' => $count,
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/me
     *
     * Devuelve el usuario autenticado con sus relaciones básicas.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('defaultBranch.defaultWarehouse', 'branches', 'roles');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    /**
     * POST /api/v1/auth/pin-verify
     *
     * Body: { pin }
     * Verifica el PIN del usuario actual. Útil para autorizaciones in-flight
     * (ej: confirmar cancelación de venta) sin re-autenticar todo.
     */
    public function pinVerify(PinVerifyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ok = $this->auth->verifySupervisorPin($user, (string) $request->validated('pin'));

        if (! $ok) {
            return $this->errorResponse(
                code: 'PIN_INVALID',
                message: 'PIN inválido o cuenta bloqueada.',
                status: 401,
            );
        }

        return response()->json(['data' => ['valid' => true]]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function errorResponse(
        string $code,
        string $message,
        int $status,
        array $details = [],
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => request()->header('X-Request-Id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }
}
