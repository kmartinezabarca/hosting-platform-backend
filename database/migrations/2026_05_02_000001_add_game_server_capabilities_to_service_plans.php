<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            $table->string('game_type', 50)->nullable()->after('provisioner');
            $table->json('game_runtime_options')->nullable()->after('game_type');
            $table->json('game_config_schema')->nullable()->after('game_runtime_options');
        });
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            $table->dropColumn([
                'game_type',
                'game_runtime_options',
                'game_config_schema',
            ]);
        });
    }
};
