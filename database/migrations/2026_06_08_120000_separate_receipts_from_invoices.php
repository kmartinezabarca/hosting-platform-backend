<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa claramente RECIBOS (receipts) de FACTURAS CFDI (invoices).
 *
 * Contexto: una migración previa renombró las TABLAS (invoices→receipts,
 * service_invoices→invoices) pero dejó las columnas FK con el nombre viejo
 * (`invoice_id`, `invoice_number`) y la tabla de líneas como `invoice_items`,
 * aunque todas pertenecen a los RECIBOS. Eso confunde: un dev ve `invoice_id`
 * y cree que apunta a la factura CFDI cuando en realidad apunta al recibo.
 *
 * Esta migración alinea la nomenclatura con el dominio:
 *   - invoice_items                 → receipt_items   (líneas del recibo)
 *   - receipt_items.invoice_id      → receipt_id      (FK al recibo)
 *   - invoices.invoice_id (CFDI)    → receipt_id      (la factura referencia su recibo)
 *   - transactions.invoice_id       → receipt_id
 *   - receipts.invoice_number       → receipt_number
 *   - drop tabla legacy `products`  (catálogo vive en service_plans)
 *
 * Todas las FK `invoice_id` referencian receipts.id (verificado).
 * Reversible vía down().
 */
return new class extends Migration
{
    public function up(): void
    {
        // IMPORTANTE: tablas renombradas en migraciones previas conservan el nombre
        // VIEJO de sus FK en MySQL (p. ej. la FK de invoices.invoice_id se llama
        // `service_invoices_invoice_id_foreign`). Por eso NO usamos dropForeign(['col'])
        // (que deriva el nombre del nombre actual de la tabla) sino que buscamos el
        // nombre real en information_schema. La migración además es IDEMPOTENTE para
        // poder retomar si una corrida anterior quedó a medias.

        // 1. Tabla de líneas: invoice_items → receipt_items
        if (Schema::hasTable('invoice_items') && ! Schema::hasTable('receipt_items')) {
            Schema::rename('invoice_items', 'receipt_items');
        }
        if (Schema::hasColumn('receipt_items', 'invoice_id')) {
            $this->dropForeignForColumn('receipt_items', 'invoice_id');
            Schema::table('receipt_items', fn (Blueprint $t) => $t->renameColumn('invoice_id', 'receipt_id'));
            Schema::table('receipt_items', fn (Blueprint $t) => $t->foreign('receipt_id')->references('id')->on('receipts')->cascadeOnDelete());
        }

        // 2. Factura CFDI: invoices.invoice_id (FK al recibo) → receipt_id
        if (Schema::hasColumn('invoices', 'invoice_id')) {
            $this->dropForeignForColumn('invoices', 'invoice_id');
            Schema::table('invoices', fn (Blueprint $t) => $t->renameColumn('invoice_id', 'receipt_id'));
            Schema::table('invoices', fn (Blueprint $t) => $t->foreign('receipt_id')->references('id')->on('receipts')->nullOnDelete());
        }

        // 3. Transacciones: transactions.invoice_id → receipt_id
        if (Schema::hasColumn('transactions', 'invoice_id')) {
            $this->dropForeignForColumn('transactions', 'invoice_id');
            Schema::table('transactions', fn (Blueprint $t) => $t->renameColumn('invoice_id', 'receipt_id'));
            Schema::table('transactions', fn (Blueprint $t) => $t->foreign('receipt_id')->references('id')->on('receipts')->nullOnDelete());
        }

        // 4. Número del recibo: receipts.invoice_number → receipt_number
        if (Schema::hasColumn('receipts', 'invoice_number')) {
            Schema::table('receipts', fn (Blueprint $t) => $t->renameColumn('invoice_number', 'receipt_number'));
        }

        // 5. Tabla legacy sin uso
        Schema::dropIfExists('products');
    }

    /**
     * Dropea la FK de (tabla, columna) usando su nombre REAL de constraint,
     * resolviéndolo desde information_schema (tolera renames de tabla previos).
     */
    private function dropForeignForColumn(string $table, string $column): void
    {
        $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->value('CONSTRAINT_NAME');

        if ($constraint) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->renameColumn('receipt_number', 'invoice_number');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['receipt_id']);
            $table->renameColumn('receipt_id', 'invoice_id');
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('receipts')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['receipt_id']);
            $table->renameColumn('receipt_id', 'invoice_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('receipts')->nullOnDelete();
        });

        Schema::table('receipt_items', function (Blueprint $table) {
            $table->dropForeign(['receipt_id']);
            $table->renameColumn('receipt_id', 'invoice_id');
        });
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('receipts')->cascadeOnDelete();
        });
        Schema::rename('receipt_items', 'invoice_items');

        // `products` no se recrea (era código muerto); restaurar desde la
        // migración original si fuese necesario.
    }
};
