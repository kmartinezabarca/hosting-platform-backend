<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PetPostComment extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'pet_post_comments';
    protected $keyType = 'string';

    protected $fillable = ['post_id', 'owner_id', 'parent_id', 'body', 'replies_count'];

    protected $casts = ['replies_count' => 'integer'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(PetPost::class, 'post_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }
}
