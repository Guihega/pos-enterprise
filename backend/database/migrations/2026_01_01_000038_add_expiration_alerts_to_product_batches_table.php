<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-195 / scheduler batches:detect-expiring. Idempotencia patron
     * EX-043 (como lost_alerted_at en transfers): la caducidad es un
     * estado que no revierte, cada lote se alerta una sola vez por
     * umbral mediante whereNull + marca de timestamp al notificar.
     */
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->timestamp('expiring_alerted_at')->nullable()->after('status');
            $table->timestamp('expired_alerted_at')->nullable()->after('expiring_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropColumn(['expiring_alerted_at', 'expired_alerted_at']);
        });
    }
};
