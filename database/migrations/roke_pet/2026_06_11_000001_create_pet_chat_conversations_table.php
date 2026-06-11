<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversaciones del chat de soporte unificado.
 *
 * Vive en la BD `roke_pet` (aislada del hosting). El diseño es "brand-aware"
 * desde el día uno para poder, en el futuro, dar servicio también a ROKE
 * Industries mediante un adaptador — sin romper el chat de tickets actual.
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_chat_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Marca / canal / origen — preparados para multi-producto.
            $table->string('brand', 32)->default('roke_pet');           // roke_pet | roke_industries
            $table->string('channel', 32)->default('pet_app');          // web | portal | admin | pet_app | mobile | whatsapp_future
            $table->string('source', 32)->default('pet_app');           // customer_portal | public_site | pet_app | admin_created

            // Identidad del cliente (dueño de mascota). Guardamos el uuid del Owner
            // igual que el resto del dominio Pet (ver pet_inbox_notifications).
            $table->uuid('owner_id')->nullable();
            $table->uuid('assigned_agent_id')->nullable();              // admin/agente que tomó la conversación

            // Estado del ciclo de soporte.
            // open | ai_active | waiting_customer | waiting_agent | human_active | resolved | closed
            $table->string('status', 24)->default('ai_active');
            $table->string('priority', 16)->default('normal');          // low | normal | high | urgent

            $table->string('subject', 180)->nullable();

            // Estado de la IA.
            $table->boolean('ai_enabled')->default(true);
            $table->string('ai_status', 16)->default('enabled');        // enabled | disabled | escalated | failed

            // Contadores de no leídos (badges) — se mantienen al crear mensajes.
            $table->unsignedInteger('unread_for_owner')->default(0);
            $table->unsignedInteger('unread_for_agent')->default(0);

            // Marcas de tiempo del ciclo.
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalation_reason', 64)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['brand', 'status']);
            $table->index(['owner_id', 'last_message_at']);
            $table->index(['assigned_agent_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_chat_conversations');
    }
};
