<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'          => $this->uuid,
            'name'          => $this->name,
            'domain'        => $this->domain,
            'status'        => $this->status,
            'billing_cycle' => $this->billing_cycle,
            'price'         => (float) $this->price,
            'next_due_date' => optional($this->next_due_date)->toDateString(),
            'configuration' => $this->configuration,
            'plan'          => $this->whenLoaded('plan', fn() => [
                'uuid'     => $this->plan->uuid,
                'name'     => $this->plan->name,
                'slug'     => $this->plan->slug,
                'category' => $this->plan->category?->name,
            ]),
            'add_ons'       => $this->whenLoaded('addOns', fn() =>
                $this->addOns->map(fn($a) => [
                    'uuid'       => $a->add_on_uuid,
                    'name'       => $a->name,
                    'unit_price' => (float) $a->unit_price,
                ])
            ),
            'created_at'    => optional($this->created_at)->toIso8601String(),
            'updated_at'    => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
