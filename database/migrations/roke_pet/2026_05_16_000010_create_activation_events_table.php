<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('activation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id')->nullable();
            $table->uuid('pet_id')->nullable();
            $table->string('event_type');
            $table->string('source')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->foreign('owner_id')->references('id')->on('owners')->nullOnDelete();
            $table->foreign('pet_id')->references('id')->on('pets')->nullOnDelete();
            $table->index('owner_id');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('activation_events');
    }
};
