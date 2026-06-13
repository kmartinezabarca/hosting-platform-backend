<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biblioteca de "consejos" reutilizables que el admin envía a los dueños como
 * notificaciones de engagement (tips de alimentación, juegos, salud…). Un tip
 * vive aquí y puede reenviarse/programarse cuantas veces se quiera; cada envío
 * concreto se registra en pet_notification_campaigns.
 *
 * Correr con:
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_notification_tips', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('title', 160);
            $table->text('body');
            $table->string('category', 32)->default('consejo'); // consejo | alimentacion | juego | salud | novedad
            $table->string('url', 300)->nullable();             // deep link opcional (ej. /comunidad)
            $table->string('icon', 16)->nullable();             // emoji opcional para la notificación
            $table->boolean('is_active')->default(true);

            $table->uuid('created_by')->nullable();             // uuid del admin que lo creó
            $table->timestamps();

            $table->index(['is_active', 'category']);
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_notification_tips');
    }
};
