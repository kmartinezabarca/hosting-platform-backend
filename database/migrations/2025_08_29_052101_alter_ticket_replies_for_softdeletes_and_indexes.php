<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            if (!Schema::hasColumn('ticket_replies', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
            $table->boolean('is_internal')->default(false)->change();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_replies', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
            $table->dropIndex(['ticket_id', 'created_at']);
        });
    }
};
