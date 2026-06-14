<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compute_plan_catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40)->default('compute')->index();
            $table->string('tier', 30);
            $table->string('name', 80);
            $table->string('description', 180)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('currency', 3)->default('MXN');
            $table->decimal('monthly_amount', 10, 2)->nullable();
            $table->decimal('annual_amount', 10, 2)->nullable();
            $table->string('stripe_monthly_price_id')->nullable();
            $table->string('stripe_annual_price_id')->nullable();
            $table->unsignedInteger('max_resources')->nullable();
            $table->unsignedInteger('ram_mb_max')->nullable();
            $table->unsignedInteger('max_members')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['kind', 'tier']);
        });

        $now = now();
        $plans = [
            [
                'kind' => 'compute',
                'tier' => 'free',
                'name' => 'Free',
                'description' => 'Para probar deploys y proyectos personales chicos.',
                'sort_order' => 10,
                'currency' => 'MXN',
                'monthly_amount' => 0,
                'annual_amount' => 0,
                'stripe_monthly_price_id' => null,
                'stripe_annual_price_id' => null,
                'max_resources' => 2,
                'ram_mb_max' => 512,
                'max_members' => 1,
                'features' => json_encode([
                    '2 recursos incluidos',
                    '512 MB RAM por recurso',
                    '1 miembro',
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'kind' => 'compute',
                'tier' => 'starter',
                'name' => 'Starter',
                'description' => 'Para sitios, APIs pequenas y side projects en produccion.',
                'sort_order' => 20,
                'currency' => 'MXN',
                'monthly_amount' => 129,
                'annual_amount' => 1290,
                'stripe_monthly_price_id' => null,
                'stripe_annual_price_id' => null,
                'max_resources' => 5,
                'ram_mb_max' => 1024,
                'max_members' => 1,
                'features' => json_encode([
                    '5 recursos incluidos',
                    '1 GB RAM por recurso',
                    'Deploys desde GitHub',
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'kind' => 'compute',
                'tier' => 'pro',
                'name' => 'Pro',
                'description' => 'Para productos pequenos con mas apps, bases de datos y equipo.',
                'sort_order' => 30,
                'currency' => 'MXN',
                'monthly_amount' => 299,
                'annual_amount' => 2990,
                'stripe_monthly_price_id' => null,
                'stripe_annual_price_id' => null,
                'max_resources' => 15,
                'ram_mb_max' => 2048,
                'max_members' => 3,
                'features' => json_encode([
                    '15 recursos incluidos',
                    '2 GB RAM por recurso',
                    '3 miembros de equipo',
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'kind' => 'compute',
                'tier' => 'team',
                'name' => 'Team',
                'description' => 'Para equipos que operan varias apps y servicios.',
                'sort_order' => 40,
                'currency' => 'MXN',
                'monthly_amount' => 699,
                'annual_amount' => 6990,
                'stripe_monthly_price_id' => null,
                'stripe_annual_price_id' => null,
                'max_resources' => 40,
                'ram_mb_max' => 4096,
                'max_members' => 10,
                'features' => json_encode([
                    '40 recursos incluidos',
                    '4 GB RAM por recurso',
                    '10 miembros de equipo',
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'kind' => 'compute',
                'tier' => 'agency',
                'name' => 'Agency',
                'description' => 'Para agencias y multiples proyectos de clientes.',
                'sort_order' => 50,
                'currency' => 'MXN',
                'monthly_amount' => 1499,
                'annual_amount' => 14990,
                'stripe_monthly_price_id' => null,
                'stripe_annual_price_id' => null,
                'max_resources' => 150,
                'ram_mb_max' => 4096,
                'max_members' => 25,
                'features' => json_encode([
                    '150 recursos incluidos',
                    '4 GB RAM por recurso',
                    '25 miembros de equipo',
                    'Prioridad para cuentas de alto volumen',
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('compute_plan_catalog_entries')->upsert(
            $plans,
            ['kind', 'tier'],
            [
                'name',
                'description',
                'sort_order',
                'currency',
                'monthly_amount',
                'annual_amount',
                'stripe_monthly_price_id',
                'stripe_annual_price_id',
                'max_resources',
                'ram_mb_max',
                'max_members',
                'features',
                'is_active',
                'updated_at',
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('compute_plan_catalog_entries');
    }
};
