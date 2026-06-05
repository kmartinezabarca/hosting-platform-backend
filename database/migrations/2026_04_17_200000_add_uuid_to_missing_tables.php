<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a separate `uuid` column (VARCHAR 36, UNIQUE) to every table that only
 * had an integer primary key.  Existing rows are backfilled with UUID() so the
 * column can immediately be made NOT NULL + UNIQUE.
 */
return new class extends Migration
{
    /** Tables that need a uuid column added. */
    private array $tables = [
        'invoice_items',
        'ticket_replies',
        'plan_features',
        'plan_pricing',
        'service_invoices',
        'add_on_plan',
        'service_add_ons',
        'activity_logs',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tbl) {
            // 1. Add nullable column (safe for tables that already have rows)
            if (! Schema::hasColumn($tbl, 'uuid')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->uuid('uuid')->nullable()->after('id');
                });

                // 2. Back-fill existing rows — MySQL UUID() returns a unique value per row
                DB::statement("UPDATE `{$tbl}` SET `uuid` = UUID() WHERE `uuid` IS NULL");

                // 3. Add UNIQUE constraint + NOT NULL via raw ALTER (avoids doctrine/dbal dependency)
                DB::statement("ALTER TABLE `{$tbl}` MODIFY `uuid` CHAR(36) NOT NULL");
                DB::statement("ALTER TABLE `{$tbl}` ADD UNIQUE KEY `{$tbl}_uuid_unique` (`uuid`)");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tbl) {
            if (Schema::hasColumn($tbl, 'uuid')) {
                Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                    $table->dropUnique("{$tbl}_uuid_unique");
                    $table->dropColumn('uuid');
                });
            }
        }
    }
};
