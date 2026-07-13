<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\UsersController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Cash\CashMovementsController;
use App\Http\Controllers\Api\V1\Cash\CashRegistersController;
use App\Http\Controllers\Api\V1\Cash\CashSessionsController;
use App\Http\Controllers\Api\V1\Catalog\BrandsController;
use App\Http\Controllers\Api\V1\Catalog\CategoriesController;
use App\Http\Controllers\Api\V1\Catalog\ProductsController;
use App\Http\Controllers\Api\V1\Catalog\TaxesController;
use App\Http\Controllers\Api\V1\Catalog\UnitsController;
use App\Http\Controllers\Api\V1\Customer\CustomersController;
use App\Http\Controllers\Api\V1\Inventory\BatchController;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Controllers\Api\V1\Inventory\TransferController;
use App\Http\Controllers\Api\V1\Inventory\TransferRequestController;
use App\Http\Controllers\Api\V1\Inventory\WarehousesController;
use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Http\Controllers\Api\V1\Reports\ReportsController;
use App\Http\Controllers\Api\V1\Sales\FolioRangesController;
use App\Http\Controllers\Api\V1\Sales\SalesController;
use App\Http\Controllers\Api\V1\Sync\SyncBatchController;
use App\Http\Controllers\Api\V1\Sync\SyncChangesController;
use App\Http\Controllers\Api\V1\Sync\SyncHeartbeatController;
use App\Http\Controllers\Api\V1\Tenancy\BranchesController;
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
                Route::get('/users', [UsersController::class, 'index'])
                    ->name('admin.users.index');
                Route::get('/users/{uuid}', [UsersController::class, 'show'])
                    ->name('admin.users.show');
                Route::post('/users/{uuid}/roles', [UsersController::class, 'syncRoles'])
                    ->name('admin.users.sync_roles');
            });

            // ----- Catálogo: products -----
            Route::apiResource('products', ProductsController::class)
                ->parameters(['products' => 'product'])
                ->scoped(['product' => 'uuid']);

            // ----- Catálogo auxiliar -----
            Route::apiResource('categories', CategoriesController::class)
                ->parameters(['categories' => 'category'])
                ->scoped(['category' => 'uuid']);

            Route::apiResource('brands', BrandsController::class)
                ->parameters(['brands' => 'brand'])
                ->scoped(['brand' => 'uuid']);

            Route::apiResource('units', UnitsController::class)
                ->parameters(['units' => 'unit'])
                ->scoped(['unit' => 'uuid']);

            Route::apiResource('taxes', TaxesController::class)
                ->parameters(['taxes' => 'tax'])
                ->scoped(['tax' => 'uuid']);

            // ----- Inventario -----
            Route::get('warehouses', [WarehousesController::class, 'index']);
            Route::get('warehouses/{warehouse:uuid}', [WarehousesController::class, 'show']);
            Route::post('warehouses', [WarehousesController::class, 'store']);

            // ----- Sucursales (multi-sucursal) -----
            Route::get('branches', [BranchesController::class, 'index']);
            Route::get('branches/{branch:uuid}', [BranchesController::class, 'show']);
            Route::post('branches', [BranchesController::class, 'store']);
            Route::patch('branches/{branch:uuid}', [BranchesController::class, 'update']);
            Route::post('branches/{branch:uuid}/deactivate', [BranchesController::class, 'deactivate']);

            Route::prefix('inventory')->group(function (): void {
                Route::get('stocks', [InventoryController::class, 'stocks']);
                Route::get('movements', [InventoryController::class, 'movements']);
                Route::post('adjust', [InventoryController::class, 'adjust']);
                Route::post('transfer', [InventoryController::class, 'transfer']);

                // ----- Lotes (doc maestro 29.6) -----
                Route::get('batches', [BatchController::class, 'index']);
                Route::get('batches/{batch:uuid}', [BatchController::class, 'show']);
                Route::post('batches/{batch:uuid}/quarantine', [BatchController::class, 'quarantine']);
                Route::post('batches/{batch:uuid}/release', [BatchController::class, 'release']);
                Route::get('expirations', [BatchController::class, 'expirations']);
            });

            // ----- Transferencias inter-sucursal -----
            Route::prefix('transfers')->group(function (): void {
                Route::get('/', [TransferController::class, 'index']);
                Route::get('{transfer:uuid}', [TransferController::class, 'show']);
                Route::post('/', [TransferController::class, 'store']);
                Route::post('{transfer:uuid}/send', [TransferController::class, 'send']);
                Route::post('{transfer:uuid}/receive', [TransferController::class, 'receive']);
                Route::post('{transfer:uuid}/cancel', [TransferController::class, 'cancel']);
            });

            // ----- Solicitudes de transferencia (CU-GER-003) -----
            Route::prefix('transfer-requests')->group(function (): void {
                Route::get('/', [TransferRequestController::class, 'index']);
                Route::get('{transferRequest:uuid}', [TransferRequestController::class, 'show']);
                Route::post('/', [TransferRequestController::class, 'store']);
                Route::post('{transferRequest:uuid}/approve', [TransferRequestController::class, 'approve']);
                Route::post('{transferRequest:uuid}/reject', [TransferRequestController::class, 'reject']);
                Route::post('{transferRequest:uuid}/cancel', [TransferRequestController::class, 'cancel']);
            });

            Route::prefix('notifications')->group(function (): void {
                Route::get('/', [NotificationController::class, 'index']);
                Route::post('{notification:uuid}/read', [NotificationController::class, 'read']);
            });

            // ----- Caja -----
            Route::prefix('cash')->group(function (): void {
                // Cash registers (puntos de cobro físicos)
                Route::get('registers', [CashRegistersController::class, 'index']);
                Route::get('registers/{register:uuid}', [CashRegistersController::class, 'show']);
                Route::post('registers', [CashRegistersController::class, 'store']);

                // Cash sessions
                Route::get('sessions', [CashSessionsController::class, 'index']);
                Route::get('sessions/{session:uuid}', [CashSessionsController::class, 'show']);
                Route::get('sessions/{session:uuid}/report', [CashSessionsController::class, 'report']);
                Route::post('sessions/open', [CashSessionsController::class, 'open']);
                Route::post('sessions/{session:uuid}/close', [CashSessionsController::class, 'close']);

                // Movements within a session
                Route::get('sessions/{session:uuid}/movements', [CashMovementsController::class, 'index']);
                Route::post('sessions/{session:uuid}/movements', [CashMovementsController::class, 'store']);
            });

            // ----- Clientes -----
            Route::apiResource('customers', CustomersController::class)
                ->parameters(['customers' => 'customer'])
                ->scoped(['customer' => 'uuid']);

            // ----- Sync (sec. 38.3) -----
            Route::prefix('sync')->group(function (): void {
                Route::post('/batch', SyncBatchController::class)
                    ->name('sync.batch');
                Route::get('/changes', SyncChangesController::class)
                    ->name('sync.changes');
                Route::get('/heartbeat', SyncHeartbeatController::class)
                    ->name('sync.heartbeat');
            });
            // ----- Folios (ADR-0009) -----
            Route::prefix('folio-ranges')->group(function (): void {
                Route::post('/reserve', [FolioRangesController::class, 'reserve'])
                    ->name('folio-ranges.reserve');
            });
            // ----- Ventas -----
            Route::prefix('sales')->group(function (): void {
                Route::get('/', [SalesController::class, 'index'])
                    ->name('sales.index');
                Route::get('/{uuid}', [SalesController::class, 'show'])
                    ->name('sales.show');
                Route::post('/', [SalesController::class, 'store'])
                    ->name('sales.store');
                Route::post('/{uuid}/cancel', [SalesController::class, 'cancel'])
                    ->name('sales.cancel');
            });

            // ----- Reportes -----
            Route::prefix('reports')->group(function (): void {
                Route::get('sales-summary', [ReportsController::class, 'salesSummary'])
                    ->name('reports.sales-summary');

                // Reportes consolidados cross-sucursal (doc maestro 46.6).
                Route::prefix('consolidated')->group(function (): void {
                    Route::get('sales-daily', [ReportsController::class, 'consolidatedSalesDaily'])
                        ->name('reports.consolidated.sales-daily');
                    Route::get('inventory', [ReportsController::class, 'consolidatedInventory'])
                        ->name('reports.consolidated.inventory');
                    Route::get('branch-comparison', [ReportsController::class, 'consolidatedBranchComparison'])
                        ->name('reports.consolidated.branch-comparison');
                });
            });
        });
    });

});
