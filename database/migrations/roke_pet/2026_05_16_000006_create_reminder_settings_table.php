<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('reminder_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->boolean('enabled')->default(true);
            $table->boolean('email_notifications')->default(true);
            $table->json('reminder_days')->nullable(); // default [30,14,7,3,1] en el modelo
            $table->boolean('vaccine_reminders')->default(true);
            $table->boolean('deworming_reminders')->default(true);
            $table->boolean('checkup_reminders')->default(true);
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('reminder_settings');
    }
};
