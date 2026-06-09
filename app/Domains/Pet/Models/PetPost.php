<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Publicación de la comunidad ROKE PET (red social de mascotas).
 * media: [{type: 'image'|'video', url: string}]
 */
class PetPost extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'pet_posts';
    protected $keyType = 'string';

    protected $fillable = [
        'owner_id', 'pet_id', 'caption', 'media',
        'likes_count', 'comments_count', 'moderation_status',
    ];

    protected $casts = [
        'media'          => 'array',
        'likes_count'    => 'integer',
        'comments_count' => 'integer',
    ];

    protected $attributes = [
        'likes_count'       => 0,
        'comments_count'    => 0,
        'moderation_status' => 'active',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class, 'pet_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PetPostLike::class, 'post_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PetPostComment::class, 'post_id')->orderBy('created_at', 'asc');
    }
}
