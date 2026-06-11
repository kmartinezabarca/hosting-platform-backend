<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class KnowledgeArticle extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table      = 'pet_knowledge_articles';

    protected $fillable = [
        'brand', 'title', 'slug', 'excerpt', 'content',
        'category', 'tags', 'keywords', 'status',
    ];

    protected $casts = [
        'tags'       => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopePublished($q)
    {
        return $q->where('status', 'published');
    }

    public function scopeForBrand($q, string $brand = 'roke_pet')
    {
        return $q->where('brand', $brand);
    }
}
