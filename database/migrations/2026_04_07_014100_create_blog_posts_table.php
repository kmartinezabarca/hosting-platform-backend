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
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->uuid("category_id");
            $table->string("title");
            $table->string("slug")->unique();
            $table->text("content");
            $table->string("image")->nullable();
            $table->boolean("is_published")->default(false);
            $table->timestamp("published_at")->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign("category_id")->references("id")->on("blog_categories")->onDelete("cascade");

            $table->index(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
