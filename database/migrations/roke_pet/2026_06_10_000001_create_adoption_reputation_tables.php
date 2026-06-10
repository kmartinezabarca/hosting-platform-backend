<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reputación de adopciones (ROKE PET) — vive en la BD `roke_pet`.
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 *
 * Construye confianza bidireccional sobre adopciones REALES:
 *  - adoption_listings: + adopter vinculado al completar la adopción.
 *  - adoption_followups: seguimiento con fotos (¿cómo está la mascota hoy?).
 *  - adoption_reviews:  calificación rescatista↔adoptante (1 por adopción/persona).
 *  - owners: agregados denormalizados para rankear candidatos sin recalcular.
 */
return new class extends Migration {
    protected $connection = 'roke_pet';

    public function up(): void
    {
        // Ancla de la adopción: quién adoptó y cuándo (se fija al marcar `adopted`).
        Schema::connection('roke_pet')->table('adoption_listings', function (Blueprint $table) {
            $table->uuid('adopted_by_owner_id')->nullable()->after('owner_id');
            $table->timestamp('adopted_at')->nullable()->after('adopted_by_owner_id');
            $table->index('adopted_by_owner_id');
        });

        // Seguimiento con fotos — el adoptante demuestra cómo está la mascota.
        Schema::connection('roke_pet')->create('adoption_followups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('listing_id');
            $table->uuid('adopter_owner_id');                    // quien sube las fotos
            $table->uuid('requested_by_owner_id')->nullable();   // rescatista que lo pidió
            $table->enum('status', ['requested', 'submitted'])->default('requested');
            $table->json('photos')->nullable();                  // 1–3 URLs
            $table->text('note')->nullable();
            $table->timestamp('due_at')->nullable();             // vencimiento sugerido (auto +30d)
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->foreign('listing_id')->references('id')->on('adoption_listings')->cascadeOnDelete();
            $table->index('listing_id');
            $table->index('adopter_owner_id');
            $table->index('status');
        });

        // Calificación bidireccional anclada a una adopción concreta.
        Schema::connection('roke_pet')->create('adoption_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('listing_id');
            $table->uuid('reviewer_owner_id');                   // quien califica
            $table->uuid('reviewee_owner_id');                   // a quién califican
            $table->enum('role', ['adopter', 'rescuer']);        // rol del EVALUADO
            $table->unsignedTinyInteger('rating');               // general 1–5
            $table->unsignedTinyInteger('score_responsibility')->nullable();
            $table->unsignedTinyInteger('score_communication')->nullable();
            $table->unsignedTinyInteger('score_conditions')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->foreign('listing_id')->references('id')->on('adoption_listings')->cascadeOnDelete();
            // 1 reseña por persona por adopción.
            $table->unique(['listing_id', 'reviewer_owner_id']);
            $table->index('reviewee_owner_id');
            $table->index('role');
        });

        // Agregados denormalizados por dueño (lectura barata para ranking/badges).
        Schema::connection('roke_pet')->table('owners', function (Blueprint $table) {
            $table->decimal('adopter_rating_avg', 3, 2)->nullable()->after('public_address_visible');
            $table->integer('adopter_rating_count')->default(0)->after('adopter_rating_avg');
            $table->integer('adopter_adoptions_count')->default(0)->after('adopter_rating_count');
            $table->decimal('adopter_followups_ratio', 4, 3)->nullable()->after('adopter_adoptions_count');
            $table->decimal('rescuer_rating_avg', 3, 2)->nullable()->after('adopter_followups_ratio');
            $table->integer('rescuer_rating_count')->default(0)->after('rescuer_rating_avg');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('adoption_reviews');
        Schema::connection('roke_pet')->dropIfExists('adoption_followups');

        Schema::connection('roke_pet')->table('adoption_listings', function (Blueprint $table) {
            $table->dropIndex(['adopted_by_owner_id']);
            $table->dropColumn(['adopted_by_owner_id', 'adopted_at']);
        });

        Schema::connection('roke_pet')->table('owners', function (Blueprint $table) {
            $table->dropColumn([
                'adopter_rating_avg', 'adopter_rating_count', 'adopter_adoptions_count',
                'adopter_followups_ratio', 'rescuer_rating_avg', 'rescuer_rating_count',
            ]);
        });
    }
};
