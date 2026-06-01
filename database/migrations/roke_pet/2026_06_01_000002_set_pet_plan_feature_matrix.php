<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Define la matriz de features diferenciada de los planes de roke.pet.
 *
 * Cada feature lleva un `key` estable que el backend usa para autorizar/bloquear
 * (OwnerSubscription::hasFeature) y `included` para que la UI muestre ✅/❌.
 * Editable luego desde el admin de planes.
 *
 * Free   = probar (1 mascota, identidad + emergencia).
 * Starter= familia (3 mascotas, salud + veterinario + push).
 * Pro    = ilimitadas + analítica + WhatsApp + soporte prioritario.
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        $matrix = [
            'free' => [
                'max_pets'    => 1,
                'description' => 'Empieza sin costo. Identifica a tu mascota y ten lista su info de emergencia.',
                'audience'    => '1 mascota',
                'features'    => [
                    ['key' => 'qr_nfc',            'label' => 'Perfil público con QR y NFC',         'included' => true],
                    ['key' => 'lost_mode',         'label' => 'Modo extraviado',                     'included' => true],
                    ['key' => 'email_reminders',   'label' => 'Recordatorios por email',             'included' => true],
                    ['key' => 'medical_history',   'label' => 'Cartilla e historial médico',         'included' => true],
                    ['key' => 'vet_links',         'label' => 'Enlaces veterinarios temporales',     'included' => false],
                    ['key' => 'push_reminders',    'label' => 'Recordatorios push',                  'included' => false],
                    ['key' => 'weight_tracking',   'label' => 'Historial de peso con gráficas',      'included' => false],
                    ['key' => 'scan_analytics',    'label' => 'Analítica avanzada de escaneos',      'included' => false],
                ],
            ],
            'starter' => [
                'max_pets'    => 3,
                'description' => 'Para familias que gestionan la salud de sus mascotas y la comparten con su veterinario.',
                'audience'    => 'Hasta 3 mascotas',
                'features'    => [
                    ['key' => 'qr_nfc',            'label' => 'Perfil público con QR y NFC',         'included' => true],
                    ['key' => 'lost_mode',         'label' => 'Modo extraviado',                     'included' => true],
                    ['key' => 'email_reminders',   'label' => 'Recordatorios por email',             'included' => true],
                    ['key' => 'push_reminders',    'label' => 'Recordatorios push',                  'included' => true],
                    ['key' => 'medical_history',   'label' => 'Cartilla e historial médico',         'included' => true],
                    ['key' => 'vet_links',         'label' => 'Enlaces veterinarios temporales',     'included' => true],
                    ['key' => 'weight_tracking',   'label' => 'Historial de peso con gráficas',      'included' => true],
                    ['key' => 'scan_analytics',    'label' => 'Analítica avanzada de escaneos',      'included' => false],
                    ['key' => 'whatsapp_reminders','label' => 'Recordatorios por WhatsApp',          'included' => false],
                    ['key' => 'priority_support',  'label' => 'Soporte prioritario',                 'included' => false],
                ],
            ],
            'pro' => [
                'max_pets'    => null,
                'description' => 'Para hogares con varias mascotas, criadores o cuidadores frecuentes.',
                'audience'    => 'Mascotas ilimitadas',
                'features'    => [
                    ['key' => 'qr_nfc',            'label' => 'Perfil público con QR y NFC',         'included' => true],
                    ['key' => 'lost_mode',         'label' => 'Modo extraviado',                     'included' => true],
                    ['key' => 'email_reminders',   'label' => 'Recordatorios por email',             'included' => true],
                    ['key' => 'push_reminders',    'label' => 'Recordatorios push',                  'included' => true],
                    ['key' => 'medical_history',   'label' => 'Cartilla e historial médico',         'included' => true],
                    ['key' => 'vet_links',         'label' => 'Enlaces veterinarios temporales',     'included' => true],
                    ['key' => 'weight_tracking',   'label' => 'Historial de peso con gráficas',      'included' => true],
                    ['key' => 'scan_analytics',    'label' => 'Analítica avanzada de escaneos',      'included' => true],
                    ['key' => 'whatsapp_reminders','label' => 'Recordatorios por WhatsApp',          'included' => true],
                    ['key' => 'priority_support',  'label' => 'Soporte prioritario',                 'included' => true],
                ],
            ],
        ];

        foreach ($matrix as $slug => $data) {
            DB::connection('roke_pet')->table('pet_plans')->where('slug', $slug)->update([
                'max_pets'    => $data['max_pets'],
                'description' => $data['description'],
                'audience'    => $data['audience'],
                'features'    => json_encode($data['features']),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No se revierte el contenido editorial de los planes.
    }
};
