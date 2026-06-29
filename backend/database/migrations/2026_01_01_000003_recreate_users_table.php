<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla users del producto.
 *
 * Sobrescribe la migración default de Laravel (que ya corrió en
 * migrate). Para que esta migración tome control limpiamente,
 * primero dropea la tabla default si existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop de la tabla default de Laravel si existe (sin datos relevantes)
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            // Sucursal default del usuario. Puede ser NULL si tiene acceso
            // a todas las del tenant (admin) o se elige al hacer login.
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->nullOnDelete();
            $table->index(['company_id', 'branch_id']);

            // Identificación
            $table->string('name', 200);
            $table->string('email', 200);
            $table->string('username', 60)->nullable()
                ->comment('Login alternativo a email para usuarios sin email (cajeros).');
            $table->timestamp('email_verified_at')->nullable();

            // Auth: password
            $table->string('password', 255)
                ->comment('bcrypt hash');

            // Auth: PIN (4-8 dígitos para acciones rápidas y autorizaciones in-flight)
            $table->string('pin_hash', 255)->nullable()
                ->comment('bcrypt hash del PIN. NULL si el usuario no tiene PIN configurado.');
            $table->timestamp('pin_set_at')->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();

            // 2FA (placeholder Fase 4+, columnas listas)
            $table->boolean('two_factor_enabled')->default(false);
            $table->string('two_factor_secret', 255)->nullable();
            $table->jsonb('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // Bloqueo por intentos fallidos de password
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            // Estado
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();

            // Auditoría de login
            $table->timestamp('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->string('last_login_user_agent', 500)->nullable();
            $table->string('last_login_device_id', 100)->nullable();

            // Preferencias
            $table->string('locale', 10)->nullable();
            $table->string('timezone', 50)->nullable();
            $table->jsonb('preferences')->default('{}');

            // Token "remember me" + softdeletes
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            // Email único POR TENANT, no global (dos tenants pueden tener
            // un usuario con el mismo email — son dos personas distintas
            // o la misma con cuentas separadas).
            $table->unique(['company_id', 'email']);
            $table->unique(['company_id', 'username']);

            $table->index('email');
            $table->index('is_active');
            $table->index('locked_until');
        });

        TenantTable::enableRls('users');

        // Tabla password_reset_tokens recreada (por tenant también):
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            TenantTable::companyColumn($table);
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary(['company_id', 'email']);
        });

        TenantTable::enableRls('password_reset_tokens');
    }

    public function down(): void
    {
        TenantTable::disableRls('password_reset_tokens');
        Schema::dropIfExists('password_reset_tokens');
        TenantTable::disableRls('users');
        Schema::dropIfExists('users');
    }
};
