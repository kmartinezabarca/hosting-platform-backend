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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('domain_name')->unique();
            $table->string('registrar', 100);
            $table->string('external_id')->nullable();
            $table->enum('status', ['active', 'expired', 'suspended', 'pending_transfer', 'cancelled']);
            $table->date('registration_date');
            $table->date('expiration_date');
            $table->boolean('auto_renew')->default(true);
            $table->json('nameservers')->nullable();
            $table->json('dns_records')->nullable();
            $table->boolean('whois_privacy')->default(false);
            $table->timestamps();
            
            $table->index(['user_id']);
            $table->index(['status']);
            $table->index(['expiration_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
