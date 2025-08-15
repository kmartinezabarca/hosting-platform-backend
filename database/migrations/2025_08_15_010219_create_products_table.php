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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('service_type', ['web_hosting', 'vps', 'game_server', 'domain']);
            $table->string('game_type', 50)->nullable(); // minecraft, rust, etc.
            $table->json('specifications'); // RAM, CPU, disk, etc.
            $table->json('pricing'); // monthly, quarterly, yearly prices
            $table->decimal('setup_fee', 10, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index(['service_type']);
            $table->index(['is_active']);
            $table->index(['sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
