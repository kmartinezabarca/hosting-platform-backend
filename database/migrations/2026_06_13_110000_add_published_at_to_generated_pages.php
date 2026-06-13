<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publicación de páginas generadas (SiteBuilder fase 2, Opción A): el backend
 * sirve el HTML guardado en una URL pública. `published_at` marca si la página
 * está publicada (visible) o no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_pages', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('generated_pages', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
