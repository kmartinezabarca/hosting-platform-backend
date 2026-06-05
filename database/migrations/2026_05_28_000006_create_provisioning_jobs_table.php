<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cola persistente de aprovisionamiento con reintentos.
 *
 * Tras un pago exitoso el servicio existe en BD, pero el aprovisionamiento en el
 * proveedor (Pterodactyl / Coolify) puede fallar o quedar pendiente. Esta tabla
 * registra un job por (servicio, proveedor) con reintentos y backoff, de modo
 * que un fallo del proveedor NO deja el servicio a medias: el comando
 * provisioning:process-pending lo reintenta hasta `max_attempts`.
 *
 * unique(service_id, provider) garantiza idempotencia: nunca dos jobs para el
 * mismo servicio+proveedor (evita doble provisioning).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // pterodactyl | coolify
            $table->enum('status', ['pending', 'running', 'succeeded', 'failed'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('available_at')->nullable(); // backoff: no correr antes de esta hora
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'provider']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_jobs');
    }
};
