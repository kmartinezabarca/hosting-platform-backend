<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('service_id');
            $table->string('stripe_subscription_id')->unique();
            $table->string('stripe_customer_id');
            $table->string('stripe_price_id');
            $table->string('name'); // Subscription name/description
            $table->enum('status', ['active', 'canceled', 'incomplete', 'incomplete_expired', 'past_due', 'trialing', 'unpaid'])->default('incomplete');
            $table->decimal('amount', 10, 2); // Subscription amount
            $table->string('currency', 3)->default('USD');
            $table->enum('billing_cycle', ['monthly', 'yearly', 'weekly', 'daily'])->default('monthly');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_start')->nullable();
            $table->timestamp('trial_end')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable(); // Additional data
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index('stripe_subscription_id');
            $table->index('stripe_customer_id');
            $table->foreign("user_id")->references("id")->on("users")->onDelete("cascade");
            $table->foreign("service_id")->references("id")->on("services")->onDelete("set null");
            $table->index(['uuid']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
