<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // Agrega solo si no existe
            if (! Schema::hasColumn('payment_methods', 'stripe_payment_method_id')) {
                $table->string('stripe_payment_method_id', 191)->nullable()->after('id');
            }
            if (! Schema::hasColumn('payment_methods', 'stripe_customer_id')) {
                $table->string('stripe_customer_id', 191)->nullable()->after('stripe_payment_method_id');
            }
            if (! Schema::hasColumn('payment_methods', 'type')) {
                $table->string('type', 50)->nullable()->after('stripe_customer_id');
            }
            if (! Schema::hasColumn('payment_methods', 'name')) {
                $table->string('name', 100)->nullable()->after('type');
            }
            if (! Schema::hasColumn('payment_methods', 'details')) {
                // JSON si tu MySQL lo soporta; si no, cámbialo a text()
                $table->json('details')->nullable()->after('name');
            }
            if (! Schema::hasColumn('payment_methods', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('details');
            }
            if (! Schema::hasColumn('payment_methods', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_default');
            }
        });

        // Índice único para evitar duplicados por usuario+PM (opcional pero recomendado)
        try {
            Schema::table('payment_methods', function (Blueprint $table) {
                // Si el índice ya existe, esto lanzará excepción y será ignorada
                $table->unique(['user_id', 'stripe_payment_method_id'], 'pm_user_stripe_pm_unique');
            });
        } catch (\Throwable $e) {
            // Ignorar si ya existe
        }
    }

    public function down(): void
    {
        // Quita índice si existe
        try {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropUnique('pm_user_stripe_pm_unique');
            });
        } catch (\Throwable $e) {}

        Schema::table('payment_methods', function (Blueprint $table) {
            if (Schema::hasColumn('payment_methods', 'stripe_payment_method_id')) {
                $table->dropColumn('stripe_payment_method_id');
            }
            if (Schema::hasColumn('payment_methods', 'stripe_customer_id')) {
                $table->dropColumn('stripe_customer_id');
            }
            if (Schema::hasColumn('payment_methods', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('payment_methods', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('payment_methods', 'details')) {
                $table->dropColumn('details');
            }
            if (Schema::hasColumn('payment_methods', 'is_default')) {
                $table->dropColumn('is_default');
            }
            if (Schema::hasColumn('payment_methods', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
