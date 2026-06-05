<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('owner_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id')->unique();
            $table->string('plan_code')->default('starter');
            $table->enum('status', ['trialing', 'active', 'past_due', 'canceled', 'incomplete'])->default('trialing');
            $table->string('provider')->default('stripe_payment_link');
            $table->text('checkout_url')->nullable();
            $table->string('billing_email')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->text('support_notes')->nullable();

            // Stripe IDs
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->string('last_invoice_id')->nullable();
            $table->timestamp('canceled_at')->nullable();

            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('owner_subscriptions');
    }
};
