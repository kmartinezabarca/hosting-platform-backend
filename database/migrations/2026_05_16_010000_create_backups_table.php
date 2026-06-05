<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            // platform | game_server | hosting | client_files
            $table->string('type', 32)->index();
            // pending | running | completed | failed
            $table->string('status', 16)->default('pending')->index();

            // Cliente / servicio asociado (nullable para backups de plataforma)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('schedule_id')->nullable();

            // Ubicación física en el NAS
            $table->string('disk', 32)->default('nas');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->json('meta')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
