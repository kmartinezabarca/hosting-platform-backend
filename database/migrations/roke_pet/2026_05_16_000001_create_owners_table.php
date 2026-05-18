<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('owners', function (Blueprint $table) {
            // id = UUID del usuario en el sistema principal (sin FK cruzada de DB)
            $table->uuid('id')->primary();
            $table->string('display_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->boolean('public_email_visible')->default(false);
            $table->boolean('public_address_visible')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('owners');
    }
};
