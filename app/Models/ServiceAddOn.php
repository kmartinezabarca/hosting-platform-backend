<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceAddOn extends Model
{
    protected $fillable = ['service_id', 'add_on_id', 'add_on_uuid', 'name', 'unit_price', 'quantity', 'metadata'];
    protected $casts = ['unit_price' => 'decimal:2', 'quantity' => 'integer', 'metadata' => 'array'];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
    public function addOn()
    {
        return $this->belongsTo(AddOn::class);
    }
}
