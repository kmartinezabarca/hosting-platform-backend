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
        Schema::create('service_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->string('slug', 100)->unique(); // hosting-starter, hosting-pro, etc.
            $table->string('name', 200); // Hosting Starter, Hosting Pro, etc.
            $table->text('description')->nullable();
            $table->decimal('base_price', 10, 2); // Precio base mensual
            $table->decimal('setup_fee', 10, 2)->default(0.00);
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('specifications')->nullable(); // storage, bandwidth, domains, email, etc.
            $table->timestamps();
            
            $table->index(['category_id']);
            $table->index(['is_active']);
            $table->index(['is_popular']);
            $table->index(['sort_order']);
            $table->index(['slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_plans');
    }
};

