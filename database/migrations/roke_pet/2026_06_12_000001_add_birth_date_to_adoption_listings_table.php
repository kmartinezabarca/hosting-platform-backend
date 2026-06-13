<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('adoption_listings', function (Blueprint $table) {
            $table->date('birth_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('adoption_listings', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
