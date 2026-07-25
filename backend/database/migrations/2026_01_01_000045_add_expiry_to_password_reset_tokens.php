<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vigencia y trazabilidad para password_reset_tokens (flujo 57.6 del maestro).
 *
 * La tabla existe desde 000003 con el esquema estandar de Laravel adaptado a
 * multi-tenant (company_id, email, token, created_at) y PK compuesta que ya
 * garantiza un solo token vivo por usuario. Faltaba lo que exige el flujo:
 * vigencia explicita (1 hora), marca de consumo de un solo uso y rastro de
 * quien emitio el reset.
 *
 * Decisiones documentadas:
 * - expires_at explicito en vez de derivarlo de created_at: la ventana puede
 *   volverse configurable sin reinterpretar filas ya emitidas.
 * - used_at en vez de borrar la fila al canjear: conserva evidencia para
 *   auditoria; la PK compuesta permite sobrescribir al emitir uno nuevo.
 * - created_by nullable: null = auto-servicio (forgot-password, diferido al
 *   slice de email), con valor = reset administrativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['expires_at', 'used_at', 'created_by']);
        });
    }
};
