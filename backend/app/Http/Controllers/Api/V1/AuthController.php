<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Exceptions\AccountInactiveException;
use App\Domain\Identity\Exceptions\AccountLockedException;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use App\Domain\Identity\Exceptions\InvalidResetTokenException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Identity\Services\PasswordResetService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PinVerifyRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PasswordResetService $passwords,
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
    /**
     * POST /api/v1/auth/change-password
     *
     * Body: { current_password, new_password }
     * Cambio voluntario del propio usuario. Exige la password actual.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->passwords->change(
                $user,
                (string) $request->validated('current_password'),
                (string) $request->validated('new_password'),
            );
        } catch (InvalidCredentialsException) {
            return $this->errorResponse(
                code: 'INVALID_CREDENTIALS',
                message: 'La contrasena actual no coincide.',
                status: 401,
            );
        }

        return response()->json(['data' => ['changed' => true]]);
    }

    /**
     * POST /api/v1/auth/reset-password
     *
     * Body: { email, token, password }
     * Publico dentro del tenant (flujo 57.6 paso 4). Canjea el token de un
     * solo uso emitido por un admin y revoca todas las sesiones.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->passwords->redeem(
                (string) $request->validated('email'),
                (string) $request->validated('token'),
                (string) $request->validated('password'),
            );
        } catch (InvalidResetTokenException $e) {
            return $this->errorResponse(
                code: 'INVALID_RESET_TOKEN',
                message: $e->getMessage(),
                status: 422,
            );
        }

        return response()->json(['data' => ['reset' => true]]);
    }

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
