<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respuestas a comentarios de la comunidad (1 nivel, estilo Instagram).
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 *
 * parent_id apunta SIEMPRE a un comentario raíz: responder a una respuesta
 * cuelga del mismo hilo (el backend normaliza), nunca hay árboles profundos.
 */
return new class extends Migration {
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('pet_post_comments', function (Blueprint $table) {
            $table->uuid('parent_id')->nullable()->after('post_id');
            $table->integer('replies_count')->default(0)->after('body');

            $table->foreign('parent_id')->references('id')->on('pet_post_comments')->cascadeOnDelete();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('pet_post_comments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn(['parent_id', 'replies_count']);
        });
    }
};
