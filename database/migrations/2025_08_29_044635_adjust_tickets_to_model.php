<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // 1) Descripción
            if (!Schema::hasColumn('tickets', 'description')) {
                $table->text('description')->nullable()->after('subject');
            }

            // 2) Category (front/model) — mantenemos department para compatibilidad
            if (!Schema::hasColumn('tickets', 'category')) {
                $table->enum('category', ['technical','billing','general','feature_request','bug_report'])
                      ->nullable()
                      ->after('status');
                $table->index('category');
            }

            // 3) resolved_at
            if (!Schema::hasColumn('tickets', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('closed_at');
                $table->index('resolved_at');
            }

            // 4) last_reply_at / last_reply_by
            if (!Schema::hasColumn('tickets', 'last_reply_at')) {
                $table->timestamp('last_reply_at')->nullable()->after('updated_at');
                $table->index('last_reply_at');
            }

            if (!Schema::hasColumn('tickets', 'last_reply_by')) {
                $table->unsignedBigInteger('last_reply_by')->nullable()->after('last_reply_at');
                $table->foreign('last_reply_by')->references('id')->on('users')->nullOnDelete();
                $table->index('last_reply_by');
            }

            // 5) Soft deletes si tu modelo lo usa
            if (!Schema::hasColumn('tickets', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // 6) Backfill de category desde department (solo donde esté null)
        if (Schema::hasColumn('tickets', 'category') && Schema::hasColumn('tickets', 'department')) {
            DB::table('tickets')->whereNull('category')->update([
                'category' => DB::raw("CASE department
                    WHEN 'technical' THEN 'technical'
                    WHEN 'billing'   THEN 'billing'
                    WHEN 'sales'     THEN 'general'
                    WHEN 'abuse'     THEN 'bug_report'
                    ELSE 'general' END")
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'last_reply_by')) {
                $table->dropForeign(['last_reply_by']);
                $table->dropIndex(['last_reply_by']);
                $table->dropColumn('last_reply_by');
            }
            if (Schema::hasColumn('tickets', 'last_reply_at')) {
                $table->dropIndex(['last_reply_at']);
                $table->dropColumn('last_reply_at');
            }
            if (Schema::hasColumn('tickets', 'resolved_at')) {
                $table->dropIndex(['resolved_at']);
                $table->dropColumn('resolved_at');
            }
            if (Schema::hasColumn('tickets', 'category')) {
                $table->dropIndex(['category']);
                $table->dropColumn('category');
            }
            if (Schema::hasColumn('tickets', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('tickets', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
