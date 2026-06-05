<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('slug')->unique();
            $table->string('name');
            $table->enum('species', ['cat', 'dog', 'rabbit', 'other'])->default('cat');
            $table->string('breed')->nullable();
            $table->string('breed_en')->nullable();
            $table->enum('gender', ['female', 'male'])->nullable();
            $table->date('birth_date')->nullable();
            $table->string('color')->nullable();
            $table->string('color_en')->nullable();
            $table->string('eye_color')->nullable();
            $table->string('eye_color_en')->nullable();
            $table->decimal('weight', 4, 2)->nullable();
            $table->boolean('sterilized')->default(false);
            $table->string('microchip_id')->nullable();
            $table->string('nfc_id')->nullable();
            $table->text('photo_url')->nullable();
            $table->text('story')->nullable();
            $table->text('story_en')->nullable();

            // text[] arrays → json (defaults manejados en el modelo)
            $table->json('traits')->nullable();
            $table->json('traits_en')->nullable();
            $table->json('allergies')->nullable();
            $table->json('allergies_en')->nullable();
            $table->json('allergy_profiles')->nullable();
            $table->json('conditions')->nullable();
            $table->json('conditions_en')->nullable();
            $table->json('active_treatments')->nullable();
            $table->json('active_treatments_en')->nullable();
            $table->json('current_medications')->nullable();
            $table->json('current_medications_en')->nullable();

            $table->text('special_care')->nullable();
            $table->text('special_care_en')->nullable();
            $table->string('primary_vet_name')->nullable();
            $table->string('primary_vet_phone')->nullable();
            $table->string('primary_vet_clinic')->nullable();

            $table->integer('scanned_count')->default(0);
            $table->json('last_scan_location')->nullable();
            $table->boolean('public_profile_enabled')->default(true);

            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pets');
    }
};
