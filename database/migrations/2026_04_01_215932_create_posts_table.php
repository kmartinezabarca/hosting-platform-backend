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
        Schema::create("blog_posts", function (Blueprint $table) {
            $table->bigIncrements("id");
            $table->uuid("uuid")->unique();
            $table->foreignId("blog_category_id")->nullable()->constrained("blog_categories")->onDelete("set null");
            $table->foreignId("user_id")->nullable()->constrained("users")->onDelete("set null");
            $table->string("title");
            $table->string("slug")->unique();
            $table->text("excerpt");
            $table->longText("content");
            $table->string("image")->nullable();
            $table->string("read_time")->nullable();
            $table->boolean("is_featured")->default(false);
            $table->timestamp("published_at")->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("blog_posts");
    }
};
