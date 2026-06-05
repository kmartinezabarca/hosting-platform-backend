<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'description' => $this->description,
            'quantity'    => (int) $this->quantity,
            'unit_price'  => (float) $this->unit_price,
            'total'       => (float) ($this->total ?? $this->total_price),
        ];
    }
}
