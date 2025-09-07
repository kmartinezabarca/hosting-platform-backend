<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Agent;
use Illuminate\Support\Facades\Hash;

class AgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear usuarios de soporte si no existen
        $supportUsers = [
            [
                'first_name' => 'Ana',
                'last_name' => 'García',
                'email' => 'ana.garcia@support.com',
                'role' => 'support',
                'department' => 'support',
                'specialization' => 'general'
            ],
            [
                'first_name' => 'Carlos',
                'last_name' => 'Rodríguez',
                'email' => 'carlos.rodriguez@support.com',
                'role' => 'support',
                'department' => 'technical',
                'specialization' => 'technical'
            ],
            [
                'first_name' => 'María',
                'last_name' => 'López',
                'email' => 'maria.lopez@support.com',
                'role' => 'support',
                'department' => 'billing',
                'specialization' => 'billing'
            ],
            [
                'first_name' => 'David',
                'last_name' => 'Martínez',
                'email' => 'david.martinez@support.com',
                'role' => 'admin',
                'department' => 'support',
                'specialization' => 'escalation'
            ],
            [
                'first_name' => 'Laura',
                'last_name' => 'Fernández',
                'email' => 'laura.fernandez@support.com',
                'role' => 'support',
                'department' => 'sales',
                'specialization' => 'sales'
            ]
        ];

        foreach ($supportUsers as $userData) {
            // Crear usuario si no existe
            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'uuid' => \Illuminate\Support\Str::uuid(),
                    'first_name' => $userData['first_name'],
                    'last_name' => $userData['last_name'],
                    'password' => Hash::make('password123'),
                    'role' => $userData['role'],
                    'status' => 'active',
                    'email_verified_at' => now()
                ]
            );

            // Crear agente si no existe
            Agent::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'department' => $userData['department'],
                    'specialization' => $userData['specialization'],
                    'status' => 'active',
                    'max_concurrent_tickets' => rand(8, 15),
                    'performance_rating' => rand(400, 500) / 100, // 4.00 - 5.00
                    'total_tickets_resolved' => rand(50, 200),
                    'average_response_time' => rand(5, 30), // 5-30 minutos
                    'average_resolution_time' => rand(60, 240), // 1-4 horas
                    'working_hours' => [
                        'monday' => ['start' => '09:00', 'end' => '18:00'],
                        'tuesday' => ['start' => '09:00', 'end' => '18:00'],
                        'wednesday' => ['start' => '09:00', 'end' => '18:00'],
                        'thursday' => ['start' => '09:00', 'end' => '18:00'],
                        'friday' => ['start' => '09:00', 'end' => '18:00']
                    ],
                    'skills' => $this->getSkillsBySpecialization($userData['specialization']),
                    'notes' => 'Agente creado automáticamente por el seeder',
                    'last_activity_at' => now()
                ]
            );
        }
    }

    /**
     * Obtener habilidades según especialización
     */
    private function getSkillsBySpecialization($specialization)
    {
        $skills = [
            'general' => ['Atención al cliente', 'Comunicación', 'Resolución de problemas', 'Paciencia'],
            'technical' => ['Soporte técnico', 'Troubleshooting', 'Redes', 'Servidores', 'Linux', 'Windows'],
            'billing' => ['Facturación', 'Pagos', 'Contabilidad', 'Stripe', 'Resolución de disputas'],
            'sales' => ['Ventas', 'Negociación', 'Productos', 'Upselling', 'Cross-selling'],
            'escalation' => ['Gestión de escalaciones', 'Liderazgo', 'Toma de decisiones', 'Mediación']
        ];

        return $skills[$specialization] ?? $skills['general'];
    }
}

