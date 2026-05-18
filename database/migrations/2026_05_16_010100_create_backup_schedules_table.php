<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            // platform | game_server | hosting | client_files
            $table->string('type', 32)->index();

            // Alcance: all | user | service
            $table->string('scope', 16)->default('all');
            $table->unsignedBigInteger('scope_id')->nullable();

            // daily | weekly | monthly | cron
            $table->string('frequency', 16)->default('daily');
            $table->string('cron_expression')->nullable();
            // Hora del día para daily/weekly/monthly (formato HH:MM)
            $table->string('run_at_time', 5)->default('03:00');
            // 0-6 para weekly, 1-31 para monthly
            $table->unsignedTinyInteger('run_at_day')->nullable();

            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->boolean('is_enabled')->default(true)->index();

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
    }
};
