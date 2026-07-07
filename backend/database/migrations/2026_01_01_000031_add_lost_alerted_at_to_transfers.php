<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EX-043: marca de alerta de transferencia perdida.
 *
 * Una transferencia en estado sent que nunca se recibe (sent_at mas antiguo
 * que el TTL) escala a admin. Esta columna registra CUANDO se emitio la
 * alerta, garantizando idempotencia del scheduler: cada transferencia perdida
 * se notifica una sola vez (el comando filtra lost_alerted_at IS NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->timestampTz('lost_alerted_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn('lost_alerted_at');
        });
    }
};
