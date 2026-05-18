<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->dropIfExists('push_subscriptions');
        Schema::connection('roke_pet')->create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('owner_id');

            // FIX
            $table->string('endpoint', 500)->unique();

            $table->string('p256dh');
            $table->string('auth');

            $table->timestamp('created_at')->useCurrent();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('push_subscriptions');
    }
};
