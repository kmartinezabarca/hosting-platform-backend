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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('server_node_id');
            $table->string('name');
            $table->enum('status', ['pending', 'active', 'suspended', 'terminated', 'failed'])->default('pending');
            $table->string('external_id')->nullable(); // ID en Proxmox/Pterodactyl
            $table->json('connection_details')->nullable(); // IP, puertos, credenciales
            $table->json('configuration')->nullable(); // Configuración específica del servicio
            $table->date('next_due_date');
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'semi_annually', 'annually']);
            $table->decimal('price', 10, 2);
            $table->decimal('setup_fee', 10, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->index(['user_id']);
            $table->index(['status']);
            $table->index(['next_due_date']);
            $table->index(['external_id']);
            $table->foreign("user_id")->references("id")->on("users")->onDelete("cascade");
            $table->foreign("product_id")->references("id")->on("products")->onDelete("restrict");
            $table->foreign("server_node_id")->references("id")->on("server_nodes")->onDelete("set null");
            $table->index(['uuid']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
