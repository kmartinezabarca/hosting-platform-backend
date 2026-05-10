<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin middleware already guards these routes
    }

    public function rules(): array
    {
        return [
            'title'                   => 'required|string|max:255',
            'client_name'             => 'required|string|max:255',
            'client_email'            => 'required|email|max:255',
            'client_company'          => 'nullable|string|max:255',
            'client_phone'            => 'nullable|string|max:50',
            'items'                   => 'required|array|min:1',
            'items.*.description'     => 'required|string|max:500',
            'items.*.quantity'        => 'required|numeric|min:0.01',
            'items.*.unit_price'      => 'required|numeric|min:0',
            'discount_percent'        => 'nullable|numeric|min:0|max:100',
            'tax_percent'             => 'nullable|numeric|min:0|max:100',
            'currency'                => 'nullable|string|in:MXN,USD',
            'notes'                   => 'nullable|string',
            'terms'                   => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'               => 'El título es obligatorio.',
            'client_name.required'         => 'El nombre del cliente es obligatorio.',
            'client_email.required'        => 'El correo del cliente es obligatorio.',
            'client_email.email'           => 'El correo del cliente no es válido.',
            'items.required'               => 'La cotización debe tener al menos un concepto.',
            'items.min'                    => 'La cotización debe tener al menos un concepto.',
            'items.*.description.required' => 'Cada concepto debe tener una descripción.',
            'items.*.quantity.required'    => 'Cada concepto debe tener una cantidad.',
            'items.*.quantity.min'         => 'La cantidad debe ser mayor a cero.',
            'items.*.unit_price.required'  => 'Cada concepto debe tener un precio unitario.',
            'currency.in'                  => 'La moneda debe ser MXN o USD.',
            'discount_percent.max'         => 'El descuento no puede superar el 100%.',
        ];
    }
}
