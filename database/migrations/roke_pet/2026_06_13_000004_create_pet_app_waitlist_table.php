<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de espera de la app móvil: mientras la app no está publicada, la landing
 * muestra "Próximamente" y captura el correo/teléfono de quien quiere que le
 * avisemos. Aquí caen esos leads (sin sesión).
 *
 * Correr con:
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_app_waitlist', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('platform', 16)->nullable();  // ios | android | any
            $table->string('source', 32)->default('landing');
            $table->boolean('notified')->default(false);  // marca cuando ya se le avisó del lanzamiento
            $table->timestamps();

            $table->index('email');
            $table->index('phone');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_app_waitlist');
    }
};
