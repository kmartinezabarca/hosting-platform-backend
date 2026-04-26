<?php
// database/seeders/MarketingServiceSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MarketingService;
use Illuminate\Support\Str;

class MarketingServiceSeeder extends Seeder
{
    public function run(): void
    {
        // Limpiar para evitar duplicados en re-seed
        MarketingService::truncate();

        $services = [
            // ── Servicios principales ──────────────────────────────
            [
                'type'        => 'main',
                'icon_name'   => 'Server',
                'title'       => 'Hosting Web Gestionado',
                'description' => 'Plataforma de hosting de alto rendimiento con uptime del 99.9% garantizado, servidores NVMe y gestión experta incluida.',
                'features'    => [
                    'Almacenamiento NVMe SSD',
                    'Certificados SSL gratuitos',
                    'Backups diarios automatizados',
                    'Panel cPanel incluido',
                ],
                'color'       => 'text-blue-500',
                'bg_color'    => 'bg-blue-500/10',
                'order'       => 1,
            ],
            [
                'type'        => 'main',
                'icon_name'   => 'Gamepad2',
                'title'       => 'Servidores Gaming de Baja Latencia',
                'description' => 'Infraestructura optimizada para la mejor experiencia de juego. Minecraft, Rust, ARK y más, con Anti-DDoS incluido.',
                'features'    => [
                    'Protección Anti-DDoS incluida',
                    'Instalador de mods 1-Click',
                    'Panel Pterodactyl incluido',
                    'Soporte técnico 24/7',
                ],
                'color'       => 'text-purple-500',
                'bg_color'    => 'bg-purple-500/10',
                'order'       => 2,
            ],
            [
                'type'        => 'main',
                'icon_name'   => 'Cloud',
                'title'       => 'VPS Cloud a Medida',
                'description' => 'Servidores virtuales privados con recursos dedicados, escalabilidad instantánea y control total para tus aplicaciones.',
                'features'    => [
                    'Recursos dedicados (CPU/RAM)',
                    'Acceso root completo',
                    'Red privada virtual (VPC)',
                    'Escalado bajo demanda',
                ],
                'color'       => 'text-emerald-500',
                'bg_color'    => 'bg-emerald-500/10',
                'order'       => 3,
            ],
            [
                'type'        => 'main',
                'icon_name'   => 'Database',
                'title'       => 'Bases de Datos Gestionadas',
                'description' => 'MySQL, PostgreSQL y MongoDB con alta disponibilidad, réplicas automáticas, backups y monitoreo experto 24/7.',
                'features'    => [
                    'Backups automáticos con retención',
                    'Réplicas de lectura incluidas',
                    'Monitoreo de rendimiento 24/7',
                    'SSL en tránsito y en reposo',
                ],
                'color'       => 'text-amber-500',
                'bg_color'    => 'bg-amber-500/10',
                'order'       => 4,
            ],
            [
                'type'        => 'main',
                'icon_name'   => 'Shield',
                'title'       => 'Seguridad y Hardening',
                'description' => 'Protección integral para tu infraestructura. WAF, monitoreo proactivo de amenazas y auditorías de seguridad.',
                'features'    => [
                    'Firewall de Aplicaciones Web (WAF)',
                    'Monitoreo proactivo Anti-Malware',
                    'Auditorías de seguridad periódicas',
                    'Hardening de servidores incluido',
                ],
                'color'       => 'text-red-500',
                'bg_color'    => 'bg-red-500/10',
                'order'       => 5,
            ],
            [
                'type'        => 'main',
                'icon_name'   => 'Code',
                'title'       => 'Desarrollo de Software a Medida',
                'description' => 'Convertimos tu idea en producto. Aplicaciones web y móviles con React, Laravel y Flutter, con diseño UX/UI incluido.',
                'features'    => [
                    'Desarrollo Full-Stack (React + Laravel)',
                    'Aplicaciones móviles (Flutter)',
                    'Diseño UX/UI profesional',
                    'Optimización SEO y performance',
                ],
                'color'       => 'text-orange-500',
                'bg_color'    => 'bg-orange-500/10',
                'order'       => 6,
            ],
            [
                'type'        => 'main',
                'icon_name'   => 'Cpu',
                'title'       => 'Prototipado de Hardware e IoT',
                'description' => 'De la idea al prototipo funcional. Diseñamos y fabricamos dispositivos IoT con impresión 3D, PCBs y firmware a medida.',
                'features'    => [
                    'Diseño de circuitos y PCBs',
                    'Fabricación con impresión 3D y CNC',
                    'Firmware en Arduino / ESP32',
                    'Integración con plataformas cloud',
                ],
                'color'       => 'text-cyan-500',
                'bg_color'    => 'bg-cyan-500/10',
                'order'       => 7,
            ],
            [
                'type'        => 'main',
                'icon_name'   => 'ScanEye',
                'title'       => 'Automatización y Robótica',
                'description' => 'Sistemas inteligentes para automatizar procesos industriales. Visión por computadora, robótica y control de maquinaria.',
                'features'    => [
                    'Integración de sistemas robóticos',
                    'Visión por computadora (NVIDIA Jetson)',
                    'Control de maquinaria industrial',
                    'Automatización de procesos empresariales',
                ],
                'color'       => 'text-teal-500',
                'bg_color'    => 'bg-teal-500/10',
                'order'       => 8,
            ],

            // ── Servicios adicionales / diferenciadores ────────────
            [
                'type'        => 'additional',
                'icon_name'   => 'LifeBuoy',
                'title'       => 'Soporte de Misión Crítica 24/7',
                'description' => 'Ingenieros disponibles las 24 horas para resolver incidentes y garantizar la continuidad de tu operación.',
                'order'       => 9,
            ],
            [
                'type'        => 'additional',
                'icon_name'   => 'Zap',
                'title'       => 'Migración Gratuita "White Glove"',
                'description' => 'Nos encargamos de todo. Migramos tu sitio, apps y bases de datos desde tu proveedor actual sin costo y con mínimo tiempo de inactividad.',
                'order'       => 10,
            ],
            [
                'type'        => 'additional',
                'icon_name'   => 'RefreshCw',
                'title'       => 'Backups Automáticos Offsite',
                'description' => 'Respaldos diarios cifrados almacenados en múltiples ubicaciones geográficas. Recuperación garantizada en menos de 4 horas.',
                'order'       => 11,
            ],
            [
                'type'        => 'additional',
                'icon_name'   => 'BarChart2',
                'title'       => 'Monitoreo y Observabilidad',
                'description' => 'Dashboards en tiempo real con Grafana, alertas proactivas y reportes mensuales de rendimiento de tu infraestructura.',
                'order'       => 12,
            ],
        ];

        foreach ($services as $data) {
            MarketingService::create(array_merge(
                $data,
                [
                    'slug' => Str::slug($data['title']),
                    'uuid' => (string) Str::uuid(),
                ]
            ));
        }

        $this->command->info('✅ MarketingServiceSeeder completado — ' . MarketingService::count() . ' servicios creados.');
    }
}
