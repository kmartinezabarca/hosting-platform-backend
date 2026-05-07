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
        Schema::table('services', function (Blueprint $table) {
            $table->string('payment_intent_id')->nullable()->after('status');
            $table->unsignedInteger('selected_egg_id')->nullable()->after('payment_intent_id');
            $table->unsignedInteger('max_players')->nullable()->after('selected_egg_id');
            $table->index('payment_intent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['payment_intent_id']);
            $table->dropColumn(['payment_intent_id', 'selected_egg_id', 'max_players']);
        });
    }
};
