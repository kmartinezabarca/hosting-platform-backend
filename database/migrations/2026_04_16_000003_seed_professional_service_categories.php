<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Inserta las categorías de Servicios Profesionales si no existen.
     * Las 4 categorías de infraestructura las maneja el CategorySeeder original.
     */
    public function up(): void
    {
        $professional = [
            [
                'slug'        => 'database-architecture',
                'name'        => 'Arquitectura de Bases de Datos',
                'description' => 'Diseño, optimización y consultoría de arquitecturas de datos.',
                'icon'        => 'DatabaseZap',
                'color'       => 'text-cyan-500',
                'bg_color'    => 'bg-cyan-500/15',
                'sort_order'  => 5,
            ],
            [
                'slug'        => 'software-development',
                'name'        => 'Desarrollo de Software a Medida',
                'description' => 'Desarrollo de aplicaciones y sistemas empresariales personalizados.',
                'icon'        => 'Code2',
                'color'       => 'text-indigo-500',
                'bg_color'    => 'bg-indigo-500/15',
                'sort_order'  => 6,
            ],
            [
                'slug'        => 'security-devops',
                'name'        => 'Consultoría de Seguridad y DevOps',
                'description' => 'Auditorías de seguridad, CI/CD, IaC y cultura DevOps.',
                'icon'        => 'ShieldCheck',
                'color'       => 'text-red-500',
                'bg_color'    => 'bg-red-500/15',
                'sort_order'  => 7,
            ],
            [
                'slug'        => 'migration-modernization',
                'name'        => 'Migración y Modernización',
                'description' => 'Migración de infraestructura legacy a nube moderna.',
                'icon'        => 'ArrowUpCircle',
                'color'       => 'text-orange-500',
                'bg_color'    => 'bg-orange-500/15',
                'sort_order'  => 8,
            ],
            [
                'slug'        => 'critical-support',
                'name'        => 'Soporte de Misión Crítica 24/7',
                'description' => 'Soporte especializado con SLA garantizado las 24 horas.',
                'icon'        => 'HeartPulse',
                'color'       => 'text-rose-500',
                'bg_color'    => 'bg-rose-500/15',
                'sort_order'  => 9,
            ],
        ];

        foreach ($professional as $cat) {
            DB::table('categories')->updateOrInsert(
                ['slug' => $cat['slug']],
                array_merge($cat, [
                    'uuid'       => (string) Str::uuid(),
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }

    public function down(): void
    {
        DB::table('categories')->whereIn('slug', [
            'database-architecture',
            'software-development',
            'security-devops',
            'migration-modernization',
            'critical-support',
        ])->delete();
    }
};
