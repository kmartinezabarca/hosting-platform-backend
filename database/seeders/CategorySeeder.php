<?php
// database/seeders/CategorySeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'slug'        => 'hosting',
                'name'        => 'Web Hosting',
                'icon'        => 'Globe',
                'description' => 'Hosting compartido de alto rendimiento para sitios web y aplicaciones, con uptime garantizado del 99.9%.',
                'color'       => 'text-blue-500',
                'bg_color'    => 'bg-blue-500/15',
                'is_active'   => true,
                'sort_order'  => 1,
            ],
            [
                'slug'        => 'gameserver',
                'name'        => 'Servidores de Juegos',
                'icon'        => 'Gamepad2',
                'description' => 'Servidores gaming de baja latencia con protección Anti-DDoS y panel Pterodactyl incluido.',
                'color'       => 'text-purple-500',
                'bg_color'    => 'bg-purple-500/15',
                'is_active'   => true,
                'sort_order'  => 2,
            ],
            [
                'slug'        => 'vps',
                'name'        => 'VPS Cloud',
                'icon'        => 'Cloud',
                'description' => 'Servidores virtuales privados con recursos dedicados, acceso root completo y escalabilidad inmediata.',
                'color'       => 'text-emerald-500',
                'bg_color'    => 'bg-emerald-500/15',
                'is_active'   => true,
                'sort_order'  => 3,
            ],
            [
                'slug'        => 'database',
                'name'        => 'Base de Datos',
                'icon'        => 'Database',
                'description' => 'Bases de datos gestionadas (MySQL, PostgreSQL, MongoDB) con backups automáticos y monitoreo 24/7.',
                'color'       => 'text-amber-500',
                'bg_color'    => 'bg-amber-500/15',
                'is_active'   => true,
                'sort_order'  => 4,
            ],
        ];

        foreach ($categories as $data) {
            Category::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
