<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('sent_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_id');
            // ID de la vacuna o del registro médico que generó el recordatorio
            $table->string('reference_id');
            // 'vaccine' | 'deworming' | 'checkup'
            $table->string('reference_type');
            $table->timestamp('sent_at')->useCurrent();
            // 'email' | 'push' | 'both'
            $table->string('channel')->default('email');

            $table->index(['reference_id', 'reference_type', 'sent_at']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('sent_reminders');
    }
};
