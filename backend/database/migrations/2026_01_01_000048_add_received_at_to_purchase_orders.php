<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca temporal de recepcion para purchase_orders.
 *
 * La 000047 creo submitted_at, approved_at y cancelled_at, pero no
 * received_at: el estado received existia en el enum sin ser alcanzable,
 * porque /receive quedaba fuera de aquella entrega. Al implementarlo hace
 * falta la columna simetrica a las otras tres.
 *
 * Va en migracion propia y no editando la 000047 porque esa ya esta
 * aplicada en entornos y mergeada en main.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->timestampTz('received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn('received_at');
        });
    }
};
