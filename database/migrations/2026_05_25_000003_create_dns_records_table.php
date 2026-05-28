<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla relacional de registros DNS por dominio.
     *
     * Reemplaza el campo JSON dns_records en la tabla domains.
     * Permite queries eficientes, historial de cambios,
     * sincronización con Cloudflare y auditoría por registro.
     */
    public function up(): void
    {
        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Dominio al que pertenece el registro
            $table->foreignId('domain_id')
                ->constrained()
                ->onDelete('cascade');

            // Tipo de registro DNS
            $table->enum('type', ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'])
                ->default('A');

            // Nombre del registro (ej: "@", "www", "mail", "_dmarc")
            $table->string('name', 255);

            // Contenido/valor del registro (IP, hostname, texto)
            $table->text('content');

            // TTL en segundos (1 = automático en Cloudflare)
            $table->unsignedInteger('ttl')->default(3600);

            // Solo para MX/SRV: prioridad
            $table->unsignedSmallInteger('priority')->nullable();

            // Proxiado por Cloudflare (solo A, AAAA, CNAME)
            $table->boolean('proxied')->default(false);

            // ID del registro en Cloudflare para updates/deletes
            $table->string('cloudflare_id', 100)->nullable()->unique();

            // Estado de sincronización con el proveedor externo
            $table->enum('sync_status', [
                'synced',    // en sync con Cloudflare
                'pending',   // creado localmente, aún no en Cloudflare
                'dirty',     // modificado localmente, pendiente de push
                'deleted',   // marcado para borrar en Cloudflare
            ])->default('pending');

            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Índices ───────────────────────────────────────────────────────
            $table->index(['domain_id', 'type']);
            $table->index(['domain_id', 'sync_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
