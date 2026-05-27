<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Provisiona catálogo default (unidades y impuestos) para un tenant nuevo.
 *
 * Idempotente: usa firstOrCreate. Llamar varias veces no duplica.
 *
 * Las unidades son universales (PZA, KG, G, LT, ML, MT, CM).
 * Los impuestos dependen del country_code del tenant.
 */
final class CatalogProvisioner
{
    /**
     * Provisiona unidades + impuestos default.
     */
    public function provision(Company $company): void
    {
        TenantContext::runAs($company, function () use ($company): void {
            DB::transaction(function () use ($company): void {
                $this->provisionUnits($company);
                $this->provisionTaxes($company);
            });
        });
    }

    private function provisionUnits(Company $company): void
    {
        $units = [
            ['code' => 'PZA', 'name' => 'Pieza', 'plural_name' => 'Piezas', 'symbol' => 'pza',
                'category' => Unit::CATEGORY_COUNT, 'factor' => 1, 'is_decimal' => false],
            ['code' => 'KG', 'name' => 'Kilogramo', 'plural_name' => 'Kilogramos', 'symbol' => 'kg',
                'category' => Unit::CATEGORY_WEIGHT, 'factor' => 1000, 'is_decimal' => true],
            ['code' => 'G', 'name' => 'Gramo', 'plural_name' => 'Gramos', 'symbol' => 'g',
                'category' => Unit::CATEGORY_WEIGHT, 'factor' => 1, 'is_decimal' => true],
            ['code' => 'LT', 'name' => 'Litro', 'plural_name' => 'Litros', 'symbol' => 'l',
                'category' => Unit::CATEGORY_VOLUME, 'factor' => 1000, 'is_decimal' => true],
            ['code' => 'ML', 'name' => 'Mililitro', 'plural_name' => 'Mililitros', 'symbol' => 'ml',
                'category' => Unit::CATEGORY_VOLUME, 'factor' => 1, 'is_decimal' => true],
            ['code' => 'MT', 'name' => 'Metro', 'plural_name' => 'Metros', 'symbol' => 'm',
                'category' => Unit::CATEGORY_LENGTH, 'factor' => 100, 'is_decimal' => true],
            ['code' => 'CM', 'name' => 'Centímetro', 'plural_name' => 'Centímetros', 'symbol' => 'cm',
                'category' => Unit::CATEGORY_LENGTH, 'factor' => 1, 'is_decimal' => true],
            ['code' => 'CJA', 'name' => 'Caja', 'plural_name' => 'Cajas', 'symbol' => 'cja',
                'category' => Unit::CATEGORY_OTHER, 'factor' => 1, 'is_decimal' => false],
            ['code' => 'PQT', 'name' => 'Paquete', 'plural_name' => 'Paquetes', 'symbol' => 'pqt',
                'category' => Unit::CATEGORY_OTHER, 'factor' => 1, 'is_decimal' => false],
        ];

        foreach ($units as $unit) {
            Unit::firstOrCreate(
                ['company_id' => $company->id, 'code' => $unit['code']],
                $unit + ['company_id' => $company->id, 'is_active' => true]
            );
        }
    }

    private function provisionTaxes(Company $company): void
    {
        $taxes = match ($company->country_code) {
            'MX' => $this->mexicoTaxes(),
            'US' => $this->unitedStatesTaxes(),
            'CO' => $this->colombiaTaxes(),
            'AR' => $this->argentinaTaxes(),
            default => $this->genericTaxes(),
        };

        foreach ($taxes as $tax) {
            Tax::firstOrCreate(
                ['company_id' => $company->id, 'code' => $tax['code']],
                $tax + ['company_id' => $company->id, 'is_active' => true]
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function mexicoTaxes(): array
    {
        return [
            [
                'code' => 'IVA-16',
                'name' => 'IVA Tasa General 16%',
                'rate' => 0.16,
                'type' => Tax::TYPE_VAT,
                'is_inclusive' => true,
                'is_default' => true,
            ],
            [
                'code' => 'IVA-8',
                'name' => 'IVA Frontera 8%',
                'rate' => 0.08,
                'type' => Tax::TYPE_VAT,
                'is_inclusive' => true,
                'is_default' => false,
            ],
            [
                'code' => 'IVA-0',
                'name' => 'IVA Tasa 0% (productos básicos)',
                'rate' => 0.00,
                'type' => Tax::TYPE_VAT,
                'is_inclusive' => true,
                'is_default' => false,
            ],
            [
                'code' => 'EXENTO',
                'name' => 'Exento',
                'rate' => 0.00,
                'type' => Tax::TYPE_OTHER,
                'is_inclusive' => true,
                'is_default' => false,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function unitedStatesTaxes(): array
    {
        return [
            [
                'code' => 'NO-TAX',
                'name' => 'No tax',
                'rate' => 0.00,
                'type' => Tax::TYPE_SALES_TAX,
                'is_inclusive' => false,
                'is_default' => true,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function colombiaTaxes(): array
    {
        return [
            [
                'code' => 'IVA-19',
                'name' => 'IVA 19%',
                'rate' => 0.19,
                'type' => Tax::TYPE_VAT,
                'is_inclusive' => true,
                'is_default' => true,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function argentinaTaxes(): array
    {
        return [
            [
                'code' => 'IVA-21',
                'name' => 'IVA 21%',
                'rate' => 0.21,
                'type' => Tax::TYPE_VAT,
                'is_inclusive' => true,
                'is_default' => true,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function genericTaxes(): array
    {
        return [
            [
                'code' => 'TAX-DEFAULT',
                'name' => 'Tax default',
                'rate' => 0.00,
                'type' => Tax::TYPE_OTHER,
                'is_inclusive' => false,
                'is_default' => true,
            ],
        ];
    }
}
