<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('type', ['main', 'additional']);
            $table->string('icon_name');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');
            $table->json('features')->nullable();
            $table->string('color')->nullable();
            $table->string('bg_color')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_services');
    }
};
