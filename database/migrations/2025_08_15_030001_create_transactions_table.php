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
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('payment_method_id');
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
            $table->index(['user_id', 'status']);
            $table->index(['invoice_id']);
            $table->index(['transaction_id']);
            $table->index(['provider_transaction_id']);
            $table->foreign("user_id")->references("id")->on("users")->onDelete("cascade");
            $table->foreign("invoice_id")->references("id")->on("invoices")->onDelete("set null");
            $table->foreign("payment_method_id")->references("id")->on("payment_methods")->onDelete("set null");
            $table->index(['uuid']);
            $table->timestamps();
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

