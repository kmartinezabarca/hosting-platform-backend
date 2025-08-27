<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // Agrega columnas solo si no existen
            if (! Schema::hasColumn('payment_methods', 'stripe_payment_method_id')) {
                $table->string('stripe_payment_method_id', 191)->nullable()->after('id');
                $table->index('stripe_payment_method_id', 'pm_stripe_pm_idx');
            }

            if (! Schema::hasColumn('payment_methods', 'stripe_customer_id')) {
                $table->string('stripe_customer_id', 191)->nullable()->after('stripe_payment_method_id');
                $table->index('stripe_customer_id', 'pm_stripe_customer_idx');
            }

            if (! Schema::hasColumn('payment_methods', 'type')) {
                $table->string('type', 50)->nullable()->after('stripe_customer_id');
            }

            if (! Schema::hasColumn('payment_methods', 'name')) {
                $table->string('name', 100)->nullable()->after('type');
            }

            if (! Schema::hasColumn('payment_methods', 'details')) {
                // Si tu MySQL no soporta JSON, cambia a ->text('details')
                $table->json('details')->nullable()->after('name');
            }

            if (! Schema::hasColumn('payment_methods', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('details');
            }

            if (! Schema::hasColumn('payment_methods', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_default');
            }
        });

        // Índice único recomendado para evitar duplicados del mismo PM por usuario
        try {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->unique(['user_id', 'stripe_payment_method_id'], 'pm_user_stripe_pm_unique');
            });
        } catch (\Throwable $e) {
            // Ignorar si ya existía
        }
    }

    public function down(): void
    {
        // Quitar índices/unique si existen
        try {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropUnique('pm_user_stripe_pm_unique');
                $table->dropIndex('pm_stripe_pm_idx');
                $table->dropIndex('pm_stripe_customer_idx');
            });
        } catch (\Throwable $e) {}

        Schema::table('payment_methods', function (Blueprint $table) {
            if (Schema::hasColumn('payment_methods', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('payment_methods', 'is_default')) {
                $table->dropColumn('is_default');
            }
            if (Schema::hasColumn('payment_methods', 'details')) {
                $table->dropColumn('details');
            }
            if (Schema::hasColumn('payment_methods', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('payment_methods', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('payment_methods', 'stripe_customer_id')) {
                $table->dropColumn('stripe_customer_id');
            }
            if (Schema::hasColumn('payment_methods', 'stripe_payment_method_id')) {
                $table->dropColumn('stripe_payment_method_id');
            }
        });
    }
};
