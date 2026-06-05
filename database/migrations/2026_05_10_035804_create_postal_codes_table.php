<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_codes', function (Blueprint $table) {
            $table->id();

            $table->string('postal_code', 10)->index();

            $table->string('state', 100);

            $table->string('city', 100);

            $table->string('township', 100)->nullable();

            $table->string('country', 2)->default('MX');

            $table->timestamps();

            $table->index(['postal_code', 'country']);
            $table->unique([
                'postal_code',
                'state',
                'city',
                'township',
                'country',
            ], 'postal_codes_unique_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postal_codes');
    }
};
