<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'             => $this->uuid,
            'title'            => $this->title,
            'client_name'      => $this->client_name,
            'client_email'     => $this->client_email,
            'client_company'   => $this->client_company,
            'client_phone'     => $this->client_phone,
            'items'            => $this->items,
            'subtotal'         => $this->subtotal,
            'discount_percent' => $this->discount_percent,
            'discount_amount'  => $this->discount_amount,
            'tax_percent'      => $this->tax_percent,
            'tax_amount'       => $this->tax_amount,
            'total'            => $this->total,
            'currency'         => $this->currency,
            'notes'            => $this->notes,
            'terms'            => $this->terms,

            // Status
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),

            // Public link
            'public_url'       => $this->public_url,
            'expires_at'       => $this->expires_at?->toIso8601String(),
            'is_expired'       => $this->isExpired(),

            // Lifecycle timestamps
            'sent_at'          => $this->sent_at?->toIso8601String(),
            'accepted_at'      => $this->accepted_at?->toIso8601String(),
            'rejected_at'      => $this->rejected_at?->toIso8601String(),
            'reopened_at'      => $this->reopened_at?->toIso8601String(),
            'reopened_reason'  => $this->reopened_reason,

            // Versioning
            'revision_number'  => $this->revision_number,
            'parent_uuid'      => $this->parent_uuid,

            // Business-rule flags for the frontend (read-only hints)
            'can_be_modified'  => $this->canBeModified(),
            'can_be_deleted'   => $this->canBeDeleted(),
            'can_be_accepted'  => $this->canBeAccepted(),
            'can_be_rejected'  => $this->canBeRejected(),
            'can_be_reopened'  => $this->canBeReopened(),
            'can_be_sent'      => $this->canBeSent(),

            'created_at'       => $this->created_at->toIso8601String(),
            'updated_at'       => $this->updated_at->toIso8601String(),

            // Eager-loaded
            'activities'       => $this->whenLoaded('activities', fn() =>
                $this->activities->map(fn($a) => [
                    'action'     => $a->action,
                    'old_values' => $a->old_values,
                    'new_values' => $a->new_values,
                    'metadata'   => $a->metadata,
                    'user_id'    => $a->user_id,
                    'ip_address' => $a->ip_address,
                    'created_at' => $a->created_at->toIso8601String(),
                ])
            ),
        ];
    }
}
