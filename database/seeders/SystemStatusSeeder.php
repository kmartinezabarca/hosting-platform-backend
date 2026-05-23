<?php

namespace Database\Seeders;

use App\Models\SystemStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SystemStatusSeeder extends Seeder
{
    public function run(): void
    {
        $datacenters = [
            [
                'service_name'  => 'mx-norte',
                'region'        => 'MX-NORTE',
                'label'         => 'MX-NORTE',
                'coord_x'       => 28,
                'coord_y'       => 38,
                'load_pct'      => 32,
                'is_primary'    => false,
                'is_datacenter' => true,
                'status'        => 'operational',
                'message'       => 'Operacional · Monterrey, MX',
            ],
            [
                'service_name'  => 'mx-centro',
                'region'        => 'MX-CENTRO',
                'label'         => 'MX-CENTRO',
                'coord_x'       => 50,
                'coord_y'       => 50,
                'load_pct'      => 48,
                'is_primary'    => true,
                'is_datacenter' => true,
                'status'        => 'operational',
                'message'       => 'Datacenter principal · CDMX',
            ],
            [
                'service_name'  => 'us-east',
                'region'        => 'US-EAST',
                'label'         => 'US-EAST',
                'coord_x'       => 78,
                'coord_y'       => 32,
                'load_pct'      => 21,
                'is_primary'    => false,
                'is_datacenter' => true,
                'status'        => 'operational',
                'message'       => 'Operacional · Virginia, US',
            ],
            [
                'service_name'  => 'eu-west',
                'region'        => 'EU-WEST',
                'label'         => 'EU-WEST',
                'coord_x'       => 18,
                'coord_y'       => 70,
                'load_pct'      => 67,
                'is_primary'    => false,
                'is_datacenter' => true,
                'status'        => 'degraded_performance',
                'message'       => 'Mantenimiento programado',
            ],
            [
                'service_name'  => 'sa-east',
                'region'        => 'SA-EAST',
                'label'         => 'SA-EAST',
                'coord_x'       => 72,
                'coord_y'       => 82,
                'load_pct'      => 14,
                'is_primary'    => false,
                'is_datacenter' => true,
                'status'        => 'operational',
                'message'       => 'Operacional · São Paulo, BR',
            ],
        ];

        foreach ($datacenters as $dc) {
            SystemStatus::updateOrCreate(
                ['service_name' => $dc['service_name']],
                array_merge($dc, ['last_updated' => Carbon::now()])
            );
        }
    }
}
