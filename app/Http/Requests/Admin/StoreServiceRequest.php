<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Relaciones obligatorias ──────────────────────────────────────
            'user_id'         => ['required', 'integer', 'exists:users,id'],
            'service_plan_id' => ['required', 'integer', 'exists:service_plans,id'],
            // server_node_id solo aplica a infraestructura (VPS, hosting, game servers)
            'server_node_id'  => ['nullable', 'integer', 'exists:server_nodes,id'],

            // ── Identificación ───────────────────────────────────────────────
            'name'            => ['nullable', 'string', 'max:255'],
            // domain: la unicidad en servicios activos se valida en el controller
            // porque solo aplica a infraestructura, no a servicios profesionales
            'domain'          => ['nullable', 'string', 'max:255'],

            // ── Estado ───────────────────────────────────────────────────────
            'status'          => ['required', Rule::in(['pending', 'active', 'suspended', 'terminated', 'failed'])],

            // ── Facturación ──────────────────────────────────────────────────
            // one_time: aplica a proyectos de software, consultoría, migraciones
            // monthly/quarterly/annually: aplica a hosting, VPS, retainers, soporte 24/7
            'billing_cycle'   => ['required', Rule::in(['monthly', 'quarterly', 'semi_annually', 'annually', 'one_time'])],
            'price'           => ['required', 'numeric', 'min:0'],
            'setup_fee'       => ['nullable', 'numeric', 'min:0'],
            // Infraestructura: próxima fecha de cobro recurrente
            // Profesional:     fecha estimada de entrega del proyecto
            'next_due_date'   => ['nullable', 'date'],

            // ── Configuración específica por tipo (JSON libre) ───────────────
            // Infraestructura: { ip_address, panel_url, ssh_port, os, ... }
            // Game server:     { game, server_port, max_players, ram_mb, pterodactyl_id }
            // Profesional:     { start_date, end_date, hours_included, deliverables[], contract_reference }
            'configuration'   => ['nullable', 'array'],

            // ── Extra ────────────────────────────────────────────────────────
            'notes'           => ['nullable', 'string', 'max:2000'],
            'external_id'     => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required'          => 'El cliente es obligatorio.',
            'user_id.exists'            => 'El cliente seleccionado no existe.',
            'service_plan_id.required'  => 'El plan de servicio es obligatorio.',
            'service_plan_id.exists'    => 'El plan de servicio seleccionado no existe.',
            'status.required'           => 'El estado del servicio es obligatorio.',
            'status.in'                 => 'Estado inválido. Use: pending, active, suspended, terminated o failed.',
            'billing_cycle.required'    => 'El ciclo de facturación es obligatorio.',
            'billing_cycle.in'          => 'Ciclo inválido. Use: monthly, quarterly, semi_annually, annually o one_time.',
            'price.required'            => 'El precio del servicio es obligatorio.',
            'price.min'                 => 'El precio no puede ser negativo.',
            'setup_fee.min'             => 'El costo de configuración no puede ser negativo.',
        ];
    }
}
