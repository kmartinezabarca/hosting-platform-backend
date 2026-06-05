<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->create('pet_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->decimal('price_monthly', 8, 2)->default(0);
            $table->decimal('price_yearly', 8, 2)->nullable();

            $table->boolean('trial_enabled')->default(true);
            $table->unsignedTinyInteger('trial_days')->default(14);

            $table->unsignedSmallInteger('max_pets')->nullable()->comment('null = unlimited');

            $table->json('features')->nullable()->comment('Array of feature strings shown in pricing');

            $table->string('stripe_price_monthly')->nullable()->comment('Stripe Price ID for monthly billing');
            $table->string('stripe_price_yearly')->nullable()->comment('Stripe Price ID for yearly billing');

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();
        });

        // Seed initial plans matching existing Stripe config
        DB::connection('roke_pet')->table('pet_plans')->insert([
            [
                'id'                   => \Illuminate\Support\Str::uuid()->toString(),
                'name'                 => 'Starter',
                'slug'                 => 'starter',
                'description'          => 'Perfecto para comenzar a gestionar la salud de tu mascota.',
                'price_monthly'        => 79.00,
                'price_yearly'         => 790.00,
                'trial_enabled'        => true,
                'trial_days'           => 14,
                'max_pets'             => 2,
                'features'             => json_encode([
                    'Hasta 2 mascotas',
                    'Historial médico completo',
                    'Vacunas y desparasitaciones',
                    'Collar NFC',
                    'Recordatorios por email',
                    'Perfil público compartible',
                ]),
                'stripe_price_monthly' => env('ROKEPET_STRIPE_PRICE_STARTER'),
                'stripe_price_yearly'  => null,
                'is_active'            => true,
                'sort_order'           => 1,
                'metadata'             => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'id'                   => \Illuminate\Support\Str::uuid()->toString(),
                'name'                 => 'Pro',
                'slug'                 => 'pro',
                'description'          => 'Para familias con varias mascotas y necesidades avanzadas.',
                'price_monthly'        => 149.00,
                'price_yearly'         => 1490.00,
                'trial_enabled'        => true,
                'trial_days'           => 14,
                'max_pets'             => null,
                'features'             => json_encode([
                    'Mascotas ilimitadas',
                    'Historial médico completo',
                    'Vacunas y desparasitaciones',
                    'Collar NFC premium',
                    'Recordatorios push + email',
                    'Perfil público compartible',
                    'Links veterinarios ilimitados',
                    'Soporte prioritario',
                ]),
                'stripe_price_monthly' => env('ROKEPET_STRIPE_PRICE_PRO'),
                'stripe_price_yearly'  => null,
                'is_active'            => true,
                'sort_order'           => 2,
                'metadata'             => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->dropIfExists('pet_plans');
    }
};
