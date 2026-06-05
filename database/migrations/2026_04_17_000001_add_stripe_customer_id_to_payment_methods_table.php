<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_methods', 'stripe_customer_id')) {
                // El customer ID de Stripe se almacena aquí para facilitar
                // llamadas directas a la API de Stripe sin tener que resolver
                // el usuario primero. El mismo valor vive en users.stripe_customer_id.
                $table->string('stripe_customer_id', 191)
                      ->nullable()
                      ->after('stripe_payment_method_id')
                      ->index('pm_stripe_customer_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (Schema::hasColumn('payment_methods', 'stripe_customer_id')) {
                $table->dropIndex('pm_stripe_customer_idx');
                $table->dropColumn('stripe_customer_id');
            }
        });
    }
};
