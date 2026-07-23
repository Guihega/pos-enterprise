<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Maestro 39.1 (stock insuficiente en sync): "acepta venta, permite
 * stock negativo, alerta admin". Las ventas offline son historicas y
 * se aceptan aunque dejen quantity_on_hand negativo; el faltante es
 * senal real de inventario, no un error.
 *
 * Relaja SOLO lo necesario:
 * - stocks: quantity_on_hand puede ser negativo; quantity_reserved y
 *   average_cost siguen no-negativos.
 * - inventory_movements: quantity_after refleja el stock resultante,
 *   que ahora puede ser negativo; el CHECK se elimina.
 *
 * El down restaura los CHECK originales de 000015 (fallara si existen
 * filas negativas: comportamiento esperado, el rollback exige limpiar
 * el estado que el constraint prohibe).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stocks DROP CONSTRAINT IF EXISTS stocks_quantities_non_negative');
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_quantities_non_negative
            CHECK (quantity_reserved >= 0 AND average_cost >= 0)');
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS inventory_movements_after_non_negative');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stocks DROP CONSTRAINT IF EXISTS stocks_quantities_non_negative');
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_quantities_non_negative
            CHECK (quantity_on_hand >= 0 AND quantity_reserved >= 0 AND average_cost >= 0)');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_after_non_negative
            CHECK (quantity_after >= 0)');
    }
};
