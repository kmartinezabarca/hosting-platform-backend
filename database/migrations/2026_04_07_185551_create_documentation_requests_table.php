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
        Schema::create('documentation_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string("name");
            $table->string("email");
            $table->string("topic");
            $table->text("description")->nullable();
            $table->enum("kind", ["documentation", "api_documentation"]);
            $table->boolean("is_resolved")->default(false);
            $table->index(['uuid']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentation_requests');
    }
};
