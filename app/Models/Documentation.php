<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Documentation extends Model
{
    protected $table = 'documentation';
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'category',
        'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
            $model->slug = Str::slug($model->title);
        });

        static::updating(function ($model) {
            $model->slug = Str::slug($model->title);
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }
}
