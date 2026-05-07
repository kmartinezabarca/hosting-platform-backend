<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pterodactyl_eggs', function (Blueprint $table) {
            $table->enum('game_protocol', [
                'java',
                'bedrock',
                'crossplay',
            ])
            ->default('java')
            ->after('egg_name')
            ->comment('Tipo de protocolo/conexión del servidor');
        });

        /*
        |--------------------------------------------------------------------------
        | Migración automática de datos existentes
        |--------------------------------------------------------------------------
        |
        | Clasificamos los eggs actuales basándonos en su nombre.
        | Esto solo ocurre una vez.
        |
        */

        DB::table('pterodactyl_eggs')
            ->select('id', 'egg_name')
            ->orderBy('id')
            ->chunkById(100, function ($eggs) {

                foreach ($eggs as $egg) {
                    $name = strtolower($egg->egg_name);

                    $protocol = match (true) {

                        // Crossplay
                        str_contains($name, 'geyser'),
                        str_contains($name, 'floodgate')
                            => 'crossplay',

                        // Bedrock
                        str_contains($name, 'bedrock'),
                        str_contains($name, 'nukkit'),
                        str_contains($name, 'pocketmine'),
                        str_contains($name, 'pmmp')
                            => 'bedrock',

                        // Default
                        default
                            => 'java',
                    };

                    DB::table('pterodactyl_eggs')
                        ->where('id', $egg->id)
                        ->update([
                            'game_protocol' => $protocol,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pterodactyl_eggs', function (Blueprint $table) {
            $table->dropColumn('game_protocol');
        });
    }
};
