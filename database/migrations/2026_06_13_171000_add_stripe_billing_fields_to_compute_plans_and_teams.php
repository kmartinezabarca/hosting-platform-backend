<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compute_plan_catalog_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('compute_plan_catalog_entries', 'stripe_product_id')) {
                $table->string('stripe_product_id')->nullable()->after('currency');
            }
        });

        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->after('billing_interval');
            }
            if (! Schema::hasColumn('teams', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id')->nullable()->index()->after('stripe_customer_id');
            }
            if (! Schema::hasColumn('teams', 'stripe_checkout_session_id')) {
                $table->string('stripe_checkout_session_id')->nullable()->after('stripe_subscription_id');
            }
            if (! Schema::hasColumn('teams', 'stripe_price_id')) {
                $table->string('stripe_price_id')->nullable()->after('stripe_checkout_session_id');
            }
            if (! Schema::hasColumn('teams', 'billing_status')) {
                $table->string('billing_status', 30)->nullable()->after('stripe_price_id');
            }
            if (! Schema::hasColumn('teams', 'current_period_ends_at')) {
                $table->timestamp('current_period_ends_at')->nullable()->after('billing_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'stripe_subscription_id')) {
                $table->dropIndex(['stripe_subscription_id']);
            }

            foreach ([
                'current_period_ends_at',
                'billing_status',
                'stripe_price_id',
                'stripe_checkout_session_id',
                'stripe_subscription_id',
                'stripe_customer_id',
            ] as $column) {
                if (Schema::hasColumn('teams', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('compute_plan_catalog_entries', function (Blueprint $table) {
            if (Schema::hasColumn('compute_plan_catalog_entries', 'stripe_product_id')) {
                $table->dropColumn('stripe_product_id');
            }
        });
    }
};
