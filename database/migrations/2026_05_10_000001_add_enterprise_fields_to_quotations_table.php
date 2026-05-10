<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend ENUM with new statuses
        DB::statement("ALTER TABLE quotations MODIFY COLUMN status
            ENUM('draft','sent','viewed','accepted','rejected','expired','cancelled','pending_revision')
            NOT NULL DEFAULT 'draft'");

        Schema::table('quotations', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
            $table->timestamp('accepted_at')->nullable()->after('sent_at');
            $table->timestamp('rejected_at')->nullable()->after('accepted_at');
            $table->timestamp('reopened_at')->nullable()->after('rejected_at');
            $table->text('reopened_reason')->nullable()->after('reopened_at');
            $table->unsignedSmallInteger('revision_number')->default(1)->after('reopened_reason');
            $table->string('parent_uuid')->nullable()->after('revision_number');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'accepted_at', 'rejected_at', 'reopened_at',
                'reopened_reason', 'revision_number', 'parent_uuid',
            ]);
        });

        DB::statement("ALTER TABLE quotations MODIFY COLUMN status
            ENUM('draft','sent','viewed','accepted','rejected','expired')
            NOT NULL DEFAULT 'draft'");
    }
};
