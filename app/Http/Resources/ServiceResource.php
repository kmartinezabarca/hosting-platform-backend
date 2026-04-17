<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'name'          => $this->name,
            'domain'        => $this->domain,
            'status'        => $this->status,
            'billing_cycle' => $this->billing_cycle,
            'price'         => (float) $this->price,
            'setup_fee'     => (float) $this->setup_fee,
            'next_due_date' => optional($this->next_due_date)->toDateString(),
            'notes'         => $this->notes,
            'configuration' => $this->configuration,
            'user'          => $this->whenLoaded('user', fn() => [
                'id'    => $this->user->id,
                'name'  => $this->user->full_name,
                'email' => $this->user->email,
            ]),
            // service_type: 'infrastructure' | 'professional' | 'other'
            // El frontend usa esto para mostrar los campos correctos de configuration
            'service_type'  => $this->whenLoaded('plan', fn() => $this->service_type, 'other'),
            'plan'          => $this->whenLoaded('plan', fn() => [
                'id'           => $this->plan->id,
                'uuid'         => $this->plan->uuid,
                'name'         => $this->plan->name,
                'slug'         => $this->plan->slug,
                'category_slug'=> $this->plan->category?->slug,
                'category'     => $this->plan->category?->name,
            ]),
            'add_ons'       => $this->whenLoaded('selectedAddOns', fn() =>
                $this->selectedAddOns->map(fn($a) => [
                    'uuid'       => $a->uuid,
                    'name'       => $a->name,
                    'unit_price' => (float) $a->unit_price,
                ])
            ),
            'created_at'    => optional($this->created_at)->toIso8601String(),
            'updated_at'    => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
