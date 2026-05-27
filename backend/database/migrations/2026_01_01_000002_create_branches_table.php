<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->string('code', 20)
                ->comment('Código corto único por tenant. Ej: "CTR", "PB-01".');
            $table->string('name', 200);

            // Datos fiscales y operativos
            $table->string('tax_id', 30)->nullable()
                ->comment('Override del tax_id de la company si aplica.');
            $table->string('series', 10)->default('A')
                ->comment('Serie de folio para tickets. Ej: "A".');

            // Localización
            $table->string('country_code', 2)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Contacto
            $table->string('phone', 30)->nullable();
            $table->string('email', 200)->nullable();

            // Configuración
            $table->string('timezone', 50)->nullable()
                ->comment('Override del timezone de la company.');
            $table->jsonb('settings')->default('{}')
                ->comment('Settings específicos: impresora default, métodos de pago, etc.');

            // Estado
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false)
                ->comment('Una sucursal default por tenant. Útil para signups simples.');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        TenantTable::enableRls('branches');

        // Solo una sucursal default por tenant.
        DB::statement('
            CREATE UNIQUE INDEX branches_one_default_per_company
            ON branches (company_id)
            WHERE is_default = TRUE AND deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        TenantTable::disableRls('branches');
        Schema::dropIfExists('branches');
    }
};
