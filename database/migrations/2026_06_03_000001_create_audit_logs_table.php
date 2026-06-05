<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for sensitive administrative actions (impersonation, refunds,
 * request approvals, status changes, catalog changes, etc.).
 *
 * Actor identity fields are denormalized snapshots taken at write-time so the
 * trail survives even if the acting user is later renamed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role', 30)->nullable();

            $table->string('action');                 // e.g. invoice.refunded
            $table->string('target_type')->nullable(); // e.g. Invoice, User
            $table->string('target_id')->nullable();    // numeric id or uuid (kept as string)

            $table->text('description')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('changes')->nullable();        // { field: [old, new] }

            $table->timestamps();

            $table->index('actor_id');
            $table->index('action');
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
