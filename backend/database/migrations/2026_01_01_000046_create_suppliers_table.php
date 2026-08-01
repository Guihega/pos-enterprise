<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maestro de proveedores (4.1.5 Compras).
 *
 * Alcance deliberadamente minimo: identificacion y contacto. NO incluye
 * datos fiscales (RFC, regimen) porque este ciclo no aborda facturacion de
 * proveedor; agregarlos sin uso seria deuda disfrazada de prevision. El
 * enganche fiscal futuro ya existe en inventory_movements.external_folio.
 *
 * Sin branch_id: un proveedor le vende al tenant, no a una sucursal. Es la
 * diferencia con warehouses, que si vive dentro de una branch.
 *
 * Preparado para escalar: purchase_orders colgara de suppliers.id con FK, y
 * product_batches (000035) recuperara su supplier_id, omitido entonces por
 * no existir este dominio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);
            $table->string('code', 30);
            $table->string('name', 200);
            $table->string('contact_name', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code'], 'suppliers_company_code_unique');
            $table->index(['company_id', 'name']);
        });

        TenantTable::enableRls('suppliers');
    }

    public function down(): void
    {
        TenantTable::disableRls('suppliers');
        Schema::dropIfExists('suppliers');
    }
};
