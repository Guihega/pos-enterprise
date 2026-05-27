<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // Garantiza que ningún test "filtre" contexto al siguiente.
        TenantContext::forget();
        parent::tearDown();
    }

    /**
     * Helper: ejecuta el resto del test bajo el contexto del tenant indicado.
     */
    protected function actingAsTenant(Company $company): static
    {
        TenantContext::set($company);

        return $this;
    }
}
