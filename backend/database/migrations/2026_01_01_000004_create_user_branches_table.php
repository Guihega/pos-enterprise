<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot user_branches: un usuario puede operar en varias sucursales.
 * El branch_id de users es solo la sucursal default; aquí está la lista
 * completa de sucursales autorizadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_branches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->timestampsTz();

            $table->unique(['user_id', 'branch_id']);
            $table->index(['company_id', 'user_id']);
        });

        TenantTable::enableRls('user_branches');
    }

    public function down(): void
    {
        TenantTable::disableRls('user_branches');
        Schema::dropIfExists('user_branches');
    }
};
