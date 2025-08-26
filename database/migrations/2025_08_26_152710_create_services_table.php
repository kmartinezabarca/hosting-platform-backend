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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->uuid("uuid")->unique();
            $table->foreignId("user_id")->constrained()->onDelete("cascade");
            $table->string("plan_id"); // Store as string for now, can be foreign key to plans table later
            $table->string("name");
            $table->string("status")->default("pending");
            $table->string("billing_cycle");
            $table->string("domain")->nullable();
            $table->string("payment_intent_id")->nullable();
            $table->json("additional_options")->nullable();
            $table->timestamp("next_billing_date")->nullable();
            $table->timestamp("canceled_at")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
