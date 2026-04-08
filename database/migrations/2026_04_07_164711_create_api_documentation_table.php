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
        Schema::create('api_documentation', function (Blueprint $table) {
            $table->id();
            $table->uuid("uuid")->unique();
            $table->string("title");
            $table->string("slug")->unique();
            $table->longText("content");
            $table->string("category")->nullable();
            $table->boolean("is_published")->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_documentation');
    }
};
