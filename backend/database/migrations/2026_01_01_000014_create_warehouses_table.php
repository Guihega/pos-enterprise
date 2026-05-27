<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Almacenes. Una sucursal puede tener N almacenes:
 *   - Piso de venta (lo que está exhibido)
 *   - Bodega (lo de atrás)
 *   - Tránsito (mercancía en camino entre sucursales)
 *
 * "is_default" identifica el almacén principal de la sucursal donde
 * se hacen las ventas POS por defecto. Solo uno per branch.
 *
 * "is_sellable" determina si los productos en este almacén pueden
 * descontarse vía POS. False para bodega/tránsito (no exhibido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete();

            $table->string('code', 30);
            $table->string('name', 200);
            $table->string('description', 500)->nullable();

            $table->enum('type', ['main', 'storage', 'transit', 'damaged', 'consignment'])
                ->default('main')
                ->comment('Tipo de almacén: main=piso, storage=bodega, transit=en ruta, damaged=merma, consignment=consignación');

            $table->boolean('is_sellable')->default(true)
                ->comment('Si false, el stock aquí no aparece en pantalla de venta');
            $table->boolean('is_default')->default(false)
                ->comment('Almacén principal de la sucursal (uno por branch)');
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code'], 'warehouses_company_code_unique');
            $table->index(['company_id', 'branch_id']);
        });

        TenantTable::enableRls('warehouses');

        // Solo UN almacén default por branch (parcial unique)
        DB::statement('CREATE UNIQUE INDEX warehouses_one_default_per_branch
            ON warehouses (branch_id) WHERE is_default = true AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS warehouses_one_default_per_branch');
        TenantTable::disableRls('warehouses');
        Schema::dropIfExists('warehouses');
    }
};
