<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Stripe tracking columns:
 *   service_plans.stripe_product_id  — the Stripe Product that wraps the plan
 *   plan_pricing.stripe_price_id     — one Stripe Price per billing cycle
 *
 * service_plans.stripe_price_id already exists (added in an earlier migration)
 * and is kept as the "default / monthly" price for backwards compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── service_plans: add stripe_product_id ────────────────────────────
        Schema::table('service_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('service_plans', 'stripe_product_id')) {
                $table->string('stripe_product_id')->nullable()->after('stripe_price_id')
                      ->comment('Stripe Product ID — created once per service plan');
            }
        });

        // ── plan_pricing: add stripe_price_id ───────────────────────────────
        Schema::table('plan_pricing', function (Blueprint $table) {
            if (! Schema::hasColumn('plan_pricing', 'stripe_price_id')) {
                $table->string('stripe_price_id')->nullable()->after('price')
                      ->comment('Stripe Price ID for this plan + billing cycle combination');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            if (Schema::hasColumn('service_plans', 'stripe_product_id')) {
                $table->dropColumn('stripe_product_id');
            }
        });

        Schema::table('plan_pricing', function (Blueprint $table) {
            if (Schema::hasColumn('plan_pricing', 'stripe_price_id')) {
                $table->dropColumn('stripe_price_id');
            }
        });
    }
};
