<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('medical_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pet_id');
            $table->date('date');
            $table->date('follow_up_date')->nullable();
            $table->enum('type', ['checkup', 'surgery', 'treatment', 'deworming', 'illness']);
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->string('vet')->nullable();
            $table->string('clinic')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('pet_id')->references('id')->on('pets')->cascadeOnDelete();
            $table->index('pet_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('medical_records');
    }
};
