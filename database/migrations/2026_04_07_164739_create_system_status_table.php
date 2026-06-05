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
        Schema::create('system_status', function (Blueprint $table) {
            $table->id();
            $table->uuid("uuid")->unique();
            $table->string("service_name")->unique();
            $table->enum("status", ["operational", "degraded_performance", "partial_outage", "major_outage"])->default("operational");
            $table->text("message")->nullable();
            $table->timestamp("last_updated")->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_status');
    }
};
