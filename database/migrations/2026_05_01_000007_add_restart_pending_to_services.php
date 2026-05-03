<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('restart_required')->default(false)->after('notes');
            $table->unsignedSmallInteger('pending_changes_count')->default(0)->after('restart_required');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['restart_required', 'pending_changes_count']);
        });
    }
};
