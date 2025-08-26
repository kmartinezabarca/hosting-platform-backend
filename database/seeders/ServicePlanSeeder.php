<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\ServicePlan;
use App\Models\BillingCycle;
use App\Models\PlanFeature;
use App\Models\PlanPricing;

class ServicePlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $hostingCategory = Category::where("slug", "hosting")->first();

        if ($hostingCategory) {
            $plans = [
                [
                    "id" => "hosting-starter",
                    "name" => "Hosting Starter",
                    "description" => "Perfecto para sitios web personales y pequeños proyectos",
                    "base_price" => 9.99,
                    "popular" => false, // Corregido: 'popular' en minúsculas
                    "features" => [
                        "1 Sitio Web",
                        "10 GB SSD",
                        "Ancho de banda ilimitado",
                        "5 Cuentas de email",
                        "SSL gratuito",
                        "Soporte 24/7",
                    ],
                    "specs" => [
                        "storage" => "10 GB SSD",
                        "bandwidth" => "Ilimitado",
                        "domains" => "1 Dominio",
                        "email" => "5 Cuentas",
                    ],
                    "pricing" => [
                        "monthly" => 9.99,
                        "quarterly" => 8.99,
                        "annually" => 7.99,
                    ],
                ],
                [
                    "id" => "hosting-pro",
                    "name" => "Hosting Pro",
                    "description" => "Ideal para empresas y sitios web con tráfico medio",
                    "base_price" => 19.99,
                    "popular" => true, // Corregido: 'popular' en minúsculas
                    "features" => [
                        "5 Sitios Web",
                        "50 GB SSD",
                        "Ancho de banda ilimitado",
                        "Cuentas de email ilimitadas",
                        "SSL gratuito",
                        "Backup diario",
                        "CDN incluido",
                        "Soporte prioritario",
                    ],
                    "specs" => [
                        "storage" => "50 GB SSD",
                        "bandwidth" => "Ilimitado",
                        "domains" => "5 Dominios",
                        "email" => "Ilimitado",
                    ],
                    "pricing" => [
                        "monthly" => 19.99,
                        "quarterly" => 17.99,
                        "annually" => 15.99,
                    ],
                ],
            ];

            foreach ($plans as $planData) {
                $servicePlan = ServicePlan::create([
                    "category_id" => $hostingCategory->id,
                    "slug" => $planData["id"],
                    "name" => $planData["name"],
                    "description" => $planData["description"],
                    "base_price" => $planData["base_price"],
                    "is_popular" => $planData["popular"],
                    "specifications" => $planData["specs"],
                    "is_active" => true,
                ]);

                foreach ($planData["features"] as $index => $feature) {
                    PlanFeature::create([
                        "service_plan_id" => $servicePlan->id,
                        "feature" => $feature,
                        "sort_order" => $index,
                    ]);
                }

                foreach ($planData["pricing"] as $cycleSlug => $price) {
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


