<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('project_id', 100)->default('roke_pet');
            $table->unsignedBigInteger('platform_user_id')->nullable();
            $table->uuid('pet_id')->nullable();
            $table->foreign('pet_id')->references('id')->on('pets')->onDelete('set null');
            $table->string('owner_id', 36)->nullable()->index();
            $table->string('channel', 50)->default('push');     // push | email | sms
            $table->string('provider', 100)->nullable();         // webpush | mailgun | etc.
            $table->string('notification_type', 100);            // lost_pet_scan | vaccine_reminder | ...
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('pending');   // pending | sent | delivered | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['pet_id', 'created_at']);
            $table->index(['next_retry_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('notification_logs');
    }
};
