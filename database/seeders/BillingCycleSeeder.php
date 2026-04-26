<?php
// database/seeders/BillingCycleSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BillingCycle;

class BillingCycleSeeder extends Seeder
{
    public function run(): void
    {
        $cycles = [
            [
                'slug'                => 'trial',
                'name'                => 'Prueba Gratuita',
                'months'              => 0,
                'discount_percentage' => 100,
                'is_active'           => true,
                'sort_order'          => 0,
            ],
            [
                'slug'                => 'monthly',
                'name'                => 'Mensual',
                'months'              => 1,
                'discount_percentage' => 0,
                'is_active'           => true,
                'sort_order'          => 1,
            ],
            [
                'slug'                => 'quarterly',
                'name'                => 'Trimestral',
                'months'              => 3,
                'discount_percentage' => 10,
                'is_active'           => true,
                'sort_order'          => 2,
            ],
            [
                'slug'                => 'annually',
                'name'                => 'Anual',
                'months'              => 12,
                'discount_percentage' => 20,
                'is_active'           => true,
                'sort_order'          => 3,
            ],
        ];

        foreach ($cycles as $data) {
            BillingCycle::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
