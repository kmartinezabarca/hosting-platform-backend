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
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('blog_category_id');
            $table->string('author_name')->nullable();

            $table->string("title");
            $table->string("slug")->unique();
            $table->string('excerpt', 500)->nullable();
            $table->text("content");
            $table->string("image")->nullable();
            $table->unsignedInteger('read_time')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean("is_published")->default(false);
            $table->timestamp("published_at")->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('blog_category_id')->references('id')->on('blog_categories')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['blog_category_id']);
            $table->index(['user_id']);
            $table->index(['is_published', 'published_at']);
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
