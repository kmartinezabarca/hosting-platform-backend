<?php

namespace App\Http\Requests\Client;

use App\Domains\Platform\Models\CheckoutQuote;
use App\Domains\Platform\Models\ServicePlan;
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
        $quoteUuid = $this->input('quote_id');
        $quote     = $quoteUuid ? CheckoutQuote::where('uuid', $quoteUuid)->first() : null;
        $planSlug  = $quote ? $quote->servicePlan?->slug : $this->input('plan_id');
        $plan      = $planSlug ? ServicePlan::where('slug', $planSlug)->first() : null;

        $usesQuote         = filled($quoteUuid);
        $isPterodactylPlan = $plan?->isPterodactylManaged() ?? false;
        $isNoCharge        = $plan?->isNoCharge() ?? false;   // free | trial → no requiere pago

        return [
            'quote_id'      => ['required_without:plan_id', 'nullable', 'uuid', Rule::exists('checkout_quotes', 'uuid')],
            'plan_id'       => ['required_without:quote_id', 'nullable', 'string', Rule::exists('service_plans', 'slug')],
            'billing_cycle' => ['required_without:quote_id', 'nullable', Rule::in(['trial', 'monthly', 'quarterly', 'semi_annually', 'annually'])],
            'domain'        => ['nullable', 'string', 'max:255'],
            'service_name'  => ['required', 'string', 'max:255'],

            // Teléfono/celular capturado en el checkout de hosting. Se guarda en
            // el perfil del cliente para no volver a pedirlo. Se aceptan ambas claves.
            'phone'         => ['sometimes', 'nullable', 'string', 'max:20'],
            'phone_number'  => ['sometimes', 'nullable', 'string', 'max:20'],

            // Egg selection — required only for Pterodactyl-managed (game server) plans.
            'egg_id' => $isPterodactylPlan
                ? ['required', 'integer', 'min:1', Rule::exists('pterodactyl_eggs', 'id')]
                : ['sometimes', 'nullable', 'integer'],

            // Pago — NOT requerido para planes free/trial.
            // Para planes paid: se debe proveer payment_intent_id O payment_method_id.
            'payment_intent_id' => $isNoCharge
                || $usesQuote
                ? ['sometimes', 'nullable', 'string']
                : ['required_without:payment_method_id', 'nullable', 'string', 'starts_with:pi_'],
            'payment_method_id' => $isNoCharge
                || $usesQuote
                ? ['sometimes', 'nullable', 'string']
                : ['required_without:payment_intent_id', 'nullable', 'string'],

            'additional_options' => ['nullable', 'array'],

            // Add-ons (UUIDs)
            'add_ons'   => ['sometimes', 'array'],
            'add_ons.*' => ['string', 'distinct', 'uuid'],

            // Datos fiscales para CFDI (no aplica para planes $0).
            'invoice'            => ['sometimes', 'nullable', 'array'],
            'invoice.rfc'        => ['required_with:invoice', 'string', 'max:13'],
            'invoice.name'       => ['required_with:invoice', 'string', 'max:255'],
            'invoice.zip'        => ['required_with:invoice', 'string', 'size:5'],
            'invoice.regimen'    => ['required_with:invoice', 'string', 'max:4'],
            'invoice.cfdi_use_code' => ['required_with:invoice', 'string', 'max:10'],
            // La constancia puede llegar como string base64 (diseño original) o como
            // objeto {filename, mime, content_b64} (lo que arma el frontend). Se aceptan
            // ambos; el servicio extrae el base64. Sin esto, subir constancia daba 422.
            'invoice.constancia'             => ['nullable'],
            'invoice.constancia.content_b64' => ['nullable', 'string'],
            'invoice.constancia.filename'    => ['nullable', 'string', 'max:255'],
            'invoice.constancia.mime'        => ['nullable', 'string', 'max:120'],

            'generate_cfdi'       => ['sometimes', 'boolean'],
            'fiscal_profile_uuid' => ['sometimes', 'nullable', 'uuid'],
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
