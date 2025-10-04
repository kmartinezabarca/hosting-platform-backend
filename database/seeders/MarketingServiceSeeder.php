<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\MarketingService;
use Illuminate\Support\Str;

class MarketingServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $servicesData = [
            // --- Servicios de Infraestructura Cloud ---
            [
                'type' => 'main',
                'icon_name' => 'Server',
                'title' => 'Hosting Web Gestionado',
                'description' => 'Plataforma de hosting de alto rendimiento para sitios y aplicaciones, con un uptime del 99.9% garantizado y gestión experta.',
                'features' => [
                    'Almacenamiento NVMe SSD',
                    'Certificados SSL Gratuitos',
                    'Backups Diarios Automatizados',
                    'Panel de Control Intuitivo',
                ],
                'color' => 'text-blue-500',
                'bg_color' => 'bg-blue-500/10',
                'order' => 1,
            ],
            [
                'type' => 'main',
                'icon_name' => 'Gamepad2',
                'title' => 'Servidores Gaming de Baja Latencia',
                'description' => 'Infraestructura optimizada para la mejor experiencia de juego. Despliega servidores para Minecraft, Rust, y más, con protección Anti-DDoS.',
                'features' => [
                    'Protección Anti-DDoS Incluida',
                    'Instalador de Mods 1-Click',
                    'Panel de Control Pterodactyl',
                    'Soporte Técnico 24/7',
                ],
                'color' => 'text-purple-500',
                'bg_color' => 'bg-purple-500/10',
                'order' => 2,
            ],
            [
                'type' => 'main',
                'icon_name' => 'Cloud',
                'title' => 'Infraestructura Cloud a Medida (VPS)',
                'description' => 'Servidores virtuales privados (VPS) con recursos dedicados, escalabilidad instantánea y control total para tus aplicaciones más exigentes.',
                'features' => [
                    'Recursos Dedicados (CPU/RAM)',
                    'Escalado Automático (Auto-scaling)',
                    'Red Privada Virtual (VPC)',
                    'Acceso Root Completo',
                ],
                'color' => 'text-cyan-500',
                'bg_color' => 'bg-cyan-500/10',
                'order' => 3,
            ],
            // --- Servicios de Plataforma y Desarrollo ---
            [
                'type' => 'main',
                'icon_name' => 'Database',
                'title' => 'Bases de Datos Gestionadas',
                'description' => 'Servicios de bases de datos de alto rendimiento (MySQL, PostgreSQL, MongoDB) con replicación, backups y optimización experta.',
                'features' => [
                    'Backups Automatizados y Seguros',
                    'Configuración de Replicación',
                    'Monitoreo de Rendimiento 24/7',
                    'Optimización de Consultas',
                ],
                'color' => 'text-green-500',
                'bg_color' => 'bg-green-500/10',
                'order' => 4,
            ],
            [
                'type' => 'main',
                'icon_name' => 'Shield',
                'title' => 'Seguridad y Hardening',
                'description' => 'Protección integral para tu infraestructura digital. Aseguramos tus aplicaciones contra las amenazas más avanzadas.',
                'features' => [
                    'Firewall de Aplicaciones Web (WAF)',
                    'Monitoreo Proactivo de Malware',
                    'Auditorías de Seguridad',
                    'Hardening de Servidores',
                ],
                'color' => 'text-red-500',
                'bg_color' => 'bg-red-500/10',
                'order' => 5,
            ],
            [
                'type' => 'main',
                'icon_name' => 'Code',
                'title' => 'Desarrollo de Software a Medida',
                'description' => 'Convertimos tu visión en realidad. Desarrollamos aplicaciones web y móviles personalizadas con las tecnologías más modernas.',
                'features' => [
                    'Desarrollo Full-Stack (React, Laravel)',
                    'Aplicaciones Móviles (Flutter)',
                    'Diseño de Experiencia de Usuario (UX/UI)',
                    'Optimización para SEO',
                ],
                'color' => 'text-orange-500',
                'bg_color' => 'bg-orange-500/10',
                'order' => 6,
            ],
            // --- TUS SERVICIOS ÚNICOS Y DIFERENCIADORES ---
            [
                'type' => 'main',
                'icon_name' => 'Cpu',
                'title' => 'Prototipado de Hardware e IoT',
                'description' => 'De la idea al prototipo funcional. Diseñamos, fabricamos y programamos dispositivos de hardware a medida para soluciones de IoT.',
                'features' => [
                    'Diseño de Circuitos y PCBs',
                    'Fabricación con Impresión 3D y CNC',
                    'Programación de Firmware (Arduino/ESP32)',
                    'Integración con Plataformas Cloud',
                ],
                'color' => 'text-amber-500',
                'bg_color' => 'bg-amber-500/10',
                'order' => 7,
            ],
            [
                'type' => 'main',
                'icon_name' => 'ScanEye',
                'title' => 'Soluciones de Automatización y Robótica',
                'description' => 'Implementamos sistemas inteligentes para automatizar tus procesos. Desde la visión por computadora hasta la robótica a medida.',
                'features' => [
                    'Integración de Sistemas Robóticos',
                    'Visión por Computadora (NVIDIA Jetson)',
                    'Control de Maquinaria Industrial',
                    'Automatización de Procesos',
                ],
                'color' => 'text-teal-500',
                'bg_color' => 'bg-teal-500/10',
                'order' => 8,
            ],
            // --- Servicios Adicionales ---
            [
                'type' => 'additional',
                'icon_name' => 'LifeBuoy',
                'title' => 'Soporte de Misión Crítica 24/7',
                'description' => 'Nuestro equipo de ingenieros está disponible 24/7 para resolver cualquier inconveniente y asegurar la continuidad de tu operación.',
                'order' => 9,
            ],
            [
                'type' => 'additional',
                'icon_name' => 'Zap',
                'title' => 'Migración "White Glove" Gratuita',
                'description' => 'Nos encargamos de todo. Migramos tu sitio web, aplicaciones y bases de datos desde tu proveedor actual a ROKE, sin costo y con tiempo de inactividad mínimo.',
                'order' => 10,
            ],
        ];

        foreach ($servicesData as $data) {
            MarketingService::create(array_merge($data, [
                'slug' => Str::slug($data['title']),
                'uuid' => (string) Str::uuid(),
            ]));
        }
    }
}

