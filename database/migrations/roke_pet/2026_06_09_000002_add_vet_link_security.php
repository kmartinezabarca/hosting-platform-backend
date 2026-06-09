<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endurecimiento del portal veterinario (links de acceso por token):
 *
 *   - access_code    PIN opcional (HASH bcrypt). Si está presente, el link
 *                    filtrado NO basta: hay que conocer también el código.
 *   - code_attempts  intentos fallidos del PIN; al superar el tope, el link se
 *                    auto-revoca (anti fuerza bruta sobre el código corto).
 *   - last_viewed_at última apertura del portal; se usa para avisar al dueño
 *                    cuando se abre su expediente (sin spamear en cada refresh).
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('vet_links', function (Blueprint $table) {
            $table->string('access_code')->nullable()->after('allow_add_records');
            $table->unsignedSmallInteger('code_attempts')->default(0)->after('access_code');
            $table->timestamp('last_viewed_at')->nullable()->after('view_count');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('vet_links', function (Blueprint $table) {
            $table->dropColumn(['access_code', 'code_attempts', 'last_viewed_at']);
        });
    }
};
