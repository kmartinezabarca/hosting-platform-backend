<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_server_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedSmallInteger('ping_ms')->nullable()->comment('RTT en ms; null = timeout');
            $table->boolean('is_online')->default(false)->comment('true si el servidor respondió al SLP');
            $table->unsignedSmallInteger('players')->nullable()->comment('Jugadores conectados en ese momento');
            $table->timestamp('sampled_at')->useCurrent()->index();

            $table->index(['service_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_server_pings');
    }
};
