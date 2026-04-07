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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->uuid("invoice_id");
            $table->foreign("invoice_id")->references("id")->on("invoices")->onDelete("cascade");
            $table->uuid("service_id")->nullable();
            $table->foreign("service_id")->references("id")->on("services")->onDelete("set null");
            $table->string('description', 500);
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total', 10, 2);
            $table->timestamps();
            
            $table->index(['invoice_id']);
            $table->index(['service_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
