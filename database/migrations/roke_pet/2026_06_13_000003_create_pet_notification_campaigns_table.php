<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaña = un envío concreto de una notificación a una audiencia. Hoy la
 * audiencia es "all" (todos los dueños); el esquema deja espacio para segmentos.
 *
 * Guarda un SNAPSHOT del título/cuerpo: si luego se edita el tip de origen, las
 * campañas ya enviadas no cambian. El fan-out lo hace SendCampaignJob, que va
 * llenando los contadores (recipients/sent/failed) y el estado.
 *
 * Correr con:
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_notification_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tip de origen (nullable: una campaña puede redactarse sin guardar el tip).
            $table->uuid('tip_id')->nullable();

            // Snapshot del contenido enviado.
            $table->string('title', 160);
            $table->text('body');
            $table->string('category', 32)->default('consejo');
            $table->string('url', 300)->nullable();
            $table->string('icon', 16)->nullable();

            // Audiencia (de momento siempre 'all'); metadata para futuros segmentos.
            $table->string('audience', 24)->default('all');
            $table->json('audience_filters')->nullable();

            // draft | scheduled | sending | sent | canceled | failed
            $table->string('status', 16)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Contadores de entrega (push real; el inbox se crea siempre).
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_notification_campaigns');
    }
};
