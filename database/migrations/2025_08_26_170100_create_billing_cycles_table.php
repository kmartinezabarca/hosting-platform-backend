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
        Schema::create('billing_cycles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 50)->unique(); // monthly, quarterly, annually
            $table->string('name', 100); // Mensual, Trimestral, Anual
            $table->integer('months')->default(1); // 1, 3, 12
            $table->decimal('discount_percentage', 5, 2)->default(0.00); // 0.00, 10.00, 20.00
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index(['is_active']);
            $table->index(['sort_order']);
            $table->index(['slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_cycles');
    }
};

