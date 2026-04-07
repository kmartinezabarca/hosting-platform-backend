<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table("blog_posts", function (Blueprint $table) {
            $table->string("author_name")->nullable()->after("user_id");
            $table->uuid("user_id")->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("blog_posts", function (Blueprint $table) {
            $table->dropColumn("author_name");
            $table->uuid("user_id")->nullable(false)->change();
        });
    }
};
