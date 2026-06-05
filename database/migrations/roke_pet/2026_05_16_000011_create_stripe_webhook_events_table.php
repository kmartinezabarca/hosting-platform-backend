<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('stripe_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->uuid('owner_id')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->useCurrent();

            $table->foreign('owner_id')->references('id')->on('owners')->nullOnDelete();
            $table->index('stripe_customer_id');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('stripe_webhook_events');
    }
};
