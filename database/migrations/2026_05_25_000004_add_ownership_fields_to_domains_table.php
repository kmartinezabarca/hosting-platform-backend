<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega campos para verificación de ownership de dominio via TXT record.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Token aleatorio para el TXT record de verificación
            $table->string('ownership_token', 64)->nullable()->after('whois_privacy');

            // Si el dominio fue verificado exitosamente
            $table->boolean('ownership_verified')->default(false)->after('ownership_token');

            // Cuándo fue verificado
            $table->timestamp('ownership_verified_at')->nullable()->after('ownership_verified');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['ownership_token', 'ownership_verified', 'ownership_verified_at']);
        });
    }
};
