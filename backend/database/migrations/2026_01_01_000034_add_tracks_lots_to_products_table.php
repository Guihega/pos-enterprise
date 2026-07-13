<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RN-034: producto con caducidad requiere tracks_lots=true.
 * Flag que habilita el manejo de lotes (doc maestro 26.x products.tracks_lots).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('tracks_lots')->default(false)->after('track_inventory');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('tracks_lots');
        });
    }
};
