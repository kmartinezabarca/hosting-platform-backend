<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('pet_plans', function (Blueprint $table) {
            $table->string('stripe_product_id')->nullable()->after('slug')
                ->comment('Stripe Product ID — auto-created on first checkout');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('pet_plans', function (Blueprint $table) {
            $table->dropColumn('stripe_product_id');
        });
    }
};
