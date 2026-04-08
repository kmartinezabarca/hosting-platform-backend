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
        Schema::create('add_on_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_plan_id');
            $table->unsignedBigInteger('add_on_id');
            $table->boolean('is_default')->default(false); // si el plan lo incluye por defecto
            $table->timestamps();
            $table->unique(['service_plan_id', 'add_on_id']);
            $table->foreign('service_plan_id')->references('id')->on('service_plans')->cascadeOnDelete();
            $table->foreign('add_on_id')->references('id')->on('add_ons')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('add_on_plan');
    }
};
