<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemStatusResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
            return [
                'uuid'         => $this->uuid,
                'serviceName'  => $this->service_name,
                'service_name' => $this->service_name,
                'region'       => $this->region,
                'label'        => $this->label,
                'coord_x'      => $this->coord_x,
                'coord_y'      => $this->coord_y,
                'load_pct'     => $this->load_pct,
                'is_primary'   => (bool) $this->is_primary,
                'is_datacenter'=> (bool) $this->is_datacenter,
                'status'       => $this->status,
                'message'      => $this->message,
                'lastUpdated'  => $this->last_updated->format('Y-m-d H:i:s'),
                'last_updated' => $this->last_updated->format('Y-m-d H:i:s'),
                'createdAt'    => $this->created_at->format('Y-m-d H:i:s'),
                'updatedAt'    => $this->updated_at->format('Y-m-d H:i:s'),
            ];
    }
}
