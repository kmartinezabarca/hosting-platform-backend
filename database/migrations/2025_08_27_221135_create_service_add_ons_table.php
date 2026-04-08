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
        Schema::create('service_add_ons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('add_on_id');
            $table->uuid('add_on_uuid');                    // redundante para trazabilidad
            $table->string('name');                         // snapshot
            $table->decimal('unit_price', 10, 2);          // NETO en el momento de compra
            $table->integer('quantity')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
            $table->foreign('add_on_id')->references('id')->on('add_ons')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_add_ons');
    }
};
