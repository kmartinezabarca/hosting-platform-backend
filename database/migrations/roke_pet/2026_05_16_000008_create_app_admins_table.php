<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('app_admins', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('owners')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('app_admins');
    }
};
