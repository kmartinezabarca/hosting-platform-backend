<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Páginas generadas con IA (SiteBuilder, mes 3). Guarda el resultado para que
 * el cliente lo vea/previsualice y luego lo despliegue (fases 2-3). `status`
 * nace listo para encolar a futuro (pending|ready|failed); hoy, al ser síncrono,
 * solo se persisten las exitosas (ready).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('site_name')->nullable();
            $table->string('locale', 10)->default('es');
            $table->json('spec')->nullable();              // palette, sections
            $table->string('status', 20)->default('ready'); // pending|ready|failed
            $table->string('title');
            $table->longText('html');
            $table->string('provider', 30);                // ollama|claude|…
            $table->string('model');
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_pages');
    }
};
