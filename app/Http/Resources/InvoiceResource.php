<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'             => $this->uuid,
            'invoice_number'   => $this->invoice_number,
            'status'           => $this->status,
            'currency'         => $this->currency,
            'subtotal'         => (float) $this->subtotal,
            'tax_rate'         => (float) $this->tax_rate,
            'tax_amount'       => (float) $this->tax_amount,
            'total'            => (float) $this->total,
            'due_date'         => optional($this->due_date)->toDateString(),
            'paid_at'          => optional($this->paid_at)->toIso8601String(),
            'payment_method'   => $this->payment_method,
            'payment_reference'=> $this->payment_reference,
            'notes'            => $this->notes,
            'items'            => InvoiceItemResource::collection($this->whenLoaded('items')),
            'created_at'       => optional($this->created_at)->toIso8601String(),
            'updated_at'       => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
