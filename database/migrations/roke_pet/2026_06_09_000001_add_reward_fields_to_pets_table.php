<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recompensa del modo perdido.
 *
 * Permite que el dueño ofrezca una recompensa al reportar a su mascota como
 * extraviada. Se muestra en el cartel de búsqueda y en el perfil público para
 * incentivar la devolución.
 *
 *   - reward_amount   monto numérico (nullable; sin recompensa si es null)
 *   - reward_currency moneda ISO-4217 (MXN por defecto)
 *   - reward_notes    condiciones libres ("a convenir", "sin preguntas", etc.)
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('pets', function (Blueprint $table) {
            $table->decimal('reward_amount', 10, 2)->nullable()->after('lost_banner_enabled');
            $table->string('reward_currency', 3)->default('MXN')->after('reward_amount');
            $table->string('reward_notes', 255)->nullable()->after('reward_currency');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('pets', function (Blueprint $table) {
            $table->dropColumn(['reward_amount', 'reward_currency', 'reward_notes']);
        });
    }
};
