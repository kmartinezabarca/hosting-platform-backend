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
            // 1. Eliminar la llave foránea existente
            $table->dropForeign(["user_id"]);
            // 2. Eliminar la columna user_id (bigInt)
            $table->dropColumn("user_id");

            // 3. Añadir la nueva columna user_id de tipo UUID
            $table->uuid("user_id")->nullable()->after("blog_category_id");
            // 4. Añadir la columna author_name
            $table->string("author_name")->nullable()->after("user_id");

            // 5. Restaurar la llave foránea a la nueva columna user_id (UUID)
            $table->foreign("user_id")->references("uuid")->on("users")->onDelete("set null");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("blog_posts", function (Blueprint $table) {
            // 1. Eliminar la nueva llave foránea
            $table->dropForeign(["user_id"]);
            // 2. Eliminar la columna user_id (UUID)
            $table->dropColumn("user_id");
            // 3. Eliminar la columna author_name
            $table->dropColumn("author_name");

            // 4. Re-añadir la columna user_id original (foreignId) y su llave foránea
            $table->foreignId("user_id")->nullable()->after("blog_category_id")->constrained("users")->onDelete("set null");
        });
    }
};
