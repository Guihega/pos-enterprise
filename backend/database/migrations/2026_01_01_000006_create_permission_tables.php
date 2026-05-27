<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de spatie/laravel-permission con teams habilitado.
 *
 * Decisiones:
 *   - Usamos "company_id" como nombre de la columna de team
 *     (config('permission.column_names.team_foreign_key') = 'company_id').
 *   - Cada tabla aplica RLS de Postgres (ADR-0006), de modo que aún si
 *     alguien usa los modelos directos de Spatie sin pasar por el scope
 *     de Eloquent, la BD filtra.
 *   - El nombre del role/permission es único POR tenant (un "admin" de
 *     A es distinto del "admin" de B).
 *   - guard_name fijo = 'sanctum'. Añadirlo al unique evita choques si
 *     en el futuro hay otros guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teamForeignKey = 'company_id';

        // ---- permissions ----
        Schema::create('permissions', function (Blueprint $table) use ($teamForeignKey): void {
            $table->bigIncrements('id');
            // company_id como team_id de Spatie. Nullable para permitir
            // permisos GLOBALES (compartidos entre tenants) si alguna vez
            // los necesitamos; en práctica todos llevarán company_id.
            $table->unsignedBigInteger($teamForeignKey)->nullable();
            $table->index($teamForeignKey, 'permissions_company_id_index');

            $table->string('name');
            $table->string('guard_name', 32);
            $table->timestampsTz();

            $table->unique([$teamForeignKey, 'name', 'guard_name'], 'permissions_company_name_guard_unique');
        });

        // FK a companies + RLS (excepto permisos globales con company_id NULL)
        DB::statement('ALTER TABLE permissions ADD CONSTRAINT permissions_company_id_foreign '.
            'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE');

        // RLS condicional: filtra cuando hay tenant context, deja pasar
        // los registros con company_id IS NULL (permisos globales).
        DB::statement('ALTER TABLE permissions ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation_permissions ON permissions
            USING (company_id IS NULL OR company_id = current_tenant_id())");

        // ---- roles ----
        Schema::create('roles', function (Blueprint $table) use ($teamForeignKey): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger($teamForeignKey)->nullable();
            $table->index($teamForeignKey, 'roles_company_id_index');

            $table->string('name');
            $table->string('guard_name', 32);
            $table->timestampsTz();

            $table->unique([$teamForeignKey, 'name', 'guard_name'], 'roles_company_name_guard_unique');
        });

        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_company_id_foreign '.
            'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE roles ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation_roles ON roles
            USING (company_id IS NULL OR company_id = current_tenant_id())");

        // ---- model_has_permissions (pivot) ----
        Schema::create('model_has_permissions', function (Blueprint $table) use ($teamForeignKey): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign('permission_id')
                ->references('id')->on('permissions')->cascadeOnDelete();

            $table->unsignedBigInteger($teamForeignKey);
            $table->index($teamForeignKey, 'model_has_permissions_company_id_index');

            $table->primary([$teamForeignKey, 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_pkey');
        });

        DB::statement('ALTER TABLE model_has_permissions ADD CONSTRAINT model_has_permissions_company_id_foreign '.
            'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE model_has_permissions ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation_model_has_permissions ON model_has_permissions
            USING (company_id = current_tenant_id())");

        // ---- model_has_roles (pivot) ----
        Schema::create('model_has_roles', function (Blueprint $table) use ($teamForeignKey): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign('role_id')
                ->references('id')->on('roles')->cascadeOnDelete();

            $table->unsignedBigInteger($teamForeignKey);
            $table->index($teamForeignKey, 'model_has_roles_company_id_index');

            $table->primary([$teamForeignKey, 'role_id', 'model_id', 'model_type'],
                'model_has_roles_pkey');
        });

        DB::statement('ALTER TABLE model_has_roles ADD CONSTRAINT model_has_roles_company_id_foreign '.
            'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE model_has_roles ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation_model_has_roles ON model_has_roles
            USING (company_id = current_tenant_id())");

        // ---- role_has_permissions ----
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->foreign('permission_id')
                ->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')
                ->references('id')->on('roles')->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_pkey');
        });
        // No RLS aquí: los registros se aíslan implícitamente porque permissions
        // y roles ya están aislados; el join filtra en cascada.
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
