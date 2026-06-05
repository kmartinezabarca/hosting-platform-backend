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
            $table->string('avatar_emoji', 16)->nullable()->after('photo_url');
            $table->string('ring_color', 16)->nullable()->after('avatar_emoji');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('pets', function (Blueprint $table) {
            $table->dropColumn(['avatar_emoji', 'ring_color']);
        });
    }
};
