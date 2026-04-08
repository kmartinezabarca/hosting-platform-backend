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
        Schema::create('user_requests', function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->string("name");
            $table->string("email");
            $table->string("topic")->nullable();
            $table->text("description")->nullable();
            $table->enum("kind", ["blog_subscription", "documentation_request", "api_documentation_request"]);
            $table->string("status")->default("pending"); // e.g., pending, resolved, rejected
            $table->boolean("is_resolved")->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_requests');
    }
};
