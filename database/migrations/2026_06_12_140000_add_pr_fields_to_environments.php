<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ambientes preview de PR (mes 2 #1). Atan el ambiente efímero a su pull
 * request y al comentario de GitHub que se actualiza in-place (en vez de
 * spamear un comentario por evento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->unsignedInteger('pr_number')->nullable()->after('type');
            $table->unsignedBigInteger('pr_comment_id')->nullable()->after('pr_number');
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn(['pr_number', 'pr_comment_id']);
        });
    }
};
