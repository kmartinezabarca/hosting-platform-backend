<?php

namespace App\Http\Requests\Client;

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
        return [
            'plan_id'       => ['required', 'string', Rule::exists('service_plans', 'slug')],
            'billing_cycle' => ['required', Rule::in(['monthly', 'quarterly', 'annually'])],
            'domain'        => ['nullable', 'string', 'max:255'],
            'service_name'  => ['required', 'string', 'max:255'],

            // Either a confirmed PaymentIntent or a saved payment method must be supplied
            'payment_intent_id' => ['required_without:payment_method_id', 'nullable', 'string', 'starts_with:pi_'],
            'payment_method_id' => ['required_without:payment_intent_id', 'nullable', 'string'],

            'additional_options' => ['nullable', 'array'],

            // Add-ons (UUIDs)
            'add_ons'   => ['sometimes', 'array'],
            'add_ons.*' => ['string', 'distinct', 'uuid'],

            // Fiscal data (all-or-nothing via required_with)
            'invoice'            => ['sometimes', 'array'],
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
            'payment_intent_id.required_without' => 'Debes proporcionar un método de pago o un PaymentIntent confirmado.',
            'payment_method_id.required_without' => 'Debes proporcionar un método de pago o un PaymentIntent confirmado.',
            'add_ons.*.uuid'                     => 'El UUID de un add-on no es válido.',
        ];
    }
}
