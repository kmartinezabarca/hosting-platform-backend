<?php

namespace App\Http\Requests\Admin;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Receptor ────────────────────────────────────────────
            'user_id'            => ['required', 'integer', 'exists:users,id'],
            'service_id'         => ['nullable', 'integer', 'exists:services,id'],

            // ── Folio ────────────────────────────────────────────────
            'invoice_number'     => ['nullable', 'string', 'max:50',
                                     Rule::unique('invoices', 'invoice_number')],

            // ── Estado y fechas ──────────────────────────────────────
            'status'             => ['required', Rule::in([
                                         Invoice::STATUS_DRAFT,
                                         Invoice::STATUS_SENT,
                                         Invoice::STATUS_PROCESS,
                                         Invoice::STATUS_PAID,
                                         Invoice::STATUS_OVERDUE,
                                         Invoice::STATUS_CANCELLED,
                                     ])],
            'due_date'           => ['required', 'date'],

            // ── Fiscal México ────────────────────────────────────────
            // IVA estándar México = 16 %. Se puede sobreescribir (tasa 0 para exportaciones, etc.)
            'currency'           => ['nullable', 'string', 'size:3'],   // MXN por defecto
            'tax_rate'           => ['nullable', 'numeric', 'min:0', 'max:100'],

            // ── Pago ─────────────────────────────────────────────────
            'payment_method'     => ['nullable', 'string', 'max:100'],
            'payment_reference'  => ['nullable', 'string', 'max:255'],

            // ── Notas ────────────────────────────────────────────────
            'notes'              => ['nullable', 'string', 'max:1000'],

            // ── Partidas / Conceptos (CFDI obliga al menos 1) ────────
            'items'              => ['required', 'array', 'min:1'],
            'items.*.description'=> ['required', 'string', 'max:500'],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required'             => 'El receptor de la factura es obligatorio.',
            'user_id.exists'               => 'El usuario seleccionado no existe.',
            'invoice_number.unique'        => 'Este número de factura ya existe.',
            'status.required'              => 'El estado de la factura es obligatorio.',
            'status.in'                    => 'El estado no es válido.',
            'due_date.required'            => 'La fecha de vencimiento es obligatoria.',
            'due_date.date'                => 'La fecha de vencimiento no tiene formato válido.',
            'tax_rate.min'                 => 'La tasa de impuesto no puede ser negativa.',
            'tax_rate.max'                 => 'La tasa de impuesto no puede superar 100 %.',
            'items.required'               => 'Debe incluir al menos un concepto/partida.',
            'items.min'                    => 'Debe incluir al menos un concepto/partida.',
            'items.*.description.required' => 'La descripción del concepto es obligatoria.',
            'items.*.quantity.required'    => 'La cantidad del concepto es obligatoria.',
            'items.*.quantity.min'         => 'La cantidad debe ser al menos 1.',
            'items.*.unit_price.required'  => 'El precio unitario es obligatorio.',
            'items.*.unit_price.min'       => 'El precio unitario no puede ser negativo.',
        ];
    }
}
