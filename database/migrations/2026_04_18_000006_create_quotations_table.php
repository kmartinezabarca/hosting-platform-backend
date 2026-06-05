<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Datos del cliente
            $table->string('title');
            $table->string('client_name');
            $table->string('client_email');
            $table->string('client_company')->nullable();
            $table->string('client_phone')->nullable();

            // Líneas de la cotización
            // Cada item: { description, quantity, unit_price, subtotal }
            $table->json('items');

            // Importes calculados automáticamente
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(16);   // IVA default 16 %
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->string('currency', 3)->default('MXN');       // MXN | USD

            // Textos adicionales
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            // Estado
            $table->enum('status', ['draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired'])
                  ->default('draft');

            // Enlace público
            $table->string('public_token')->unique()->nullable();
            $table->string('public_url')->nullable();

            // Fechas de ciclo de vida
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Índices de búsqueda frecuente
            $table->index('status');
            $table->index('client_email');
            $table->index('public_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
