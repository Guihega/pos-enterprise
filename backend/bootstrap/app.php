<?php

declare(strict_types=1);

use App\Domain\Identity\Exceptions\AccountInactiveException;
use App\Domain\Identity\Exceptions\AccountLockedException;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Sales\Exceptions\InsufficientCreditException;
use App\Domain\Sales\Exceptions\PaymentMismatchException;
use App\Domain\Sales\Exceptions\SaleNotCancellableException;
use App\Domain\Tenancy\Middleware\EnsureTenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health/live',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => EnsureTenantContext::class,
        ]);

        // El route-model-binding (SubstituteBindings) consulta modelos
        // tenant-scoped. El TenantScope aplica WHERE FALSE si no hay contexto,
        // devolviendo 404 en bindings {x:uuid}. Por eso EnsureTenantContext
        // DEBE correr antes de SubstituteBindings. Declaramos la prioridad
        // completa con nuestro middleware insertado en la posicion correcta.
        $middleware->priority([
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            EnsureTenantContext::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        |----------------------------------------------------------------------
        | Handler global de errores de la API.
        |----------------------------------------------------------------------
        |
        | Traduce las excepciones de dominio a la envoltura estandar:
        |
        |   { "error": { "code", "message", "details", "request_id", "timestamp" } }
        |
        | El mapa excepcion -> (status, code) se resuelve EN LINEA con un match
        | dentro del closure. No se usa una funcion global a proposito: este
        | archivo puede cargarse mas de una vez (p. ej. bajo Pest/PHPUnit) y una
        | funcion global se redeclararia, provocando un fatal error.
        |
        */
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            // Solo interceptamos peticiones de API (JSON).
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // [status, code, details] o null si no se reconoce la excepcion.
            $mapped = match (true) {
                // ----- Validacion (422) -----
                // NOTA: ValidationException NO se mapea aqui a proposito. Laravel
                // ya responde con su formato nativo { message, errors: {...} } que
                // todo el proyecto verifica con assertJsonValidationErrors(). Mapearla
                // romperia esos tests en todos los modulos.

                // ----- Autenticacion -----
                // NOTA: AuthorizationException (403) NO se mapea aqui a proposito.
                // El proyecto responde 403 con el formato estandar de Laravel
                // (los tests usan assertStatus(403) sin error.code). Mapearlo
                // romperia ese contrato en los demas modulos.
                $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', []],

                // ----- No encontrado (404) -----
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', []],

                // ----- Identity -----
                $e instanceof InvalidCredentialsException => [401, 'INVALID_CREDENTIALS', []],
                $e instanceof AccountInactiveException => [403, 'ACCOUNT_INACTIVE', []],
                $e instanceof AccountLockedException => [
                    423, 'ACCOUNT_LOCKED', ['seconds_remaining' => $e->secondsRemaining()],
                ],

                // ----- Inventory -----
                $e instanceof InsufficientStockException => [409, 'INSUFFICIENT_STOCK', []],

                // ----- Sales -----
                $e instanceof PaymentMismatchException => [422, 'PAYMENT_MISMATCH', []],
                $e instanceof InsufficientCreditException => [402, 'INSUFFICIENT_CREDIT', []],
                $e instanceof SaleNotCancellableException => [409, 'SALE_NOT_CANCELLABLE', []],

                // ----- Cash (por nombre de clase, sin acoplar import) -----
                is_a($e, 'App\\Domain\\Cash\\Exceptions\\CashSessionNotOpenException')
                    => [409, 'SESSION_NOT_OPEN', []],
                is_a($e, 'App\\Domain\\Cash\\Exceptions\\CashSessionAlreadyOpenException')
                    => [409, 'SESSION_ALREADY_OPEN', []],

                // ----- Argumentos invalidos de dominio -----
                $e instanceof InvalidArgumentException => [422, 'INVALID_ARGUMENT', []],

                default => null,
            };

            if ($mapped === null) {
                return null; // Deja que Laravel maneje el resto (500, etc.)
            }

            [$status, $code, $details] = $mapped;

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $e->getMessage() ?: $code,
                    'details' => $details,
                    'request_id' => $request->header('X-Request-Id')
                        ?? $request->headers->get('X-Request-ID'),
                    'timestamp' => now()->toIso8601String(),
                ],
            ], $status);
        });
    })
    ->create();
