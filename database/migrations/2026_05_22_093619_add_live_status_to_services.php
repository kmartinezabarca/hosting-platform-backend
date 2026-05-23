<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade columnas para guardar el estado de runtime (de Pterodactyl o Coolify),
 * separado del `status` administrativo/billing. El status billing vive en
 * `services.status` (active/suspended/cancelled/terminated/failed) y representa
 * el contrato; el `live_status` representa lo que el proveedor reporta ahora
 * (running/stopped/starting/restarting/deploying/error/...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('live_status', 32)->nullable()->after('status');
            $table->timestamp('live_synced_at')->nullable()->after('live_status');
            $table->json('live_metrics')->nullable()->after('live_synced_at');
            $table->index('live_status');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['live_status']);
            $table->dropColumn(['live_status', 'live_synced_at', 'live_metrics']);
        });
    }
};
