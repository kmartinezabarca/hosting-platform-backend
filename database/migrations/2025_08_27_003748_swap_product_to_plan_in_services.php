<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Agregar plan_id y quitar product_id
            $table->foreignId('plan_id')->after('user_id')
                  ->constrained('service_plans')->restrictOnDelete();

            // Quitar FK y columna product_id si existen
            if (Schema::hasColumn('services', 'product_id')) {
                $table->dropForeign(['product_id']);
                $table->dropColumn('product_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()
                  ->constrained('products')->restrictOnDelete();
            $table->dropForeign(['plan_id']);
            $table->dropColumn('plan_id');
        });
    }
};
