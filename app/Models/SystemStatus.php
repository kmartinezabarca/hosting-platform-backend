<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SystemStatus extends Model
{
    protected $table = 'system_status';
    use HasFactory;

    protected $fillable = [
        'service_name',
        'region',
        'label',
        'coord_x',
        'coord_y',
        'load_pct',
        'is_primary',
        'is_datacenter',
        'status',
        'message',
        'last_updated',
    ];

    protected $casts = [
        'last_updated'  => 'datetime',
        'coord_x'       => 'float',
        'coord_y'       => 'float',
        'load_pct'      => 'integer',
        'is_primary'    => 'boolean',
        'is_datacenter' => 'boolean',
    ];

    public function scopeDatacenters($query)
    {
        return $query->where('is_datacenter', true);
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }
}
