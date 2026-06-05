<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * NOTE: author_name is already included in the create_blog_posts_table migration.
     * This migration is kept for backwards compatibility and is a safe no-op.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('blog_posts', 'author_name')) {
            Schema::table("blog_posts", function (Blueprint $table) {
                $table->string("author_name")->nullable()->after("user_id");
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Column is now part of the base migration; nothing to roll back here.
    }
};
