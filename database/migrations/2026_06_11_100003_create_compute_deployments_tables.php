<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano de cómputo — deployments, logs, orquestaciones y muestras de uso.
 *
 * Orchestration generaliza el patrón de ProvisioningJob: una saga con pasos
 * serializados en JSON que el OrchestrationRunner ejecuta con retries y
 * compensación. Los controladores nunca mutan resources.status directamente —
 * solo el orquestador transiciona estados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 30);  // push|manual|rollback|ai|pr_open|pr_sync
            $table->string('status', 30)->default('queued');
            $table->string('commit_sha', 64)->nullable();
            $table->string('commit_message', 500)->nullable();
            $table->string('branch')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->boolean('initiated_by_ai')->default(false);
            $table->unsignedInteger('build_seconds')->nullable();
            // Causa raíz legible generada por el motor de troubleshooting (doc 03).
            $table->text('error_summary')->nullable();
            $table->timestamps();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->index(['resource_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('deployment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('stream', 20); // build|deploy|runtime
            $table->mediumText('chunk');
            $table->timestamp('created_at')->nullable();

            $table->unique(['deployment_id', 'stream', 'seq']);
        });

        Schema::create('orchestrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('resource_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->string('flow');             // provision_app|provision_game_server|deploy|rollback|…
            $table->string('state')->nullable(); // paso actual
            $table->json('steps');              // [{step, status, started_at, finished_at, error}]
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->index(['flow', 'completed_at']);
        });

        Schema::create('usage_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->decimal('cpu_pct', 5, 2)->nullable();
            $table->unsignedInteger('ram_mb')->nullable();
            $table->unsignedInteger('disk_mb')->nullable();
            $table->unsignedInteger('net_rx_mb')->nullable();
            $table->unsignedInteger('net_tx_mb')->nullable();

            $table->index(['resource_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_samples');
        Schema::dropIfExists('orchestrations');
        Schema::dropIfExists('deployment_logs');
        Schema::dropIfExists('deployments');
    }
};
