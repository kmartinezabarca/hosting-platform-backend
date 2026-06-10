<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Estados para el manejo de disputas (chargebacks) de Stripe:
 *   - receipts.status     += 'disputed' (+ 'processing', que el modelo ya
 *     declara como STATUS_PROCESS y PaymentService consulta, pero faltaba
 *     en el ENUM).
 *   - transactions.status += 'disputed'.
 * Aditivo y seguro.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE receipts MODIFY COLUMN status ENUM('draft','sent','processing','paid','overdue','cancelled','refunded','disputed') NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('pending','processing','completed','failed','cancelled','refunded','disputed') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE receipts SET status = 'paid' WHERE status IN ('disputed','processing')");
        DB::statement("UPDATE transactions SET status = 'completed' WHERE status = 'disputed'");
        DB::statement("ALTER TABLE receipts MODIFY COLUMN status ENUM('draft','sent','paid','overdue','cancelled','refunded') NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('pending','processing','completed','failed','cancelled','refunded') NOT NULL");
    }
};
