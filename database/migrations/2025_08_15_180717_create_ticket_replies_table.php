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
        if (!Schema::hasTable('ticket_replies')) {
            Schema::create('ticket_replies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->text('message');
                $table->boolean('is_internal')->default(false);
                $table->json('attachments')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // Índices para mejorar el rendimiento
                $table->index(['ticket_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
                $table->index(['is_internal', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_replies');
    }
};
