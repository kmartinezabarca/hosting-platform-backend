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
        // Paso 1: Eliminar la llave foránea y la columna user_id existente
        Schema::table("blog_posts", function (Blueprint $table) {
            $table->dropForeign(["user_id"]);
            $table->dropColumn("user_id");
        });

        // Paso 2: Añadir la nueva columna user_id (UUID) y author_name
        Schema::table("blog_posts", function (Blueprint $table) {
            $table->uuid("user_id")->nullable()->after("blog_category_id");
            $table->string("author_name")->nullable()->after("user_id");
        });

        // Paso 3: Añadir la nueva llave foránea
        Schema::table("blog_posts", function (Blueprint $table) {
            $table->foreign("user_id")->references("uuid")->on("users")->onDelete("set null");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Paso 1: Eliminar la llave foránea y las columnas añadidas
        Schema::table("blog_posts", function (Blueprint $table) {
            $table->dropForeign(["user_id"]);
            $table->dropColumn("user_id");
            $table->dropColumn("author_name");
        });

        // Paso 2: Re-añadir la columna user_id original (foreignId) y su llave foránea
        Schema::table("blog_posts", function (Blueprint $table) {
            $table->foreignId("user_id")->nullable()->after("blog_category_id")->constrained("users")->onDelete("set null");
        });
    }
};
