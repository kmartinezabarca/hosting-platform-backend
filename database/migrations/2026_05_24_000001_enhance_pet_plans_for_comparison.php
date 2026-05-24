<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'roke_pet';

    private array $comparisonFeatures = [
        'free' => [
            ['label' => 'Mascotas incluidas',      'description' => '1'],
            ['label' => 'Perfil público con QR',   'description' => 'Incluido'],
            ['label' => 'Compatibilidad NFC',       'description' => 'Manual'],
            ['label' => 'Recordatorios email/push', 'description' => 'Email básico'],
            ['label' => 'Modo extraviado',          'description' => 'Básico'],
            ['label' => 'Enlaces veterinarios',     'description' => '1 temporal'],
            ['label' => 'Historial de escaneos',    'description' => 'Último escaneo'],
            ['label' => 'Cartilla PDF',             'description' => 'Básica'],
        ],
        'starter' => [
            ['label' => 'Mascotas incluidas',      'description' => '3'],
            ['label' => 'Perfil público con QR',   'description' => 'Incluido'],
            ['label' => 'Compatibilidad NFC',       'description' => 'Incluida'],
            ['label' => 'Recordatorios email/push', 'description' => 'Email + push'],
            ['label' => 'Modo extraviado',          'description' => 'Alertas + historial'],
            ['label' => 'Enlaces veterinarios',     'description' => 'Ilimitados'],
            ['label' => 'Historial de escaneos',    'description' => 'Historial completo'],
            ['label' => 'Cartilla PDF',             'description' => 'Completa'],
        ],
        'pro' => [
            ['label' => 'Mascotas incluidas',      'description' => 'Ilimitadas'],
            ['label' => 'Perfil público con QR',   'description' => 'Incluido'],
            ['label' => 'Compatibilidad NFC',       'description' => 'Incluida'],
            ['label' => 'Recordatorios email/push', 'description' => 'Email + push prioritario'],
            ['label' => 'Modo extraviado',          'description' => 'Alertas avanzadas'],
            ['label' => 'Enlaces veterinarios',     'description' => 'Ilimitados + permisos'],
            ['label' => 'Historial de escaneos',    'description' => 'Historial y analítica'],
            ['label' => 'Cartilla PDF',             'description' => 'Completa + multi-mascota'],
        ],
    ];

    public function up(): void
    {
        Schema::connection('roke_pet')->table('pet_plans', function (Blueprint $table) {
            $table->boolean('highlighted')->default(false)->after('sort_order');
            $table->string('audience', 120)->nullable()->after('highlighted');
            $table->string('badge', 80)->nullable()->after('audience');
            $table->string('cta_label', 100)->nullable()->after('badge');
            $table->string('checkout_url', 500)->nullable()->after('cta_label');
        });

        DB::connection('roke_pet')->table('pet_plans')->insert([
            'id'                   => Str::uuid()->toString(),
            'name'                 => 'Free',
            'slug'                 => 'free',
            'description'          => 'Empieza sin costo. Un perfil básico para conocer la plataforma.',
            'price_monthly'        => 0.00,
            'price_yearly'         => null,
            'trial_enabled'        => false,
            'trial_days'           => 0,
            'max_pets'             => 1,
            'features'             => json_encode($this->comparisonFeatures['free']),
            'stripe_price_monthly' => null,
            'stripe_price_yearly'  => null,
            'is_active'            => true,
            'sort_order'           => 0,
            'highlighted'          => false,
            'audience'             => 'Para probar',
            'badge'                => null,
            'cta_label'            => 'Crear cuenta gratis',
            'checkout_url'         => null,
            'metadata'             => null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        DB::connection('roke_pet')->table('pet_plans')->where('slug', 'starter')->update([
            'features'    => json_encode($this->comparisonFeatures['starter']),
            'highlighted' => true,
            'audience'    => 'Hasta 3 mascotas',
            'badge'       => 'Recomendado',
            'cta_label'   => 'Proteger a mi mascota',
            'checkout_url' => null,
            'updated_at'  => now(),
        ]);

        DB::connection('roke_pet')->table('pet_plans')->where('slug', 'pro')->update([
            'features'    => json_encode($this->comparisonFeatures['pro']),
            'highlighted' => false,
            'audience'    => 'Mascotas ilimitadas',
            'badge'       => 'Para familias grandes',
            'cta_label'   => 'Activar Protección Plus',
            'checkout_url' => null,
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('roke_pet')->table('pet_plans')->where('slug', 'starter')->update([
            'features' => json_encode([
                'Hasta 2 mascotas',
                'Historial médico completo',
                'Vacunas y desparasitaciones',
                'Collar NFC',
                'Recordatorios por email',
                'Perfil público compartible',
            ]),
        ]);

        DB::connection('roke_pet')->table('pet_plans')->where('slug', 'pro')->update([
            'features' => json_encode([
                'Mascotas ilimitadas',
                'Historial médico completo',
                'Vacunas y desparasitaciones',
                'Collar NFC premium',
                'Recordatorios push + email',
                'Perfil público compartible',
                'Links veterinarios ilimitados',
                'Soporte prioritario',
            ]),
        ]);

        DB::connection('roke_pet')->table('pet_plans')->where('slug', 'free')->delete();

        Schema::connection('roke_pet')->table('pet_plans', function (Blueprint $table) {
            $table->dropColumn(['highlighted', 'audience', 'badge', 'cta_label', 'checkout_url']);
        });
    }
};
