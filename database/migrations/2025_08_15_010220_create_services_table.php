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
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->foreignId('server_node_id')->nullable()->constrained()->onDelete('set null');
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
            $table->timestamps();
            
            $table->index(['user_id']);
            $table->index(['status']);
            $table->index(['next_due_date']);
            $table->index(['external_id']);
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
