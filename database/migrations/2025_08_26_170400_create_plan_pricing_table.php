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
        Schema::create('plan_pricing', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('service_plan_id');
            $table->unsignedBigInteger('billing_cycle_id');
            $table->decimal('price', 10, 2); // Precio específico para este plan y ciclo
            $table->unique(['service_plan_id', 'billing_cycle_id']);
            $table->index(['service_plan_id']);
            $table->index(['billing_cycle_id']);
            $table->foreign("service_plan_id")->references("id")->on("service_plans")->onDelete("cascade");
            $table->foreign("billing_cycle_id")->references("id")->on("billing_cycles")->onDelete("cascade");
            $table->index(['uuid']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_pricing');
    }
};

