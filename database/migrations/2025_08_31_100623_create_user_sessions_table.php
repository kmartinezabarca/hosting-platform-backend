<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->uuid('uuid')->unique();

            $table->uuid("user_id");
            $table->foreign("user_id")->references("id")->on("users")->cascadeOnDelete();

            // Para ambos modos de auth
            $table->unsignedBigInteger('sanctum_token_id')->nullable()->index(); // si usas tokens personales
            $table->string('laravel_session_id', 100)->nullable()->index();      // si usas sesión (Sanctum SPA)

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Campos opcionales (si luego quieres enriquecer)
            $table->string('device', 120)->nullable();
            $table->string('platform', 120)->nullable();
            $table->string('browser', 120)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();

            $table->timestamp('login_at')->nullable();
            $table->timestamp('last_activity')->nullable();
            $table->timestamp('logout_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'last_activity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
