<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indices for hot compute/AI queries introduced with the GitHub App,
 * deployment logs streaming, and the platform assistant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->index(
                ['github_installation_id', 'repo_full_name', 'archived_at'],
                'projects_github_repo_archived_idx'
            );
        });

        Schema::table('deployment_logs', function (Blueprint $table) {
            $table->index(
                ['deployment_id', 'seq'],
                'deployment_logs_deployment_seq_idx'
            );
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->index(
                ['conversation_id', 'id'],
                'ai_messages_conversation_id_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropIndex('ai_messages_conversation_id_idx');
        });

        Schema::table('deployment_logs', function (Blueprint $table) {
            $table->dropIndex('deployment_logs_deployment_seq_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_github_repo_archived_idx');
        });
    }
};
