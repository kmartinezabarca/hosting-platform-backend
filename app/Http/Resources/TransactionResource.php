<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'                    => $this->uuid,
            'transaction_id'          => $this->transaction_id,
            'provider_transaction_id' => $this->provider_transaction_id,
            'type'                    => $this->type,
            'status'                  => $this->status,
            'amount'                  => (float) $this->amount,
            'currency'                => $this->currency,
            'fee_amount'              => (float) $this->fee_amount,
            'provider'                => $this->provider,
            'description'             => $this->description,
            'failure_reason'          => $this->failure_reason,
            'processed_at'            => optional($this->processed_at)->toIso8601String(),
            'invoice'                 => new InvoiceResource($this->whenLoaded('invoice')),
            'payment_method'          => new PaymentMethodResource($this->whenLoaded('paymentMethod')),
            'created_at'              => optional($this->created_at)->toIso8601String(),
        ];
    }
}
