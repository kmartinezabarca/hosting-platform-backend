<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketingService extends Model
{
    use HasFactory;

    protected $table = 'marketing_services';

    protected $fillable = [
        'uuid',
        'type',
        'icon_name',
        'title',
        'slug',
        'description',
        'features',
        'color',
        'bg_color',
        'order',
    ];

    protected $casts = [
        'features' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->title);
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }
}

