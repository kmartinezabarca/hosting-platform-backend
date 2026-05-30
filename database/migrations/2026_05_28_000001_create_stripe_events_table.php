<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de idempotencia para webhooks de Stripe.
 *
 * Stripe reintenta cada evento durante hasta 3 días. Sin un registro por
 * `event_id` el mismo evento se procesaría varias veces (doble provisioning,
 * doble extensión de periodo, notificaciones duplicadas).
 *
 * El controlador del webhook inserta una fila por `event_id` (único) y omite
 * los eventos que ya fueron procesados con éxito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();   // evt_xxx — clave de idempotencia
            $table->string('type')->index();         // checkout.session.completed, invoice.paid, ...
            $table->enum('status', ['pending', 'processing', 'processed', 'failed'])
                ->default('pending')
                ->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload')->nullable();     // copia del objeto para auditoría/reproceso
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
