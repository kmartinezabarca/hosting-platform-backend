<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('vet_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pet_id')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->string('token')->unique();
            $table->timestamp('expires_at');
            $table->boolean('allow_add_records')->default(false);
            $table->integer('view_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('pet_id')->references('id')->on('pets')->cascadeOnDelete();
            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
            $table->index('owner_id');
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('vet_links');
    }
};
