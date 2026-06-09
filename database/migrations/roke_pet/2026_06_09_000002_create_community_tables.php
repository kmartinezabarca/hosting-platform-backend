<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comunidad ROKE PET (red social de mascotas) — vive en la BD `roke_pet`.
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 *
 *  - pet_posts:         publicaciones (fotos/video corto) de mascotas.
 *  - pet_post_likes:    me gusta (1 por dueño por post).
 *  - pet_post_comments: comentarios.
 *  - pet_post_reports:  reportes de moderación (contenido de usuarios).
 */
return new class extends Migration {
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->uuid('pet_id');                              // toda publicación es de una mascota
            $table->text('caption')->nullable();
            $table->json('media');                               // [{type:'image'|'video', url}]
            $table->integer('likes_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->string('moderation_status')->default('active'); // active|flagged|hidden
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
            $table->foreign('pet_id')->references('id')->on('pets')->cascadeOnDelete();
            $table->index('owner_id');
            $table->index('pet_id');
            $table->index('created_at');
            $table->index('moderation_status');
        });

        Schema::connection('roke_pet')->create('pet_post_likes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
            $table->uuid('owner_id');
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('pet_posts')->cascadeOnDelete();
            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
            $table->unique(['post_id', 'owner_id']);
            $table->index('owner_id');
        });

        Schema::connection('roke_pet')->create('pet_post_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
            $table->uuid('owner_id');
            $table->text('body');
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('pet_posts')->cascadeOnDelete();
            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
            $table->index(['post_id', 'created_at']);
        });

        Schema::connection('roke_pet')->create('pet_post_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
            $table->string('reason');                            // spam|inappropriate|other
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('pet_posts')->cascadeOnDelete();
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_post_reports');
        Schema::connection('roke_pet')->dropIfExists('pet_post_comments');
        Schema::connection('roke_pet')->dropIfExists('pet_post_likes');
        Schema::connection('roke_pet')->dropIfExists('pet_posts');
    }
};
