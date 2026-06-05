<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega seguimiento real de lectura a las respuestas de tickets/chat.
 *
 * `read_at` representa el momento en que el DESTINATARIO de la respuesta
 * la vio. Como cada respuesta tiene una sola dirección (del cliente → la
 * lee el staff, o del staff → la lee el cliente), una sola columna basta
 * para un chat de soporte 1 a 1.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            if (!Schema::hasColumn('ticket_replies', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('attachments');
                $table->index(['ticket_id', 'read_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_replies', 'read_at')) {
                $table->dropIndex(['ticket_id', 'read_at']);
                $table->dropColumn('read_at');
            }
        });
    }
};
