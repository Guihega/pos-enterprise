<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla personal_access_tokens de Sanctum.
 *
 * Se publica explícitamente en el repo (en lugar de depender del autodiscover
 * de migraciones del paquete) para tener control total del schema y poder
 * ajustarlo (índices adicionales, columnas custom).
 *
 * Notas:
 *   - tokenable_type + tokenable_id apuntan polimorficamente al usuario
 *     (App\Domain\Identity\Models\User en nuestro caso).
 *   - El tenant queda implícito: el user pertenece a un company_id, así
 *     que el token también está asociado a ese tenant.
 *   - last_used_at se actualiza por Sanctum en cada request autenticado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            // morphs() crea las columnas tokenable_type + tokenable_id Y un
            // índice compuesto sobre ambas. NO agregar otro $table->index()
            // sobre las mismas columnas: causa "Duplicate table" en el índice.
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
