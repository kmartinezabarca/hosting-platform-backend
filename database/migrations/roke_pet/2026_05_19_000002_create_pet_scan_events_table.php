<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_scan_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pet_id');
            $table->timestamp('scanned_at');
            $table->enum('source', ['nfc', 'qr', 'direct'])->default('nfc');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Ubicación — solo se guarda si el usuario acepta compartirla
            $table->boolean('share_location_allowed')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();

            // Información derivada (de IP o del cliente)
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('device_type', 50)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('pet_id')->references('id')->on('pets')->cascadeOnDelete();
            $table->index('pet_id');
            $table->index('scanned_at');
            $table->index(['pet_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_scan_events');
    }
};
