<?php
// database/seeders/ServicePlanSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BillingCycle;
use App\Models\Category;
use App\Models\PlanFeature;
use App\Models\PlanPricing;
use App\Models\ServicePlan;

class ServicePlanSeeder extends Seeder
{
    public function run(): void
    {
        // Precios base en MXN (mensual).
        // Trimestral = base * 0.90, Anual = base * 0.80
        // Trial = $0.00 (30 días, después convierte al plan contratado)

        $allPlans = [

            // ═══════════════════════════════════════════
            // WEB HOSTING
            // ═══════════════════════════════════════════
            'hosting' => [
                [
                    'id'          => 'hosting-trial',
                    'name'        => 'Hosting Trial',
                    'description' => '30 días gratuitos para que pruebes nuestro hosting sin compromiso. Sin tarjeta de crédito.',
                    'base_price'  => 0.00,
                    'popular'     => false,
                    'trial'       => true,
                    'features'    => [
                        '1 Sitio Web',
                        '2 GB SSD NVMe',
                        'Ancho de banda 10 GB',
                        '1 Cuenta de email',
                        'SSL gratuito',
                        'Soporte por ticket',
                        'Válido 30 días',
                    ],
                    'specs'       => [
                        'storage'   => '2 GB SSD',
                        'bandwidth' => '10 GB',
                        'domains'   => '1 Dominio',
                        'email'     => '1 Cuenta',
                        'duration'  => '30 días',
                    ],
                    'cycles'      => ['trial' => 0.00],
                ],
                [
                    'id'          => 'hosting-starter',
                    'name'        => 'Hosting Starter',
                    'description' => 'Perfecto para sitios web personales, blogs y pequeños proyectos.',
                    'base_price'  => 89.00,
                    'popular'     => false,
                    'trial'       => false,
                    'features'    => [
                        '1 Sitio Web',
                        '10 GB SSD NVMe',
                        'Ancho de banda ilimitado',
                        '5 Cuentas de email',
                        'SSL gratuito (Let\'s Encrypt)',
                        'Panel de control cPanel',
                        'Backup semanal',
                        'Soporte 24/7 por ticket',
                    ],
                    'specs'       => [
                        'storage'   => '10 GB SSD',
                        'bandwidth' => 'Ilimitado',
                        'domains'   => '1 Dominio',
                        'email'     => '5 Cuentas',
                    ],
                    'cycles'      => [
                        'monthly'   => 89.00,
                        'quarterly' => 80.00,
                        'annually'  => 71.00,
                    ],
                ],
                [
                    'id'          => 'hosting-pro',
                    'name'        => 'Hosting Pro',
                    'description' => 'Ideal para negocios y sitios web con tráfico moderado que necesitan más recursos.',
                    'base_price'  => 189.00,
                    'popular'     => true,
                    'trial'       => false,
                    'features'    => [
                        '5 Sitios Web',
                        '50 GB SSD NVMe',
                        'Ancho de banda ilimitado',
                        'Cuentas de email ilimitadas',
                        'SSL gratuito',
                        'Panel de control cPanel',
                        'Backup diario automatizado',
                        'CDN básico incluido',
                        'Soporte prioritario 24/7',
                        'WordPress pre-instalado',
                    ],
                    'specs'       => [
                        'storage'   => '50 GB SSD',
                        'bandwidth' => 'Ilimitado',
                        'domains'   => '5 Dominios',
                        'email'     => 'Ilimitado',
                    ],
                    'cycles'      => [
                        'monthly'   => 189.00,
                        'quarterly' => 170.00,
                        'annually'  => 151.00,
                    ],
                ],
                [
                    'id'          => 'hosting-enterprise',
                    'name'        => 'Hosting Enterprise',
                    'description' => 'Máximo rendimiento para sitios de alto tráfico y tiendas en línea exigentes.',
                    'base_price'  => 389.00,
                    'popular'     => false,
                    'trial'       => false,
                    'features'    => [
                        'Sitios web ilimitados',
                        '200 GB SSD NVMe',
                        'Ancho de banda ilimitado',
                        'Cuentas de email ilimitadas',
                        'SSL premium (Wildcard)',
                        'Panel de control cPanel',
                        'Backup diario + retención 30 días',
                        'CDN premium con Cloudflare',
                        'Soporte dedicado con SLA',
                        'Ambiente de staging incluido',
                        'Cache avanzado (Redis + OPcache)',
                    ],
                    'specs'       => [
                        'storage'   => '200 GB SSD',
                        'bandwidth' => 'Ilimitado',
                        'domains'   => 'Ilimitado',
                        'email'     => 'Ilimitado',
                    ],
                    'cycles'      => [
                        'monthly'   => 389.00,
                        'quarterly' => 350.00,
                        'annually'  => 311.00,
                    ],
                ],
            ],

            // ═══════════════════════════════════════════
            // GAME SERVERS
            // ═══════════════════════════════════════════
            //
            // pterodactyl_limits:
            //   memory  → MB de RAM
            //   disk    → MB de disco
            //   cpu     → % de CPU (100 = 1 core, 200 = 2 cores, …)
            //   swap    → MB de swap  (0 = sin swap — recomendado para Minecraft)
            //   io      → peso de I/O (100-1000, 500 es el valor normal)
            //
            // pterodactyl_feature_limits:
            //   databases   → bases de datos MySQL en el panel
            //   backups     → copias de seguridad automáticas
            //   allocations → puertos adicionales
            // Arquitectura multi-juego: el cliente elige el egg (juego) al contratar.
            // allowed_nest_ids: null = todos los nests activos disponibles.
            // max_players: snapshot del límite al contratar — se inyecta como MAX_PLAYERS
            //              en la variable de entorno del servidor Pterodactyl.
            //
            'gameserver' => [
                [
                    'id'          => 'gameserver-trial',
                    'name'        => 'Game Server Trial',
                    'description' => '30 días gratuitos para probar tu servidor de juegos. Hasta 5 jugadores, sin tarjeta.',
                    'base_price'  => 0.00,
                    'popular'     => false,
                    'trial'       => true,
                    'features'    => [
                        'Hasta 5 jugadores',
                        '1 GB RAM',
                        '1 vCPU',
                        '10 GB SSD',
                        'Panel Pterodactyl',
                        'Soporte por ticket',
                        'Válido 30 días',
                    ],
                    'specs'       => [
                        'players' => '5 Jugadores',
                        'ram'     => '1 GB RAM',
                        'cpu'     => '1 vCPU',
                        'storage' => '10 GB SSD',
                    ],
                    'cycles'      => ['trial' => 0.00],
                    'game'        => [
                        'type'             => 'minecraft',
                        'software'         => ['vanilla'],
                        'docker_image'     => 'ghcr.io/pterodactyl/yolks:java_21',
                        'allowed_nest_ids' => null, // todos los nests activos
                    ],
                    'max_players'    => 5,
                    // 1 GB RAM | 1 vCPU | 10 GB disco
                    'ptero_limits'   => ['memory' => 1024,  'swap' => 0, 'disk' => 10240,  'io' => 500, 'cpu' => 100],
                    'ptero_features' => ['databases' => 0,  'backups' => 1,  'allocations' => 1],
                ],
                [
                    'id'          => 'minecraft-basic',
                    'name'        => 'Game Server Basic',
                    'description' => 'Servidor para jugar con amigos. Minecraft, Terraria, Valheim y más.',
                    'base_price'  => 129.00,
                    'popular'     => false,
                    'trial'       => false,
                    'features'    => [
                        'Hasta 10 jugadores',
                        '2 GB RAM',
                        '1 vCPU',
                        '25 GB SSD',
                        'Panel Pterodactyl incluido',
                        'Instalador de mods 1-Click',
                        'Backup automático semanal',
                        'Protección Anti-DDoS básica',
                        'Soporte 24/7',
                    ],
                    'specs'       => [
                        'players' => '10 Jugadores',
                        'ram'     => '2 GB RAM',
                        'cpu'     => '1 vCPU',
                        'storage' => '25 GB SSD',
                    ],
                    'cycles'      => [
                        'monthly'   => 129.00,
                        'quarterly' => 116.00,
                        'annually'  => 103.00,
                    ],
                    'game'        => [
                        'type'             => 'multi',
                        'software'         => ['paper', 'vanilla'],
                        'docker_image'     => 'ghcr.io/pterodactyl/yolks:java_21',
                        'allowed_nest_ids' => null, // todos los nests activos
                    ],
                    'max_players'    => 10,
                    // 2 GB RAM | 1 vCPU | 25 GB disco
                    'ptero_limits'   => ['memory' => 2048,  'swap' => 0, 'disk' => 25600,  'io' => 500, 'cpu' => 100],
                    'ptero_features' => ['databases' => 1,  'backups' => 2,  'allocations' => 1],
                ],
                [
                    'id'          => 'minecraft-pro',
                    'name'        => 'Game Server Pro',
                    'description' => 'Para comunidades medianas. Minecraft, Rust, ARK y servidores más exigentes.',
                    'base_price'  => 249.00,
                    'popular'     => true,
                    'trial'       => false,
                    'features'    => [
                        'Hasta 30 jugadores',
                        '4 GB RAM',
                        '2 vCPU',
                        '50 GB SSD',
                        'Panel Pterodactyl avanzado',
                        'Mods y plugins ilimitados',
                        'Backup automático diario',
                        'Protección Anti-DDoS avanzada',
                        'Servidor de desarrollo incluido',
                        'Soporte prioritario 24/7',
                    ],
                    'specs'       => [
                        'players' => '30 Jugadores',
                        'ram'     => '4 GB RAM',
                        'cpu'     => '2 vCPU',
                        'storage' => '50 GB SSD',
                    ],
                    'cycles'      => [
                        'monthly'   => 249.00,
                        'quarterly' => 224.00,
                        'annually'  => 199.00,
                    ],
                    'game'        => [
                        'type'             => 'multi',
                        'software'         => ['paper', 'purpur', 'vanilla', 'fabric'],
                        'docker_image'     => 'ghcr.io/pterodactyl/yolks:java_21',
                        'allowed_nest_ids' => null, // todos los nests activos
                    ],
                    'max_players'    => 30,
                    // 4 GB RAM | 2 vCPU | 50 GB disco
                    'ptero_limits'   => ['memory' => 4096,  'swap' => 0, 'disk' => 51200,  'io' => 500, 'cpu' => 200],
                    'ptero_features' => ['databases' => 2,  'backups' => 5,  'allocations' => 1],
                ],
                [
                    'id'          => 'minecraft-enterprise',
                    'name'        => 'Game Server Enterprise',
                    'description' => 'Para grandes comunidades y servidores públicos con cientos de jugadores.',
                    'base_price'  => 499.00,
                    'popular'     => false,
                    'trial'       => false,
                    'features'    => [
                        'Hasta 150 jugadores',
                        '8 GB RAM',
                        '4 vCPU',
                        '100 GB SSD NVMe',
                        'Panel Pterodactyl premium',
                        'Mods y plugins ilimitados',
                        'Backup automático diario + retención 15 días',
                        'Protección Anti-DDoS enterprise',
                        'Subdominio dedicado gratuito',
                        'Soporte dedicado con SLA',
                        'Consola de administración avanzada',
                    ],
                    'specs'       => [
                        'players' => '150 Jugadores',
                        'ram'     => '8 GB RAM',
                        'cpu'     => '4 vCPU',
                        'storage' => '100 GB SSD',
                    ],
                    'cycles'      => [
                        'monthly'   => 499.00,
                        'quarterly' => 449.00,
                        'annually'  => 399.00,
                    ],
                    'game'        => [
                        'type'             => 'multi',
                        'software'         => ['paper', 'purpur', 'vanilla', 'fabric', 'forge'],
                        'docker_image'     => 'ghcr.io/pterodactyl/yolks:java_21',
                        'allowed_nest_ids' => null, // todos los nests activos
                    ],
                    'max_players'    => 150,
                    // 8 GB RAM | 4 vCPU | 100 GB disco
                    'ptero_limits'   => ['memory' => 8192,  'swap' => 0, 'disk' => 102400, 'io' => 500, 'cpu' => 400],
                    'ptero_features' => ['databases' => 5,  'backups' => 15, 'allocations' => 2],
                ],
            ],

            // ═══════════════════════════════════════════
            // VPS CLOUD
            // ═══════════════════════════════════════════
            'vps' => [
                [
                    'id'          => 'vps-trial',
                    'name'        => 'VPS Trial',
                    'description' => '30 días gratuitos para explorar tu VPS. Acceso root completo, sin tarjeta.',
                    'base_price'  => 0.00,
                    'popular'     => false,
                    'trial'       => true,
                    'features'    => [
                        '1 GB RAM',
                        '1 vCPU',
                        '20 GB SSD',
                        '500 GB Transferencia',
                        'Acceso root completo',
                        'Ubuntu 22.04 / Debian 12',
                        'IPv4 compartida',
                        'Soporte por ticket',
                        'Válido 30 días',
                    ],
                    'specs'       => [
                        'ram'       => '1 GB RAM',
                        'cpu'       => '1 vCPU',
                        'storage'   => '20 GB SSD',
                        'bandwidth' => '500 GB',
                    ],
                    'cycles'      => ['trial' => 0.00],
                ],
                [
                    'id'          => 'vps-basic',
                    'name'        => 'VPS Basic',
                    'description' => 'VPS ideal para proyectos en desarrollo, servidores de pruebas y aplicaciones ligeras.',
                    'base_price'  => 199.00,
                    'popular'     => false,
                    'trial'       => false,
                    'features'    => [
                        '2 GB RAM',
                        '2 vCPU',
                        '50 GB SSD NVMe',
                        '2 TB Transferencia mensual',
                        'Ubuntu / Debian / CentOS',
                        'Acceso root completo',
                        'IPv4 dedicada',
                        'Panel de control Webmin (opcional)',
                        'Backup semanal',
                        'Soporte 24/7',
                    ],
                    'specs'       => [
                        'ram'       => '2 GB RAM',
                        'cpu'       => '2 vCPU',
                        'storage'   => '50 GB SSD',
                        'bandwidth' => '2 TB',
                    ],
                    'cycles'      => [
                        'monthly'   => 199.00,
                        'quarterly' => 179.00,
                        'annually'  => 159.00,
                    ],
                ],
                [
                    'id'          => 'vps-pro',
                    'name'        => 'VPS Pro',
                    'description' => 'Para aplicaciones en producción, e-commerce y proyectos con tráfico real.',
                    'base_price'  => 399.00,
                    'popular'     => true,
                    'trial'       => false,
                    'features'    => [
                        '4 GB RAM',
                        '4 vCPU',
                        '100 GB SSD NVMe',
                        '4 TB Transferencia mensual',
                        'Ubuntu / Debian / CentOS / Windows Server',
                        'Acceso root completo',
                        'IPv4 dedicada',
                        'Backup automático diario',
                        'Monitoreo de recursos 24/7',
                        'Firewall gestionado incluido',
                        'Soporte prioritario',
                    ],
                    'specs'       => [
                        'ram'       => '4 GB RAM',
                        'cpu'       => '4 vCPU',
                        'storage'   => '100 GB SSD',
                        'bandwidth' => '4 TB',
                    ],
                    'cycles'      => [
                        'monthly'   => 399.00,
                        'quarterly' => 359.00,
                        'annually'  => 319.00,
                    ],
                ],
                [
                    'id'          => 'vps-enterprise',
                    'name'        => 'VPS Enterprise',
                    'description' => 'Para cargas críticas, alta disponibilidad y aplicaciones empresariales exigentes.',
                    'base_price'  => 799.00,
                    'popular'     => false,
                    'trial'       => false,
                    'features'    => [
                        '8 GB RAM',
                        '8 vCPU',
                        '200 GB SSD NVMe',
                        '8 TB Transferencia mensual',
                        'Ubuntu / Debian / CentOS / Windows Server',
                        'Acceso root completo',
                        '2× IPv4 dedicadas',
                        'Backup automático diario + retención 30 días',
                        'Monitoreo avanzado con alertas',
                        'Firewall gestionado + DDoS protection',
                        'Soporte dedicado con SLA 99.9%',
                        'Snapshots bajo demanda',
                    ],
                    'specs'       => [
                        'ram'       => '8 GB RAM',
                        'cpu'       => '8 vCPU',
                        'storage'   => '200 GB SSD',
                        'bandwidth' => '8 TB',
                    ],
                    'cycles'      => [
                        'monthly'   => 799.00,
                        'quarterly' => 719.00,
                        'annually'  => 639.00,
                    ],
                ],
            ],

            // ═══════════════════════════════════════════
            // BASE DE DATOS
            // ═══════════════════════════════════════════
            'database' => [
                [
                    'id'          => 'database-trial',
                    'name'        => 'Database Trial',
                    'description' => '30 días gratuitos para probar nuestra plataforma de bases de datos gestionadas.',
                    'base_price'  => 0.00,
                    'popular'     => false,
                    'trial'       => true,
                    'features'    => [
                        '512 MB RAM',
                        '5 GB SSD',
                        '10 conexiones simultáneas',
                        'MySQL 8.0',
                        'Backup diario',
                        'SSL encryption',
                        'Soporte por ticket',
                        'Válido 30 días',
                    ],
                    'specs'       => [
                        'ram'         => '512 MB RAM',
                        'storage'     => '5 GB SSD',
                        'connections' => '10 Conexiones',
                        'version'     => 'MySQL 8.0',
                    ],
                    'cycles'      => ['trial' => 0.00],
                ],
                [
                    'id'          => 'mysql-basic',
                    'name'        => 'MySQL Starter',
                    'description' => 'Base de datos MySQL gestionada, ideal para aplicaciones en etapa de crecimiento.',
                    'base_price'  => 149.00,
                    'popular'     => false,
                    'trial'       => false,
                    'features'    => [
                        '1 GB RAM',
                        '20 GB SSD NVMe',
                        '100 conexiones simultáneas',
                        'MySQL 8.0 o MariaDB 10.11',
                        'Backup diario automatizado',
                        'SSL encryption en tránsito',
                        'Monitoreo básico de rendimiento',
                        'Acceso vía IP whitelist',
                        'Soporte 24/7',
                    ],
                    'specs'       => [
                        'ram'         => '1 GB RAM',
                        'storage'     => '20 GB SSD',
                        'connections' => '100 Conexiones',
                        'version'     => 'MySQL 8.0',
                    ],
                    'cycles'      => [
                        'monthly'   => 149.00,
                        'quarterly' => 134.00,
                        'annually'  => 119.00,
                    ],
                ],
                [
                    'id'          => 'postgresql-pro',
                    'name'        => 'PostgreSQL Pro',
                    'description' => 'PostgreSQL gestionado para aplicaciones que exigen integridad, JSON y extensiones avanzadas.',
                    'base_price'  => 299.00,
                    'popular'     => true,
                    'trial'       => false,
                    'features'    => [
                        '2 GB RAM',
                        '50 GB SSD NVMe',
                        '500 conexiones simultáneas',
                        'PostgreSQL 16',
                        'Backup automático + retención 7 días',
                        'SSL encryption en tránsito y en reposo',
                        'Monitoreo avanzado con alertas',
                        'Réplica de lectura incluida',
                        'Extensiones: PostGIS, pgvector, uuid-ossp',
                        'Soporte prioritario 24/7',
                    ],
                    'specs'       => [
                        'ram'         => '2 GB RAM',
                        'storage'     => '50 GB SSD',
                        'connections' => '500 Conexiones',
                        'version'     => 'PostgreSQL 16',
                    ],
                    'cycles'      => [
                        'monthly'   => 299.00,
                        'quarterly' => 269.00,
                        'annually'  => 239.00,
                    ],
                ],
                [
                    'id'          => 'mongodb-enterprise',
                    'name'        => 'MongoDB Enterprise',
                    'description' => 'MongoDB gestionado para aplicaciones con grandes volúmenes de datos y alta escalabilidad.',
                    'base_price'  => 599.00,
                    'popular'     => false,
                    'trial'       => false,
                    'features'    => [
                        '4 GB RAM',
                        '100 GB SSD NVMe',
                        '1,000 conexiones simultáneas',
                        'MongoDB 7.0',
                        'Backup automático + retención 30 días',
                        'SSL encryption en tránsito y en reposo',
                        'Monitoreo premium con dashboards',
                        'Replica Set de 3 nodos',
                        'Sharding horizontal automático',
                        'Soporte dedicado con SLA 99.9%',
                    ],
                    'specs'       => [
                        'ram'         => '4 GB RAM',
                        'storage'     => '100 GB SSD',
                        'connections' => '1,000 Conexiones',
                        'version'     => 'MongoDB 7.0',
                    ],
                    'cycles'      => [
                        'monthly'   => 599.00,
                        'quarterly' => 539.00,
                        'annually'  => 479.00,
                    ],
                ],
            ],
        ];

        foreach ($allPlans as $categorySlug => $plans) {
            $category = Category::where('slug', $categorySlug)->first();

            if (!$category) {
                $this->command->warn("Categoría '{$categorySlug}' no encontrada — ejecuta CategorySeeder primero.");
                continue;
            }

            foreach ($plans as $planData) {
                $servicePlan = ServicePlan::updateOrCreate(
                    ['slug' => $planData['id']],
                    [
                        'category_id'    => $category->id,
                        'name'           => $planData['name'],
                        'description'    => $planData['description'],
                        'base_price'     => $planData['base_price'],
                        'is_popular'     => $planData['popular'],
                        'specifications' => $planData['specs'],
                        'is_active'      => true,
                        // Aprovisionamiento
                        'provisioner'              => isset($planData['game']) ? 'pterodactyl' : 'none',
                        'game_type'                => $planData['game']['type']         ?? null,
                        'game_runtime_options'     => isset($planData['game'])
                            ? ['software' => $planData['game']['software']]
                            : null,
                        // Pterodactyl — docker image por defecto (el egg lo elige el cliente)
                        'pterodactyl_docker_image' => $planData['game']['docker_image'] ?? null,
                        // Nests permitidos en este plan (vacío = todos los nests activos)
                        'allowed_nest_ids'         => $planData['game']['allowed_nest_ids'] ?? null,
                        // Límites de recursos — ¡estos son los que usa el aprovisionador!
                        'pterodactyl_limits'         => $planData['ptero_limits']   ?? null,
                        'pterodactyl_feature_limits' => $planData['ptero_features'] ?? null,
                        // Max jugadores resueltos en la contratación
                        'max_players'                => $planData['max_players']    ?? null,
                    ]
                );

                // Features — limpiar y recrear
                $servicePlan->features()->delete();
                foreach ($planData['features'] as $index => $feature) {
                    PlanFeature::create([
                        'service_plan_id' => $servicePlan->id,
                        'feature'         => $feature,
                        'sort_order'      => $index,
                    ]);
                }

                // Precios por ciclo de facturación
                $servicePlan->pricing()->delete();
                foreach ($planData['cycles'] as $cycleSlug => $price) {
                    $cycle = BillingCycle::where('slug', $cycleSlug)->first();
                    if ($cycle) {
                        PlanPricing::create([
                            'service_plan_id'  => $servicePlan->id,
                            'billing_cycle_id' => $cycle->id,
                            'price'            => $price,
                        ]);
                    }
                }
            }
        }

        $this->command->info('✅ ServicePlanSeeder completado — ' . ServicePlan::count() . ' planes creados.');
    }
}
