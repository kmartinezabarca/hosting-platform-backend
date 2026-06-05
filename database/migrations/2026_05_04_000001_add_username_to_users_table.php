<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable para no romper usuarios existentes.
            // El frontend pedirá el username la próxima vez que inicien sesión.
            $table->string('username', 30)
                  ->nullable()
                  ->unique()
                  ->after('last_name')
                  ->comment('Nombre de usuario único (3-30 caracteres, solo a-z 0-9 _ -)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
