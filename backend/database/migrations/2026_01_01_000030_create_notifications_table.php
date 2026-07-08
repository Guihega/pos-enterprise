<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notificaciones in-app / multicanal (doc maestro 26.14 y 11.9).
 *
 * Polimorfica: notifiable = el destinatario (normalmente User). data JSONB
 * lleva el payload de la notificacion. channels indica por donde se envio
 * (in-app siempre; email/sms/push/whatsapp son extensiones). severity para
 * priorizar. read_at NULL = no leida. expires_at opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->string('type', 180);

            // Destinatario polimorfico (User u otra entidad notificable).
            $table->string('notifiable_type', 180);
            $table->unsignedBigInteger('notifiable_id');

            $table->jsonb('data');
            $table->jsonb('channels')->default(DB::raw("'[]'::jsonb"));
            $table->string('severity', 20)->default('info');

            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->timestampsTz();

            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'idx_notifications_notifiable');
            $table->index(['company_id', 'type']);
        });

        // No-leidas por destinatario (indice parcial, doc 26.14).
        DB::statement('CREATE INDEX idx_notifications_unread
            ON notifications (notifiable_id) WHERE read_at IS NULL');

        TenantTable::enableRls('notifications');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_notifications_unread');
        TenantTable::disableRls('notifications');
        Schema::dropIfExists('notifications');
    }
};
