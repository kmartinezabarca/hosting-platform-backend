<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic user-submitted requests that staff approve or reject
 * (KYC / documentation / API access, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // documentation_request | api_documentation_request | ...
            $table->string('kind', 50)->index();
            // pending | approved | rejected
            $table->string('status', 20)->default('pending')->index();

            $table->string('subject')->nullable();
            $table->text('description')->nullable();

            // Resolution metadata
            $table->text('note')->nullable();    // optional note on approval
            $table->text('reason')->nullable();  // reason on rejection
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_requests');
    }
};
