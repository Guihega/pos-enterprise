<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla raíz del multi-tenant: cada empresa cliente del SaaS.
 *
 * Esta tabla NO está sujeta a RLS (no es tenant-scoped, ES la fuente de
 * verdad de los tenants). Solo super_admin la lee/escribe en producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            // Identificación pública
            $table->string('slug', 60)->unique()
                ->comment('Identificador en URL / subdominio. Ej: "tienda-mx"');
            $table->string('name', 200);
            $table->string('legal_name', 250)->nullable();
            $table->string('tax_id', 30)->nullable()->index()
                ->comment('RFC en MX, CUIT en AR, NIT en CO, etc.');

            // País y configuración fiscal
            $table->char('country_code', 2)->default('MX')
                ->comment('ISO 3166-1 alpha-2');
            $table->char('currency_code', 3)->default('MXN')
                ->comment('ISO 4217');
            $table->string('timezone', 50)->default('America/Mexico_City');
            $table->string('locale', 10)->default('es_MX');

            // Plan y ciclo de vida
            $table->string('plan', 30)->default('free')
                ->comment('free | starter | business | enterprise');
            $table->string('status', 30)->default('trial')
                ->comment('trial | active | suspended | cancelled | deleted');
            $table->string('suspension_reason', 500)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Branding
            $table->string('logo_url', 500)->nullable();
            $table->string('primary_color', 7)->nullable()->comment('hex #RRGGBB');

            // Settings agrupados (catch-all flexible)
            $table->jsonb('settings')->default('{}');
            $table->jsonb('limits')->default('{}')
                ->comment('Límites del plan: branches, users, products, etc.');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('plan');
            $table->index('country_code');
            $table->index(['status', 'plan']);
        });

        // Verificar que el status sea uno permitido
        DB::statement("
            ALTER TABLE companies
            ADD CONSTRAINT companies_status_check
            CHECK (status IN ('trial', 'active', 'suspended', 'cancelled', 'deleted'))
        ");

        DB::statement("
            ALTER TABLE companies
            ADD CONSTRAINT companies_plan_check
            CHECK (plan IN ('free', 'starter', 'business', 'enterprise'))
        ");

        // Índice GIN sobre settings para búsquedas en el JSON
        DB::statement('CREATE INDEX companies_settings_gin ON companies USING gin (settings)');

        DB::statement("COMMENT ON TABLE companies IS 'Tenants del SaaS. Tabla raíz no sujeta a RLS.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
