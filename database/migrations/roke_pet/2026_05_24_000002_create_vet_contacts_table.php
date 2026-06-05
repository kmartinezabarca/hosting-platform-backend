<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('vet_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('name');
            $table->string('clinic')->nullable();
            $table->string('phone')->nullable();
            $table->string('vet_license')->nullable();  // cédula profesional
            $table->string('specialty')->nullable();     // especialidad (futuro)
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('vet_contacts');
    }
};
