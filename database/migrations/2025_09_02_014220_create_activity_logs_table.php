<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 120);           // p.ej. "Service deployed"
            $table->string('service')->nullable();   // p.ej. "Web Hosting Pro" o "roketech.com"
            $table->string('type', 30)->index();     // deployment|payment|backup|domain|...
            $table->json('meta')->nullable();        // datos extra opcionales
            $table->timestamp('occurred_at')->index()->nullable(); // momento real del evento
            $table->index(['user_id', 'occurred_at']);
            $table->foreign("user_id")->references("id")->on("users")->cascadeOnDelete();
            $table->index(['uuid']);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('activity_logs');
    }
};
