<?php

declare(strict_types=1);

use App\Support\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturas de proveedor y pagos (4.1.5, maestro 29.7).
 *
 * El maestro define ambas tablas en las lineas 4541-4577 y son su UNICA
 * especificacion: no hay prosa, reglas de negocio ni casos de uso que las
 * mencionen. Todo lo que no esta en ese SQL es decision de alcance y queda
 * documentado aqui.
 *
 * DIVERGENCIAS DELIBERADAS CON EL MAESTRO:
 *
 * 1. decimal(18,4) en vez de NUMERIC(14,2). match() compara el total de la
 *    factura contra el valor recibido de la OC, y purchase_order_items usa
 *    (18,4): comparar escalas distintas obliga a redondear en algun punto y
 *    ese punto se vuelve fuente de falsos 422. El dinero se redondea al
 *    presentar, no al almacenar.
 *
 * 2. SIN bank_account_id en supplier_payments. El maestro lo declara como FK
 *    a bank_accounts, tabla que NO existe en el repo (grep vacio sobre todas
 *    las migraciones) y que el propio maestro no define en ninguna parte.
 *    Crearla a ciegas seria tesoreria, no CxP. La columna reference (120)
 *    cubre anotar transferencia o cheque. Si algun dia hay tesoreria, entra
 *    por ALTER como la 000045 y la 000049.
 *
 * 3. SIN columna balance generada. El maestro la define GENERATED ALWAYS AS
 *    (total - paid_amount) STORED, pero NINGUNA migracion del repo usa
 *    storedAs ni virtualAs. Una generada no se puede escribir (hay que
 *    excluirla de fillable) y exige refresh() tras cada pago o se lee el
 *    valor viejo. El saldo es funcion pura de paid_amount: se calcula al
 *    vuelo en el Resource y en GET /suppliers/{uuid}/balance.
 *
 * DECISIONES DE ALCANCE:
 *
 * - folio de la FACTURA lo emite el proveedor: se captura, no se genera. El
 *   unique es (company_id, supplier_id, folio) porque dos proveedores
 *   distintos pueden emitir el mismo numero; asi se impide capturar dos
 *   veces la misma factura, que es el error de captura mas comun.
 * - folio del PAGO es propio: PAY-{6 digitos} por tenant, patron de
 *   nextFolio() de PurchaseOrderService (sin lockForUpdate, leccion 15), con
 *   unique (company_id, folio) como red.
 * - supplier_payments NO lleva softDeletes: un movimiento de dinero no se
 *   borra, se contra-registra. Con borrado logico un pago invisible al
 *   global scope descuadraria paid_amount. Es el patron de linea hija
 *   (leccion 14). COROLARIO: nextFolio de pagos NO puede usar withTrashed()
 *   porque el modelo no tiene SoftDeletes; tampoco lo necesita, sin borrado
 *   logico max(id) no recicla consecutivos (leccion 16 no aplica aqui).
 * - status se DERIVA de paid_amount (0 pending, < total partial, = total
 *   paid). Evita dos fuentes de verdad para el mismo hecho.
 *   DIFERIDO: 'cancelled' existe en el enum porque el maestro lo define,
 *   pero NINGUNO de los 6 endpoints de 29.7 lo emite. Queda inalcanzable
 *   hasta que exista un endpoint de cancelacion. El enum se declara completo
 *   para no migrar la columna dos veces (mismo criterio que 'received' en la
 *   000047).
 * - purchase_order_id es nullable: el maestro admite factura sin OC, y match()
 *   es justamente el endpoint que la vincula despues.
 * - invoice_id del pago es nullable: el maestro admite pago a cuenta sin
 *   factura. DIFERIDO: como se aplica un pago sin factura al saldo del
 *   proveedor no esta definido en ninguna parte y no lo resuelve esta entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('supplier_id');
            $table->foreign('supplier_id')
                ->references('id')->on('suppliers');

            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->foreign('purchase_order_id')
                ->references('id')->on('purchase_orders')
                ->nullOnDelete();

            $table->string('folio', 80)
                ->comment('Lo emite el proveedor: se captura, no se genera');
            $table->uuid('cfdi_uuid')->nullable();
            $table->string('cfdi_xml_url', 500)->nullable();
            $table->date('issue_date');
            $table->date('due_date');

            $table->decimal('subtotal', 18, 4);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4);
            $table->decimal('paid_amount', 18, 4)->default(0)
                ->comment('Unica fuente de verdad del saldo; balance se calcula al vuelo');

            $table->enum('status', ['pending', 'partial', 'paid', 'cancelled'])
                ->default('pending')
                ->comment('Derivado de paid_amount; cancelled aun no lo emite ningun endpoint');

            $table->string('payment_method', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'supplier_id', 'folio'], 'supplier_invoices_company_supplier_folio_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id']);
            $table->index(['company_id', 'purchase_order_id']);
        });

        TenantTable::enableRls('supplier_invoices');

        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            TenantTable::companyColumn($table);

            $table->unsignedBigInteger('supplier_id');
            $table->foreign('supplier_id')
                ->references('id')->on('suppliers');

            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->foreign('invoice_id')
                ->references('id')->on('supplier_invoices')
                ->nullOnDelete();

            $table->string('folio', 40)
                ->comment('Propio: PAY-{6} por tenant, sin lock (leccion 15)');
            $table->date('payment_date');
            $table->decimal('amount', 18, 4);
            $table->string('method', 40);
            $table->string('reference', 120)->nullable()
                ->comment('Transferencia, cheque o similar; sustituye a bank_account_id');

            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')
                ->references('id')->on('users');

            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'folio'], 'supplier_payments_company_folio_unique');
            $table->index(['company_id', 'supplier_id']);
            $table->index(['company_id', 'invoice_id']);
        });

        TenantTable::enableRls('supplier_payments');
    }

    public function down(): void
    {
        TenantTable::disableRls('supplier_payments');
        Schema::dropIfExists('supplier_payments');
        TenantTable::disableRls('supplier_invoices');
        Schema::dropIfExists('supplier_invoices');
    }
};
