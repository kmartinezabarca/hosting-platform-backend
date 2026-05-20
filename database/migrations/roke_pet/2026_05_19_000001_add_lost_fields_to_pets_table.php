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
            $table->boolean('is_lost')->default(false)->after('public_profile_enabled');
            $table->timestamp('lost_since')->nullable()->after('is_lost');
            $table->text('lost_description')->nullable()->after('lost_since');
            $table->json('last_seen_location')->nullable()->after('lost_description');
            $table->string('emergency_contact_override')->nullable()->after('last_seen_location');
            $table->boolean('lost_banner_enabled')->default(true)->after('emergency_contact_override');

            $table->index('is_lost');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('pets', function (Blueprint $table) {
            $table->dropIndex(['is_lost']);
            $table->dropColumn([
                'is_lost', 'lost_since', 'lost_description',
                'last_seen_location', 'emergency_contact_override', 'lost_banner_enabled',
            ]);
        });
    }
};
