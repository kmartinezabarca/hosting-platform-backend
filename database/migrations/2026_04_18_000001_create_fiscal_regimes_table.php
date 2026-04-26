<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_regimes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 10)->unique();          // Código SAT (ej. "601")
            $table->string('description');                  // Descripción oficial
            $table->boolean('applies_to_fisica')->default(false);  // Aplica a persona física
            $table->boolean('applies_to_moral')->default(false);   // Aplica a persona moral
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_regimes');
    }
};
