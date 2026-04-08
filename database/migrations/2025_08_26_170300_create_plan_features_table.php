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
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('service_plan_id');
            $table->string('feature', 500); // "1 Sitio Web", "10 GB SSD", etc.
            $table->boolean('is_highlighted')->default(false); // Para destacar características importantes
            $table->integer('sort_order')->default(0);
            $table->index(['service_plan_id']);
            $table->index(['sort_order']);
            $table->foreign("service_plan_id")->references("id")->on("service_plans")->onDelete("cascade");
            $table->index(['uuid']);
            $table->timestamps();
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

