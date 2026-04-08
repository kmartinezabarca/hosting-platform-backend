<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\BillingCycle;

class BillingCycleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $billingCycles = [
            [
                'slug' => 'monthly',
                'name' => 'Mensual',
                'months' => 1,
                'discount_percentage' => 0,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'quarterly',
                'name' => 'Trimestral',
                'months' => 3,
                'discount_percentage' => 10,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'annually',
                'name' => 'Anual',
                'months' => 12,
                'discount_percentage' => 20,
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($billingCycles as $cycleData) {
            BillingCycle::updateOrCreate($cycleData);
        }
    }
}


