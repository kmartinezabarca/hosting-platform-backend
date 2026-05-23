<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_plan_id')->constrained('service_plans')->cascadeOnDelete();
            $table->foreignId('billing_cycle_id')->constrained('billing_cycles')->cascadeOnDelete();
            $table->json('selected_add_on_ids')->nullable();
            $table->json('request_payload');
            $table->json('pricing_snapshot');
            $table->string('quote_hash', 64);
            $table->string('currency', 3)->default('MXN');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->boolean('is_free')->default(false);
            $table->boolean('is_trial')->default(false);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
            $table->index(['service_plan_id', 'billing_cycle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_quotes');
    }
};
