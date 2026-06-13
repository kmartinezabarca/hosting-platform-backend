<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technical API request log for replay/debug/reporting.
 *
 * This intentionally lives outside activity_logs/audit_logs:
 * - activity_logs: customer-facing business timeline.
 * - audit_logs: sensitive admin actions.
 * - api_request_logs: sanitized HTTP request/response trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('request_id')->index();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('method', 10);
            $table->string('path', 2048);
            $table->char('path_hash', 64);
            $table->text('full_url')->nullable();
            $table->string('route_name')->nullable();
            $table->string('route_action')->nullable();

            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('successful')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();

            $table->ipAddress('ip_address')->nullable();
            $table->json('ip_chain')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('host')->nullable();
            $table->string('origin')->nullable();
            $table->text('referer')->nullable();
            $table->string('content_type')->nullable();
            $table->string('accept')->nullable();

            $table->json('request_headers')->nullable();
            $table->json('query_params')->nullable();
            $table->json('route_params')->nullable();
            $table->json('request_body')->nullable();
            $table->json('uploaded_files')->nullable();

            $table->json('response_headers')->nullable();
            $table->json('response_body')->nullable();

            $table->boolean('request_truncated')->default(false);
            $table->boolean('response_truncated')->default(false);

            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->text('error_trace')->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['status_code', 'created_at']);
            $table->index(['path_hash', 'created_at']);
            $table->index(['route_name', 'created_at']);
            $table->index(['method', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
