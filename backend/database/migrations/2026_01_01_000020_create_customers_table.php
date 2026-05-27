<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes.
 *
 * - "code" es opcional (algunos negocios usan código interno, otros no)
 * - "tax_id" es el RFC/NIT/CUIT/etc según el país
 * - "type" distingue persona física (individual) vs moral (business)
 * - "tax_data" jsonb permite datos específicos por país (ej. uso CFDI, régimen fiscal en MX)
 * - "credit_limit" / "credit_balance" preparan el flujo de crédito de Fase 2
 * - Ventas al público en general NO requieren cliente: customer_id en sales será nullable
 *
 * Índices únicos PARCIALES (solo cuando NO es null):
 * - (company_id, code) WHERE code IS NOT NULL
 * - (company_id, email) WHERE email IS NOT NULL
 * - (company_id, tax_id) WHERE tax_id IS NOT NULL
 * Esto permite tener N clientes "públicos" sin código/email/RFC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            // Identificación
            $table->string('code', 50)->nullable()
                ->comment('Código interno del cliente (opcional)');
            $table->enum('type', ['individual', 'business'])->default('individual');
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable()
                ->comment('Razón social (suele coincidir con name en personas físicas)');

            // Datos fiscales
            $table->string('tax_id', 50)->nullable()
                ->comment('RFC en MX, NIT en CO, CUIT en AR, etc.');
            $table->jsonb('tax_data')->default('{}')
                ->comment('Datos fiscales específicos por país (uso CFDI, régimen, etc.)');

            // Contacto
            $table->string('email', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();

            // Dirección (denormalizada simple; multi-direcciones en Fase 2)
            $table->string('address_line', 300)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->nullable();

            // Crédito
            $table->decimal('credit_limit', 14, 2)->default(0)
                ->comment('Límite de crédito autorizado. 0 = solo contado');
            $table->decimal('credit_balance', 14, 2)->default(0)
                ->comment('Saldo actual del cliente. Positivo = nos debe');

            // Estado
            $table->boolean('is_active')->default(true);
            $table->boolean('is_blocked')->default(false)
                ->comment('Bloqueado: no puede comprar (deuda, fraude, etc.)');
            $table->string('blocked_reason', 500)->nullable();

            // Notas internas
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            // Índices b-tree
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'phone']);
            $table->index(['company_id', 'is_blocked']);
        });

        TenantTable::enableRls('customers');

        // Únicos parciales: permiten múltiples nulls pero unicidad cuando hay valor
        DB::statement('CREATE UNIQUE INDEX customers_company_code_unique
            ON customers (company_id, code) WHERE code IS NOT NULL AND deleted_at IS NULL');

        DB::statement('CREATE UNIQUE INDEX customers_company_email_unique
            ON customers (company_id, lower(email)) WHERE email IS NOT NULL AND deleted_at IS NULL');

        DB::statement('CREATE UNIQUE INDEX customers_company_tax_id_unique
            ON customers (company_id, upper(tax_id)) WHERE tax_id IS NOT NULL AND deleted_at IS NULL');

        // GIN trigram en name para búsqueda rápida en POS
        DB::statement('CREATE INDEX customers_name_trgm
            ON customers USING gin (name gin_trgm_ops)');

        // Check: credit_limit no negativo, credit_balance puede ser negativo
        // (negativo = nosotros le debemos al cliente, ej. anticipos)
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_limit_non_negative
            CHECK (credit_limit >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_credit_limit_non_negative');
        DB::statement('DROP INDEX IF EXISTS customers_name_trgm');
        DB::statement('DROP INDEX IF EXISTS customers_company_tax_id_unique');
        DB::statement('DROP INDEX IF EXISTS customers_company_email_unique');
        DB::statement('DROP INDEX IF EXISTS customers_company_code_unique');
        TenantTable::disableRls('customers');
        Schema::dropIfExists('customers');
    }
};
