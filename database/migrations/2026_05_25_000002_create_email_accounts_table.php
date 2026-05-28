<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabla de cuentas de correo empresarial.
     * Registra localmente los buzones creados en Mailcow para:
     * - Validar límites por plan antes de llamar a la API
     * - Mostrar historial al cliente aunque Mailcow falle
     * - Contar buzones activos por servicio
     */
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Servicio de hosting dueño del buzón
            $table->foreignId('service_id')
                ->constrained()
                ->onDelete('cascade');

            // Usuario dueño (desnormalizado para consultas de admin)
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            // Partes de la dirección
            $table->string('local_part', 100);     // "nombre"  en nombre@dominio.com
            $table->string('domain', 255);          // "dominio.com"

            // Cuota asignada en Mailcow (MB)
            $table->unsignedInteger('quota_mb')->default(500);

            // Estado local del buzón
            $table->enum('status', [
                'active',
                'suspended',
                'deleting',    // marcado para borrar (job pendiente)
                'deleted',     // eliminado en Mailcow, registro archivado
            ])->default('active');

            // ID externo en Mailcow (generalmente es la dirección completa)
            $table->string('mailcow_id', 356)->nullable();

            // Última vez que se sincronizó el estado desde Mailcow
            $table->timestamp('last_sync_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Índices ───────────────────────────────────────────────────────
            // Índice compuesto para búsquedas frecuentes por dirección.
            // La unicidad real (no duplicar buzones activos) se gestiona a nivel
            // de aplicación + Mailcow (que rechaza direcciones ya existentes).
            $table->index(['local_part', 'domain'], 'email_accounts_address_idx');
            $table->index(['service_id', 'status']);
            $table->index(['user_id']);
            $table->index(['domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
