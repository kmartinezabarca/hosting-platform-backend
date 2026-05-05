<?php

namespace App\Http\Requests\Client;

use App\Models\ServicePlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Determine if this plan requires egg selection.
        $isPterodactylPlan = false;
        $planSlug = $this->input('plan_id');
        if ($planSlug) {
            $plan = ServicePlan::where('slug', $planSlug)->first();
            $isPterodactylPlan = $plan?->isPterodactylManaged() ?? false;
        }

        return [
            'plan_id'       => ['required', 'string', Rule::exists('service_plans', 'slug')],
            'billing_cycle' => ['required', Rule::in(['monthly', 'quarterly', 'semi_annually', 'annually'])],
            'domain'        => ['nullable', 'string', 'max:255'],
            'service_name'  => ['required', 'string', 'max:255'],

            // Egg selection — required only for Pterodactyl-managed (game server) plans.
            'egg_id' => $isPterodactylPlan
                ? ['required', 'integer', 'min:1', Rule::exists('pterodactyl_eggs', 'id')]
                : ['sometimes', 'nullable', 'integer'],

            // Either a confirmed PaymentIntent or a saved payment method must be supplied
            'payment_intent_id' => ['required_without:payment_method_id', 'nullable', 'string', 'starts_with:pi_'],
            'payment_method_id' => ['required_without:payment_intent_id', 'nullable', 'string'],

            'additional_options' => ['nullable', 'array'],

            // Add-ons (UUIDs)
            'add_ons'   => ['sometimes', 'array'],
            'add_ons.*' => ['string', 'distinct', 'uuid'],

            // Datos fiscales para CFDI.
            // null o ausente → se timbrará como Público en General pasadas 72 horas.
            // Si se envía, debe ser un objeto con todos los campos requeridos.
            'invoice'            => ['sometimes', 'nullable', 'array'],
            'invoice.rfc'        => ['required_with:invoice', 'string', 'max:13'],
            'invoice.name'       => ['required_with:invoice', 'string', 'max:255'],
            'invoice.zip'        => ['required_with:invoice', 'string', 'size:5'],
            'invoice.regimen'    => ['required_with:invoice', 'string', 'max:4'],
            'invoice.uso_cfdi'   => ['required_with:invoice', 'string', 'max:10'],
            'invoice.constancia' => ['nullable', 'string'],

            'create_subscription' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_id.exists'                     => 'El plan seleccionado no existe.',
            'billing_cycle.in'                   => 'El ciclo de facturación debe ser mensual, trimestral o anual.',
            'egg_id.required'                    => 'Debes seleccionar un juego para este servidor.',
            'egg_id.exists'                      => 'El juego seleccionado no está disponible.',
            'payment_intent_id.required_without' => 'Debes proporcionar un método de pago o un PaymentIntent confirmado.',
            'payment_method_id.required_without' => 'Debes proporcionar un método de pago o un PaymentIntent confirmado.',
            'add_ons.*.uuid'                     => 'El UUID de un add-on no es válido.',
        ];
    }
}
