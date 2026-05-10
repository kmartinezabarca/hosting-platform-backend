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
        Schema::create('postal_codes', function (Blueprint $table) {
            $table->id();
            $table->string('postal_code', 10)->index();
            $table->string('state');
            $table->string('city');
            $table->string('township')->nullable(); // Municipio/Delegación
            $table->string('country', 2)->default('MX');
            $table->timestamps();
            
            $table->unique(['postal_code', 'state', 'city', 'township', 'country'], 'postal_codes_unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postal_codes');
    }
};
