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
        Schema::create('server_nodes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('hostname');
            $table->string('ip_address', 45);
            $table->string('location');
            $table->enum('node_type', ['proxmox', 'pterodactyl', 'dedicated']);
            $table->json('specifications');
            $table->json('api_credentials'); // Credenciales encriptadas
            $table->enum('status', ['active', 'maintenance', 'offline'])->default('active');
            $table->integer('max_services')->default(0);
            $table->integer('current_services')->default(0);
            $table->timestamps();
            
            $table->index(['node_type']);
            $table->index(['status']);
            $table->index(['location']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_nodes');
    }
};
