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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('payment_method_id')->nullable()->constrained()->onDelete('set null');
            $table->string('transaction_id')->unique(); // ID único de la transacción
            $table->string('provider_transaction_id')->nullable(); // ID del proveedor
            $table->enum('type', ['payment', 'refund', 'chargeback', 'fee']);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled', 'refunded']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('MXN');
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->string('provider')->nullable(); // stripe, conekta, paypal
            $table->json('provider_data')->nullable(); // Datos del proveedor
            $table->text('description')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'status']);
            $table->index(['invoice_id']);
            $table->index(['transaction_id']);
            $table->index(['provider_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

