<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Estado de la factura
            if (!Schema::hasColumn('invoices', 'status')) {
                $table->enum('status', [
                    'draft','sent','processing','paid','overdue','cancelled','refunded'
                ])->default('sent')->after('id');
            }
            // Fechas comunes
            if (!Schema::hasColumn('invoices', 'due_date')) {
                $table->timestamp('due_date')->nullable()->after('status');
            }
            if (!Schema::hasColumn('invoices', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('due_date');
            }
            // Identificadores
            if (!Schema::hasColumn('invoices', 'invoice_number')) {
                $table->string('invoice_number', 50)->nullable()->unique()->after('paid_at');
            }
            if (!Schema::hasColumn('invoices', 'provider_invoice_id')) {
                $table->string('provider_invoice_id', 191)->nullable()->after('invoice_number');
                $table->index('provider_invoice_id', 'invoices_provider_invoice_id_idx');
            }
            // Archivos generados
            if (!Schema::hasColumn('invoices', 'pdf_path')) {
                $table->string('pdf_path', 255)->nullable()->after('provider_invoice_id');
            }
            if (!Schema::hasColumn('invoices', 'xml_path')) {
                $table->string('xml_path', 255)->nullable()->after('pdf_path');
            }
            // Totales / moneda (por si falta en tu esquema)
            if (!Schema::hasColumn('invoices', 'currency')) {
                $table->string('currency', 3)->default('MXN')->after('xml_path');
            }
            if (!Schema::hasColumn('invoices', 'total')) {
                $table->decimal('total', 10, 2)->default(0)->after('currency');
            }
            if (!Schema::hasColumn('invoices', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('total');
                $table->index('user_id', 'invoices_user_id_idx');
            }
            if (!Schema::hasColumn('invoices', 'service_id')) {
                $table->unsignedBigInteger('service_id')->nullable()->after('user_id');
                $table->index('service_id', 'invoices_service_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach ([
                'xml_path','pdf_path','provider_invoice_id','invoice_number',
                'paid_at','due_date','status','currency','total','user_id','service_id'
            ] as $col) {
                if (Schema::hasColumn('invoices', $col)) $table->dropColumn($col);
            }
        });
    }
};
