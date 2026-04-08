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
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_plan_id')->constrained()->onDelete('cascade');
            $table->string('feature', 500); // "1 Sitio Web", "10 GB SSD", etc.
            $table->boolean('is_highlighted')->default(false); // Para destacar características importantes
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index(['service_plan_id']);
            $table->index(['sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};

