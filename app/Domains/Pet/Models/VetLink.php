<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VetLink extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'vet_links';
    public $incrementing = false;
    protected $keyType = 'string';
    const UPDATED_AT = null;

    protected $fillable = [
        'pet_id', 'owner_id', 'token', 'expires_at', 'allow_add_records', 'view_count',
    ];

    protected $casts = [
        'allow_add_records' => 'boolean',
        'expires_at'        => 'datetime',
        'view_count'        => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class, 'pet_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }
}
