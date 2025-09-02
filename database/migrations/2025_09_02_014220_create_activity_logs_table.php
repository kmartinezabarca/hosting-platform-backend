<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('action', 120);           // p.ej. "Service deployed"
            $table->string('service')->nullable();   // p.ej. "Web Hosting Pro" o "roketech.com"
            $table->string('type', 30)->index();     // deployment|payment|backup|domain|...
            $table->json('meta')->nullable();        // datos extra opcionales

            $table->timestamp('occurred_at')->index()->nullable(); // momento real del evento
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('activity_logs');
    }
};
