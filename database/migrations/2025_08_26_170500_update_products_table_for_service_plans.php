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
        Schema::table("products", function (Blueprint $table) {
            // Eliminar columnas existentes
            $table->dropColumn(["service_type", "game_type", "specifications", "pricing"]);

            // Añadir service_plan_id
            $table->uuid("service_plan_id")->nullable();
            $table->foreign("service_plan_id")->references("id")->on("service_plans")->onDelete("set null");
            
            $table->index(["service_plan_id"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("products", function (Blueprint $table) {
            $table->dropForeign(["service_plan_id"]);
            $table->dropColumn(["service_plan_id"]);

            // Revertir columnas eliminadas (para rollback)
            $table->enum("service_type", ["web_hosting", "vps", "game_server", "domain"]);
            $table->string("game_type", 50)->nullable();
            $table->json("specifications");
            $table->json("pricing");
        });
    }
};

