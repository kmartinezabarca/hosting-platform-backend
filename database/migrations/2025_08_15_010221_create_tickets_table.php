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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_to');
            $table->unsignedBigInteger('service_id');
            $table->string('ticket_number', 20)->unique();
            $table->string('subject', 500);
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'])->default('open');
            $table->enum('department', ['technical', 'billing', 'sales', 'abuse'])->default('technical');
            $table->timestamp('closed_at')->nullable();
            $table->index(['user_id']);
            $table->index(['assigned_to']);
            $table->index(['status']);
            $table->index(['priority']);
            $table->index(['ticket_number']);
            $table->foreign("user_id")->references("id")->on("users")->onDelete("cascade");
            $table->foreign("assigned_to")->references("id")->on("users")->onDelete("set null");
            $table->foreign('service_id')->references('id')->on('services')->onDelete('set null');
            $table->index(['uuid']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
