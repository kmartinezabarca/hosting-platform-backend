<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_nodes', function (Blueprint $table) {
            // ID del nodo en el panel Pterodactyl (permite gestionar nodos desde aquí)
            $table->unsignedInteger('pterodactyl_node_id')->nullable()->after('current_services');

            // Prioridad de selección (mayor número = preferido primero)
            $table->integer('priority')->default(0)->after('pterodactyl_node_id');

            $table->index('pterodactyl_node_id');
        });
    }

    public function down(): void
    {
        Schema::table('server_nodes', function (Blueprint $table) {
            $table->dropIndex(['pterodactyl_node_id']);
            $table->dropColumn(['pterodactyl_node_id', 'priority']);
        });
    }
};
