<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->float('cpu_percent', 5, 2)->default(0)->comment('CPU absolute % reportado por Pterodactyl');
            $table->unsignedBigInteger('memory_bytes')->default(0);
            $table->unsignedBigInteger('memory_limit_bytes')->default(0);
            $table->unsignedBigInteger('disk_bytes')->default(0);
            $table->unsignedBigInteger('disk_limit_bytes')->default(0);
            $table->unsignedBigInteger('network_rx_bytes')->default(0)->comment('Bytes recibidos acumulados');
            $table->unsignedBigInteger('network_tx_bytes')->default(0)->comment('Bytes enviados acumulados');
            $table->string('state', 20)->default('unknown')->comment('running|offline|starting|stopping');
            $table->timestamp('sampled_at')->useCurrent()->index();

            $table->index(['service_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_metrics');
    }
};
