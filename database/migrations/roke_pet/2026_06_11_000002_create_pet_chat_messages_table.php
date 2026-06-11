<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes del chat de soporte (un registro por mensaje, de cualquier emisor).
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');

            // pet_owner | agent | ai | system
            $table->string('sender_type', 16);
            $table->uuid('sender_id')->nullable();          // owner uuid | agent uuid | null (ai/system)
            $table->string('sender_name', 120)->nullable(); // denormalizado para render sin joins

            $table->text('body')->nullable();
            // text | system | quick_reply | attachment
            $table->string('message_type', 16)->default('text');

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // Telemetría de IA (sólo en mensajes con sender_type = ai).
            $table->float('ai_confidence')->nullable();
            $table->json('ai_sources')->nullable();         // [{slug,title}] de la KB usada

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'sender_type']);
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_chat_messages');
    }
};
