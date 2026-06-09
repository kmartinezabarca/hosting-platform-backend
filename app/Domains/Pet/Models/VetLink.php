<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class VetLink extends Model
{
    use HasUuids;

    /** Intentos de PIN fallidos antes de auto-revocar el link. */
    public const MAX_CODE_ATTEMPTS = 10;

    protected $connection = 'roke_pet';
    protected $table = 'vet_links';
    public $incrementing = false;
    protected $keyType = 'string';
    const UPDATED_AT = null;

    protected $fillable = [
        'pet_id', 'owner_id', 'token', 'expires_at', 'allow_add_records', 'view_count',
        'access_code', 'code_attempts', 'last_viewed_at',
    ];

    protected $casts = [
        'allow_add_records' => 'boolean',
        'expires_at'        => 'datetime',
        'last_viewed_at'    => 'datetime',
        'view_count'        => 'integer',
        'code_attempts'     => 'integer',
    ];

    /** El PIN nunca se serializa al JSON. */
    protected $hidden = ['access_code'];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasAccessCode(): bool
    {
        return ! empty($this->access_code);
    }

    /** Verifica el PIN contra el hash almacenado (constante en tiempo). */
    public function checkCode(?string $code): bool
    {
        return $this->hasAccessCode()
            && is_string($code) && $code !== ''
            && Hash::check($code, $this->access_code);
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
