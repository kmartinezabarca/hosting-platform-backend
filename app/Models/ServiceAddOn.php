<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceAddOn extends Model
{
    protected $fillable = [
        'uuid', 'slug', 'name', 'description',
        'price', 'currency', 'is_active', 'metadata'
    ];

    protected $casts = [
        'price'    => 'decimal:2',
        'is_active'=> 'boolean',
        'metadata' => 'array',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function addOn()
    {
        return $this->belongsTo(AddOn::class);
    }

    public function plans()
    {
        return $this->belongsToMany(ServicePlan::class, 'add_on_service_plan')->withTimestamps();
    }
}
