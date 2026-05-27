<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Prefijo /api/v1 implícito (Laravel 11). Default throttle:api.
|
| Tres categorías de rutas según middleware:
|   1. Públicas (no tenant, no auth):  health
|   2. Tenant-aware no autenticadas:   auth/login
|   3. Tenant-aware autenticadas:      auth/me, auth/logout, etc.
|
*/

Route::prefix('v1')->group(function (): void {

    // ---- 1. Públicas ----
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'pos-api',
        'timestamp' => now()->toIso8601String(),
        'version' => config('app.version', '0.1.0'),
    ]));

    // ---- 2. Tenant-aware (cualquier ruta dentro requiere X-Tenant válido) ----
    Route::middleware('tenant')->group(function (): void {

        // Tenant info público (cliente quiere saber detalles del tenant antes de loguear)
        Route::get('/tenant', function (Request $request) {
            $tenant = $request->attributes->get('tenant');

            return response()->json([
                'data' => [
                    'uuid' => $tenant->uuid,
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                    'plan' => $tenant->plan,
                    'status' => $tenant->status,
                    'country_code' => $tenant->country_code,
                    'timezone' => $tenant->timezone,
                    'locale' => $tenant->locale,
                ],
            ]);
        });

        // Auth: login es público dentro del tenant
        Route::post('/auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1')   // 5 intentos/min por IP
            ->name('auth.login');

        // ---- 3. Tenant-aware + autenticadas con Sanctum ----
        Route::middleware('auth:sanctum')->group(function (): void {

            Route::prefix('auth')->group(function (): void {
                Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
                Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
                Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout_all');
                Route::post('/pin-verify', [AuthController::class, 'pinVerify'])
                    ->middleware('throttle:10,1')
                    ->name('auth.pin_verify');
            });

            // ----- Admin: gestión de usuarios y roles -----
            Route::prefix('admin')->group(function (): void {
                Route::get('/users', [\App\Http\Controllers\Api\V1\Admin\UsersController::class, 'index'])
                    ->name('admin.users.index');
                Route::get('/users/{uuid}', [\App\Http\Controllers\Api\V1\Admin\UsersController::class, 'show'])
                    ->name('admin.users.show');
                Route::post('/users/{uuid}/roles', [\App\Http\Controllers\Api\V1\Admin\UsersController::class, 'syncRoles'])
                    ->name('admin.users.sync_roles');
            });

            // ----- Catálogo: products -----
            Route::apiResource('products', \App\Http\Controllers\Api\V1\Catalog\ProductsController::class)
                ->parameters(['products' => 'product'])
                ->scoped(['product' => 'uuid']);

            // ----- Catálogo auxiliar -----
            Route::apiResource('categories', \App\Http\Controllers\Api\V1\Catalog\CategoriesController::class)
                ->parameters(['categories' => 'category'])
                ->scoped(['category' => 'uuid']);

            Route::apiResource('brands', \App\Http\Controllers\Api\V1\Catalog\BrandsController::class)
                ->parameters(['brands' => 'brand'])
                ->scoped(['brand' => 'uuid']);

            Route::apiResource('units', \App\Http\Controllers\Api\V1\Catalog\UnitsController::class)
                ->parameters(['units' => 'unit'])
                ->scoped(['unit' => 'uuid']);

            Route::apiResource('taxes', \App\Http\Controllers\Api\V1\Catalog\TaxesController::class)
                ->parameters(['taxes' => 'tax'])
                ->scoped(['tax' => 'uuid']);

            // ----- Inventario -----
            Route::get('warehouses', [\App\Http\Controllers\Api\V1\Inventory\WarehousesController::class, 'index']);
            Route::get('warehouses/{warehouse:uuid}', [\App\Http\Controllers\Api\V1\Inventory\WarehousesController::class, 'show']);
            Route::post('warehouses', [\App\Http\Controllers\Api\V1\Inventory\WarehousesController::class, 'store']);

            Route::prefix('inventory')->group(function (): void {
                Route::get('stocks', [\App\Http\Controllers\Api\V1\Inventory\InventoryController::class, 'stocks']);
                Route::get('movements', [\App\Http\Controllers\Api\V1\Inventory\InventoryController::class, 'movements']);
                Route::post('adjust', [\App\Http\Controllers\Api\V1\Inventory\InventoryController::class, 'adjust']);
                Route::post('transfer', [\App\Http\Controllers\Api\V1\Inventory\InventoryController::class, 'transfer']);
            });

            // ----- Caja -----
            Route::prefix('cash')->group(function (): void {
                // Cash registers (puntos de cobro físicos)
                Route::get('registers', [\App\Http\Controllers\Api\V1\Cash\CashRegistersController::class, 'index']);
                Route::get('registers/{register:uuid}', [\App\Http\Controllers\Api\V1\Cash\CashRegistersController::class, 'show']);
                Route::post('registers', [\App\Http\Controllers\Api\V1\Cash\CashRegistersController::class, 'store']);

                // Cash sessions
                Route::get('sessions', [\App\Http\Controllers\Api\V1\Cash\CashSessionsController::class, 'index']);
                Route::get('sessions/{session:uuid}', [\App\Http\Controllers\Api\V1\Cash\CashSessionsController::class, 'show']);
                Route::post('sessions/open', [\App\Http\Controllers\Api\V1\Cash\CashSessionsController::class, 'open']);
                Route::post('sessions/{session:uuid}/close', [\App\Http\Controllers\Api\V1\Cash\CashSessionsController::class, 'close']);

                // Movements within a session
                Route::get('sessions/{session:uuid}/movements', [\App\Http\Controllers\Api\V1\Cash\CashMovementsController::class, 'index']);
                Route::post('sessions/{session:uuid}/movements', [\App\Http\Controllers\Api\V1\Cash\CashMovementsController::class, 'store']);
            });

            // ----- Clientes -----
            Route::apiResource('customers', \App\Http\Controllers\Api\V1\Customer\CustomersController::class)
                ->parameters(['customers' => 'customer'])
                ->scoped(['customer' => 'uuid']);

            // ----- Ventas -----
            Route::prefix('sales')->group(function (): void {
                Route::get('/', [\App\Http\Controllers\Api\V1\Sales\SalesController::class, 'index'])
                    ->name('sales.index');
                Route::get('/{uuid}', [\App\Http\Controllers\Api\V1\Sales\SalesController::class, 'show'])
                    ->name('sales.show');
                Route::post('/', [\App\Http\Controllers\Api\V1\Sales\SalesController::class, 'store'])
                    ->name('sales.store');
                Route::post('/{uuid}/cancel', [\App\Http\Controllers\Api\V1\Sales\SalesController::class, 'cancel'])
                    ->name('sales.cancel');
            });
        });
    });

});
