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
        Schema::create('service_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('service_id');
            $table->string('rfc', 13);
            $table->string('name');
            $table->string('zip', 5);
            $table->string('regimen', 4);
            $table->string('uso_cfdi', 10);
            $table->longText('constancia')->nullable(); // archivo base64
            $table->foreign("service_id")->references("id")->on("services")->cascadeOnDelete();
            $table->index(['uuid']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_invoices');
    }
};
