<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('pets', function (Blueprint $table) {
            $table->string('primary_vet_license')->nullable()->after('primary_vet_clinic');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('pets', function (Blueprint $table) {
            $table->dropColumn('primary_vet_license');
        });
    }
};
