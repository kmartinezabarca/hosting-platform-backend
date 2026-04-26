<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Nombre amigable para identificar el perfil (ej. "Mi empresa", "Uso personal")
            $table->string('alias', 100)->nullable();

            // Datos fiscales SAT
            $table->string('rfc', 13);                    // RFC con homoclave
            $table->string('razon_social');               // Nombre o razón social exacta (SAT)
            $table->string('codigo_postal', 5);           // CP del domicilio fiscal
            $table->string('regimen_fiscal', 10);         // Código del régimen (ej. "612")
            $table->string('uso_cfdi', 10)->default('G03'); // Uso CFDI predeterminado para este perfil

            // Constancia de Situación Fiscal (archivo PDF subido por el cliente)
            $table->string('constancia_path')->nullable();

            // Un usuario puede tener múltiples perfiles; solo uno puede ser default
            $table->boolean('is_default')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_fiscal_profiles');
    }
};
