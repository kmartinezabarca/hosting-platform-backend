<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderación de reseñas de adopción: estado + reportes de usuarios.
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 *
 * Mismo patrón que adoption_reports / pet_post_reports: 3+ reportes abiertos
 * marcan la reseña como 'flagged' para revisión; un admin decide ocultarla.
 */
return new class extends Migration {
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('adoption_reviews', function (Blueprint $table) {
            $table->enum('moderation_status', ['active', 'flagged', 'hidden'])
                ->default('active')->after('comment');
            $table->index('moderation_status');
        });

        Schema::connection('roke_pet')->create('adoption_review_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('review_id');
            $table->uuid('reporter_owner_id')->nullable();       // si tiene cuenta
            $table->string('reason');                            // spam|inappropriate|false|other
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();

            $table->foreign('review_id')->references('id')->on('adoption_reviews')->cascadeOnDelete();
            $table->index('review_id');
            $table->index('resolved');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('adoption_review_reports');

        Schema::connection('roke_pet')->table('adoption_reviews', function (Blueprint $table) {
            $table->dropIndex(['moderation_status']);
            $table->dropColumn('moderation_status');
        });
    }
};
