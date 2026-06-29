<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /** @var RoleProvisioner $roleProvisioner */
        $roleProvisioner = app(RoleProvisioner::class);

        /** @var CatalogProvisioner $catalogProvisioner */
        $catalogProvisioner = app(CatalogProvisioner::class);

        // ==============================================
        //  Tenant demo
        // ==============================================
        $demo = Company::factory()->create([
            'slug' => 'demo',
            'name' => 'Tienda Demo',
            'legal_name' => 'Tienda Demo S.A. de C.V.',
            'tax_id' => 'XAXX010101000',
            'plan' => Company::PLAN_BUSINESS,
            'status' => Company::STATUS_ACTIVE,
            'limits' => [
                'branches' => 20,
                'users' => 100,
                'products' => 100000,
                'transactions_per_month' => 500000,
                'webhooks' => 50,
            ],
        ]);

        $roleProvisioner->provisionDefaultRoles($demo);
        $catalogProvisioner->provision($demo);

        TenantContext::set($demo);

        try {
            $branchCenter = Branch::factory()->default()->create([
                'company_id' => $demo->id,
                'code' => 'CTR',
                'name' => 'Sucursal Centro',
                'address' => 'Av. Reforma 100, Col. Centro',
                'city' => 'Ciudad de México',
                'state' => 'CDMX',
                'postal_code' => '06000',
            ]);

            // Almacén default de la sucursal centro
            Warehouse::factory()
                ->default()->ofBranch($branchCenter)->create([
                    'code' => 'CTR-MAIN',
                    'name' => 'Piso de venta Centro',
                ]);

            $branchNorte = Branch::factory()->create([
                'company_id' => $demo->id,
                'code' => 'NRT',
                'name' => 'Sucursal Plaza Norte',
                'address' => 'Plaza Norte L-12',
                'city' => 'Ciudad de México',
                'state' => 'CDMX',
                'postal_code' => '02000',
            ]);
            Warehouse::factory()
                ->default()->ofBranch($branchNorte)->create([
                    'code' => 'NRT-MAIN',
                    'name' => 'Piso de venta Plaza Norte',
                ]);

            // admin@demo.local con rol admin (full access salvo super_admin)
            $admin = User::factory()->create([
                'company_id' => $demo->id,
                'branch_id' => $branchCenter->id,
                'name' => 'Administrador Demo',
                'email' => 'admin@demo.local',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $admin->setPin('5872');
            $admin->syncBranches(Branch::pluck('id')->all());
            $admin->assignRole(Roles::ADMIN);

            // cajero@demo.local con rol cajero (limitado)
            $cashier = User::factory()->create([
                'company_id' => $demo->id,
                'branch_id' => $branchCenter->id,
                'name' => 'Cajero Demo',
                'email' => 'cajero@demo.local',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $cashier->setPin('3690');
            $cashier->syncBranches([$branchCenter->id]);
            $cashier->assignRole(Roles::CAJERO);

        } finally {
            TenantContext::forget();
        }

        // ==============================================
        //  Tenant secundario: aislamiento manual
        // ==============================================
        $other = Company::factory()->create([
            'slug' => 'otra-empresa',
            'name' => 'Otra Empresa',
            'plan' => Company::PLAN_STARTER,
        ]);

        $roleProvisioner->provisionDefaultRoles($other);
        $catalogProvisioner->provision($other);

        TenantContext::set($other);
        try {
            $otherBranch = Branch::factory()->default()->create([
                'company_id' => $other->id,
                'code' => 'PRC',
                'name' => 'Sucursal Principal',
            ]);

            Warehouse::factory()
                ->default()->ofBranch($otherBranch)->create([
                    'code' => 'PRC-MAIN',
                    'name' => 'Piso de venta Principal',
                ]);

            $otherAdmin = User::factory()->create([
                'company_id' => $other->id,
                'branch_id' => $otherBranch->id,
                'name' => 'Admin Otra Empresa',
                'email' => 'admin@otra-empresa.local',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $otherAdmin->syncBranches([$otherBranch->id]);
            $otherAdmin->assignRole(Roles::ADMIN);
        } finally {
            TenantContext::forget();
        }

        // ==============================================
        //  Datos de desarrollo: catalogo de productos
        //  para el tenant `demo`. Solo en entornos no-prod.
        // ==============================================
        if (! app()->environment('production')) {
            $this->call(DevDataSeeder::class);
        }
    }
}
