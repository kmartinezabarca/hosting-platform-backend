<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte automatizado por WhatsApp orquestado con n8n.
 *
 * whatsapp_conversations: un hilo por número de teléfono. Puede vincularse a un
 * usuario (si el teléfono coincide) y escalar a un ticket cuando pasa a humano.
 * whatsapp_messages: cada mensaje entrante/saliente (contacto, bot o agente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('wa_phone', 32)->unique();      // E.164 sin '+', como lo manda Meta
            $table->string('contact_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            // bot   = lo atiende el flujo automático
            // human = escalado a un agente
            // closed
            $table->enum('status', ['bot', 'human', 'closed'])->default('bot');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_message_at');
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            // contact = lo escribió el cliente; bot = respuesta automática; agent = humano
            $table->enum('sender', ['contact', 'bot', 'agent'])->default('contact');
            $table->text('body')->nullable();
            $table->string('wa_message_id')->nullable()->index(); // id de Meta para idempotencia
            $table->json('meta')->nullable();                      // payload extra (media, template, etc.)
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
    }
};
