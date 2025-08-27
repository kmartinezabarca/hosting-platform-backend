<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'slug' => 'hosting',
                'name' => 'Web Hosting',
                'icon' => 'Globe',
                'description' => 'Hosting compartido y dedicado para sitios web',
                'color' => 'text-blue-500',
                'bg_color' => 'bg-blue-500/15',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'gameserver',
                'name' => 'Servidores de Juegos',
                'icon' => 'Gamepad2',
                'description' => 'Servidores optimizados para gaming',
                'color' => 'text-purple-500',
                'bg_color' => 'bg-purple-500/15',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'vps',
                'name' => 'VPS Cloud',
                'icon' => 'Cloud',
                'description' => 'Servidores virtuales privados escalables',
                'color' => 'text-emerald-500',
                'bg_color' => 'bg-emerald-500/15',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'slug' => 'database',
                'name' => 'Base de Datos',
                'icon' => 'Database',
                'description' => 'Bases de datos administradas y optimizadas',
                'color' => 'text-amber-500',
                'bg_color' => 'bg-amber-500/15',
                'is_active' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($categories as $categoryData) {
           Category::updateOrCreate($categoryData);
        }
    }
}


