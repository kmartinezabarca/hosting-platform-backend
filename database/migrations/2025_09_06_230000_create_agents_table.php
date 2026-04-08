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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('agent_code', 20)->unique(); // Código único del agente
            $table->string('department', 100)->default('support'); // Departamento: support, technical, billing, etc.
            $table->enum('specialization', ['general', 'technical', 'billing', 'sales', 'escalation'])->default('general');
            $table->enum('status', ['active', 'inactive', 'busy', 'away'])->default('active');
            $table->integer('max_concurrent_tickets')->default(10); // Máximo de tickets concurrentes
            $table->integer('current_ticket_count')->default(0); // Tickets actuales asignados
            $table->decimal('performance_rating', 3, 2)->default(5.00); // Rating de 1.00 a 5.00
            $table->integer('total_tickets_resolved')->default(0); // Total de tickets resueltos
            $table->decimal('average_response_time', 8, 2)->nullable(); // Tiempo promedio de respuesta en minutos
            $table->decimal('average_resolution_time', 8, 2)->nullable(); // Tiempo promedio de resolución en minutos
            $table->json('working_hours')->nullable(); // Horarios de trabajo en formato JSON
            $table->json('skills')->nullable(); // Habilidades/competencias en formato JSON
            $table->text('notes')->nullable(); // Notas administrativas
            $table->timestamp('last_activity_at')->nullable(); // Última actividad
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para optimizar consultas
            $table->index(['status']);
            $table->index(['department']);
            $table->index(['specialization']);
            $table->index(['current_ticket_count']);
            $table->index(['performance_rating']);
            $table->index(['last_activity_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};

