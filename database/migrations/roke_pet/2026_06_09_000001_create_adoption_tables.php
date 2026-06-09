<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistema de adopción (ROKE PET) — vive en la BD `roke_pet`.
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 *
 *  - adoption_listings: animales rescatados publicados para adopción.
 *  - adoption_requests: solicitudes de interés (relay, sin exponer al publicador).
 *  - adoption_reports:  reportes de moderación (contenido de usuarios).
 */
return new class extends Migration {
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('adoption_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('slug')->unique();

            $table->string('name');
            $table->enum('species', ['cat', 'dog', 'rabbit', 'other'])->default('cat');
            $table->string('breed')->nullable();
            $table->enum('gender', ['female', 'male'])->nullable();
            $table->string('age_label')->nullable();              // "2 meses", "adulto"…
            $table->enum('size', ['small', 'medium', 'large'])->nullable();
            $table->string('color')->nullable();
            $table->text('description')->nullable();              // historia / sobre el animal

            $table->json('photos')->nullable();                  // array de URLs
            $table->text('photo_url')->nullable();               // foto principal

            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->boolean('sterilized')->default(false);
            $table->boolean('vaccinated')->default(false);
            $table->boolean('dewormed')->default(false);
            $table->boolean('good_with_kids')->nullable();
            $table->boolean('good_with_pets')->nullable();
            $table->boolean('special_needs')->default(false);
            $table->text('requirements')->nullable();            // requisitos de adopción

            $table->enum('status', ['available', 'reserved', 'adopted', 'paused'])->default('available');
            $table->boolean('is_published')->default(true);
            $table->enum('moderation_status', ['active', 'flagged', 'hidden'])->default('active');

            $table->integer('views_count')->default(0);
            $table->integer('requests_count')->default(0);
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
            $table->index('owner_id');
            $table->index('species');
            $table->index('status');
            $table->index('city');
            $table->index('moderation_status');
        });

        Schema::connection('roke_pet')->create('adoption_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('listing_id');
            $table->uuid('requester_owner_id')->nullable();      // si el solicitante tiene cuenta
            $table->string('requester_name');
            $table->string('requester_contact');                 // email o teléfono para contactarle
            $table->text('message')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('listing_id')->references('id')->on('adoption_listings')->cascadeOnDelete();
            $table->index('listing_id');
            $table->index('status');
        });

        Schema::connection('roke_pet')->create('adoption_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('listing_id');
            $table->string('reason');                            // spam|inappropriate|scam|other
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();

            $table->foreign('listing_id')->references('id')->on('adoption_listings')->cascadeOnDelete();
            $table->index('listing_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('adoption_reports');
        Schema::connection('roke_pet')->dropIfExists('adoption_requests');
        Schema::connection('roke_pet')->dropIfExists('adoption_listings');
    }
};
