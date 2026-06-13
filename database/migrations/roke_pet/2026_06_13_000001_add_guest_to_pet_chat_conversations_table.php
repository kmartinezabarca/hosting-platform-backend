<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carril "invitado" del chat de soporte: permite iniciar una conversación desde
 * la landing pública SIN sesión. owner_id ya es nullable; aquí añadimos los datos
 * del lead (nombre + correo/teléfono) y un guest_token secreto que autoriza al
 * invitado a leer/escribir SU conversación (en vez de Sanctum).
 *
 * Correr con:
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('pet_chat_conversations', function (Blueprint $table) {
            // Token opaco que el frontend guarda en localStorage y manda en
            // X-Guest-Token. Único e indexado para resolver la conversación rápido.
            $table->string('guest_token', 64)->nullable()->unique()->after('owner_id');
            $table->string('guest_name', 120)->nullable()->after('guest_token');
            $table->string('guest_email', 180)->nullable()->after('guest_name');
            $table->string('guest_phone', 40)->nullable()->after('guest_email');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('pet_chat_conversations', function (Blueprint $table) {
            $table->dropUnique(['guest_token']);
            $table->dropColumn(['guest_token', 'guest_name', 'guest_email', 'guest_phone']);
        });
    }
};
