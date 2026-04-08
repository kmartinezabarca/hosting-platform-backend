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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 50)->unique(); // hosting, gameserver, vps, database
            $table->string('name', 100); // Web Hosting, Servidores de Juegos, etc.
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable(); // Nombre del icono para el frontend
            $table->string('color', 50)->nullable(); // Clase CSS para el color del texto
            $table->string('bg_color', 50)->nullable(); // Clase CSS para el color de fondo
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
        Schema::dropIfExists('categories');
    }
};

