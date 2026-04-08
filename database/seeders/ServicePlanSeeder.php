<?php

namespace Database\Seeders;

use App\Models\BillingCycle;
use App\Models\Category;
use App\Models\PlanFeature;
use App\Models\PlanPricing;
use App\Models\ServicePlan;
use Illuminate\Database\Seeder;

class ServicePlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Definir todos los planes en una estructura de datos centralizada
        $allPlansData = [
            'hosting' => [
                [
                    "id" => "hosting-starter",
                    "name" => "Hosting Starter",
                    "description" => "Perfecto para sitios web personales y pequeños proyectos",
                    "price" => ["monthly" => 9.99, "quarterly" => 8.99, "annually" => 7.99],
                    "popular" => false,
                    "features" => ["1 Sitio Web", "10 GB SSD", "Ancho de banda ilimitado", "5 Cuentas de email", "SSL gratuito", "Soporte 24/7"],
                    "specs" => ["storage" => "10 GB SSD", "bandwidth" => "Ilimitado", "domains" => "1 Dominio", "email" => "5 Cuentas"],
                ],
                [
                    "id" => "hosting-pro",
                    "name" => "Hosting Pro",
                    "description" => "Ideal para empresas y sitios web con tráfico medio",
                    "price" => ["monthly" => 19.99, "quarterly" => 17.99, "annually" => 15.99],
                    "popular" => true,
                    "features" => ["5 Sitios Web", "50 GB SSD", "Ancho de banda ilimitado", "Cuentas de email ilimitadas", "SSL gratuito", "Backup diario", "CDN incluido", "Soporte prioritario"],
                    "specs" => ["storage" => "50 GB SSD", "bandwidth" => "Ilimitado", "domains" => "5 Dominios", "email" => "Ilimitado"],
                ],
                [
                    "id" => "hosting-enterprise",
                    "name" => "Hosting Enterprise",
                    "description" => "Máximo rendimiento para sitios web de alto tráfico",
                    "price" => ["monthly" => 39.99, "quarterly" => 35.99, "annually" => 31.99],
                    "popular" => false,
                    "features" => ["Sitios web ilimitados", "200 GB SSD", "Ancho de banda ilimitado", "Cuentas de email ilimitadas", "SSL gratuito", "Backup diario", "CDN premium", "Soporte dedicado", "Staging environment"],
                    "specs" => ["storage" => "200 GB SSD", "bandwidth" => "Ilimitado", "domains" => "Ilimitado", "email" => "Ilimitado"],
                ],
            ],
            'gameserver' => [
                [
                    "id" => "minecraft-basic",
                    "name" => "Minecraft Basic",
                    "description" => "Servidor Minecraft para jugar con amigos",
                    "price" => ["monthly" => 12.99, "quarterly" => 11.99, "annually" => 9.99],
                    "popular" => false,
                    "features" => ["Hasta 10 jugadores", "2 GB RAM", "1 vCPU", "25 GB SSD", "Panel de control", "Mods y plugins", "Backup automático", "Soporte 24/7"],
                    "specs" => ["players" => "10 Jugadores", "ram" => "2 GB RAM", "cpu" => "1 vCPU", "storage" => "25 GB SSD"],
                ],
                [
                    "id" => "minecraft-pro",
                    "name" => "Minecraft Pro",
                    "description" => "Servidor Minecraft para comunidades medianas",
                    "price" => ["monthly" => 24.99, "quarterly" => 22.99, "annually" => 19.99],
                    "popular" => true,
                    "features" => ["Hasta 25 jugadores", "4 GB RAM", "2 vCPU", "50 GB SSD", "Panel de control avanzado", "Mods y plugins ilimitados", "Backup automático", "DDoS protection", "Soporte prioritario"],
                    "specs" => ["players" => "25 Jugadores", "ram" => "4 GB RAM", "cpu" => "2 vCPU", "storage" => "50 GB SSD"],
                ],
                [
                    "id" => "minecraft-enterprise",
                    "name" => "Minecraft Enterprise",
                    "description" => "Servidor Minecraft para grandes comunidades",
                    "price" => ["monthly" => 49.99, "quarterly" => 44.99, "annually" => 39.99],
                    "popular" => false,
                    "features" => ["Hasta 100 jugadores", "8 GB RAM", "4 vCPU", "100 GB SSD", "Panel de control premium", "Mods y plugins ilimitados", "Backup automático", "DDoS protection", "Soporte dedicado", "Servidor de desarrollo"],
                    "specs" => ["players" => "100 Jugadores", "ram" => "8 GB RAM", "cpu" => "4 vCPU", "storage" => "100 GB SSD"],
                ],
            ],
            'vps' => [
                [
                    "id" => "vps-basic",
                    "name" => "VPS Basic",
                    "description" => "Servidor virtual para proyectos pequeños",
                    "price" => ["monthly" => 19.99, "quarterly" => 17.99, "annually" => 15.99],
                    "popular" => false,
                    "features" => ["2 GB RAM", "2 vCPU", "50 GB SSD", "2 TB Transferencia", "Ubuntu/CentOS/Debian", "Acceso root completo", "IPv4 dedicada", "Soporte 24/7"],
                    "specs" => ["ram" => "2 GB RAM", "cpu" => "2 vCPU", "storage" => "50 GB SSD", "bandwidth" => "2 TB"],
                ],
                [
                    "id" => "vps-pro",
                    "name" => "VPS Pro",
                    "description" => "Servidor virtual para aplicaciones medianas",
                    "price" => ["monthly" => 39.99, "quarterly" => 35.99, "annually" => 31.99],
                    "popular" => true,
                    "features" => ["4 GB RAM", "4 vCPU", "100 GB SSD", "4 TB Transferencia", "Ubuntu/CentOS/Debian/Windows", "Acceso root completo", "IPv4 dedicada", "Backup automático", "Monitoreo 24/7", "Soporte prioritario"],
                    "specs" => ["ram" => "4 GB RAM", "cpu" => "4 vCPU", "storage" => "100 GB SSD", "bandwidth" => "4 TB"],
                ],
                [
                    "id" => "vps-enterprise",
                    "name" => "VPS Enterprise",
                    "description" => "Servidor virtual para aplicaciones críticas",
                    "price" => ["monthly" => 79.99, "quarterly" => 71.99, "annually" => 63.99],
                    "popular" => false,
                    "features" => ["8 GB RAM", "8 vCPU", "200 GB SSD", "8 TB Transferencia", "Ubuntu/CentOS/Debian/Windows", "Acceso root completo", "IPv4 dedicada", "Backup automático", "Monitoreo 24/7", "Soporte dedicado", "SLA 99.9%"],
                    "specs" => ["ram" => "8 GB RAM", "cpu" => "8 vCPU", "storage" => "200 GB SSD", "bandwidth" => "8 TB"],
                ],
            ],
            'database' => [
                [
                    "id" => "mysql-basic",
                    "name" => "MySQL Basic",
                    "description" => "Base de datos MySQL para aplicaciones pequeñas",
                    "price" => ["monthly" => 14.99, "quarterly" => 13.49, "annually" => 11.99],
                    "popular" => false,
                    "features" => ["1 GB RAM", "20 GB SSD", "100 conexiones", "MySQL 8.0", "Backup diario", "SSL encryption", "Monitoreo básico", "Soporte 24/7"],
                    "specs" => ["ram" => "1 GB RAM", "storage" => "20 GB SSD", "connections" => "100 Conexiones", "version" => "MySQL 8.0"],
                ],
                [
                    "id" => "postgresql-pro",
                    "name" => "PostgreSQL Pro",
                    "description" => "Base de datos PostgreSQL para aplicaciones medianas",
                    "price" => ["monthly" => 29.99, "quarterly" => 26.99, "annually" => 23.99],
                    "popular" => true,
                    "features" => ["2 GB RAM", "50 GB SSD", "500 conexiones", "PostgreSQL 15", "Backup automático", "SSL encryption", "Monitoreo avanzado", "Réplicas de lectura", "Soporte prioritario"],
                    "specs" => ["ram" => "2 GB RAM", "storage" => "50 GB SSD", "connections" => "500 Conexiones", "version" => "PostgreSQL 15"],
                ],
                [
                    "id" => "mongodb-enterprise",
                    "name" => "MongoDB Enterprise",
                    "description" => "Base de datos MongoDB para aplicaciones escalables",
                    "price" => ["monthly" => 59.99, "quarterly" => 53.99, "annually" => 47.99],
                    "popular" => false,
                    "features" => ["4 GB RAM", "100 GB SSD", "1000 conexiones", "MongoDB 7.0", "Backup automático", "SSL encryption", "Monitoreo premium", "Sharding automático", "Réplicas múltiples", "Soporte dedicado"],
                    "specs" => ["ram" => "4 GB RAM", "storage" => "100 GB SSD", "connections" => "1000 Conexiones", "version" => "MongoDB 7.0"],
                ],
            ],
        ];

        foreach ($allPlansData as $categorySlug => $plans) {
            $category = Category::where("slug", $categorySlug)->first();

            if (!$category) {
                $this->command->warn("Categoría no encontrada, saltando: {$categorySlug}");
                continue;
            }

            foreach ($plans as $planData) {
                $servicePlan = ServicePlan::updateOrCreate(
                    ['slug' => $planData["id"]],
                    [
                        "category_id" => $category->id,
                        "name" => $planData["name"],
                        "description" => $planData["description"],
                        "base_price" => $planData["price"]["monthly"],
                        "is_popular" => $planData["popular"],
                        "specifications" => $planData["specs"],
                        "is_active" => true,
                    ]
                );

                $servicePlan->features()->delete();
                foreach ($planData["features"] as $index => $feature) {
                    PlanFeature::create([
                        "service_plan_id" => $servicePlan->id,
                        "feature" => $feature,
                        "sort_order" => $index,
                    ]);
                }


                $servicePlan->pricing()->delete();

                foreach ($planData["price"] as $cycleSlug => $price) {
                    $billingCycle = BillingCycle::where("slug", $cycleSlug)->first();
                    if ($billingCycle) {
                        PlanPricing::create([
                            "service_plan_id" => $servicePlan->id,
                            "billing_cycle_id" => $billingCycle->id,
                            "price" => $price,
                        ]);
                    }
                }
            }
        }
    }
}
