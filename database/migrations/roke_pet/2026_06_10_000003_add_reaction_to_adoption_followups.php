<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reacción del rescatista a un seguimiento entregado (cierra el ciclo de
 * comunicación: el adoptante sube fotos → el rescatista agradece/comenta).
 *   php artisan migrate --path=database/migrations/roke_pet --database=roke_pet
 */
return new class extends Migration {
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('adoption_followups', function (Blueprint $table) {
            $table->string('reaction', 16)->nullable()->after('note');       // heart|clap|smile|pray
            $table->text('reaction_note')->nullable()->after('reaction');    // comentario del rescatista
            $table->timestamp('reacted_at')->nullable()->after('reaction_note');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('adoption_followups', function (Blueprint $table) {
            $table->dropColumn(['reaction', 'reaction_note', 'reacted_at']);
        });
    }
};
