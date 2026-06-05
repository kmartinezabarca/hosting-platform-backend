<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'         => $this->uuid,
            'title'        => $this->title,
            'client_name'  => $this->client_name,
            'client_email' => $this->client_email,
            'total'        => $this->total,
            'currency'     => $this->currency,
            'status'       => $this->status->value,
            'status_label' => $this->status->label(),
            'is_expired'   => $this->isExpired(),
            'revision_number' => $this->revision_number,
            'expires_at'   => $this->expires_at?->toIso8601String(),
            'sent_at'      => $this->sent_at?->toIso8601String(),
            'created_at'   => $this->created_at->toIso8601String(),
            'updated_at'   => $this->updated_at->toIso8601String(),
        ];
    }
}
