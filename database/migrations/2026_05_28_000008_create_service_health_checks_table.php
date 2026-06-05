<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de health checks HTTP para servicios de hosting (Coolify).
 *
 * Coolify NO expone CPU/RAM por su API pública, así que la métrica REAL que sí
 * podemos medir para un sitio es disponibilidad (uptime) y latencia: un GET
 * periódico al dominio del sitio. De aquí salen uptime % y un sparkline de
 * latencia 100% reales (sin datos sintéticos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->boolean('ok')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamps();

            $table->index(['service_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_health_checks');
    }
};
