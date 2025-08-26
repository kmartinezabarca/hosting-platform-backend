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
            $table->foreignId('service_plan_id')->constrained()->onDelete('cascade');
            $table->foreignId('billing_cycle_id')->constrained()->onDelete('cascade');
            $table->decimal('price', 10, 2); // Precio específico para este plan y ciclo
            $table->timestamps();
            
            $table->unique(['service_plan_id', 'billing_cycle_id']);
            $table->index(['service_plan_id']);
            $table->index(['billing_cycle_id']);
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

