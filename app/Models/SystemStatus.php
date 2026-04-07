<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SystemStatus extends Model
{
    use HasFactory;

    protected $table = 'system_status';

    protected $fillable = [
        'service_name',
        'status',
        'message',
        'last_updated',
    ];

    protected $casts = [
        'last_updated' => 'datetime',
    ];

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
