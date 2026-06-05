<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_inbox_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('notif_type', 64)->nullable();
            $table->string('url', 512)->nullable();
            $table->string('tag', 128)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'created_at']);
            $table->index(['owner_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_inbox_notifications');
    }
};
