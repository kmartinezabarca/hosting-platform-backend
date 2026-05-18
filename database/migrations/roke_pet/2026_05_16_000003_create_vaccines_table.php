<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('vaccines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pet_id');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->date('date')->nullable();
            $table->date('next_due')->nullable();
            $table->string('applied_by')->nullable();
            $table->string('batch_number')->nullable();
            $table->enum('status', ['applied', 'pending', 'overdue'])->default('pending');
            $table->timestamps();

            $table->foreign('pet_id')->references('id')->on('pets')->cascadeOnDelete();
            $table->index('pet_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('vaccines');
    }
};
