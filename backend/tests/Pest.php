<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case por defecto para Pest
|--------------------------------------------------------------------------
|
| Aplica TestCase + RefreshDatabase a todo lo que esté bajo tests/Feature
| y tests/Unit. Cada test corre dentro de una transacción que se revierte
| al finalizar, garantizando aislamiento entre tests.
|
*/

pest()
    ->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations personalizados
|--------------------------------------------------------------------------
*/

expect()->extend('toBeUuid', function () {
    return $this->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

/*
|--------------------------------------------------------------------------
| Helpers globales para tests
|--------------------------------------------------------------------------
*/

if (! function_exists('expectQueryException')) {
    /**
     * Ejecuta un closure que se espera lance QueryException.
     *
     * Lo aísla en un savepoint (DB::transaction interno) para que la
     * transacción exterior de RefreshDatabase no quede abortada por
     * el error de Postgres (SQLSTATE[25P02]).
     *
     * Patrón requerido cuando un test verifica constraints de BD
     * (unique, FK, NOT NULL, check) en Postgres.
     *
     * Marca el test como NO-risky agregando una expectation explícita.
     */
    function expectQueryException(Closure $callback): void
    {
        $thrown = false;
        try {
            DB::transaction($callback);
        } catch (QueryException $e) {
            $thrown = true;
        }

        // Esta expectation evita que Pest marque el test como "risky"
        // por no tener assertions visibles.
        expect($thrown)->toBeTrue('Expected a QueryException but none was thrown.');
    }
}
