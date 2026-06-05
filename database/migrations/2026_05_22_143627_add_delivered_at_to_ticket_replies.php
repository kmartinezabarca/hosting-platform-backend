<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade `delivered_at` a ticket_replies (read_at ya existe en otra migración).
 * Con esto podemos diferenciar:
 *   - ✓ delivered_at: el otro lado recibió el mensaje por WS y persistió el receipt.
 *   - ✓✓ read_at:     el otro lado lo vio en pantalla (chat abierto + mensaje visible).
 *
 * La actualización del estado entre clientes se hace por presence channel
 * + whispers de Echo (zero costo backend), pero persistimos en DB para que
 * sobreviva a reconexiones / refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('read_at');
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropIndex(['delivered_at']);
            $table->dropColumn('delivered_at');
        });
    }
};
