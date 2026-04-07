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
   $table->dropForeign(['user_id']);          $table->dropColumn('user_id');
            $table->uuid('user_id')->nullable()->after('blog_category_id');       $table->string('author_name')->nullable()->after('user_id');        $table->foreign('user_id')->references('uuid')->on('users')->onDelete('set null');});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("blog_posts", function (Blueprint $table) {
     $table->dropForeign(['user_id']);
           $table->dropColumn('user_id');            $table->dropColumn('author_name');         $table->foreignId('user_id')->nullable()->after('blog_category_id')->constrained('users')->onDelete('set null');    }
};
