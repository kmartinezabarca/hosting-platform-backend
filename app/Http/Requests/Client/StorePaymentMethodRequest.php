<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate checked by auth middleware
    }

    public function rules(): array
    {
        return [
            'stripe_payment_method_id' => ['required', 'string', 'starts_with:pm_'],
            'name'                     => ['sometimes', 'string', 'max:100'],
            'is_default'               => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'stripe_payment_method_id.required'    => 'El ID del método de pago de Stripe es requerido.',
            'stripe_payment_method_id.starts_with' => 'El ID del método de pago no es válido.',
        ];
    }
}
