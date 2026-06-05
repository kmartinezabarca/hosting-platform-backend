<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabla de certificados SSL por servicio/dominio.
     * Permite rastrear el estado actual, historial de emisiones y alertas de vencimiento.
     */
    public function up(): void
    {
        Schema::create('ssl_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Servicio de hosting al que pertenece el cert (nullable para certs de dominio externo)
            $table->foreignId('service_id')
                ->nullable()
                ->constrained()
                ->onDelete('cascade');

            // Dominio exacto cubierto por el certificado (puede ser subdominio)
            $table->string('domain', 255);

            // Emisor (Let's Encrypt, Cloudflare, ZeroSSL, etc.)
            $table->string('issuer', 255)->nullable();

            // Tipo de cert: lets_encrypt | cloudflare | custom | self_signed
            $table->string('type', 30)->default('lets_encrypt');

            // Estado del ciclo de vida del cert
            $table->enum('status', [
                'pending',       // solicitado, aún no emitido
                'active',        // emitido y válido
                'expiring_soon', // activo pero vence en ≤ 30 días
                'expired',       // venció
                'failed',        // falló la emisión/renovación
                'revoked',       // revocado manualmente
            ])->default('pending');

            // Fechas de validez según el certificado real
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            // Opciones de gestión
            $table->boolean('auto_renew')->default(true);
            $table->boolean('force_https')->default(false);
            $table->boolean('is_wildcard')->default(false);    // *.dominio.com

            // Metadatos opcionales: fingerprint SHA256, SANs, número de serie, etc.
            $table->json('meta')->nullable();

            // Cuándo se verificó por última vez el estado del cert (desde fetchCertInfo)
            $table->timestamp('last_checked_at')->nullable();

            // Cuándo se notificó al cliente del próximo vencimiento (evitar spam)
            $table->timestamp('expiry_notified_at')->nullable();

            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────────
            $table->index(['service_id', 'status']);
            $table->index(['domain']);
            $table->index(['status', 'valid_until']);   // para el job de alertas
            $table->index(['auto_renew', 'valid_until']); // para renovación automática
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
