<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('weight_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pet_id');
            $table->decimal('weight', 6, 2);
            $table->date('recorded_at')->default(now());
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('pet_id')->references('id')->on('pets')->cascadeOnDelete();
            $table->index('pet_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('weight_history');
    }
};
